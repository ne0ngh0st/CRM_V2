<?php

namespace App\Services\Equipe;

use App\Models\User;
use App\Models\VendedorPerfil;

/**
 * Regra de acesso da página Equipe, portada 1:1 de comercial_mapa_pode_acessar()
 * do legado (PLANO-DE-ESCAPE/includes/utils/comercial_acesso_compartilhado.php):
 * admin/diretor veem tudo; supervisor só a própria equipe, somente leitura;
 * os demais perfis não acessam a página.
 */
class EquipeScopeResolver
{
    public function podeAcessar(User $user): bool
    {
        $role = $user->getRoleNames()->first();

        if (in_array($role, ['admin', 'diretor'], true)) {
            return true;
        }

        return $role === 'supervisor' && $user->vendedorPerfil?->cod_vendedor !== null;
    }

    public function podeGerenciar(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'diretor']);
    }

    /**
     * Códigos de vendedor visíveis pro usuário logado. `null` = todos (admin/diretor).
     *
     * @return array<string>|null
     */
    public function codigosEquipe(User $user): ?array
    {
        $role = $user->getRoleNames()->first();

        if (in_array($role, ['admin', 'diretor'], true)) {
            return null;
        }

        if ($role === 'supervisor') {
            $proprio = $user->vendedorPerfil?->cod_vendedor;
            if (! $proprio) {
                return [];
            }

            $equipe = VendedorPerfil::query()->where('cod_super', $proprio)->pluck('cod_vendedor');

            return $equipe->push($proprio)->unique()->values()->all();
        }

        return [];
    }
}
