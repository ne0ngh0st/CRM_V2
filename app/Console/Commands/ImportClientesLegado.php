<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoConexao;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportClientesLegado extends Command
{
    protected $signature = 'legado:import-clientes
        {--fonte=homolog : homolog (espelho local, padrão) ou producao}
        {--chunk=1000 : tamanho do lote de upsert}';

    protected $description = 'Import (upsert em lote) de clientes + data de última compra do TOTVS pro CRM-V2';

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $chunk = (int) $this->option('chunk');
        $pdo = LegadoConexao::pdo($fonte);

        $this->info("Lendo ultimo_faturamento ({$fonte})...");
        $ultimaCompra = $this->carregarUltimaCompra($pdo);
        $this->info(sprintf('%d combinações cod_cliente+loja com data de última compra.', count($ultimaCompra)));

        $this->info("Lendo clientes ({$fonte})...");
        $stmt = $pdo->query(
            'SELECT COD_CLIENT, LOJA, CNPJ, CLIENTE, NOME_FANTASIA, COD_VENDEDOR, COD_SEG, '
            .'Estado, CEP, DDD, Telefone, EMailNFe FROM CLIENTES'
        );

        $lote = [];
        $total = 0;
        $ignorados = 0;
        $agora = now();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $codCliente = trim((string) $row['COD_CLIENT']);
            $loja = trim((string) $row['LOJA']);

            if ($codCliente === '' || $loja === '') {
                $ignorados++;

                continue;
            }

            $lote[] = [
                'cod_cliente' => $codCliente,
                'loja' => $loja,
                'cnpj' => self::formatarDocumento($row['CNPJ']),
                'razao_social' => trim((string) $row['CLIENTE']),
                'nome_fantasia' => self::valorOuNull($row['NOME_FANTASIA'] ?? ''),
                'cod_vendedor' => self::valorOuNull($row['COD_VENDEDOR'] ?? ''),
                'cod_segmento' => self::normalizarSegmento($row['COD_SEG'] ?? ''),
                'estado' => self::valorOuNull($row['Estado'] ?? ''),
                'cep' => self::valorOuNull($row['CEP'] ?? ''),
                'telefone' => self::montarTelefone($row['DDD'] ?? '', $row['Telefone'] ?? ''),
                'email' => self::emailOuNull($row['EMailNFe'] ?? ''),
                'data_ultima_compra' => $ultimaCompra[$codCliente.'|'.$loja] ?? null,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= $chunk) {
                $this->upsertLote($lote);
                $total += count($lote);
                $this->line("  {$total} processados...");
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->upsertLote($lote);
            $total += count($lote);
        }

        $this->info("Importados/atualizados: {$total}");
        if ($ignorados > 0) {
            $this->warn("Ignorados (sem cod_cliente ou loja): {$ignorados}");
        }

        return self::SUCCESS;
    }

    /**
     * ultimo_faturamento já é o rollup de última compra por cliente pré-calculado pelo
     * TOTVS/pipeline — não agregar FATURAMENTO (900k+ linhas) na mão.
     *
     * @return array<string, string> chave "cod_cliente|loja" => data (Y-m-d)
     */
    private function carregarUltimaCompra(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT COD_CLIENT, LOJA, DT_FAT FROM ultimo_faturamento');
        $mapa = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dtFat = trim((string) ($row['DT_FAT'] ?? ''));
            if ($dtFat === '') {
                continue;
            }

            $data = DateTime::createFromFormat('d/m/Y', $dtFat);
            if (! $data) {
                continue;
            }

            $chave = trim((string) $row['COD_CLIENT']).'|'.trim((string) $row['LOJA']);
            $mapa[$chave] = $data->format('Y-m-d');
        }

        return $mapa;
    }

    private function upsertLote(array $lote): void
    {
        DB::table('clientes')->upsert(
            $lote,
            ['cod_cliente', 'loja'],
            [
                'cnpj', 'razao_social', 'nome_fantasia', 'cod_vendedor', 'cod_segmento',
                'estado', 'cep', 'telefone', 'email', 'data_ultima_compra', 'updated_at',
            ]
        );
    }

    private static function valorOuNull(string $valor): ?string
    {
        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * COD_SEG vem do TOTVS com zero-padding inconsistente — o mesmo código de
     * segmento aparece como "101" e como "000101" dependendo do registro, o que
     * quebrava o match com `segmentos.codigo` (join de aderência da Carteira).
     */
    private static function normalizarSegmento(string $valor): ?string
    {
        $valor = trim($valor);

        if ($valor === '') {
            return null;
        }

        return ctype_digit($valor) ? (string) ((int) $valor) : $valor;
    }

    private static function emailOuNull(string $valor): ?string
    {
        $valor = trim($valor);

        return ($valor === '' || $valor === '.') ? null : $valor;
    }

    /**
     * DDD vem com zero-padding inconsistente no espelho (ex. "000031" em vez de "31").
     */
    private static function montarTelefone(string $ddd, string $telefone): ?string
    {
        $ddd = ltrim(trim($ddd), '0');
        $telefone = trim($telefone);

        if ($telefone === '') {
            return null;
        }

        return $ddd !== '' ? "({$ddd}) {$telefone}" : $telefone;
    }

    private static function formatarDocumento(?string $bruto): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $bruto);

        if ($digitos === '') {
            return null;
        }

        if (strlen($digitos) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos);
        }

        if (strlen($digitos) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digitos);
        }

        return $digitos;
    }
}
