<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carga do faturamento historico a partir de um CSV ja normalizado por
 * `scripts/faturamento_xlsx_para_csv.py`.
 *
 * POR QUE UM COMANDO SEPARADO DO `legado:import-faturamento`:
 * o outro le o espelho `autopel01_homolog` e, sem `--desde`, faz TRUNCATE na tabela
 * inteira. Isso e adequado para o espelho (que e recarregado por completo a cada
 * rodada) e catastrofico para carga historica -- o Excel de 2019 nao contem 2026,
 * entao um truncate antes de importar 2019 apagaria todo o resto.
 *
 * ⚠️ ESTE COMANDO NUNCA TRUNCA. Com `--ano`, apaga apenas aquele ano antes de
 * inserir, o que o torna repetivel sem duplicar. Sem `--ano`, so acrescenta.
 */
class ImportFaturamentoArquivo extends Command
{
    protected $signature = 'legado:import-faturamento-arquivo
        {arquivo : caminho do CSV gerado por scripts/faturamento_xlsx_para_csv.py}
        {--ano= : apaga este ano antes de inserir, tornando a carga repetivel}
        {--chunk=2000 : tamanho do lote de insert}
        {--force : nao pedir confirmacao (use apenas em script)}';

    protected $description = 'Importa faturamento historico de um CSV normalizado, sem truncar a tabela';

    /** Ordem exata das colunas escritas pelo conversor. */
    private const COLUNAS = [
        'filial', 'nota_fiscal', 'pedido', 'data_emissao', 'cod_cliente', 'cnpj',
        'cliente_nome', 'cod_vendedor', 'cod_produto', 'produto_desc', 'segmento',
        'quantidade', 'valor_unitario', 'valor_total',
    ];

    public function handle(): int
    {
        $arquivo = (string) $this->argument('arquivo');
        $chunk = max(100, (int) $this->option('chunk'));
        $ano = $this->option('ano') !== null ? (int) $this->option('ano') : null;

        if (! is_readable($arquivo)) {
            $this->error("Arquivo nao encontrado ou sem permissao de leitura: {$arquivo}");
            $this->line('Lembre que este comando roda DENTRO do container — o caminho tem que ser visivel la.');

            return self::FAILURE;
        }

        if ($ano !== null && ($ano < 2000 || $ano > 2100)) {
            $this->error("Ano invalido: {$ano}");

            return self::FAILURE;
        }

        // Regra de ouro nº 7: dizer em voz alta contra qual banco isto vai rodar,
        // ANTES de qualquer escrita. O incidente de 2026-08-10 nasceu de um comando
        // que escreveu no banco errado sem que ninguem tivesse conferido o alvo.
        $conexao = config('database.default');
        $banco = config("database.connections.{$conexao}.database");
        $host = config("database.connections.{$conexao}.host");

        $this->newLine();
        $this->warn('  ALVO DA ESCRITA');
        $this->line("    conexao ... {$conexao}");
        $this->line("    host ...... {$host}");
        $this->line("    banco ..... {$banco}");
        $this->line('    tabela .... faturamentos');
        $this->line($ano !== null
            ? "    acao ...... APAGA o ano {$ano} e insere o arquivo"
            : '    acao ...... APENAS insere (nada e apagado)');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Confirma?', false)) {
            $this->line('Cancelado.');

            return self::SUCCESS;
        }

        $fh = fopen($arquivo, 'r');
        if ($fh === false) {
            $this->error('Nao consegui abrir o arquivo.');

            return self::FAILURE;
        }

        $cabecalho = fgetcsv($fh);
        if ($cabecalho === false) {
            fclose($fh);
            $this->error('Arquivo vazio.');

            return self::FAILURE;
        }
        $cabecalho = array_map(fn ($c) => trim((string) $c), $cabecalho);

        if ($cabecalho !== self::COLUNAS) {
            fclose($fh);
            $this->error('Cabecalho do CSV nao confere com o esperado.');
            $this->line('  esperado: '.implode(',', self::COLUNAS));
            $this->line('  recebido: '.implode(',', $cabecalho));
            $this->line('Gere o CSV com scripts/faturamento_xlsx_para_csv.py.');

            return self::FAILURE;
        }

        if ($ano !== null) {
            $apagados = DB::table('faturamentos')
                ->whereBetween('data_emissao', ["{$ano}-01-01", "{$ano}-12-31"])
                ->delete();
            $this->info("Removidas {$apagados} linhas de {$ano} (carga repetivel).");
        }

        // Sem isto o Laravel acumula toda query executada em memoria durante a
        // importacao -- com milhoes de inserts isso sozinho estoura o processo.
        DB::connection()->disableQueryLog();

        $agora = now();
        $lote = [];
        $total = $ignoradas = $foraDoAno = 0;

        while (($linha = fgetcsv($fh)) !== false) {
            if (count($linha) !== count(self::COLUNAS)) {
                $ignoradas++;

                continue;
            }

            $registro = array_combine(self::COLUNAS, $linha);

            if (trim((string) $registro['data_emissao']) === '') {
                $ignoradas++;

                continue;
            }

            // Rede de seguranca: se o arquivo trouxer linha de outro ano, ela nao
            // seria apagada por uma reimportacao com --ano e viraria duplicata.
            if ($ano !== null && ! str_starts_with($registro['data_emissao'], (string) $ano)) {
                $foraDoAno++;

                continue;
            }

            $lote[] = [
                'filial' => self::intOuNull($registro['filial']),
                'nota_fiscal' => self::valorOuNull($registro['nota_fiscal']),
                'pedido' => self::valorOuNull($registro['pedido']),
                'data_emissao' => $registro['data_emissao'],
                'cod_cliente' => self::valorOuNull($registro['cod_cliente']),
                'cnpj' => self::valorOuNull($registro['cnpj']),
                'cliente_nome' => self::valorOuNull($registro['cliente_nome']),
                'cod_vendedor' => trim((string) $registro['cod_vendedor']),
                'cod_produto' => self::valorOuNull($registro['cod_produto']),
                'produto_desc' => self::valorOuNull($registro['produto_desc']),
                'segmento' => self::valorOuNull($registro['segmento']),
                'quantidade' => self::numeroOuNull($registro['quantidade']),
                'valor_unitario' => self::numeroOuNull($registro['valor_unitario']),
                'valor_total' => self::numeroOuNull($registro['valor_total']) ?? 0,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= $chunk) {
                DB::table('faturamentos')->insert($lote);
                $total += count($lote);
                $lote = [];

                if ($total % 100000 === 0) {
                    $this->line("  {$total} inseridos...");
                }
            }
        }

        if ($lote !== []) {
            DB::table('faturamentos')->insert($lote);
            $total += count($lote);
        }

        fclose($fh);

        $this->newLine();
        $this->info("Inseridos: {$total}");
        if ($ignoradas > 0) {
            $this->warn("Ignoradas (linha malformada ou sem data): {$ignoradas}");
        }
        if ($foraDoAno > 0) {
            $this->warn("Ignoradas (fora do ano {$ano}): {$foraDoAno}");
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
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : (int) $valor;
    }

    private static function numeroOuNull(mixed $valor): ?float
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : (float) $valor;
    }
}
