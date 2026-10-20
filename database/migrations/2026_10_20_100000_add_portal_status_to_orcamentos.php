<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O que aconteceu com o pedido DEPOIS de criado no SIC (aprovado ou recusado pela Laís).
 *
 * Até aqui o 201 da criação era a última notícia: a API não tem consulta, e a recusa
 * nunca chega ao TOTVS — então não aparecia em lugar nenhum. O SIC passou a chamar o
 * webhook `/webhooks/sic-pedidos` a cada mudança de status (combinado com o Philipe em
 * 2026-10-20), e é ele quem escreve aqui.
 *
 * `portal_enviado_por_id` guarda quem clicou em "Transformar em pedido": é quem recebe
 * o aviso junto com o dono do orçamento, e até aqui só existia dentro do job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('portal_status', 40)->nullable()->after('portal_resposta');
            $table->text('portal_status_motivo')->nullable()->after('portal_status');
            $table->timestamp('portal_status_em')->nullable()->after('portal_status_motivo');
            $table->foreignId('portal_enviado_por_id')->nullable()->after('portal_enviado_em')
                ->constrained('users')->nullOnDelete();
        });

        // Os pedidos já criados nasceram com o status da resposta (AWAITING_APPROVAL).
        DB::table('orcamentos')
            ->whereNotNull('portal_pedido_id')
            ->whereNotNull('portal_resposta')
            ->update([
                'portal_status' => DB::raw("JSON_UNQUOTE(JSON_EXTRACT(portal_resposta, '$.status'))"),
                'portal_status_em' => DB::raw('portal_enviado_em'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('portal_enviado_por_id');
            $table->dropColumn(['portal_status', 'portal_status_motivo', 'portal_status_em']);
        });
    }
};
