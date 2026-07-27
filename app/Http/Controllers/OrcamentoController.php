<?php

namespace App\Http\Controllers;

use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\User;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Orcamento\NivelAprovacaoCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class OrcamentoController extends Controller
{
    private const ORDEM_NIVEL = ['nenhum' => 0, 'supervisor' => 1, 'diretor' => 2];

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly NivelAprovacaoCalculator $calculator,
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

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validarOrcamento($request);

        DB::transaction(function () use ($request, $data) {
            $orcamento = Orcamento::create([
                'user_id' => $request->user()->id,
                'cliente_nome' => $data['cliente_nome'],
                'cliente_cnpj' => $data['cliente_cnpj'] ?? null,
                'cliente_contato' => $data['cliente_contato'] ?? null,
                'forma_pagamento' => $data['forma_pagamento'] ?? null,
                'valor_total' => 0,
                'data_validade' => $data['data_validade'] ?? null,
                'desconto_pct_max' => 0,
                'nivel_aprovacao' => 'nenhum',
                'status_gestor' => 'pendente',
            ]);

            $this->salvarItens($orcamento, $data['itens']);
            $this->recalcularAprovacao($orcamento, novo: true);
        });

        return back();
    }

    public function update(Request $request, Orcamento $orcamento): RedirectResponse
    {
        $user = $request->user();
        abort_unless($orcamento->user_id === $user->id || in_array($user->getRoleNames()->first(), ['admin', 'diretor'], true), 403);

        $data = $this->validarOrcamento($request);

        DB::transaction(function () use ($orcamento, $data) {
            $orcamento->update([
                'cliente_nome' => $data['cliente_nome'],
                'cliente_cnpj' => $data['cliente_cnpj'] ?? null,
                'cliente_contato' => $data['cliente_contato'] ?? null,
                'forma_pagamento' => $data['forma_pagamento'] ?? null,
                'data_validade' => $data['data_validade'] ?? null,
            ]);

            $this->salvarItens($orcamento, $data['itens']);
            $this->recalcularAprovacao($orcamento, novo: false);
        });

        return back();
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

        $pdf = Pdf::loadView('orcamentos.pdf', ['orcamento' => $orcamento]);
        $nomeArquivo = "orcamento-{$orcamento->id}.pdf";

        return $request->boolean('download')
            ? $pdf->download($nomeArquivo)
            : $pdf->stream($nomeArquivo);
    }

    /** @return array{cliente_nome: string, cliente_cnpj: ?string, cliente_contato: ?string, forma_pagamento: ?string, data_validade: ?string, itens: array} */
    private function validarOrcamento(Request $request): array
    {
        return $request->validate([
            'cliente_nome' => ['required', 'string', 'max:255'],
            'cliente_cnpj' => ['nullable', 'string', 'max:18'],
            'cliente_contato' => ['nullable', 'string', 'max:255'],
            'forma_pagamento' => ['nullable', 'string', 'max:50'],
            'data_validade' => ['nullable', 'date'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.tipo_item' => ['nullable', 'string', 'max:255'],
            'itens.*.cod_produto' => ['nullable', 'string', 'max:100'],
            'itens.*.descricao' => ['required', 'string', 'max:255'],
            'itens.*.quantidade' => ['required', 'numeric', 'min:0.01'],
            'itens.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'itens.*.preco_tabela' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /** @param array<int, array{tipo_item: ?string, cod_produto: ?string, descricao: string, quantidade: float|string, valor_unitario: float|string, preco_tabela: float|string|null}> $itens */
    private function salvarItens(Orcamento $orcamento, array $itens): void
    {
        $orcamento->itens()->delete();

        $valorTotal = 0;
        foreach ($itens as $item) {
            $quantidade = (float) $item['quantidade'];
            $valorUnitario = (float) $item['valor_unitario'];
            $valorItemTotal = round($quantidade * $valorUnitario, 2);
            $valorTotal += $valorItemTotal;

            OrcamentoItem::create([
                'orcamento_id' => $orcamento->id,
                'tipo_item' => $item['tipo_item'] ?? null,
                'cod_produto' => $item['cod_produto'] ?? null,
                'descricao' => $item['descricao'],
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'valor_total' => $valorItemTotal,
                'preco_tabela' => $item['preco_tabela'] ?? null,
            ]);
        }

        $orcamento->update(['valor_total' => $valorTotal]);
    }

    private function recalcularAprovacao(Orcamento $orcamento, bool $novo): void
    {
        $itens = $orcamento->itens()->get(['valor_unitario', 'preco_tabela'])
            ->map(fn (OrcamentoItem $i) => ['valor_unitario' => $i->valor_unitario, 'preco_tabela' => $i->preco_tabela]);

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
}
