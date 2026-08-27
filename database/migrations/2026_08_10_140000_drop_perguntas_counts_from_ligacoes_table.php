<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ligação no CRM-V2 é só contagem de chamadas — não existe roteiro de perguntas
 * (decisão do Tony, 2026-08-10; no legado é `PERGUNTAS_LIGACAO`/`RESPOSTAS_LIGACAO`,
 * que não será portado).
 *
 * Essas duas colunas vieram junto na primeira versão e nunca tiveram quem escrevesse
 * nelas em uso real — só o LigacaoSeeder. O Dashboard chegava a calcular uma média de
 * "perguntas respondidas" em cima delas que nem era enviada pro front.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ligacoes', function (Blueprint $table) {
            $table->dropColumn(['perguntas_respondidas_count', 'perguntas_obrigatorias_count']);
        });
    }

    public function down(): void
    {
        Schema::table('ligacoes', function (Blueprint $table) {
            $table->unsignedTinyInteger('perguntas_respondidas_count')->default(0);
            $table->unsignedTinyInteger('perguntas_obrigatorias_count')->default(0);
        });
    }
};
