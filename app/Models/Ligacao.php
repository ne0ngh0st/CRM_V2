<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ligacao extends Model
{
    protected $table = 'ligacoes';

    protected $fillable = [
        'usuario_id',
        'cliente_id',
        'lead_id',
        'cliente_nome',
        'tipo_contato',
        'status',
        'data_ligacao',
    ];

    protected function casts(): array
    {
        return [
            'data_ligacao' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
