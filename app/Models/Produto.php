<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    protected $fillable = [
        'cod_produto',
        'descricao',
        'categoria',
        'unidade',
        'preco_tabela',
    ];

    protected function casts(): array
    {
        return [
            'preco_tabela' => 'decimal:4',
        ];
    }
}
