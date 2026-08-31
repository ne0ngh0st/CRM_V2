<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indices COVERING para as agregacoes de faturamento (Regra de ouro nº 6 e nº 9).
 *
 * CONTEXTO: com a carga do historico 2018-2025, `faturamentos` foi de 1,0 M para
 * 5,85 M de linhas. Os indices que existiam -- `(data_emissao)` e
 * `(cod_vendedor, data_emissao)` -- deixaram de ser escolhidos pelo otimizador, e
 * as duas agregacoes do sistema passaram a fazer table scan de 5,8 M de linhas.
 *
 * ⚠️ O PONTO QUE NAO E OBVIO: o problema nao era falta de indice, era o indice nao
 * COBRIR a coluna somada. Como `valor_total` nao estava neles, o MySQL teria que ir
 * na tabela linha a linha para somar -- e corretamente concluiu que varrer tudo
 * sequencialmente sairia mais barato. Acrescentar `valor_total` ao fim de cada
 * indice faz a leitura acontecer 100% dentro do indice (`Using index`), sem tocar
 * a tabela nenhuma vez.
 *
 * MEDIDO em 2026-08-31, com as 5,85 M de linhas reais:
 *
 *   Agregacao por ano (DashboardBlocos::faturamentoComparacao)
 *     table scan .................... 4.074 ms
 *     com covering .....................660 ms   (6,2x)
 *
 *   Soma por vendedor (MetaRankingResolver::metaVsFaturamento)
 *     table scan .................... 3.785 ms
 *     com covering .................... 493 ms   (7,7x)
 *
 * Para calibrar: antes do historico, com 1,0 M de linhas e sem estes indices, a
 * agregacao por ano levava 1.131 ms. Ou seja, com 5,7x mais dado a consulta ficou
 * 1,7x MAIS RAPIDA do que era -- o custo passou a ser proporcional a janela
 * consultada, nao ao tamanho da tabela.
 *
 * ⚠️ `faturamentos_data_emissao_index` ficou REDUNDANTE: `(data_emissao)` e prefixo
 * de `(data_emissao, valor_total)`, entao qualquer consulta que usava o primeiro e
 * atendida pelo segundo. Nao foi removido aqui de proposito -- e uma decisao a
 * parte, que economiza espaco e acelera INSERT (relevante nos imports em lote), mas
 * que merece ser tomada e medida por si, nao de carona nesta migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faturamentos', function (Blueprint $table) {
            // Escopo "empresa inteira": SUM(valor_total) por intervalo de datas.
            $table->index(['data_emissao', 'valor_total'], 'fat_data_valor_idx');

            // Escopo por vendedor: mesma soma, filtrada por cod_vendedor.
            // A ordem importa -- cod_vendedor vem primeiro porque e igualdade,
            // data_emissao depois porque e intervalo, e valor_total por ultimo
            // porque so precisa estar presente, nunca e filtrado.
            $table->index(['cod_vendedor', 'data_emissao', 'valor_total'], 'fat_vend_data_valor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('faturamentos', function (Blueprint $table) {
            $table->dropIndex('fat_data_valor_idx');
            $table->dropIndex('fat_vend_data_valor_idx');
        });
    }
};
