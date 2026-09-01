<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Observacao;
use App\Models\User;
use App\Services\Dashboard\DashboardScopeResolver;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ObservacaoController extends Controller
{
    public function __construct(
        private readonly DashboardScopeResolver $scopeResolver,
        private readonly NotificacaoService $notificacaoService,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $scope = $this->scopeResolver->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $request->string('visao_vendedor')->value() ?: null,
        );
        $userIds = $this->scopeResolver->usuarioIds($user, $scope);

        return Observacao::query()
            ->with('user:id,name,display_name')
            ->whereIn('user_id', $userIds)
            ->orderByDesc('fixada')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (Observacao $o) => [
                'id' => $o->id,
                'autor' => $o->nomeAutor(),
                'cnpj' => $o->cnpj,
                'mensagem' => $o->mensagem,
                'fixada' => $o->fixada,
                'podeEditar' => $o->user_id === $user->id,
                'criadoEm' => $o->created_at->toIso8601String(),
            ]);
    }

    /**
     * Histórico de observações de um cliente específico (modal da Carteira).
     */
    public function porCliente(Request $request, Cliente $cliente)
    {
        $user = $request->user();

        return Observacao::query()
            ->with('user:id,name,display_name')
            ->where(function ($q) use ($cliente) {
                $q->where('cliente_id', $cliente->id);
                if ($cliente->cnpj) {
                    $q->orWhere(function ($q2) use ($cliente) {
                        $q2->whereNull('cliente_id')->whereNull('lead_id')->where('cnpj', $cliente->cnpj);
                    });
                }
            })
            ->orderByDesc('fixada')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Observacao $o) => [
                'id' => $o->id,
                'autor' => $o->nomeAutor(),
                'mensagem' => $o->mensagem,
                'fixada' => $o->fixada,
                'podeEditar' => $o->user_id === $user->id,
                'criadoEm' => $o->created_at->format('d/m/Y H:i'),
            ]);
    }

    /**
     * Histórico de observações de um lead (modal da página Leads).
     */
    public function porLead(Request $request, Lead $lead)
    {
        $user = $request->user();

        return Observacao::query()
            ->with('user:id,name,display_name')
            ->where('lead_id', $lead->id)
            ->orderByDesc('fixada')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Observacao $o) => [
                'id' => $o->id,
                'autor' => $o->nomeAutor(),
                'mensagem' => $o->mensagem,
                'fixada' => $o->fixada,
                'podeEditar' => $o->user_id === $user->id,
                'criadoEm' => $o->created_at->format('d/m/Y H:i'),
            ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'mensagem' => ['required', 'string', 'max:2000'],
        ]);

        $cliente = ! empty($data['cliente_id']) ? Cliente::find($data['cliente_id']) : null;
        $lead = ! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null;
        $cnpj = $cliente?->cnpj ?: ($lead?->cnpj ?: ($data['cnpj'] ?? null));

        if (! $cliente && ! $lead && ! $cnpj) {
            return response()->json([
                'message' => 'Informe o cliente, o lead ou o CNPJ.',
                'errors' => ['cnpj' => ['Informe o cliente, o lead ou o CNPJ.']],
            ], 422);
        }

        $observacao = Observacao::create([
            'user_id' => $user->id,
            'cliente_id' => $cliente?->id,
            'lead_id' => $lead?->id,
            'cnpj' => $cnpj ?: 'SEM_CNPJ',
            'mensagem' => $data['mensagem'],
        ]);

        $observacao->load('user:id,name,display_name');

        $this->notificarDonoCarteira($observacao, $cliente, $lead, $user);

        return response()->json([
            'id' => $observacao->id,
            'autor' => $observacao->nomeAutor(),
            'cnpj' => $observacao->cnpj,
            'mensagem' => $observacao->mensagem,
            'fixada' => $observacao->fixada,
            'podeEditar' => true,
            'criadoEm' => $observacao->created_at->format('d/m/Y H:i'),
        ], 201);
    }

    /**
     * Notifica quem é dono da carteira do cliente/lead — só quando o autor
     * da observação é outra pessoa (ex.: gestor comentando no cliente do vendedor).
     */
    private function notificarDonoCarteira(Observacao $observacao, ?Cliente $cliente, ?Lead $lead, User $autor): void
    {
        $donos = match (true) {
            $cliente?->cod_vendedor !== null => User::whereHas(
                'vendedorPerfil',
                fn ($q) => $q->where('cod_vendedor', $cliente->cod_vendedor),
            )->get(),
            $lead?->user_id !== null => User::where('id', $lead->user_id)->get(),
            default => collect(),
        };

        $nomeAlvo = $cliente?->razao_social ?? $lead?->razao_social ?? $observacao->cnpj;

        foreach ($donos as $dono) {
            if ($dono->id === $autor->id) {
                continue;
            }

            $this->notificacaoService->notificar(
                destinatario: $dono,
                tipo: 'observacao_nova',
                titulo: "Nova observação em {$nomeAlvo}",
                mensagem: Str::limit($observacao->mensagem, 140),
                link: $cliente ? route('carteira.index') : ($lead ? route('leads.index') : null),
            );
        }
    }

    public function togglePin(Request $request, Observacao $observacao)
    {
        abort_unless($observacao->user_id === $request->user()->id, 403);

        $observacao->update(['fixada' => ! $observacao->fixada]);

        return response()->json($observacao);
    }
}
