<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Models\MetaMensal;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MetaController extends Controller
{
    use ExportaPlanilha;

    /** @var list<string> */
    private const GESTORES = ['admin', 'diretor', 'supervisor'];

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly MetaRankingResolver $rankingResolver,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $this->podeAcessar($user)) {
            return redirect()->route('dashboard');
        }

        $role = $user->getRoleNames()->first();
        $p = $this->parametrosRanking($request, $user);
        $resultado = $this->rankingResolver->ranking(
            $p['scope']['codVendedores'],
            $p['ano'],
            $p['mes'],
            $p['modo'],
            $p['busca'],
            $p['faixa'],
        );

        return Inertia::render('Metas/Index', [
            'role' => $role,
            'linhas' => $resultado['linhas'],
            'totais' => $resultado['totais'],
            'kpis' => $resultado['kpis'],
            'periodo' => $resultado['periodo'],
            'filtros' => [
                'ano' => $p['ano'],
                'mes' => $p['mes'],
                'modo' => $p['modo'],
                'busca' => $p['busca'],
                'faixa' => $p['faixa'],
                'visao_supervisor' => $p['scope']['visaoSupervisor'],
            ],
            'opcoes' => [
                'supervisores' => in_array($role, ['admin', 'diretor'], true)
                    ? $this->scopeResolver->opcoesSupervisores()
                    : [],
                'anos' => range((int) now()->year, (int) now()->year - 2),
            ],
            'podeEditar' => $p['modo'] === 'mensal',
        ]);
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        $this->prepararExport('metas');

        $user = $request->user();
        abort_unless($this->podeAcessar($user), 403);

        $p = $this->parametrosRanking($request, $user);
        $resultado = $this->rankingResolver->ranking(
            $p['scope']['codVendedores'],
            $p['ano'],
            $p['mes'],
            $p['modo'],
            $p['busca'],
            $p['faixa'],
        );

        return Excel::download(
            new \App\Exports\MetasExport($resultado['linhas']),
            "metas-{$p['ano']}-{$p['mes']}-".now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** Parsing de ano/mes/modo/busca/faixa + escopo. Usado por index() e exportar(). */
    private function parametrosRanking(Request $request, User $user): array
    {
        $ano = (int) ($request->integer('ano') ?: now()->year);
        $mes = (int) ($request->integer('mes') ?: now()->month);
        $mes = max(1, min(12, $mes));
        $modo = $request->string('modo')->value() === 'acumulado' ? 'acumulado' : 'mensal';
        $busca = trim((string) $request->string('busca'));
        $faixa = (string) $request->string('faixa');
        if (! in_array($faixa, ['atingiu', 'quase', 'abaixo', 'sem_meta'], true)) {
            $faixa = '';
        }

        $visaoSupervisor = $request->string('visao_supervisor')->value() ?: null;
        // Ranking lista a equipe inteira (ou empresa); não filtra a um vendedor só.
        $scope = $this->scopeResolver->resolve($user, $visaoSupervisor, null);

        return compact('ano', 'mes', 'modo', 'busca', 'faixa', 'scope');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->podeAcessar($user), 403);

        $data = $request->validate([
            'cod_vendedor' => ['required', 'string', 'max:20'],
            'ano' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'meta_faturamento' => ['required', 'numeric', 'min:0'],
            'meta_venda' => ['required', 'numeric', 'min:0'],
        ]);

        abort_unless($this->podeEditarCodigo($user, $data['cod_vendedor']), 403);

        foreach (['faturamento' => $data['meta_faturamento'], 'venda' => $data['meta_venda']] as $tipo => $valor) {
            MetaMensal::query()->updateOrCreate(
                [
                    'cod_vendedor' => $data['cod_vendedor'],
                    'ano' => $data['ano'],
                    'mes' => $data['mes'],
                    'tipo' => $tipo,
                ],
                ['valor_meta' => $valor],
            );
        }

        return back()->with('success', 'Metas atualizadas.');
    }

    private function podeAcessar(User $user): bool
    {
        return $user->hasAnyRole(self::GESTORES);
    }

    private function podeEditarCodigo(User $user, string $codVendedor): bool
    {
        $role = $user->getRoleNames()->first();

        if (in_array($role, ['admin', 'diretor'], true)) {
            return VendedorPerfil::query()->where('cod_vendedor', $codVendedor)->exists();
        }

        if ($role === 'supervisor') {
            $meuCodigo = $user->vendedorPerfil?->cod_vendedor;
            if (! $meuCodigo) {
                return false;
            }

            return VendedorPerfil::query()
                ->where('cod_vendedor', $codVendedor)
                ->where('cod_super', $meuCodigo)
                ->exists();
        }

        return false;
    }
}
