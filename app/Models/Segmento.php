<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segmento extends Model
{
    protected $table = 'segmentos';

    protected $fillable = [
        'codigo',
        'nome',
        'peso_potencial',
    ];

    protected $casts = [
        'peso_potencial' => 'decimal:2',
    ];

    public function segmentosVendedor(): HasMany
    {
        return $this->hasMany(SegmentoVendedor::class);
    }
}
