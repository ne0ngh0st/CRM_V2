<?php

namespace App\Services\Cotacao;

use RuntimeException;

/**
 * Erro que é para o USUÁRIO ler (planilha sem coluna de descrição, estoque não
 * carregado, código fora do estoque). Diferente de defeito, não vai para o log.
 */
class CotacaoRecusada extends RuntimeException {}
