<?php

namespace App\Http\Controllers;

use App\Models\Ligacao;
use App\Models\Observacao;
use App\Models\User;
use App\Services\Dashboard\DashboardScopeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class VisaoGestorController extends Controller
{
    private const DIAS_ALERTA_LIGACAO = 7;

    private const DIAS_ALERTA_OBS = 15;

    /** @var list<string> */
    private const GESTORES = ['admin', 'diretor', 'supervisor'];

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->hasAnyRole(self::GESTORES)) {
            return redirect()->route('dashboard');
        }

        $role = $user->getRoleNames()->first();
        $busca = trim((string) $request->string('busca'));
        $soAtencao = $request->boolean('so_atencao');
        $visaoSupervisor = $request->string('visao_supervisor')->value() ?: null;

        $scope = $this->scopeResolver->resolve($user, $visaoSupervisor, null);
        $usuarios = $this->usuariosDoEscopo($scope['codVendedores']);
        if ($busca !== '') {
            $termo = mb_strtolower($busca);
            $usuarios = $usuarios->filter(function (User $u) use ($termo) {
                $nome = mb_strtolower($u->display_name ?: $u->name);
                $cod = mb_strtolower((string) ($u->vendedorPerfil?->cod_vendedor ?? ''));

                return str_contains($nome, $termo) || str_contains($cod, $termo);
            })->values();
        }

        $ids = $usuarios->pluck('id')->all();
        $inicioMes = now()->startOfMonth()->toDateTimeString();
        $fimMes = now()->endOfMonth()->toDateTimeString();

        $ligMes = $this->agregarLigacoesMes($ids, $inicioMes, $fimMes);
        $obsMes = $this->agregarObservacoesMes($ids, $inicioMes, $fimMes);
        $ultimaLig = $this->ultimaLigacao($ids);
        $ultimaObs = $this->ultimaObservacao($ids);

        $limiteLig = now()->subDays(self::DIAS_ALERTA_LIGACAO);
        $limiteObs = now()->subDays(self::DIAS_ALERTA_OBS);

        $linhas = $usuarios->map(function (User $u) use ($ligMes, $obsMes, $ultimaLig, $ultimaObs, $limiteLig, $limiteObs) {
            $ultimaLigAt = isset($ultimaLig[$u->id]) ? Carbon::parse($ultimaLig[$u->id]) : null;
            $ultimaObsAt = isset($ultimaObs[$u->id]) ? Carbon::parse($ultimaObs[$u->id]) : null;

            $semLig = $ultimaLigAt === null || $ultimaLigAt->lt($limiteLig);
            $semObs = $ultimaObsAt === null || $ultimaObsAt->lt($limiteObs);
            $atencao = $semLig || $semObs;

            return [
                'userId' => $u->id,
                'nome' => $u->display_name ?: $u->name,
                'perfil' => $u->getRoleNames()->first(),
                'codVendedor' => $u->vendedorPerfil?->cod_vendedor,
                'ligacoesMes' => (int) ($ligMes[$u->id] ?? 0),
                'observacoesMes' => (int) ($obsMes[$u->id] ?? 0),
                'ultimaLigacao' => $ultimaLigAt?->toIso8601String(),
                'ultimaObservacao' => $ultimaObsAt?->toIso8601String(),
                'atencao' => $atencao,
                'motivoAtencao' => $atencao
                    ? collect([
                        $semLig ? 'sem ligação ≥'.self::DIAS_ALERTA_LIGACAO.'d' : null,
                        $semObs ? 'sem obs ≥'.self::DIAS_ALERTA_OBS.'d' : null,
                    ])->filter()->implode(' · ')
                    : null,
            ];
        });

        $kpis = [
            'vendedores' => $linhas->count(),
            'ligacoesMes' => (int) $linhas->sum('ligacoesMes'),
            'observacoesMes' => (int) $linhas->sum('observacoesMes'),
            'atencao' => $linhas->filter(fn (array $l) => $l['atencao'])->count(),
        ];

        if ($soAtencao) {
            $linhas = $linhas->filter(fn (array $l) => $l['atencao'])->values();
        }

        $linhas = $linhas
            ->sort(function (array $a, array $b) {
                if ($a['atencao'] !== $b['atencao']) {
                    return $a['atencao'] ? -1 : 1;
                }

                return strcmp($a['nome'], $b['nome']);
            })
            ->values();

        return Inertia::render('VisaoGestor/Index', [
            'role' => $role,
            'linhas' => $linhas->all(),
            'kpis' => $kpis,
            'filtros' => [
                'busca' => $busca,
                'so_atencao' => $soAtencao,
                'visao_supervisor' => $scope['visaoSupervisor'],
            ],
            'opcoes' => [
                'supervisores' => in_array($role, ['admin', 'diretor'], true)
                    ? $this->scopeResolver->opcoesSupervisores()
                    : [],
            ],
            'alertas' => [
                'diasLigacao' => self::DIAS_ALERTA_LIGACAO,
                'diasObservacao' => self::DIAS_ALERTA_OBS,
            ],
        ]);
    }

    /**
     * @param  array<string>|null  $codVendedores
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function usuariosDoEscopo(?array $codVendedores)
    {
        if ($codVendedores !== null && $codVendedores === []) {
            return collect();
        }

        $query = User::role(['vendedor', 'representante'])
            ->where('is_active', true)
            ->whereHas('vendedorPerfil', function ($q) use ($codVendedores) {
                $q->whereNotNull('cod_vendedor')->where('cod_vendedor', '!=', '');
                if ($codVendedores !== null) {
                    $q->whereIn('cod_vendedor', $codVendedores);
                }
            })
            ->with(['vendedorPerfil', 'roles']);

        return $query->get()->sortBy(fn (User $u) => mb_strtolower($u->display_name ?: $u->name))->values();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function agregarLigacoesMes(array $ids, string $inicio, string $fim): array
    {
        if ($ids === []) {
            return [];
        }

        return Ligacao::query()
            ->selectRaw('usuario_id, COUNT(*) as total')
            ->whereIn('usuario_id', $ids)
            ->where('status', '!=', 'excluida')
            ->whereBetween('data_ligacao', [$inicio, $fim])
            ->groupBy('usuario_id')
            ->pluck('total', 'usuario_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function agregarObservacoesMes(array $ids, string $inicio, string $fim): array
    {
        if ($ids === []) {
            return [];
        }

        return Observacao::query()
            ->selectRaw('user_id, COUNT(*) as total')
            ->whereIn('user_id', $ids)
            ->whereBetween('created_at', [$inicio, $fim])
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function ultimaLigacao(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Ligacao::query()
            ->selectRaw('usuario_id, MAX(data_ligacao) as ultima')
            ->whereIn('usuario_id', $ids)
            ->where('status', '!=', 'excluida')
            ->groupBy('usuario_id')
            ->pluck('ultima', 'usuario_id')
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function ultimaObservacao(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Observacao::query()
            ->selectRaw('user_id, MAX(created_at) as ultima')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->pluck('ultima', 'user_id')
            ->all();
    }
}
