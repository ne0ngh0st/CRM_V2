<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendedorPerfil extends Model
{
    protected $fillable = [
        'user_id',
        'cod_vendedor',
        'cod_super',
        'cod_gerente',
        'meta_venda',
        'meta_faturamento',
        'segmento',
        'equipe_rep',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
