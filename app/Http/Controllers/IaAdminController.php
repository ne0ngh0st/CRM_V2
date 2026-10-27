<?php

namespace App\Http\Controllers;

use App\Models\IaChamado;
use App\Models\IaConversa;
use App\Models\IaMensagem;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela admin do assistente: os chamados que ele abriu e quanto está custando.
 *
 * É também onde se lê a conversa inteira de quem marcou 👎 — é dessas respostas que sai
 * o próximo ajuste no manual (resources/ia/manual.md) e no conjunto de avaliação.
 */
class IaAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->autorizarAdmin($request);

        $status = in_array($request->query('status'), ['aberto', 'resolvido'], true) ? $request->query('status') : 'aberto';

        $chamados = IaChamado::query()
            ->with('user:id,name,display_name')
            ->where('status', $status)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (IaChamado $c) => [
                'id' => $c->id,
                'usuario' => $c->user?->display_name ?: $c->user?->name,
                'categoria' => $c->rotuloCategoria(),
                'resumo' => $c->resumo,
                'tela' => $c->tela,
                'conversaId' => $c->conversa_id,
                'resposta' => $c->resposta,
                'abertoEm' => $c->created_at?->format('d/m/Y H:i'),
                'resolvidoEm' => $c->resolvido_em?->format('d/m/Y H:i'),
            ]);

        $inicioMes = now()->startOfMonth();

        $porDia = IaMensagem::query()
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) AS dia')
            ->selectRaw("SUM(papel = 'usuario') AS perguntas")
            ->selectRaw('SUM(custo_usd) AS custo')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) DESC')
            ->get()
            ->map(fn ($l) => [
                'dia' => date('d/m', strtotime($l->dia)),
                'perguntas' => (int) $l->perguntas,
                'custo' => round((float) $l->custo, 2),
            ]);

        $porUsuario = DB::table('ia_mensagens')
            ->join('ia_conversas', 'ia_conversas.id', '=', 'ia_mensagens.conversa_id')
            ->join('users', 'users.id', '=', 'ia_conversas.user_id')
            ->where('ia_mensagens.created_at', '>=', $inicioMes)
            ->groupBy('users.id', 'users.name', 'users.display_name')
            ->select('users.id', DB::raw('COALESCE(users.display_name, users.name) AS nome'))
            ->selectRaw("SUM(ia_mensagens.papel = 'usuario') AS perguntas")
            ->selectRaw('SUM(ia_mensagens.custo_usd) AS custo')
            ->selectRaw('SUM(ia_mensagens.avaliacao = -1) AS negativas')
            ->orderByDesc('perguntas')
            ->limit(50)
            ->get()
            ->map(fn ($l) => [
                'nome' => $l->nome,
                'perguntas' => (int) $l->perguntas,
                'custo' => round((float) $l->custo, 2),
                'negativas' => (int) $l->negativas,
            ]);

        $negativas = IaMensagem::query()
            ->where('avaliacao', -1)
            ->with('conversa.user:id,name,display_name')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (IaMensagem $m) => [
                'conversaId' => $m->conversa_id,
                'usuario' => $m->conversa?->user?->display_name ?: $m->conversa?->user?->name,
                'titulo' => $m->conversa?->titulo,
                'quando' => $m->created_at?->format('d/m H:i'),
            ]);

        $mes = IaMensagem::query()->where('created_at', '>=', $inicioMes);

        return Inertia::render('Ia/Admin', [
            'status' => $status,
            'chamados' => $chamados,
            'uso' => [
                'perguntasMes' => (clone $mes)->where('papel', 'usuario')->count(),
                'custoMes' => round((float) (clone $mes)->sum('custo_usd'), 2),
                'tetoMes' => (float) config('ia.teto_mensal_usd'),
                'modelo' => config('ia.modelo'),
                'habilitado' => (bool) config('ia.habilitado'),
                'liberados' => count(config('ia.usuarios_liberados', [])),
                'porDia' => $porDia,
                'porUsuario' => $porUsuario,
                'negativas' => $negativas,
            ],
            'chamadoAberto' => $request->integer('chamado') ?: null,
        ]);
    }

    /** A conversa inteira, para o admin ler um chamado ou uma resposta ruim. */
    public function conversa(Request $request, IaConversa $conversa): JsonResponse
    {
        $this->autorizarAdmin($request);

        $conversa->load('user:id,name,display_name');

        return response()->json([
            'id' => $conversa->id,
            'usuario' => $conversa->user?->display_name ?: $conversa->user?->name,
            'mensagens' => $conversa->mensagens->map(fn (IaMensagem $m) => [
                ...$m->paraTela(),
                'ferramentas' => $m->ferramentas ?? [],
                'custo' => (float) $m->custo_usd,
                'latenciaMs' => $m->latencia_ms,
                'quando' => $m->created_at?->format('d/m H:i'),
            ])->values(),
        ]);
    }

    public function responder(Request $request, IaChamado $chamado, NotificacaoService $notificacoes): RedirectResponse
    {
        $this->autorizarAdmin($request);

        $dados = $request->validate(['resposta' => ['required', 'string', 'max:2000']]);

        $chamado->update([
            'status' => 'resolvido',
            'resposta' => $dados['resposta'],
            'resolvido_por' => $request->user()->id,
            'resolvido_em' => now(),
        ]);

        if ($chamado->user) {
            $notificacoes->notificar(
                $chamado->user,
                'ia_chamado_resposta',
                'Resposta do suporte ao chamado #'.$chamado->id,
                $dados['resposta'],
                null,
                'ia_chamado_resposta',
                $chamado->id,
            );
        }

        return back();
    }

    private function autorizarAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }
}
