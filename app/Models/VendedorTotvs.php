<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lookup código -> nome do vendedor no TOTVS.
 *
 * ⚠️ Não é o vendedor do CRM — esse é `VendedorPerfil`, que só existe para quem tem
 * conta aqui. Este cobre TODO código que o TOTVS usa, e é por isso que ele é o fallback
 * de `App\Services\Vendedores\NomeVendedorResolver`. Ver a migration para o porquê.
 *
 * Populado por `totvs:import-clientes` e `legado:import-clientes`.
 */
class VendedorTotvs extends Model
{
    protected $table = 'vendedores_totvs';

    protected $fillable = [
        'codigo',
        'nome',
        'nome_reduzido',
    ];
}
