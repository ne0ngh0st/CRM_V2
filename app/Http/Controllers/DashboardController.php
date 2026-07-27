<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DataSyncStatus;
use App\Models\Faturamento;
use App\Models\Ligacao;
use App\Models\Observacao;
use App\Models\Orcamento;
use App\Models\Pedido;
use App\Services\Carteira\CarteiraAderenciaResolver;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CarteiraAderenciaResolver $aderenciaResolver,
        private readonly MetaRankingResolver $metaRanking,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $visaoSupervisor = $request->string('visao_supervisor')->value() ?: null;
        $visaoVendedor = $request->string('visao_vendedor')->value() ?: null;

        $scope = $this->scopeResolver->resolve($user, $visaoSupervisor, $visaoVendedor);
        $codVendedores = $scope['codVendedores'];
        $temEscopo = $codVendedores === null || count($codVendedores) > 0;
        $usuarioIds = $this->scopeResolver->usuarioIds($user, $scope);

        return Inertia::render('Dashboard', [
            'role' => $role,
            'statusSistema' => $this->statusSistema(),
            'visao' => [
                'mostrarSeletor' => in_array($role, ['supervisor', 'admin', 'diretor'], true),
                'supervisores' => in_array($role, ['admin', 'diretor'], true) ? $this->scopeResolver->opcoesSupervisores() : [],
                'vendedores' => in_array($role, ['supervisor', 'admin', 'diretor'], true)
                    ? $this->scopeResolver->opcoesVendedores($user, $scope['visaoSupervisor'])
                    : [],
                'visaoSupervisor' => $scope['visaoSupervisor'],
                'visaoVendedor' => $scope['visaoVendedor'],
            ],
            'metaGauge' => $temEscopo && $role !== 'assistente' ? $this->metaGauge($codVendedores, $role) : null,
            'ligacoesStats' => $role !== 'assistente' ? $this->ligacoesStats($usuarioIds) : null,
            'observacoesStats' => $role !== 'assistente' ? $this->observacoesStats($usuarioIds) : null,
            'faturamentoComparacao' => $temEscopo && $role !== 'assistente' ? $this->faturamentoComparacao($codVendedores) : null,
            'carteiraSegmento' => $temEscopo && $role !== 'assistente' ? $this->carteiraSegmento($codVendedores) : null,
            'orcamentosStats' => $role !== 'assistente' ? $this->orcamentosStats($usuarioIds) : null,
            'pedidosAtencao' => $temEscopo && $role !== 'assistente' ? $this->pedidosAtencao($codVendedores) : null,
        ]);
    }

    private function statusSistema(): array
    {
        return DataSyncStatus::query()->get()->map(function (DataSyncStatus $s) {
            $horas = $s->last_synced_at->diffInHours(now());
            $status = match (true) {
                $horas < 24 => 'atualizado',
                $horas < 48 => 'atencao',
                default => 'desatualizado',
            };

            return [
                'tabela' => $s->tabela,
                'status' => $status,
                'ultimaSincronizacao' => $s->last_synced_at->toIso8601String(),
            ];
        })->values()->all();
    }

    private function metaGauge(?array $codVendedores, string $role): array
    {
        $ano = (int) now()->year;
        $mes = (int) now()->month;

        return [
            'isRepresentante' => $role === 'representante',
            'mes' => $this->metaRanking->metaVsFaturamento($codVendedores, $ano, $mes, $mes),
            'ano' => $this->metaRanking->metaVsFaturamento($codVendedores, $ano, 1, $mes),
            'pedidosEmitidos' => $this->pedidosEmitidos($codVendedores, $ano, $mes),
        ];
    }

    /** Volume de pedidos emitidos (toda a tabela, aberto ou faturado) no mês e no ano corrente. */
    private function pedidosEmitidos(?array $codVendedores, int $ano, int $mes): array
    {
        [$inicioMes, $fimMes] = $this->metaRanking->intervaloDatas($ano, $mes, $mes);
        [$inicioAno, $fimAno] = $this->metaRanking->intervaloDatas($ano, 1, $mes);

        $base = Pedido::query();
        if ($codVendedores !== null) {
            $base->whereIn('cod_vendedor', $codVendedores);
        }

        $doMes = (clone $base)->whereBetween('data_pedido', [$inicioMes, $fimMes]);
        $doAno = (clone $base)->whereBetween('data_pedido', [$inicioAno, $fimAno]);

        return [
            'mes' => ['pedidos' => (clone $doMes)->count(), 'valor' => (float) (clone $doMes)->sum('valor_total')],
            'ano' => ['pedidos' => (clone $doAno)->count(), 'valor' => (float) (clone $doAno)->sum('valor_total')],
        ];
    }

    /** @param array<int> $usuarioIds */
    private function ligacoesStats(array $usuarioIds): array
    {
        $ligacoes = Ligacao::query()
            ->whereIn('usuario_id', $usuarioIds)
            ->whereYear('data_ligacao', now()->year)
            ->whereMonth('data_ligacao', now()->month)
            ->where('status', '!=', 'excluida')
            ->get();

        $total = $ligacoes->count();
        $finalizadas = $ligacoes->where('status', 'finalizada')->count();
        $canceladas = $ligacoes->where('status', 'cancelada')->count();
        $media = $total > 0
            ? round($ligacoes->avg(fn (Ligacao $l) => $l->perguntas_obrigatorias_count > 0
                ? ($l->perguntas_respondidas_count / $l->perguntas_obrigatorias_count) * 100
                : 0), 1)
            : 0;

        return [
            'total' => $total,
            'finalizadas' => $finalizadas,
            'canceladas' => $canceladas,
            'mediaRespostas' => $media,
        ];
    }

    /** @param array<int> $usuarioIds */
    private function observacoesStats(array $usuarioIds): array
    {
        $esteMesQuery = fn () => Observacao::query()
            ->whereIn('user_id', $usuarioIds)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month);

        $hoje = Observacao::query()
            ->whereIn('user_id', $usuarioIds)
            ->whereDate('created_at', now()->toDateString())
            ->count();

        return [
            'hoje' => $hoje,
            'esteMes' => $esteMesQuery()->count(),
            'clientesUnicos' => $esteMesQuery()->distinct('cnpj')->count('cnpj'),
        ];
    }

    private function faturamentoComparacao(?array $codVendedores): array
    {
        $anoAtual = (int) now()->year;

        $porAno = function (int $ano) use ($codVendedores) {
            $query = Faturamento::query()
                ->selectRaw('MONTH(data_emissao) as mes, SUM(valor_total) as total')
                ->whereYear('data_emissao', $ano)
                ->groupBy('mes');

            if ($codVendedores !== null) {
                $query->whereIn('cod_vendedor', $codVendedores);
            }

            $totaisPorMes = $query->pluck('total', 'mes');

            return collect(range(1, 12))->map(fn ($mes) => (float) ($totaisPorMes[$mes] ?? 0))->values()->all();
        };

        return [
            'anoAtual' => $anoAtual,
            'anoAnterior' => $anoAtual - 1,
            'valoresAnoAtual' => $porAno($anoAtual),
            'valoresAnoAnterior' => $porAno($anoAtual - 1),
        ];
    }

    private function carteiraSegmento(?array $codVendedores): array
    {
        $query = Cliente::query();
        if ($codVendedores !== null) {
            // Qualificado: CarteiraAderenciaResolver faz LEFT JOIN em segmentos_vendedor,
            // que também tem cod_vendedor — sem o prefixo a query vira ambígua.
            $query->whereIn('clientes.cod_vendedor', $codVendedores);
        }

        return $this->aderenciaResolver->resolver($query);
    }

    /** @param array<int> $usuarioIds */
    private function orcamentosStats(array $usuarioIds): array
    {
        $query = Orcamento::query()->whereIn('user_id', $usuarioIds);

        return [
            'total' => (clone $query)->count(),
            'valorTotal' => (float) (clone $query)->sum('valor_total'),
            'aguardandoSupervisor' => (clone $query)->where('status_gestor', 'pendente')->where('nivel_aprovacao', 'supervisor')->count(),
            'aguardandoDiretor' => (clone $query)->where('status_gestor', 'pendente')->where('nivel_aprovacao', 'diretor')->count(),
            'aprovados' => (clone $query)->where('status_gestor', 'aprovado')->count(),
            'valorAprovado' => (float) (clone $query)->where('status_gestor', 'aprovado')->sum('valor_total'),
            'rejeitados' => (clone $query)->where('status_gestor', 'rejeitado')->count(),
            'itens' => (clone $query)
                ->latest()
                ->limit(8)
                ->get(['id', 'cliente_nome', 'valor_total', 'status_gestor', 'created_at'])
                ->map(fn (Orcamento $o) => [
                    'id' => $o->id,
                    'cliente' => $o->cliente_nome,
                    'valorTotal' => (float) $o->valor_total,
                    'status' => $o->status_gestor,
                    'criadoHoje' => $o->created_at->isToday(),
                    'criadoEm' => $o->created_at->format('d/m/Y'),
                ])
                ->values(),
        ];
    }

    private function pedidosAtencao(?array $codVendedores): array
    {
        $emAberto = Pedido::query()->whereNull('data_faturamento');
        if ($codVendedores !== null) {
            $emAberto->whereIn('cod_vendedor', $codVendedores);
        }

        $hoje = now()->toDateString();
        $em7Dias = now()->addDays(7)->toDateString();

        $atrasados = (clone $emAberto)->whereDate('data_previsao_faturamento', '<', $hoje);
        $vencendo = (clone $emAberto)->whereBetween('data_previsao_faturamento', [$hoje, $em7Dias]);

        $itens = (clone $atrasados)
            ->with('cliente:id,razao_social')
            ->orderBy('data_previsao_faturamento')
            ->limit(8)
            ->get()
            ->map(fn (Pedido $p) => [
                'numero' => $p->numero_pedido,
                'cliente' => $p->cliente?->razao_social ?? '—',
                'valorTotal' => (float) $p->valor_total,
                'previsao' => optional($p->data_previsao_faturamento)->format('d/m/Y'),
                'diasAtraso' => (int) abs(now()->diffInDays($p->data_previsao_faturamento)),
            ])
            ->values();

        return [
            'atrasados' => (clone $atrasados)->count(),
            'vencendo' => (clone $vencendo)->count(),
            'valorEmRisco' => (float) (clone $atrasados)->sum('valor_total') + (float) (clone $vencendo)->sum('valor_total'),
            'itens' => $itens,
        ];
    }
}
