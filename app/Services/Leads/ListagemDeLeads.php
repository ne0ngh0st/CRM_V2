<?php

namespace App\Services\Leads;

use App\Models\Cliente;
use App\Models\Lead;
use App\Services\Cache\CacheDeAgregacao;
use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Totvs\Normalizador;
use App\Services\Vendedores\NomeVendedorResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Tudo o que a aba Leads e a aba Funil da Carteira LEEM: escopo, filtros, KPIs, lista e
 * quadro.
 *
 * Existe porque desde 2026-09-29 os leads moram dentro da página da Carteira (abas
 * Clientes · Leads · Funil · Calendário, uma busca só). Antes isso vivia no
 * `LeadController::index()`; com a página nova servida pelo `CarteiraController`, a
 * alternativa seria um controller chamando outro. O `LeadController` ficou com as ações
 * de escrita e delega para cá o que é leitura.
 *
 * ⚠️ A mesma query é montada em dois contextos: a tela e o job de exportação
 * (`CatalogoDeExportacoes`, via `LeadController::listaQuery()`). Filtro novo entra
 * AQUI, uma vez, senão o Excel diverge da tela. Regra de ouro nº 8.
 */
class ListagemDeLeads
{
    /**
     * Cards carregados por coluna do funil. O quadro NUNCA carrega a coluna inteira —
     * ver o docblock de funil().
     */
    public const LIMITE_COLUNA = 20;

