<?php

namespace App\Services\PowerBi;

use RuntimeException;

/**
 * O Power BI (ou o Entra ID) respondeu e disse não: credencial errada, service
 * principal sem acesso ao workspace, dataset inexistente, cota estourada, refresh já em
 * andamento. Retentar dá a mesma resposta — o problema é de configuração e precisa de
 * gente olhando.
 */
class PowerBiRecusadoException extends RuntimeException
{
}
