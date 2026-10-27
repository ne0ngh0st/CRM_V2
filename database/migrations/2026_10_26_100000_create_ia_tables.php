<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistente de IA: conversas, mensagens e os chamados que ele abre.
 *
 * Prefixo `ia_` (e não "assistente") de propósito: `assistente` já é um PERFIL do
 * sistema (quem apoia um supervisor), e os dois nomes iam se confundir em query e teste.
 *
 * ⚠️ `ia_mensagens.conteudo_api` é a conversa no formato EXATO da API da Anthropic, e a
 * conversa é reenviada a cada pergunta montada só a partir dele. Nunca editar o JSON de
 * uma mensagem já respondida: o Claude Opus 5.5 confere que o histórico não mudou (os
 * blocos de raciocínio ficam presos à conversa que os produziu) e recusa histórico
 * editado. Ver docs/ia-assistente.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_conversas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Admin que estava simulando o usuário quando a conversa nasceu. As
            // ferramentas rodam como o simulado (é o ponto da simulação); isto só registra
            // quem estava de fato do outro lado.
            $table->foreignId('admin_simulando_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('titulo', 120);
            $table->string('modelo', 60);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('ia_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('ia_conversas')->cascadeOnDelete();
            $table->enum('papel', ['usuario', 'ia']);
            // O que a tela mostra. Na pergunta, o texto digitado; na resposta, o texto final.
            $table->text('texto')->nullable();
            // A parte desta mensagem na conversa da API (ver o aviso do docblock).
            // ⚠️ longText e NÃO json: a coluna JSON do MySQL reordena as chaves de cada
            // objeto ao gravar, e o histórico voltaria à API diferente de como veio.
            $table->longText('conteudo_api')->nullable();
            // Ferramentas chamadas nesta resposta — para a tela de uso e a avaliação.
            $table->json('ferramentas')->nullable();
            $table->enum('status', ['processando', 'ok', 'erro', 'cancelada'])->default('ok');
            $table->string('erro', 500)->nullable();
            $table->string('tela', 255)->nullable();
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_saida')->default(0);
            $table->unsignedInteger('tokens_cache_leitura')->default(0);
            $table->unsignedInteger('tokens_cache_escrita')->default(0);
            $table->decimal('custo_usd', 10, 6)->default(0);
            $table->unsignedInteger('latencia_ms')->nullable();
            $table->tinyInteger('avaliacao')->nullable(); // 1 = útil, -1 = não ajudou
            $table->timestamps();

            $table->index(['conversa_id', 'id']);
            // Limite diário e teto mensal somam por data.
            $table->index(['created_at', 'papel']);
        });

        Schema::create('ia_chamados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversa_id')->nullable()->constrained('ia_conversas')->nullOnDelete();
            $table->string('categoria', 40);
            $table->text('resumo');
            $table->string('tela', 255)->nullable();
            $table->enum('status', ['aberto', 'resolvido'])->default('aberto');
            $table->text('resposta')->nullable();
            $table->foreignId('resolvido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolvido_em')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_chamados');
        Schema::dropIfExists('ia_mensagens');
        Schema::dropIfExists('ia_conversas');
    }
};
