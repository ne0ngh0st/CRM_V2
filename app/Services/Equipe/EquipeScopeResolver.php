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
     * A outra metade da mesma decisão — em que equipe cada LINHA cai — é
     * {@see self::chaveDeEquipe()}. As duas têm que andar juntas.
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

    /**
     * Em que equipe uma pessoa cai numa tela gerencial agrupada (hoje, /metas).
     *
     * Supervisor encabeça a PRÓPRIA equipe; os demais caem na equipe do `cod_super`; sem
     * supervisor, `null` ("Sem supervisor").
     *
     * ⚠️ NÃO é `cod_super` cru. O `cod_super` de um supervisor aponta para o DIRETOR
     * (conferido em 2026-09-15: CLEBER 000006 → 010002). Agrupando por ele, a linha do
     * CLEBER cairia na equipe do diretor, e o subtotal da equipe "CLEBER" que o admin vê
     * teria uma pessoa a menos que o "Totais" que o próprio CLEBER vê logado — porque
     * {@see self::codigosEquipeDe()} o põe dentro da equipe. Dois números para a mesma
     * coisa, com o mesmo rótulo.
     *
     * ⚠️ Decide pelo PERFIL, e não por "tem subordinados na lista": a chave precisa ser a
     * mesma com qualquer busca ou filtro aplicado, senão o supervisor muda de grupo quando
     * a equipe dele é filtrada.
     *
     * ⚠️ Diverge de /equipe, que agrupa por `cod_super` cru — lá a pergunta é "a quem esta
     * pessoa responde?"; aqui é "por qual número ela responde?".
     */
    public static function chaveDeEquipe(?string $perfil, ?string $codVendedor, ?string $codSuper): ?string
    {
        if ($perfil === 'supervisor' && $codVendedor) {
            return $codVendedor;
        }

        return $codSuper ?: null;
    }
}
