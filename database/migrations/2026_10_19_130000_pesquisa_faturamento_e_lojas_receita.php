<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pesquisa de mercado da planilha Supermercadista (2026-10-19).
 *
 * - `lojas_receita` repete, em cada CNPJ de 14 dígitos, quantos estabelecimentos ATIVOS
 *   com CNAE de loja aquela empresa tem na base da Receita. A raiz é calculada na carga
 *   e não vira coluna (Regra de ouro nº 3) — o mesmo desenho do capital social.
 * - `faturamento_mercado` é o último faturamento anual achado da empresa (âncora de 14
 *   dígitos) ou, quando a rede da ABRAS não tem CNPJ, da própria conta (`chave` = c + id).
 *   A tela não lê esta tabela.
 * - O número da ABRAS também fica na conta, para a carga copiar nas empresas ligadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->unsignedInteger('lojas_receita')->nullable()->after('porte');
        });

        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->unsignedBigInteger('faturamento_abras')->nullable()->after('posicao_abras');
            $table->unsignedSmallInteger('faturamento_abras_ano')->nullable()->after('faturamento_abras');
        });

        Schema::create('faturamento_mercado', function (Blueprint $table) {
            $table->id();
            // CNPJ de 14 dígitos da âncora, ou "c" + id da conta quando a rede não tem CNPJ.
            $table->string('chave', 20)->unique();
            $table->foreignId('conta_id')->nullable()->constrained('contas_estrategicas')->nullOnDelete();
            // Reais. Nulo = já consultado e a fonte não declarou um número.
            $table->unsignedBigInteger('valor')->nullable();
            $table->unsignedSmallInteger('ano')->nullable();
            $table->string('fonte', 10); // abras | web
            $table->string('url', 500)->nullable();
            $table->dateTime('consultado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturamento_mercado');

        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->dropColumn(['faturamento_abras', 'faturamento_abras_ano']);
        });

        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->dropColumn('lojas_receita');
        });
    }
};
