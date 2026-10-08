<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Pedido de inativação passa a sair numa LISTA DIÁRIA, não num e-mail por clique
 * (Tony, 2026-10-05): eram 43 e-mails num dia só para o Cadastro, e a cota do SMTP é de
 * 500 por mês. O clique só registra; `EnviarInativacoesDoDiaJob` manda os pendentes às
 * 18h e carimba `enviado_em`.
 *
 * Os pedidos que já existem SAÍRAM um a um pelo caminho antigo — carimbados com o
 * próprio `created_at`, senão a primeira lista reenviaria todos ao Cadastro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitacoes_inativacao', function (Blueprint $table) {
            $table->timestamp('enviado_em')->nullable()->after('solicitado_por')->index();
        });

        DB::table('solicitacoes_inativacao')->update(['enviado_em' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('solicitacoes_inativacao', function (Blueprint $table) {
            $table->dropIndex(['enviado_em']);
            $table->dropColumn('enviado_em');
        });
    }
};
