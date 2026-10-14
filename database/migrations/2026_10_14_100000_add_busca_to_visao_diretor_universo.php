<?php

use App\Services\VisaoDiretor\UniversoDoSegmento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto de busca pré-normalizado da Visão Diretor → Maiores por Segmento.
 *
 * Desde 2026-10-14 a busca atravessa os segmentos (o cadastro do TOTVS erra segmento), então
 * lê a tabela inteira. Com os 24 `LIKE` por linha de `TermoDeBusca::sql()` isso dava ~330 ms
 * por consulta (e a tela faz duas: tiles e lista); com a coluna normalizada é um `LIKE` só,
 * ~110 ms. Ver `TermoDeBusca::sqlNormalizado()`.
 *
 * Preenche na hora pelo MESMO código do `reconstruir()`: sem isso a busca não acharia nada
 * até a próxima reconstrução agendada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visao_diretor_universo', function (Blueprint $table) {
            $table->string('busca_texto', 800)->nullable();
            $table->string('documento_digitos', 20)->nullable();
        });

        UniversoDoSegmento::preencherBusca();
    }

    public function down(): void
    {
        Schema::table('visao_diretor_universo', function (Blueprint $table) {
            $table->dropColumn(['busca_texto', 'documento_digitos']);
        });
    }
};
