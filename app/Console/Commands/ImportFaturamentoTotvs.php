<?php

namespace App\Console\Commands;

use App\Services\Totvs\Normalizador;
use App\Services\Totvs\Relatorios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa o faturamento do relatório 198, direto do arquivo.
 *
 * ⚠️ NUNCA TRUNCA, e essa é a diferença que mais importa em relação ao
 * `legado:import-faturamento`. A tabela guarda 5,85 milhões de linhas de 2018 a hoje, e
 * os oito anos antigos vieram de planilhas — não existem no relatório nem em lugar
 * nenhum que dê para reimportar. O 198 traz só o mês vigente (hoje, um único dia), então
 * qualquer recarga total seria destruição pura.
 *
 * ⚠️ O MERGE É PELO CONJUNTO DE DATAS PRESENTES NO ARQUIVO, não pelo intervalo
 * min–max. A diferença aparece quando o relatório traz uma linha com data furada: pelo
 * intervalo, uma emissão perdida em 2019 mandaria apagar de 2019 até hoje — seis anos —
 * para reinserir três mil linhas. Pelo conjunto, só os dias que o arquivo realmente
 * contém são substituídos, e a linha furada afeta apenas o próprio dia dela.
 *
 * O ciclo é: apaga os dias do arquivo, insere o arquivo. Rodar duas vezes seguidas dá o
 * mesmo resultado, e reprocessar um dia que ganhou nota nova é só gerar o relatório de
 * novo — sem `--desde` para digitar (e digitar errado).
 *
 * Para carga histórica de um ano inteiro, o comando é outro:
 * `legado:import-faturamento-arquivo --ano=2024`, que existe justamente porque um xlsx
 * de 285 MB não passa por PhpSpreadsheet.
 */
class ImportFaturamentoTotvs extends Command
{
    /**
     * Acima disto o arquivo não é o relatório mensal — provavelmente é carga histórica,
     * que tem comando próprio. Avisa em vez de engolir 1,1 milhão de linhas numa
     * transação só.
     */
    private const DIAS_ESPERADOS_NO_MES = 92;

    protected $signature = 'totvs:import-faturamento
        {--chunk=2000 : tamanho do lote de insert}
        {--dry-run : lê e conta, sem escrever nada}';

    protected $description = 'Importa o faturamento do relatório 198 do TOTVS, substituindo só os dias contidos no arquivo';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $leitor = Relatorios::abrir('faturamento');
        $leitor->exigirColunas([
            'FILIAL', 'EMISSAO', 'COD_CLI', 'CNPJ', 'CLIENTE', 'COD_VENDEDOR',
            'COD_PROD', 'DES_PROD', 'SEGMENTO', 'QUANT', 'VLR_UNIT', 'VLR_TOTAL',
            'PEDIDO', 'NTA_FISCAL',
        ]);

        // Primeira passada: descobre quais dias o arquivo cobre. Ler duas vezes é mais
        // barato que segurar o arquivo inteiro em memória, e é o que permite apagar
        // exatamente os dias certos antes de inserir.
        [$datas, $linhas, $semData] = $this->levantarDatas($leitor);

        if ($datas === []) {
            $this->error('Nenhuma linha com data de emissão válida. Nada a fazer.');

            return self::FAILURE;
        }

        sort($datas);
        $this->line(sprintf(
            '198 - Faturamento: %s linhas, %d dia(s) — de %s a %s.',
            number_format($linhas, 0, ',', '.'),
            count($datas),
            reset($datas),
            end($datas)
        ));

        if (count($datas) > self::DIAS_ESPERADOS_NO_MES) {
            $this->warn('O arquivo cobre '.count($datas).' dias — bem mais que um mês.');
            $this->warn('Se a intenção é carga histórica, o comando é legado:import-faturamento-arquivo --ano=AAAA.');
        }

        $existentes = DB::table('faturamentos')->whereIn('data_emissao', $datas)->count();
        $this->line('Já gravadas nesses dias (serão substituídas): '.number_format($existentes, 0, ',', '.'));

        if ($dryRun) {
            $this->info('[dry-run] Inseriria '.number_format($linhas - $semData, 0, ',', '.').' linhas.');

            if ($semData > 0) {
                $this->warn("[dry-run] Ignoraria {$semData} sem data de emissão.");
            }

            return self::SUCCESS;
        }

        $inseridas = 0;

        DB::transaction(function () use ($leitor, $datas, $chunk, &$inseridas) {
            DB::table('faturamentos')->whereIn('data_emissao', $datas)->delete();
            $inseridas = $this->inserir($leitor, $chunk);
        });

        $this->info('Linhas inseridas: '.number_format($inseridas, 0, ',', '.'));
        $this->line('Substituídas: '.number_format($existentes, 0, ',', '.'));

        if ($semData > 0) {
            $this->warn("Ignoradas (sem data de emissão): {$semData}");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<string>, 1: int, 2: int}
     */
    private function levantarDatas(\App\Services\Totvs\LeitorRelatorio $leitor): array
    {
        $datas = [];
        $linhas = 0;
        $semData = 0;

        foreach ($leitor->linhas() as $linha) {
            $linhas++;
            $data = Normalizador::data($linha['EMISSAO']);

            if ($data === null) {
                $semData++;

                continue;
            }

            $datas[$data] = true;
        }

        return [array_keys($datas), $linhas, $semData];
    }

    private function inserir(\App\Services\Totvs\LeitorRelatorio $leitor, int $chunk): int
    {
        $agora = now();
        $lote = [];
        $total = 0;

        foreach ($leitor->linhas() as $linha) {
            $data = Normalizador::data($linha['EMISSAO']);

            if ($data === null) {
                continue;
            }

            $lote[] = [
                'filial' => is_numeric($linha['FILIAL']) ? (int) $linha['FILIAL'] : null,
                'nota_fiscal' => Normalizador::valorOuNull($linha['NTA_FISCAL']),
                'pedido' => Normalizador::valorOuNull($linha['PEDIDO']),
                'data_emissao' => $data,
                'cod_cliente' => Normalizador::valorOuNull($linha['COD_CLI']),
                'cnpj' => Normalizador::valorOuNull($linha['CNPJ']),
                'cliente_nome' => Normalizador::valorOuNull($linha['CLIENTE']),
                'cod_vendedor' => $linha['COD_VENDEDOR'],
                'cod_produto' => Normalizador::valorOuNull($linha['COD_PROD']),
                'produto_desc' => Normalizador::valorOuNull($linha['DES_PROD']),
                'segmento' => Normalizador::valorOuNull($linha['SEGMENTO']),
                // ⚠️ O relatório traz número em formato brasileiro ("12.610,17").
                // (float) direto pararia no ponto e leria 12,00 — erro que só aparece
                // em valor de milhar, justamente o que mais pesa na soma.
                'quantidade' => Normalizador::numero($linha['QUANT']),
                'valor_unitario' => Normalizador::numero($linha['VLR_UNIT']),
                'valor_total' => Normalizador::numero($linha['VLR_TOTAL']),
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= $chunk) {
                DB::table('faturamentos')->insert($lote);
                $total += count($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            DB::table('faturamentos')->insert($lote);
            $total += count($lote);
        }

        return $total;
    }
}
