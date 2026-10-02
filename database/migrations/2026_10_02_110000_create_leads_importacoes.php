<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O relatório de cada rodada do `totvs:import-leads` (inclusive `--dry-run`), para a
 * `/atualizacoes` mostrar o que entrou e o que ficou de fora — sem isso o resultado só
 * existia no terminal de quem rodou.
 *
 * `resultado` é JSON de propósito: são ~15 contagens que só a tela lê, nunca filtradas
 * nem somadas no banco. `recusadas` guarda as primeiras linhas recusadas (arquivo:linha),
 * que é o que quem montou a planilha precisa para corrigir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads_importacoes', function (Blueprint $table) {
            $table->id();
            $table->boolean('simulacao')->default(false);
            $table->enum('status', ['sucesso', 'falhou'])->default('sucesso');
            $table->json('arquivos')->nullable();
            $table->json('resultado')->nullable();
            $table->json('recusadas')->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('iniciada_em');
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->index(['simulacao', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads_importacoes');
    }
};
