<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Todos os clientes e leads de cada segmento, pré-calculados para a lista paginada da
 * Visão Diretor → Maiores por Segmento. Quem escreve é só `UniversoDoSegmento::reconstruir()`.
 *
 * Por que uma tabela própria: a lista é ordenada por capital social, que mora em
 * `cnpj_situacoes`. Ordenar ao vivo por coluna de outra tabela é filesort sobre o segmento
 * inteiro — 820 ms em Supermercadista (dev, 13/10), o dobro do orçamento da Regra nº 9.
 *
 * ⚠️ `capital_ordem` = capital ou -1, e não `ORDER BY capital IS NULL`: expressão como
 * primeira chave de ordenação não usa índice (lição da Carteira, 2026-08-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visao_diretor_universo', function (Blueprint $table) {
            $table->id();
            $table->string('segmento_codigo', 10);
            $table->enum('tipo', ['cliente', 'lead']);
            $table->string('cod_cliente', 20)->nullable();
            // Filial-âncora (menor id entre as filiais do cliente no segmento): link da ficha.
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            // Cliente: a razão social (a linha é o cliente inteiro, não uma loja).
            $table->string('nome');
            $table->string('razao_social')->nullable();
            // Só para a busca: "OBA HORTIFRUTI" é fantasia de loja, a razão é outra.
            $table->string('nome_fantasia')->nullable();
            $table->string('documento', 20)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cod_vendedor', 20)->nullable();
            $table->unsignedInteger('lojas')->default(1);
            // Consolidada entre TODAS as filiais do cliente, como a pill da Carteira.
            $table->date('data_ultima_compra')->nullable();
            $table->decimal('capital_social', 17, 2)->nullable();
            $table->decimal('capital_ordem', 17, 2);
            $table->string('porte', 6)->nullable();
            $table->timestamp('reconstruido_em');

            $table->index(['segmento_codigo', 'capital_ordem', 'id'], 'vdu_lista_idx');
            // Lista filtrada por tipo (status "Lead" num segmento cheio de clientes).
            $table->index(['segmento_codigo', 'tipo', 'capital_ordem', 'id'], 'vdu_lista_tipo_idx');
            // Contagens por status sem ir à tabela (`Using index`). `cod_cliente`/`lead_id`
            // entram porque a tabela do segmento tira quem é de rede (NOT IN nos dois).
            $table->index(['segmento_codigo', 'tipo', 'data_ultima_compra', 'cod_cliente', 'lead_id'], 'vdu_contagem_idx');
            // "Atualizado em" da tela: MAX() lido do índice, não da tabela inteira.
            $table->index('reconstruido_em');
            $table->index('cod_cliente');
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visao_diretor_universo');
    }
};
