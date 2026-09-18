<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma pessoa × uma publicação: quando abriu (`lida_em`) e quando deu ciência
 * (`ciente_em`). Ver a migration `2026_09_18_100000` para o porquê de ser uma tabela só.
 */
class IntranetLeitura extends Model
{
    protected $table = 'intranet_leituras';

    public $timestamps = false;

    protected $fillable = ['publicacao_id', 'user_id', 'lida_em', 'ciente_em'];

    protected function casts(): array
    {
        return [
            'lida_em' => 'datetime',
            'ciente_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
