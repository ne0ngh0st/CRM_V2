<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cotação: lista do cliente × estoque de revenda, com memória das conferências.
 *
 * Substitui o Cotacao.exe e a API que ele mantinha na KingHost (`cotacao_decisao` +
 * `cotacao_decisao_atual` no `autopel01`). O desenho das duas tabelas de memória foi
 * mantido — histórico append-only + a decisão atual por texto —, mas com `user_id` no
 * lugar de `usuario`/`maquina` em texto livre (Regra de ouro nº 1).
 *
 * ⚠️ A CHAVE da memória é `texto_normalizado`, produzido pelo motor Python
 * (`motor/cotacao/normalizar.py`). Nunca calcular essa chave em PHP: um espaço de
 * diferença e a memória deixa de casar, sem erro nenhum. Mudou o dicionário de
 * sinônimos → `php artisan cotacao:renormalizar-memoria`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('nome_arquivo');
            $table->string('caminho_arquivo');
            $table->enum('status', ['processando', 'pronta', 'falha'])->default('processando');
            $table->text('erro')->nullable();
            // Como o detector leu a planilha (aba, linha do cabeçalho, avisos).
            $table->json('leitura')->nullable();
            $table->unsignedInteger('itens_total')->default(0);
            $table->decimal('valor_total', 16, 2)->default(0);
            // Retrato do estoque usado: `max(updated_at)` do estoque_revenda na hora.
            $table->timestamp('estoque_em')->nullable();
            $table->timestamp('processada_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('cotacao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotacao_id')->constrained('cotacoes')->cascadeOnDelete();
            $table->string('cod_cliente', 60)->nullable();
            $table->text('descricao_cliente');
            $table->string('texto_normalizado', 512);
            $table->string('subgrupo')->nullable();
            $table->string('unidade', 30)->nullable();
            $table->string('marca')->nullable();
            $table->decimal('qtd_periodo', 16, 3)->default(0);
            $table->decimal('valor_periodo', 16, 2)->default(0);
            $table->unsignedInteger('ordens')->default(0);
            $table->decimal('preco_unit', 16, 4)->default(0);
            // O que o motor respondeu (fica para auditoria e para a planilha)...
            $table->string('status_motor', 30);
            $table->decimal('score', 5, 1)->default(0);
            $table->json('motivos');
            $table->string('codigo_sugerido', 40)->nullable();
            $table->json('alternativas');
            // ...e o estado atual, que muda quando alguém confere.
            $table->string('status', 30);
            $table->enum('decisao', ['OK', 'TROCAR', 'NAO_TEMOS'])->nullable();
            $table->string('codigo_escolhido', 40)->nullable();
            $table->boolean('pulado')->default(false);
            $table->foreignId('decidido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decidido_em')->nullable();
            $table->timestamps();

            // Fila de conferência: maior valor primeiro, dentro da cotação.
            $table->index(['cotacao_id', 'status', 'valor_periodo']);
            $table->index(['cotacao_id', 'texto_normalizado']);
        });

        Schema::create('cotacao_decisoes', function (Blueprint $table) {
            $table->id();
            $table->string('texto_normalizado', 512);
            $table->text('descricao_cliente');
            $table->enum('decisao', ['OK', 'TROCAR', 'NAO_TEMOS', 'ANULADO']);
            $table->string('codigo', 40)->nullable();
            $table->string('descricao_nossa', 512)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cotacao_id')->nullable()->constrained('cotacoes')->nullOnDelete();
            // Nome de quem decidiu e arquivo de origem quando a decisão veio do .exe
            // (importação), onde não há usuário nem cotação do CRM para apontar.
            $table->string('origem')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('texto_normalizado');
        });

        Schema::create('cotacao_memoria', function (Blueprint $table) {
            $table->string('texto_normalizado', 512)->primary();
            $table->text('descricao_cliente');
            $table->enum('decisao', ['OK', 'TROCAR', 'NAO_TEMOS']);
            $table->string('codigo', 40)->nullable();
            $table->string('descricao_nossa', 512)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('quem', 120)->nullable();
            $table->foreignId('decisao_id')->constrained('cotacao_decisoes');
            $table->timestamp('atualizado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cotacao_memoria');
        Schema::dropIfExists('cotacao_decisoes');
        Schema::dropIfExists('cotacao_itens');
        Schema::dropIfExists('cotacoes');
    }
};
