<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aceite do CLIENTE no orçamento, separado da aprovação de MARGEM (`status_gestor`).
 *
 * Quem preenche é o próprio vendedor, a qualquer momento. É pré-condição para virar pedido
 * no Portal, junto com a margem aprovada (ver `Orcamento::bloqueioDoPedido()`).
 *
 * ⚠️ A DATA DO ARQUIVO É DE PROPÓSITO ANTERIOR À CRIAÇÃO (2026-10-15), mesmo caso da
 * `2026_09_16_119000`: tem que rodar antes de `2026_09_16_120000_create_views_do_bi`, que
 * chama `ViewsBi::recriar()` com a SQL ATUAL (já lê `resposta_cliente`). Onde as views já
 * existem, esta entra como pendente e a `2026_10_15_100100` recria as views depois.
 *
 * ⚠️ Backfill só do que JÁ virou pedido: se virou, o cliente aceitou — e sem isso esses
 * orçamentos apareceriam como "aguardando cliente" para sempre. Os demais nascem nulos
 * (= aguardando). Os históricos do legado tinham `status_cliente`, mas o import pontual de
 * 2026-07 não o trouxe; ficam como aguardando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->enum('resposta_cliente', ['aprovado', 'recusado'])->nullable()->after('motivo_rejeicao');
            $table->string('motivo_recusa_cliente', 30)->nullable()->after('resposta_cliente');
            $table->string('motivo_recusa_cliente_texto', 500)->nullable()->after('motivo_recusa_cliente');
            $table->foreignId('resposta_cliente_por_id')->nullable()->after('motivo_recusa_cliente_texto')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('resposta_cliente_em')->nullable()->after('resposta_cliente_por_id');
        });

        DB::table('orcamentos')
            ->whereNotNull('portal_pedido_id')
            ->update([
                'resposta_cliente' => 'aprovado',
                'resposta_cliente_em' => DB::raw('portal_enviado_em'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resposta_cliente_por_id');
            $table->dropColumn(['resposta_cliente', 'motivo_recusa_cliente', 'motivo_recusa_cliente_texto', 'resposta_cliente_em']);
        });
    }
};
