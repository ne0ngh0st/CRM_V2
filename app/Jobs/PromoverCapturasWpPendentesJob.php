<?php

namespace App\Jobs;

use App\Models\MarketingWpLeadRaw;
use App\Services\Marketing\WpLeadIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(WpLeadIngestor $ingestor): void
    {
        MarketingWpLeadRaw::query()
            ->pendentes()
            ->orderBy('id')
            ->limit(self::LOTE)
            ->get()
            ->each(fn (MarketingWpLeadRaw $staging) => $ingestor->promover($staging));
    }
}
