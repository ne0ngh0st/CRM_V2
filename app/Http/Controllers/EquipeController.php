<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Equipe\EquipeScopeResolver;
use App\Services\Equipe\OrganogramaBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EquipeController extends Controller
{
    use ExportaPlanilha;

    /** Janela de "online agora", mesma da usuario_presenca_minutos_online() do legado. */
    private const MINUTOS_ONLINE = 5;

    public function __construct(
        private readonly EquipeScopeResolver $scope,
        private readonly OrganogramaBuilder $organogramaBuilder,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $this->scope->podeAcessar($user)) {
            return redirect()->route('dashboard');
        }

        $podeGerenciar = $this->scope->podeGerenciar($user);

        $estadosDisponiveis = $this->queryFiltrada($request, $user, apenasEscopo: true)
            ->whereNotNull('estado')->where('estado', '!=', '')
            ->distinct()->orderBy('estado')->pluck('estado');

        $supervisoresDisponiveis = User::role('supervisor')
            ->whereHas('vendedorPerfil')
            ->with('vendedorPerfil')
            ->get()
            ->map(fn (User $s) => ['codVendedor' => $s->vendedorPerfil->cod_vendedor, 'nome' => $s->display_name ?: $s->name])
            ->sortBy('nome')
            ->values();

        $usuarios = $this->queryFiltrada($request, $user)->get();

        $codVendedoresPresentes = $usuarios->pluck('vendedorPerfil.cod_vendedor')->filter()->values();
        $segmentosPorCodVendedor = SegmentoVendedor::query()
            ->whereIn('cod_vendedor', $codVendedoresPresentes)
            ->with('segmento')
            ->get()
            ->groupBy('cod_vendedor')
            ->map(fn ($grupo) => $grupo->pluck('segmento'));

        $nomesPorCodVendedor = VendedorPerfil::query()
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);

        $usuariosMapeados = $usuarios->map(function (User $u) use ($segmentosPorCodVendedor) {
            $codVendedor = $u->vendedorPerfil?->cod_vendedor;

            return [
                'id' => $u->id,
                'nome' => $u->display_name ?: $u->name,
                'nomeCompleto' => $u->name,
                'nomeExibicao' => $u->display_name,
                'email' => $u->email,
                'perfil' => $u->getRoleNames()->first(),
                'ativo' => $u->is_active,
                'estado' => $u->estado,
                'tipoUsuario' => $u->tipo_usuario,
                'codVendedor' => $codVendedor,
                'codSuper' => $u->vendedorPerfil?->cod_super,
                'ultimoLogin' => optional($u->last_login_at)->toIso8601String(),
                'online' => $u->last_activity_at?->gt(now()->subMinutes(self::MINUTOS_ONLINE)) ?? false,
                'segmentos' => $codVendedor ? ($segmentosPorCodVendedor[$codVendedor] ?? collect())->pluck('nome')->all() : [],
                'segmentosIds' => $codVendedor ? ($segmentosPorCodVendedor[$codVendedor] ?? collect())->pluck('id')->all() : [],
            ];
        });

        $usuariosPorSupervisor = $usuariosMapeados
            ->groupBy(fn (array $u) => $u['codSuper'] ?: '_sem_supervisor')
            ->map(fn ($grupo, $chave) => [
                'supervisorCod' => $chave === '_sem_supervisor' ? null : $chave,
                'supervisorNome' => $chave === '_sem_supervisor' ? 'Sem Supervisor' : ($nomesPorCodVendedor[$chave] ?? 'Supervisor desconhecido'),
                'usuarios' => $grupo->sortBy('nome')->values()->all(),
            ])
            ->sort(function (array $a, array $b) {
                $aSemSuper = $a['supervisorCod'] === null;
                $bSemSuper = $b['supervisorCod'] === null;
                if ($aSemSuper !== $bSemSuper) {
                    return $aSemSuper ? 1 : -1;
                }

                return strcmp($a['supervisorNome'], $b['supervisorNome']);
            })
            ->values()
            ->all();

        $organograma = null;
        if ($podeGerenciar) {
            $nosOrganograma = VendedorPerfil::query()
                // ⚠️ 'user.roles' é obrigatório aqui: o map abaixo chama getRoleNames()
                // por nó, e sem as roles carregadas o Spatie consulta o banco uma vez
                // por usuário — eram 201 queries extras nesta página (218 no total).
                ->with(['user:id,name,display_name', 'user.roles'])
                ->get()
                ->filter(fn (VendedorPerfil $vp) => $vp->user !== null)
                ->map(fn (VendedorPerfil $vp) => [
                    'id' => $vp->user->id,
                    'codVendedor' => $vp->cod_vendedor,
                    'codSuper' => $vp->cod_super,
                    'nome' => $vp->user->display_name ?: $vp->user->name,
                    'perfil' => $vp->user->getRoleNames()->first(),
                ]);

            $organograma = $this->organogramaBuilder->construir($nosOrganograma);
        }

        return Inertia::render('Equipe/Index', [
            'role' => $user->getRoleNames()->first(),
            'podeGerenciar' => $podeGerenciar,
            'totalUsuarios' => $usuariosMapeados->count(),
            'totalOnline' => $usuariosMapeados->where('online', true)->count(),
            'usuarios' => $usuariosPorSupervisor,
            'filtros' => $request->only(['busca', 'perfil', 'supervisor', 'estado', 'tipo', 'status', 'online', 'login']),
            'opcoes' => [
                'perfis' => Role::pluck('name'),
                'supervisores' => $supervisoresDisponiveis,
                'estados' => $estadosDisponiveis,
                'segmentos' => Segmento::orderBy('nome')->get(['id', 'codigo', 'nome']),
            ],
            'organograma' => $organograma,
        ]);
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        $this->prepararExport('equipe');

        $user = $request->user();
        abort_unless($this->scope->podeAcessar($user), 403);

        $query = $this->queryFiltrada($request, $user);

        return Excel::download(
            new \App\Exports\EquipeExport($query),
            'equipe-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /**
     * Escopo (codigosEquipe) + filtros de busca/perfil/supervisor/estado/tipo/status/online/login.
     * Usado por index() (lista e opções de filtro) e exportar().
     */
    protected function queryFiltrada(Request $request, User $user, bool $apenasEscopo = false): Builder
    {
        $codigosEquipe = $this->scope->codigosEquipe($user);

        $query = User::query()->with(['vendedorPerfil', 'roles']);

        if ($codigosEquipe !== null) {
            if ($codigosEquipe === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('vendedorPerfil', fn ($q) => $q->whereIn('cod_vendedor', $codigosEquipe));
            }
        }

        if ($apenasEscopo) {
            return $query;
        }

        $podeGerenciar = $this->scope->podeGerenciar($user);

        $busca = trim((string) $request->string('busca'));
        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('name', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', "%{$busca}%")
                    ->orWhereHas('vendedorPerfil', fn ($q2) => $q2->where('cod_vendedor', 'like', "%{$busca}%"));
            });
        }

        if ($perfil = (string) $request->string('perfil')) {
            $query->role($perfil);
        }

        if ($podeGerenciar && ($supervisorFiltro = (string) $request->string('supervisor'))) {
            $query->whereHas('vendedorPerfil', fn ($q) => $q->where('cod_super', $supervisorFiltro));
        }

        if ($estado = (string) $request->string('estado')) {
            $query->where('estado', $estado);
        }

        if ($tipo = (string) $request->string('tipo')) {
            $query->where('tipo_usuario', $tipo);
        }

        if ($status = (string) $request->string('status')) {
            $query->where('is_active', $status === 'ativo');
        }

        if ((string) $request->string('online') === 'sim') {
            $query->where('last_activity_at', '>=', now()->subMinutes(self::MINUTOS_ONLINE));
        }

        match ((string) $request->string('login')) {
            'hoje' => $query->whereDate('last_login_at', today()),
            'semana' => $query->where('last_login_at', '>=', now()->subDays(7)),
            'mes' => $query->where('last_login_at', '>=', now()->subDays(30)),
            'nunca' => $query->whereNull('last_login_at'),
            default => null,
        };

        return $query;
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'perfil' => ['required', Rule::in(Role::pluck('name'))],
            'cod_vendedor' => ['nullable', 'string', 'max:20'],
            'cod_super' => ['nullable', 'string', 'max:20'],
            'estado' => ['nullable', 'string', 'max:2'],
            'tipo_usuario' => ['nullable', Rule::in(['INTERNO', 'EXTERNO'])],
        ]);

        $usuario = User::create([
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? $data['name'],
            'username' => $this->usernameUnico($data['email']),
            'email' => $data['email'],
            'password' => $data['password'],
            'tipo_usuario' => $data['tipo_usuario'] ?? 'INTERNO',
            'estado' => $data['estado'] ?? null,
        ]);

        $usuario->assignRole($data['perfil']);

        if (! empty($data['cod_vendedor'])) {
            VendedorPerfil::create([
                'user_id' => $usuario->id,
                'cod_vendedor' => $data['cod_vendedor'],
                'cod_super' => $data['cod_super'] ?: null,
            ]);
        }

        return back();
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario->id)],
            'perfil' => ['required', Rule::in(Role::pluck('name'))],
            'cod_vendedor' => ['nullable', 'string', 'max:20'],
            'cod_super' => ['nullable', 'string', 'max:20'],
            'estado' => ['nullable', 'string', 'max:2'],
            'tipo_usuario' => ['nullable', Rule::in(['INTERNO', 'EXTERNO'])],
            'segmentos' => ['nullable', 'array'],
            'segmentos.*' => ['integer', Rule::exists('segmentos', 'id')],
        ]);

        $usuario->update([
            'name' => $data['name'],
            'display_name' => $data['display_name'] ?? $data['name'],
            'email' => $data['email'],
            'estado' => $data['estado'] ?? null,
            'tipo_usuario' => $data['tipo_usuario'] ?? $usuario->tipo_usuario,
        ]);

        $usuario->syncRoles([$data['perfil']]);

        $codVendedorAnterior = $usuario->vendedorPerfil?->cod_vendedor;

        if (! empty($data['cod_vendedor'])) {
            VendedorPerfil::updateOrCreate(
                ['user_id' => $usuario->id],
                ['cod_vendedor' => $data['cod_vendedor'], 'cod_super' => $data['cod_super'] ?: null],
            );

            if ($codVendedorAnterior && $codVendedorAnterior !== $data['cod_vendedor']) {
                SegmentoVendedor::where('cod_vendedor', $codVendedorAnterior)->delete();
            }

            SegmentoVendedor::where('cod_vendedor', $data['cod_vendedor'])->delete();
            foreach (array_unique($data['segmentos'] ?? []) as $segmentoId) {
                SegmentoVendedor::create(['cod_vendedor' => $data['cod_vendedor'], 'segmento_id' => $segmentoId]);
            }
        } else {
            $usuario->vendedorPerfil?->delete();
            if ($codVendedorAnterior) {
                SegmentoVendedor::where('cod_vendedor', $codVendedorAnterior)->delete();
            }
        }

        return back();
    }

    public function atualizarSenha(Request $request, User $usuario): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $data = $request->validate([
            'senha' => ['required', 'string', 'min:6'],
        ]);

        $usuario->update(['password' => $data['senha']]);

        return back();
    }

    public function toggleStatus(Request $request, User $usuario): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $usuario->update(['is_active' => ! $usuario->is_active]);

        return back();
    }

    public function reatribuirSupervisorMassa(Request $request): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'novo_cod_super' => ['required', 'string', 'max:20'],
        ]);

        $novoCodSuper = $data['novo_cod_super'] === '__REMOVER__' ? null : $data['novo_cod_super'];

        VendedorPerfil::query()
            ->whereIn('user_id', $data['user_ids'])
            ->update(['cod_super' => $novoCodSuper]);

        return back();
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        abort_unless($this->scope->podeGerenciar($request->user()), 403);

        if ($usuario->id === $request->user()->id) {
            return back()->withErrors(['usuario' => 'Você não pode excluir seu próprio usuário.']);
        }

        if ($usuario->hasRole('admin') && User::role('admin')->where('is_active', true)->count() <= 1) {
            return back()->withErrors(['usuario' => 'Não é possível excluir o último administrador ativo.']);
        }

        $usuario->delete();

        return back();
    }

    private function usernameUnico(string $email): string
    {
        $base = strtolower(explode('@', $email)[0]);
        $username = $base;
        $contador = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.$contador;
            $contador++;
        }

        return $username;
    }
}
