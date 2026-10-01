<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de venda do orçamento: Venda (Consumo), Venda (Revenda) ou Serviço.
 *
 * Vira o `invoiceType` do pedido no Portal, e no Protheus decide a TES — a tributação
 * da NF-e é diferente para consumo e revenda (Laís, SIC, 2026-10-01). Até aqui o CRM
 * mandava todo pedido como consumo, chumbado no config.
 *
 * ⚠️ NULLABLE de propósito: os ~2 mil orçamentos existentes não têm como saber o tipo, e
 * chutar "consumo" seria repetir o chumbado no banco. O formulário passa a exigir o campo
 * (sem valor pré-marcado), e orçamento antigo escolhe na hora de virar pedido.
 *
 * ⚠️ Não confundir com `tipo_produto_servico`, que só decide se o preço embute IPI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->enum('tipo_venda', ['consumo', 'revenda', 'servico'])->nullable()->after('tipo_frete');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('tipo_venda');
        });
    }
};
