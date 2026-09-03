<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice em `clientes.nome_fantasia`, para a busca de titularidade.
 *
 * ⚠️ O motivo é menos óbvio do que "busca ficou mais rápida": `razao_social` JÁ era
 * indexada, mas a busca de titularidade casa as duas colunas com um OR — e um OR em que
 * UM dos lados não tem índice derruba o plano inteiro. Medido com os 92.209 clientes
 * reais, procurando o prefixo "KNTT":
 *
 *   só `razao_social`                : 1,2 ms   (type=range, Using index)
 *   `razao_social` OR `nome_fantasia`: 287,3 ms (type=index — varre o índice todo)
 *
 * Ou seja: acrescentar a segunda coluna à busca, sem o índice, custaria 240x. É o mesmo
 * tipo de armadilha do `Extra:` documentada em 2026-08-31 — o `key:` continuava apontando
 * para `clientes_razao_social_index`, e mesmo assim o plano era ruim.
 *
 * Idempotente porque dev e produção podem chegar aqui em estados diferentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->existe('clientes_nome_fantasia_index')) {
            DB::statement('ALTER TABLE clientes ADD INDEX `clientes_nome_fantasia_index` (nome_fantasia)');
        }
    }

    public function down(): void
    {
        if ($this->existe('clientes_nome_fantasia_index')) {
            DB::statement('ALTER TABLE clientes DROP INDEX `clientes_nome_fantasia_index`');
        }
    }

    private function existe(string $indice): bool
    {
        return DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['clientes', $indice],
        ) !== null;
    }
};
