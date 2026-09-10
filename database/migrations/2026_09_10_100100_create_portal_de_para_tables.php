<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De-para entre os códigos do TOTVS (que o CRM-V2 conhece) e os ids internos do
 * Portal Autopel (que o `POST /v1/api/orders` exige). Espelho de LEITURA, no mesmo
 * espírito de `clientes`/`produtos`: nada aqui é fonte de verdade, tudo é reposto
 * pela sincronização.
 *
 * ⚠️ POR QUE ESPELHO E NÃO CONSULTA AO VIVO: a API de pedidos não tem endpoint de
 * listagem nenhum, e resolver quatro ids por HTTP no meio de um POST colocaria a
 * disponibilidade do Portal dentro do orçamento de 500 ms da Regra de ouro nº 9.
 *
 * O mapa e as armadilhas (três colunas parecidas com código, duas com loja) estão
 * em docs/integracao-portal-pedidos.md §4.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_clientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_id')->unique();

            /*
             * ⚠️ `code` + `store`, NUNCA `autopel_code` nem `external_id`. A tabela do
             * Portal tem as três, e só esta casa com `clientes.cod_cliente` + `loja`:
             * `autopel_code` é nulo em parte das linhas e `external_id` é sequencial
             * interno deles.
             */
            $table->string('code', 30);
            $table->string('store', 30);

            /*
             * CNPJ/CPF só dígitos, do jeito que o Portal guarda (a nossa `clientes.cnpj`
             * é mascarada). 🚨 Esta coluna NÃO é decoração: é a guarda contra criar
             * pedido para a empresa errada. Há par `code`+`store` que existe nos dois
             * lados apontando para empresas diferentes (medido em 10/09).
             */
            $table->string('document', 20)->nullable();
            $table->string('razao_social')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['code', 'store']);
            $table->index('document');
        });

        Schema::create('portal_produtos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_id')->unique();
            $table->string('code', 60)->unique();
            $table->string('descricao')->nullable();

            /*
             * Fator de conversão: a API recusa com 400 quando o tipo é `D`, a unidade
             * secundária é `CX` e a quantidade não é múltiplo exato do fator. Guardar
             * isso aqui é o que permite avisar o vendedor ANTES de enviar, em vez de
             * ele descobrir por um erro na cara dele.
             */
            $table->string('unidade', 20)->nullable();
            $table->string('unidade_secundaria', 20)->nullable();
            $table->decimal('fator_conversao', 14, 4)->nullable();
            $table->string('tipo_conversao', 10)->nullable();

            /* Nota de serviço só é aceita para produto do grupo 3 no Portal. */
            $table->string('grupo', 30)->nullable();

            $table->boolean('deleted')->default(false);
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_usuarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_id')->unique();

            /*
             * As duas chaves possíveis para casar com o nosso `users`: o e-mail
             * corporativo e o código de vendedor do Protheus (= TOTVS), que é o mesmo
             * formato de `vendedor_perfis.cod_vendedor`.
             */
            $table->string('email')->nullable()->index();
            $table->string('protheus_seller_code', 30)->nullable()->index();

            $table->string('nome')->nullable();

            /*
             * `active` e `deleted` são separados no Portal e dão erros diferentes:
             * inativo responde 409, excluído responde 404.
             */
            $table->boolean('ativo')->default(true);
            $table->boolean('deleted')->default(false);
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_representantes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('portal_id')->unique();

            /*
             * O `clientRepresentativeId` da API é o id DESTA linha — o vínculo entre um
             * usuário do Portal e um cliente. É pessoa da Autopel, não contato do
             * cliente (confirmado pelo schema em 10/09).
             *
             * Guarda os ids do PORTAL, não os nossos: é isso que o payload precisa, e
             * evita depender da ordem de sincronização das outras três tabelas.
             */
            $table->unsignedBigInteger('portal_cliente_id')->index();
            $table->unsignedBigInteger('portal_usuario_id')->index();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['portal_cliente_id', 'portal_usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_representantes');
        Schema::dropIfExists('portal_usuarios');
        Schema::dropIfExists('portal_produtos');
        Schema::dropIfExists('portal_clientes');
    }
};
