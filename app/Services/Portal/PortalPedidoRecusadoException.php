<?php

namespace App\Services\Portal;

use RuntimeException;

/**
 * O Portal recusou o pedido (4xx). É TERMINAL: retentar com a mesma chave daria
 * exatamente a mesma resposta, porque a causa é o conteúdo, não a rede.
 *
 * ⚠️ A mensagem é a DELES, propagada literalmente. A documentação pede isso, e faz
 * sentido: "Cliente ainda está como prospect" diz mais a quem opera do que qualquer
 * tradução nossa. Ver docs/integracao-portal-pedidos.md §"Regras de negócio".
 */
class PortalPedidoRecusadoException extends RuntimeException
{
}
