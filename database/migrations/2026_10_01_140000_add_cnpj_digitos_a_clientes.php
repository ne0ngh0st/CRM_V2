<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Os 14 dígitos do CNPJ, calculados pelo banco a partir de `clientes.cnpj` (que é
 * gravado com máscara pelo `Normalizador::documento()`).
 *
 * Existe para a Carteira cruzar com `cnpj_situacoes` (situação na Receita) por
 * IGUALDADE e com índice. Sem ela o `ON` teria que ser um `REPLACE`/`REGEXP_REPLACE`
 * sobre a coluna, que varre os 92 mil clientes a cada página.
 *
 * STORED, e não VIRTUAL, porque o índice é o ponto. É coluna gerada: nenhum import
 * precisa saber que ela existe, e ela não tem como divergir do `cnpj`.
 *
 * ⚠️ Em produção o ALTER reconstrói a tabela (~92k linhas, segundos). Durante isso a
 * escrita em `clientes` espera — rodar fora da hora cheia do `totvs:atualizar`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('cnpj_digitos', 14)
                ->storedAs("REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', '')")
                ->nullable()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['cnpj_digitos']);
            $table->dropColumn('cnpj_digitos');
        });
    }
};
