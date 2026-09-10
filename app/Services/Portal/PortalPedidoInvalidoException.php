<?php

namespace App\Services\Portal;

use RuntimeException;

/**
 * O orçamento não pode virar pedido, e o motivo é NOSSO — não vale gastar uma
 * chamada à API para descobrir o que dá para saber antes.
 *
 * ⚠️ A mensagem é mostrada ao vendedor. Escrever aqui o que ELE pode fazer, não o
 * nome do campo que falhou.
 */
class PortalPedidoInvalidoException extends RuntimeException
{
}
