<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Também conhecida como": as marcas pelas quais a rede é procurada ("OBA, OBA HORTIFRUTI"
 * na FARTURA). Texto livre separado por vírgula, só para a busca (`BuscaNasRedes`) — serve
 * sobretudo à rede-ALVO sem cliente ligado, onde não há filial para a busca achar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->string('apelidos', 500)->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->dropColumn('apelidos');
        });
    }
};
