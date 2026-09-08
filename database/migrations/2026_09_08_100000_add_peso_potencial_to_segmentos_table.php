<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Peso do segmento no cálculo de Potencial da Carteira.
 *
 * Valores entregues pela diretoria em 08/09/2026 (planilha "Potencial segmentos
 * 08-09-2026.xlsx"). Escala 0-20, definida por eles: 20 = onde a Autopel mais tem a ganhar
 * por cliente recuperado, 0 = segmento que não é alvo de reativação.
 *
 * ⚠️ COLUNA EM `segmentos`, e não uma tabela nova, porque o peso é 1:1 com o segmento — é
 * atributo dele, não relação. A `potencial_pesos` (segmento × família de produto) continua
 * existindo e sem uso: ela responde outra pergunta, que volta quando a regra de família for
 * fechada com a diretoria. Duas tabelas de peso conviveriam mal; por isso esta é a que a
 * tela usa, e está escrito aqui qual é qual.
 *
 * ⚠️ Default 0, nunca 1: peso ausente tem que valer "não é alvo", não "vale um". Segmento
 * novo que apareça no TOTVS entra zerado e some do ranking de potencial até alguém definir
 * o peso — o oposto (entrar valendo 1) o colocaria no meio da lista sem ninguém ter
 * decidido nada.
 */
return new class extends Migration
{
    /** codigo do segmento => peso, exatamente como a diretoria entregou. */
    private const PESOS = [
        '101' => 20,  // SUPERMERCADISTA
        '103' => 0,   // ORGAO PUBLICO
        '104' => 20,  // REVENDA
        '105' => 0,   // CORPORATIVO
        '106' => 0,   // TRANSPORTE
        '107' => 0,   // AUTOMOTIVO PEÇAS E LOCADORAS
        '108' => 10,  // REDE DE LOJAS
        '109' => 8,   // DROGARIAS
        '111' => 0,   // CORPORATIVO EDUCACIONAL
        '112' => 10,  // ALIMENTACAO
        '113' => 0,   // ESTACIONAMENTOS
        '114' => 8,   // POSTOS E CONVENIENCIAS
        '115' => 10,  // MAGAZINES
        '116' => 8,   // COSMETICOS
        '117' => 0,   // CORPORATIVO SAUDE
        '118' => 0,   // PEDAGIO
        '119' => 0,   // ENTRETENIMENTO
        '120' => 8,   // CONSTRUCAO
        '121' => 0,   // FABRICANTES DE EQUIPAMENTOS
        '122' => 5,   // PET SHOP
        '123' => 0,   // CORPORATIVO FINANCEIRO
        '124' => 0,   // LOGISTICA
        '125' => 5,   // E-COMMERCE
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('segmentos', 'peso_potencial')) {
            Schema::table('segmentos', function (Blueprint $table) {
                $table->decimal('peso_potencial', 5, 2)->default(0)->after('nome');
            });
        }

        /*
         * ⚠️ A migration PREENCHE os valores, não só cria a coluna: dev e produção já têm
         * os 23 segmentos gravados, e o seeder só alcança banco que é semeado de novo.
         * Sem este bloco a coluna nasceria toda zerada em produção e a tela mostraria
         * potencial 0 para todo mundo — o tipo de defeito que passa por "ainda não
         * calibraram os pesos" e fica meses assim.
         */
        foreach (self::PESOS as $codigo => $peso) {
            DB::table('segmentos')->where('codigo', $codigo)->update(['peso_potencial' => $peso]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('segmentos', 'peso_potencial')) {
            Schema::table('segmentos', function (Blueprint $table) {
                $table->dropColumn('peso_potencial');
            });
        }
    }
};
