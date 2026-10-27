<?php

namespace App\Services\Ia;

use Carbon\CarbonInterface;

/**
 * Datas e valores já no formato brasileiro antes de chegar ao modelo.
 *
 * Mandar `2026-10-09` e `12345.6` e pedir que o modelo formate funciona quase sempre —
 * e o "quase" é um vendedor lendo R$ 12.345,6 ou 10/09 trocado por 09/10. Formatar aqui
 * tira essa margem.
 */
final class Formato
{
    public static function data(?CarbonInterface $data): ?string
    {
        return $data?->format('d/m/Y');
    }

    public static function reais(float|string|null $valor): ?string
    {
        return $valor === null ? null : 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    /** Descreve o escopo resolvido em palavras, para o modelo dizer de quem é o número. */
    public static function escopo(?array $codVendedores, ?string $perfil): string
    {
        if ($codVendedores === null) {
            return 'empresa inteira';
        }
        if ($codVendedores === []) {
            return 'nenhuma carteira (usuário sem código de vendedor)';
        }
        if (count($codVendedores) === 1) {
            return 'carteira do código '.$codVendedores[0];
        }

        return ($perfil === 'supervisor' ? 'equipe do supervisor' : 'conjunto de vendedores')
            .' ('.count($codVendedores).' códigos)';
    }
}
