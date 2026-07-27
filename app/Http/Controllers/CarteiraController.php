<?php

namespace App\Http\Controllers;

use App\Models\AgendamentoLigacao;
use App\Models\CarteiraMotivoInatividade;
use App\Models\Cliente;
use App\Models\Ligacao;
use App\Models\Pedido;
use App\Models\SegmentoVendedor;
use App\Models\VendedorPerfil;
use App\Services\Carteira\CarteiraAderenciaResolver;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Dashboard\DashboardScopeResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CarteiraController extends Controller
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CarteiraAderenciaResolver $aderenciaResolver,
        private readonly ClienteStatusResolver $statusResolver,
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

        $limiteAtivo = $this->statusResolver->limiteAtivo()->toDateString();
        $limiteInativando = $this->statusResolver->limiteInativando()->toDateString();

        $busca = trim((string) $request->string('busca'));
        $estado = (string) $request->string('estado');
        $segmento = (string) $request->string('segmento');
        $status = (string) $request->string('status');
        $aderencia = (string) $request->string('aderencia');
        $ordenar = (string) $request->string('ordenar') ?: 'nome_asc';
        $aba = (string) $request->string('aba') ?: 'clientes';

        $scopeQuery = function () use ($codVendedores) {
            $query = Cliente::query();
            if ($codVendedores !== null) {
                $query->whereIn('clientes.cod_vendedor', $codVendedores);
            }

            return $query;
        };

        $baseQuery = function () use ($scopeQuery, $busca, $estado, $segmento, $status, $limiteAtivo, $limiteInativando) {
            $query = $scopeQuery()->select('clientes.*');

            if ($busca !== '') {
                $query->where(function ($q) use ($busca) {
                    $q->where('clientes.razao_social', 'like', "%{$busca}%")
                        ->orWhere('clientes.nome_fantasia', 'like', "%{$busca}%")
                        ->orWhere('clientes.cnpj', 'like', "%{$busca}%")
                        ->orWhere('clientes.cod_cliente', 'like', "%{$busca}%");
                });
            }

            if ($estado !== '') {
                $query->where('clientes.estado', $estado);
            }

            if ($segmento !== '') {
                $query->where('clientes.cod_segmento', $segmento);
            }

            match ($status) {
                'ativo' => $query->where('clientes.data_ultima_compra', '>=', $limiteAtivo),
                'inativando' => $query->where('clientes.data_ultima_compra', '<', $limiteAtivo)
                    ->where('clientes.data_ultima_compra', '>=', $limiteInativando),
                'inativo' => $query->where(fn ($q) => $q->whereNull('clientes.data_ultima_compra')->orWhere('clientes.data_ultima_compra', '<', $limiteInativando)),
                default => null,
            };

            return $query;
        };

        $kpis = $this->aderenciaResolver->resolver($baseQuery());

        $listaQuery = $baseQuery();

        if ($aderencia !== '') {
            $listaQuery->leftJoin('segmentos_vendedor', function ($join) {
                $join->on('segmentos_vendedor.cod_vendedor', '=', 'clientes.cod_vendedor')
                    ->on('segmentos_vendedor.segmento', '=', 'clientes.cod_segmento');
            });

            $aderencia === 'dentro'
                ? $listaQuery->whereNotNull('segmentos_vendedor.id')
                : $listaQuery->whereNull('segmentos_vendedor.id');
        }

        match ($ordenar) {
            'ultima_compra_desc' => $listaQuery->orderByRaw('clientes.data_ultima_compra IS NULL, clientes.data_ultima_compra DESC'),
            'ultima_compra_asc' => $listaQuery->orderByRaw('clientes.data_ultima_compra IS NULL, clientes.data_ultima_compra ASC'),
            default => $listaQuery->orderBy('clientes.razao_social'),
        };

        $clientes = $listaQuery->paginate(30)->withQueryString();

        $codVendedoresPresentes = $clientes->getCollection()->pluck('cod_vendedor')->filter()->unique()->values();
        $nomesPorCodVendedor = VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codVendedoresPresentes)
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);

        $clienteIdsNaPagina = $clientes->getCollection()->pluck('id');

        $motivosPorCliente = CarteiraMotivoInatividade::query()
            ->whereIn('cliente_id', $clienteIdsNaPagina)
            ->latest()
            ->get()
            ->groupBy('cliente_id')
            ->map(fn ($grupo) => $grupo->first());

        $hoje = now();

        $segmentosPorVendedor = SegmentoVendedor::query()
            ->whereIn('cod_vendedor', $codVendedoresPresentes)
            ->get()
            ->groupBy('cod_vendedor')
            ->map(fn ($grupo) => $grupo->pluck('segmento')->all());

        $clientes->through(function (Cliente $cliente) use ($nomesPorCodVendedor, $motivosPorCliente, $segmentosPorVendedor, $hoje) {
            $motivo = $motivosPorCliente->get($cliente->id);
            $segmentosVendedor = $segmentosPorVendedor[$cliente->cod_vendedor] ?? [];
            $estaDentro = $cliente->cod_segmento && in_array($cliente->cod_segmento, $segmentosVendedor, true);

            return [
                'id' => $cliente->id,
                'codCliente' => $cliente->cod_cliente,
                'razaoSocial' => $cliente->razao_social,
                'nomeFantasia' => $cliente->nome_fantasia,
                'cnpj' => $cliente->cnpj,
                'telefone' => $cliente->telefone,
                'estado' => $cliente->estado,
                'segmento' => $cliente->cod_segmento,
                'codVendedor' => $cliente->cod_vendedor,
                'vendedorNome' => $nomesPorCodVendedor[$cliente->cod_vendedor] ?? $cliente->cod_vendedor,
                'status' => $this->statusResolver->statusPara($cliente->data_ultima_compra, $hoje),
                'dataUltimaCompra' => optional($cliente->data_ultima_compra)->format('d/m/Y'),
                'aderencia' => $estaDentro ? 'dentro' : 'fora',
                'motivoInatividade' => $motivo ? [
                    'motivo' => $motivo->motivo,
                    'observacao' => $motivo->observacao,
                    'criadoEm' => $motivo->created_at->format('d/m/Y'),
                ] : null,
            ];
        });

        return Inertia::render('Carteira/Index', [
            'role' => $role,
            'aba' => in_array($aba, ['clientes', 'calendario'], true) ? $aba : 'clientes',
            'clientes' => $clientes,
            'kpis' => $kpis,
            'agendamentos' => $this->agendamentosDoEscopo($codVendedores),
            'filtros' => [
                'busca' => $busca,
                'estado' => $estado,
                'segmento' => $segmento,
                'status' => $status,
                'aderencia' => $aderencia,
                'ordenar' => $ordenar,
            ],
            'opcoes' => [
                'estados' => $scopeQuery()->whereNotNull('estado')->where('estado', '!=', '')->distinct()->orderBy('estado')->pluck('estado'),
                'segmentos' => $scopeQuery()->whereNotNull('cod_segmento')->where('cod_segmento', '!=', '')->distinct()->orderBy('cod_segmento')->pluck('cod_segmento'),
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

    public function detalhes(Request $request, Cliente $cliente): Response
    {
        $user = $request->user();
        $scope = $this->scopeResolver->resolve($user, null, null);

        if ($scope['codVendedores'] !== null && ! in_array($cliente->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }

        $pedidosBase = Pedido::query()->where('cliente_id', $cliente->id);

        $qtdPedidos = (clone $pedidosBase)->count();
        $volumeTotal = (float) (clone $pedidosBase)->sum('valor_total');
        $ticketMedio = $qtdPedidos > 0 ? round($volumeTotal / $qtdPedidos, 2) : 0.0;

        $ultimaFaturamento = (clone $pedidosBase)->whereNotNull('data_faturamento')->max('data_faturamento');
        $ultimaCompraFormatada = optional($cliente->data_ultima_compra)->format('d/m/Y')
            ?? ($ultimaFaturamento ? \Illuminate\Support\Carbon::parse($ultimaFaturamento)->format('d/m/Y') : null);

        $pedidos = Pedido::query()
            ->where('cliente_id', $cliente->id)
            ->withCount('itens')
            ->orderByDesc('data_pedido')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Pedido $p) => [
                'id' => $p->id,
                'numeroPedido' => $p->numero_pedido,
                'dataPedido' => optional($p->data_pedido)->format('d/m/Y'),
                'dataFaturamento' => optional($p->data_faturamento)->format('d/m/Y'),
                'status' => $p->status,
                'valorTotal' => (float) $p->valor_total,
                'itensCount' => $p->itens_count,
                'emAberto' => $p->data_faturamento === null,
            ]);

        $vendedorNome = VendedorPerfil::query()
            ->where('cod_vendedor', $cliente->cod_vendedor)
            ->with('user:id,name,display_name')
            ->first();

        return Inertia::render('Carteira/Detalhes', [
            'cliente' => [
                'id' => $cliente->id,
                'codCliente' => $cliente->cod_cliente,
                'loja' => $cliente->loja,
                'razaoSocial' => $cliente->razao_social,
                'nomeFantasia' => $cliente->nome_fantasia,
                'cnpj' => $cliente->cnpj,
                'estado' => $cliente->estado,
                'cep' => $cliente->cep,
                'telefone' => $cliente->telefone,
                'email' => $cliente->email,
                'segmento' => $cliente->cod_segmento,
                'codVendedor' => $cliente->cod_vendedor,
                'vendedorNome' => $vendedorNome?->user?->display_name ?: $vendedorNome?->user?->name ?: $cliente->cod_vendedor,
                'status' => $this->statusResolver->statusPara($cliente->data_ultima_compra, now()),
                'dataUltimaCompra' => $ultimaCompraFormatada,
            ],
            'kpis' => [
                'pedidos' => $qtdPedidos,
                'volumeTotal' => $volumeTotal,
                'ticketMedio' => $ticketMedio,
                'ultimaCompra' => $ultimaCompraFormatada ?? 'Nunca',
            ],
            'pedidos' => $pedidos,
        ]);
    }

    public function registrarMotivoInatividade(Request $request, Cliente $cliente): RedirectResponse
    {
        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ]);

        CarteiraMotivoInatividade::create([
            'cliente_id' => $cliente->id,
            'motivo' => $data['motivo'],
            'observacao' => $data['observacao'] ?? null,
            'criado_por_id' => $request->user()->id,
        ]);

        return back();
    }

    public function registrarLigacao(Request $request, Cliente $cliente): RedirectResponse
    {
        Ligacao::create([
            'usuario_id' => $request->user()->id,
            'cliente_id' => $cliente->id,
            'cliente_nome' => $cliente->razao_social,
            'tipo_contato' => 'telefonica',
            'status' => 'finalizada',
            'data_ligacao' => now(),
        ]);

        return back();
    }

    public function registrarAgendamento(Request $request, Cliente $cliente): RedirectResponse
    {
        $data = $request->validate([
            'data_agendamento' => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ]);

        AgendamentoLigacao::create([
            'cliente_id' => $cliente->id,
            'user_id' => $request->user()->id,
            'data_agendamento' => $data['data_agendamento'],
            'observacao' => $data['observacao'] ?? null,
            'status' => 'agendado',
        ]);

        return back();
    }

    public function atualizarAgendamento(Request $request, AgendamentoLigacao $agendamento): RedirectResponse
    {
        $user = $request->user();
        $scope = $this->scopeResolver->resolve($user, null, null);

        $agendamento->load('cliente');
        if ($scope['codVendedores'] !== null && ! in_array($agendamento->cliente?->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:agendado,realizado,cancelado'],
        ]);

        $agendamento->update(['status' => $data['status']]);

        return back();
    }

    /**
     * @param  array<string>|null  $codVendedores
     * @return array<int, array<string, mixed>>
     */
    private function agendamentosDoEscopo(?array $codVendedores): array
    {
        $query = AgendamentoLigacao::query()
            ->with(['cliente:id,razao_social,cnpj,telefone,cod_vendedor', 'user:id,name,display_name'])
            ->whereBetween('data_agendamento', [now()->subMonths(3)->startOfMonth(), now()->addMonths(6)->endOfMonth()])
            ->orderBy('data_agendamento');

        if ($codVendedores !== null) {
            $query->whereHas('cliente', fn ($q) => $q->whereIn('cod_vendedor', $codVendedores));
        }

        return $query->get()->map(fn (AgendamentoLigacao $a) => [
            'id' => $a->id,
            'dataAgendamento' => $a->data_agendamento->toIso8601String(),
            'dataLabel' => $a->data_agendamento->format('d/m/Y H:i'),
            'dia' => $a->data_agendamento->format('Y-m-d'),
            'hora' => $a->data_agendamento->format('H:i'),
            'observacao' => $a->observacao,
            'status' => $a->status,
            'clienteId' => $a->cliente_id,
            'clienteNome' => $a->cliente?->razao_social ?? '—',
            'clienteCnpj' => $a->cliente?->cnpj,
            'autor' => $a->user?->display_name ?: $a->user?->name,
        ])->values()->all();
    }
}
