<?php

namespace App\Jobs;

use App\Models\IaMensagem;
use App\Services\Escopo\ModoVisao;
use App\Services\Ia\Orquestrador;
use App\Services\Ia\RespostaCancelada;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Responde uma pergunta do assistente de IA.
 *
 * ⚠️ Fila PRÓPRIA (`ia`), com worker próprio (infra/configurar-daemons.sh). O worker da
 * `default` processa exportação de 95 s e import do TOTVS: atrás deles, o chat
 * esperaria minutos para começar a responder.
 *
 * ⚠️ `tries = 1`: retentar refaria as consultas e cobraria a API de novo por uma
 * resposta que o usuário pode já ter desistido de esperar. Falhou, avisa, e ele pergunta
 * de novo.
 */
class ResponderMensagemIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly int $mensagemId,
        public readonly ?string $modoVisao,
    ) {
        $this->timeout = (int) config('ia.timeout_job');
        $this->onQueue(config('ia.fila'));
    }

    public function handle(): void
    {
        // Antes de resolver qualquer coisa do container: o supervisor em "Minha
        // carteira" tem que receber resposta da carteira dele, não da equipe.
        ModoVisao::restaurarSemSessao($this->modoVisao);

        $mensagem = IaMensagem::query()->with('conversa.user')->find($this->mensagemId);

        if ($mensagem === null || $mensagem->status !== IaMensagem::STATUS_PROCESSANDO) {
            return;
        }

        try {
            app(Orquestrador::class)->responder($mensagem);
        } catch (RespostaCancelada) {
            // O usuário apertou "Parar": o endpoint já marcou tudo como cancelado.
        } catch (Throwable $e) {
            app(Orquestrador::class)->falhou($mensagem, $e->getMessage());
        }
    }

    /** Timeout do worker: o `catch` acima não roda, então quem marca o erro é este. */
    public function failed(?Throwable $e): void
    {
        $mensagem = IaMensagem::query()->with('conversa')->find($this->mensagemId);

        if ($mensagem?->status === IaMensagem::STATUS_PROCESSANDO) {
            app(Orquestrador::class)->falhou($mensagem, $e?->getMessage() ?? 'falhou');
        }
    }
}
