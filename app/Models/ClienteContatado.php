<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteContatado extends Model
{
    protected $table = 'clientes_contatados';

    protected $fillable = [
        'cliente_id',
        'user_id',
        'contatado_em',
    ];

    protected function casts(): array
    {
        return [
            'contatado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
