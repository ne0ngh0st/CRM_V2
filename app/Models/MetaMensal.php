<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaMensal extends Model
{
    protected $table = 'metas_mensais';

    protected $fillable = [
        'cod_vendedor',
        'ano',
        'mes',
        'tipo',
        'valor_meta',
    ];

    protected function casts(): array
    {
        return [
            'ano' => 'integer',
            'mes' => 'integer',
            'valor_meta' => 'decimal:2',
        ];
    }
}
