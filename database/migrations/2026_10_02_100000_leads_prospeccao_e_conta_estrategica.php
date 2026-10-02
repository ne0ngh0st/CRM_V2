<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads da prospecção (CSVs da pasta `Leads/`) e a ligação lead → conta-alvo da
 * Visão Diretor.
 *
 * 1. `leads.origem` ganha `prospeccao`. ⚠️ Enum escrito LITERAL, não a partir de
 *    `Lead::ORIGENS`: migration é retrato de um momento (lição da 2026_09_03_100000).
 *
 * 2. O vínculo vira VÁRIOS leads por conta (`leads.conta_estrategica_id`), no lugar do
 *    único `contas_estrategicas.lead_id`: a prospecção traz vários CNPJs da mesma rede.
 *    `conta_vinculo` diz se a ligação foi sugerida pelo import (falta alguém confirmar),
 *    confirmada, ou recusada — a recusada guarda a conta para o import não sugerir a
 *    mesma de novo.
 *
 * 3. O lead aberto pelo botão "Gerar lead" passa para o lado do lead, já confirmado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leads MODIFY origem ENUM('sistema','manual','wordpress','prospeccao') NOT NULL DEFAULT 'sistema'");

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('conta_estrategica_id')->nullable()->after('segmento')
                ->constrained('contas_estrategicas')->nullOnDelete();
            $table->enum('conta_vinculo', ['sugerido', 'confirmado', 'recusado'])->nullable()->after('conta_estrategica_id');
        });

        DB::table('contas_estrategicas')->whereNotNull('lead_id')->orderBy('id')
            ->get(['id', 'lead_id'])
            ->each(fn ($c) => DB::table('leads')->where('id', $c->lead_id)
                ->update(['conta_estrategica_id' => $c->id, 'conta_vinculo' => 'confirmado']));

        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('observacao')->constrained('leads')->nullOnDelete();
        });

        // Volta só um lead por conta (o mais antigo confirmado): o desenho antigo não cabia mais.
        DB::table('leads')->where('conta_vinculo', 'confirmado')->whereNotNull('conta_estrategica_id')
            ->orderBy('id')->get(['id', 'conta_estrategica_id'])
            ->unique('conta_estrategica_id')
            ->each(fn ($l) => DB::table('contas_estrategicas')->where('id', $l->conta_estrategica_id)
                ->update(['lead_id' => $l->id]));

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conta_estrategica_id');
            $table->dropColumn('conta_vinculo');
        });

        DB::table('leads')->where('origem', 'prospeccao')->update(['origem' => 'sistema']);
        DB::statement("ALTER TABLE leads MODIFY origem ENUM('sistema','manual','wordpress') NOT NULL DEFAULT 'sistema'");
    }
};
