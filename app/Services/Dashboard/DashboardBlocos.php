<?php

namespace App\Services\Dashboard;

use App\Models\Cliente;
use App\Models\DataSyncStatus;
use App\Models\Faturamento;
use App\Models\Ligacao;
use App\Models\Observacao;
use App\Models\Orcamento;
use App\Models\Pedido;
use App\Services\Cache\CacheDeAgregacao;
use App\Services\Cache\ChaveEscopo;
use App\Services\Carteira\CarteiraAderenciaResolver;
use App\Services\Metas\MetaRankingResolver;
use Closure;

/**
 * Os blocos de dados do Dashboard, cada um com sua própria chave de cache.
 *
 * ⚠️ ESTA CLASSE É O QUE TORNA O CACHE WARMING CONFIÁVEL.
 * O job de aquecimento (Fase 3) chama exatamente os mesmos métodos daqui, só que numa
 * instância `comRecalculoForcado()`. Como chave e cálculo vivem no mesmo lugar, é
 * impossível o job gravar numa chave diferente da que o controller lê — o modo de falha
 * mais traiçoeiro do warming (aquecer o cache errado e continuar lento, sem erro nenhum)
 * deixa de existir por construção, não por disciplina.
 *
 * Antes disso, estes métodos eram privados do DashboardController e montavam as chaves
 * à mão, de três jeitos ligeiramente diferentes.
 */
class DashboardBlocos
{
    /** Quando true, todo bloco recalcula e sobrescreve o cache em vez de ler. */
    private bool $forcarRecalculo = false;

    public function __construct(
        private readonly CacheDeAgregacao $cache,
        private readonly CarteiraAderenciaResolver $aderenciaResolver,
        private readonly MetaRankingResolver $metaRanking,
    ) {}

    /** Instância irmã que sempre recalcula. Usada só pelo job de warming e pelo comando. */
    public function comRecalculoForcado(): self
    {
        $clone = clone $this;
        $clone->forcarRecalculo = true;

        return $clone;
    }

    // ── Blocos SEM cache ────────────────────────────────────────────────────────
    //
    // Decisão consciente, não esquecimento: são baratos e são os mais sensíveis a dado
    // velho. `statusSistema` é uma query numa tabela minúscula e alimenta justamente a
    // pill de "dados atualizados/desatualizados" — cachear o rótulo por 30 minutos o
    // tornaria mentiroso. `ligacoesStats` (1 query agregada) e `observacoesStats`
    // (3 queries) refletem o que o próprio usuário acabou de registrar: se ele salva uma
    // observação e o número não muda, ele não conclui "o cache está velho", conclui "o
    // sistema não salvou". Ver docs/performance.md, Parte 4.

    public function statusSistema(): array
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

    /** @param array<int> $usuarioIds */
    public function ligacoesStats(array $usuarioIds): array
    {
        // Ligação no v2 é só contagem — sem roteiro de perguntas (decisão do Tony,
        // 2026-08-10). Agrega no banco em vez de trazer as linhas.
        $contagem = Ligacao::query()
            ->whereIn('usuario_id', $usuarioIds)
            ->whereBetween('data_ligacao', [now()->startOfMonth(), now()->endOfMonth()])
            ->where('status', '!=', 'excluida')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status = 'finalizada') as finalizadas")
            ->selectRaw("SUM(status = 'cancelada') as canceladas")
            ->first();

