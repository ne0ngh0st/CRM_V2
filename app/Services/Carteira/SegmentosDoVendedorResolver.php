<?php

namespace App\Services\Carteira;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use Illuminate\Support\Collection;

/**
 * Quais segmentos um vendedor atende (`segmentos_vendedor` → `segmentos`).
 *
 * Existe como serviço porque a mesma pergunta passou a ser feita em dois lugares: a tela
 * de Equipe, que já montava este mapa à mão, e o Painel, que passou a mostrar o segmento
 * do vendedor no topo e no card de Carteira por Segmento. Regra de ouro nº 8 — a segunda
 * cópia é o que faz as duas telas divergirem sozinhas.
 *
 * ⚠️ O vínculo é por `cod_vendedor` (string), NÃO por `user_id`: `segmentos_vendedor` não
 * tem chave estrangeira para `users`, e `cod_vendedor` é documentadamente não único (há
 * código compartilhado entre usuários no legado). Duas pessoas com o mesmo código veem os
 * mesmos segmentos, o que é o comportamento correto — elas dividem a mesma carteira.
 *
 * ⚠️ Não confundir com `vendedor_perfis.segmento`, que é texto livre herdado do legado e
 * está morto desde o redesenho de 2026-07-29. A fonte é `segmentos_vendedor.segmento_id`.
 */
class SegmentosDoVendedorResolver
{
    /**
     * Nomes dos segmentos de um vendedor, em ordem alfabética. Uma query.
     *
     * @return list<string>
     */
    public function nomes(string $codVendedor): array
    {
        if (trim($codVendedor) === '') {
            return [];
        }

        return SegmentoVendedor::query()
            ->where('segmentos_vendedor.cod_vendedor', $codVendedor)
            ->join('segmentos', 'segmentos.id', '=', 'segmentos_vendedor.segmento_id')
            ->orderBy('segmentos.nome')
            ->pluck('segmentos.nome')
            ->all();
    }

    /**
     * Versão em lote: `cod_vendedor` => Collection<Segmento>. Uma query para a lista
     * inteira, em vez de uma por linha da tabela de Equipe.
     *
     * @param  Collection<int, string>|array<string>  $codVendedores
     * @return Collection<string, Collection<int, Segmento>>
     */
    public function porCodigo(Collection|array $codVendedores): Collection
    {
        $codigos = collect($codVendedores)->filter()->unique()->values();

        if ($codigos->isEmpty()) {
            return collect();
        }

        return SegmentoVendedor::query()
            ->whereIn('cod_vendedor', $codigos)
            ->with('segmento')
            ->get()
            ->groupBy('cod_vendedor')
            ->map(fn (Collection $grupo) => $grupo->pluck('segmento')->filter()->values());
    }
}
