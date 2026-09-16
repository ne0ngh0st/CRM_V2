<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gera o de-para produto → família que o conversor de xlsx usa nos anos sem família.
 *
 * Os xlsx de 2018 a 2023 não têm `DESC_FAMILIA`; 2024, 2025 e o relatório 198 têm. A
 * família é uma propriedade do PRODUTO, então o que falta nos anos antigos sai do que já
 * foi gravado nos recentes: `scripts/faturamento_xlsx_para_csv.py --familias <este CSV>`.
 *
 * ⚠️ A fonte é a tabela `faturamentos`, não só o 198 do mês: o 198 cobre um mês e só
 * conhece os produtos vendidos nele; 2024–2026 inteiros conhecem muito mais. Por isso a
 * ORDEM da carga histórica importa: reimportar 2024 e 2025 (e rodar o 198) ANTES de
 * gerar este arquivo, e só então converter 2018–2023.
 *
 * Produto que apareceu com mais de uma família fica com a MAIS RECENTE — o cadastro do
 * TOTVS é o de hoje, e é ele que o relatório atual reflete. Os casos são contados e
 * listados para conferência, não escondidos.
 *
 * Só lê. Uma varredura em `faturamentos`, sem índice que ajude: rodar fora do horário de
 * uso em produção.
 */
class ExportarFamiliasDeProduto extends Command
{
    protected $signature = 'faturamento:de-para-familias
        {saida=storage/app/bi/de_para_familias.csv : caminho do CSV, relativo à raiz do projeto}';

    protected $description = 'Gera o CSV produto → família (a partir de faturamentos) para a carga dos anos sem DESC_FAMILIA';

    public function handle(): int
    {
        $saida = $this->caminhoAbsoluto((string) $this->argument('saida'));

        $linhas = DB::table('faturamentos')
            ->selectRaw('UPPER(TRIM(cod_produto)) AS cod, desc_familia, MAX(data_emissao) AS ultima, COUNT(*) AS n')
            ->whereNotNull('cod_produto')
            ->whereNotNull('desc_familia')
            ->groupByRaw('UPPER(TRIM(cod_produto)), desc_familia')
            ->orderByRaw('cod, ultima')
            ->get();

        /** @var array<string, string> $familiaPorProduto */
        $familiaPorProduto = [];
        /** @var array<string, list<string>> $conflitos */
        $conflitos = [];

        foreach ($linhas as $linha) {
            $cod = (string) $linha->cod;

            if (isset($familiaPorProduto[$cod]) && $familiaPorProduto[$cod] !== $linha->desc_familia) {
                $conflitos[$cod] ??= [$familiaPorProduto[$cod]];
                $conflitos[$cod][] = $linha->desc_familia;
            }

            // Ordenado por data: a última atribuição é a mais recente.
            $familiaPorProduto[$cod] = $linha->desc_familia;
        }

        if ($familiaPorProduto === []) {
            $this->error('Nenhuma linha de faturamento com família. Importe 2024/2025 ou o 198 antes.');

            return self::FAILURE;
        }

        if (! is_dir(dirname($saida))) {
            mkdir(dirname($saida), 0775, true);
        }

        $fh = fopen($saida, 'w');
        fputcsv($fh, ['cod_produto', 'desc_familia']);

        foreach ($familiaPorProduto as $cod => $familia) {
            fputcsv($fh, [$cod, $familia]);
        }

        fclose($fh);

        $this->info(number_format(count($familiaPorProduto), 0, ',', '.').' produtos com família → '.$saida);

        if ($conflitos !== []) {
            $this->warn(count($conflitos).' produto(s) com mais de uma família — ficou a mais recente:');

            foreach (array_slice($conflitos, 0, 20, true) as $cod => $familias) {
                $this->line("  {$cod}: ".implode(' → ', $familias));
            }

            if (count($conflitos) > 20) {
                $this->line('  …');
            }
        }

        return self::SUCCESS;
    }

    private function caminhoAbsoluto(string $caminho): string
    {
        return str_starts_with($caminho, '/') ? $caminho : base_path($caminho);
    }
}
