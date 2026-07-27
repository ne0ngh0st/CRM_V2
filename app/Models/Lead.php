<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'origem',
        'user_id',
        'cod_vendedor',
        'nome',
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'email',
        'telefone',
        'endereco',
        'cidade',
        'estado',
        'segmento',
        'valor_estimado',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'valor_estimado' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function observacoes(): HasMany
    {
        return $this->hasMany(Observacao::class);
    }

    public function agendamentos(): HasMany
    {
        return $this->hasMany(AgendamentoLigacao::class);
    }

    public function scopeVisivel($query)
    {
        return $query->where('status', '!=', 'excluido');
    }
}
