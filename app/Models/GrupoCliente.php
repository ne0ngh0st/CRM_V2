<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lookup código -> nome do grupo de cliente do TOTVS (`GrpVendas`).
 * Populado por `legado:import-clientes`, no mesmo passo que lê os clientes.
 */
class GrupoCliente extends Model
{
    /**
     * `9998` = CLIENTES DIVERSOS: o balde de "sem grupo real" do TOTVS (~30% da base).
     * Mostrar esse nome como grupo de uma empresa é ruído — quem exibe grupo trata como vazio.
     */
    public const SEM_GRUPO = '9998';

    protected $table = 'grupos_cliente';

    protected $fillable = [
        'codigo',
        'nome',
    ];
}
