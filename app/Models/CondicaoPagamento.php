<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Condição de pagamento do Protheus (tabela SE4) — lookup, chaveado pelo CÓDIGO.
 *
 * O código é o que o Portal recebe (`paymentConditionCode`) e o que o TOTVS já manda em
 * cada pedido importado (`pedidos.condicao_pagamento`). Quem escreve aqui é só o
 * CondicoesPagamento::sincronizar() — via migration (carga inicial) ou pelo comando
 * `condicoes-pagamento:importar` quando o Protheus ganhar condições novas.
 */
class CondicaoPagamento extends Model
{
    protected $table = 'condicoes_pagamento';

    protected $primaryKey = 'codigo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['codigo', 'descricao', 'tipo', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}
