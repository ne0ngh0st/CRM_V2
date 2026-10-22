<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Item do estoque de revenda (relatório 026, filial 05) — o catálogo da Cotação.
 *
 * Só leitura: quem escreve é o `totvs:import-estoque-revenda`.
 *
 * ⚠️ `ult_preco` é custo de compra. Nunca serializar este model inteiro para a tela;
 * o `CotacaoService` monta o payload e só inclui o custo para quem pode ver.
 */
class EstoqueRevenda extends Model
{
    protected $table = 'estoque_revenda';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'fisico' => 'float',
            'reserva' => 'float',
            'disponivel' => 'float',
            'comprado' => 'float',
            'ult_preco' => 'float',
        ];
    }
}
