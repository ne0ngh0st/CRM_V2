<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoItem extends Model
{
    protected $table = 'pedido_itens';

    protected $fillable = [
        'pedido_id',
        'cod_produto',
        'descricao',
        'nota_fiscal',
        'quantidade',
        'quantidade_liberada',
        'peso_liquido',
        'valor_unitario',
        'valor_total',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }
}
