<?php

namespace App\Http\Controllers;

use App\Exports\LeadExport;
use App\Http\Controllers\Concerns\ExportaPlanilha;
use App\Models\AgendamentoLigacao;
use App\Models\Lead;
use App\Models\Ligacao;
use App\Models\VendedorPerfil;
use App\Services\Cache\CacheDeAgregacao;
use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Marketing\WpLeadCapturaStatus;
use App\Services\Marketing\WpLeadIngestor;
use App\Services\Marketing\WpLeadPayloadParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeadController extends Controller
{
    /**
     * Cards carregados por coluna do funil. O quadro NUNCA carrega a coluna inteira —
     * ver o docblock de quadroDoFunil().
     */
    private const LIMITE_COLUNA = 20;

    use ExportaPlanilha;

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CacheDeAgregacao $cache,
        private readonly WpLeadCapturaStatus $wpCaptura,
        private readonly WpLeadIngestor $wpIngestor,
    ) {}

    /**
     * Opcoes dos dropdowns de Estado e Segmento.
     *
     * Dois DISTINCT sobre os ~17 mil leads (45 ms medidos so no de estado). Dependem
     * apenas do escopo, nunca dos filtros ativos, e mudam so quando entra lead novo —
     * logo, cacheaveis com TTL longo. Mesmo desenho usado na Carteira.
     *
     * @param  array<string>|null  $codVendedores
     * @return array{estados: mixed, segmentos: mixed}
     */
    private function opcoesDeFiltro(Request $request, ?array $codVendedores): array
    {
        $extras = $this->somenteWordpress($request) ? ['origem' => Lead::ORIGEM_WORDPRESS] : [];

        return $this->cache->lembrarPorHoras(
            ChaveEscopo::deCodVendedores($codVendedores)->para('leads-opcoes', $extras),
            (int) config('perf.ttl_lookup_minutos', 360) / 60,
            fn () => [
                'estados' => $this->scopeQuery($request)->whereNotNull('estado')->where('estado', '!=', '')->distinct()->orderBy('estado')->pluck('estado'),
                'segmentos' => $this->scopeQuery($request)->whereNotNull('segmento')->where('segmento', '!=', '')->distinct()->orderBy('segmento')->pluck('segmento'),
            ],
        );
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $role = $user->getRoleNames()->first();

        $visaoSupervisor = $request->string('visao_supervisor')->value() ?: null;
        $visaoVendedor = $request->string('visao_vendedor')->value() ?: null;
        $scope = $this->scopeResolver->resolve($user, $visaoSupervisor, $visaoVendedor);
        $codVendedores = $scope['codVendedores'];

        $busca = trim((string) $request->string('busca'));
        $estado = (string) $request->string('estado');
        $segmento = (string) $request->string('segmento');
        $status = (string) $request->string('status');
        $origem = (string) $request->string('origem');
        $ordenar = (string) $request->string('ordenar') ?: 'nome_asc';
        $aba = (string) $request->string('aba') ?: 'leads';
        $somenteWordpress = $this->somenteWordpress($request);

        if ($somenteWordpress) {
            $origem = Lead::ORIGEM_WORDPRESS;
        } elseif (! in_array($origem, Lead::ORIGENS, true)) {
            $origem = '';
        }
        if (! in_array($status, Lead::ETAPAS, true)) {
            $status = '';
        }
        if (! in_array($aba, ['leads', 'calendario', 'funil'], true)) {
            $aba = 'leads';
        }

        /*
         * Uma linha agregada em vez de quatro varreduras.
         *
         * Cada `count()` reexecutava `baseQuery()` do zero — quatro passagens pelos 17 mil
         * leads para responder quatro perguntas sobre o mesmo conjunto. Medido: 18,9 ms
         * nos quatro separados contra 14,0 ms na consolidada.
         *
         * ⚠️ O ganho é menor do que parece à primeira vista, e vale saber por quê: os
         * counts separados usam índice (`origem`, `status`), enquanto os SUM() com
         * expressão varrem tudo. O que a consolidação realmente economiza são as idas ao
         * banco — irrelevante com o MySQL na mesma máquina, mas em produção, com o RDS
         * separado por rede, cada round-trip custa cerca de 1 ms.
         */
        $contagem = $this->baseQuery($request)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(origem = 'sistema') as sistema")
            ->selectRaw("SUM(origem = 'manual') as manual")
            ->selectRaw("SUM(origem = 'wordpress') as wordpress")
            // "Em jogo" = etapa ainda aberta. Antes era `status = 'ativo'`, que depois da
            // separação dos eixos passaria a contar TODO lead não-excluído — inclusive os
            // ganhos e perdidos — e o KPI viraria uma cópia do total.
            ->selectRaw("SUM(etapa IN ('novo','em_contato','orcamento','negociacao')) as ativos")
            ->first();

        $kpis = [
            'total' => (int) ($contagem->total ?? 0),
            'sistema' => (int) ($contagem->sistema ?? 0),
            'manual' => (int) ($contagem->manual ?? 0),
            'wordpress' => (int) ($contagem->wordpress ?? 0),
            'ativos' => (int) ($contagem->ativos ?? 0),
        ];

        // Só paga as 2 queries do eager load se existir lead do site no escopo.
        // A maioria das carteiras não tem nenhum, e esta página é das mais abertas.
        $leadsQuery = $this->listaQuery($request);
        if ($kpis['wordpress'] > 0) {
            $leadsQuery->with(['stagingWordpress.formulario:id,nome,identificador']);
        }

        $leads = $leadsQuery->paginate(30)->withQueryString();

        $codigos = $leads->getCollection()->pluck('cod_vendedor')->filter()->unique()->values();
        $nomesPorCod = VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codigos)
            ->with('user:id,name,display_name')
            ->get()
            ->mapWithKeys(fn (VendedorPerfil $vp) => [$vp->cod_vendedor => $vp->user?->display_name ?: $vp->user?->name]);

        $leads->through(fn (Lead $lead) => [
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
        ]);

        return Inertia::render('Leads/Index', [
            'role' => $role,
            'aba' => $aba,
            'leads' => $leads,
            'kpis' => $kpis,
            /*
             * Prop opcional, mesmo tratamento da Carteira: a aba Leads, onde a maioria
             * das visitas para, deixou de pagar por uma consulta que ia pro lixo.
             *
             * ⚠️ Visita completa (F5, ou /leads?aba=calendario) NAO traz prop opcional —
             * o onMounted do Leads/Index.vue cobre esse caso.
             */
            'agendamentos' => Inertia::optional(fn () => $this->agendamentosDoEscopo($request, $codVendedores)),
            /*
             * O quadro do funil, também opcional: são 17 mil leads e a aba Lista é onde a
             * maioria das visitas para. ⚠️ Visita completa (/leads?aba=funil ou F5) não
             * traz prop opcional — o onMounted do Leads/Index.vue cobre esse caso.
             */
            'funil' => Inertia::optional(fn () => $this->quadroDoFunil($request)),
            'filtros' => [
                'busca' => $busca,
                'estado' => $estado,
                'segmento' => $segmento,
                'status' => $status,
                'origem' => $origem,
                'ordenar' => $ordenar,
            ],
            'opcoes' => [
                // Dois DISTINCT sobre os 17 mil leads (45 ms medidos no de estado). Dependem
                // só do escopo, nunca dos filtros — senão o dropdown perderia opções conforme
                // o usuário filtra. Mesmo tratamento dado aos dropdowns da Carteira.
                ...$this->opcoesDeFiltro($request, $scope['codVendedores']),
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
            'somenteWordpress' => $somenteWordpress,
            'wordpressCaptura' => $this->wpCaptura->resumir(
                podeTestar: in_array($role, ['admin', 'diretor'], true),
            ),
        ]);
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        $this->prepararExport('leads');

        return Excel::download(
            new LeadExport($this->listaQuery($request)),
            'leads-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    public function enviarTesteWordpress(Request $request): RedirectResponse
    {
        $role = $request->user()->getRoleNames()->first();
        abort_unless(in_array($role, ['admin', 'diretor'], true), 403);

        $this->wpIngestor->ingerirTesteInterno($request->user()->id);

        // Sem isto o resultado só apareceria depois do TTL do cache do status,
        // e o botão pareceria não ter feito nada.
        $this->wpCaptura->esquecer();

        return redirect()->route('leads.index', ['origem' => Lead::ORIGEM_WORDPRESS]);
    }

    /**
     * O que o cliente preencheu no site, em forma de ficha legível.
     *
     * ⚠️ Quem abre isto é VENDEDOR, não técnico. Até 2026-09-03 a tela despejava
     * `JSON.stringify(payload, null, 2)` num <pre> — quem precisava do telefone do
     * cliente tinha que garimpar chave crua de plugin do WordPress. Agora os campos
     * comerciais vêm prontos em `campos`, e o JSON continua disponível só para admin,
     * em `tecnico`, porque é o que permite diagnosticar um formato de payload novo.
     *
     * ⚠️ Os rótulos comerciais saem de `WpLeadPayloadParser::extrairCampos()`, o MESMO
     * dicionário de aliases que a promoção do lead usa. Não duplicar esse mapa no front:
     * se ele divergir, a ficha mostra um valor e o lead guarda outro. Regra de ouro nº 8.
     */
    public function capturaWordpress(Request $request, Lead $lead, WpLeadPayloadParser $parser): JsonResponse
    {
        $this->autorizarLead($request, $lead);
        abort_unless($lead->origem === Lead::ORIGEM_WORDPRESS, 404);

        $staging = $lead->stagingWordpress()->with('formulario:id,nome')->first();
        abort_unless($staging, 404);

        $envelope = json_decode($staging->payload_json, true);

        return response()->json([
            'fonte' => $staging->fonte,
            'recebidoEm' => $staging->recebido_em?->format('d/m/Y H:i:s'),
            'formulario' => $staging->formulario?->nome,
            'campos' => $parser->extrairCampos($this->camposCrusDoEnvelope($envelope)),
            // Só admin. Ver o docblock acima.
            'tecnico' => $request->user()->hasRole('admin') ? [
                'remoteAddr' => $staging->remote_addr,
                'userAgent' => $staging->user_agent,
                'tentativas' => $staging->tentativas,
                'erro' => $staging->erro,
                'payloadHash' => $staging->payload_hash,
                'payload' => $envelope ?? $staging->payload_json,
            ] : null,
        ]);
    }

    /**
     * O envelope tem TRÊS formas, e a ficha precisa aguentar as três:
     * webhook e teste interno guardam os campos em `parsed`; o import de CSV guarda em
     * `colunas`. E `json_decode` devolve null quando o payload gravado não é JSON válido
     * — nesse caso não há campo comercial nenhum a extrair, e a ficha fica vazia em vez
     * de estourar.
     *
     * @return array<string, mixed>
     */
    private function camposCrusDoEnvelope(mixed $envelope): array
    {
        if (! is_array($envelope)) {
            return [];
        }

        foreach (['parsed', 'colunas'] as $chave) {
            if (isset($envelope[$chave]) && is_array($envelope[$chave])) {
                return $envelope[$chave];
            }
        }

        return [];
    }

    /**
     * O quadro do funil: uma coluna por etapa aberta, com o total e só as primeiras N.
     *
     * ⚠️ PERFORMANCE É O DESENHO, NÃO UM AJUSTE DEPOIS. São 17 mil leads e a esmagadora
     * maioria está em "Novo" (a base de prospecção importada nunca foi trabalhada).
     * Carregar coluna inteira seria um payload de megabytes e uma tela travada. Por isso:
     * uma query de contagem agregada para todas as colunas, e uma query por coluna
     * limitada a LIMITE_COLUNA.
     *
     * ⚠️ Ordem `etapa_alterada_em ASC` — mais parado primeiro. Não é estética: é o que faz
     * o quadro ser útil (o topo da coluna é o que precisa de ação) e é exatamente o que o
     * índice `leads_funil_idx` cobre.
     */
    protected function quadroDoFunil(Request $request): array
    {
        $porEtapa = (clone $this->baseQuery($request))
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
    protected function cardsDaColuna(Request $request, string $etapa, ?int $depois = null): array
    {
        $query = (clone $this->baseQuery($request))
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

    /** "Carregar mais" de UMA coluna, sem remontar o quadro inteiro. */
    public function maisDoFunil(Request $request): JsonResponse
    {
        $etapa = (string) $request->string('etapa');
        abort_unless(in_array($etapa, Lead::ETAPAS_ABERTAS, true), 422);

        return response()->json([
            'etapa' => $etapa,
            'cards' => $this->cardsDaColuna($request, $etapa, $request->integer('depois') ?: null),
        ]);
    }

    /**
     * ÚNICO ponto de escrita da etapa vindo da tela. Arrastar o card e clicar no "→"
     * caem os dois aqui — mesma validação, mesma autorização (Regra de ouro nº 8).
     *
     * ⚠️ A etapa chega da requisição e vira valor de um ENUM do MySQL. Sem o `Rule::in`,
     * um valor arbitrário grava STRING VAZIA em silêncio fora do modo estrito, e a
     * contagem por coluna passa a mentir sem erro nenhum aparecer. Mesmo risco da
     * whitelist de ordenação da Carteira: isto é segurança, não organização.
     */
    public function moverEtapa(Request $request, Lead $lead): JsonResponse
    {
        $this->autorizarLead($request, $lead);

        $data = $request->validate([
            'etapa' => ['required', Rule::in(Lead::ETAPAS)],
            // Perder sem dizer por quê é o que torna o funil inútil como diagnóstico.
            'motivo_perda' => [Rule::requiredIf($request->input('etapa') === Lead::ETAPA_PERDIDO), 'nullable', 'string', 'max:255'],
        ]);

        $lead->moverParaEtapa($data['etapa'], $data['motivo_perda'] ?? null);

        /*
         * ⚠️ JSON, não `back()`. Mover um card não é navegação: um redirect faria o Inertia
         * refazer a visita, e `funil` é prop OPCIONAL — ela não voltaria, o `v-if` da
         * página derrubaria o quadro e o vendedor perderia a tela a cada movimento.
         * (Confirmado no navegador antes de mudar.) O quadro já mantém o estado local e
         * desfaz sozinho quando esta resposta falha.
         */
        return response()->json([
            'etapa' => $lead->etapa,
            'proximaEtapa' => $lead->proximaEtapa(),
            'paradoDesde' => $lead->etapa_alterada_em?->toIso8601String(),
        ]);
    }

    /** Escopo (cod_vendedor, via Lead::visivel()) puro, sem filtros. */
    protected function scopeQuery(Request $request): Builder
    {
        $query = Lead::query()->visivel();

        if ($this->somenteWordpress($request)) {
            return $query->where('origem', Lead::ORIGEM_WORDPRESS);
        }

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

    /** scopeQuery() + busca/estado/segmento/status/origem. Usado por index() (KPIs e lista) e exportar(). */
    protected function baseQuery(Request $request): Builder
    {
        $busca = trim((string) $request->string('busca'));
        $estado = (string) $request->string('estado');
        $segmento = (string) $request->string('segmento');
        $status = (string) $request->string('status');
        $origem = (string) $request->string('origem');
        $somenteWordpress = $this->somenteWordpress($request);
        if ($somenteWordpress) {
            $origem = '';
        } elseif (! in_array($origem, Lead::ORIGENS, true)) {
            $origem = '';
        }
        if (! in_array($status, Lead::ETAPAS, true)) {
            $status = '';
        }

        $query = $this->scopeQuery($request);

        if ($busca !== '') {
            $query->where(function ($q) use ($busca) {
                $q->where('nome', 'like', "%{$busca}%")
                    ->orWhere('razao_social', 'like', "%{$busca}%")
                    ->orWhere('nome_fantasia', 'like', "%{$busca}%")
                    ->orWhere('cnpj', 'like', "%{$busca}%")
                    ->orWhere('email', 'like', "%{$busca}%")
                    ->orWhere('telefone', 'like', "%{$busca}%");
            });
        }

        if ($estado !== '') {
            $query->where('estado', $estado);
        }
        if ($segmento !== '') {
            $query->where('segmento', $segmento);
        }
        if ($status !== '') {
            // O filtro passou a ser por ETAPA. O nome do parâmetro segue `status` para não
            // quebrar link salvo, mas o que ele recorta é o estágio da negociação.
            $query->where('etapa', $status);
        }
        if ($origem !== '') {
            $query->where('origem', $origem);
        }

        return $query;
    }

    /** baseQuery() + ordenação. Usado por index() (lista) e exportar(). */
    protected function listaQuery(Request $request): Builder
    {
        $query = $this->baseQuery($request);
        $ordenar = (string) $request->string('ordenar') ?: 'nome_asc';

        match ($ordenar) {
            'valor_desc' => $query->orderByRaw('valor_estimado IS NULL, valor_estimado DESC'),
            'recentes' => $query->orderByDesc('updated_at'),
            default => $query->orderBy('razao_social'),
        };

        return $query;
    }

    /**
     * Registra um contato com o lead. Mesmo contrato do `CarteiraController` —
     * o canal vem do front e é validado contra `Ligacao::TIPOS_CONTATO`.
     */
    public function registrarLigacao(Request $request, Lead $lead): RedirectResponse
    {
        $this->autorizarLead($request, $lead);

        $tipo = $request->validate([
            'tipo' => ['nullable', Rule::in(Ligacao::TIPOS_CONTATO)],
        ])['tipo'] ?? 'telefonica';

        Ligacao::create([
            'usuario_id' => $request->user()->id,
            'lead_id' => $lead->id,
            'cliente_nome' => $lead->razao_social ?: $lead->nome,
            'tipo_contato' => $tipo,
            'status' => 'finalizada',
            'data_ligacao' => now(),
        ]);

        return back();
    }

    public function registrarAgendamento(Request $request, Lead $lead): RedirectResponse
    {
        $this->autorizarLead($request, $lead);

        $data = $request->validate([
            'data_agendamento' => ['required', 'date'],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ]);

        AgendamentoLigacao::create([
            'lead_id' => $lead->id,
            'user_id' => $request->user()->id,
            'data_agendamento' => $data['data_agendamento'],
            'observacao' => $data['observacao'] ?? null,
            'status' => 'agendado',
        ]);

        return back();
    }

    public function atualizarAgendamento(Request $request, AgendamentoLigacao $agendamento): RedirectResponse
    {
        $agendamento->load('lead');
        abort_unless($agendamento->lead_id, 404);

        if ($this->somenteWordpress($request)) {
            abort_unless($agendamento->lead?->origem === Lead::ORIGEM_WORDPRESS, 403);
        } else {
            $scope = $this->scopeResolver->resolve($request->user(), null, null);
            if ($scope['codVendedores'] !== null
                && ! in_array($agendamento->lead?->cod_vendedor, $scope['codVendedores'], true)) {
                abort(403);
            }
        }

        $data = $request->validate([
            'status' => ['required', 'in:agendado,realizado,cancelado'],
        ]);

        $agendamento->update(['status' => $data['status']]);

        return back();
    }

    public function excluir(Request $request, Lead $lead): RedirectResponse
    {
        $this->autorizarLead($request, $lead);

        $lead->update(['status' => 'excluido']);

        return back();
    }

    private function autorizarLead(Request $request, Lead $lead): void
    {
        if ($this->somenteWordpress($request)) {
            abort_unless($lead->origem === Lead::ORIGEM_WORDPRESS, 403);

            return;
        }

        $scope = $this->scopeResolver->resolve($request->user(), null, null);
        if ($scope['codVendedores'] !== null && ! in_array($lead->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }
    }

    private function somenteWordpress(Request $request): bool
    {
        return $request->user()->getRoleNames()->first() === 'assistente';
    }

    /**
     * @param  array<string>|null  $codVendedores
     * @return array<int, array<string, mixed>>
     */
    private function agendamentosDoEscopo(Request $request, ?array $codVendedores): array
    {
        $query = AgendamentoLigacao::query()
            ->with(['lead:id,razao_social,nome,cnpj,telefone,cod_vendedor,origem', 'user:id,name,display_name'])
            ->whereNotNull('lead_id')
            // Janela de -1 a +3 meses com teto de 500, igual a Carteira: o calendario
            // mostra um mes por vez, e meio ano de cada lado era payload que ninguem abria.
            ->whereBetween('data_agendamento', [now()->subMonth()->startOfMonth(), now()->addMonths(3)->endOfMonth()])
            ->orderBy('data_agendamento')
            ->limit(500);

        if ($this->somenteWordpress($request)) {
            $query->whereIn('lead_id', Lead::query()->select('id')->where('origem', Lead::ORIGEM_WORDPRESS));
        } elseif ($codVendedores !== null) {
            // Subquery IN em vez de whereHas: o whereHas gera EXISTS correlacionado,
            // avaliado por linha de agendamento.
            $query->whereIn('lead_id', Lead::query()->select('id')->whereIn('cod_vendedor', $codVendedores));
        }

        return $query->get()->map(fn (AgendamentoLigacao $a) => [
            'id' => $a->id,
            'dataAgendamento' => $a->data_agendamento->toIso8601String(),
            'dataLabel' => $a->data_agendamento->format('d/m/Y H:i'),
            'dia' => $a->data_agendamento->format('Y-m-d'),
            'hora' => $a->data_agendamento->format('H:i'),
            'observacao' => $a->observacao,
            'status' => $a->status,
            'clienteId' => null,
            'clienteNome' => $a->lead?->razao_social ?: ($a->lead?->nome ?? '—'),
            'clienteCnpj' => $a->lead?->cnpj,
            'autor' => $a->user?->display_name ?: $a->user?->name,
        ])->values()->all();
    }
}
