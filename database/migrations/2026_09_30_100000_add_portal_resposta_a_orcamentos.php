<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda o que o Portal DEVOLVEU na criação do pedido.
 *
 * 🚨 Desde a versão de 2026-09-30 da API, o pedido nasce direto em AWAITING_APPROVAL
 * e o ERP decide frete, transportadora e datas NA CRIAÇÃO — e pode decidir diferente
 * do que mandamos (a data de entrega pedida 15/10 volta 17/10, com 201 e sem aviso).
 * Não existe endpoint de consulta: esta resposta é a ÚNICA forma de saber o que ficou
 * gravado lá. Perdê-la é não ter como responder "quando entrega?" depois.
 *
 * Par do `portal_payload`: um é o que pedimos, o outro é o que valeu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->json('portal_resposta')->nullable()->after('portal_payload');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('portal_resposta');
        });
    }
};
