<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Capital social e porte da empresa, para a coluna da Carteira e dos Leads.
 *
 * Na Receita os dois são da EMPRESA (os 8 primeiros dígitos do CNPJ), não da filial. Aqui
 * ficam repetidos em cada CNPJ de 14 dígitos de propósito: guardar a raiz seria a coluna
 * que a Regra de ouro nº 3 proíbe. A carga mensal calcula a raiz em memória e grava o
 * valor em cada filial.
 *
 * Nulo = não sabemos (CNPJ ainda não passou pela carga com o arquivo de Empresas, ou a
 * Receita não informa o porte). Capital 0 é valor real: empresário individual,
 * associação e muita empresa antiga declaram zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->decimal('capital_social', 17, 2)->nullable()->after('data_situacao');
            // ME, EPP ou DEMAIS — ver App\Services\Receita\PorteEmpresa.
            $table->string('porte', 6)->nullable()->after('capital_social');
        });
    }

    public function down(): void
    {
        Schema::table('cnpj_situacoes', function (Blueprint $table) {
            $table->dropColumn(['capital_social', 'porte']);
        });
    }
};
