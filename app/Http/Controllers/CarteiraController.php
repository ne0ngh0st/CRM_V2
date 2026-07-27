<?php

namespace App\Http\Controllers;

use App\Models\CarteiraClienteOculto;
use App\Models\CarteiraMotivoInatividade;
use App\Models\Cliente;
use App\Models\ClienteContatado;
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
        $mostrarOcultos = $request->boolean('mostrar_ocultos');
        $ordenar = (string) $request->string('ordenar') ?: 'nome_asc';

        // Escopo puro (sem os filtros dinâmicos) — usado pras opções de estado/segmento,
        // pra não fazer o dropdown "sumir" opções assim que o usuário escolhe uma.
        $scopeQuery = function () use ($codVendedores) {
            $query = Cliente::query();
            if ($codVendedores !== null) {
                $query->whereIn('clientes.cod_vendedor', $codVendedores);
            }

            return $query;
        };

        // $baseQuery nunca faz JOIN em segmentos_vendedor — o CarteiraAderenciaResolver
        // já adiciona o dele próprio; se essa query também tivesse, dava join duplicado.
        $baseQuery = function () use ($scopeQuery, $user, $busca, $estado, $segmento, $status, $mostrarOcultos, $limiteAtivo, $limiteInativando) {
            $query = $scopeQuery()
                ->select('clientes.*')
                ->selectRaw('carteira_clientes_ocultos.id as oculto_id')
                ->leftJoin('carteira_clientes_ocultos', function ($join) use ($user) {
                    $join->on('carteira_clientes_ocultos.cliente_id', '=', 'clientes.id')
                        ->where('carteira_clientes_ocultos.user_id', '=', $user->id);
                });

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

            if (! $mostrarOcultos) {
                $query->whereNull('carteira_clientes_ocultos.id');
            }

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

        $contatosPorCliente = ClienteContatado::query()
            ->whereIn('cliente_id', $clienteIdsNaPagina)
            ->latest('contatado_em')
            ->get()
            ->groupBy('cliente_id')
            ->map(fn ($grupo) => $grupo->first());

        $hoje = now();

        $segmentosPorVendedor = SegmentoVendedor::query()
            ->whereIn('cod_vendedor', $codVendedoresPresentes)
            ->get()
            ->groupBy('cod_vendedor')
            ->map(fn ($grupo) => $grupo->pluck('segmento')->all());

        $clientes->through(function (Cliente $cliente) use ($nomesPorCodVendedor, $motivosPorCliente, $contatosPorCliente, $segmentosPorVendedor, $hoje) {
            $motivo = $motivosPorCliente->get($cliente->id);
            $contato = $contatosPorCliente->get($cliente->id);
            $segmentosVendedor = $segmentosPorVendedor[$cliente->cod_vendedor] ?? [];
            $estaDentro = $cliente->cod_segmento && in_array($cliente->cod_segmento, $segmentosVendedor, true);

            return [
                'id' => $cliente->id,
                'codCliente' => $cliente->cod_cliente,
                'razaoSocial' => $cliente->razao_social,
                'nomeFantasia' => $cliente->nome_fantasia,
                'cnpj' => $cliente->cnpj,
                'estado' => $cliente->estado,
                'segmento' => $cliente->cod_segmento,
                'codVendedor' => $cliente->cod_vendedor,
                'vendedorNome' => $nomesPorCodVendedor[$cliente->cod_vendedor] ?? $cliente->cod_vendedor,
                'status' => $this->statusResolver->statusPara($cliente->data_ultima_compra, $hoje),
                'dataUltimaCompra' => optional($cliente->data_ultima_compra)->format('d/m/Y'),
                'aderencia' => $estaDentro ? 'dentro' : 'fora',
                'oculto' => $cliente->oculto_id !== null,
                'motivoInatividade' => $motivo ? [
                    'motivo' => $motivo->motivo,
                    'observacao' => $motivo->observacao,
                    'criadoEm' => $motivo->created_at->format('d/m/Y'),
                ] : null,
                'contatadoEm' => $contato ? $contato->contatado_em->format('d/m/Y H:i') : null,
            ];
        });

        return Inertia::render('Carteira/Index', [
            'role' => $role,
            'clientes' => $clientes,
            'kpis' => $kpis,
            'filtros' => [
                'busca' => $busca,
                'estado' => $estado,
                'segmento' => $segmento,
                'status' => $status,
                'aderencia' => $aderencia,
                'mostrar_ocultos' => $mostrarOcultos,
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

    public function ocultar(Request $request, Cliente $cliente): RedirectResponse
    {
        $userId = $request->user()->id;

        $existente = CarteiraClienteOculto::query()
            ->where('cliente_id', $cliente->id)
            ->where('user_id', $userId)
            ->first();

        if ($existente) {
            $existente->delete();
        } else {
            CarteiraClienteOculto::create(['cliente_id' => $cliente->id, 'user_id' => $userId]);
        }

        return back();
    }

    public function marcarContatado(Request $request, Cliente $cliente): RedirectResponse
    {
        ClienteContatado::create([
            'cliente_id' => $cliente->id,
            'user_id' => $request->user()->id,
            'contatado_em' => now(),
        ]);

        return back();
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
}
