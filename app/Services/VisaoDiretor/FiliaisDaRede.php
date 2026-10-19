<?php

namespace App\Services\VisaoDiretor;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * "As filiais desta rede", como a linha juntada da Maiores por Segmento as conta
 * (`UniversoDoSegmento::juntarPorRede()`). É o que a Carteira filtra no `?rede_alvo=`.
 *
 * Lê `visao_diretor_universo_filiais`, a mesma tabela que gerou o número da linha — e não
 * os vínculos de `redes_mercado_vinculos` direto: a linha conta a EMPRESA inteira (todas as
 * filiais da raiz) e só as empresas que ficaram na página, e é isso que o clique tem que
 * abrir. Rede sem linha juntada não casa nada (nunca "sem restrição").
 */
class FiliaisDaRede
{
    public function aplicar(Builder|QueryBuilder $query, int $redeId): void
    {
        $ids = DB::table('visao_diretor_universo_filiais')->where('rede_id', $redeId)->pluck('cliente_id')->all();

        $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIntegerInRaw('clientes.id', $ids);
    }

    /**
     * Pedaço da chave de cache da Carteira: id + quando a tabela foi reconstruída (é só aí
     * que as filiais mudam). `null` se a rede não existe (o filtro então é ignorado).
     */
    public function assinatura(int $redeId): ?string
    {
        if (! DB::table('redes_mercado')->where('id', $redeId)->exists()) {
            return null;
        }

        return $redeId.':'.DB::table('visao_diretor_universo')->max('reconstruido_em');
    }

    public function nome(int $redeId): ?string
    {
        return DB::table('redes_mercado')->where('id', $redeId)->value('nome');
    }
}
