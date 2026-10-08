<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CNAE principal do estabelecimento, para classificar o segmento dos leads da base de
 * prospecção (ver App\Services\Leads\SegmentoDosLeads).
 *
 * Só os 7 dígitos ("4711302"), sem máscara: é o formato da base aberta da Receita, e a
 * comparação com o mapa CNAE → segmento fica exata. Nulo = não sabemos (CNPJ inexistente
 * ou ainda não passou pela carga).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->char('cnae_principal', 7)->nullable()->after('porte');
        });
    }

    public function down(): void
    {
        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->dropColumn('cnae_principal');
        });
    }
};
