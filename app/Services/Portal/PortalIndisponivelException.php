<?php

namespace App\Services\Portal;

use RuntimeException;

/**
 * Timeout, conexão derrubada ou 5xx. É SEGURO retentar — desde que com a MESMA
 * `Idempotency-Key`.
 *
 * 🚨 Retentar com chave NOVA é o único caminho que ainda duplica pedido no Portal.
 * A proteção deles depende de nós repetirmos o mesmo valor, e é por isso que a chave
 * mora na coluna `orcamentos.portal_idempotency_key` em vez de ser gerada no envio.
 */
class PortalIndisponivelException extends RuntimeException
{
}
