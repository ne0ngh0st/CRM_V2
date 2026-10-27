<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Excel com mais de uma aba espera a pessoa escolher qual é a lista.
 * O enum é literal de propósito: a migration é o retrato deste momento,
 * não a constante do model (que pode ganhar valor depois).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE cotacoes MODIFY COLUMN status ENUM('processando', 'pronta', 'falha', 'aguardando_aba') NOT NULL DEFAULT 'processando'");

        Schema::table('cotacoes', function (Blueprint $table) {
            $table->string('aba', 191)->nullable()->after('caminho_arquivo');
        });
    }

    public function down(): void
    {
        DB::table('cotacoes')->where('status', 'aguardando_aba')->update(['status' => 'falha']);

        Schema::table('cotacoes', function (Blueprint $table) {
            $table->dropColumn('aba');
        });

        DB::statement("ALTER TABLE cotacoes MODIFY COLUMN status ENUM('processando', 'pronta', 'falha') NOT NULL DEFAULT 'processando'");
    }
};
