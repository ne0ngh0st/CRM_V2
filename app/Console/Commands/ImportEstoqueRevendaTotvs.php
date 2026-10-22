<?php

namespace App\Console\Commands;

use App\Models\EstoqueRevendaCarga;
use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Estoque de revenda (catálogo da Cotação) a partir do Relatório de Revenda (RV).
 *
 * O FLUXO (combinado pelo Tony com quem monta o RV, 22/10/2026): todo dia um arquivo
 * NOVO em `RELATORIOS TOTVS/Relatório revenda/`, em xlsx, sem sobrescrever o anterior —
 * a pasta (e o S3) vira a coleção de todas as versões. Daí as três regras daqui:
 *
 * 1. **A versão que vale é a DATA DO NOME** (`... 22-10-2026.xlsx`), nunca a data de
 *    modificação: abrir e salvar um RV antigo mudaria o mtime e o faria "voltar" (o
 *    mesmo engano de 03/09 com os relatórios de pedidos). Arquivo sem data reconhecível
 *    é ignorado e listado na saída. O nome em si não importa ("Revanda" passa).
 * 2. **Só trabalha quando há RV novo.** Roda de hora em hora fora da corrente do
 *    `totvs:atualizar` (ver `routes/console.php`) e sai na hora se o RV mais novo já foi
 *    carregado — custo de listar uma pasta. `--forcar` recarrega mesmo assim.
 * 3. **Nunca derruba nada**: arquivo trocado, sem a aba ou truncado sai com sucesso, o
 *    motivo na saída e a tabela intacta. Quem denuncia estoque parado é a /cotacoes,
 *    que mostra de que dia é o RV em uso.
 *
 * Lê a aba `Geral` (a que o Cotacao.exe lia; as outras são cópias por comprador) e mapeia
 * as colunas PELO NOME — posição muda no dia em que alguém insere uma coluna. O RV já
 * vem no recorte certo (filial 05, ativos; conferido pelo SRM contra o 026), então não
 * há filtro. É retrato completo: código que sumiu do RV sai do estoque.
 */
class ImportEstoqueRevendaTotvs extends Command
{
    protected $signature = 'totvs:import-estoque-revenda
        {--forcar : recarrega o RV mais novo mesmo que já tenha sido carregado}
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Carrega o Relatório de Revenda mais novo (xlsx) no estoque da Cotação';

    public const ABA = 'Geral';

    public const COLUNAS = [
        'Codigo', 'Descricao', 'Marca', 'Ult.Preco', 'Fisico', 'Reserva',
        'Disponivel', 'Comprado', 'Grupo',
    ];

    /**
     * Abaixo desta fração do que já está gravado, o arquivo é tratado como truncado e
     * nada é apagado. Um RV pela metade não pode esvaziar o catálogo de todo mundo.
     */
    private const FRACAO_MINIMA = 0.5;

    public function handle(): int
    {
        $escolhido = $this->rvMaisNovo();

        if ($escolhido === null) {
            return self::SUCCESS;
        }

        [$caminho, $data] = $escolhido;
        $nome = basename($caminho);
        $atual = EstoqueRevendaCarga::atual();

        if (! $this->option('forcar') && $atual !== null && $this->jaCarregado($atual, $nome, $data, $caminho)) {
            $this->line("RV de {$data->format('d/m/Y')} já carregado ({$nome}). Nada a fazer.");

            return self::SUCCESS;
        }

        try {
            $linhas = $this->ler($caminho);
        } catch (Throwable $e) {
            $this->error("RV {$nome} NÃO importado: ".$e->getMessage());

            return self::SUCCESS;
        }

        $novos = count($linhas);
        $atuais = DB::table('estoque_revenda')->count();
        $this->line(sprintf('RV de %s (%s): %s itens.', $data->format('d/m/Y'), $nome, number_format($novos, 0, ',', '.')));

        if ($novos === 0 || ($atuais > 0 && $novos < $atuais * self::FRACAO_MINIMA)) {
            $this->error(sprintf('Arquivo parece truncado (%d itens contra %d gravados): nada foi alterado.', $novos, $atuais));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('[dry-run] nada gravado.');

            return self::SUCCESS;
        }

        $agora = now();

        DB::transaction(function () use ($linhas, $agora, $nome, $data, $novos) {
            foreach (array_chunk(array_values($linhas), 1000) as $lote) {
                DB::table('estoque_revenda')->upsert(
                    array_map(fn ($l) => $l + ['created_at' => $agora, 'updated_at' => $agora], $lote),
                    ['codigo'],
                    ['descricao', 'marca', 'grupo', 'fisico', 'reserva', 'disponivel', 'comprado', 'ult_preco', 'updated_at'],
                );
            }

            // Apaga o que não foi tocado nesta carga (mais barato que um IN de 4 mil).
            DB::table('estoque_revenda')->where('updated_at', '<', $agora)->delete();

            EstoqueRevendaCarga::create([
                'arquivo' => mb_substr($nome, 0, 255),
                'data_rv' => $data->toDateString(),
                'itens' => $novos,
                'importado_em' => $agora,
            ]);
        });

        $this->info("Estoque de revenda gravado: {$novos} itens, RV de {$data->format('d/m/Y')}.");

        return self::SUCCESS;
    }

