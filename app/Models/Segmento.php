<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Segmento extends Model
{
    /**
     * Representante atende SÓ este segmento, sem exceção (Tony, 2026-07-29).
     *
     * A regra mora na escrita ({@see \App\Services\Equipe\SegmentoVendedorSync}),
     * não no seeder: seeder só alcança banco novo. O código '101' é o do TOTVS
     * (`ultimo_faturamento.Segmento1`), não um id interno — o id muda entre
     * ambientes, o código não.
     */
    public const CODIGO_SUPERMERCADISTA = '101';

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
