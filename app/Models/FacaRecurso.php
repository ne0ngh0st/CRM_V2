<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacaRecurso extends Model
{
    protected $table = 'faca_recursos';

    protected $fillable = [
        'faca_id',
        'ordem',
        'descricao',
        'imagem',
    ];

    public function faca(): BelongsTo
    {
        return $this->belongsTo(Faca::class);
    }
}
