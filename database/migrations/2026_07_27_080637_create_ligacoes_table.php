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
        Schema::create('ligacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->string('cliente_nome')->nullable();
            $table->enum('tipo_contato', ['telefonica', 'presencial', 'whatsapp', 'email'])->default('telefonica');
            $table->enum('status', ['ativa', 'finalizada', 'cancelada', 'excluida'])->default('ativa');
            $table->dateTime('data_ligacao');
            $table->unsignedTinyInteger('perguntas_respondidas_count')->default(0);
            $table->unsignedTinyInteger('perguntas_obrigatorias_count')->default(0);
            $table->timestamps();

            $table->index(['usuario_id', 'data_ligacao']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ligacoes');
    }
};
