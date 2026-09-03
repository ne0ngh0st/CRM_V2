<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `orcamento_itens.preco_tabela` nasceu decimal(12,2), mas a origem do dado —
 * `produtos.preco_tabela`, importada de `CODIGO_PRODUTOS.PRCVENDA` do TOTVS — é
 * decimal(12,4). O MySQL não reclama disso: ele ARREDONDA a casa excedente em silêncio
 * (Note 1265), então o valor era truncado ao gravar sem nenhum erro aparecer.
 *
 * ⚠️ Por que isso importa mais do que parece: `preco_tabela` é o DENOMINADOR do cálculo
 * de desconto em `NivelAprovacaoCalculator` — `(1 - valor_unitario / preco_tabela) * 100`.
 * As fronteiras de aprovação são 10% (supervisor) e 15% (diretor), então um truncamento
 * de centésimo no denominador pode fazer um item cruzar a fronteira e mudar QUEM precisa
 * aprovar o orçamento.
 *
 * Só amplia a precisão: nenhum valor existente é perdido.
 *
 * ⚠️ NÃO há backfill dos orçamentos já gravados a partir de `produtos`, de propósito.
 * O preço de tabela de um orçamento é a referência DAQUELE momento; reescrevê-lo mudaria
 * retroativamente o desconto e o nível de aprovação de documentos já aprovados. As 4 casas
 * valem só para orçamento novo ou reeditado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->decimal('preco_tabela', 12, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->decimal('preco_tabela', 12, 2)->nullable()->change();
        });
    }
};
