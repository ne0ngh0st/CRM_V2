<?php

namespace App\Services\VisaoDiretor;

use App\Models\ContaEstrategica;
use App\Models\Cliente;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Os números da página "Maiores por Segmento" (Visão Diretor).
 *
 * A conta-alvo é digitada (nome, UF, filiais de mercado, site, observação); TODO o resto é
 * derivado dos vínculos, via `ClientesDaConta` — a mesma definição que a Carteira usa no
 * `?conta_alvo=`. É isso que faz "Nossas lojas" bater com a lista que o clique abre.
 *
 * Custo: três consultas agregadas sobre os pares (conta, filial), independentemente de
 * quantas contas existam — há teste de teto de queries. Nenhuma lê `faturamentos`: o
 * faturamento vem do rollup `faturamento_cliente_mensal` (ver `FaturamentoMensalRollup`).
 *
 * Os filtros da tela são aplicados DEPOIS, em memória, sobre ~370 linhas: é mais barato que
 * refazer a agregação, e garante que KPI, resumo e tabela saiam da mesma lista.
 */
class MaioresPorSegmentoResolver
{
    /** Status que a página conhece. `lead` = nenhuma loja nossa (era o "LEAD" da planilha). */
    public const STATUS = ['ativo', 'inativando', 'inativo', 'lead'];

    public function __construct(
        private readonly ClientesDaConta $clientesDaConta,
        private readonly ClienteStatusResolver $statusResolver,
        private readonly NomeVendedorResolver $nomeVendedor,
        private readonly FaturamentoMensalRollup $rollup,
    ) {
    }

    /**
     * @param  array{segmento?: string, status?: string, uf?: string, busca?: string}  $filtros
     */
    public function resolver(array $filtros = []): array
    {
        $periodo = $this->periodo();
        $linhas = $this->linhas($periodo);

        $semSegmento = $this->filtrar($linhas, [...$filtros, 'segmento' => '']);
        $filtradas = $this->filtrar($linhas, $filtros);

        /*
         * A tela é uma aba por segmento, igual à planilha: as seis abas existem mesmo
         * sem conta (tabela vazia), e a troca de aba NÃO recorta o payload — o front
         * escolhe qual tabela mostrar. O filtro `segmento` só vale para o Excel, que
         * exporta a aba aberta.
         */
        $todasAsAbas = $this->completarAbasDaPlanilha(
            $semSegmento
                ->groupBy('segmento.codigo')
                ->map(fn (Collection $contas) => [
                    ...$contas->first()['segmento'],
                    'resumo' => $this->resumir($contas),
                    'contas' => $contas->map(fn (array $l) => collect($l)->except('segmento')->all())->values()->all(),
                ])
        );

        /*
         * `segmento` recorta só o Excel (a aba aberta). A página manda vazio de propósito:
         * as seis tabelas viajam juntas e o front troca de aba sem ir ao servidor.
         */
        $segmentos = $todasAsAbas;
        if (($filtros['segmento'] ?? '') !== '') {
            $segmentos = $todasAsAbas->where('codigo', $filtros['segmento'])->values();
        }

        return [
            'segmentos' => $segmentos->all(),
            'kpis' => $this->resumir($filtradas),
            /*
             * O quadro-resumo DESENHA a quebra por segmento, então ignora o filtro de
             * segmento (mesma regra das facetas do card da Carteira): filtrado, ele viraria
             * uma linha só e perderia a comparação que existe para mostrar.
             */
            'resumoPorSegmento' => $todasAsAbas
                ->map(fn (array $s) => collect($s)->except('contas')->all())
                ->values()
                ->all(),
            'opcoes' => [
                'segmentos' => $linhas->pluck('segmento')->unique('codigo')->map(fn ($s) => [
                    'codigo' => $s['codigo'],
                    'nome' => $s['nome'],
                ])->values()->all(),
                'ufs' => $linhas->pluck('uf')->filter()->unique()->sort()->values()->all(),
            ],
            'periodo' => [
                'inicio' => $periodo['inicio']->toDateString(),
                'fim' => $periodo['fim']->toDateString(),
                'rotulo' => $this->rotuloMes($periodo['inicio']).'–'.$this->rotuloMes($periodo['fim']),
                'rollupAtualizadoEm' => $this->rollup->atualizadoEm()?->toIso8601String(),
            ],
        ];
    }

