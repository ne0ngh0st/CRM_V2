<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendedorPerfil extends Model
{
    protected $table = 'vendedor_perfis';

    protected $fillable = [
        'user_id',
        'cod_vendedor',
        'cod_super',
        'cod_gerente',
        'segmento',
        'equipe_rep',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
