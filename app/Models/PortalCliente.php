<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Espelho de `autopel_sic.clients`. `portal_id` é o `clientId` que o
 * `POST /v1/api/orders` exige; `code` + `store` é o que casa com a nossa `clientes`.
 *
 * ⚠️ `document` NÃO é decoração — é a guarda contra criar pedido para a empresa
 * errada. Ver PortalDeParaResolver.
 */
class PortalCliente extends Model
{
    protected $table = 'portal_clientes';

    protected $fillable = [
        'portal_id', 'code', 'store', 'document', 'razao_social', 'deleted', 'sincronizado_em',
    ];

    protected function casts(): array
    {
        return ['deleted' => 'boolean', 'sincronizado_em' => 'datetime'];
    }

    /** Só dígitos, para comparar com a nossa `clientes.cnpj`, que é mascarada. */
    public static function apenasDigitos(?string $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor) ?? '';
    }
}
