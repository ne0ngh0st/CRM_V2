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
        Schema::create('sugestoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('categoria')->nullable();
            $table->text('mensagem');
            $table->enum('status', ['pendente', 'em_analise', 'aprovada', 'rejeitada', 'implementada'])->default('pendente');
            $table->text('resposta_admin')->nullable();
            $table->foreignId('admin_respondeu_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('visivel')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sugestoes');
    }
};
