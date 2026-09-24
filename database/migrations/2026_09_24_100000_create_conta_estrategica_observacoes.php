<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico da observação das contas-alvo (Visão Diretor → Maiores por Segmento).
 *
 * `contas_estrategicas.observacao` continua sendo o valor ATUAL (é o que a tabela mostra e o
 * Excel leva); esta tabela guarda cada versão, com autor e data. Quem escreve aqui é só o
 * gancho `ContaEstrategica::saved` — ver o model.
 *
 * `texto` nulo = a observação foi apagada naquele momento. Registrar a remoção é o ponto:
 * sem ela, o histórico mostraria como vigente um texto que alguém tirou de propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conta_estrategica_observacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas_estrategicas')->cascadeOnDelete();
            // Nulo = carga da planilha (comando), ou usuário que deixou de existir.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('texto')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conta_id', 'id']);
        });

        /*
         * A observação que já existe vira a primeira versão, datada de quando a conta
         * nasceu — sem isso o histórico começaria vazio para as ~370 contas da planilha,
         * e a primeira edição pareceria ser a origem do texto.
         */
        DB::statement(<<<'SQL'
            INSERT INTO conta_estrategica_observacoes (conta_id, user_id, texto, created_at)
            SELECT id, NULL, observacao, COALESCE(created_at, NOW())
            FROM contas_estrategicas
            WHERE observacao IS NOT NULL AND observacao <> ''
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('conta_estrategica_observacoes');
    }
};
