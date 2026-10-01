<?php

use App\Services\Orcamento\CondicoesPagamento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Condições de pagamento do Protheus (SE4) + o código escolhido no orçamento.
 *
 * O Portal passou a aceitar `paymentConditionCode` e só aceita condição ativa no Protheus
 * (Laís/Philipe, 2026-10-01). O orçamento guardava texto livre; agora guarda o CÓDIGO, e
 * `forma_pagamento` continua existindo como a descrição — é o que PDF, tela, Excel e BI já
 * leem, e por isso nenhum deles precisou mudar.
 *
 * ⚠️ A migration CARREGA a lista (391 condições, versionadas em
 * database/data/condicoes_pagamento.json), não só cria a tabela: seeder não roda no
 * deploy, e uma tabela vazia em produção deixaria o formulário sem opção nenhuma —
 * travando a criação de orçamento para todo mundo. Mesmo raciocínio de
 * `segmentos.peso_potencial` (2026-09-08).
 *
 * `condicao_pagamento_codigo` nasce NULO nos orçamentos existentes, de propósito: o texto
 * antigo é reconhecido como SUGESTÃO na tela (CondicoesPagamento::sugerirCodigo), nunca
 * gravado sem alguém confirmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('condicoes_pagamento', function (Blueprint $table) {
            $table->string('codigo', 3)->primary();
            $table->string('descricao', 80);
            $table->string('tipo', 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        CondicoesPagamento::sincronizar(CondicoesPagamento::doArquivo());

        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('condicao_pagamento_codigo', 3)->nullable()->after('forma_pagamento');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('condicao_pagamento_codigo');
        });

        Schema::dropIfExists('condicoes_pagamento');
    }
};
