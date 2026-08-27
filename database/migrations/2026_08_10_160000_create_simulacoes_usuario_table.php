<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de auditoria da simulação de usuário. O legado permitia simular sem registrar
 * nada — aqui toda sessão de simulação vira uma linha, com quem simulou, quem foi
 * simulado, quando começou e quando terminou.
 *
 * Escrita só nas transições (iniciar/encerrar), nunca por request — simular não pode
 * custar performance em página nenhuma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulacoes_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('alvo_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('iniciada_em');
            $table->timestamp('encerrada_em')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'iniciada_em']);
            $table->index('alvo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulacoes_usuario');
    }
};
