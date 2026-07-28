<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EtiquetaMateriaPrima extends Model
{
    protected $table = 'etiquetas_materia_prima';

    protected $fillable = [
        'categoria',
        'fabricante',
        'cod_mp',
        'cod_comercial',
        'desc_mp',
        'larg_mp',
        'preco_m2',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'larg_mp' => 'decimal:2',
            'preco_m2' => 'decimal:4',
            'ativo' => 'boolean',
        ];
    }

    public function scopeAtiva($query)
    {
        return $query->where('ativo', true);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class, 'materia_prima_id');
    }
}
