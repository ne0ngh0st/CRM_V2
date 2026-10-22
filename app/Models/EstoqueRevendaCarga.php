<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um Relatório de Revenda carregado em `estoque_revenda`. Ver a migration
 * `create_estoque_revenda_cargas_table`.
 */
class EstoqueRevendaCarga extends Model
{
    protected $table = 'estoque_revenda_cargas';

    public $timestamps = false;

    protected $fillable = ['arquivo', 'data_rv', 'itens', 'importado_em'];

    protected function casts(): array
    {
        return [
            'data_rv' => 'date',
            'itens' => 'integer',
            'importado_em' => 'datetime',
        ];
    }

    /** A carga que está valendo: o RV de data mais nova (e, no mesmo dia, o último). */
    public static function atual(): ?self
    {
        return static::query()->orderByDesc('data_rv')->orderByDesc('id')->first();
    }
}
