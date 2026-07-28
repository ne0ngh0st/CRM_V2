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
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('tipo_produto_servico', 20)->default('produto')->after('cliente_contato');
            $table->text('observacoes')->nullable()->after('motivo_rejeicao');
            $table->text('variacao_producao_personalizado')->nullable()->after('observacoes');
            $table->string('prazo_producao', 255)->nullable()->after('variacao_producao_personalizado');
            $table->string('garantia_imagem', 255)->nullable()->after('prazo_producao');
            $table->text('texto_importante')->nullable()->after('garantia_imagem');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_produto_servico',
                'observacoes',
                'variacao_producao_personalizado',
                'prazo_producao',
                'garantia_imagem',
                'texto_importante',
            ]);
        });
    }
};
