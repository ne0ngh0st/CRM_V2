<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Troca o índice do mapa `(cod_cliente, cod_municipio, loja)` por
 * `(cod_municipio, cod_cliente, loja)` — as mesmas colunas, na ordem inversa.
 *
 * 🚨 O ÍNDICE ANTIGO DEIXAVA A LISTA FILTRADA POR REGIÃO EM 7,7 SEGUNDOS no escopo
 * empresa (medido em 2026-09-30, antes de subir). Para `COUNT(DISTINCT cod_cliente)
 * WHERE cod_municipio IN (<municípios da região>)`, o MySQL escolhia o índice que começa
 * por `cod_cliente` e percorria tudo por ele (estimativa de 1,9 milhão de linhas), em vez
 * de ir direto nos municípios. A mesma contagem pelo índice de `cod_municipio` levava
 * 22 ms. Índice escolhido ≠ índice certo — a lição de 2026-08-31, agora com um índice
 * criado por mim sendo o sequestrador.
 *
 * Com `cod_municipio` na frente, um índice só serve aos dois usos:
 *   - o filtro de região/município vira busca por faixa na primeira coluna;
 *   - a agregação do mapa, que agrupa por (município, cliente) e só olha a loja, continua
 *     sendo lida inteira dentro dele (`Using index`), na ordem do `GROUP BY`.
 *
 * Por isso o índice simples `clientes_cod_municipio_index` sai: virou prefixo do novo.
 *
 * ⚠️ A ORDEM do `GROUP BY` de `CarteiraController::mapaDoEscopo()` é a deste índice
 * (município, depois cliente). Inverter um sem o outro devolve a tabela temporária sem
 * erro nenhum.
 *
 * ⚠️ Migration nova, e não edição da `100000`: aquela já rodou em produção, e migration
 * aplicada não roda de novo (lição de 2026-08-31). Idempotente, porque dev e produção
 * chegam aqui no mesmo estado mas nada garante que continue assim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            if (! Schema::hasIndex('clientes', 'clientes_municipio_cliente_index')) {
                $table->index(['cod_municipio', 'cod_cliente', 'loja'], 'clientes_municipio_cliente_index');
            }
        });

        Schema::table('clientes', function (Blueprint $table) {
            if (Schema::hasIndex('clientes', 'clientes_mapa_index')) {
                $table->dropIndex('clientes_mapa_index');
            }

            if (Schema::hasIndex('clientes', 'clientes_cod_municipio_index')) {
                $table->dropIndex('clientes_cod_municipio_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            if (! Schema::hasIndex('clientes', 'clientes_cod_municipio_index')) {
                $table->index('cod_municipio');
            }

            if (! Schema::hasIndex('clientes', 'clientes_mapa_index')) {
                $table->index(['cod_cliente', 'cod_municipio', 'loja'], 'clientes_mapa_index');
            }
        });

        Schema::table('clientes', function (Blueprint $table) {
            if (Schema::hasIndex('clientes', 'clientes_municipio_cliente_index')) {
                $table->dropIndex('clientes_municipio_cliente_index');
            }
        });
    }
};
