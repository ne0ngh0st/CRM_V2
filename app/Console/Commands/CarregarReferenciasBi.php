<?php

namespace App\Console\Commands;

use App\Services\PowerBi\SchemaBi;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carrega os CSVs de `database/dados-bi/` nas tabelas de referência do schema `bi`.
 *
 * O formato é o export do phpMyAdmin descrito no README da pasta: BOM, `;`, vírgula
 * decimal, data `dd/mm/aaaa hh:mm:ss`. O cabeçalho de cada arquivo é conferido
 * EXATAMENTE — um reexport com outras opções falha aqui, em vez de carregar número
 * com a vírgula no lugar errado.
 *
 * Repetível: cada tabela é esvaziada e recarregada dentro de uma transação, então uma
 * falha no meio deixa o conteúdo anterior intacto. São dados derivados (IBGE e de-para),
 * sem nada digitado no CRM — recarregar não perde informação.
 */
class CarregarReferenciasBi extends Command
{
    protected $signature = 'bi:carregar-referencias
        {--pasta=database/dados-bi : pasta dos CSVs, relativa à raiz do projeto}
        {--force : não pedir confirmação}';

    protected $description = 'Carrega IBGE, de-para de município, indicadores e potencial por estado no schema do Power BI';

    /**
     * tabela => [arquivo, [coluna do CSV => tipo]]. Coluna com tipo `null` existe no CSV
     * e não é gravada (o `id` sequencial do legado).
     *
     * @var array<string, array{0: string, 1: array<string, string|null>}>
     */
    private const CARGAS = [
        'ibge_municipios' => ['IBGE_MUNICIPIOS.csv', [
            'id' => null,
            'cod_municipio' => 'int',
            'nome_municipio' => 'texto',
            'cod_micro' => 'int',
            'nome_micro' => 'texto?',
            'cod_meso' => 'int',
            'nome_meso' => 'texto?',
            'cod_uf' => 'int',
            'cod_regiao' => 'int?',
            'nome_regiao' => 'texto?',
            'sigla_uf' => 'texto',
        ]],
        'de_para_municipio' => ['de_para_municipio.csv', [
            'uf' => 'texto',
            'nome_norm' => 'texto',
            'cod_municipio' => 'int',
            'origem' => 'texto',
        ]],
        'indicadores_municipio' => ['IBGE_INDICADORES_MUNICIPIO.csv', [
            'cod_municipio' => 'int',
            'pea_total' => 'decimal',
            'ano_base_pea' => 'int',
            'populacao' => 'decimal',
            'ano_base_pop' => 'int',
            'pib_total_reais' => 'decimal',
            'ano_base_pib' => 'int',
            'num_supermercados' => 'int',
            'supermercados_pessoal_ocupado' => 'int',
            'supermercados_massa_salarial_reais' => 'decimal',
            'ano_base_supermercados' => 'int?',
            'atualizado_em' => 'datahora',
        ]],
        'potencial_mercado_estado' => ['potencial_mercado_estado.csv', [
            'uf' => 'texto',
            'estado' => 'texto',
            'regiao' => 'texto',
            'populacao' => 'int',
            'pib_milhoes' => 'decimal',
            'pib_per_capita' => 'decimal',
            'pct_pib_nacional' => 'decimal',
            'pct_populacao_nacional' => 'decimal',
            'num_pdvs' => 'int',
            'pct_pdvs_nacional' => 'decimal',
            'ipm' => 'decimal',
            'ranking_potencial' => 'int',
            'ano_base_pop' => 'int',
            'ano_base_pib' => 'int',
            'ano_base_pdvs' => 'int',
            'peso_pib' => 'decimal',
            'peso_pop' => 'decimal',
            'peso_pdvs' => 'decimal',
            'atualizado_em' => 'datahora',
        ]],
    ];

