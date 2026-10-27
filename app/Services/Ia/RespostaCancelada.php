<?php

namespace App\Services\Ia;

use RuntimeException;

/**
 * O usuário apertou "Parar". Lançada de dentro do streaming para interromper a leitura —
 * o que também fecha a conexão com o modelo e para de gerar (e de cobrar) tokens.
 */
final class RespostaCancelada extends RuntimeException {}
