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
        Schema::create('notificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tipo');
            $table->string('titulo');
            $table->string('mensagem')->nullable();
            $table->string('link')->nullable();
            // Chave de idempotência dos jobs agendados (ex.: agendamento_ligacao/10, pedido_atencao/42):
            // evita duplicar a mesma notificação a cada execução do job.
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->timestamp('lida_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'lida_em']);
            $table->unique(['user_id', 'tipo', 'referencia_tipo', 'referencia_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notificacoes');
    }
};
