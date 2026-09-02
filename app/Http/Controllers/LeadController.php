<?php

namespace App\Http\Controllers;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeadController extends Controller
{
    use ExportaPlanilha;

    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CacheDeAgregacao $cache,
        private readonly WpLeadCapturaStatus $wpCaptura,
        private readonly WpLeadIngestor $wpIngestor,
    ) {
    }

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
        if (! in_array($status, ['ativo', 'inativo', 'convertido'], true)) {
            $status = '';
        }
        if (! in_array($aba, ['leads', 'calendario'], true)) {
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
            ->selectRaw("SUM(status = 'ativo') as ativos")
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
            'status' => $lead->status,
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
            new \App\Exports\LeadExport($this->listaQuery($request)),
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

    public function capturaWordpress(Request $request, Lead $lead): JsonResponse
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
            'payload' => $envelope ?? $staging->payload_json,
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
        if (! in_array($status, ['ativo', 'inativo', 'convertido'], true)) {
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
            $query->where('status', $status);
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

    public function registrarLigacao(Request $request, Lead $lead): RedirectResponse
    {
        $this->autorizarLead($request, $lead);

        Ligacao::create([
            'usuario_id' => $request->user()->id,
            'lead_id' => $lead->id,
            'cliente_nome' => $lead->razao_social ?: $lead->nome,
            'tipo_contato' => 'telefonica',
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
