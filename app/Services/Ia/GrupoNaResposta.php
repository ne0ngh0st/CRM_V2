<?php

namespace App\Services\Ia;

use App\Models\GrupoCliente;

/**
 * Código e nome do grupo de vendas para o modelo dizer "este grupo" sem chutar.
 */
final class GrupoNaResposta
{
    /**
     * @param  iterable<int, string|null>  $codigos
     * @return array<string, string>
     */
    public static function nomes(iterable $codigos): array
    {
        $codigos = collect($codigos)->filter()->unique()->values();

        if ($codigos->isEmpty()) {
            return [];
        }

        return GrupoCliente::query()->whereIn('codigo', $codigos)->pluck('nome', 'codigo')->all();
    }

    /**
     * @param  array<string, string>  $nomes
     * @return array{codigo: string, nome: string, sem_grupo_real: bool}|null
     */
    public static function de(?string $codGrupo, array $nomes): ?array
    {
        if ($codGrupo === null || $codGrupo === '') {
            return null;
        }

        return [
            'codigo' => $codGrupo,
            'nome' => $nomes[$codGrupo] ?? $codGrupo,
            'sem_grupo_real' => $codGrupo === GrupoCliente::SEM_GRUPO,
        ];
    }
}
