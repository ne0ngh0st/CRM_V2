<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->map(fn (Notificacao $n) => $this->mapear($n));

        return response()->json([
            'naoLidas' => $naoLidas,
            'contagem' => $contagem,
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

    /** @return array{id: int, tipo: string, titulo: string, mensagem: ?string, link: ?string, criadoEm: string} */
    private function mapear(Notificacao $n): array
    {
        return [
            'id' => $n->id,
            'tipo' => $n->tipo,
            'titulo' => $n->titulo,
            'mensagem' => $n->mensagem,
            'link' => $n->link,
            'criadoEm' => $n->created_at->toIso8601String(),
        ];
    }
}
