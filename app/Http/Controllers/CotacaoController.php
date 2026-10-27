<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Models\Cotacao;
use App\Models\CotacaoItem;
use App\Models\EstoqueRevenda;
use App\Models\EstoqueRevendaCarga;
use App\Services\Cotacao\CotacaoRecusada;
use App\Services\Cotacao\CotacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cotação: a pessoa sobe a lista do cliente, o motor compara com o estoque de revenda e
 * ela confere um item por vez. O que decide (perfis, custo, rótulos, memória) mora no
 * `CotacaoService`; aqui só autoriza e monta o payload.
 *
 * Acesso: gate `usar-cotacao` nas rotas, mais `podeVer()` por cotação (dono ou admin).
 */
class CotacaoController extends Controller
{
    use ExportaPlanilha;

    public function __construct(
        private readonly CotacaoService $servico,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $cotacoes = Cotacao::query()
            ->with(['user:id,name,display_name', 'cliente:id,razao_social'])
            ->when(! $user->hasRole('admin'), fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $pendentes = CotacaoItem::query()
            ->whereIn('cotacao_id', $cotacoes->pluck('id'))
            ->whereIn('status', CotacaoService::REVISAVEIS)
            ->where('pulado', false)
            ->groupBy('cotacao_id')
            ->pluck(DB::raw('COUNT(*)'), 'cotacao_id');

        $cotacoes->through(fn (Cotacao $c) => [
            'id' => $c->id,
            'arquivo' => $c->nome_arquivo,
            'cliente' => $c->cliente?->razao_social,
            'autor' => $c->user ? ($c->user->display_name ?: $c->user->name) : null,
            'status' => $c->status,
            'travou' => $c->travou(),
            'erro' => $c->erro,
            'itens' => $c->itens_total,
            'pendentes' => (int) ($pendentes[$c->id] ?? 0),
            'valor' => $c->valor_total,
            'criadaEm' => $c->created_at?->format('d/m/Y H:i'),
        ]);

        $escolherId = $request->integer('escolher');
        $escolher = Cotacao::query()
            ->where('status', Cotacao::STATUS_AGUARDANDO_ABA)
            ->when($escolherId > 0, fn ($q) => $q->whereKey($escolherId))
            ->when(! $user->hasRole('admin') || $escolherId === 0, fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')
            ->first();

        if ($escolher && ! $this->servico->podeVer($user, $escolher)) {
            $escolher = null;
        }

        return Inertia::render('Cotacoes/Index', [
            'cotacoes' => $cotacoes,
            'emAndamento' => $cotacoes->getCollection()->contains(fn ($c) => $c['status'] === Cotacao::STATUS_PROCESSANDO && ! $c['travou']),
            'estoque' => $this->resumoDoEstoque(),
            'mostraAutor' => $user->hasRole('admin'),
            'maxUploadMb' => (int) round(config('cotacao.max_upload_kb') / 1024),
            'escolher' => $escolher ? [
                'id' => $escolher->id,
                'arquivo' => $escolher->nome_arquivo,
                'abas' => $escolher->leitura['abas'] ?? [],
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'arquivo' => ['required', 'file', 'mimes:xlsx,xlsm,csv,txt', 'max:'.config('cotacao.max_upload_kb')],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
        ], [
            'arquivo.mimes' => 'Envie a lista em Excel (.xlsx, .xlsm) ou CSV.',
        ]);

        $cotacao = $this->servico->iniciar($request->user(), $request->file('arquivo'), $dados['cliente_id'] ?? null);

        if ($cotacao->status === Cotacao::STATUS_AGUARDANDO_ABA) {
            return redirect()->route('cotacoes.index', ['escolher' => $cotacao->id]);
        }

        return redirect()->route('cotacoes.show', $cotacao);
    }

    /** A pessoa marcou qual aba do Excel é a lista de itens. */
    public function escolherAba(Request $request, Cotacao $cotacao): RedirectResponse
    {
        $this->autorizar($request, $cotacao);

        $nomes = array_column($cotacao->leitura['abas'] ?? [], 'nome');
        $dados = $request->validate([
            'aba' => ['required', 'string', Rule::in($nomes)],
        ], [
            'aba.required' => 'Escolha a aba que tem a lista de itens.',
            'aba.in' => 'Escolha uma das abas do arquivo.',
        ]);

        try {
            $this->servico->confirmarAba($cotacao, $dados['aba']);
        } catch (CotacaoRecusada $e) {
            return back()->withErrors(['aba' => $e->getMessage()]);
        }

        return redirect()->route('cotacoes.show', $cotacao);
    }

    /** Resumo: quanto foi achado, quanto falta conferir, e a lista completa. */
    public function show(Request $request, Cotacao $cotacao): Response|RedirectResponse
    {
        $this->autorizar($request, $cotacao);

        if ($cotacao->status === Cotacao::STATUS_AGUARDANDO_ABA) {
            return redirect()->route('cotacoes.index', ['escolher' => $cotacao->id]);
        }
        $comCusto = $this->servico->podeVerCusto($request->user());

        $porStatus = $cotacao->itens()
            ->selectRaw('status, COUNT(*) AS n, SUM(valor_periodo) AS valor')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $resumo = [];
        foreach (CotacaoService::STATUS as $status => $rotulo) {
            if (isset($porStatus[$status])) {
                $resumo[] = $rotulo + [
                    'status' => $status,
                    'n' => (int) $porStatus[$status]->n,
                    'valor' => (float) $porStatus[$status]->valor,
                ];
            }
        }

        $filtro = $request->string('status')->toString();
        $itens = $cotacao->itens()
            ->with('decisor:id,name,display_name')
            ->when(isset(CotacaoService::STATUS[$filtro]), fn ($q) => $q->where('status', $filtro))
            ->orderByDesc('valor_periodo')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $estoque = $this->estoqueDosCodigos($itens->getCollection()->map->codigoFinal()->filter());

        $itens->through(fn (CotacaoItem $i) => [
            'id' => $i->id,
            'descricao' => $i->descricao_cliente,
            'codCliente' => $i->cod_cliente,
            'unidade' => $i->unidade,
            'qtd' => $i->qtd_periodo,
            'valor' => $i->valor_periodo,
            ...$this->statusDoItem($i),
            'score' => $i->score,
            'nosso' => ($codigo = $i->codigoFinal()) !== null
                ? $this->servico->produto($estoque[$codigo] ?? null, $codigo, $comCusto)
                : null,
            'decididoPor' => $i->decisor ? ($i->decisor->display_name ?: $i->decisor->name) : null,
            'podeDesfazer' => $i->decisao !== null,
        ]);

        $pendentes = $this->servico->pendentes($cotacao);

        return Inertia::render('Cotacoes/Show', [
            'cotacao' => $this->cabecalho($cotacao),
            'resumo' => $resumo,
            'pendentes' => [
                'n' => (clone $pendentes)->count(),
                'valor' => (float) (clone $pendentes)->sum('valor_periodo'),
            ],
            'itens' => $itens,
            'filtroStatus' => isset(CotacaoService::STATUS[$filtro]) ? $filtro : '',
            'statusOpcoes' => collect(CotacaoService::STATUS)->map(fn ($r, $s) => ['valor' => $s, 'rotulo' => $r['rotulo']])->values(),
            'comCusto' => $comCusto,
        ]);
    }

    /** Um item por vez, o de maior valor primeiro. */
    public function conferir(Request $request, Cotacao $cotacao): Response|RedirectResponse
    {
        $this->autorizar($request, $cotacao);

        if ($cotacao->status === Cotacao::STATUS_AGUARDANDO_ABA) {
            return redirect()->route('cotacoes.index', ['escolher' => $cotacao->id]);
        }

        $comCusto = $this->servico->podeVerCusto($request->user());

        $fila = $this->servico->pendentes($cotacao);
        $restantes = (clone $fila)->count();
        $item = $fila->first();

        $revisaveis = $cotacao->itens()->whereIn('status_motor', CotacaoService::REVISAVEIS)->count();

        $payload = null;
        if ($item !== null) {
            $codigos = collect([$item->codigo_sugerido, ...array_column($item->alternativas ?? [], 'codigo')])->filter();

            $payload = [
                'id' => $item->id,
                'descricao' => $item->descricao_cliente,
                'codCliente' => $item->cod_cliente,
                'subgrupo' => $item->subgrupo,
                'unidade' => $item->unidade,
                'marca' => $item->marca,
                'qtd' => $item->qtd_periodo,
                'valor' => $item->valor_periodo,
                'ordens' => $item->ordens,
                ...$this->statusDoItem($item),
                'score' => $item->score,
                'motivos' => $item->motivos,
                'sugerido' => $item->codigo_sugerido,
                'candidatos' => $this->servico->candidatos($item, $this->estoqueDosCodigos($codigos), $comCusto),
            ];
        }

        return Inertia::render('Cotacoes/Conferir', [
            'cotacao' => $this->cabecalho($cotacao),
            'item' => $payload,
            'restantes' => $restantes,
            'feitos' => max(0, $revisaveis - $restantes),
            'revisaveis' => $revisaveis,
            'comCusto' => $comCusto,
        ]);
    }

    /**
     * Grava a conferência de um item. `acao`: escolher (com `codigo`), nao_temos ou pular.
     * Responde JSON: a tela recarrega só o próximo item, sem perder o lugar.
     */
    public function decidir(Request $request, CotacaoItem $item): JsonResponse
    {
        $this->autorizar($request, $item->cotacao);

        $dados = $request->validate([
            'acao' => ['required', 'in:escolher,nao_temos,pular'],
            'codigo' => ['required_if:acao,escolher', 'nullable', 'string', 'max:40'],
        ]);

        try {
            match ($dados['acao']) {
                'escolher' => $this->servico->decidir($item, $request->user(), $dados['codigo']),
                'nao_temos' => $this->servico->decidir($item, $request->user(), null),
                'pular' => $this->servico->pular($item),
            };
        } catch (CotacaoRecusada $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function esquecer(Request $request, CotacaoItem $item): RedirectResponse
    {
        $this->autorizar($request, $item->cotacao);
        abort_if($item->decisao === null, 422, 'Este item ainda não foi conferido.');

        $this->servico->esquecer($item, $request->user());

        return back();
    }

    /** Busca no estoque de revenda, para escolher um produto que o motor não sugeriu. */
    public function buscarProduto(Request $request): JsonResponse
    {
        $termo = trim($request->string('q')->toString());

        if (mb_strlen($termo) < 2) {
            return response()->json([]);
        }

        $comCusto = $this->servico->podeVerCusto($request->user());
        $palavras = array_slice(preg_split('/\s+/', $termo), 0, 5);

        $achados = EstoqueRevenda::query()
            ->where(function ($q) use ($termo, $palavras) {
                $q->where('codigo', $termo)
                    ->orWhere(function ($q) use ($palavras) {
                        foreach ($palavras as $p) {
                            $q->where('descricao', 'like', '%'.addcslashes($p, '%_\\').'%');
                        }
                    });
            })
            ->orderByDesc('disponivel')
            ->limit(20)
            ->get();

        return response()->json($achados->map(fn (EstoqueRevenda $e) => $this->servico->produto($e, $e->codigo, $comCusto))->values());
    }

    public function exportar(Request $request): RedirectResponse
    {
        return $this->entregarPlanilha('cotacao', $request);
    }

    // ------------------------------------------------------------------ apoio

    private function autorizar(Request $request, ?Cotacao $cotacao): void
    {
        abort_unless($cotacao !== null && $this->servico->podeVer($request->user(), $cotacao), 404);
    }

    private function cabecalho(Cotacao $cotacao): array
    {
        $cotacao->loadMissing('cliente:id,razao_social');

        return [
            'id' => $cotacao->id,
            'arquivo' => $cotacao->nome_arquivo,
            'cliente' => $cotacao->cliente?->razao_social,
            'status' => $cotacao->status,
            'travou' => $cotacao->travou(),
            'erro' => $cotacao->erro,
            'itens' => $cotacao->itens_total,
            'valor' => $cotacao->valor_total,
            'avisos' => $cotacao->leitura['avisos'] ?? [],
            'estoqueEm' => $cotacao->estoque_em?->format('d/m/Y'),
            'criadaEm' => $cotacao->created_at?->format('d/m/Y H:i'),
        ];
    }

    private function statusDoItem(CotacaoItem $item): array
    {
        $rotulo = CotacaoService::rotulo($item->status);

        return [
            'status' => $item->status,
            'statusRotulo' => $rotulo['rotulo'],
            'statusTom' => $rotulo['tom'],
        ];
    }

    private function estoqueDosCodigos($codigos)
    {
        return EstoqueRevenda::query()->whereIn('codigo', collect($codigos)->unique()->values())->get()->keyBy('codigo');
    }

    /** Idade do retrato do estoque: a tela avisa quando o Relatório de Revenda parou de chegar. */
    private function resumoDoEstoque(): array
    {
        // A data é a do RV (nome do arquivo), não a da importação: é ela que diz quão
        // velho está o estoque que a pessoa vai cotar.
        $carga = EstoqueRevendaCarga::atual();

        return [
            'itens' => $carga?->itens ?? 0,
            'em' => $carga?->data_rv?->format('d/m/Y'),
            'desatualizado' => $carga === null || $carga->data_rv->lt(now()->startOfDay()->subWeekdays(2)),
        ];
    }
}
