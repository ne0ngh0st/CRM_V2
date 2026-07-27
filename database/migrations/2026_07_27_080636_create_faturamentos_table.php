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
        Schema::create('faturamentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('filial')->nullable();
            $table->string('nota_fiscal')->nullable();
            $table->string('pedido')->nullable();
            $table->date('data_emissao');
            $table->string('cod_cliente')->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->string('cliente_nome')->nullable();
            $table->string('cod_vendedor');
            $table->string('cod_produto')->nullable();
            $table->string('produto_desc')->nullable();
            $table->string('segmento')->nullable();
            $table->decimal('quantidade', 15, 2)->nullable();
            $table->decimal('valor_unitario', 15, 2)->nullable();
            $table->decimal('valor_total', 15, 2);
            $table->timestamps();

            $table->index(['cod_vendedor', 'data_emissao']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faturamentos');
    }
};
