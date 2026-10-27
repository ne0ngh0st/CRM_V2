<?php

namespace App\Services\Ia;

use App\Models\GrupoCliente;

/**
 * "000258" pode ser grupo de vendas (258) e, ao mesmo tempo, o código de OUTRO cliente.
 *
 * O modelo que recebe só o cliente responde com a empresa errada. Este aviso vai no
 * resultado da busca de cliente para ele chamar `consultar_grupo`.
 */
final class AvisoCodigoGrupo
{
    /**
     * @return array{codigo: string, nome: string, aviso: string}|null
     */
    public static function paraTermo(string $termo): ?array
    {
        $digitos = preg_replace('/\D/', '', $termo) ?? '';

        if ($digitos === '' || strlen($digitos) > 6 || ! ctype_digit($digitos)) {
            return null;
        }

        $codigo = (string) (int) $digitos;

        if ($codigo === '0' || $codigo === GrupoCliente::SEM_GRUPO) {
            return null;
        }

        $grupo = GrupoCliente::query()->where('codigo', $codigo)->first();

        if ($grupo === null) {
            return null;
        }

        return [
            'codigo' => $grupo->codigo,
            'nome' => $grupo->nome,
            'aviso' => "Este número é o grupo de vendas {$grupo->codigo} ({$grupo->nome}), não um código de cliente. "
                .'Para caixas ou para o grupo, chame consultar_grupo.',
        ];
    }
}
