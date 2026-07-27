<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\VendedorPerfil;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PedidoController extends Controller
{
    private const STATUSES = ['separacao', 'bloqueio', 'wms', 'liberado', 'faturado'];

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly MetaRankingResolver $metaRanking,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $visaoSupervisor = $request->string('visao_supervisor')->value() ?: null;
        $visaoVendedor = $request->string('visao_vendedor')->value() ?: null;

        $scope = $this->scopeResolver->resolve($user, $visaoSupervisor, $visaoVendedor);
        $codVendedores = $scope['codVendedores'];

        $hoje = now()->toDateString();
        $em7Dias = now()->addDays(7)->toDateString();

        $busca = trim((string) $request->string('busca'));
        $status = (string) $request->string('status');
        $dataInicio = (string) $request->string('data_inicio');
        $dataFim = (string) $request->string('data_fim');
        $situacao = (string) $request->string('situacao') ?: 'todos';
        $ordenar = (string) $request->string('ordenar') ?: 'previsao_asc';

        $baseQuery = function () use ($codVendedores, $busca, $status, $dataInicio, $dataFim) {
            $query = Pedido::query()->whereNull('data_faturamento');

            if ($codVendedores !== null) {
                $query->whereIn('cod_vendedor', $codVendedores);
            }

            if ($busca !== '') {
                $query->where(function ($q) use ($busca) {
                    $q->where('numero_pedido', 'like', "%{$busca}%")
                        ->orWhereHas('cliente', fn ($c) => $c->where('razao_social', 'like', "%{$busca}%")->orWhere('cnpj', 'like', "%{$busca}%"))
                        ->orWhereHas('itens', fn ($i) => $i->where('descricao', 'like', "%{$busca}%")->orWhere('cod_produto', 'like', "%{$busca}%"));
                });
            }

            if ($status !== '') {
                $query->where('status', $status);
            }

            if ($dataInicio !== '') {
                $query->whereDate('data_pedido', '>=', $dataInicio);
            }

            if ($dataFim !== '') {
                $query->whereDate('data_pedido', '<=', $dataFim);
            }

            return $query;
        };

        $kpis = [
            'totalAberto' => (clone $baseQuery())->count(),
            'atrasados' => (clone $baseQuery())->whereDate('data_previsao_faturamento', '<', $hoje)->count(),
            'vencendo' => (clone $baseQuery())->whereBetween('data_previsao_faturamento', [$hoje, $em7Dias])->count(),
            'valorEmRisco' => (float) (clone $baseQuery())
                ->where(function ($q) use ($hoje, $em7Dias) {
                    $q->whereDate('data_previsao_faturamento', '<', $hoje)
                        ->orWhereBetween('data_previsao_faturamento', [$hoje, $em7Dias]);
                })
                ->sum('valor_total'),
        ];

        $listaQuery = $baseQuery();

        match ($situacao) {
            'atrasado' => $listaQuery->whereDate('data_previsao_faturamento', '<', $hoje),
            'vencendo' => $listaQuery->whereBetween('data_previsao_faturamento', [$hoje, $em7Dias]),
            'no_prazo' => $listaQuery->where(fn ($q) => $q->whereDate('data_previsao_faturamento', '>', $em7Dias)->orWhereNull('data_previsao_faturamento')),
            default => null,
        };

        match ($ordenar) {
            'previsao_desc' => $listaQuery->orderByRaw('data_previsao_faturamento IS NULL, data_previsao_faturamento DESC'),
            'valor_desc' => $listaQuery->orderByDesc('valor_total'),
            'valor_asc' => $listaQuery->orderBy('valor_total'),
            'data_pedido_desc' => $listaQuery->orderByDesc('data_pedido'),
            'data_pedido_asc' => $listaQuery->orderBy('data_pedido'),
            default => $listaQuery->orderByRaw('data_previsao_faturamento IS NULL, data_previsao_faturamento ASC'),
        };

        $pedidos = $listaQuery
            ->with(['cliente:id,razao_social,cnpj,telefone,email', 'itens'])
            ->paginate(20)
            ->withQueryString();

        $codVendedoresPresentes = $pedidos->getCollection()->pluck('cod_vendedor')->unique()->values();
        $nomesPorCodVendedor = VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codVendedoresPresentes)
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);

        $pedidos->through(fn (Pedido $p) => [
            'id' => $p->id,
            'numeroPedido' => $p->numero_pedido,
            'cliente' => $p->cliente ? [
                'id' => $p->cliente->id,
                'razaoSocial' => $p->cliente->razao_social,
                'cnpj' => $p->cliente->cnpj,
                'telefone' => $p->cliente->telefone,
                'email' => $p->cliente->email,
            ] : null,
            'codVendedor' => $p->cod_vendedor,
            'vendedorNome' => $nomesPorCodVendedor[$p->cod_vendedor] ?? $p->cod_vendedor,
            'dataPedido' => optional($p->data_pedido)->format('d/m/Y'),
            'dataPrevisaoFaturamento' => optional($p->data_previsao_faturamento)->format('d/m/Y'),
            'dataEntregaPrevista' => optional($p->data_entrega_prevista)->format('d/m/Y'),
            'dataPcp' => optional($p->data_pcp)->format('d/m/Y'),
            'carga' => $p->carga,
            'condicaoPagamento' => $p->condicao_pagamento,
            'situacao' => $this->classificarSituacao($p->data_previsao_faturamento, $hoje, $em7Dias),
            'diasAtraso' => $p->data_previsao_faturamento && $p->data_previsao_faturamento->toDateString() < $hoje
                ? (int) $p->data_previsao_faturamento->diffInDays(now())
                : null,
            'valorTotal' => (float) $p->valor_total,
            'status' => $p->status,
            'itens' => $p->itens->map(fn ($item) => [
                'codProduto' => $item->cod_produto,
                'descricao' => $item->descricao,
                'quantidade' => (float) $item->quantidade,
                'quantidadeLiberada' => $item->quantidade_liberada !== null ? (float) $item->quantidade_liberada : null,
                'valorUnitario' => (float) $item->valor_unitario,
                'valorTotal' => (float) $item->valor_total,
            ])->values(),
        ]);

        return Inertia::render('Pedidos/Index', [
            'role' => $role,
            'pedidos' => $pedidos,
            'kpis' => $kpis,
            'filtros' => [
                'busca' => $busca,
                'situacao' => $situacao,
                'status' => $status,
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
                'ordenar' => $ordenar,
            ],
            'opcoes' => [
                'status' => self::STATUSES,
            ],
            'visao' => [
                'mostrarSeletor' => in_array($role, ['supervisor', 'admin', 'diretor'], true),
                'supervisores' => in_array($role, ['admin', 'diretor'], true) ? $this->scopeResolver->opcoesSupervisores() : [],
                'vendedores' => in_array($role, ['supervisor', 'admin', 'diretor'], true)
                    ? $this->scopeResolver->opcoesVendedores($user, $scope['visaoSupervisor'])
                    : [],
                'visaoSupervisor' => $scope['visaoSupervisor'],
                'visaoVendedor' => $scope['visaoVendedor'],
            ],
        ]);
    }

    /**
     * Pedidos emitidos = toda a tabela `pedidos` (ex-META_VENDA do legado).
     * Período mensal com a mesma janela D-1 da Home / Metas.
     */
    public function emitidos(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $visaoSupervisor = $request->string('visao_supervisor')->value() ?: null;
        $visaoVendedor = $request->string('visao_vendedor')->value() ?: null;
        $scope = $this->scopeResolver->resolve($user, $visaoSupervisor, $visaoVendedor);
        $codVendedores = $scope['codVendedores'];

        $ano = (int) ($request->integer('ano') ?: now()->year);
        $mes = (int) ($request->integer('mes') ?: now()->month);
        $mes = max(1, min(12, $mes));
        $busca = trim((string) $request->string('busca'));
        $faturamento = (string) $request->string('faturamento'); // '' | faturado | aberto
        if (! in_array($faturamento, ['faturado', 'aberto'], true)) {
            $faturamento = '';
        }
        $ordenar = (string) $request->string('ordenar') ?: 'data_pedido_desc';

        [$inicio, $fim] = $this->metaRanking->intervaloDatas($ano, $mes, $mes);

        $baseQuery = function () use ($codVendedores, $busca, $faturamento, $inicio, $fim) {
            $query = Pedido::query()->whereBetween('data_pedido', [$inicio, $fim]);

            if ($codVendedores !== null) {
                $query->whereIn('cod_vendedor', $codVendedores);
            }

            if ($busca !== '') {
                $query->where(function ($q) use ($busca) {
                    $q->where('numero_pedido', 'like', "%{$busca}%")
                        ->orWhereHas('cliente', fn ($c) => $c->where('razao_social', 'like', "%{$busca}%")->orWhere('cnpj', 'like', "%{$busca}%"))
                        ->orWhereHas('itens', fn ($i) => $i->where('descricao', 'like', "%{$busca}%")->orWhere('cod_produto', 'like', "%{$busca}%"));
                });
            }

            match ($faturamento) {
                'faturado' => $query->whereNotNull('data_faturamento'),
                'aberto' => $query->whereNull('data_faturamento'),
                default => null,
            };

            return $query;
        };

        // KPIs no período (sem filtro faturamento da listagem — chips continuam corretos).
        $kpiBase = Pedido::query()->whereBetween('data_pedido', [$inicio, $fim]);
        if ($codVendedores !== null) {
            $kpiBase->whereIn('cod_vendedor', $codVendedores);
        }
        if ($busca !== '') {
            $kpiBase->where(function ($q) use ($busca) {
                $q->where('numero_pedido', 'like', "%{$busca}%")
                    ->orWhereHas('cliente', fn ($c) => $c->where('razao_social', 'like', "%{$busca}%")->orWhere('cnpj', 'like', "%{$busca}%"));
            });
        }

        $kpis = [
            'total' => (clone $kpiBase)->count(),
            'valorTotal' => (float) (clone $kpiBase)->sum('valor_total'),
            'faturados' => (clone $kpiBase)->whereNotNull('data_faturamento')->count(),
            'emAberto' => (clone $kpiBase)->whereNull('data_faturamento')->count(),
        ];

        $listaQuery = $baseQuery();

        match ($ordenar) {
            'valor_desc' => $listaQuery->orderByDesc('valor_total'),
            'valor_asc' => $listaQuery->orderBy('valor_total'),
            'data_pedido_asc' => $listaQuery->orderBy('data_pedido'),
            'faturamento_desc' => $listaQuery->orderByRaw('data_faturamento IS NULL, data_faturamento DESC'),
            'faturamento_asc' => $listaQuery->orderByRaw('data_faturamento IS NULL, data_faturamento ASC'),
            default => $listaQuery->orderByDesc('data_pedido'),
        };

        $pedidos = $listaQuery
            ->with(['cliente:id,razao_social,cnpj,telefone,email', 'itens'])
            ->paginate(25)
            ->withQueryString();

        $codVendedoresPresentes = $pedidos->getCollection()->pluck('cod_vendedor')->unique()->values();
        $nomesPorCodVendedor = VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codVendedoresPresentes)
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);

        $pedidos->through(fn (Pedido $p) => [
            'id' => $p->id,
            'numeroPedido' => $p->numero_pedido,
            'cliente' => $p->cliente ? [
                'id' => $p->cliente->id,
                'razaoSocial' => $p->cliente->razao_social,
                'cnpj' => $p->cliente->cnpj,
                'telefone' => $p->cliente->telefone,
                'email' => $p->cliente->email,
            ] : null,
            'codVendedor' => $p->cod_vendedor,
            'vendedorNome' => $nomesPorCodVendedor[$p->cod_vendedor] ?? $p->cod_vendedor,
            'dataPedido' => optional($p->data_pedido)->format('d/m/Y'),
            'dataFaturamento' => optional($p->data_faturamento)->format('d/m/Y'),
            'dataPrevisaoFaturamento' => optional($p->data_previsao_faturamento)->format('d/m/Y'),
            'dataEntregaPrevista' => optional($p->data_entrega_prevista)->format('d/m/Y'),
            'dataPcp' => optional($p->data_pcp)->format('d/m/Y'),
            'carga' => $p->carga,
            'condicaoPagamento' => $p->condicao_pagamento,
            'faturado' => $p->data_faturamento !== null,
            'valorTotal' => (float) $p->valor_total,
            'status' => $p->status,
            'itens' => $p->itens->map(fn ($item) => [
                'codProduto' => $item->cod_produto,
                'descricao' => $item->descricao,
                'quantidade' => (float) $item->quantidade,
                'quantidadeLiberada' => $item->quantidade_liberada !== null ? (float) $item->quantidade_liberada : null,
                'valorUnitario' => (float) $item->valor_unitario,
                'valorTotal' => (float) $item->valor_total,
            ])->values(),
        ]);

        return Inertia::render('Pedidos/Emitidos', [
            'role' => $role,
            'pedidos' => $pedidos,
            'kpis' => $kpis,
            'periodo' => [
                'inicio' => $inicio,
                'fim' => $fim,
                'd1' => $ano === (int) now()->year && $mes === (int) now()->month,
            ],
            'filtros' => [
                'ano' => $ano,
                'mes' => $mes,
                'busca' => $busca,
                'faturamento' => $faturamento,
                'ordenar' => $ordenar,
            ],
            'opcoes' => [
                'anos' => range((int) now()->year, (int) now()->year - 2),
            ],
            'visao' => [
                'mostrarSeletor' => in_array($role, ['supervisor', 'admin', 'diretor'], true),
                'supervisores' => in_array($role, ['admin', 'diretor'], true) ? $this->scopeResolver->opcoesSupervisores() : [],
                'vendedores' => in_array($role, ['supervisor', 'admin', 'diretor'], true)
                    ? $this->scopeResolver->opcoesVendedores($user, $scope['visaoSupervisor'])
                    : [],
                'visaoSupervisor' => $scope['visaoSupervisor'],
                'visaoVendedor' => $scope['visaoVendedor'],
            ],
        ]);
    }

    private function classificarSituacao(?Carbon $previsao, string $hoje, string $em7Dias): string
    {
        if (! $previsao) {
            return 'sem_previsao';
        }

        $data = $previsao->toDateString();

        return match (true) {
            $data < $hoje => 'atrasado',
            $data <= $em7Dias => 'vencendo',
            default => 'no_prazo',
        };
    }
}
