<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sugestao extends Model
{
    protected $table = 'sugestoes';

    protected $fillable = [
        'user_id',
        'categoria',
        'mensagem',
        'status',
        'resposta_admin',
        'admin_respondeu_id',
        'visivel',
    ];

    protected function casts(): array
    {
        return [
            'visivel' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminRespondeu(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_respondeu_id');
    }
}
