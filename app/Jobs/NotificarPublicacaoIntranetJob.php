<?php

namespace App\Jobs;

use App\Models\IntranetPublicacao;
use App\Models\User;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa todo mundo de uma publicação nova na intranet — ou de que uma regra mudou e
 * precisa de ciência de novo.
 *
 * ⚠️ FILA, não requisição. São ~200 destinatários, cada um com um insert e um evento no
 * Reverb: 1-2 s de trabalho que, dentro do POST de publicar, estourariam o orçamento de
 * escrita da Regra de ouro nº 9 (500 ms). Publicar é raro — poucas vezes por semana —, então
 * o custo no worker é irrelevante.
 *
 * ⚠️ Idempotente em retry: a referência é `(intranet_publicacao:r<revisão>, id)` e o
 * `NotificacaoService` não cria duas vezes a mesma notificação para a mesma pessoa. Se o
 * worker morrer no destinatário 120, a nova tentativa pula os 119 primeiros.
 *
 * ⚠️ A REVISÃO entra na referência de propósito: "pedir ciência de novo" sobe o número, e é
 * isso que faz o segundo aviso existir. Com a referência fixa, a dedupe engoliria o aviso
 * em silêncio — o autor pediria ciência de novo e ninguém ficaria sabendo.
 */
class NotificarPublicacaoIntranetJob implements ShouldQueue
{
    use Queueable;

    public const MOTIVO_NOVA = 'nova';

    public const MOTIVO_CIENCIA = 'ciencia';

    public function __construct(
        public int $publicacaoId,
        public string $motivo = self::MOTIVO_NOVA,
    ) {}

    public function handle(NotificacaoService $notificacaoService): void
    {
        // Excluída entre o publicar e o job rodar: não há o que avisar.
        $publicacao = IntranetPublicacao::with('autor:id,name,display_name')->find($this->publicacaoId);

        if ($publicacao === null) {
            return;
        }

        $tipo = $publicacao->importante ? 'intranet_importante' : 'intranet';
        $autor = $publicacao->autor?->display_name ?: $publicacao->autor?->name;
        $categoria = IntranetPublicacao::rotuloDaCategoria($publicacao->categoria);

        [$titulo, $mensagem] = $this->motivo === self::MOTIVO_CIENCIA
            ? ["Regra atualizada: {$publicacao->titulo}", 'Mudou — confirme que leu a versão nova.']
            : ["Intranet: {$publicacao->titulo}", trim("{$categoria} · por {$autor}", ' ·')];

        User::query()
            ->where('is_active', true)
            ->whereKeyNot($publicacao->user_id)
            ->select(['id'])
            ->chunkById(100, function ($usuarios) use ($notificacaoService, $publicacao, $tipo, $titulo, $mensagem) {
                foreach ($usuarios as $usuario) {
                    $notificacaoService->notificar(
                        destinatario: $usuario,
                        tipo: $tipo,
                        titulo: $titulo,
                        mensagem: $mensagem,
                        link: route('intranet.show', $publicacao->id, false),
                        referenciaTipo: 'intranet_publicacao:r'.$publicacao->revisao_ciencia,
                        referenciaId: $publicacao->id,
                    );
                }
            });
    }
}
