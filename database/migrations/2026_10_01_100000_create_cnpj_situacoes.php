<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Situação cadastral na Receita, um registro por CNPJ de 14 dígitos.
 *
 * Não é o `cnpj_consultas`: aquela guarda o CARTÃO inteiro de quem clicou no botão
 * (uma consulta por vez, pela API). Esta guarda só a situação, para a base INTEIRA
 * de leads e clientes, e é preenchida pela carga mensal da base aberta da Receita
 * (`receita:importar-situacoes`). O botão do cartão também escreve aqui quando
 * consulta — é o que faz esta tabela ser a resposta única a "este CNPJ está ativo?".
 *
 * Chaveada pelo CNPJ e não pelo lead de propósito: o import de leads do legado apaga e
 * recria as linhas, e uma marca em `leads` sumiria junto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnpj_situacoes', function (Blueprint $table) {
            $table->char('cnpj', 14)->primary();
            // ATIVA, SUSPENSA, INAPTA, BAIXADA, NULA ou INEXISTENTE (não está na base).
            $table->string('situacao', 20)->index();
            $table->date('data_situacao')->nullable();
            // 'receita_base' (carga mensal) ou o nome da API que respondeu o cartão.
            $table->string('fonte', 20);
            // Mês da base da Receita (AAAA-MM). Nulo quando veio do cartão.
            $table->char('referencia', 7)->nullable();
            $table->dateTime('atualizado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnpj_situacoes');
    }
};