    public function handle(): int
    {
        SchemaBi::exigir();

        $pasta = base_path((string) $this->option('pasta'));
        $conexao = config('database.default');

        // Regra de ouro nº 7: o alvo em voz alta antes de escrever.
        $this->newLine();
        $this->warn('  ALVO DA ESCRITA');
        $this->line('    host ...... '.config("database.connections.{$conexao}.host"));
        $this->line('    schema .... '.SchemaBi::nome());
        $this->line('    tabelas ... '.implode(', ', array_keys(self::CARGAS)).' (esvaziadas e recarregadas)');
        $this->line("    origem .... {$pasta}");
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Confirma?', false)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        try {
            foreach (self::CARGAS as $tabela => [$arquivo, $colunas]) {
                $linhas = $this->ler("{$pasta}/{$arquivo}", $colunas);

                DB::transaction(function () use ($tabela, $linhas) {
                    DB::table(SchemaBi::nome().'.'.$tabela)->delete();

                    foreach (array_chunk($linhas, 1000) as $lote) {
                        DB::table(SchemaBi::nome().'.'.$tabela)->insert($lote);
                    }
                });

                $this->line(sprintf('  %-25s %s linhas', $tabela, number_format(count($linhas), 0, ',', '.')));
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|null>  $colunas
     * @return list<array<string, mixed>>
     */
    private function ler(string $caminho, array $colunas): array
    {
        if (! is_readable($caminho)) {
            throw new RuntimeException("Arquivo não encontrado: {$caminho}");
        }

        $fh = fopen($caminho, 'r');

        // ⚠️ O BOM tem que sair ANTES do fgetcsv. Depois é tarde: o campo começa com o
        // BOM e não com a aspa, então o parser não reconhece o campo como citado e o
        // nome vem `"id"`, com as aspas. Tirar o BOM do resultado não resolve isso.
        if (fread($fh, 3) !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $cabecalho = fgetcsv($fh, 0, ';');

        if ($cabecalho !== array_keys($colunas)) {
            fclose($fh);

            throw new RuntimeException(
                basename($caminho).': cabeçalho inesperado.'
                ."\n  esperado: ".implode(';', array_keys($colunas))
                ."\n  recebido: ".implode(';', $cabecalho)
            );
        }

        $linhas = [];
        $numero = 1;

        while (($campos = fgetcsv($fh, 0, ';')) !== false) {
            $numero++;

            if ($campos === [null]) {
                continue;
            }

            if (count($campos) !== count($colunas)) {
                fclose($fh);

                throw new RuntimeException(basename($caminho).": linha {$numero} com ".count($campos).' campos.');
            }

            $registro = [];

            foreach (array_combine(array_keys($colunas), $campos) as $coluna => $valor) {
                $tipo = $colunas[$coluna];

                if ($tipo !== null) {
                    $registro[$coluna] = $this->converter($valor, $tipo, basename($caminho), $numero);
                }
            }

            $linhas[] = $registro;
        }

        fclose($fh);

        return $linhas;
    }

    private function converter(string $valor, string $tipo, string $arquivo, int $linha): mixed
    {
        $valor = trim($valor);
        $opcional = str_ends_with($tipo, '?');
        $tipo = rtrim($tipo, '?');

        if ($valor === '') {
            if ($opcional) {
                return null;
            }

            throw new RuntimeException("{$arquivo}: linha {$linha} com campo obrigatório vazio.");
        }

        return match ($tipo) {
            'texto' => $valor,
            'int' => ctype_digit(ltrim($valor, '-')) ? (int) $valor
                : throw new RuntimeException("{$arquivo}: linha {$linha}, '{$valor}' não é inteiro."),
            // Export em pt-BR: sem separador de milhar, vírgula decimal ("10526,00").
            'decimal' => preg_match('/^-?\d+(,\d+)?$/', $valor) === 1 ? str_replace(',', '.', $valor)
                : throw new RuntimeException("{$arquivo}: linha {$linha}, '{$valor}' não é decimal pt-BR."),
            'datahora' => Carbon::createFromFormat('d/m/Y H:i:s', $valor)->format('Y-m-d H:i:s'),
        };
    }
}
