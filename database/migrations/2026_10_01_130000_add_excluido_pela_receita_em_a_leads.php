<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Carimbo de "este lead saiu do CRM porque o CNPJ não estava ativo na Receita".
 *
 * É o que permite o lead VOLTAR sozinho quando o CNPJ for regularizado (decisão do
 * Tony, 2026-10-01) sem ressuscitar o lead que alguém excluiu à mão: só volta quem tem
 * o carimbo. Ver `SituacaoCadastral::sincronizarLeads()`.
 *
 * Backfill: a primeira exclusão em massa rodou em produção em 2026-10-01 e marcou os
 * 3.072 leads TODOS no mesmo minuto (11:46, horário do app) — conferido no RDS antes de
 * escrever isto. A janela de 11:40 a 11:59 pega exatamente esses e nenhum excluído à
 * mão (que não existia nesse minuto). Em dev e na suíte a janela não casa nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('excluido_pela_receita_em')->nullable()->after('status');
        });

        DB::table('leads')
            ->where('origem', 'sistema')
            ->where('status', 'excluido')
            ->whereBetween('updated_at', ['2026-10-01 11:40:00', '2026-10-01 11:59:59'])
            ->update(['excluido_pela_receita_em' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('excluido_pela_receita_em');
        });
    }
};
