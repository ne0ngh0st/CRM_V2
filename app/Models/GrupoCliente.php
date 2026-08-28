<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lookup código -> nome do grupo de cliente do TOTVS (`GrpVendas`).
 * Populado por `legado:import-clientes`, no mesmo passo que lê os clientes.
 */
class GrupoCliente extends Model
{
    protected $table = 'grupos_cliente';

    protected $fillable = [
        'codigo',
        'nome',
    ];
}
