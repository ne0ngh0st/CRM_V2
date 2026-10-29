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
            ->selectRaw('SUM(tokens_entrada + tokens_saida + tokens_cache_leitura + tokens_cache_escrita) AS tokens')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at) DESC')
            ->get()
            ->map(fn ($l) => [
                'dia' => date('d/m', strtotime($l->dia)),
                'perguntas' => (int) $l->perguntas,
                'custo' => round((float) $l->custo, 2),
                'tokens' => (int) $l->tokens,
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
            ->selectRaw('SUM(ia_mensagens.tokens_entrada + ia_mensagens.tokens_saida + ia_mensagens.tokens_cache_leitura + ia_mensagens.tokens_cache_escrita) AS tokens')
            ->orderByDesc('perguntas')
            ->limit(50)
            ->get()
            ->map(fn ($l) => [
                'nome' => $l->nome,
                'perguntas' => (int) $l->perguntas,
                'custo' => round((float) $l->custo, 2),
                'negativas' => (int) $l->negativas,
                'tokens' => (int) $l->tokens,
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

        return Inertia::render('Ia/Admin', [
            'status' => $status,
            'chamados' => $chamados,
            'uso' => [
                ...$this->consumoDoMes(),
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

    /**
     * Quanto o mês já gastou, quanto sobra do teto e para onde vai, em US$ e R$.
     *
     * ⚠️ "Disponível" é o que sobra do TETO DO CRM (`ia.teto_mensal_usd`), não um saldo
     * na AWS: o Bedrock é pós-pago e cobra na fatura do mês. O custo é estimado pela
     * tabela `ia.precos`; a cobrança de verdade está no Cost Explorer da AWS.
     *
     * Uma varredura só do mês (índice `created_at, papel`).
     */
    private function consumoDoMes(): array
    {
        $inicio = now()->startOfMonth();

        $l = IaMensagem::query()
            ->where('created_at', '>=', $inicio)
            ->selectRaw("SUM(papel = 'usuario') AS perguntas")
            ->selectRaw('COALESCE(SUM(custo_usd), 0) AS custo')
            ->selectRaw('COALESCE(SUM(tokens_entrada), 0) AS entrada')
            ->selectRaw('COALESCE(SUM(tokens_saida), 0) AS saida')
            ->selectRaw('COALESCE(SUM(tokens_cache_leitura), 0) AS cache_leitura')
            ->selectRaw('COALESCE(SUM(tokens_cache_escrita), 0) AS cache_escrita')
            ->first();

        $custo = (float) $l->custo;
        $teto = (float) config('ia.teto_mensal_usd');
        $perguntas = (int) $l->perguntas;

        // Ritmo dos dias já passados (o de hoje conta inteiro), esticado até o fim do mês.
        $diasPassados = now()->day;
        $projecao = $custo / $diasPassados * now()->daysInMonth;

        return [
            'perguntasMes' => $perguntas,
            'custoMes' => round($custo, 2),
            'tetoMes' => $teto,
            'disponivelMes' => round(max(0, $teto - $custo), 2),
            'projecaoMes' => round($projecao, 2),
            'custoPorPergunta' => $perguntas > 0 ? round($custo / $perguntas, 4) : 0.0,
            'cotacaoDolar' => (float) config('ia.cotacao_dolar'),
            'tokensMes' => [
                'entrada' => (int) $l->entrada,
                'saida' => (int) $l->saida,
                'cacheLeitura' => (int) $l->cache_leitura,
                'cacheEscrita' => (int) $l->cache_escrita,
            ],
        ];
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
