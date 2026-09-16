<?php

namespace App\Services\PowerBi;

use RuntimeException;

/**
 * Timeout, conexão derrubada ou 5xx, no Entra ID ou na API do Power BI. É seguro
 * retentar: pedir um refresh duas vezes não duplica nada, só gasta cota — e a trava só
 * conta o disparo que foi aceito.
 */
class PowerBiIndisponivelException extends RuntimeException
{
}
