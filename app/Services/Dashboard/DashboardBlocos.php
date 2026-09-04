<?php

namespace App\Services\Dashboard;

use App\Jobs\AquecerCacheDashboardJob;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Estado do cache warming, para a pill de fogo do Painel.
     *
     * ⚠️ Existe porque um worker morto esfria o cache EM SILÊNCIO: o sistema continua
     * correto, só volta a ficar lento, e a degradação só apareceria como reclamação de
     * usuário dias depois. O job roda a cada 10 min, então passar de 20 sem aquecer já
     * significa que alguma rodada não aconteceu.
     *
     * Não custa query: lê a marca que o próprio job grava no cache.
     */
    public function statusCache(): array
    {
        $marca = Cache::get(AquecerCacheDashboardJob::CHAVE_ULTIMO_AQUECIMENTO);

        if (! $marca) {
            // Estado normal em desenvolvimento: o scheduler só dispara com um cron real
            // chamando `schedule:run`. Por isso é 'neutro', não alarme.
            return ['status' => 'ausente', 'minutos' => null, 'em' => null];
        }

        // ⚠️ Ordem importa: no Carbon 3 (Laravel 11) diffInMinutes devolve valor COM
        // SINAL. `now()->diffInMinutes($passado)` dá negativo, e um cache velho de 100
        // minutos passaria no teste `<= 20` como se estivesse quente. O mesmo tropeço já
        // aparece resolvido com abs() no pedidosAtencao.
        $minutos = (int) Carbon::parse($marca['em'])->diffInMinutes(now());

        return [
            'status' => match (true) {
                $minutos <= 20 => 'aquecido',
                $minutos <= 40 => 'esfriando',
                default => 'frio',
            },
            'minutos' => $minutos,
            'em' => $marca['em'],
        ];
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
            ->tap(fn ($q) => Ligacao::somarPorCanal($q))
            ->first();

        return [
            'total' => (int) ($contagem->total ?? 0),
            'finalizadas' => (int) ($contagem->finalizadas ?? 0),
            'canceladas' => (int) ($contagem->canceladas ?? 0),
            'porCanal' => Ligacao::lerPorCanal($contagem),
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
     * Metas do mês e do ano, nos DOIS tipos (venda e faturamento), + volume de pedidos.
     *
     * ⚠️ VENDA vem primeiro e é a aba padrão do card, deliberadamente: venda é pedido
     * emitido — o que o vendedor consegue influenciar hoje. Faturamento é consequência e
     * chega depois. Até 2026-09-04 o gauge só sabia falar de faturamento, e o vendedor via
     * a própria performance medida por um número que ele não controla no dia.
     *
     * ⚠️ OS DOIS TIPOS VÊM JUNTOS, e o alternador do card NÃO vai ao servidor. Mesma
     * decisão do card de Comparação: buscar a outra aba no clique tornaria a troca lenta
     * justamente para quem alterna.
     *
     * ⚠️ OS CÓDIGOS DO ESCOPO SÃO RESOLVIDOS UMA VEZ AQUI, e não dentro de cada agregação.
     * Resolver `null` (empresa) custa 6 queries de roles/perfis do spatie; com quatro
     * agregações, resolver por dentro seriam 24 queries só para redescobrir a mesma lista.
     *
     * ⚠️ `pedidosEmitidos` RECEBE A MESMA LISTA RESOLVIDA, e isso MUDA O NÚMERO QUE O
     * ADMIN VÊ. Antes ele somava a tabela inteira de pedidos, inclusive códigos sem
     * usuário comercial ativo — em dev isso é 81% do valor do mês (R$ 3,99 mi → R$ 763
     * mil) e 826 pedidos de 1.171. Com a aba Venda logo acima, manter o KPI irrestrito
     * poria "Realizado R$ 763 mil" e "Valor no mês R$ 3,99 mi" a cinco centímetros de
     * distância: dois números para a mesma coisa, na mesma leitura. O universo do KPI
     * passou a ser o universo da meta, que é o mesmo de /metas.
     *
     * ⚠️ Consequência a conhecer: o KPI diverge da listagem /pedidos-emitidos, para onde
     * o próprio tile linka — lá o escopo é operacional (todo pedido do escopo bruto),
     * aqui é a equipe comercial ativa. É deliberado; se um dia isso incomodar mais que a
     * contradição interna do card, o conserto é uma linha (voltar a passar
     * `$codVendedores` cru para pedidosEmitidos).
     *
     * Custo medido em dev, recálculo completo (cache MISS), com volume real — 92 mil
     * clientes, 6,0 mi de faturamentos, 46 mil pedidos, medianas de 15 execuções
     * intercaladas com a versão antiga para cancelar deriva da máquina:
     *
     *   escopo      antes (só faturamento)   depois (dois tipos)   queries
     *   empresa            167,8 ms                149,0 ms         20 → 15
     *   equipe (51)         49,2 ms                 57,1 ms          8 →  9
     *   vendedor            11,7 ms                 17,7 ms          8 →  9
     *
     * O escopo empresa FICOU MAIS RÁPIDO mesmo dobrando as agregações, porque a resolução
     * única do escopo e a fusão das contagens de pedido economizaram mais do que a segunda
     * métrica custou. Nada disso está no caminho quente: o bloco é cacheado por 30 min e
     * pré-aquecido pelo job, então o p95 de quem abre o Painel não muda.
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
            function () use ($ano, $mes, $codVendedores) {
                $codigos = $this->metaRanking->codigosDoEscopo($codVendedores);

                $porTipo = fn (string $tipo) => [
                    'mes' => $this->metaRanking->metaVsRealizado($codigos, $ano, $mes, $mes, $tipo),
                    'ano' => $this->metaRanking->metaVsRealizado($codigos, $ano, 1, $mes, $tipo),
                ];

                $venda = $porTipo('venda');

                return [
                    'venda' => $venda,
                    'faturamento' => $porTipo('faturamento'),
                    'pedidosEmitidos' => $this->pedidosEmitidos($codigos, $ano, $mes, $venda),
                ];
            },
        );
    }

    /**
     * Comparação de VENDA (pedido emitido) ano vs. ano, mês a mês.
     *
     * "Venda" aqui é a convenção já estabelecida no projeto: toda linha de `pedidos`,
     * aberta ou faturada, datada por `data_pedido` — a mesma base de `pedidosEmitidos()`,
     * de `/pedidos-emitidos` e do `MetaRankingResolver`. Não é subconjunto do faturamento:
     * é o que foi vendido, independente de já ter virado nota.
     *
     * ⚠️ Bloco de cache PRÓPRIO (`venda-comparacao`), nunca reaproveitando a chave do
     * faturamento. Os dois blocos têm o mesmo formato de retorno, então uma chave
     * compartilhada não daria erro nenhum — só entregaria o número errado, na aba errada,
     * de forma silenciosa. É o pior tipo de bug de cache.
     *
     * ⚠️ O valor sai do CABEÇALHO (`pedidos.valor_total`), sem JOIN com `pedido_itens`.
     * Conferido no banco: a soma dos itens bate exatamente com a dos cabeçalhos
     * (R$ 114.997.228,23) e não existe pedido sem item — então o JOIN só custaria.
     *
     * ⚠️ ESTADO DO DADO, e isto muda como a aba é lida: `pedidos` só tem volume denso a
     * partir de julho/2026. O ano anterior inteiro tem 67 pedidos. O histórico de pedidos
     * emitidos ainda não foi carregado (existe no legado, em `pedidos_status`), então a
     * linha do ano anterior nasce rente ao zero. Não é bug de query — é ausência de dado
     * de origem, e o front declara isso em vez de fingir.
     *
     * @param  array<string>|null  $codVendedores
     */
    public function vendaComparacao(ChaveEscopo $escopo, ?array $codVendedores): array
    {
        $anoAtual = (int) now()->year;

        return $this->cachear(
            $escopo->paraDoDia('venda-comparacao', ['ano' => $anoAtual]),
            function () use ($anoAtual, $codVendedores) {
                $meses = fn (int $ano) => $this->somaMensal(
                    Pedido::query()->selectRaw('MONTH(data_pedido) as mes, SUM(valor_total) as total'),
                    'data_pedido',
                    $ano,
                    $codVendedores,
                );

                return [
                    'metrica' => 'venda',
                    'anoAtual' => $anoAtual,
                    'anoAnterior' => $anoAtual - 1,
                    'valoresAnoAtual' => $meses($anoAtual),
                    'valoresAnoAnterior' => $meses($anoAtual - 1),
                ];
            },
        );
    }

    /**
     * Soma por mês de um ano, com os 12 meses sempre preenchidos.
     *
     * ⚠️ Extraído porque venda e faturamento faziam a MESMA coisa em dois lugares (Regra
     * de ouro nº 8). O que muda entre eles é só a tabela e o nome da coluna de data; se a
     * regra de "mês corrente" ou o preenchimento com zero divergir entre as duas abas do
     * mesmo card, o usuário vê dois períodos diferentes lado a lado.
     *
     * ⚠️ O corte do mês corrente vem de `MetaRankingResolver::fimRealizado()` — D-1, e D-3
     * na segunda-feira. É a mesma janela de TODA métrica de pedido do projeto (o KPI
     * "Valor no mês" fica no card logo acima); usar o mês civil cheio aqui faria o gráfico
     * e o KPI discordarem na mesma tela.
     *
     * @param  array<string>|null  $codVendedores
     * @return list<float>
     */
    private function somaMensal(Builder $query, string $colunaData, int $ano, ?array $codVendedores): array
    {
        [$inicio, $fim] = $this->metaRanking->intervaloDatas($ano, 1, 12);

        $query->whereBetween($colunaData, [$inicio, $fim])->groupBy('mes');

        if ($codVendedores !== null) {
            $query->whereIn('cod_vendedor', $codVendedores);
        }

        $totaisPorMes = $query->pluck('total', 'mes');

        return collect(range(1, 12))->map(fn ($mes) => (float) ($totaisPorMes[$mes] ?? 0))->values()->all();
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

        /*
         * ⚠️ A chave virou `paraDoDia` porque a janela do mês corrente agora é D-1 —
         * ou seja, o resultado depende de HOJE. Com `para()` a chave não mudaria de um dia
         * para o outro e o gráfico ficaria congelado no recorte de ontem até o TTL expirar.
         */
        return $this->cachear(
            $escopo->paraDoDia('faturamento-comparacao', ['ano' => $anoAtual]),
            function () use ($anoAtual, $codVendedores) {
                $meses = fn (int $ano) => $this->somaMensal(
                    Faturamento::query()->selectRaw('MONTH(data_emissao) as mes, SUM(valor_total) as total'),
                    'data_emissao',
                    $ano,
                    $codVendedores,
                );

                return [
                    'metrica' => 'faturamento',
                    'anoAtual' => $anoAtual,
                    'anoAnterior' => $anoAtual - 1,
                    'valoresAnoAtual' => $meses($anoAtual),
                    'valoresAnoAnterior' => $meses($anoAtual - 1),
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

    /**
     * Volume de pedidos emitidos (aberto ou faturado) no mês e no ano.
     *
     * ⚠️ O VALOR NÃO É RECALCULADO: vem do realizado da aba Venda, que já somou
     * `pedidos.valor_total` no mesmo período e sobre os mesmos códigos. Antes eram duas
     * somas idênticas na mesma requisição — desperdício, e pior, duas chances de os
     * números divergirem na tela depois de alguém mexer só num dos lados. Aqui a
     * igualdade entre "Realizado" e "Valor no mês" é estrutural, não uma promessa de
     * comentário.
     *
     * ⚠️ AS DUAS CONTAGENS SAEM DE UMA QUERY SÓ. A janela do mês é sufixo da janela do
     * ano (mesmo fim, D-1), então `COUNT(*)` responde o ano e um `SUM(data >= início do
     * mês)` responde o mês, na mesma varredura — mesmo padrão de `Ligacao::somarPorCanal`.
     *
     * @param  list<string>  $codigos
     * @param  array{mes: array{realizado: float}, ano: array{realizado: float}}  $venda
     */
    private function pedidosEmitidos(array $codigos, int $ano, int $mes, array $venda): array
    {
        [$inicioMes] = $this->metaRanking->intervaloDatas($ano, $mes, $mes);
        [$inicioAno, $fimAno] = $this->metaRanking->intervaloDatas($ano, 1, $mes);

        $contagem = Pedido::query()
            ->whereIn('cod_vendedor', $codigos)
            ->whereBetween('data_pedido', [$inicioAno, $fimAno])
            ->selectRaw('COUNT(*) as no_ano')
            ->selectRaw('SUM(data_pedido >= ?) as no_mes', [$inicioMes])
            ->first();

        return [
            'mes' => ['pedidos' => (int) ($contagem->no_mes ?? 0), 'valor' => $venda['mes']['realizado']],
            'ano' => ['pedidos' => (int) ($contagem->no_ano ?? 0), 'valor' => $venda['ano']['realizado']],
        ];
    }

    private function cachear(string $chave, Closure $calcular): mixed
    {
        return $this->forcarRecalculo
            ? $this->cache->aquecer($chave, $calcular)
            : $this->cache->lembrar($chave, $calcular);
    }
}
