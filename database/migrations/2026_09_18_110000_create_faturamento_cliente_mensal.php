<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollup de faturamento por cliente e mês — a base de faturamento da Visão Diretor.
 *
 * 🚨 Existe porque somar `faturamentos` por cliente AO VIVO não cabe no orçamento: 12
 * meses de UMA conta (ESTAPAR, 162 códigos) levaram ~10 s em dev (18/09/2026). A tabela
 * tem ~6 M de linhas e nenhum índice por `cod_cliente` — e índice novo nela custa caro em
 * toda recarga (10m40s contra 66s, 31/08). O `PotencialCarteiraResolver` já registrava que
 * a resposta seria esta.
 *
 * Dono único da escrita: `FaturamentoMensalRollup` (comando `faturamento:rollup-mensal` e
 * o gancho pós-`totvs:atualizar`). Valor derivado, logo reconstruível a qualquer momento.
 *
 * ⚠️ O grão é `cod_cliente`, NÃO a filial: nota antiga não tem `loja` (a coluna só entrou
 * em 16/09). Um cliente com filiais em grupos diferentes soma o faturamento do código
 * inteiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturamento_cliente_mensal', function (Blueprint $table) {
            $table->string('cod_cliente', 20);
            // Sempre o dia 1 do mês.
            $table->date('mes');
            $table->decimal('valor_total', 15, 2);
            $table->unsignedInteger('notas');
            // Quando este mês foi recalculado. A tela mostra o do mês mais recente.
            $table->timestamp('calculado_em');

            // A PK começa por cod_cliente: é o caminho da conta ("estes 162 códigos, nos
            // últimos 12 meses"). O índice por mês serve o delete+insert do rollup.
            $table->primary(['cod_cliente', 'mes']);
            $table->index('mes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faturamento_cliente_mensal');
    }
};
