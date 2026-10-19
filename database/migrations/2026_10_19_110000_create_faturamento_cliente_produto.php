<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quem já comprou qual produto (faturado), derivada de `faturamentos` pelo
 * `FaturamentoMensalRollup`. Serve ao vínculo por PRODUTO PERSONALIZADO da Visão Diretor
 * (`VinculoPorProduto`): a bobina "MAX MUFFATO" diz de quem é o cliente que a compra.
 *
 * ⚠️ Nasce VAZIA de propósito: o rollup só refaz o mês corrente e o anterior. A carga do
 * histórico é UMA vez, depois do deploy: `php artisan faturamento:rollup-mensal --desde=2018-01`.
 * Varrer `faturamentos` (6 M de linhas, sem índice em produto) aqui dentro seguraria o deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faturamento_cliente_produto', function (Blueprint $table) {
            $table->string('cod_produto', 60);
            $table->string('cod_cliente', 20);
            $table->string('descricao', 255)->nullable();
            $table->date('primeira_emissao');
            $table->date('ultima_emissao');
            $table->timestamp('calculado_em')->nullable();

            $table->primary(['cod_produto', 'cod_cliente']);
        });

        DB::statement("ALTER TABLE redes_mercado_vinculos MODIFY nivel ENUM('manual', 'nome', 'grupo', 'mesma_empresa', 'produto') NOT NULL");
    }

    public function down(): void
    {
        DB::table('redes_mercado_vinculos')->where('nivel', 'produto')->delete();
        DB::statement("ALTER TABLE redes_mercado_vinculos MODIFY nivel ENUM('manual', 'nome', 'grupo', 'mesma_empresa') NOT NULL");
        Schema::dropIfExists('faturamento_cliente_produto');
    }
};
