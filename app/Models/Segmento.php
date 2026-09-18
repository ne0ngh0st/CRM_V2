<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segmento extends Model
{
    protected $table = 'segmentos';

    protected $fillable = [
        'codigo',
        'nome',
        'peso_potencial',
        'especialista_user_id',
    ];

    protected $casts = [
        'peso_potencial' => 'decimal:2',
    ];

    public function segmentosVendedor(): HasMany
    {
        return $this->hasMany(SegmentoVendedor::class);
    }

    /** O responsável do segmento na diretoria (Visão Diretor → Maiores por Segmento). */
    public function especialista(): BelongsTo
    {
        return $this->belongsTo(User::class, 'especialista_user_id');
    }
}
