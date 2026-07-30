<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoConexao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportFaturamentoLegado extends Command
{
    protected $signature = 'legado:import-faturamento
        {--fonte=homolog : homolog ou producao}
        {--desde= : reimporta só a partir dessa data (Y-m-d, period_merge); sem isso, recarrega o histórico inteiro}
        {--chunk=2000 : tamanho do lote de insert}';

    protected $description = 'Import do faturamento (linha de nota fiscal) do TOTVS pro CRM-V2';

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $chunk = (int) $this->option('chunk');
        $desde = $this->option('desde');

        if ($desde !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $this->error('--desde precisa estar no formato Y-m-d.');

            return self::FAILURE;
        }

        if ($desde !== null) {
            $apagados = DB::table('faturamentos')->where('data_emissao', '>=', $desde)->delete();
            $this->info("Removidas {$apagados} linhas de faturamentos a partir de {$desde} (period_merge).");
        } else {
            DB::table('faturamentos')->truncate();
            $this->info('Tabela faturamentos zerada — recarga completa do histórico.');
        }

        $pdo = LegadoConexao::pdo($fonte);
        // Sem isso, o driver bufferiza o resultado inteiro no cliente antes de devolver
        // a primeira linha — inviável pra 900k+ linhas.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $sql = 'SELECT FILIAL, emissao_date, COD_CLIE, CNPJ, CLIENTE, COD_VENDEDOR, COD_PROD, DES_PROD, '
            .'SEGMENTO, QUANT, VLR_UNIT, VLR_TOTAL, PEDIDO, NTA_FISCAL FROM FATURAMENTO';
        if ($desde !== null) {
            $sql .= ' WHERE emissao_date >= '.$pdo->quote($desde);
        }

        $this->info('Lendo FATURAMENTO ('.$fonte.')'.($desde ? " a partir de {$desde}" : ' (histórico completo)').'...');

        $stmt = $pdo->query($sql);

        $lote = [];
        $total = 0;
        $ignorados = 0;
        $agora = now();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['emissao_date'])) {
                $ignorados++;

                continue;
            }

            $lote[] = [
                'filial' => self::intOuNull($row['FILIAL']),
                'nota_fiscal' => self::valorOuNull($row['NTA_FISCAL']),
                'pedido' => self::valorOuNull($row['PEDIDO']),
                'data_emissao' => $row['emissao_date'],
                'cod_cliente' => self::valorOuNull($row['COD_CLIE']),
                'cnpj' => self::valorOuNull($row['CNPJ']),
                'cliente_nome' => self::valorOuNull($row['CLIENTE']),
                'cod_vendedor' => trim((string) $row['COD_VENDEDOR']),
                'cod_produto' => self::valorOuNull($row['COD_PROD']),
                'produto_desc' => self::valorOuNull($row['DES_PROD']),
                'segmento' => self::valorOuNull($row['SEGMENTO']),
                'quantidade' => $row['QUANT'],
                'valor_unitario' => $row['VLR_UNIT'],
                'valor_total' => $row['VLR_TOTAL'] ?? 0,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= $chunk) {
                DB::table('faturamentos')->insert($lote);
                $total += count($lote);
                $this->line("  {$total} inseridos...");
                $lote = [];
            }
        }

        if ($lote !== []) {
            DB::table('faturamentos')->insert($lote);
            $total += count($lote);
        }

        $this->info("Inseridos: {$total}");
        if ($ignorados > 0) {
            $this->warn("Ignorados (sem data de emissão): {$ignorados}");
        }

        return self::SUCCESS;
    }

    private static function valorOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }

    private static function intOuNull(mixed $valor): ?int
    {
        return ($valor === null || $valor === '') ? null : (int) $valor;
    }
}
