<?php

namespace App\Services\VisaoDiretor;

use App\Models\ContaEstrategica;
use App\Models\Cliente;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Segmentos\EspecialistasDoSegmento;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Os números da página "Maiores por Segmento" (Visão Diretor).
 *
 * A conta-alvo é digitada (nome, UF, filiais de mercado, site, observação); TODO o resto é
 * derivado dos vínculos, via `ClientesDaConta` — a mesma definição que a Carteira usa no
 * `?conta_alvo=`. É isso que faz "Clientes" bater com a lista que o clique abre.
 *
 * Custo: duas consultas agregadas sobre os pares (conta, filial), independentemente de
 * quantas contas existam — há teste de teto de queries. Não agrega `faturamentos`.
 *
 * Os filtros da tela são aplicados DEPOIS, em memória, sobre ~370 linhas: é mais barato que
 * refazer a agregação, e garante que KPI, resumo e tabela saiam da mesma lista.
 */
class MaioresPorSegmentoResolver
{
    /** Status que a página conhece. `lead` = nenhuma loja nossa (era o "LEAD" da planilha). */
    public const STATUS = ['ativo', 'inativando', 'inativo', 'lead'];

    /**
     * Valores aceitos no filtro `?status=`: cada status, mais o tile "A trabalhar + lead",
     * que filtra os dois de uma vez (é o mesmo número que o tile mostra).
     */
    public const FILTROS_STATUS = [
        'ativo' => ['ativo'],
        'inativando' => ['inativando'],
        'inativo' => ['inativo'],
        'lead' => ['lead'],
        'a_trabalhar' => ['inativo', 'lead'],
    ];

    public function __construct(
        private readonly ClientesDaConta $clientesDaConta,
        private readonly ClienteStatusResolver $statusResolver,
        private readonly NomeVendedorResolver $nomeVendedor,
        private readonly EspecialistasDoSegmento $especialistas,
    ) {
    }

