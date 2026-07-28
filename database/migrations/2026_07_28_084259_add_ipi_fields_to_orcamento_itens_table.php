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
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->boolean('calcula_ipi')->default(true)->after('preco_tabela');
            $table->json('etiqueta_calc')->nullable()->after('calcula_ipi');
            $table->foreignId('materia_prima_id')->nullable()->after('etiqueta_calc')
                ->constrained('etiquetas_materia_prima')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->dropForeign(['materia_prima_id']);
            $table->dropColumn(['calcula_ipi', 'etiqueta_calc', 'materia_prima_id']);
        });
    }
};
