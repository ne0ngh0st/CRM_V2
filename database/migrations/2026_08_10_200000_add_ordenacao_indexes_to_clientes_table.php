<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices pras colunas que a Carteira ordena e filtra.
     *
     * Medido antes (91.293 clientes, escopo admin sem filtro): `ORDER BY razao_social
     * LIMIT 30` dava `type: ALL` + `Using filesort` e 120ms — ou seja, a tela já
     * varria e ordenava a tabela inteira a cada carregamento, antes mesmo de existir
     * ordenação por clique. Com escopo de vendedor era 0,8ms, porque aí o índice de
     * `cod_vendedor` já resolvia.
     *
     * Aqui o índice funciona (diferente do caso da Regra de ouro nº 6, onde não
     * adiantou): com `ORDER BY <coluna indexada> LIMIT 30` o MySQL lê o índice em
     * ordem e para na 30ª linha, em vez de ordenar as 91 mil. O que decide não é a
     * seletividade da coluna, é o LIMIT.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->index('razao_social');       // ordem padrão da tela + coluna "Cliente"
            $table->index('data_ultima_compra'); // colunas "Última Compra" e "Status"
            $table->index('estado');             // ordena e filtra
            $table->index('cod_segmento');       // ordena e filtra
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['razao_social']);
            $table->dropIndex(['data_ultima_compra']);
            $table->dropIndex(['estado']);
            $table->dropIndex(['cod_segmento']);
        });
    }
};
