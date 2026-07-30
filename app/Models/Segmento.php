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
    ];

    public function segmentosVendedor(): HasMany
    {
        return $this->hasMany(SegmentoVendedor::class);
    }
}