    /**
     * Os 12 meses FECHADOS mais recentes, e os 12 antes deles.
     *
     * ⚠️ Fechados de propósito: com o mês corrente dentro, a comparação com o ano anterior
     * poria um mês pela metade contra um mês inteiro, e toda conta pareceria cair no
     * começo do mês.
     *
     * @return array{inicio: CarbonImmutable, fim: CarbonImmutable, anteriorInicio: CarbonImmutable}
     */
    public function periodo(): array
    {
        $fim = CarbonImmutable::today()->startOfMonth()->subDay();
        $inicio = $fim->startOfMonth()->subMonthsNoOverflow(11);

        return [
            'inicio' => $inicio,
            'fim' => $fim,
            'anteriorInicio' => $inicio->subMonthsNoOverflow(12),
        ];
    }

    /**
     * Uma linha por conta, com todos os derivados. Sem filtro de tela.
     *
     * @return Collection<int, array>
     */
    public function linhas(?array $periodo = null): Collection
    {
        $periodo ??= $this->periodo();

        $contas = ContaEstrategica::query()
            ->with('segmento:id,codigo,nome,especialista_user_id', 'segmento.especialista:id,name,display_name')
            ->get();

        if ($contas->isEmpty()) {
            return collect();
        }

        $pares = $this->clientesDaConta->paresDeTodasAsContas();

        /*
         * (conta, vendedor) → lojas e última compra. A linha da conta soma as lojas e
         * pega a maior data; o "Atendimento" sai daqui sem outra consulta.
         */
        $porVendedor = DB::query()
            ->fromSub($pares, 'p')
            ->select('p.conta_id', 'p.cod_vendedor')
            ->selectRaw('COUNT(*) as lojas')
            ->selectRaw('MAX(p.data_ultima_compra) as uc')
            ->groupBy('p.conta_id', 'p.cod_vendedor')
            ->get()
            ->groupBy('conta_id');

        /*
         * Clientes distintos e faturamento. ⚠️ Os dois NÃO podem sair da consulta acima:
         * um `cod_cliente` dividido entre vendedores (39% das linhas da base) seria contado
         * uma vez por vendedor, e o faturamento dele somado em dobro.
         *
         * O LEFT JOIN no rollup vai pela PK (`cod_cliente`, `mes`) — é o motivo de a PK
         * começar por `cod_cliente`.
         */
        $distintos = DB::query()
            ->fromSub($pares, 'x')
            ->select('x.conta_id', 'x.cod_cliente')
            ->distinct();

        $porConta = DB::query()
            ->fromSub($distintos, 'd')
            ->leftJoin('faturamento_cliente_mensal as f', function ($join) use ($periodo) {
                $join->on('f.cod_cliente', '=', 'd.cod_cliente')
                    ->whereBetween('f.mes', [$periodo['anteriorInicio']->toDateString(), $periodo['fim']->toDateString()]);
            })
            ->select('d.conta_id')
            ->selectRaw('COUNT(DISTINCT d.cod_cliente) as clientes')
            ->selectRaw('COALESCE(SUM(CASE WHEN f.mes >= ? THEN f.valor_total END), 0) as fat_atual', [$periodo['inicio']->toDateString()])
            ->selectRaw('COALESCE(SUM(CASE WHEN f.mes < ? THEN f.valor_total END), 0) as fat_anterior', [$periodo['inicio']->toDateString()])
            ->groupBy('d.conta_id')
            ->get()
            ->keyBy('conta_id');

        $vinculos = $this->vinculosComNome();

        $nomes = $this->nomeVendedor->porCodigo(
            $porVendedor->flatten(1)->pluck('cod_vendedor')->filter()->unique()
        );

        $hoje = Carbon::now();

        return $contas
            ->sortBy([['ordem', 'asc'], ['id', 'asc']])
            ->map(function (ContaEstrategica $conta) use ($porVendedor, $porConta, $vinculos, $nomes, $hoje) {
                $vendedores = $porVendedor->get($conta->id, collect());
                $agregado = $porConta->get($conta->id);

                $lojas = (int) $vendedores->sum('lojas');
                $uc = $vendedores->pluck('uc')->filter()->max();
                $fatAtual = (float) ($agregado->fat_atual ?? 0);
                $fatAnterior = (float) ($agregado->fat_anterior ?? 0);

                return [
                    'id' => $conta->id,
                    'nome' => $conta->nome,
                    'uf' => $conta->uf,
                    'site' => $conta->site,
                    'observacao' => $conta->observacao,
                    'filiaisMercado' => $conta->filiais_mercado,
                    'lojas' => $lojas,
                    'clientes' => (int) ($agregado->clientes ?? 0),
                    'penetracao' => $conta->filiais_mercado ? round($lojas / $conta->filiais_mercado, 4) : null,
                    /*
                     * Mesmo corte da Carteira (ClienteStatusResolver), sobre a ÚLTIMA compra
                     * de QUALQUER loja da conta — a rede está ativa se alguma loja comprou.
                     * Sem loja nossa nenhuma, é lead: o status que a planilha usava.
                     */
                    'status' => $lojas === 0
                        ? 'lead'
                        : $this->statusResolver->statusPara($uc ? Carbon::parse($uc) : null, $hoje),
                    'ultimaCompra' => $uc ? Carbon::parse($uc)->toDateString() : null,
                    'atendimento' => $vendedores
                        ->filter(fn ($v) => $v->cod_vendedor)
                        ->sortByDesc('lojas')
                        ->map(fn ($v) => [
                            'codVendedor' => $v->cod_vendedor,
                            'nome' => $nomes[$v->cod_vendedor] ?? $v->cod_vendedor,
                            'lojas' => (int) $v->lojas,
                        ])
                        ->values()
                        ->all(),
                    'fat12m' => $fatAtual,
                    'fat12mAnterior' => $fatAnterior,
                    'variacao' => $fatAnterior > 0 ? round(($fatAtual - $fatAnterior) / $fatAnterior, 4) : null,
                    'vinculos' => $vinculos->get($conta->id, collect())->values()->all(),
                    'temSugestao' => $vinculos->get($conta->id, collect())->contains('origem', ContaEstrategicaVinculo::ORIGEM_SUGESTAO),
                    'segmento' => [
                        'id' => $conta->segmento->id,
                        'codigo' => $conta->segmento->codigo,
                        'nome' => $conta->segmento->nome,
                        'especialista' => $conta->segmento->especialista ? [
                            'id' => $conta->segmento->especialista->id,
                            'nome' => $conta->segmento->especialista->display_name ?: $conta->segmento->especialista->name,
                        ] : null,
                    ],
                ];
            })
            ->values();
    }

