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
            return $this->codigosEquipeDe($user->vendedorPerfil?->cod_vendedor);
        }

        return [];
    }

    /**
     * Equipe de um supervisor MAIS o código dele — a regra "gerencial" de escopo.
     *
     * ⚠️ ISTO DIVERGE DO RESTO DO SISTEMA, DE PROPÓSITO, E A DIVERGÊNCIA PRECISA TER NOME.
     * O `DashboardScopeResolver` resolve o supervisor como equipe PURA: na Carteira, no
     * Painel e nos Pedidos, a carteira pessoal dele só aparece no modo "Minha carteira".
     * Já em `/equipe` e em `/metas` — as duas telas de GESTÃO — ele entra junto da equipe,
     * porque a pergunta ali é "por quem esta pessoa responde?", e ela responde por si
     * mesma também: são R$ 9,04 mi de meta gravados em códigos de supervisor que antes
     * nunca eram somados em lugar nenhum.
     *
     * Este método existe para que as duas telas compartilhem UMA regra, em vez de cada
     * uma montar a sua (Regra de ouro nº 8). Se aparecer uma terceira tela gerencial, ela
     * chama isto — não copia.
     *
     * @return array<string>
     */
    public function codigosEquipeDe(?string $codSupervisor): array
    {
        if (! $codSupervisor) {
            return [];
        }

        return VendedorPerfil::query()
            ->where('cod_super', $codSupervisor)
            ->pluck('cod_vendedor')
            ->push($codSupervisor)
            ->unique()
            ->values()
            ->all();
    }
}
