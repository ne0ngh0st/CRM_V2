<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Models\VendedorPerfil;
use Illuminate\Support\Collection;

/**
 * Único lugar que decide "quais cod_vendedor entram na agregação" pro usuário logado.
 * No legado essa lógica estava duplicada (e levemente inconsistente) em 4 arquivos
 * PHP diferentes — aqui é resolvida uma vez e reaproveitada por todos os blocos da Home.
 */
class DashboardScopeResolver
{
    /**
     * @return array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string}
     */
    public function resolve(User $user, ?string $visaoSupervisor, ?string $visaoVendedor): array
    {
        $role = $user->getRoleNames()->first();
        $proprio = $user->vendedorPerfil?->cod_vendedor;

        if (in_array($role, ['vendedor', 'representante'], true)) {
            return ['codVendedores' => $proprio ? [$proprio] : [], 'visaoSupervisor' => null, 'visaoVendedor' => null];
        }

        if ($role === 'supervisor') {
            $equipe = $this->equipeDoSupervisor($proprio);

            if ($visaoVendedor && $equipe->contains($visaoVendedor)) {
                return ['codVendedores' => [$visaoVendedor], 'visaoSupervisor' => null, 'visaoVendedor' => $visaoVendedor];
            }

            return ['codVendedores' => $equipe->all(), 'visaoSupervisor' => null, 'visaoVendedor' => null];
        }

        if (in_array($role, ['admin', 'diretor'], true)) {
            if ($visaoSupervisor) {
                $equipe = $this->equipeDoSupervisor($visaoSupervisor);

                if ($visaoVendedor && $equipe->contains($visaoVendedor)) {
                    return ['codVendedores' => [$visaoVendedor], 'visaoSupervisor' => $visaoSupervisor, 'visaoVendedor' => $visaoVendedor];
                }

                return ['codVendedores' => $equipe->all(), 'visaoSupervisor' => $visaoSupervisor, 'visaoVendedor' => null];
            }

            if ($visaoVendedor) {
                return ['codVendedores' => [$visaoVendedor], 'visaoSupervisor' => null, 'visaoVendedor' => $visaoVendedor];
            }

            // Sem seleção: agrega a empresa toda.
            return ['codVendedores' => null, 'visaoSupervisor' => null, 'visaoVendedor' => null];
        }

        // assistente e qualquer outro perfil sem escopo de vendas.
        return ['codVendedores' => [], 'visaoSupervisor' => null, 'visaoVendedor' => null];
    }

    /**
     * Supervisores que de fato têm equipe (pro dropdown de admin/diretor).
     *
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    public function opcoesSupervisores(): Collection
    {
        return User::role('supervisor')
            ->whereHas('vendedorPerfil', fn ($q) => $q->whereIn('cod_vendedor', VendedorPerfil::query()->whereNotNull('cod_super')->pluck('cod_super')))
            ->with('vendedorPerfil')
            ->get()
            ->map(fn (User $u) => ['cod_vendedor' => $u->vendedorPerfil->cod_vendedor, 'nome' => $u->display_name ?: $u->name])
            ->sortBy('nome')
            ->values();
    }

    /**
     * Vendedores/representantes disponíveis pro dropdown, dado quem está logado e
     * (se aplicável) o supervisor já selecionado no outro dropdown.
     *
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    public function opcoesVendedores(User $user, ?string $visaoSupervisor): Collection
    {
        $role = $user->getRoleNames()->first();

        $codSuper = match (true) {
            $role === 'supervisor' => $user->vendedorPerfil?->cod_vendedor,
            in_array($role, ['admin', 'diretor'], true) && $visaoSupervisor => $visaoSupervisor,
            default => null,
        };

        $query = User::role(['vendedor', 'representante'])->with('vendedorPerfil');

        if ($codSuper) {
            $query->whereHas('vendedorPerfil', fn ($q) => $q->where('cod_super', $codSuper));
        }

        return $query->get()
            ->filter(fn (User $u) => $u->vendedorPerfil !== null)
            ->map(fn (User $u) => ['cod_vendedor' => $u->vendedorPerfil->cod_vendedor, 'nome' => $u->display_name ?: $u->name])
            ->sortBy('nome')
            ->values();
    }

    /**
     * IDs de `users` dentro do escopo resolvido — pra agregar ligações/observações
     * (que são por `usuario_id`, não por `cod_vendedor`, já que o código pode ser
     * compartilhado entre contas).
     *
     * @param array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string} $scope
     * @return array<int>
     */
    public function usuarioIds(User $user, array $scope): array
    {
        $role = $user->getRoleNames()->first();

        if (in_array($role, ['vendedor', 'representante'], true)) {
            return [$user->id];
        }

        if ($scope['codVendedores'] === null) {
            return User::role(['vendedor', 'representante'])->pluck('id')->all();
        }

        if ($scope['codVendedores'] === []) {
            return [];
        }

        return VendedorPerfil::query()
            ->whereIn('cod_vendedor', $scope['codVendedores'])
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    private function equipeDoSupervisor(?string $codSupervisor): Collection
    {
        if (! $codSupervisor) {
            return collect();
        }

        return VendedorPerfil::query()
            ->where('cod_super', $codSupervisor)
            ->pluck('cod_vendedor');
    }
}
