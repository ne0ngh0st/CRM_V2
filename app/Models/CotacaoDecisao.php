<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Histórico append-only das conferências. Nunca se edita nem se apaga uma linha daqui:
 * "esquecer" insere ANULADO. A decisão que vale mora em `CotacaoMemoria`.
 */
class CotacaoDecisao extends Model
{
    protected $table = 'cotacao_decisoes';

    public const UPDATED_AT = null;

    protected $fillable = [
        'texto_normalizado', 'descricao_cliente', 'decisao', 'codigo', 'descricao_nossa',
        'user_id', 'cotacao_id', 'origem', 'created_at',
    ];
}
