<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendedorPerfil extends Model
{
    protected $table = 'vendedor_perfis';

    protected $fillable = [
        'user_id',
        'cod_vendedor',
        'cod_super',
        'cod_gerente',
        'segmento',
        'equipe_rep',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Só os perfis que respondem pelo código — fora o assistente, que usa o código do
     * gestor que apoia (ver `User::PERFIS_ESPELHO`). Toda pergunta "de quem é este
     * código?" passa por aqui.
     */
    public function scopeTitulares(Builder $query): Builder
    {
        return $query->whereDoesntHave('user.roles', fn (Builder $q) => $q
            ->whereIn('roles.name', User::PERFIS_ESPELHO)
            ->where('roles.guard_name', 'web'));
    }
}
