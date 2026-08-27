<?php

namespace App\Http\Controllers;

use App\Models\AgendamentoLigacao;
use App\Models\CarteiraMotivoInatividade;
use App\Models\Cliente;
use App\Models\GrupoCliente;
use App\Models\Ligacao;
use App\Models\Pedido;
use App\Models\Segmento;
use App\Models\VendedorPerfil;
use App\Services\Cache\CacheDeAgregacao;
use App\Services\Cache\ChaveEscopo;
use App\Services\Carteira\CarteiraAderenciaResolver;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Dashboard\DashboardBlocos;
use App\Services\Dashboard\DashboardScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CarteiraController extends Controller
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CarteiraAderenciaResolver $aderenciaResolver,
        private readonly ClienteStatusResolver $statusResolver,
        private readonly CacheDeAgregacao $cache,
        private readonly DashboardBlocos $blocos,
    ) {
    }

    /**
     * KPIs de aderência do topo da Carteira.
     *
     * O bloco mais caro da tela: ~950 ms de SQL no escopo admin (LEFT JOIN em
     * segmentos/segmentos_vendedor sobre as ~90k linhas de clientes). Era o último lugar
     * do sistema rodando essa agregação sem cache, e a razão de /carteira ter sido o pior
     * p50 do teste de carga.
     *
     * ⚠️ SEM FILTRO, ISTO É EXATAMENTE O CARD DO DASHBOARD.
     * `baseQuery()` sem filtros é `scopeQuery()` mais um `select('clientes.*')` — e o
     * CarteiraAderenciaResolver limpa o select logo no início, então a diferença some.
     * Como a pergunta é a mesma, a resposta tem que ser a mesma (Regra de ouro nº 8):
     * delegamos ao bloco do Dashboard e as duas telas passam a dividir a MESMA chave.
     *
     * Isso vale mais que economia de uma query: essa chave já é aquecida pelo job de
     * warming, então a Carteira sem filtro passou a nunca pagar a agregação — de graça,
     * sem precisar aquecer nada novo.
     *
     * Com filtro ativo, cai no cache próprio: chave com data (o resolver classifica por
     * dias desde a última compra, 290/365, a partir de now()) e TTL curto, já que a
     * cardinalidade vem da query string e é ilimitada em teoria.
     *
     * @param  array<string>|null  $codVendedores
     */
    private function aderencia(Request $request, ?array $codVendedores): array
    {
        $escopo = ChaveEscopo::deCodVendedores($codVendedores);
        $assinatura = $this->assinaturaDosFiltros($request);

        if ($assinatura === 'vazio') {
            return $this->blocos->carteiraSegmento($escopo, $codVendedores);
        }

        return $this->cache->lembrarPorMinutos(
            $escopo->paraDoDia('carteira-kpis', ['f' => $assinatura]),
            10,
            fn () => $this->aderenciaResolver->resolver($this->baseQuery($request)),
        );
    }

    /**
     * Opções dos dropdowns de Estado e Segmento.
     *
     * ⚠️ São dois DISTINCT sobre a tabela inteira de clientes (~90k linhas), e dependem
     * SÓ do escopo — nunca dos filtros ativos, senão o dropdown perderia opções conforme
     * o usuário filtra. Por dependerem só do escopo, são perfeitamente cacheáveis, e por
     * mudarem apenas quando o TOTVS traz cliente novo, o TTL pode ser longo.
     *
     * @param  array<string>|null  $codVendedores
     * @return array{estados: mixed, segmentos: mixed}
     */
    private function opcoesDeFiltro(Request $request, ?array $codVendedores): array
    {
        return $this->cache->lembrarPorHoras(
            ChaveEscopo::deCodVendedores($codVendedores)->para('carteira-opcoes'),
            (int) config('perf.ttl_lookup_minutos', 360) / 60,
            fn () => [
                'estados' => $this->scopeQuery($request)
                    ->whereNotNull('estado')->where('estado', '!=', '')
                    ->distinct()->orderBy('estado')->pluck('estado'),
                'segmentos' => Segmento::query()
                    ->whereIn('codigo', $this->scopeQuery($request)
                        ->whereNotNull('cod_segmento')->where('cod_segmento', '!=', '')
                        ->distinct()->pluck('cod_segmento'))
                    ->orderBy('nome')
                    ->get(['codigo', 'nome']),
            ],
        );
    }

    /**
     * Assinatura estável dos filtros ativos, para compor a chave de cache dos KPIs.
     *
     * ⚠️ Sem filtro nenhum devolve a constante 'vazio', e isso é essencial: é o único
     * caso que o warming alcança (as demais combinações vêm da query string e são
     * ilimitadas em teoria). Manter esse caso legível, em vez de virar mais um hash,
     * é o que permite tratá-lo de propósito em `aderencia()`.
     */
    private function assinaturaDosFiltros(Request $request): string
    {
        $filtros = array_filter([
            'busca' => trim((string) $request->string('busca')),
            'estado' => (string) $request->string('estado'),
            'segmento' => (string) $request->string('segmento'),
            'status' => (string) $request->string('status'),
            'aderencia' => (string) $request->string('aderencia'),
        ], fn (string $v) => $v !== '');

        if ($filtros === []) {
            return 'vazio';
        }

        ksort($filtros);

        return md5(json_encode($filtros));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $scope = $this->scopeResolver->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );
        $codVendedores = $scope['codVendedores'];

        $busca = trim((string) $request->string('busca'));
        $estado = (string) $request->string('estado');
        $segmento = (string) $request->string('segmento');
        $status = (string) $request->string('status');
        $aderencia = (string) $request->string('aderencia');
        $ordenar = (string) $request->string('ordenar') ?: 'nome_asc';
        $aba = (string) $request->string('aba') ?: 'clientes';

        $kpis = $this->aderencia($request, $codVendedores);

        /*
         * O total é contado por `filtradaQuery()`, que NÃO tem o join de ordenação.
         * Ordenar por nome de grupo/segmento faz um LEFT JOIN, e sem isso o COUNT da
         * paginação pagaria o join também: medido em ~450ms dos ~610ms totais.
         * O join é num campo `unique` (`grupos_cliente.codigo`, `segmentos.codigo`),
         * então é 1:1 no máximo e não muda a contagem — a conta continua correta.
         * Se algum dia entrar aqui um join que possa multiplicar linha, este atalho
         * deixa de valer e o total sai errado.
         */
        $clientes = $this->listaQuery($request)
            ->paginate(perPage: 30, total: $this->filtradaQuery($request)->count())
            ->withQueryString();

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

        $nomePorCodigo = Segmento::pluck('nome', 'codigo');

        // Só os grupos que aparecem nesta página — são 2.4k no total, não vale carregar tudo.
        $nomePorGrupo = GrupoCliente::query()
            ->whereIn('codigo', $clientes->getCollection()->pluck('cod_grupo')->filter()->unique())
            ->pluck('nome', 'codigo');

        $clientes->through(function (Cliente $cliente) use ($nomesPorCodVendedor, $motivosPorCliente, $nomePorCodigo, $nomePorGrupo, $hoje) {
            $motivo = $motivosPorCliente->get($cliente->id);

            return [
                'id' => $cliente->id,
                'codCliente' => $cliente->cod_cliente,
                'razaoSocial' => $cliente->razao_social,
                'nomeFantasia' => $cliente->nome_fantasia,
                'cnpj' => $cliente->cnpj,
                'telefone' => $cliente->telefone,
                'estado' => $cliente->estado,
                'segmento' => $cliente->cod_segmento ? ($nomePorCodigo[$cliente->cod_segmento] ?? $cliente->cod_segmento) : null,
                'grupo' => $cliente->cod_grupo ? ($nomePorGrupo[$cliente->cod_grupo] ?? $cliente->cod_grupo) : null,
                'codVendedor' => $cliente->cod_vendedor,
                'vendedorNome' => $nomesPorCodVendedor[$cliente->cod_vendedor] ?? $cliente->cod_vendedor,
                'status' => $this->statusResolver->statusPara($cliente->data_ultima_compra, $hoje),
                'dataUltimaCompra' => optional($cliente->data_ultima_compra)->format('d/m/Y'),
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
            'opcoes' => $this->opcoesDeFiltro($request, $codVendedores),
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

    public function exportar(Request $request): BinaryFileResponse
    {
        // Medido com os ~89.800 clientes sem filtro (escopo admin): ~540MB de pico e ~100s —
        // acima do memory_limit/max_execution_time padrão do PHP-FPM. Ajuste só desta request.
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        return Excel::download(
            new \App\Exports\CarteiraExport($this->listaQuery($request), $this->statusResolver),
            'carteira-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** Escopo (cod_vendedor) puro, sem filtros de busca/estado/segmento/status. */
    protected function scopeQuery(Request $request): Builder
    {
        $scope = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $query = Cliente::query();
        if ($scope['codVendedores'] !== null) {
            $query->whereIn('clientes.cod_vendedor', $scope['codVendedores']);
        }

        return $query;
    }

    /** scopeQuery() + busca/estado/segmento/status. Sem aderência/ordenação. Usado por index() (lista e KPIs) e exportar(). */
    protected function baseQuery(Request $request): Builder
    {
        $limiteAtivo = $this->statusResolver->limiteAtivo()->toDateString();
        $limiteInativando = $this->statusResolver->limiteInativando()->toDateString();

        $busca = trim((string) $request->string('busca'));
        $estado = (string) $request->string('estado');
        $segmento = (string) $request->string('segmento');
        $status = (string) $request->string('status');

        $query = $this->scopeQuery($request)->select('clientes.*');

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
    }

    /**
     * Colunas que a tela deixa ordenar por clique no header.
     *
     * É whitelist de propósito: o campo vem da query string, e concatenar isso
     * direto num orderBy seria injeção de SQL. Nunca trocar por passar o valor
     * cru pro orderBy(), nem "só validar se não tem espaço".
     *
     * `join` marca as duas que ordenam por um nome que mora em outra tabela.
     * Custo medido com 91.293 clientes (escopo admin, sem filtro): coluna direta
     * indexada ~0,5ms; as com join ~156ms, porque o LEFT JOIN força filesort e
     * nenhum índice cobre. Por isso nenhuma das duas é a ordem padrão da tela —
     * são ação explícita do usuário, não custo de todo carregamento.
     */
    private const ORDENACOES = [
        'nome' => ['coluna' => 'clientes.razao_social'],
        'grupo' => ['coluna' => 'grp_ord.nome', 'join' => 'grupo'],
        'vendedor' => ['coluna' => 'clientes.cod_vendedor'],
        'estado' => ['coluna' => 'clientes.estado'],
        'segmento' => ['coluna' => 'seg_ord.nome', 'join' => 'segmento'],
        // Status é derivado de data_ultima_compra por faixas, então ordenar por
        // um é ordenar pelo outro. Só inverte o sentido: "status asc" é
        // Ativo -> Inativo, que é compra mais RECENTE primeiro.
        'status' => ['coluna' => 'clientes.data_ultima_compra', 'inverter' => true],
        'ultima_compra' => ['coluna' => 'clientes.data_ultima_compra'],
    ];

    /** baseQuery() + aderência + ordenação. Usado por index() (lista) e exportar(). */
    protected function listaQuery(Request $request): Builder
    {
        return $this->aplicarOrdenacao(
            $this->filtradaQuery($request),
            (string) $request->string('ordenar') ?: 'nome_asc',
        );
    }

    /**
     * baseQuery() + aderência, sem ordenação. Existe separado porque é a query que
     * define QUANTAS linhas a lista tem — o join de ordenação por nome de
     * grupo/segmento não pode entrar nessa conta (ver `index()`).
     */
    protected function filtradaQuery(Request $request): Builder
    {
        $query = $this->baseQuery($request);
        $aderencia = (string) $request->string('aderencia');

        if ($aderencia !== '') {
            $temSegmentoDefinido = fn ($q) => $q->selectRaw(1)
                ->from('segmentos_vendedor as sv2')
                ->whereColumn('sv2.cod_vendedor', 'clientes.cod_vendedor');

            if ($aderencia === 'sem_segmento') {
                $query->whereNotExists($temSegmentoDefinido);
            } else {
                $query->whereExists($temSegmentoDefinido)
                    ->leftJoin('segmentos', 'segmentos.codigo', '=', 'clientes.cod_segmento')
                    ->leftJoin('segmentos_vendedor', function ($join) {
                        $join->on('segmentos_vendedor.cod_vendedor', '=', 'clientes.cod_vendedor')
                            ->on('segmentos_vendedor.segmento_id', '=', 'segmentos.id');
                    });

                $aderencia === 'dentro'
                    ? $query->whereNotNull('segmentos_vendedor.id')
                    : $query->whereNull('segmentos_vendedor.id');
            }
        }

        return $query;
    }

    /**
     * `ordenar` chega como "<campo>_<asc|desc>" (ex.: "grupo_desc"). Campo fora da
     * whitelist ou direção inválida cai no padrão, sem erro pro usuário.
     */
    protected function aplicarOrdenacao(Builder $query, string $ordenar): Builder
    {
        preg_match('/^(.*)_(asc|desc)$/', $ordenar, $partes);

        $campo = $partes[1] ?? 'nome';
        $direcao = $partes[2] ?? 'asc';

        $config = self::ORDENACOES[$campo] ?? self::ORDENACOES['nome'];

        if (($config['inverter'] ?? false)) {
            $direcao = $direcao === 'asc' ? 'desc' : 'asc';
        }

        // Aliases: `listaQuery` já pode ter dado join em `segmentos` pro filtro de
        // aderência — sem alias o segundo join quebra com "Not unique table/alias".
        match ($config['join'] ?? null) {
            'grupo' => $query->leftJoin('grupos_cliente as grp_ord', 'grp_ord.codigo', '=', 'clientes.cod_grupo'),
            'segmento' => $query->leftJoin('segmentos as seg_ord', 'seg_ord.codigo', '=', 'clientes.cod_segmento'),
            default => null,
        };

        /*
         * ⚠️ Sem `data_ultima_compra IS NULL` na frente, que é como isto era escrito.
         * Aquilo põe uma EXPRESSÃO como primeira chave de ordenação, e expressão não
         * usa índice: media 80ms mesmo com o índice em `data_ultima_compra`, contra
         * 0,34ms sem ela (mesma armadilha da Regra de ouro nº 6, agora no ORDER BY).
         *
         * O MySQL já entrega o que aquilo queria no DESC: NULL é o menor valor, então
         * "nunca comprou" vai pro fim sozinho. No ASC os NULLs vêm primeiro — mudança
         * de comportamento assumida, e que faz sentido: quem nunca comprou é o mais
         * frio de todos. MySQL 8 não tem NULLS LAST, então manter os dois com NULL no
         * fim custaria o filesort de volta.
         */
        return $query->orderBy($config['coluna'], $direcao)
            /*
             * Desempate estável: sem isso, páginas diferentes podem repetir/pular
             * registros quando a coluna ordenada tem muitos valores iguais (estado,
             * grupo, status).
             *
             * ⚠️ A direção do desempate TEM que acompanhar a da coluna. Um índice
             * secundário do InnoDB já carrega a PK dentro dele, mas na ordem dele:
             * ordenar "coluna DESC, id ASC" mistura os sentidos, nenhum índice
             * atende, e volta o `type: ALL` + filesort (medido: 80ms contra 0,39ms).
             * Fixar `->orderBy('clientes.id')` aqui reintroduz o problema.
             */
            ->orderBy('clientes.id', $direcao);
    }

    public function detalhes(Request $request, Cliente $cliente): Response
    {
        $this->autorizarCliente($request, $cliente);

        $pedidosBase = Pedido::query()->where('cliente_id', $cliente->id);

        $qtdPedidos = (clone $pedidosBase)->count();
        $volumeTotal = (float) (clone $pedidosBase)->sum('valor_total');
        $ticketMedio = $qtdPedidos > 0 ? round($volumeTotal / $qtdPedidos, 2) : 0.0;

        $ultimaFaturamento = (clone $pedidosBase)->whereNotNull('data_faturamento')->max('data_faturamento');
        $ultimaCompraFormatada = optional($cliente->data_ultima_compra)->format('d/m/Y')
            ?? ($ultimaFaturamento ? \Illuminate\Support\Carbon::parse($ultimaFaturamento)->format('d/m/Y') : null);

        $pedidos = Pedido::query()
            ->where('cliente_id', $cliente->id)
            // Os itens vêm junto (uma query a mais pra página inteira, não uma por
            // pedido) porque a linha é expansível. São só 20 pedidos por página —
            // o cliente com mais pedidos da base tem 32 no total.
            ->with('itens:id,pedido_id,cod_produto,descricao,quantidade,quantidade_liberada,valor_unitario,valor_total')
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
                'itens' => $p->itens->map(fn ($i) => [
                    'id' => $i->id,
                    'codProduto' => $i->cod_produto,
                    'descricao' => $i->descricao,
                    'quantidade' => (float) $i->quantidade,
                    'quantidadeLiberada' => $i->quantidade_liberada !== null ? (float) $i->quantidade_liberada : null,
                    'valorUnitario' => (float) $i->valor_unitario,
                    'valorTotal' => (float) $i->valor_total,
                ])->all(),
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
                'segmento' => $cliente->cod_segmento ? (Segmento::where('codigo', $cliente->cod_segmento)->value('nome') ?? $cliente->cod_segmento) : null,
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
        $this->autorizarCliente($request, $cliente);

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
        $this->autorizarCliente($request, $cliente);

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
        $this->autorizarCliente($request, $cliente);

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
        $agendamento->load('cliente');
        if ($agendamento->cliente) {
            $this->autorizarCliente($request, $agendamento->cliente);
        }

        $data = $request->validate([
            'status' => ['required', 'in:agendado,realizado,cancelado'],
        ]);

        $agendamento->update(['status' => $data['status']]);

        return back();
    }

    private function autorizarCliente(Request $request, Cliente $cliente): void
    {
        $scope = $this->scopeResolver->resolve($request->user(), null, null);

        if ($scope['codVendedores'] !== null && ! in_array($cliente->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }
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
