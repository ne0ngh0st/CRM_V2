<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Índices de ordenação para `leads` e `pedidos`.
 *
 * ⚠️ ISTO É A MIGRATION DE 2026_08_10_200000 TERMINADA.
 * Aquela adicionou índices de ordenação em `clientes` (razao_social, data_ultima_compra,
 * estado, cod_segmento) e derrubou o ORDER BY da Carteira de 120 ms para 0,67 ms. As
 * tabelas irmãs, que têm telas com o MESMO desenho — listagem paginada, ordenável por
 * clique no header —, ficaram de fora sem que ninguém notasse.
 *
 * O sintoma reapareceu no teste de carga de 2026-08-27, onde /leads teve o pior p95
 * (1.159 ms). Medido isolado: `SELECT * FROM leads ORDER BY razao_social LIMIT 30`
 * custava 48 ms.
 *
 * Por que índice resolve aqui, sendo que não resolveu no caso do faturamento (Regra de
 * ouro nº 6): o que decide é o `LIMIT`. Com `ORDER BY <coluna indexada> LIMIT 30` o MySQL
 * lê o índice em ordem e para na 30ª linha — não depende da seletividade da coluna. No
 * caso do faturamento não havia LIMIT: a agregação precisava varrer tudo de qualquer jeito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Ordem padrão da tela e coluna "Lead" do header ordenável.
            // Medido: SELECT * ... ORDER BY razao_social LIMIT 30 caiu de 48 ms para 0,87 ms.
            $table->index('razao_social');

            /*
             * ⚠️ `valor_estimado` NÃO é indexada, de propósito. A primeira versão desta
             * migration indexava (a tela ordena por ela), mas medindo antes de fechar:
             * 100% dos 17.173 leads têm valor_estimado NULL. Ordenar por essa coluna hoje
             * não ordena nada, e o índice só custaria espaço e escrita nos imports.
             * Se um dia o campo passar a ser preenchido, indexar aí — com medição.
             */
        });

        Schema::table('pedidos', function (Blueprint $table) {
            // Ordem PADRÃO de /pedidos-abertos, então roda em toda visita à tela.
            // `data_faturamento` e `data_pedido` já eram indexadas; a de previsão não.
            $table->index('data_previsao_faturamento');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['razao_social']);
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex(['data_previsao_faturamento']);
        });
    }
};
