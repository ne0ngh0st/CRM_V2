<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chave de origem para os dois imports que trazem registro do PALMA legado.
 *
 * ⚠️ POR QUE ISSO PRECISOU EXISTIR: o `legado:import-orcamentos-historico` nasceu como
 * migração pontual e fazia `insertGetId` puro -- sem truncate e sem chave de dedup. Rodar
 * de novo não traria só o que falta: reinseriria os 2.265 por cima dos 1.860 já
 * importados, duplicando todo o histórico sem erro nenhum. Com `legado_id` unique, a
 * segunda rodada é upsert e o comando vira reexecutável.
 *
 * Nullable de propósito: orçamento criado na tela do v2 não tem origem no legado e fica
 * com null. O unique do MySQL não conta NULL, então vários nativos convivem sem colidir.
 *
 * ⚠️ O valor é o `id` da tabela de ORIGEM (`ORCAMENTOS.id` / `observacoes.id` do legado),
 * não um número nosso. Se um dia a origem for reconstruída do zero e reciclar ids, esta
 * coluna passa a apontar para outra coisa -- conferir antes de reimportar nesse cenário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->unsignedBigInteger('legado_id')->nullable()->unique()->after('id');
        });

        Schema::table('observacoes', function (Blueprint $table) {
            $table->unsignedBigInteger('legado_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropUnique(['legado_id']);
            $table->dropColumn('legado_id');
        });

        Schema::table('observacoes', function (Blueprint $table) {
            $table->dropUnique(['legado_id']);
            $table->dropColumn('legado_id');
        });
    }
};
