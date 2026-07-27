<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faturamento extends Model
{
    protected $fillable = [
        'filial',
        'nota_fiscal',
        'pedido',
        'data_emissao',
        'cod_cliente',
        'cnpj',
        'cliente_nome',
        'cod_vendedor',
        'cod_produto',
        'produto_desc',
        'segmento',
        'quantidade',
        'valor_unitario',
        'valor_total',
    ];

    protected function casts(): array
    {
        return [
            'data_emissao' => 'date',
            'quantidade' => 'decimal:2',
            'valor_unitario' => 'decimal:2',
            'valor_total' => 'decimal:2',
        ];
    }
}
