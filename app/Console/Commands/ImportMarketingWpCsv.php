<?php

namespace App\Console\Commands;

use App\Services\Marketing\WpLeadIngestor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa histórico exportado do WordPress em CSV para staging + lead comercial.
 *
 * Cada linha vira um envelope JSON (fonte=historico_csv) — as colunas do
 * cabeçalho são as chaves, nada é descartado. Se o CSV tiver uma coluna de
 * data reconhecível, ela vira `recebido_em` (volume histórico real); senão
 * fica a data do import.
 *
 * Uso (dentro do container):
 *   php artisan marketing:import-wp-csv caminho/arquivo.csv [rotulo]
 */
class ImportMarketingWpCsv extends Command
{
    protected $signature = 'marketing:import-wp-csv
        {arquivo : caminho do CSV (UTF-8, com ou sem BOM)}
        {rotulo? : rótulo do formulário (contato, orçamento…)}
        {--force : não pedir confirmação}';

    protected $description = 'Importa CSV histórico de leads do WordPress para staging e /leads (origem=wordpress)';

    public function __construct(
        private readonly WpLeadIngestor $ingestor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $arquivo = (string) $this->argument('arquivo');
        $rotuloArg = $this->argument('rotulo');
        $rotulo = is_string($rotuloArg) && trim($rotuloArg) !== '' ? trim($rotuloArg) : null;

        if (! is_readable($arquivo)) {
            $this->error("Arquivo não encontrado ou sem permissão de leitura: {$arquivo}");
            $this->line('Lembre que este comando roda DENTRO do container — o caminho tem que ser visível lá.');

            return self::FAILURE;
        }

        $conexao = config('database.default');
        $banco = config("database.connections.{$conexao}.database");
        $host = config("database.connections.{$conexao}.host");

        $this->newLine();
        $this->warn('  ALVO DA ESCRITA');
        $this->line("    conexao ... {$conexao}");
        $this->line("    host ...... {$host}");
        $this->line("    banco ..... {$banco}");
        $this->line('    tabelas ... marketing_wp_leads_raw + leads (origem=wordpress)');
        $this->line('    acao ...... APENAS insere (nada é apagado)');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Confirma?', false)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        $handle = fopen($arquivo, 'rb');
        if ($handle === false) {
            $this->error('Não foi possível abrir o arquivo.');

            return self::FAILURE;
        }

        try {
            return $this->importar($handle, basename($arquivo), $rotulo);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function importar($handle, string $basename, ?string $rotulo): int
    {
        $delimiter = $this->detectarDelimitador($handle);
        $headers = fgetcsv($handle, 0, $delimiter);

        if ($headers === false || $headers === [null]) {
            $this->error('CSV sem cabeçalho válido ou arquivo vazio.');

            return self::FAILURE;
        }

        $headers = array_map(static function ($h, $idx) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);
            $key = trim((string) $h);

            return $key !== '' ? $key : 'col_'.$idx;
        }, $headers, array_keys($headers));

        $lineNo = 1;
        $ok = 0;
        $erros = [];
        $userAgent = 'CLI marketing:import-wp-csv';

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNo++;
            if ($row === [null]) {
                continue;
            }

            $packed = [];
            foreach ($headers as $i => $name) {
                $packed[$name] = $row[$i] ?? '';
            }

            $allEmpty = true;
            foreach ($packed as $v) {
                if (trim((string) $v) !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }

            try {
                $this->ingestor->ingerirDoCsv($packed, $basename, $rotulo, $lineNo, $userAgent);
                $ok++;
            } catch (Throwable $e) {
                $erros[] = "linha {$lineNo}: ".$e->getMessage();
            }
        }

        $this->info("Importadas: {$ok} linha(s).");
        if ($erros !== []) {
            $this->warn("Avisos/erros:\n".implode("\n", $erros));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  resource  $handle
     */
    private function detectarDelimitador($handle): string
    {
        $peek = fread($handle, 3);
        $temBom = $peek === "\xEF\xBB\xBF";
        if (! $temBom) {
            rewind($handle);
        }

        $amostra = fread($handle, 4096) ?: '';
        $delimiter = ';';
        if (str_contains($amostra, ',') && substr_count($amostra, ';') <= substr_count($amostra, ',')) {
            $delimiter = ',';
        }

        if ($temBom) {
            fseek($handle, 3);
        } else {
            rewind($handle);
        }

        return $delimiter;
    }
}
