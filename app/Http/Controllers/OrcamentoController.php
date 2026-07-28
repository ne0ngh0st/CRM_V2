<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\EtiquetaMateriaPrima;
use App\Models\Lead;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\Produto;
use App\Models\User;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Orcamento\NivelAprovacaoCalculator;
use App\Services\Orcamento\OrcamentoCalculoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OrcamentoController extends Controller
{
    private const ORDEM_NIVEL = ['nenhum' => 0, 'supervisor' => 1, 'diretor' => 2];

    private const OUTRAS_INFORMACOES_PADRAO = [
        'variacao_producao_personalizado' => '(+ ou - 10% da quantidade produzida)',
        'prazo_producao' => '10 dias após a aprovação do LAYOUT',
        'garantia_imagem' => '5 anos',
        'texto_importante' => 'Os preços deste orçamento têm validade de 5 dias a partir da data de emissão.',
    ];

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly NivelAprovacaoCalculator $calculator,
        private readonly OrcamentoCalculoService $calculoService,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        if ($role === 'assistente') {
            return redirect()->route('dashboard');
        }

        $visaoSupervisor = $request->string('visao_supervisor')->value() ?: null;
        $visaoVendedor = $request->string('visao_vendedor')->value() ?: null;

        $scope = $this->scopeResolver->resolve($user, $visaoSupervisor, $visaoVendedor);
        $semFiltro = in_array($role, ['admin', 'diretor'], true) && $scope['codVendedores'] === null;
        $usuarioIds = $this->scopeResolver->usuarioIds($user, $scope);

        $busca = trim((string) $request->string('busca'));
        $status = (string) $request->string('status');
        $nivel = (string) $request->string('nivel');
        $dataInicio = (string) $request->string('data_inicio');
        $dataFim = (string) $request->string('data_fim');

        $baseQuery = function () use ($semFiltro, $usuarioIds, $busca, $status, $nivel, $dataInicio, $dataFim) {
            $query = Orcamento::query();

            if (! $semFiltro) {
                $query->whereIn('user_id', $usuarioIds);
            }

            if ($busca !== '') {
                $query->where(function ($q) use ($busca) {
                    $q->where('cliente_nome', 'like', "%{$busca}%")
                        ->orWhere('cliente_cnpj', 'like', "%{$busca}%");
                });
            }

            if ($status !== '') {
                $query->where('status_gestor', $status);
            }

            if ($nivel !== '') {
                $query->where('nivel_aprovacao', $nivel);
            }

            if ($dataInicio !== '') {
                $query->whereDate('created_at', '>=', $dataInicio);
            }

            if ($dataFim !== '') {
                $query->whereDate('created_at', '<=', $dataFim);
            }

            return $query;
        };

        $kpis = [
            'total' => (clone $baseQuery())->count(),
            'valorTotal' => (float) (clone $baseQuery())->sum('valor_total'),
            'aguardandoSupervisor' => (clone $baseQuery())->where('status_gestor', 'pendente')->where('nivel_aprovacao', 'supervisor')->count(),
            'aguardandoDiretor' => (clone $baseQuery())->where('status_gestor', 'pendente')->where('nivel_aprovacao', 'diretor')->count(),
            'aprovados' => (clone $baseQuery())->where('status_gestor', 'aprovado')->count(),
            'valorAprovado' => (float) (clone $baseQuery())->where('status_gestor', 'aprovado')->sum('valor_total'),
            'rejeitados' => (clone $baseQuery())->where('status_gestor', 'rejeitado')->count(),
        ];

        $orcamentos = $baseQuery()
            ->with(['user:id,name,display_name', 'aprovadoPor:id,name,display_name', 'itens'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $orcamentos->through(fn (Orcamento $o) => [
            'id' => $o->id,
            'clienteNome' => $o->cliente_nome,
            'clienteCnpj' => $o->cliente_cnpj,
            'clienteContato' => $o->cliente_contato,
            'vendedorNome' => $o->user->display_name ?: $o->user->name,
            'formaPagamento' => $o->forma_pagamento,
            'tipoProdutoServico' => $o->tipo_produto_servico,
            'valorTotal' => (float) $o->valor_total,
            'dataValidade' => optional($o->data_validade)->format('Y-m-d'),
            'dataValidadeFormatada' => optional($o->data_validade)->format('d/m/Y'),
            'validadeSituacao' => $this->classificarValidade($o->data_validade),
            'descontoPctMax' => (float) $o->desconto_pct_max,
            'nivelAprovacao' => $o->nivel_aprovacao,
            'statusGestor' => $o->status_gestor,
            'aprovadoPorNome' => $o->aprovadoPor ? ($o->aprovadoPor->display_name ?: $o->aprovadoPor->name) : null,
            'aprovadoEm' => optional($o->aprovado_em)->format('d/m/Y H:i'),
            'motivoRejeicao' => $o->motivo_rejeicao,
            'criadoEm' => $o->created_at->format('d/m/Y'),
            'podeDecidir' => $o->status_gestor === 'pendente' && $this->podeDecidir($user, $o->nivel_aprovacao),
            'podeEditar' => $o->user_id === $user->id || in_array($role, ['admin', 'diretor'], true),
            'itens' => $o->itens->map(fn (OrcamentoItem $i) => [
                'id' => $i->id,
                'tipoItem' => $i->tipo_item,
                'codProduto' => $i->cod_produto,
                'descricao' => $i->descricao,
                'quantidade' => (float) $i->quantidade,
                'valorUnitario' => (float) $i->valor_unitario,
                'valorTotal' => (float) $i->valor_total,
                'precoTabela' => $i->preco_tabela !== null ? (float) $i->preco_tabela : null,
            ])->values(),
        ]);

        return Inertia::render('Orcamentos/Index', [
            'role' => $role,
            'podeExcluir' => in_array($role, ['admin', 'diretor'], true),
            'orcamentos' => $orcamentos,
            'kpis' => $kpis,
            'filtros' => [
                'busca' => $busca,
                'status' => $status,
                'nivel' => $nivel,
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
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

    public function novo(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();
        $isAdmin = $role === 'admin';

        $prefillCliente = null;
        if ($request->filled('cliente_nome')) {
            $prefillCliente = [
                'nome' => (string) $request->string('cliente_nome'),
                'cnpj' => (string) $request->string('cliente_cnpj'),
                'contato' => (string) $request->string('cliente_contato'),
            ];
        }

        $copiaDe = null;
        if ($request->filled('copiar_de')) {
            $origem = Orcamento::query()->with('itens')->findOrFail($request->integer('copiar_de'));
            abort_unless($this->podeVisualizar($user, $origem), 403);

            $copiaDe = $this->mapOrcamentoParaForm($origem, $isAdmin);
            // Cópia é sempre um documento novo: sem id, e validade recalculada a partir de hoje.
            unset($copiaDe['id']);
            $copiaDe['dataValidade'] = now()->addDays(5)->toDateString();
        }

        return Inertia::render('Orcamentos/Form', [
            'role' => $role,
            'isAdmin' => $isAdmin,
            'orcamento' => null,
            'prefillCliente' => $prefillCliente,
            'copiaDe' => $copiaDe,
            'materiasPrimas' => $isAdmin ? $this->materiasPrimasParaSelect() : [],
            'outrasInformacoesPadrao' => self::OUTRAS_INFORMACOES_PADRAO,
        ]);
    }

    public function editar(Request $request, Orcamento $orcamento): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();
        $isAdmin = $role === 'admin';

        abort_unless($orcamento->user_id === $user->id || in_array($role, ['admin', 'diretor'], true), 403);

        $orcamento->load('itens');

        return Inertia::render('Orcamentos/Form', [
            'role' => $role,
            'isAdmin' => $isAdmin,
            'orcamento' => $this->mapOrcamentoParaForm($orcamento, $isAdmin),
            'prefillCliente' => null,
            'copiaDe' => null,
            'materiasPrimas' => $isAdmin ? $this->materiasPrimasParaSelect() : [],
            'outrasInformacoesPadrao' => self::OUTRAS_INFORMACOES_PADRAO,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->sanitizarEtiquetaCalcParaNaoAdmin($request);
        $data = $this->validarOrcamento($request);

        DB::transaction(function () use ($request, $data) {
            $orcamento = Orcamento::create([
                'user_id' => $request->user()->id,
                'cliente_nome' => $data['cliente_nome'],
                'cliente_cnpj' => $data['cliente_cnpj'] ?? null,
                'cliente_contato' => $data['cliente_contato'] ?? null,
                'forma_pagamento' => $data['forma_pagamento'] ?? null,
                'tipo_produto_servico' => $data['tipo_produto_servico'],
                'valor_total' => 0,
                'data_validade' => $data['data_validade'] ?? null,
                'desconto_pct_max' => 0,
                'nivel_aprovacao' => 'nenhum',
                'status_gestor' => 'pendente',
                'observacoes' => $data['observacoes'] ?? null,
                'variacao_producao_personalizado' => $data['variacao_producao_personalizado'] ?? null,
                'prazo_producao' => $data['prazo_producao'] ?? null,
                'garantia_imagem' => $data['garantia_imagem'] ?? null,
                'texto_importante' => $data['texto_importante'] ?? null,
            ]);

            $this->salvarItens($orcamento, $data['itens']);
            $this->recalcularAprovacao($orcamento, novo: true);
        });

        return redirect()->route('orcamentos.index');
    }

    public function update(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $user = $request->user();
        abort_unless($orcamento->user_id === $user->id || in_array($user->getRoleNames()->first(), ['admin', 'diretor'], true), 403);

        $this->sanitizarEtiquetaCalcParaNaoAdmin($request);
        $data = $this->validarOrcamento($request);

        DB::transaction(function () use ($orcamento, $data) {
            $orcamento->update([
                'cliente_nome' => $data['cliente_nome'],
                'cliente_cnpj' => $data['cliente_cnpj'] ?? null,
                'cliente_contato' => $data['cliente_contato'] ?? null,
                'forma_pagamento' => $data['forma_pagamento'] ?? null,
                'tipo_produto_servico' => $data['tipo_produto_servico'],
                'data_validade' => $data['data_validade'] ?? null,
                'observacoes' => $data['observacoes'] ?? null,
                'variacao_producao_personalizado' => $data['variacao_producao_personalizado'] ?? null,
                'prazo_producao' => $data['prazo_producao'] ?? null,
                'garantia_imagem' => $data['garantia_imagem'] ?? null,
                'texto_importante' => $data['texto_importante'] ?? null,
            ]);

            $this->salvarItens($orcamento, $data['itens']);
            $this->recalcularAprovacao($orcamento, novo: false);
        });

        return redirect()->route('orcamentos.index');
    }

    public function aprovar(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->podeDecidir($user, $orcamento->nivel_aprovacao), 403);
        abort_unless($orcamento->status_gestor === 'pendente', 409);

        $orcamento->update([
            'status_gestor' => 'aprovado',
            'aprovado_por_id' => $user->id,
            'aprovado_em' => now(),
            'motivo_rejeicao' => null,
        ]);

        return back();
    }

    public function rejeitar(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->podeDecidir($user, $orcamento->nivel_aprovacao), 403);
        abort_unless($orcamento->status_gestor === 'pendente', 409);

        $data = $request->validate([
            'motivo_rejeicao' => ['required', 'string', 'max:1000'],
        ]);

        $orcamento->update([
            'status_gestor' => 'rejeitado',
            'aprovado_por_id' => $user->id,
            'aprovado_em' => now(),
            'motivo_rejeicao' => $data['motivo_rejeicao'],
        ]);

        return back();
    }

    public function destroy(Request $request, Orcamento $orcamento): RedirectResponse
    {
        abort_unless(in_array($request->user()->getRoleNames()->first(), ['admin', 'diretor'], true), 403);

        $orcamento->delete();

        return back();
    }

    public function pdf(Request $request, Orcamento $orcamento): HttpResponse
    {
        abort_unless($this->podeVisualizar($request->user(), $orcamento), 403);

        $orcamento->load(['itens', 'user', 'aprovadoPor']);

        $itensCalculados = $orcamento->itens
            ->map(fn (OrcamentoItem $i) => $this->calculoService->calcularItem([
                'tipo_item' => $i->tipo_item,
                'valor_unitario' => (float) $i->valor_unitario,
                'valor_total' => (float) $i->valor_total,
                'calcula_ipi' => $i->calcula_ipi,
            ], $orcamento->tipo_produto_servico) + [
                'descricao' => $i->descricao,
                'codProduto' => $i->cod_produto,
                'quantidade' => (float) $i->quantidade,
            ])
            ->values();

        $resumo = $this->calculoService->resumo($itensCalculados);

        $pdf = Pdf::loadView('orcamentos.pdf', [
            'orcamento' => $orcamento,
            'itensCalculados' => $itensCalculados,
            'resumo' => $resumo,
        ]);
        $nomeArquivo = "orcamento-{$orcamento->id}.pdf";

        return $request->boolean('download')
            ? $pdf->download($nomeArquivo)
            : $pdf->stream($nomeArquivo);
    }

    public function buscarClientes(Request $request): JsonResponse
    {
        $q = trim((string) $request->string('q'));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $clientes = Cliente::query()
            ->where(function ($query) use ($q) {
                $query->where('cnpj', 'like', "%{$q}%")
                    ->orWhere('razao_social', 'like', "%{$q}%")
                    ->orWhere('nome_fantasia', 'like', "%{$q}%");
            })
            ->limit(10)
            ->get()
            ->map(fn (Cliente $c) => [
                'origem' => 'cliente',
                'nome' => $c->razao_social,
                'cnpj' => $c->cnpj,
                'telefone' => $c->telefone,
                'email' => $c->email,
                'estado' => $c->estado,
                'cep' => $c->cep,
            ]);

        $leads = Lead::query()
            ->visivel()
            ->where(function ($query) use ($q) {
                $query->where('cnpj', 'like', "%{$q}%")
                    ->orWhere('razao_social', 'like', "%{$q}%")
                    ->orWhere('nome_fantasia', 'like', "%{$q}%");
            })
            ->limit(10)
            ->get()
            ->map(fn (Lead $l) => [
                'origem' => 'lead',
                'nome' => $l->razao_social ?: $l->nome,
                'cnpj' => $l->cnpj,
                'telefone' => $l->telefone,
                'email' => $l->email,
                'estado' => $l->estado,
                'cep' => null,
            ]);

        return response()->json($clientes->concat($leads)->take(10)->values());
    }

    public function buscarProdutos(Request $request): JsonResponse
    {
        $q = trim((string) $request->string('q'));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $produtos = Produto::query()
            ->where(function ($query) use ($q) {
                $query->where('cod_produto', 'like', "%{$q}%")
                    ->orWhere('descricao', 'like', "%{$q}%");
            })
            ->limit(15)
            ->get(['cod_produto', 'descricao', 'categoria', 'preco_tabela'])
            ->map(fn (Produto $p) => [
                'codProduto' => $p->cod_produto,
                'descricao' => $p->descricao,
                'categoria' => $p->categoria,
                'precoTabela' => $p->preco_tabela !== null ? (float) $p->preco_tabela : null,
            ]);

        return response()->json($produtos);
    }

    /** @return array{cliente_nome: string, cliente_cnpj: ?string, cliente_contato: ?string, forma_pagamento: ?string, tipo_produto_servico: string, data_validade: ?string, observacoes: ?string, variacao_producao_personalizado: ?string, prazo_producao: ?string, garantia_imagem: ?string, texto_importante: ?string, itens: array} */
    private function validarOrcamento(Request $request): array
    {
        return $request->validate([
            'cliente_nome' => ['required', 'string', 'max:255'],
            'cliente_cnpj' => ['nullable', 'string', 'max:18'],
            'cliente_contato' => ['nullable', 'string', 'max:255'],
            'forma_pagamento' => ['nullable', 'string', 'max:50'],
            'tipo_produto_servico' => ['required', Rule::in(['produto', 'servico'])],
            'data_validade' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string'],
            'variacao_producao_personalizado' => ['nullable', 'string', 'max:2000'],
            'prazo_producao' => ['nullable', 'string', 'max:255'],
            'garantia_imagem' => ['nullable', 'string', 'max:255'],
            'texto_importante' => ['nullable', 'string'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.tipo_item' => ['required', Rule::in(['bobina', 'etiqueta', 'outro'])],
            'itens.*.cod_produto' => ['nullable', 'string', 'max:100'],
            'itens.*.descricao' => ['required', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'itens.*.preco_tabela' => ['nullable', 'numeric', 'min:0'],
            'itens.*.calcula_ipi' => ['nullable', 'boolean'],
            'itens.*.materia_prima_id' => ['nullable', 'integer', 'exists:etiquetas_materia_prima,id'],
            'itens.*.etiqueta_calc' => ['nullable', 'array'],
        ]);
    }

    /**
     * Remove itens.*.etiqueta_calc do payload antes de validar/persistir quando quem
     * está enviando não é admin — os dados de custo/margem da calculadora de etiqueta
     * nunca devem ser aceitos de um perfil que não tem acesso à calculadora.
     */
    private function sanitizarEtiquetaCalcParaNaoAdmin(Request $request): void
    {
        if ($request->user()->getRoleNames()->first() === 'admin') {
            return;
        }

        $itens = collect($request->input('itens', []))
            ->map(fn ($item) => Arr::except($item, 'etiqueta_calc'))
            ->all();

        $request->merge(['itens' => $itens]);
    }

    /** @param array<int, array{tipo_item: string, cod_produto: ?string, descricao: string, quantidade: float|string, valor_unitario: float|string, preco_tabela: float|string|null, calcula_ipi?: bool|int|null, materia_prima_id?: ?int, etiqueta_calc?: ?array}> $itens */
    private function salvarItens(Orcamento $orcamento, array $itens): void
    {
        $orcamento->itens()->delete();

        $valorTotal = 0;
        foreach ($itens as $item) {
            $quantidade = (float) $item['quantidade'];
            $valorUnitario = (float) $item['valor_unitario'];
            $valorItemTotal = round($quantidade * $valorUnitario, 2);
            $valorTotal += $valorItemTotal;

            $tipoItem = $item['tipo_item'];
            // Etiqueta nunca incide IPI, independente do que foi enviado.
            $calculaIpi = $tipoItem === 'etiqueta' ? false : (bool) ($item['calcula_ipi'] ?? true);

            OrcamentoItem::create([
                'orcamento_id' => $orcamento->id,
                'tipo_item' => $tipoItem,
                'cod_produto' => $item['cod_produto'] ?? null,
                'descricao' => $item['descricao'],
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'valor_total' => $valorItemTotal,
                'preco_tabela' => $item['preco_tabela'] ?? null,
                'calcula_ipi' => $calculaIpi,
                'etiqueta_calc' => $item['etiqueta_calc'] ?? null,
                'materia_prima_id' => $item['materia_prima_id'] ?? null,
            ]);
        }

        $orcamento->update(['valor_total' => $valorTotal]);
    }

    private function recalcularAprovacao(Orcamento $orcamento, bool $novo): void
    {
        $tipoProdutoServico = $orcamento->tipo_produto_servico;

        $itens = $orcamento->itens()->get(['tipo_item', 'valor_unitario', 'preco_tabela', 'calcula_ipi'])
            ->map(fn (OrcamentoItem $i) => [
                'valor_unitario' => $this->calculoService->baseParaDesconto($tipoProdutoServico, [
                    'valor_unitario' => (float) $i->valor_unitario,
                    'tipo_item' => $i->tipo_item,
                    'calcula_ipi' => $i->calcula_ipi,
                ]),
                'preco_tabela' => $i->preco_tabela,
            ]);

        $nivelAntigo = $orcamento->nivel_aprovacao;
        $statusAntigo = $orcamento->status_gestor;
        $novoNivel = $this->calculator->calcular($itens);
        $descontoMax = $this->calculator->maiorDesconto($itens);

        $update = [
            'desconto_pct_max' => $descontoMax,
            'nivel_aprovacao' => $novoNivel,
        ];

        if (! $novo && $statusAntigo === 'rejeitado') {
            // Uma rejeição já registrada nunca é reaberta automaticamente por uma edição.
        } elseif ($novoNivel === 'nenhum') {
            $update['status_gestor'] = 'aprovado';
            $update['aprovado_por_id'] = null;
            $update['aprovado_em'] = now();
        } elseif ($novo) {
            $update['status_gestor'] = 'pendente';
        } elseif ($statusAntigo === 'aprovado' && self::ORDEM_NIVEL[$novoNivel] > self::ORDEM_NIVEL[$nivelAntigo]) {
            $update['status_gestor'] = 'pendente';
            $update['aprovado_por_id'] = null;
            $update['aprovado_em'] = null;
        }

        $orcamento->update($update);
    }

    private function podeDecidir(User $user, string $nivel): bool
    {
        $role = $user->getRoleNames()->first();

        if ($nivel === 'diretor') {
            return in_array($role, ['admin', 'diretor'], true);
        }

        return in_array($role, ['admin', 'diretor', 'supervisor'], true);
    }

    private function podeVisualizar(User $user, Orcamento $orcamento): bool
    {
        $role = $user->getRoleNames()->first();
        $scope = $this->scopeResolver->resolve($user, null, null);

        if (in_array($role, ['admin', 'diretor'], true) && $scope['codVendedores'] === null) {
            return true;
        }

        $usuarioIds = $this->scopeResolver->usuarioIds($user, $scope);

        return in_array($orcamento->user_id, $usuarioIds, true);
    }

    private function classificarValidade(?Carbon $dataValidade): string
    {
        if (! $dataValidade) {
            return 'sem_validade';
        }

        $hoje = now()->toDateString();
        $em7Dias = now()->addDays(7)->toDateString();
        $data = $dataValidade->toDateString();

        return match (true) {
            $data < $hoje => 'vencido',
            $data <= $em7Dias => 'proximo',
            default => 'no_prazo',
        };
    }

    /** @return array{id: int, statusGestor: string, clienteNome: string, clienteCnpj: ?string, clienteContato: ?string, formaPagamento: ?string, tipoProdutoServico: string, dataValidade: ?string, observacoes: ?string, variacaoProducaoPersonalizado: ?string, prazoProducao: ?string, garantiaImagem: ?string, textoImportante: ?string, itens: array} */
    private function mapOrcamentoParaForm(Orcamento $orcamento, bool $isAdmin): array
    {
        return [
            'id' => $orcamento->id,
            'statusGestor' => $orcamento->status_gestor,
            'clienteNome' => $orcamento->cliente_nome,
            'clienteCnpj' => $orcamento->cliente_cnpj,
            'clienteContato' => $orcamento->cliente_contato,
            'formaPagamento' => $orcamento->forma_pagamento,
            'tipoProdutoServico' => $orcamento->tipo_produto_servico,
            'dataValidade' => optional($orcamento->data_validade)->format('Y-m-d'),
            'observacoes' => $orcamento->observacoes,
            'variacaoProducaoPersonalizado' => $orcamento->variacao_producao_personalizado,
            'prazoProducao' => $orcamento->prazo_producao,
            'garantiaImagem' => $orcamento->garantia_imagem,
            'textoImportante' => $orcamento->texto_importante,
            'itens' => $orcamento->itens->map(fn (OrcamentoItem $i) => Arr::except([
                'id' => $i->id,
                'tipoItem' => $i->tipo_item,
                'codProduto' => $i->cod_produto,
                'descricao' => $i->descricao,
                'quantidade' => (float) $i->quantidade,
                'valorUnitario' => (float) $i->valor_unitario,
                'precoTabela' => $i->preco_tabela !== null ? (float) $i->preco_tabela : null,
                'calculaIpi' => (bool) $i->calcula_ipi,
                'etiquetaCalc' => $i->etiqueta_calc,
                'materiaPrimaId' => $i->materia_prima_id,
            ], $isAdmin ? [] : ['etiquetaCalc', 'materiaPrimaId']))->values(),
        ];
    }

    /** @return array<int, array{id: int, descMp: string, categoria: ?string, fabricante: ?string, precoM2: float}> */
    private function materiasPrimasParaSelect(): array
    {
        return EtiquetaMateriaPrima::query()
            ->ativa()
            ->orderBy('desc_mp')
            ->get()
            ->map(fn ($mp) => [
                'id' => $mp->id,
                'descMp' => $mp->desc_mp,
                'categoria' => $mp->categoria,
                'fabricante' => $mp->fabricante,
                'precoM2' => (float) $mp->preco_m2,
            ])
            ->values()
            ->all();
    }
}
