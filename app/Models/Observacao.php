<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Observacao extends Model
{
    protected $table = 'observacoes';

    protected $fillable = [
        'user_id',
        'cnpj',
        'mensagem',
        'fixada',
    ];

    protected function casts(): array
    {
        return [
            'fixada' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
