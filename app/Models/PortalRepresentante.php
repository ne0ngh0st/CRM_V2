<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Espelho de `autopel_sic.clients_representatives` — o `clientRepresentativeId`.
 *
 * ⚠️ É o vínculo entre um USUÁRIO DO PORTAL e um CLIENTE, ou seja, a pessoa da
 * Autopel que responde por aquele cliente. Não é contato do lado do cliente.
 * Guarda os ids do Portal, não os nossos.
 */
class PortalRepresentante extends Model
{
    protected $table = 'portal_representantes';

    protected $fillable = [
        'portal_id', 'portal_cliente_id', 'portal_usuario_id', 'sincronizado_em',
    ];

    protected function casts(): array
    {
        return ['sincronizado_em' => 'datetime'];
    }
}
