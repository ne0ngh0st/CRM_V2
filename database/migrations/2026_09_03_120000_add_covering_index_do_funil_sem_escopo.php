<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice do funil para quem NÃO filtra por vendedor (admin e diretor).
 *
 * A `leads_funil_idx` (cod_vendedor, etapa, etapa_alterada_em) resolve o vendedor e o
 * supervisor: medido em 1,7 ms. Mas ela começa por `cod_vendedor`, então o admin — que
 * não filtra nada — não a alcança, e o MySQL caía em `leads_status_index`:
 *
 *   contagem por coluna : type=ref key=leads_status_index Extra=Using temporary
 *   cards de uma coluna : type=ref key=leads_status_index Extra=Using where; Using filesort
 *
 * ⚠️ Repare que havia índice ESCOLHIDO — `key` não era NULL. Quem denunciava era o
 * `Extra:`. É a mesma lição de 2026-08-31 nos faturamentos: índice escolhido e índice
 * suficiente são coisas diferentes, e olhar só o `key:` esconde exatamente este caso.
 *
 * Medido com os 17.173 leads reais, escopo admin:
 *
 *   |                        |    antes |  depois |    ganho |
 *   |------------------------|---------:|--------:|---------:|
 *   | contagem das 4 colunas |  55,1 ms | 10,4 ms |    5,3x  |
 *   | cards das 4 colunas    | 281,1 ms |  8,1 ms |   34,7x  |
 *
 * 336 ms só para desenhar o quadro estouraria sozinho o orçamento de 400 ms da Regra
 * nº 9 — e isso num MySQL local, sem a rede do RDS no meio. Depois: 19 ms.
 *
 * O `Extra:` das duas consultas passou de `Using temporary` / `Using filesort` para
 * `Using index` — ou seja, a leitura acontece inteira dentro do índice.
 *
 * `leads_status_index` vira prefixo deste e sai, como foi feito nos faturamentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Criar ANTES de dropar: nunca deixar a tabela sem índice que sustente a consulta.
        $this->criarSeFaltar('leads_funil_geral_idx', '(status, etapa, etapa_alterada_em)');
        $this->droparSeExistir('leads_status_index');
    }

    public function down(): void
    {
        $this->criarSeFaltar('leads_status_index', '(status)');
        $this->droparSeExistir('leads_funil_geral_idx');
    }

    /** Dev e produção podem chegar aqui em estados diferentes — mesma lição da 110000. */
    private function criarSeFaltar(string $indice, string $colunas): void
    {
        if (! $this->existe($indice)) {
            DB::statement("ALTER TABLE leads ADD INDEX `{$indice}` {$colunas}");
        }
    }

    private function droparSeExistir(string $indice): void
    {
        if ($this->existe($indice)) {
            DB::statement("ALTER TABLE leads DROP INDEX `{$indice}`");
        }
    }

    private function existe(string $indice): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['leads', $indice],
        ) !== null;
    }
};
