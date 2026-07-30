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
        // O índice (cod_vendedor, data_emissao) já existente só ajuda quem filtra por
        // vendedor. Consulta "empresa inteira" (admin/diretor/supervisor sem filtro)
        // não usa nenhum índice — ver Regra de ouro nº 6 no CLAUDE.md.
        Schema::table('faturamentos', function (Blueprint $table) {
            $table->index('data_emissao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faturamentos', function (Blueprint $table) {
            $table->dropIndex(['data_emissao']);
        });
    }
};
