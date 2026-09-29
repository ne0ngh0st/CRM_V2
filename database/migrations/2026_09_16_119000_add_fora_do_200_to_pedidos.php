<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido em aberto no CRM que NÃO estava no último relatório 200.
 *
 * Desde 2026-09-25 quem cria pedido e o dá como faturado é o 232, e o 200 só atualiza a
 * etapa. Só que o 232 é recorte por DATA DO PEDIDO (o mês corrente): um pedido de agosto
 * que faturou em setembro nunca mais aparece num 232 e ficava "em aberto" para sempre em
 * `/pedidos-abertos`, mesmo já tendo saído do 200. Esta marca é o que faz a tela voltar a
 * ser espelho do 200 sem o 200 mexer em valor nem em data de faturamento — ele não sabe
 * se o pedido saiu por nota ou por cancelamento, e nem traz a data da nota.
 *
 * ⚠️ A DATA DO ARQUIVO É DE PROPÓSITO ANTERIOR À CRIAÇÃO (2026-09-29): tem que rodar
 * antes de `2026_09_16_120000_create_views_do_bi`. Aquela migration chama
 * `ViewsBi::recriar()` com a SQL ATUAL, que já lê `fora_do_200` — num banco novo (suíte,
 * dev recriado) ela quebraria com "Unknown column". Onde a das views já rodou (produção),
 * esta entra como pendente normalmente, e a `2026_09_29_110100` recria as views depois.
 *
 * Default false: pedido novo que o 232 cria aparece na tela até o próximo 200 dizer o
 * contrário, e banco sem nenhum 200 importado continua mostrando tudo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->boolean('fora_do_200')->default(false)->after('historico_em');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('fora_do_200');
        });
    }
};