    /**
     * O xlsx de data mais nova no NOME. Empate (o mesmo dia salvo duas vezes, com nomes
     * diferentes): vence o modificado por último.
     *
     * @return array{0: string, 1: Carbon}|null
     */
    private function rvMaisNovo(): ?array
    {
        $candidatos = [];
        $semData = [];

        foreach (Relatorios::todos('estoque_revenda') as $caminho) {
            $nome = basename($caminho);

            // Arquivo de trava que o Excel cria enquanto a planilha está aberta.
            if (str_starts_with($nome, '~$')) {
                continue;
            }

            $data = self::dataDoNome($nome);

            if ($data === null) {
                $semData[] = $nome;

                continue;
            }

            $candidatos[] = [$caminho, $data, filemtime($caminho) ?: 0];
        }

        foreach ($semData as $nome) {
            $this->warn("Ignorado (sem data DD-MM-AAAA no nome): {$nome}");
        }

        if ($candidatos === []) {
            $this->warn('Nenhum Relatório de Revenda com data no nome: estoque de revenda não foi tocado.');

            return null;
        }

        usort($candidatos, fn ($a, $b) => [$b[1]->timestamp, $b[2]] <=> [$a[1]->timestamp, $a[2]]);

        return [$candidatos[0][0], $candidatos[0][1]];
    }

    /** "Relatorio de Revenda 22-10-2026.xlsx" → 22/10/2026. Aceita `-`, `_` e `.`. */
    public static function dataDoNome(string $nome): ?Carbon
    {
        if (! preg_match('/(\d{1,2})[-_.](\d{1,2})[-_.](\d{4})/', $nome, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
    }

    /**
     * O RV mais novo da pasta já está no banco? Data mais velha que a carregada nunca
     * volta; a mesma data só recarrega se for outro arquivo ou o mesmo salvo de novo
     * depois da carga (correção do dia).
     */
    private function jaCarregado(EstoqueRevendaCarga $atual, string $nome, Carbon $data, string $caminho): bool
    {
        if ($data->lt($atual->data_rv)) {
            return true;
        }

        return $data->equalTo($atual->data_rv)
            && $atual->arquivo === $nome
            && (filemtime($caminho) ?: 0) <= $atual->importado_em->timestamp;
    }

    /** @return array<string, array<string, mixed>> indexado pelo código */
    private function ler(string $caminho): array
    {
        $leitor = IOFactory::createReader('Xlsx');
        $leitor->setReadDataOnly(true);

        $nomesDasAbas = $leitor->listWorksheetNames($caminho);
        if (! in_array(self::ABA, $nomesDasAbas, true)) {
            throw new RuntimeException('não tem a aba "'.self::ABA.'". Abas: '.implode(', ', $nomesDasAbas));
        }

        $leitor->setLoadSheetsOnly([self::ABA]);
        $linhas = $leitor->load($caminho)->getSheetByName(self::ABA)->toArray(null, false, false, false);

        $cabecalho = array_map(fn ($c) => trim((string) $c), array_shift($linhas) ?? []);
        $faltando = array_diff(self::COLUNAS, $cabecalho);
        if ($faltando !== []) {
            throw new RuntimeException('faltam as colunas '.implode(', ', $faltando).'. Encontradas: '.implode(', ', array_filter($cabecalho)));
        }

        $i = array_flip($cabecalho);
        $saida = [];

        foreach ($linhas as $l) {
            $codigo = trim((string) ($l[$i['Codigo']] ?? ''));
            $descricao = trim((string) ($l[$i['Descricao']] ?? ''));

            if ($codigo === '' || $descricao === '') {
                continue;
            }

            $saida[$codigo] = [
                'codigo' => mb_substr($codigo, 0, 40),
                'descricao' => mb_substr($descricao, 0, 255),
                'unidade' => null,
                'marca' => Normalizador::valorOuNull(trim((string) ($l[$i['Marca']] ?? ''))),
                'grupo' => Normalizador::valorOuNull(mb_substr(trim((string) ($l[$i['Grupo']] ?? '')), 0, 40)),
                'fisico' => self::numero($l[$i['Fisico']] ?? 0),
                'reserva' => self::numero($l[$i['Reserva']] ?? 0),
                'disponivel' => self::numero($l[$i['Disponivel']] ?? 0),
                'comprado' => self::numero($l[$i['Comprado']] ?? 0),
                'ult_preco' => self::numero($l[$i['Ult.Preco']] ?? 0),
            ];
        }

        return $saida;
    }

    /**
     * Número da célula. No xlsx já vem numérico; texto cai no formato brasileiro, com ou
     * sem "R$" ("R$ 1.234,56", "-R$ 57,24", "R$ -").
     */
    public static function numero(mixed $valor): float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $texto = trim((string) $valor);
        $negativo = str_starts_with($texto, '-');
        $limpo = trim(str_replace(['R$', '-', ' ', "\u{00A0}"], '', $texto));

        if ($limpo === '') {
            return 0.0;
        }

        $numero = Normalizador::numero($limpo);

        return $negativo ? -$numero : $numero;
    }
}
