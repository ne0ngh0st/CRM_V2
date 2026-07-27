<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    protected $fillable = [
        'numero_pedido',
        'cliente_id',
        'cod_vendedor',
        'data_pedido',
        'data_previsao_faturamento',
        'data_faturamento',
        'data_entrega_prevista',
        'data_pcp',
        'carga',
        'condicao_pagamento',
        'status',
        'valor_total',
    ];

    protected function casts(): array
    {
        return [
            'data_pedido' => 'date',
            'data_previsao_faturamento' => 'date',
            'data_faturamento' => 'date',
            'data_entrega_prevista' => 'date',
            'data_pcp' => 'date',
            'valor_total' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }
}
