<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda o corpo EXATO que foi enviado ao Portal.
 *
 * 🚨 Não é auditoria decorativa: o contrato de idempotência deles é "reenvie a
 * requisição IDÊNTICA com a mesma chave". Se a retentativa remontasse o payload a
 * partir do orçamento, qualquer edição no meio do caminho mudaria o conteúdo e a
 * mesma chave passaria a responder 409 — o pedido ficaria preso sem motivo aparente.
 * Persistir o corpo é o que torna a retentativa literalmente idêntica.
 *
 * De quebra, responde "o que exatamente foi mandado?" meses depois, que é a pergunta
 * que aparece quando um pedido sai diferente do orçamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->json('portal_payload')->nullable()->after('portal_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('portal_payload');
        });
    }
};
