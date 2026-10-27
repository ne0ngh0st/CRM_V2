<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caixas faturadas por cliente, mês e família (bobina / etiqueta / tag).
 *
 * Somar `faturamentos` ao vivo por cliente não cabe no chat: a tabela tem milhões de
 * linhas e não tem índice por `cod_cliente` (a mesma razão de `faturamento_cliente_mensal`).
 * Escrita única em `FaturamentoMensalRollup`.
 *
 * ⚠️ Nasce vazia. O `totvs:atualizar` só refaz o mês corrente e o anterior. O histórico
 * entra com `php artisan faturamento:rollup-mensal --desde=2018-01`. Sem isso o assistente
 * diz que o volume ainda não foi calculado — e não que o grupo compra zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturamento_cliente_familia_mensal', function (Blueprint $table) {
            $table->string('cod_cliente', 20);
            $table->date('mes');
            $table->string('familia', 20);
            $table->decimal('quantidade', 15, 2);
            // Bobina cuja nota não virou caixa: o nome do código não declara "C/30".
            $table->decimal('unidades_sem_caixa', 15, 2)->default(0);
            $table->timestamp('calculado_em');

            $table->primary(['cod_cliente', 'mes', 'familia']);
            $table->index('mes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturamento_cliente_familia_mensal');
    }
};
