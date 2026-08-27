<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faca extends Model
{
    /** Catálogos do legado, agora só um valor de coluna. Ordem = ordem de exibição no filtro. */
    public const TIPOS = [
        'balanca' => 'Balança',
        'retangulares' => 'Simples retangulares',
        'etiquetas-a4' => 'Etiquetas A4',
        'tags' => 'Tags',
        'rotulos' => 'Rótulos',
        'lacres' => 'Lacres',
        'bagtag' => 'Bag Tag',
        'especiais' => 'Especiais',
    ];

    protected $fillable = [
        'tipo',
        'item',
        'largura',
        'altura',
        'observacao',
    ];

    public function recursos(): HasMany
    {
        return $this->hasMany(FacaRecurso::class)->orderBy('ordem');
    }

    public function getTipoNomeAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** "40 x 25" — as duas medidas já vêm em mm do catálogo. */
    public function getMedidaAttribute(): string
    {
        return trim(($this->largura ?? '-').' x '.($this->altura ?? '-'));
    }
}