        return [
            'total' => (int) ($contagem->total ?? 0),
            'finalizadas' => (int) ($contagem->finalizadas ?? 0),
            'canceladas' => (int) ($contagem->canceladas ?? 0),
        ];
    }

    /** @param array<int> $usuarioIds */
    public function observacoesStats(array $usuarioIds): array
    {
        $esteMesQuery = fn () => Observacao::query()
            ->whereIn('user_id', $usuarioIds)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month);

        return [
            'hoje' => Observacao::query()
                ->whereIn('user_id', $usuarioIds)
                ->whereDate('created_at', now()->toDateString())
                ->count(),
            'esteMes' => $esteMesQuery()->count(),
            'clientesUnicos' => $esteMesQuery()->distinct('cnpj')->count('cnpj'),
        ];
    }

    // ── Blocos COM cache ────────────────────────────────────────────────────────

    /**
     * Metas do mês e do ano + volume de pedidos emitidos.
     *
     * Era o maior buraco não cacheado do Dashboard: 6+ queries agregadas sobre
     * `faturamentos`, `pedidos` e `metas_mensais`, no topo visual da página.
     *
     * `isRepresentante` NÃO entra aqui de propósito — é derivado do perfil de quem está
     * olhando, não do escopo. Incluir poria o role na chave e duplicaria o cache de dois
     * usuários que veem exatamente os mesmos números. O controller adiciona esse campo.
     *
     * @param  array<string>|null  $codVendedores
     */
    public function metaGauge(ChaveEscopo $escopo, ?array $codVendedores): array
    {
        $ano = (int) now()->year;
        $mes = (int) now()->month;

        return $this->cachear(
            $escopo->para('meta-gauge', ['ano' => $ano, 'mes' => $mes]),
            fn () => [
                'mes' => $this->metaRanking->metaVsFaturamento($codVendedores, $ano, $mes, $mes),
                'ano' => $this->metaRanking->metaVsFaturamento($codVendedores, $ano, 1, $mes),
                'pedidosEmitidos' => $this->pedidosEmitidos($codVendedores, $ano, $mes),
            ],
        );
    }

    /**
     * ⚠️ `BETWEEN` em vez de `whereYear()` é a higiene correta de SQL, mas não resolve
     * sozinho o caso "empresa inteira": hoje 100% do faturamento importado é de um único
     * ano, então nenhum índice reduz as linhas lidas — a soma passa pelas ~930k linhas de
     * qualquer jeito. É por isso que aqui o cache é a resposta, e não um índice novo
     * (Regra de ouro nº 6).
     *
     * @param  array<string>|null  $codVendedores
     */
    public function faturamentoComparacao(ChaveEscopo $escopo, ?array $codVendedores): array
    {
        $anoAtual = (int) now()->year;

        return $this->cachear(
            $escopo->para('faturamento-comparacao', ['ano' => $anoAtual]),
            function () use ($anoAtual, $codVendedores) {
                $porAno = function (int $ano) use ($codVendedores) {
                    $query = Faturamento::query()
                        ->selectRaw('MONTH(data_emissao) as mes, SUM(valor_total) as total')
                        ->whereBetween('data_emissao', ["{$ano}-01-01", "{$ano}-12-31"])
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
            },
        );
    }

    /**
     * Aderência da carteira por segmento. O bloco mais caro do sistema: o
     * CarteiraAderenciaResolver faz LEFT JOIN em `segmentos`/`segmentos_vendedor` sobre as
     * ~90k linhas de `clientes` — ~2 s por request no escopo admin, e o maior contribuinte
     * para o Dashboard travar sob concorrência (teste de carga de 2026-07-30).
     *
     * ⚠️ `paraDoDia`: o resolver classifica ativo/inativando/inativo por dias desde a
     * última compra (290/365), calculados a partir de `now()`. Sem a data na chave, o
     * resultado de hoje continuaria servindo amanhã com os limiares errados.
     *
     * @param  array<string>|null  $codVendedores
     */
    public function carteiraSegmento(ChaveEscopo $escopo, ?array $codVendedores): array
    {
        return $this->cachear(
            $escopo->paraDoDia('carteira-segmento'),
            function () use ($codVendedores) {
                $query = Cliente::query();

                if ($codVendedores !== null) {
                    // Qualificado: o resolver faz LEFT JOIN em segmentos_vendedor, que
                    // também tem cod_vendedor — sem o prefixo a query vira ambígua.
                    $query->whereIn('clientes.cod_vendedor', $codVendedores);
                }

                return $this->aderenciaResolver->resolver($query);
            },
        );
    }

    /** @param array<int> $usuarioIds */
    public function orcamentosStats(ChaveEscopo $escopo, array $usuarioIds): array
    {
        return $this->cachear(
            $escopo->para('orcamentos-stats'),
            function () use ($usuarioIds) {
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
            },
        );
    }

    /**
     * ⚠️ `paraDoDia`: "atrasado" e "vencendo em 7 dias" são relativos a hoje.
     *
     * @param  array<string>|null  $codVendedores
     */
    public function pedidosAtencao(ChaveEscopo $escopo, ?array $codVendedores): array
    {
        return $this->cachear(
            $escopo->paraDoDia('pedidos-atencao'),
            function () use ($codVendedores) {
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
            },
        );
    }

    // ── Interno ─────────────────────────────────────────────────────────────────

    /** Volume de pedidos emitidos (toda a tabela, aberto ou faturado) no mês e no ano. */
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

    private function cachear(string $chave, Closure $calcular): mixed
    {
        return $this->forcarRecalculo
            ? $this->cache->aquecer($chave, $calcular)
            : $this->cache->lembrar($chave, $calcular);
    }
}
