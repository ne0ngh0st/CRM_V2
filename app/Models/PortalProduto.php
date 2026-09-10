<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Espelho de `autopel_sic.products`. `code` casa com `produtos.cod_produto`.
 *
 * O fator de conversão vive aqui para que a recusa por múltiplo aconteça ANTES do
 * envio — a API responde 400 e o vendedor não teria como adivinhar o motivo.
 */
class PortalProduto extends Model
{
    protected $table = 'portal_produtos';

    protected $fillable = [
        'portal_id', 'code', 'descricao', 'unidade', 'unidade_secundaria',
        'fator_conversao', 'tipo_conversao', 'grupo', 'deleted', 'sincronizado_em',
    ];

    protected function casts(): array
    {
        return [
            'deleted' => 'boolean',
            'fator_conversao' => 'decimal:4',
            'sincronizado_em' => 'datetime',
        ];
    }
}
