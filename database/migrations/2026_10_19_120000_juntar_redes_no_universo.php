<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maiores por Segmento: as empresas de uma mesma rede da Maiores redes viram UMA linha
 * (Tony, 2026-10-19). Ver `UniversoDoSegmento::juntarPorRede()`.
 *
 * - `visao_diretor_universo.rede_id`: a linha da rede (`redes_mercado.id`); `empresas`,
 *   quantas empresas ela juntou.
 * - `visao_diretor_universo_filiais`: as filiais que essa linha conta. É o que a Carteira
 *   filtra no `?rede_alvo=` — mesma fonte da contagem, para o número clicado bater com a
 *   lista aberta. Só `reconstruir()` escreve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visao_diretor_universo', function (Blueprint $table) {
            $table->unsignedBigInteger('rede_id')->nullable()->after('posicao');
            $table->unsignedInteger('empresas')->nullable()->after('rede_id');
        });

        Schema::create('visao_diretor_universo_filiais', function (Blueprint $table) {
            $table->unsignedBigInteger('rede_id');
            $table->unsignedBigInteger('cliente_id');
            $table->primary(['rede_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visao_diretor_universo_filiais');

        Schema::table('visao_diretor_universo', function (Blueprint $table) {
            $table->dropColumn(['rede_id', 'empresas']);
        });
    }
};
