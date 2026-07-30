<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Troca `segmento` (texto livre, digitado à mão, sem relação com o código real
 * do TOTVS) por `segmento_id`, apontando pra tabela `segmentos` (código+nome
 * reais, importados de `ultimo_faturamento.Segmento1`/`Descricao1`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segmentos_vendedor', function (Blueprint $table) {
            $table->dropColumn('segmento');
            $table->foreignId('segmento_id')->after('cod_vendedor')->constrained('segmentos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('segmentos_vendedor', function (Blueprint $table) {
            $table->dropConstrainedForeignId('segmento_id');
            $table->string('segmento')->after('cod_vendedor');
        });
    }
};
