<?php

namespace App\Http\Controllers;

use App\Jobs\ResponderMensagemIaJob;
use App\Models\IaConversa;
use App\Models\IaMensagem;
use App\Services\Escopo\ModoVisao;
use App\Services\Ia\AcessoIa;
use App\Services\Ia\PromptDoSistema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * O chat do assistente de IA (painel lateral do layout).
 *
 * ⚠️ `enviar` NÃO responde a pergunta: grava, enfileira e devolve em milissegundos. A
 * resposta leva de 5 a 20 s e vem pelo Reverb (`IaEvento`) — com `mensagem` como rede de
 * segurança para quando o WebSocket cair. Regra de ouro nº 9.
 *
 * Tudo é JSON e chamado por `fetch`: o painel vive por cima de qualquer página, e uma
 * visita do Inertia recarregaria a página de baixo.
 */
class IaController extends Controller
{
    public function __construct(
        private readonly AcessoIa $acesso,
        private readonly PromptDoSistema $prompt,
        private readonly ModoVisao $modo,
    ) {}

    public function enviar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'texto' => ['required', 'string', 'max:2000'],
            'conversa_id' => ['nullable', 'integer'],
            'tela' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();

        if ($bloqueio = $this->acesso->bloqueio($user)) {
            return response()->json(['erro' => $bloqueio], 429);
        }

        $conversa = null;
        if (! empty($dados['conversa_id'])) {
            $conversa = IaConversa::query()->whereKey($dados['conversa_id'])->where('user_id', $user->id)->firstOrFail();

            $pendente = $conversa->mensagens()->where('status', IaMensagem::STATUS_PROCESSANDO)->get()
                ->contains(fn (IaMensagem $m) => ! $m->travou());
            if ($pendente) {
                return response()->json(['erro' => 'Aguarde a resposta anterior terminar.'], 409);
            }
        }

        $conversa ??= IaConversa::create([
            'user_id' => $user->id,
            'admin_simulando_id' => $request->session()->get(SimulacaoController::SESSAO_ADMIN_ID),
            'titulo' => Str::limit(trim($dados['texto']), 110, '…'),
            'modelo' => config('ia.modelo'),
        ]);

        $tela = $dados['tela'] ?? null;

        $pergunta = $conversa->mensagens()->create([
            'papel' => 'usuario',
            'texto' => trim($dados['texto']),
            'tela' => $tela,
            'conteudo_api' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $this->prompt->contexto($user, $tela, now())."\n\n".trim($dados['texto'])]],
            ]],
        ]);

        $resposta = $conversa->mensagens()->create([
            'papel' => 'ia',
            'status' => IaMensagem::STATUS_PROCESSANDO,
        ]);

        ResponderMensagemIaJob::dispatch($resposta->id, $this->modo->atual());

        return response()->json([
            'conversaId' => $conversa->id,
            'pergunta' => $pergunta->paraTela(),
            'resposta' => $resposta->fresh()->paraTela(),
        ]);
    }

    /** Polling: o estado atual de uma resposta. */
    public function mensagem(Request $request, IaMensagem $mensagem): JsonResponse
    {
        $this->autorizar($request, $mensagem->conversa);

        return response()->json($mensagem->paraTela());
    }

    /**
     * "Parar": o modelo entendeu errado e o usuário não quer esperar a resposta inteira.
     *
     * Marca a resposta E a pergunta como canceladas (as duas saem do histórico — a
     * tentativa descartada só confundiria a próxima pergunta) e avisa o job pelo Redis.
     * Devolve o texto da pergunta para o painel pôr de volta na caixa, pronto para
     * corrigir.
     *
     * ⚠️ O UPDATE é condicional a "processando": se a resposta terminou um instante antes
     * do clique, ela fica — cancelar uma resposta já entregue apagaria do histórico algo
     * que o usuário já leu.
     */
    public function parar(Request $request, IaMensagem $mensagem): JsonResponse
    {
        $this->autorizar($request, $mensagem->conversa);
        abort_unless($mensagem->papel === 'ia', 422);

        $cancelou = IaMensagem::query()
            ->whereKey($mensagem->id)
            ->where('status', IaMensagem::STATUS_PROCESSANDO)
            ->update(['status' => IaMensagem::STATUS_CANCELADA, 'updated_at' => now()]);

        $pergunta = IaMensagem::query()
            ->where('conversa_id', $mensagem->conversa_id)
            ->where('id', '<', $mensagem->id)
            ->where('papel', 'usuario')
            ->orderByDesc('id')
            ->first();

        if ($cancelou) {
            $mensagem->pedirParada();
            $pergunta?->update(['status' => IaMensagem::STATUS_CANCELADA]);
        }

        return response()->json([
            'cancelou' => (bool) $cancelou,
            'mensagem' => $mensagem->fresh()->paraTela(),
            'pergunta' => $pergunta?->fresh()->paraTela(),
        ]);
    }

    public function avaliar(Request $request, IaMensagem $mensagem): JsonResponse
    {
        $this->autorizar($request, $mensagem->conversa);
        abort_unless($mensagem->papel === 'ia', 422);

        $dados = $request->validate(['avaliacao' => ['nullable', 'integer', 'in:1,-1']]);
        $mensagem->update(['avaliacao' => $dados['avaliacao'] ?? null]);

        return response()->json($mensagem->paraTela());
    }

    public function conversas(Request $request): JsonResponse
    {
        return response()->json(IaConversa::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'titulo', 'updated_at'])
            ->map(fn (IaConversa $c) => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'quando' => $c->updated_at?->format('d/m H:i'),
            ]));
    }

    public function conversa(Request $request, IaConversa $conversa): JsonResponse
    {
        $this->autorizar($request, $conversa);

        return response()->json([
            'id' => $conversa->id,
            'mensagens' => $conversa->mensagens->map->paraTela()->values(),
        ]);
    }

    /** Só o dono. Nem admin lê a conversa de outro por aqui — para isso existe a tela admin. */
    private function autorizar(Request $request, ?IaConversa $conversa): void
    {
        abort_unless($conversa !== null && $conversa->user_id === $request->user()->id, 404);
    }
}
