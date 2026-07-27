<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarteiraMotivoInatividade extends Model
{
    protected $table = 'carteira_motivos_inatividade';

    protected $fillable = [
        'cliente_id',
        'motivo',
        'observacao',
        'criado_por_id',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }
}
