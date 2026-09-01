<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingWpFormulario extends Model
{
    public const IDENTIFICADOR_PADRAO = '*';

    protected $table = 'marketing_wp_formularios';

    protected $fillable = [
        'identificador',
        'nome',
        'cod_vendedor',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    public function capturas(): HasMany
    {
        return $this->hasMany(MarketingWpLeadRaw::class, 'formulario_id');
    }
}
