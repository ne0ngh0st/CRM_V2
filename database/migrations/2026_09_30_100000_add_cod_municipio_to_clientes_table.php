<?php

use App\Services\Geografia\MunicipioSincronizador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código IBGE do município de cada filial, dentro de `clientes`.
 *
 * É valor DERIVADO do texto `clientes.municipio` + `estado`, resolvido pelo de-para do
 * schema do BI — mesma família de `data_ultimo_contato`: mora aqui para o mapa da
 * Carteira agrupar e filtrar por uma coluna inteira indexada, em vez de refazer um join
 * entre schemas sobre uma expressão de texto a cada requisição.
 *
 * Não fere a Regra de ouro nº 4: o cadastro continua sendo espelho do TOTVS; isto é a
 * mesma informação que ele manda, em forma de código.
 *
 * ⚠️ Dono único: `MunicipioSincronizador`. A migration preenche chamando o MESMO serviço
 * que os imports chamam — onde o de-para ainda não foi carregado (`bi:carregar-referencias`)
 * a coluna nasce toda NULL e `php artisan clientes:resolver-municipios` a preenche depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->unsignedInteger('cod_municipio')->nullable()->after('municipio');
            // Para o filtro `?municipio=` (o clique na bolha do mapa).
            $table->index('cod_municipio');

            /*
             * Para a agregação do mapa, que agrupa por (cliente, município) e só olha a
             * loja (comercial × entrega): com este índice a leitura acontece inteira
             * dentro dele (`Using index`), em ordem, sem tabela temporária. Medido no
             * escopo empresa (92 mil filiais): 418 ms -> 150 ms nesse passo.
             *
             * ⚠️ A ORDEM das colunas é a do `GROUP BY` de `CarteiraController::mapaDoEscopo()`.
             * Inverter lá ou aqui devolve a tabela temporária sem erro nenhum.
             */
            $table->index(['cod_cliente', 'cod_municipio', 'loja'], 'clientes_mapa_index');
        });

        app(MunicipioSincronizador::class)->sincronizar();
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex('clientes_mapa_index');
            $table->dropIndex(['cod_municipio']);
            $table->dropColumn('cod_municipio');
        });
    }
};
