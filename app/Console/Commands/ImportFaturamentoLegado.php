<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoConexao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * ⚠️ Sem `--desde`, este comando faz TRUNCATE em `faturamentos` antes de recarregar.
 * Isso foi inofensivo enquanto a tabela era só o espelho do ano corrente: o que fosse
 * apagado voltava na mesma execução.
 *
 * Deixou de ser em 2026-08-31, com a carga do histórico 2018-2025 (5,85 milhões de
 * linhas). O espelho do TOTVS só tem o ano corrente — os outros oito anos vieram de
 * planilhas e existem apenas no banco e nos CSVs da máquina do Tony. Rodar sem `--desde`
 * contra um banco com histórico apaga tudo isso, e nenhuma reimportação traz de volta.
 *
 * Daí a trava em `fonteCobreOHistorico()`.
 */
class ImportFaturamentoLegado extends Command
{
    protected $signature = 'legado:import-faturamento
        {--fonte=homolog : homolog ou producao}
        {--desde= : reimporta só a partir dessa data (Y-m-d, period_merge); sem isso, recarrega o histórico inteiro}
        {--apagar-historico : autoriza o truncate mesmo quando a fonte não cobre todo o período já gravado}
        {--chunk=2000 : tamanho do lote de insert}';

    protected $description = 'Import do faturamento (linha de nota fiscal) do TOTVS pro CRM-V2';

    /**
     * Recusa o truncate quando o destino guarda período que a fonte não sabe repor.
     *
     * A comparação é entre a data mais antiga já gravada e a mais antiga que a origem
     * oferece: se o banco tem 2018 e o espelho começa em 2026, recarregar apaga oito anos
     * que ninguém consegue trazer de volta. Compara data, não contagem de linhas — o que
     * importa é a cobertura do período, não o volume.
     */
    private function fonteCobreOHistorico(PDO $pdo): bool
    {
        $maisAntigoNoDestino = DB::table('faturamentos')->min('data_emissao');

        if ($maisAntigoNoDestino === null) {
            return true;
        }

        $maisAntigoNaFonte = $pdo->query('SELECT MIN(emissao_date) FROM FATURAMENTO')->fetchColumn();

        if ($maisAntigoNaFonte === false || $maisAntigoNaFonte === null) {
            $maisAntigoNaFonte = '9999-12-31';
        }

        if ($maisAntigoNoDestino >= $maisAntigoNaFonte) {
            return true;
        }

        $emRisco = DB::table('faturamentos')->where('data_emissao', '<', $maisAntigoNaFonte)->count();

        if ($this->option('apagar-historico')) {
            $this->warn("--apagar-historico informado: seguindo mesmo com {$emRisco} linhas anteriores a {$maisAntigoNaFonte} sendo descartadas.");

            return true;
        }

        $this->error('Recusando o truncate: a fonte não cobre todo o período já gravado.');
        $this->line("  Mais antigo no banco : {$maisAntigoNoDestino}");
        $this->line("  Mais antigo na fonte : {$maisAntigoNaFonte}");
        $this->line("  Linhas que sumiriam  : {$emRisco}");
        $this->newLine();
        $this->line('Para atualizar só o período que a fonte tem, use --desde='.$maisAntigoNaFonte.'.');
        $this->line('Se a intenção é mesmo descartar o histórico, repita com --apagar-historico.');

        return false;
    }

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $chunk = (int) $this->option('chunk');
        $desde = $this->option('desde');

        if ($desde !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $this->error('--desde precisa estar no formato Y-m-d.');

            return self::FAILURE;
        }

        $pdo = LegadoConexao::pdo($fonte);

        if ($desde === null && ! $this->fonteCobreOHistorico($pdo)) {
            return self::FAILURE;
        }

        if ($desde !== null) {
            $apagados = DB::table('faturamentos')->where('data_emissao', '>=', $desde)->delete();
            $this->info("Removidas {$apagados} linhas de faturamentos a partir de {$desde} (period_merge).");
        } else {
            DB::table('faturamentos')->truncate();
            $this->info('Tabela faturamentos zerada — recarga completa do histórico.');
        }

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
