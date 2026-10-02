<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Botão "Importar leads" na /atualizacoes: a rodada nasce `executando` no clique (a tela
 * entra em modo de acompanhamento na hora) e guarda quem disparou. Rodada pelo terminal
 * fica com `user_id` nulo.
 *
 * ⚠️ Enum escrito literal — migration é retrato de um momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leads_importacoes MODIFY status ENUM('executando','sucesso','falhou') NOT NULL DEFAULT 'executando'");

        Schema::table('leads_importacoes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('simulacao')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads_importacoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        DB::table('leads_importacoes')->where('status', 'executando')->update(['status' => 'falhou']);
        DB::statement("ALTER TABLE leads_importacoes MODIFY status ENUM('sucesso','falhou') NOT NULL DEFAULT 'sucesso'");
    }
};
