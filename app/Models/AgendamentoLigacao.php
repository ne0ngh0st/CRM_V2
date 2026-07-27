<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgendamentoLigacao extends Model
{
    protected $table = 'agendamentos_ligacoes';

    protected $fillable = [
        'cliente_id',
        'lead_id',
        'user_id',
        'data_agendamento',
        'observacao',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'data_agendamento' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
