<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem recebe o resumo diário da equipe por e-mail, e em que formato.
 *
 *   nenhum      — não recebe (default: e-mail automático é opt-in, nunca opt-out)
 *   equipe      — a equipe do próprio código (quem tem `cod_super` = ele, mais ele)
 *   consolidado — todas as equipes marcadas como `equipe`, uma seção por gestor
 *
 * ⚠️ Enum e não boolean: o Paulo (diretor sem equipe própria) e o Leandro (cópia dele)
 * recebem outra coisa que os supervisores, e um segundo campo para isso deixaria
 * combinações sem sentido ("consolidado" sem "recebe").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('resumo_diario', ['nenhum', 'equipe', 'consolidado'])
                ->default('nenhum')
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('resumo_diario');
        });
    }
};
