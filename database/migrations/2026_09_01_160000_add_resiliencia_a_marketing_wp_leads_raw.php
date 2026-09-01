<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Torna a captura do site auto-recuperável e idempotente.
 *
 * - payload_hash: o mesmo POST reenviado (timeout do WordPress) não vira lead
 *   duplicado. É a chave de idempotência, não uma otimização.
 * - tentativas/erro: quando a promoção staging → lead falha, a linha fica
 *   pendente com o motivo à vista, e o job de reconciliação tenta de novo.
 *   Sem isso, "staging é prova de captura" seria mentira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_wp_leads_raw', function (Blueprint $table) {
            $table->char('payload_hash', 64)->nullable()->after('payload_json');
            $table->unsignedSmallInteger('tentativas')->default(0)->after('formulario_id');
            $table->string('erro', 500)->nullable()->after('tentativas');

            // Retry do WordPress: mesma carga, mesma fonte, minutos depois.
            $table->index(['payload_hash', 'recebido_em'], 'mwlr_hash_recebido_idx');

            // Fila do job de reconciliação: as pendentes são poucas, mas a
            // tabela cresce para sempre — sem índice isso viraria full scan.
            $table->index(['lead_id', 'tentativas'], 'mwlr_pendentes_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_wp_leads_raw', function (Blueprint $table) {
            $table->dropIndex('mwlr_hash_recebido_idx');
            $table->dropIndex('mwlr_pendentes_idx');
            $table->dropColumn(['payload_hash', 'tentativas', 'erro']);
        });
    }
};
