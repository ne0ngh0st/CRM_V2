<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orçamento aprovado vira pedido no Portal Autopel (SIC).
 * Ver docs/integracao-portal-pedidos.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            /*
             * ⚠️ Até aqui o orçamento só guardava `cliente_nome` e `cliente_cnpj` como
             * TEXTO, e o CNPJ vinha em dois formatos na mesma coluna (440 mascarados,
             * 1.418 só dígitos — medido em 09/09). Sem vínculo real não há
             * `cod_cliente` + `loja`, e sem esse par não há como resolver o `clientId`
             * do Portal. Nullable porque orçamento de LEAD não tem cliente, e porque
             * os 1.864 históricos não têm como ser vinculados retroativamente.
             */
            $table->foreignId('cliente_id')->nullable()->after('lead_id')->constrained('clientes')->nullOnDelete();

            /*
             * Id do pedido criado no Portal. É a ÚNICA referência que liga o orçamento
             * ao pedido do outro lado — a API não tem endpoint de consulta.
             */
            $table->unsignedBigInteger('portal_pedido_id')->nullable()->after('motivo_rejeicao');

            /*
             * 🚨 A chave de idempotência NASCE PERSISTIDA, antes do primeiro envio, e é
             * REUSADA em toda retentativa. Gerar chave nova na hora do envio é o único
             * caminho que ainda duplica pedido no Portal — a proteção deles depende de
             * nós repetirmos o mesmo valor. Unique para que nem um bug consiga
             * reaproveitar a chave de outro orçamento (que responderia 409).
             */
            $table->string('portal_idempotency_key', 200)->nullable()->unique()->after('portal_pedido_id');

            $table->timestamp('portal_enviado_em')->nullable()->after('portal_idempotency_key');

            /*
             * Mensagem de erro do Portal, propagada LITERALMENTE. A documentação deles
             * pede isso: as mensagens são específicas ("Representante não encontrado",
             * "Cliente ainda está como prospect") e traduzir só afastaria quem opera da
             * causa real.
             */
            $table->text('portal_erro')->nullable()->after('portal_enviado_em');

            $table->index('portal_pedido_id');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropIndex(['portal_pedido_id']);
            $table->dropColumn([
                'cliente_id',
                'portal_pedido_id',
                'portal_idempotency_key',
                'portal_enviado_em',
                'portal_erro',
            ]);
        });
    }
};
