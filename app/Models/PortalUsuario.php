<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Espelho de `autopel_sic.users`. É o `createdBy` do pedido.
 *
 * Duas chaves possíveis contra o nosso `users`: `email` e `protheus_seller_code`
 * (Protheus = TOTVS, mesmo formato de `vendedor_perfis.cod_vendedor`).
 *
 * ⚠️ `ativo` e `deleted` são coisas diferentes no Portal e dão erros diferentes:
 * inativo responde 409, excluído responde 404.
 */
class PortalUsuario extends Model
{
    protected $table = 'portal_usuarios';

    protected $fillable = [
        'portal_id', 'email', 'protheus_seller_code', 'nome', 'ativo', 'deleted', 'sincronizado_em',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'deleted' => 'boolean', 'sincronizado_em' => 'datetime'];
    }
}
