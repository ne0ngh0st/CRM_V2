<?php

namespace App\Console\Commands;

use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Estoque de revenda (catálogo da Cotação) a partir do Relatório de Revenda (RV) em CSV.
 *
 * É o MESMO relatório que o Cotacao.exe lia (aba Geral do xlsx; colunas em
 * `fontes.COLS_RV` do .exe), agora exportado pelo TOTVS como `Relatorio de Revenda -
 * SQL.csv`. O RV já é o recorte pronto (filial 05, só ativos, tipo RV/PA — o SRM
 * conferiu contra o 026 em 24/09), então aqui não há filtro: toda linha com código e
 * descrição entra, inclusive os baldes OCIOSO/INATIVO do comprador, como no .exe.
 *
 * Formato visto no primeiro export (22/10/2026): cabeçalho na 1ª linha (sem título),
 * nomes com padding, quantidades como inteiro puro ("1200") e preços como
 * "R$ 1.234,56", "-R$ 57,24" ou "R$ -" (zero).
 *
 * É retrato completo: o que sumiu do arquivo sai da tabela, senão o motor continuaria
 * sugerindo produto que não se vende mais.
 *
 * ⚠️ NUNCA FALHA A CORRENTE do `totvs:atualizar`: arquivo ausente, trocado ou truncado
 * devolve SUCCESS com o motivo na saída (que a /atualizacoes mostra) e não toca a
 * tabela. Devolver FAILURE aqui interromperia os derivados (rollup, Visão Diretor) e
 * impediria o marcador — a rodada inteira voltaria a importar tudo de hora em hora por
 * causa de um relatório que só a Cotação usa. Quem denuncia estoque parado é a própria
 * tela da Cotação, que mostra a data do retrato.
 */
class ImportEstoqueRevendaTotvs extends Command
{
    protected $signature = 'totvs:import-estoque-revenda
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa o estoque de revenda (Relatório de Revenda) para a Cotação';

    /**
     * Abaixo desta fração do que já está gravado, o arquivo é tratado como truncado e
     * nada é apagado. Um export pela metade não pode esvaziar o catálogo de todo mundo.
     */
    private const FRACAO_MINIMA = 0.5;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (Relatorios::caminho('estoque_revenda') === null) {
            $this->warn('Relatório de Revenda não está na pasta: estoque de revenda não foi tocado.');

            return self::SUCCESS;
        }

        try {
            $leitor = Relatorios::abrir('estoque_revenda');
            $leitor->exigirColunas([
                'Codigo', 'Descricao', 'Marca', 'Ult.Preco', 'Fisico', 'Reserva',
                'Disponivel', 'Comprado', 'Grupo',
            ]);
        } catch (RuntimeException $e) {
            $this->error('Relatório de Revenda NÃO importado: '.$e->getMessage());

            return self::SUCCESS;
        }

        $agora = now();
        $linhas = [];
        $ignoradas = 0;

        foreach ($leitor->linhas() as $linha) {
            $codigo = trim($linha['Codigo']);
            $descricao = trim($linha['Descricao']);

            if ($codigo === '' || $descricao === '') {
                $ignoradas++;

                continue;
            }

            $linhas[$codigo] = [
                'codigo' => mb_substr($codigo, 0, 40),
                'descricao' => mb_substr($descricao, 0, 255),
                'unidade' => null,
                'marca' => Normalizador::valorOuNull($linha['Marca']),
                'grupo' => Normalizador::valorOuNull(mb_substr($linha['Grupo'], 0, 40)),
                'fisico' => Normalizador::numero($linha['Fisico']),
                'reserva' => Normalizador::numero($linha['Reserva']),
                'disponivel' => Normalizador::numero($linha['Disponivel']),
                'comprado' => Normalizador::numero($linha['Comprado']),
                'ult_preco' => self::reais($linha['Ult.Preco']),
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        $novos = count($linhas);
        $atuais = DB::table('estoque_revenda')->count();

        $this->line(sprintf(
            'Relatório de Revenda: %s itens; %s linhas sem código ou descrição.',
            number_format($novos, 0, ',', '.'),
            number_format($ignoradas, 0, ',', '.')
        ));

        if ($novos === 0 || ($atuais > 0 && $novos < $atuais * self::FRACAO_MINIMA)) {
            $this->error(sprintf(
                'Arquivo parece truncado (%d itens contra %d gravados): nada foi alterado.',
                $novos,
                $atuais
            ));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('[dry-run] nada gravado.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($linhas, $agora) {
            foreach (array_chunk(array_values($linhas), 1000) as $lote) {
                DB::table('estoque_revenda')->upsert($lote, ['codigo'], [
                    'descricao', 'marca', 'grupo', 'fisico', 'reserva',
                    'disponivel', 'comprado', 'ult_preco', 'updated_at',
                ]);
            }

            // Apaga o que não foi tocado nesta rodada (mais barato que um IN de 4 mil).
            DB::table('estoque_revenda')->where('updated_at', '<', $agora)->delete();
        });

        $this->info('Estoque de revenda gravado: '.number_format($novos, 0, ',', '.').' itens.');

        return self::SUCCESS;
    }

    /**
     * Preço do RV: "R$ 1.234,56", "-R$ 57,24" ou "R$ -" (zero). O `Normalizador::numero`
     * leria o "R$" e devolveria 0 para tudo.
     */
    public static function reais(string $valor): float
    {
        $negativo = str_contains($valor, '-R$') || str_starts_with(trim($valor), '-');
        $limpo = trim(str_replace(['R$', '-', ' ', "\u{00A0}"], '', $valor));

        if ($limpo === '') {
            return 0.0;
        }

        $numero = Normalizador::numero($limpo);

        return $negativo ? -$numero : $numero;
    }
}
