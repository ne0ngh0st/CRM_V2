<?php

namespace App\Services\Notificacao;

use App\Events\NotificacaoCriada;
use App\Models\Notificacao;
use App\Models\User;

/**
 * Único ponto de criação de notificações. Escreve a notificação no momento do
 * evento (nunca recomputada na leitura — ver CLAUDE.md, seção "Sistema de
 * Notificações") e dispara o broadcast pro sino em tempo real via Reverb.
 */
class NotificacaoService
{
    public function notificar(
        User $destinatario,
        string $tipo,
        string $titulo,
        ?string $mensagem = null,
        ?string $link = null,
        ?string $referenciaTipo = null,
        ?int $referenciaId = null,
    ): ?Notificacao {
        if ($referenciaTipo !== null && $referenciaId !== null) {
            $jaExiste = Notificacao::query()
                ->where('user_id', $destinatario->id)
                ->where('tipo', $tipo)
                ->where('referencia_tipo', $referenciaTipo)
                ->where('referencia_id', $referenciaId)
                ->exists();

            if ($jaExiste) {
                return null;
            }
        }

        /*
         * ⚠️ `titulo` e `mensagem` são varchar(255), e parte das mensagens vem de FORA —
         * o erro do Portal chegou com 400+ caracteres (um SOAP-ERROR do Protheus) e o
         * INSERT estourou: o vendedor ficou sem aviso e o job, ao retentar, falhou de
         * novo por outro motivo. O sino é um aviso; o texto inteiro mora na origem
         * (ex.: `orcamentos.portal_erro`).
         */
        $notificacao = Notificacao::create([
            'user_id' => $destinatario->id,
            'tipo' => $tipo,
            'titulo' => mb_strimwidth($titulo, 0, 255, '…'),
            'mensagem' => $mensagem === null ? null : mb_strimwidth($mensagem, 0, 255, '…'),
            'link' => $link,
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
        ]);

        event(new NotificacaoCriada($notificacao));

        return $notificacao;
    }
}