    /**
     * Os vínculos de todas as contas, com o nome do grupo/cliente — para o modal de edição
     * mostrar "RAIA SP (100)" em vez de só o código. Três consultas para o conjunto todo.
     *
     * @return Collection<int, Collection<int, array>> por `conta_id`
     */
    private function vinculosComNome(): Collection
    {
        $vinculos = ContaEstrategicaVinculo::query()
            ->orderBy('tipo')->orderBy('codigo')
            ->get(['conta_id', 'tipo', 'codigo', 'origem']);

        $grupos = GrupoCliente::query()
            ->whereIn('codigo', $vinculos->where('tipo', ContaEstrategicaVinculo::TIPO_GRUPO)->pluck('codigo'))
            ->pluck('nome', 'codigo');

        $clientes = Cliente::query()
            ->whereIn('cod_cliente', $vinculos->where('tipo', ContaEstrategicaVinculo::TIPO_CLIENTE)->pluck('codigo'))
            ->groupBy('cod_cliente')
            ->selectRaw("cod_cliente, MIN(COALESCE(NULLIF(nome_fantasia, ''), razao_social)) as nome")
            ->pluck('nome', 'cod_cliente');

        return $vinculos
            ->map(fn (ContaEstrategicaVinculo $v) => [
                'tipo' => $v->tipo,
                'codigo' => $v->codigo,
                'origem' => $v->origem,
                'nome' => $v->tipo === ContaEstrategicaVinculo::TIPO_GRUPO
                    ? ($grupos[$v->codigo] ?? null)
                    : ($clientes[$v->codigo] ?? null),
                'conta_id' => $v->conta_id,
            ])
            ->groupBy('conta_id')
            ->map(fn (Collection $lista) => $lista->map(fn (array $v) => collect($v)->except('conta_id')->all()));
    }

