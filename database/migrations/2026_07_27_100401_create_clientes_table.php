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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('cod_cliente');
            $table->string('loja');
            $table->string('cnpj', 18)->nullable();
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->string('cod_vendedor')->nullable();
            $table->string('cod_segmento')->nullable();
            $table->string('estado', 2)->nullable();
            $table->string('cep', 10)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('email')->nullable();
            $table->date('data_ultima_compra')->nullable();
            $table->timestamps();

            $table->unique(['cod_cliente', 'loja']);
            $table->index('cnpj');
            $table->index('cod_vendedor');
            $table->index(['cod_vendedor', 'data_ultima_compra']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
