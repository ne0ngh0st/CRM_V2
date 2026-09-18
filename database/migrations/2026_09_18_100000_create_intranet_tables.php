<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intranet — estágio 1 (2026-09-18).
 *
 * Três tabelas, e a terceira é a que carrega o desenho:
 *
 *  - `intranet_publicacoes` — o "post": título, texto em markdown, categoria.
 *  - `intranet_anexos`      — arquivos da publicação (PDF, planilha, imagem de fluxo).
 *  - `intranet_leituras`    — UMA linha por pessoa por publicação, respondendo duas
 *    perguntas ao mesmo tempo: "já abriu?" (`lida_em`, alimenta o contador da faixa do
 *    Painel) e "deu ciência?" (`ciente_em`, alimenta o painel do autor). Nada vai para
 *    `users`, que continua sendo só identidade.
 *
 * ⚠️ O enum de categoria é LITERAL aqui, não `IntranetPublicacao::CATEGORIAS`. Migration é
 * retrato de um momento: a `2026_09_03_100000` montou o enum dela a partir de uma constante
 * e, quando a constante ganhou um valor, banco novo e banco antigo passaram a divergir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intranet_publicacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('categoria', ['aviso', 'regra', 'workflow', 'documento']);
            $table->string('titulo', 160);
            $table->text('corpo');
            $table->boolean('importante')->default(false);
            $table->boolean('fixada')->default(false);
            $table->boolean('exige_ciencia')->default(false);
            /*
             * Sobe quando o autor marca "mudança relevante: pedir ciência de novo". Entra na
             * referência da notificação: a dedupe do NotificacaoService é por
             * (usuário, tipo, referência), e sem um valor novo ela engoliria o segundo aviso
             * em silêncio — o autor pediria ciência de novo e ninguém ficaria sabendo.
             */
            $table->unsignedInteger('revisao_ciencia')->default(1);
            $table->timestamp('publicada_em');
            $table->timestamp('editada_em')->nullable();
            $table->timestamps();
            // Apagar uma regra não pode levar junto o registro de quem deu ciência nela.
            $table->softDeletes();

            // A listagem: não apagadas, fixadas primeiro, mais recentes em seguida.
            $table->index(['deleted_at', 'fixada', 'publicada_em']);
        });

        Schema::create('intranet_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicacao_id')->constrained('intranet_publicacoes')->cascadeOnDelete();
            $table->string('nome_original', 200);
            $table->string('caminho', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('tamanho');
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('intranet_leituras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicacao_id')->constrained('intranet_publicacoes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('lida_em');
            $table->timestamp('ciente_em')->nullable();

            // `user_id` primeiro: as duas contagens do Painel partem do usuário logado.
            $table->unique(['user_id', 'publicacao_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intranet_leituras');
        Schema::dropIfExists('intranet_anexos');
        Schema::dropIfExists('intranet_publicacoes');
    }
};
