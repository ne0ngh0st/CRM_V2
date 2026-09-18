<?php

namespace App\Services\Equipe;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;

/**
 * Único ponto que grava `segmentos_vendedor`.
 *
 * Dois call-sites (editar usuário na lista e o quadro visual) — a segunda cópia
 * é o que faz as duas telas divergirem sozinhas (Regra de ouro nº 8). O vínculo
 * é por `cod_vendedor`, não por `user_id`: duas pessoas com o mesmo código
 * dividem a carteira e, portanto, os segmentos.
 *
 * ⚠️ Representante é forçado em SUPERMERCADISTA aqui, não no formulário. Um
 * `v-if` no Vue não impede o POST; a regra de negócio tem que valer no
 * servidor, inclusive quando a edição vem do modal antigo da lista.
 */
class SegmentoVendedorSync
{
    /**
     * @param  list<int>  $segmentoIds
     */
    public function substituir(string $codVendedor, array $segmentoIds, ?string $perfil = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $segmentoIds)));

        if ($perfil === 'representante') {
            $ids = $this->somenteSupermercadista();
        }

        SegmentoVendedor::query()->where('cod_vendedor', $codVendedor)->delete();

        foreach ($ids as $id) {
            if ($id <= 0) {
                continue;
            }

            SegmentoVendedor::create([
                'cod_vendedor' => $codVendedor,
                'segmento_id' => $id,
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function somenteSupermercadista(): array
    {
        $id = Segmento::query()
            ->where('codigo', Segmento::CODIGO_SUPERMERCADISTA)
            ->value('id');

        return $id ? [(int) $id] : [];
    }
}
