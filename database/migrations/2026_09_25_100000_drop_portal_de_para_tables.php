<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apaga o de-para do Portal (`portal_clientes`/`produtos`/`usuarios`/`representantes`).
 *
 * Em 2026-09-25 o time do Portal passou a aceitar as CHAVES DE NEGÓCIO do TOTVS direto
 * no `POST /v1/api/orders` (`sellerCode`, `clientCode`+`clientStore`, `productCode`) e a
 * resolver os ids internos deles do lado deles. Com isso o espelho local perdeu função:
 * o payload é montado a partir do próprio orçamento. Decisão do Tony (confiar na
 * resolução do Portal) — ver docs/integracao-portal-pedidos.md §4.9.
 *
 * ⚠️ As tabelas nasceram VAZIAS e nunca foram populadas em produção (a homologação foi
 * feita com uma linha semeada à mão), então dropá-las não perde dado nenhum. A migration
 * `down()` NÃO as recria: o código que as lia (models e `PortalDeParaResolver`) foi
 * removido, então recriar tabela vazia não devolveria a feature — se um dia o de-para
 * voltar, volta com o código junto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('portal_representantes');
        Schema::dropIfExists('portal_usuarios');
        Schema::dropIfExists('portal_produtos');
        Schema::dropIfExists('portal_clientes');
    }

    public function down(): void
    {
        // Intencionalmente vazio: ver o docblock. Recriar as tabelas sem o código que as
        // consumia não restauraria nada.
    }
};
