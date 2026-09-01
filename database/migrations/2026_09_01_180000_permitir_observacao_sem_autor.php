<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observação pode não ter autor humano.
 *
 * A mensagem que o cliente escreve no formulário do site vira observação no
 * lead, para o vendedor ler onde já lê tudo. Mas quem escreveu foi o cliente,
 * não um usuário do CRM — e atribuir a nota ao vendedor que a recebeu seria
 * gravar uma autoria falsa.
 *
 * Quem lê usa Observacao::nomeAutor(), que devolve o rótulo do sistema quando
 * user_id é nulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observacoes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('observacoes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
