<?php

namespace App\Console\Commands;

use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Estoque de revenda (catálogo da Cotação) a partir do relatório 026 do TOTVS.
 *
 * O recorte é o do "Relatório de Revenda" que o Cotacao.exe lia (conferido pelo SRM em
 * 4.170 de 4.170 itens, 24/09/2026): **só a filial 05**, só ATIVO, só tipo RV/PA. O
 * 026 traz as filiais 01, 05 e 07 numa linha cada, e somar filiais foi o primeiro
 * palpite errado de lá — não somar.
 *
 * É retrato completo: o que sumiu do arquivo (ficou inativo, mudou de tipo) sai da
 * tabela, senão o motor continuaria sugerindo produto que não se vende mais.
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

    protected $description = 'Importa o estoque de revenda (relatório 026, filial 05) para a Cotação';

    public const FILIAL = 5;

    public const TIPOS = ['RV', 'PA'];

    /**
     * Abaixo desta fração do que já está gravado, o arquivo é tratado como truncado e
     * nada é apagado. Um export pela metade não pode esvaziar o catálogo de todo mundo.
     */
    private const FRACAO_MINIMA = 0.5;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (Relatorios::caminho('estoque_revenda') === null) {
            $this->warn('026 - Estoque necessidade não está na pasta: estoque de revenda não foi tocado.');

            return self::SUCCESS;
        }

        try {
            $leitor = Relatorios::abrir('estoque_revenda');
            $leitor->exigirColunas([
                'Filial', 'Codigo', 'Descricao', 'Tipo', 'EST_FISICO', 'Qtd. Reserva',
                'EST_DISP', 'PED_EMITID', 'Ult. Preco', 'Marca', 'ATIVO',
            ]);
        } catch (RuntimeException $e) {
            $this->error('026 - Estoque necessidade NÃO importado: '.$e->getMessage());

            return self::SUCCESS;
        }

        $agora = now();
        $linhas = [];
        $fora = 0;

        foreach ($leitor->linhas() as $linha) {
            if (Normalizador::filial($linha['Filial']) !== self::FILIAL
                || mb_strtoupper(trim($linha['ATIVO'])) !== 'ATIVO'
                || ! in_array(mb_strtoupper(trim($linha['Tipo'])), self::TIPOS, true)) {
                $fora++;

                continue;
            }

            $codigo = trim($linha['Codigo']);
            $descricao = trim($linha['Descricao']);

            if ($codigo === '' || $descricao === '') {
                $fora++;

                continue;
            }

            $linhas[$codigo] = [
                'codigo' => mb_substr($codigo, 0, 40),
                'descricao' => mb_substr($descricao, 0, 255),
                'unidade' => Normalizador::valorOuNull(mb_substr($linha['Unidade'] ?? '', 0, 10)),
                'marca' => Normalizador::valorOuNull($linha['Marca']),
                'grupo' => Normalizador::valorOuNull(mb_substr($linha['Grupo'] ?? '', 0, 40)),
                'fisico' => Normalizador::numero($linha['EST_FISICO']),
                'reserva' => Normalizador::numero($linha['Qtd. Reserva']),
                'disponivel' => Normalizador::numero($linha['EST_DISP']),
                'comprado' => Normalizador::numero($linha['PED_EMITID']),
                'ult_preco' => Normalizador::numero($linha['Ult. Preco']),
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        $novos = count($linhas);
        $atuais = DB::table('estoque_revenda')->count();

        $this->line(sprintf(
            '026 - Estoque de revenda: %s itens da filial 05 ativos (RV/PA); %s linhas fora do recorte.',
            number_format($novos, 0, ',', '.'),
            number_format($fora, 0, ',', '.')
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
                    'descricao', 'unidade', 'marca', 'grupo', 'fisico', 'reserva',
                    'disponivel', 'comprado', 'ult_preco', 'updated_at',
                ]);
            }

            // ⚠️ `whereNotIn` com a lista toda seria um IN de ~4 mil valores; mais barato
            // é apagar o que não foi tocado nesta rodada (updated_at mais velho).
            DB::table('estoque_revenda')->where('updated_at', '<', $agora)->delete();
        });

        $this->info('Estoque de revenda gravado: '.number_format($novos, 0, ',', '.').' itens.');

        return self::SUCCESS;
    }
}
