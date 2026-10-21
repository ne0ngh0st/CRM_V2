<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificacaoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $contagem = Notificacao::query()
            ->where('user_id', $userId)
            ->naoLidas()
            ->count();

        $naoLidas = Notificacao::query()
            ->where('user_id', $userId)
            ->naoLidas()
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (Notificacao $n) => $n->paraOSino());

        return response()->json([
            'naoLidas' => $naoLidas,
            'contagem' => $contagem,
        ]);
    }

    /**
     * Histórico de notificações — lidas e não lidas — da própria pessoa.
     *
     * O sino só mostra as não lidas; depois de clicada, a notificação sumia sem caminho de
     * volta. Lidas ficam aqui até o expurgo (Notificacao::DIAS_GUARDA_LIDAS).
     *
     * ⚠️ Só as PRÓPRIAS, para todos os perfis, inclusive admin: o link de uma notificação
     * pode ser o download de uma planilha com a carteira de outra pessoa.
     */
    public function historico(Request $request): Response
    {
        $userId = $request->user()->id;
        $situacao = in_array($request->query('situacao'), ['nao_lidas', 'lidas'], true)
            ? $request->query('situacao')
            : '';

        $notificacoes = Notificacao::query()
            ->where('user_id', $userId)
            ->when($situacao === 'nao_lidas', fn ($q) => $q->whereNull('lida_em'))
            ->when($situacao === 'lidas', fn ($q) => $q->whereNotNull('lida_em'))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString()
            // Mesmo payload do sino (paraOSino) + quando foi lida: a tela reaproveita a
            // regra de abrir link/baixar arquivo do sino sem uma segunda cópia.
            ->through(fn (Notificacao $n) => $n->paraOSino() + [
                'lidaEm' => $n->lida_em?->toIso8601String(),
            ]);

        return Inertia::render('Notificacoes/Index', [
            'notificacoes' => $notificacoes,
            'filtros' => ['situacao' => $situacao],
            'naoLidas' => Notificacao::query()->where('user_id', $userId)->naoLidas()->count(),
            'diasGuarda' => Notificacao::DIAS_GUARDA_LIDAS,
        ]);
    }

    public function marcarLida(Request $request, Notificacao $notificacao): JsonResponse
    {
        abort_unless($notificacao->user_id === $request->user()->id, 403);

        $notificacao->update(['lida_em' => now()]);

        return response()->json(['ok' => true]);
    }

    public function marcarTodas(Request $request): JsonResponse
    {
        Notificacao::query()
            ->where('user_id', $request->user()->id)
            ->naoLidas()
            ->update(['lida_em' => now()]);

        return response()->json(['ok' => true]);
    }
}
