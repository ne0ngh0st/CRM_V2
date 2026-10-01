<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Quando foi a última carga da base?" — `SituacaoCadastral::idadeDaBase()` — roda a
 * cada minuto pelo `metricas:publicar`. Sem este índice seria uma varredura das ~81 mil
 * linhas por minuto; com ele é uma leitura no fim do índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->index(['fonte', 'atualizado_em']);
        });
    }

    public function down(): void
    {
        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->dropIndex(['fonte', 'atualizado_em']);
        });
    }
};
