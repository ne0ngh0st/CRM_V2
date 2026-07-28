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

        $notificacao = Notificacao::create([
            'user_id' => $destinatario->id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'mensagem' => $mensagem,
            'link' => $link,
            'referencia_tipo' => $referenciaTipo,
            'referencia_id' => $referenciaId,
        ]);

        event(new NotificacaoCriada($notificacao));

        return $notificacao;
    }
}
