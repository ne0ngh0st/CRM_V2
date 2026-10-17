<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maiores redes: o veredito "a rede existe e vale trabalhar?" (`SituacaoDaRede`), gravado
 * por `MaioresRedes::recalcular()` para a tela filtrar e contar pelo banco, e as lojas
 * ativas com o nome da rede na Receita (de qualquer empresa) — é o que diz que uma
 * franquia, que não tem CNPJ único, continua existindo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redes_mercado', function (Blueprint $table) {
            $table->unsignedInteger('cnpj_lojas_com_nome_ativas')->nullable()->after('cnpj_lojas_ativas');
            $table->string('situacao_rede', 20)->nullable()->after('status');
            $table->index(['ativa', 'categoria', 'situacao_rede']);
        });
    }

    public function down(): void
    {
        Schema::table('redes_mercado', function (Blueprint $table) {
            $table->dropIndex(['ativa', 'categoria', 'situacao_rede']);
            $table->dropColumn(['cnpj_lojas_com_nome_ativas', 'situacao_rede']);
        });
    }
};
