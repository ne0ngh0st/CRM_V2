<?php

namespace App\Http\Controllers;

use App\Models\AgendamentoLigacao;
use App\Models\Lead;
use App\Models\Ligacao;
use App\Models\VendedorPerfil;
use App\Services\Cache\CacheDeAgregacao;
use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LeadController extends Controller
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly CacheDeAgregacao $cache,
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
        return $this->cache->lembrarPorHoras(
            ChaveEscopo::deCodVendedores($codVendedores)->para('leads-opcoes'),
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

        if (! in_array($origem, ['sistema', 'manual'], true)) {
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
            ->selectRaw("SUM(status = 'ativo') as ativos")
            ->first();

        $kpis = [
            'total' => (int) ($contagem->total ?? 0),
            'sistema' => (int) ($contagem->sistema ?? 0),
            'manual' => (int) ($contagem->manual ?? 0),
            'ativos' => (int) ($contagem->ativos ?? 0),
        ];

        $leads = $this->listaQuery($request)->paginate(30)->withQueryString();

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
        ]);

        return Inertia::render('Leads/Index', [
            'role' => $role,
            'aba' => $aba,
            'leads' => $leads,
            'kpis' => $kpis,
            'agendamentos' => $this->agendamentosDoEscopo($codVendedores),
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
        ]);
    }

    public function exportar(Request $request): BinaryFileResponse
    {
        // Margem de segurança pra escopo admin sem filtro (~17k leads) — ver CarteiraController::exportar().
        ini_set('memory_limit', '1024M');
        set_time_limit(300);

        return Excel::download(
            new \App\Exports\LeadExport($this->listaQuery($request)),
            'leads-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /** Escopo (cod_vendedor, via Lead::visivel()) puro, sem filtros. */
    protected function scopeQuery(Request $request): Builder
    {
        $scope = $this->scopeResolver->resolve(
            $request->user(),
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );

        $query = Lead::query()->visivel();
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
        if (! in_array($origem, ['sistema', 'manual'], true)) {
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
        $user = $request->user();
        $scope = $this->scopeResolver->resolve($user, null, null);

        $agendamento->load('lead');
        if ($scope['codVendedores'] !== null
            && ! in_array($agendamento->lead?->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }

        abort_unless($agendamento->lead_id, 404);

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
        $scope = $this->scopeResolver->resolve($request->user(), null, null);
        if ($scope['codVendedores'] !== null && ! in_array($lead->cod_vendedor, $scope['codVendedores'], true)) {
            abort(403);
        }
    }

    /**
     * @param  array<string>|null  $codVendedores
     * @return array<int, array<string, mixed>>
     */
    private function agendamentosDoEscopo(?array $codVendedores): array
    {
        $query = AgendamentoLigacao::query()
            ->with(['lead:id,razao_social,nome,cnpj,telefone,cod_vendedor', 'user:id,name,display_name'])
            ->whereNotNull('lead_id')
            ->whereBetween('data_agendamento', [now()->subMonths(3)->startOfMonth(), now()->addMonths(6)->endOfMonth()])
            ->orderBy('data_agendamento');

        if ($codVendedores !== null) {
            $query->whereHas('lead', fn ($q) => $q->whereIn('cod_vendedor', $codVendedores));
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
