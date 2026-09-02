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
     * Memória curta, viva só enquanto esta instância existir.
     *
     * ⚠️ POR QUE AQUI E NÃO NO CONTAINER (`scoped()`/`singleton()`):
     * o resolver é injetado no construtor dos controllers, então dentro de uma requisição
     * já existe uma instância só — memoizar no objeto basta, e o cache morre junto com ele.
     * Registrar como `scoped()` faria a instância sobreviver entre as várias requisições
     * de um mesmo teste, e um teste que alterasse `vendedor_perfis` entre dois `$this->get()`
     * passaria a ler escopo velho. Ganho idêntico, risco menor.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    /**
     * @return array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string}
     */
    public function resolve(User $user, ?string $visaoSupervisor, ?string $visaoVendedor): array
    {
        return $this->memo["resolve:{$user->id}:{$visaoSupervisor}:{$visaoVendedor}"]
            ??= $this->resolverEscopo($user, $visaoSupervisor, $visaoVendedor);
    }

    /**
     * @return array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string}
     */
    private function resolverEscopo(User $user, ?string $visaoSupervisor, ?string $visaoVendedor): array
    {
        $role = $this->roleDe($user);
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
        return $this->memo['opcoesSupervisores'] ??= $this->calcularOpcoesSupervisores();
    }

    /**
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    private function calcularOpcoesSupervisores(): Collection
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
        return $this->memo["opcoesVendedores:{$user->id}:{$visaoSupervisor}"]
            ??= $this->calcularOpcoesVendedores($user, $visaoSupervisor);
    }

    /**
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    private function calcularOpcoesVendedores(User $user, ?string $visaoSupervisor): Collection
    {
        $role = $this->roleDe($user);

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
        $codVendedores = $scope['codVendedores'];
        $chave = "usuarioIds:{$user->id}:".($codVendedores === null ? 'todos' : implode(',', $codVendedores));

        return $this->memo[$chave] ??= $this->calcularUsuarioIds($user, $scope);
    }

    /**
     * @param  array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string}  $scope
     * @return array<int>
     */
    private function calcularUsuarioIds(User $user, array $scope): array
    {
        // Vendedor/representante/assistente agrega só o próprio histórico, mesmo que o
        // código de vendedor seja compartilhado com outra conta (acontece no legado).
        // Assistente não tem carteira, mas cria orçamento no próprio user_id.
        if (in_array($this->roleDe($user), ['vendedor', 'representante', 'assistente'], true)) {
            return [$user->id];
        }

        return $this->usuarioIdsDoEscopo($scope['codVendedores']);
    }

    /**
     * Ids de `users` de um escopo, sem precisar de um usuário logado.
     *
     * Existe para o job de cache warming, que enumera escopos em vez de partir de quem
     * está olhando. Fica aqui, e não no job, para que a regra de "quais usuários compõem
     * este escopo" continue existindo num lugar só (Regra de ouro nº 8) — se divergir, o
     * job aquece uma chave que nenhuma requisição vai procurar.
     *
     * @param  array<string>|null  $codVendedores
     * @return array<int>
     */
    public function usuarioIdsDoEscopo(?array $codVendedores): array
    {
        if ($codVendedores === null) {
            return User::role(['vendedor', 'representante'])->pluck('id')->all();
        }

        if ($codVendedores === []) {
            return [];
        }

        return VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codVendedores)
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

        return $this->memo["equipe:{$codSupervisor}"] ??= VendedorPerfil::query()
            ->where('cod_super', $codSupervisor)
            ->pluck('cod_vendedor');
    }

    /**
     * O perfil do usuário é consultado por quase todo método daqui. Sem memoizar, cada
     * chamada de `getRoleNames()` pode ir ao banco de novo — e como os controllers chamam
     * o resolver várias vezes por requisição (o de Orçamentos, oito), isso se multiplica.
     */
    private function roleDe(User $user): ?string
    {
        return $this->memo["role:{$user->id}"] ??= $user->getRoleNames()->first();
    }
}
