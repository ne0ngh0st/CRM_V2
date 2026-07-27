<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->string('numero_pedido')->unique();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('cod_vendedor')->index();
            $table->date('data_pedido');
            $table->date('data_previsao_faturamento')->nullable();
            $table->date('data_faturamento')->nullable();
            $table->date('data_entrega_prevista')->nullable();
            $table->date('data_pcp')->nullable();
            $table->string('carga')->nullable();
            $table->string('condicao_pagamento')->nullable();
            $table->enum('status', ['separacao', 'bloqueio', 'wms', 'liberado', 'faturado'])->default('separacao');
            $table->decimal('valor_total', 12, 2);
            $table->timestamps();

            $table->index(['cod_vendedor', 'data_pedido']);
            $table->index('data_faturamento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
