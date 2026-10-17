<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maiores redes: o CNPJ da PRÓPRIA rede (a matriz da empresa dona das lojas), achado na
 * base aberta da Receita pelo `visao-diretor:cnpj-das-redes`, ou informado à mão pela tela.
 * A situação dele NÃO mora aqui: é o `cnpj_situacoes` (a resposta única a "este CNPJ está
 * ativo?"), que a carga semanal mantém em dia.
 *
 * É o CNPJ de 14 dígitos da matriz, não a raiz (Regra de ouro nº 3).
 *
 * `filiais_cnpj_irregular` é contador como os outros da linha (`MaioresRedes::recalcular()`):
 * filiais dos NOSSOS clientes da rede com CNPJ não ativo na Receita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redes_mercado', function (Blueprint $table) {
            $table->char('cnpj', 14)->nullable()->after('linha_original');
            $table->string('cnpj_razao_social', 150)->nullable()->after('cnpj');
            // `CnpjDasRedes::CONFIANCAS`. `manual` = informado pela tela; a carga não mexe.
            $table->string('cnpj_confianca', 20)->nullable()->after('cnpj_razao_social');
            // Lojas ativas da empresa no ramo da categoria, na base da Receita.
            $table->unsignedInteger('cnpj_lojas_ativas')->nullable()->after('cnpj_confianca');
            // Mês da base da Receita em que foi achado (AAAA-MM). Nulo no manual.
            $table->char('cnpj_referencia', 7)->nullable()->after('cnpj_lojas_ativas');
            $table->foreignId('cnpj_user_id')->nullable()->after('cnpj_referencia')->constrained('users')->nullOnDelete();

            $table->unsignedInteger('filiais_cnpj_irregular')->default(0)->after('filiais');
        });
    }

    public function down(): void
    {
        Schema::table('redes_mercado', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cnpj_user_id');
            $table->dropColumn(['cnpj', 'cnpj_razao_social', 'cnpj_confianca', 'cnpj_lojas_ativas', 'cnpj_referencia', 'filiais_cnpj_irregular']);
        });
    }
};
