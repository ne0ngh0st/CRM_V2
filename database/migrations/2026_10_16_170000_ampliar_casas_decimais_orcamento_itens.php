<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantidade e valor unitário do orçamento nasceram decimal(12,2). O vendedor
 * precisa digitar 3 casas (preço por unidade, quantidade fracionada). Com 2 casas
 * o MySQL arredonda a terceira em silêncio (Note 1265) — o mesmo dano do
 * preco_tabela antes da migration 2026_09_03_090000.
 *
 * O total da linha e o total do documento continuam em 2 casas: são centavos,
 * round(quantidade × valor_unitario, 2). Só amplia. Nenhum valor já gravado se perde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->decimal('quantidade', 12, 3)->change();
            $table->decimal('valor_unitario', 12, 3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->decimal('quantidade', 12, 2)->change();
            $table->decimal('valor_unitario', 12, 2)->change();
        });
    }
};
