<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\MarketingWpLeadRaw;
use App\Models\User;
use App\Services\Marketing\WpLeadIngestor;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rede de segurança da captura do site: pega o que chegou mas não virou lead.
 *
 * O envelope é commitado sozinho (ver WpLeadIngestor), então uma falha na
 * promoção — banco em failover no instante do POST, `*` de
 * marketing_wp_formularios ainda não cadastrado, campo novo estourando o
 * tamanho da coluna — deixa a linha com `lead_id` nulo em vez de perder o
 * lead. Este job roda de minuto a minuto e fecha a lacuna sozinho.
 *
 * ⚠️ É isto que sustenta a promessa de "funciona sem ninguém cuidar". Se
 * alguém desligar o agendamento em routes/console.php, o webhook continua
 * capturando, mas lead que falhar na primeira tentativa fica parado para
 * sempre — e ninguém percebe, porque a captura em si continua respondendo 201.
 */
class PromoverCapturasWpPendentesJob implements ShouldQueue
{
    use Queueable;

    /** Teto por rodada: o job roda a cada minuto, não precisa esvaziar tudo de uma vez. */
    private const LOTE = 200;

    public function handle(WpLeadIngestor $ingestor, NotificacaoService $notificacoes): void
    {
        MarketingWpLeadRaw::query()
            ->pendentes()
            ->orderBy('id')
            ->limit(self::LOTE)
            ->get()
            ->each(function (MarketingWpLeadRaw $staging) use ($ingestor, $notificacoes) {
                $lead = $ingestor->promover($staging);

                if ($lead === null) {
                    $this->avisarSeTravou($staging->fresh(), $notificacoes);
                }
            });
    }

    /**
     * Captura que esgotou as tentativas vira aviso para o admin.
     *
     * ⚠️ Sem isto, a falha é MUDA: o webhook responde 201 normalmente, o cliente acha que
     * mandou, e a única pista é um contador na barra da /leads que ninguém olha — o
     * CLAUDE.md chegava a mandar consultar a coluna `erro` no banco à mão. É exatamente o
     * tipo de silêncio que a Regra de ouro nº 9 dos alarmes trata: dado ausente não pode
     * ser lido como saúde.
     *
     * ⚠️ Notifica só na TRANSIÇÃO para travada, e são DOIS mecanismos diferentes — não
     * confundir. O que evita o aviso por minuto é o escopo `pendentes()` (lead_id nulo E
     * tentativas < MAX): assim que a captura trava, ela deixa de ser devolvida e este
     * método nem é chamado. A chave de idempotência cobre o que o escopo não alcança —
     * dois workers processando o mesmo lote em paralelo, ambos vendo tentativas = MAX-1
     * e ambos chegando aqui.
     *
     * ⚠️ Nunca deixa o job cair: uma notificação que falha não pode impedir a promoção
     * das outras capturas do lote.
     */
    private function avisarSeTravou(?MarketingWpLeadRaw $staging, NotificacaoService $notificacoes): void
    {
        if ($staging === null || $staging->lead_id !== null) {
            return;
        }

        if ($staging->tentativas < MarketingWpLeadRaw::MAX_TENTATIVAS) {
            return;
        }

        try {
            $admins = User::query()->where('is_active', true)->role('admin')->get();

            foreach ($admins as $admin) {
                $notificacoes->notificar(
                    destinatario: $admin,
                    tipo: 'captura_wp_travada',
                    titulo: 'Captura do site nao virou lead',
                    mensagem: 'Apos '.$staging->tentativas.' tentativas: '.($staging->erro ?: 'motivo nao registrado'),
                    link: route('leads.index', ['origem' => Lead::ORIGEM_WORDPRESS]),
                    referenciaTipo: 'marketing_wp_lead_raw',
                    referenciaId: $staging->id,
                );
            }
        } catch (Throwable $e) {
            Log::warning('wp-lead: nao consegui avisar sobre captura travada', [
                'staging_id' => $staging->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