    public const POR_PAGINA = 30;

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CacheDeAgregacao $cache,
        private readonly NomeVendedorResolver $nomeVendedor,
    ) {}

    /** Escopo (cod_vendedor, via Lead::visivel()) puro, sem filtros. */
    public function escopo(Request $request): Builder
    {
        $query = Lead::query()->visivel();

        $scope = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        if ($scope['codVendedores'] !== null) {
            $query->whereIn('cod_vendedor', $scope['codVendedores']);
        }

        return $query;
    }

    /** escopo() + busca/estado/segmento/status/origem. */
    public function base(Request $request): Builder
    {
        $filtros = $this->filtros($request);

        $query = $this->escopo($request);

        if ($filtros['busca'] !== '') {
            $query->busca($filtros['busca']);
        }
        if ($filtros['estado'] !== '') {
            $query->where('estado', $filtros['estado']);
        }
        if ($filtros['segmento'] !== '') {
            $query->where('segmento', $filtros['segmento']);
        }
        if ($filtros['status'] !== '') {
            // O filtro é por ETAPA. O nome do parâmetro segue `status` para não quebrar
            // link salvo, mas o que ele recorta é o estágio da negociação.
            $query->where('etapa', $filtros['status']);
        }
        if ($filtros['origem'] !== '') {
            $query->where('origem', $filtros['origem']);
        }

        return $query;
    }

    /** base() + ordenação. Usado pela aba Leads e pela exportação. */
    public function lista(Request $request): Builder
    {
        $query = $this->base($request);

        match ($this->filtros($request)['ordenar']) {
            'valor_desc' => $query->orderByRaw('valor_estimado IS NULL, valor_estimado DESC'),
            'recentes' => $query->orderByDesc('updated_at'),
            default => $query->orderBy('razao_social'),
        };

        return $query;
    }

    /**
     * Os filtros da aba, já validados. `status` e `origem` fora da whitelist viram ''
     * (valor arbitrário da URL nunca chega ao WHERE).
     *
     * @return array{busca: string, estado: string, segmento: string, status: string, origem: string, ordenar: string}
     */
    public function filtros(Request $request): array
    {
        $status = (string) $request->string('status');
        $origem = (string) $request->string('origem');

        return [
            'busca' => trim((string) $request->string('busca')),
            'estado' => (string) $request->string('estado'),
            'segmento' => (string) $request->string('segmento'),
            'status' => in_array($status, Lead::ETAPAS, true) ? $status : '',
            'origem' => in_array($origem, Lead::ORIGENS, true) ? $origem : '',
            'ordenar' => (string) $request->string('ordenar') ?: 'nome_asc',
        ];
    }

    /**
     * Uma linha agregada em vez de quatro varreduras.
     *
     * Cada `count()` reexecutava a query do zero — quatro passagens pelos 17 mil leads
     * para responder quatro perguntas sobre o mesmo conjunto. Medido: 18,9 ms nos quatro
     * separados contra 14,0 ms na consolidada.
     *
     * @return array{total: int, sistema: int, manual: int, wordpress: int, ativos: int}
     */
    public function kpis(Request $request): array
    {
        $contagem = $this->base($request)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(origem = 'sistema') as sistema")
            ->selectRaw("SUM(origem = 'manual') as manual")
            ->selectRaw("SUM(origem = 'wordpress') as wordpress")
            /*
             * "Em jogo" = etapa da ESTEIRA. ⚠️ Sai da constante, não de uma lista literal:
             * "Outros" é coluna do quadro mas NÃO é negócio em jogo (é SAC, licitação,
             * currículo). Regra de ouro nº 8.
             */
            ->selectRaw(
                'SUM(etapa IN ('.implode(',', array_fill(0, count(Lead::ETAPAS_ESTEIRA), '?')).')) as ativos',
                Lead::ETAPAS_ESTEIRA,
            )
            ->first();

        return [
            'total' => (int) ($contagem->total ?? 0),
            'sistema' => (int) ($contagem->sistema ?? 0),
            'manual' => (int) ($contagem->manual ?? 0),
            'wordpress' => (int) ($contagem->wordpress ?? 0),
            'ativos' => (int) ($contagem->ativos ?? 0),
        ];
    }

    /**
     * A página da lista, pronta para a tela.
     *
     * @param  array<string>|null  $codVendedores  escopo JÁ resolvido (para o selo "Já é cliente")
     */
    public function pagina(Request $request, ?array $codVendedores, bool $temLeadDoSite): LengthAwarePaginator
    {
        $query = $this->lista($request);

        // Só paga as 2 queries do eager load se existir lead do site no escopo.
        // A maioria das carteiras não tem nenhum, e esta aba é das mais abertas.
        if ($temLeadDoSite) {
            $query->with(['stagingWordpress.formulario:id,nome,identificador']);
        }

        $leads = $query->paginate(self::POR_PAGINA)->withQueryString();

        $nomesPorCod = $this->nomeVendedor->porCodigo($leads->getCollection()->pluck('cod_vendedor'));
        $clientes = $this->clientesComMesmoCnpj($leads->getCollection()->pluck('cnpj'), $codVendedores);

        return $leads->through(fn (Lead $lead) => [
            'id' => $lead->id,
            'origem' => $lead->origem,
            'nome' => $lead->nome,
            'razaoSocial' => $lead->razao_social,
            'nomeFantasia' => $lead->nome_fantasia,
            'cnpj' => $lead->cnpj,
            'email' => $lead->email,
            'telefone' => $lead->telefone,
            'endereco' => $lead->endereco,
            'cidade' => $lead->cidade,
            'estado' => $lead->estado,
            'segmento' => $lead->segmento,
            'valorEstimado' => $lead->valor_estimado !== null ? (float) $lead->valor_estimado : null,
            'etapa' => $lead->etapa,
            'proximaEtapa' => $lead->proximaEtapa(),
            'paradoDesde' => $lead->etapa_alterada_em?->toIso8601String(),
            'motivoPerda' => $lead->motivo_perda,
            'codVendedor' => $lead->cod_vendedor,
            'vendedorNome' => $nomesPorCod[$lead->cod_vendedor] ?? $lead->cod_vendedor,
            'atualizadoEm' => $lead->updated_at?->format('d/m/Y'),
            'formularioNome' => $lead->stagingWordpress?->formulario?->nome,
            'temCaptura' => $lead->stagingWordpress !== null,
            'jaECliente' => $clientes[$this->chaveCnpj($lead->cnpj)] ?? null,
        ]);
    }

    /**
     * "Já é cliente": lead cujo CNPJ existe em `clientes`. Resposta à dúvida do Tony
     * (2026-09-29) sobre lead e cliente "com o mesmo nome" — nome não identifica empresa,
     * CNPJ sim. Em dev, 1.927 dos 17.173 leads (11%) caíam nesse caso.
     *
     * ⚠️ Compara pela forma normalizada (`Normalizador::documento`, a mesma dos
     * clientes). O mutator de `Lead::cnpj` já grava assim, mas o que entrou antes dele
     * e ainda não passou pela migration de normalização não pode sumir do selo.
     *
     * ⚠️ O selo aparece mesmo quando o cliente é de OUTRA carteira — é justamente o caso
     * útil (prospectar quem já compra de um colega). Mas o `clienteId`, que vira link
     * para a ficha, só sai quando o cliente está no escopo de quem vê: a ficha tem
     * status, compras e valores, e a "Quem cuida do cliente?" já responde de quem é sem
     * expor nada disso.
     *
     * Uma consulta por página (30 CNPJs no máximo), `cnpj IN (...)` indexado.
     *
     * @param  iterable<string|null>  $cnpjs
     * @param  array<string>|null  $codVendedores
     * @return array<string, array{clienteId: int|null}>
     */
    private function clientesComMesmoCnpj(iterable $cnpjs, ?array $codVendedores): array
    {
        $chaves = collect($cnpjs)->map(fn ($c) => $this->chaveCnpj($c))->filter()->unique()->values();

        if ($chaves->isEmpty()) {
            return [];
        }

        $resultado = [];
        Cliente::query()
            ->whereIn('cnpj', $chaves)
            ->get(['id', 'cnpj', 'cod_vendedor'])
            ->each(function (Cliente $c) use (&$resultado, $codVendedores) {
                $noEscopo = $codVendedores === null || in_array($c->cod_vendedor, $codVendedores, true);
                $atual = $resultado[$c->cnpj]['clienteId'] ?? null;
                $resultado[$c->cnpj] = ['clienteId' => $atual ?? ($noEscopo ? $c->id : null)];
            });

        return $resultado;
    }

    private function chaveCnpj(?string $cnpj): ?string
    {
        $normalizado = Normalizador::documento($cnpj);

        // Só CNPJ/CPF completo identifica empresa; pedaço de documento não casa nada.
        return $normalizado !== null && str_contains($normalizado, '.') ? $normalizado : null;
    }

    /**
     * Opções dos dropdowns de Estado e Segmento. Dependem só do escopo, nunca dos filtros
     * ativos — senão o dropdown perderia opções conforme se filtra. Cacheadas: dois
     * DISTINCT sobre os ~17 mil leads (45 ms medidos só no de estado).
     *
     * @param  array<string>|null  $codVendedores
     * @return array{estados: mixed, segmentos: mixed}
     */
    public function opcoes(Request $request, ?array $codVendedores): array
    {
        return $this->cache->lembrarPorHoras(
            ChaveEscopo::deCodVendedores($codVendedores)->para('leads-opcoes'),
            (int) config('perf.ttl_lookup_minutos', 360) / 60,
            fn () => [
                'estados' => $this->escopo($request)->whereNotNull('estado')->where('estado', '!=', '')->distinct()->orderBy('estado')->pluck('estado'),
                'segmentos' => $this->escopo($request)->whereNotNull('segmento')->where('segmento', '!=', '')->distinct()->orderBy('segmento')->pluck('segmento'),
            ],
        );
    }

    /**
     * O quadro do funil: uma coluna por etapa aberta, com o total e só as primeiras N.
     *
     * ⚠️ PERFORMANCE É O DESENHO, NÃO UM AJUSTE DEPOIS. São 17 mil leads e a esmagadora
     * maioria está em "Novo". Carregar coluna inteira seria um payload de megabytes e uma
     * tela travada. Por isso: uma query de contagem agregada para todas as colunas, e uma
     * query por coluna limitada a LIMITE_COLUNA.
     *
     * ⚠️ Ordem `etapa_alterada_em ASC` — mais parado primeiro. É o que faz o quadro ser
     * útil (o topo da coluna é o que precisa de ação) e é exatamente o que o índice
     * `leads_funil_idx` cobre.
     */
    public function funil(Request $request): array
    {
        $porEtapa = (clone $this->base($request))
            ->selectRaw('etapa, COUNT(*) as total')
            ->groupBy('etapa')
            ->pluck('total', 'etapa');

        $colunas = [];
        foreach (Lead::ETAPAS_ABERTAS as $etapa) {
            $colunas[] = [
                'etapa' => $etapa,
                'total' => (int) ($porEtapa[$etapa] ?? 0),
                'cards' => $this->cardsDaColuna($request, $etapa),
            ];
        }

        return [
            'colunas' => $colunas,
            'limiteColuna' => self::LIMITE_COLUNA,
            // Desfechos não são coluna (cresceriam para sempre) — viram contador.
            'fechados' => [
                'ganho' => (int) ($porEtapa[Lead::ETAPA_GANHO] ?? 0),
                'perdido' => (int) ($porEtapa[Lead::ETAPA_PERDIDO] ?? 0),
            ],
        ];
    }

    /**
     * Uma página de cards de uma coluna.
     *
     * ⚠️ Paginação por CURSOR (`depois`, o id do último card), não por offset. Com OFFSET
     * alto o MySQL troca de plano e passa a varrer a tabela — o penhasco medido em
     * 2026-08-29 na Carteira (página 30 = 96 ms, página 40 = 1.084 ms).
     */
    public function cardsDaColuna(Request $request, string $etapa, ?int $depois = null): array
    {
        $query = (clone $this->base($request))
            ->where('etapa', $etapa)
            ->orderBy('etapa_alterada_em')
            ->orderBy('id');

        if ($depois !== null) {
            $ancora = Lead::query()->find($depois);
            if ($ancora) {
                $query->where(function ($q) use ($ancora) {
                    $q->where('etapa_alterada_em', '>', $ancora->etapa_alterada_em)
                        ->orWhere(function ($q2) use ($ancora) {
                            $q2->where('etapa_alterada_em', $ancora->etapa_alterada_em)
                                ->where('id', '>', $ancora->id);
                        });
                });
            }
        }

        return $query
            ->limit(self::LIMITE_COLUNA)
            ->get(['id', 'razao_social', 'nome', 'nome_fantasia', 'cnpj', 'telefone', 'email', 'estado', 'cidade', 'origem', 'etapa', 'etapa_alterada_em', 'valor_estimado'])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'razaoSocial' => $lead->razao_social ?: $lead->nome,
                'nome' => $lead->nome,
                'cnpj' => $lead->cnpj,
                'telefone' => $lead->telefone,
                'email' => $lead->email,
                'local' => trim(($lead->cidade ?: '').($lead->estado ? ' - '.$lead->estado : ''), ' -'),
                'origem' => $lead->origem,
                'etapa' => $lead->etapa,
                'proximaEtapa' => $lead->proximaEtapa(),
                'paradoDesde' => $lead->etapa_alterada_em?->toIso8601String(),
                'valorEstimado' => $lead->valor_estimado !== null ? (float) $lead->valor_estimado : null,
            ])
            ->all();
    }
}
