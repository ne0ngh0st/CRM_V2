<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimulacaoUsuario extends Model
{
    protected $table = 'simulacoes_usuario';

    protected $fillable = [
        'admin_id',
        'alvo_id',
        'ip',
        'iniciada_em',
        'encerrada_em',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
            'encerrada_em' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function alvo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alvo_id');
    }
}
