<?php

namespace App\Services\Equipe;

use Illuminate\Support\Collection;

/**
 * Monta a árvore hierárquica (supervisor -> equipe) a partir de vendedor_perfis.cod_super/
 * cod_vendedor. Substitui o organograma do legado, que era um diagrama Mermaid escrito à
 * mão no PHP com nomes reais fixos — aqui é sempre gerado a partir dos dados atuais.
 *
 * cod_vendedor NÃO é único no legado (código pode ser compartilhado entre usuários
 * diferentes, ver CLAUDE.md) — por isso a árvore é chaveada por id de usuário, e o
 * código só é usado para RESOLVER quem é o supervisor de cada nó (se o código do
 * supervisor for compartilhado, o primeiro usuário encontrado com aquele código vira
 * o nó-pai; os demais continuam existindo como nós próprios, sem se fundir).
 */
class OrganogramaBuilder
{
    /**
     * @param  Collection<int, array{id: int, codVendedor: string, codSuper: ?string, nome: string, perfil: string}>  $nos
     * @return array<int, array{id: int, codVendedor: string, nome: string, perfil: string, filhos: array}>
     */
    public function construir(Collection $nos): array
    {
        $porId = $nos->keyBy('id');
        $idPorCodigo = $nos->groupBy('codVendedor')->map(fn ($grupo) => $grupo->first()['id']);

        $filhosPorPai = [];
        foreach ($nos as $no) {
            $idPai = ($no['codSuper'] && $idPorCodigo->has($no['codSuper']) && $idPorCodigo[$no['codSuper']] !== $no['id'])
                ? $idPorCodigo[$no['codSuper']]
                : '_raiz';
            $filhosPorPai[$idPai][] = $no['id'];
        }

        $montar = function (int $id, array $visitados) use (&$montar, $porId, $filhosPorPai): array {
            $no = $porId->get($id);
            $visitados[$id] = true;

            $filhos = collect($filhosPorPai[$id] ?? [])
                ->reject(fn (int $filhoId) => isset($visitados[$filhoId]))
                ->map(fn (int $filhoId) => $montar($filhoId, $visitados))
                ->sortBy('nome')
                ->values()
                ->all();

            return [
                'id' => $no['id'],
                'codVendedor' => $no['codVendedor'],
                'nome' => $no['nome'],
                'perfil' => $no['perfil'],
                'filhos' => $filhos,
            ];
        };

        return collect($filhosPorPai['_raiz'] ?? [])
            ->map(fn (int $id) => $montar($id, []))
            ->sortBy('nome')
            ->values()
            ->all();
    }
}
