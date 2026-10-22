<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estoque de revenda: o catálogo contra o qual a Cotação compara a lista do cliente.
 *
 * Vem do Relatório de Revenda (RV) em CSV — o mesmo que o Cotacao.exe lia, já no
 * recorte filial 05 / ativos —, via `totvs:import-estoque-revenda`. `unidade` fica nula:
 * o RV não traz a coluna.
 *
 * Só as colunas que o motor e a tela usam. Necessidade de compra, fornecedor, consumo
 * dos meses e preço do pedido aberto são assunto do SRM, não do comercial.
 *
 * ⚠️ `ult_preco` (Ult.Preco do RV) é o ÚLTIMO PREÇO DE COMPRA (custo). Só gestor vê — quem decide é o
 * `CotacaoService::podeVerCusto()`, e o valor sai do payload no servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_revenda', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('descricao');
            $table->string('unidade', 10)->nullable();
            $table->string('marca')->nullable();
            $table->string('grupo', 40)->nullable();
            $table->decimal('fisico', 14, 3)->default(0);
            $table->decimal('reserva', 14, 3)->default(0);
            $table->decimal('disponivel', 14, 3)->default(0);
            $table->decimal('comprado', 14, 3)->default(0);
            $table->decimal('ult_preco', 16, 6)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_revenda');
    }
};
