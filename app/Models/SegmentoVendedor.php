<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SegmentoVendedor extends Model
{
    protected $table = 'segmentos_vendedor';

    protected $fillable = [
        'cod_vendedor',
        'segmento_id',
        'curva_abc',
    ];

    public function segmento(): BelongsTo
    {
        return $this->belongsTo(Segmento::class);
    }
}
