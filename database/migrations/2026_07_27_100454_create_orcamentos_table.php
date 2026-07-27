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
        Schema::create('orcamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cliente_nome');
            $table->string('cliente_cnpj', 18)->nullable();
            $table->string('cliente_contato')->nullable();
            $table->string('forma_pagamento')->nullable();
            $table->decimal('valor_total', 12, 2);
            $table->date('data_validade')->nullable();
            $table->decimal('desconto_pct_max', 6, 2)->default(0);
            $table->enum('nivel_aprovacao', ['nenhum', 'supervisor', 'diretor'])->default('nenhum');
            $table->enum('status_gestor', ['pendente', 'aprovado', 'rejeitado'])->default('pendente');
            $table->foreignId('aprovado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->text('motivo_rejeicao')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status_gestor']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orcamentos');
    }
};
