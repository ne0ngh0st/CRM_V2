<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\VendedorPerfil;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Metas\MetaRankingResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PedidoController extends Controller
{
    private const STATUSES = ['separacao', 'bloqueio', 'wms', 'liberado', 'faturado', 'pendente_totvs'];

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly MetaRankingResolver $metaRanking,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $scope = $this->scopeResolver->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $hoje = now()->toDateString();
        $em7Dias = now()->addDays(7)->toDateString();

        $busca = trim((string) $request->string('busca'));
        $status = (string) $request->string('status');
        $dataInicio = (string) $request->string('data_inicio');
        $dataFim = (string) $request->string('data_fim');
        $situacao = (string) $request->string('situacao') ?: 'todos';
        $ordenar = (string) $request->string('ordenar') ?: 'previsao_asc';

        $kpis = [
            'totalAberto' => $this->baseQueryAbertos($request)->count(),
            'atrasados' => $this->baseQueryAbertos($request)->whereDate('data_previsao_faturamento', '<', $hoje)->count(),
            'vencendo' => $this->baseQueryAbertos($request)->whereBetween('data_previsao_faturamento', [$hoje, $em7Dias])->count(),
            'valorEmRisco' => (float) $this->baseQueryAbertos($request)
                ->where(function ($q) use ($hoje, $em7Dias) {
                    $q->whereDate('data_previsao_faturamento', '<', $hoje)
                        ->orWhereBetween('data_previsao_faturamento', [$hoje, $em7Dias]);
                })
                ->sum('valor_total'),
        ];

        $pedidos = $this->listaQueryAbertos($request)
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

    public function exportarAbertos(Request $request): BinaryFileResponse
    {
        // Tabelas de pedidos podem crescer bastante sem filtro (ver Carteira, medido em ~90k linhas
        // = ~540MB/100s) — margem de segurança só nesta request.
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $query = $this->listaQueryAbertos($request)
            ->with('cliente:id,razao_social,cnpj')
            ->withCount('itens');

        return Excel::download(
            new \App\Exports\PedidoAbertoExport($query),
            'pedidos-abertos-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** Escopo (cod_vendedor) + busca/status/data. Pedidos em aberto (data_faturamento nula). Usado por index() (KPIs e lista) e exportarAbertos(). */
    protected function baseQueryAbertos(Request $request): Builder
    {
        $scope = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $busca = trim((string) $request->string('busca'));
        $status = (string) $request->string('status');
        $dataInicio = (string) $request->string('data_inicio');
        $dataFim = (string) $request->string('data_fim');

        $query = Pedido::query()->whereNull('data_faturamento');

        if ($scope['codVendedores'] !== null) {
            $query->whereIn('cod_vendedor', $scope['codVendedores']);
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
    }

    /** baseQueryAbertos() + situação + ordenação. Usado por index() (lista) e exportarAbertos(). */
    protected function listaQueryAbertos(Request $request): Builder
    {
        $query = $this->baseQueryAbertos($request);
        $hoje = now()->toDateString();
        $em7Dias = now()->addDays(7)->toDateString();
        $situacao = (string) $request->string('situacao') ?: 'todos';
        $ordenar = (string) $request->string('ordenar') ?: 'previsao_asc';

        match ($situacao) {
            'atrasado' => $query->whereDate('data_previsao_faturamento', '<', $hoje),
            'vencendo' => $query->whereBetween('data_previsao_faturamento', [$hoje, $em7Dias]),
            'risco' => $query->where(fn ($q) => $q->whereDate('data_previsao_faturamento', '<', $hoje)
                ->orWhereBetween('data_previsao_faturamento', [$hoje, $em7Dias])),
            'no_prazo' => $query->where(fn ($q) => $q->whereDate('data_previsao_faturamento', '>', $em7Dias)->orWhereNull('data_previsao_faturamento')),
            default => null,
        };

        match ($ordenar) {
            'previsao_desc' => $query->orderByRaw('data_previsao_faturamento IS NULL, data_previsao_faturamento DESC'),
            'valor_desc' => $query->orderByDesc('valor_total'),
            'valor_asc' => $query->orderBy('valor_total'),
            'data_pedido_desc' => $query->orderByDesc('data_pedido'),
            'data_pedido_asc' => $query->orderBy('data_pedido'),
            default => $query->orderByRaw('data_previsao_faturamento IS NULL, data_previsao_faturamento ASC'),
        };

        return $query;
    }

    /**
     * Pedidos emitidos = toda a tabela `pedidos` (ex-META_VENDA do legado).
     * Período mensal com a mesma janela D-1 da Home / Metas.
     */
    public function emitidos(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $scope = $this->scopeResolver->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );
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

        $pedidos = $this->listaQueryEmitidos($request)
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

    public function exportarEmitidos(Request $request): BinaryFileResponse
    {
        // Ver exportarAbertos() — mesma margem de segurança pra tabela `pedidos`.
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        $ano = (int) ($request->integer('ano') ?: now()->year);
        $mes = max(1, min(12, (int) ($request->integer('mes') ?: now()->month)));

        $query = $this->listaQueryEmitidos($request)
            ->with('cliente:id,razao_social,cnpj')
            ->withCount('itens');

        return Excel::download(
            new \App\Exports\PedidoEmitidoExport($query),
            "pedidos-emitidos-{$ano}-{$mes}-".now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** Escopo (cod_vendedor) + período (ano/mes) + busca/faturamento. Usado por emitidos() (lista) e exportarEmitidos(). */
    protected function baseQueryEmitidos(Request $request): Builder
    {
        $scope = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $ano = (int) ($request->integer('ano') ?: now()->year);
        $mes = max(1, min(12, (int) ($request->integer('mes') ?: now()->month)));
        $busca = trim((string) $request->string('busca'));
        $faturamento = (string) $request->string('faturamento');
        if (! in_array($faturamento, ['faturado', 'aberto'], true)) {
            $faturamento = '';
        }

        [$inicio, $fim] = $this->metaRanking->intervaloDatas($ano, $mes, $mes);

        $query = Pedido::query()->whereBetween('data_pedido', [$inicio, $fim]);

        if ($scope['codVendedores'] !== null) {
            $query->whereIn('cod_vendedor', $scope['codVendedores']);
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
    }

    /** baseQueryEmitidos() + ordenação. Usado por emitidos() (lista) e exportarEmitidos(). */
    protected function listaQueryEmitidos(Request $request): Builder
    {
        $query = $this->baseQueryEmitidos($request);
        $ordenar = (string) $request->string('ordenar') ?: 'data_pedido_desc';

        match ($ordenar) {
            'valor_desc' => $query->orderByDesc('valor_total'),
            'valor_asc' => $query->orderBy('valor_total'),
            'data_pedido_asc' => $query->orderBy('data_pedido'),
            'faturamento_desc' => $query->orderByRaw('data_faturamento IS NULL, data_faturamento DESC'),
            'faturamento_asc' => $query->orderByRaw('data_faturamento IS NULL, data_faturamento ASC'),
            default => $query->orderByDesc('data_pedido'),
        };

        return $query;
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