    /**
     * @param  array{segmento?: string, status?: string, uf?: string, busca?: string}  $filtros
     */
    public function resolver(array $filtros = []): array
    {
        $linhas = $this->linhas();

        $semSegmento = $this->filtrar($linhas, [...$filtros, 'segmento' => '']);

        /*
         * Os números de status (KPIs do topo, resumo de cada aba) IGNORAM o filtro de
         * status: os tiles são o próprio filtro, e um filtro não se aplica à faceta que
         * ele controla (mesma regra do card da Carteira, 2026-09-15). Sem isso, clicar em
         * "Ativo" zeraria os vizinhos e o tile só diria "N ativos entre os ativos".
         * A TABELA continua recortada.
         */
        $semStatus = $this->filtrar($linhas, [...$filtros, 'segmento' => '', 'status' => '']);
        $contasPorSegmento = $semSegmento->groupBy('segmento.codigo');

        /*
         * A tela é uma aba por segmento, igual à planilha: as seis abas existem mesmo
         * sem conta (tabela vazia), e a troca de aba NÃO recorta o payload — o front
         * escolhe qual tabela mostrar. O filtro `segmento` só vale para o Excel, que
         * exporta a aba aberta.
         */
        $todasAsAbas = $this->completarAbasDaPlanilha(
            $semStatus
                ->groupBy('segmento.codigo')
                ->map(fn (Collection $contas, $codigo) => [
                    ...$contas->first()['segmento'],
                    'resumo' => $this->resumir($contas),
                    'contas' => $contasPorSegmento->get($codigo, collect())
                        ->map(fn (array $l) => collect($l)->except('segmento')->all())
                        ->values()
                        ->all(),
                ])
        );

        /*
         * O especialista entra por cima, de uma fonte só (`EspecialistasDoSegmento`) —
         * o mesmo mapa que o quadro de Segmentos da Equipe e o Painel usam. Ele é marcado
         * pela estrela naquele quadro, não aqui.
         */
        $especialistas = $this->especialistas->porCodigo();
        $todasAsAbas = $todasAsAbas
            ->map(fn (array $s) => [...$s, 'especialista' => $especialistas[(string) $s['codigo']] ?? null])
            ->values();

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
            'kpis' => $this->resumir($this->filtrar($linhas, [...$filtros, 'status' => ''])),
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
        ];
    }

    /**
     * Uma linha por conta, com todos os derivados. Sem filtro de tela.
     *
     * @return Collection<int, array>
     */
    public function linhas(): Collection
    {
        $contas = ContaEstrategica::query()
            ->with('segmento:id,codigo,nome')
            // Subconsulta na MESMA query: não mexe no teto de queries da página.
            ->withCount('observacoes')
            /*
             * Os leads ligados à conta: o aberto pela diretoria (`LeadDaConta`) e os da
             * prospecção (`ContaDoLead`). Só ligação confirmada; excluído não conta (a conta
             * volta a oferecer "Gerar lead"). Duas consultas para o conjunto, não por conta.
             */
            ->with(['leads' => fn ($q) => $q->visivel()->orderBy('id')->select('id', 'conta_estrategica_id', 'user_id', 'cod_vendedor', 'etapa', 'nome'), 'leads.user:id,name,display_name'])
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
         * Clientes distintos. ⚠️ NÃO pode sair da consulta por vendedor: um `cod_cliente`
         * dividido entre vendedores (39% das linhas da base) seria contado uma vez por
         * vendedor.
         */
        $porConta = DB::query()
            ->fromSub($pares, 'x')
            ->select('x.conta_id')
            ->selectRaw('COUNT(DISTINCT x.cod_cliente) as clientes')
            ->groupBy('x.conta_id')
            ->get()
            ->keyBy('conta_id');

        $vinculos = $this->vinculosComNome();

        // Lead da prospecção não tem `user_id`: o responsável sai do código de vendedor.
        $nomes = $this->nomeVendedor->porCodigo(
            $porVendedor->flatten(1)->pluck('cod_vendedor')
                ->concat($contas->flatMap->leads->pluck('cod_vendedor'))
                ->filter()->unique()
        );

        $hoje = Carbon::now();

        /*
         * Posição da conta DENTRO do segmento, na ordem da planilha (a maior rede é a 1).
         * Calculada aqui, antes de qualquer filtro: filtrar por status ou UF não pode
         * renumerar — "a 4ª maior rede" continua sendo a 4ª.
         */
        $posicoes = [];

        return $contas
            ->sortBy([['ordem', 'asc'], ['id', 'asc']])
            ->map(function (ContaEstrategica $conta) use ($porVendedor, $porConta, $vinculos, $nomes, $hoje, &$posicoes) {
                $posicoes[$conta->segmento_id] = ($posicoes[$conta->segmento_id] ?? 0) + 1;

                $vendedores = $porVendedor->get($conta->id, collect());
                $agregado = $porConta->get($conta->id);

                $lojas = (int) $vendedores->sum('lojas');
                $uc = $vendedores->pluck('uc')->filter()->max();

                return [
                    'id' => $conta->id,
                    'posicao' => $posicoes[$conta->segmento_id],
                    'nome' => $conta->nome,
                    'uf' => $conta->uf,
                    'site' => $conta->site,
                    'observacao' => $conta->observacao,
                    // Versões no histórico (inclui a vigente). Só a tela usa; o Excel não.
                    'versoesObservacao' => (int) $conta->observacoes_count,
                    'filiaisMercado' => $conta->filiais_mercado,
                    'lojas' => $lojas,
                    'clientes' => (int) ($agregado?->clientes ?? 0),
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
                    'vinculos' => $vinculos->get($conta->id, collect())->values()->all(),
                    'leads' => $conta->leads->map(fn ($lead) => [
                        'id' => $lead->id,
                        'nome' => $lead->nome,
                        'etapa' => $lead->etapa,
                        'responsavel' => $lead->user?->display_name ?: $lead->user?->name
                            ?: ($nomes[$lead->cod_vendedor] ?? $lead->cod_vendedor),
                        // Sem código ninguém vê o lead (só admin/diretor): é o que a tela oferece para atribuir.
                        'semDono' => blank($lead->cod_vendedor),
                    ])->values()->all(),
                    'temSugestao' => $vinculos->get($conta->id, collect())->contains('origem', ContaEstrategicaVinculo::ORIGEM_SUGESTAO),
                    'segmento' => [
                        'id' => $conta->segmento->id,
                        'codigo' => $conta->segmento->codigo,
                        'nome' => $conta->segmento->nome,
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
            ->when($status !== '', fn ($c) => $c->whereIn('status', self::FILTROS_STATUS[$status] ?? [$status]))
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
     * @param  Collection<int, array>  $contas
     */
    private function resumir(Collection $contas): array
    {
        $status = $contas->countBy('status');

        return [
            'contas' => $contas->count(),
            'ativo' => $status->get('ativo', 0),
            'inativando' => $status->get('inativando', 0),
            'inativo' => $status->get('inativo', 0),
            'lead' => $status->get('lead', 0),
            'filiaisMercado' => (int) $contas->sum('filiaisMercado'),
            'clientes' => (int) $contas->sum('clientes'),
        ];
    }
}
