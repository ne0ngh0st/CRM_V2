<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duas colunas que a central de downloads precisa — uma de conforto, outra de correção.
 *
 * `bytes` — tamanho do arquivo gerado. A central lista planilhas para rebaixar, às vezes
 * numa conexão de celular, e "2,3 MB" é o que faz decidir baixar agora ou depois.
 * ⚠️ É coluna, e não `Storage::size()` ao montar a tela: em produção o disco é o S3, e
 * perguntar o tamanho de cada linha custaria uma chamada de rede POR ITEM da listagem.
 * O valor é conhecido de graça no instante em que o arquivo é escrito.
 *
 * `modo_visao` — 🔴 CORRIGE UM DADO ERRADO QUE JÁ ESTAVA NO AR, não é conforto.
 * O job reconstrói a query da tela a partir dos filtros, com uma Request sintética e
 * SEM SESSÃO. Só que o modo Equipe/Minha carteira do supervisor mora exatamente na
 * sessão, e `ModoVisao::atual()` devolve EQUIPE fora de contexto HTTP — de propósito,
 * por causa do aquecimento de cache. Consequência: um supervisor que pedisse a planilha
 * da Carteira em "Minha carteira" recebia a carteira da EQUIPE INTEIRA, sem erro nenhum
 * aparecer. Escopo mais amplo que o da tela, num arquivo com a base de clientes de
 * outras pessoas. Guardando o modo no momento do pedido, o job reproduz o que o usuário
 * estava vendo.
 *
 * Nullable nas duas: os registros anteriores a esta migration não têm como sabê-las, e
 * `modo_visao` nulo é lido como EQUIPE — o mesmo default de sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exportacoes', function (Blueprint $table) {
            $table->unsignedBigInteger('bytes')->nullable()->after('linhas');
            $table->string('modo_visao', 10)->nullable()->after('filtros');
        });
    }

    public function down(): void
    {
        Schema::table('exportacoes', function (Blueprint $table) {
            $table->dropColumn(['bytes', 'modo_visao']);
        });
    }
};
