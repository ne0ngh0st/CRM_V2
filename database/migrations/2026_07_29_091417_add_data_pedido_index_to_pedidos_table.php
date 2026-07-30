<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // (cod_vendedor, data_pedido) só ajuda quem filtra por vendedor. A página de
        // Pedidos Emitidos filtra por período pra empresa inteira (admin/diretor/
        // supervisor) — sem índice próprio em data_pedido isso vira index/table scan
        // conforme a tabela cresce (legado tem 407k linhas de histórico equivalente;
        // ver Regra de ouro nº 6 no CLAUDE.md).
        Schema::table('pedidos', function (Blueprint $table) {
            $table->index('data_pedido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex(['data_pedido']);
        });
    }
};
