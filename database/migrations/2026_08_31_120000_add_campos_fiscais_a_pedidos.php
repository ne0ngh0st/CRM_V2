<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos fiscais que o RLT 232 (ex-META_VENDA) vai passar a fornecer.
 *
 * Ficam nullable e vazios ate o Adriano ajustar o relatorio -- a tela so os exibe
 * quando ha valor, entao nada aparece em branco enquanto isso.
 *
 * ⚠️ POR QUE `nota_fiscal` VAI NO ITEM E NAO NO PEDIDO: medido nos dados reais de
 * agosto, 43 pedidos faturados tem DUAS notas (faturamento parcial). Sao 0,6% --
 * raro o bastante para passar meses despercebido, real o bastante para dar numero
 * errado. No cabecalho, esses 43 ficariam com metade da verdade e ninguem saberia
 * qual metade; no item, cada linha carrega a nota em que de fato saiu.
 *
 * ⚠️ `peso_liquido` E UNITARIO, nao o peso da linha. Confirmado no relatorio real:
 * os 1.884 produtos distintos tem cada um UM unico valor de PESO_LIQ, que nao muda
 * com a quantidade (o produto V0222 sai com 0,10 tanto na quantidade 1 quanto na 10).
 * O peso da linha e `peso_liquido * quantidade`, calculado na exibicao -- gravar ja
 * multiplicado divergiria assim que a quantidade mudasse.
 *
 * A rigor o peso unitario e atributo do PRODUTO, nao do item de pedido; o 232 apenas
 * o repete em toda linha. Guardamos aqui mesmo assim porque essa e a fonte que
 * temos, e nao depender de `produtos` estar preenchido e mais barato que o contrario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('rps')->nullable()->after('numero_pedido');
            $table->enum('tipo_faturamento', ['produto', 'servico'])->nullable()->after('rps');
        });

        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->string('nota_fiscal')->nullable()->after('descricao');
            $table->decimal('peso_liquido', 12, 3)->nullable()->after('quantidade_liberada');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['rps', 'tipo_faturamento']);
        });

        Schema::table('pedido_itens', function (Blueprint $table) {
            $table->dropColumn(['nota_fiscal', 'peso_liquido']);
        });
    }
};
