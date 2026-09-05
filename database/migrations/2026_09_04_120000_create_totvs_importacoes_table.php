<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das rodadas de `totvs:atualizar`.
 *
 * ⚠️ EXISTE PORQUE A TELA NÃO PODE LER O DISCO. Os relatórios e o marcador de última
 * importação vivem em `storage/app/totvs` da app-2 (o nó do scheduler e do worker), mas a
 * tela é servida pelo ALB e cai em qualquer um dos dois nós. Uma página que lesse o
 * sistema de arquivos local mostraria "nunca importado" sempre que calhasse de abrir na
 * app-1 — e pior, de forma intermitente, que é o tipo de defeito que ninguém consegue
 * reproduzir. O banco é o único lugar que os dois nós enxergam igual.
 *
 * ⚠️ `status` inclui `sem_mudanca` como resultado NORMAL, não como falha: é o que a
 * rodada de hora em hora devolve na esmagadora maioria das vezes (o Tony sobe relatório
 * uma vez por dia, no máximo). Marcar isso como erro treinaria qualquer um a ignorar a
 * tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('totvs_importacoes', function (Blueprint $table) {
            $table->id();

            $table->enum('status', ['executando', 'sucesso', 'sem_mudanca', 'falha'])
                ->default('executando');

            // 'agendador' = cron horário; 'manual' = botão da tela.
            $table->enum('origem', ['agendador', 'manual'])->default('agendador');

            // Quem clicou. Nulo quando veio do cron — é assim que a tela distingue os dois
            // sem precisar de outra coluna.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('iniciada_em');
            $table->timestamp('concluida_em')->nullable();

            // Um item por importador: nome, segundos e as linhas de saída que interessam.
            // JSON e não tabela filha de propósito: isto é log para leitura humana, nunca
            // é filtrado nem agregado, e uma tabela a mais só custaria join.
            $table->json('passos')->nullable();

            $table->text('erro')->nullable();

            $table->timestamps();

            // A tela lista as últimas N por data, e o controller pergunta "existe alguma
            // executando agora?" antes de deixar disparar outra.
            $table->index(['status', 'iniciada_em']);
            $table->index('iniciada_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('totvs_importacoes');
    }
};
