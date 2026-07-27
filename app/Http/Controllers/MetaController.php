<?php

namespace App\Http\Controllers;

use App\Models\MetaMensal;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetaController extends Controller
{
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

        $resultado = $this->rankingResolver->ranking(
            $scope['codVendedores'],
            $ano,
            $mes,
            $modo,
            $busca,
            $faixa,
        );

        return Inertia::render('Metas/Index', [
            'role' => $role,
            'linhas' => $resultado['linhas'],
            'totais' => $resultado['totais'],
            'kpis' => $resultado['kpis'],
            'periodo' => $resultado['periodo'],
            'filtros' => [
                'ano' => $ano,
                'mes' => $mes,
                'modo' => $modo,
                'busca' => $busca,
                'faixa' => $faixa,
                'visao_supervisor' => $scope['visaoSupervisor'],
            ],
            'opcoes' => [
                'supervisores' => in_array($role, ['admin', 'diretor'], true)
                    ? $this->scopeResolver->opcoesSupervisores()
                    : [],
                'anos' => range((int) now()->year, (int) now()->year - 2),
            ],
            'podeEditar' => $modo === 'mensal',
        ]);
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
