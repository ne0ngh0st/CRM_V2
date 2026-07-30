<?php

namespace App\Console\Commands;

use App\Services\Legado\LegadoConexao;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportProdutosLegado extends Command
{
    protected $signature = 'legado:import-produtos {--fonte=homolog : homolog ou producao}';

    protected $description = 'Import da tabela de preços (CODIGO_PRODUTOS) do TOTVS pro CRM-V2';

    public function handle(): int
    {
        $fonte = $this->option('fonte');
        $pdo = LegadoConexao::pdo($fonte);

        // CODIGO_PRODUTOS tem ~2,7x mais linhas que códigos distintos (reimport da planilha
        // de preço sem dedup, no legado) — GROUP BY + MAX() colapsa em 1 linha por código,
        // preferindo preço preenchido quando alguma das duplicatas tem e outra não.
        $stmt = $pdo->query(
            'SELECT COD_PROD, MAX(DESC_PROD) AS DESC_PROD, MAX(UN_PROD) AS UN_PROD, '
            .'MAX(PRCVENDA) AS PRCVENDA, MAX(CAT_PROD) AS CAT_PROD '
            .'FROM CODIGO_PRODUTOS GROUP BY COD_PROD'
        );

        $agora = now();
        $lote = [];
        $total = 0;
        $ignorados = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $codProduto = trim((string) $row['COD_PROD']);
            if ($codProduto === '') {
                $ignorados++;

                continue;
            }

            $lote[] = [
                'cod_produto' => $codProduto,
                'descricao' => self::valorOuNull($row['DESC_PROD']) ?? $codProduto,
                'categoria' => self::categoriaOuNull($row['CAT_PROD']),
                'unidade' => self::valorOuNull($row['UN_PROD']),
                'preco_tabela' => $row['PRCVENDA'],
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            if (count($lote) >= 2000) {
                $this->upsertLote($lote);
                $total += count($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->upsertLote($lote);
            $total += count($lote);
        }

        $this->info("Produtos importados: {$total}");
        if ($ignorados > 0) {
            $this->warn("Ignorados (sem cod_produto): {$ignorados}");
        }

        return self::SUCCESS;
    }

    private function upsertLote(array $lote): void
    {
        DB::table('produtos')->upsert(
            $lote,
            ['cod_produto'],
            ['descricao', 'categoria', 'unidade', 'preco_tabela', 'updated_at']
        );
    }

    private static function valorOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }

    private static function categoriaOuNull(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        // Case misto no legado (bobina/BOBINA, suply/SUPLY...) — normaliza pra não duplicar
        // categoria em filtro só por causa de caixa alta/baixa.
        return $valor === '' ? null : mb_strtoupper($valor);
    }
}