    /**
     * @param  Collection<int, array>  $linhas
     */
    private function filtrar(Collection $linhas, array $filtros): Collection
    {
        $segmento = (string) ($filtros['segmento'] ?? '');
        $status = (string) ($filtros['status'] ?? '');
        $uf = (string) ($filtros['uf'] ?? '');
        $busca = mb_strtoupper(trim((string) ($filtros['busca'] ?? '')));

        return $linhas
            ->when($segmento !== '', fn ($c) => $c->where('segmento.codigo', $segmento))
            ->when($status !== '', fn ($c) => $c->where('status', $status))
            ->when($uf !== '', fn ($c) => $c->where('uf', $uf))
            ->when($busca !== '', fn ($c) => $c->filter(fn (array $l) => str_contains(mb_strtoupper($l['nome']), $busca)))
            ->values();
    }

    /**
     * Completa a lista com as seis abas da planilha, na ordem delas, mesmo quando a aba
     * ainda não tem conta. Sem isso a tela vazia (antes da carga) não teria o que clicar,
     * e um segmento da planilha sem rede cadastrada sumiria do menu.
     *
     * Conta em segmento FORA das seis (alguém cadastrou na tela) entra no fim, para não
     * esconder dado — mas a ordem das abas originais não muda.
     *
     * @param  Collection<string, array>  $porCodigo
     * @return Collection<int, array>
     */
    private function completarAbasDaPlanilha(Collection $porCodigo): Collection
    {
        $vazio = $this->resumir(collect());
        $ordenadas = collect();

        foreach (AbasDaPlanilha::segmentos() as $seg) {
            if ($porCodigo->has($seg->codigo)) {
                $ordenadas->push($porCodigo->pull($seg->codigo));

                continue;
            }

            $ordenadas->push([
                'id' => $seg->id,
                'codigo' => $seg->codigo,
                'nome' => $seg->nome,
                'especialista' => AbasDaPlanilha::especialistaDe($seg),
                'resumo' => $vazio,
                'contas' => [],
            ]);
        }

        return $ordenadas->concat($porCodigo->values())->values();
    }

    /**
     * O quadro de números de um conjunto de contas — KPIs do topo, linha do resumo por
     * segmento e subtítulo de cada card saem daqui, para os três nunca divergirem.
     *
     * ⚠️ A penetração soma só as contas com filiais de mercado conhecidas, nos DOIS lados
     * da divisão. Somar as lojas de todas contra as filiais de algumas inflaria o
     * percentual com lojas cuja rede não tem tamanho declarado.
     *
     * @param  Collection<int, array>  $contas
     */
    private function resumir(Collection $contas): array
    {
        $comTamanho = $contas->filter(fn (array $l) => $l['filiaisMercado']);
        $filiaisMercado = (int) $comTamanho->sum('filiaisMercado');
        $status = $contas->countBy('status');

        return [
            'contas' => $contas->count(),
            'ativo' => $status->get('ativo', 0),
            'inativando' => $status->get('inativando', 0),
            'inativo' => $status->get('inativo', 0),
            'lead' => $status->get('lead', 0),
            'filiaisMercado' => (int) $contas->sum('filiaisMercado'),
            'lojas' => (int) $contas->sum('lojas'),
            'penetracao' => $filiaisMercado > 0
                ? round($comTamanho->sum('lojas') / $filiaisMercado, 4)
                : null,
            'fat12m' => round((float) $contas->sum('fat12m'), 2),
            'fat12mAnterior' => round((float) $contas->sum('fat12mAnterior'), 2),
        ];
    }

    private function rotuloMes(CarbonImmutable $data): string
    {
        $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return $meses[$data->month - 1].'/'.$data->format('y');
    }
}
