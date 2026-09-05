<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Peso de uma família de produto dentro de um segmento. Ver a migration
 * `create_potencial_pesos_table` para o porquê de existir.
 */
class PotencialPeso extends Model
{
    protected $table = 'potencial_pesos';

    protected $fillable = ['segmento_id', 'familia', 'peso'];

    protected $casts = ['peso' => 'decimal:2'];

    public function segmento(): BelongsTo
    {
        return $this->belongsTo(Segmento::class);
    }
}
