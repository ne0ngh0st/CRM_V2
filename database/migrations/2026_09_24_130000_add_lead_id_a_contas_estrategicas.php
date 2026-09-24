<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga a conta-alvo (Visão Diretor → Maiores por Segmento) ao lead que a diretoria abriu
 * para ela com um responsável.
 *
 * É esta coluna que impede o segundo clique de criar um segundo lead para a mesma rede, e
 * que deixa a linha dizer "lead aberto com Fulano". Ver `LeadDaConta`.
 *
 * `nullOnDelete`: lead não é apagado de verdade no CRM (vira `status = excluido`), mas se
 * um dia for, a conta volta a oferecer o botão em vez de apontar para o nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('observacao')->constrained('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
