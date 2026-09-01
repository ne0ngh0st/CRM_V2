<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\MarketingWpLeadRaw;
use App\Services\Marketing\WpLeadIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mantém a staging da captura do site com tamanho limitado.
 *
 * Sem isto, `marketing_wp_leads_raw` só cresce — e ela guarda o corpo inteiro
 * de cada POST num longText. É o mesmo problema que o legado tinha em todas as
 * tabelas de log (nenhuma tinha expurgo), e o mesmo remédio do
 * ExpurgarNotificacoesLidasJob.
 *
 * ⚠️ Só apaga o ENVELOPE, nunca o lead comercial: o vendedor pode estar
 * trabalhando um lead de meses atrás. A exceção é o lead de TESTE, que é
 * descartável por definição e é apagado junto — é o que permite o botão
 * "Enviar lead de teste" existir sem sujar a carteira de ninguém.
 */
class ExpurgarCapturasWpJob implements ShouldQueue
{
    use Queueable;

    /** Envelope velho já cumpriu o papel de prova/depuração. */
    private const DIAS_ENVELOPE = 180;

    /** Teste serve para confirmar o pipeline agora, não para virar histórico. */
    private const HORAS_TESTE = 24;

    public function handle(): void
    {
        $this->expurgarTestes();

        MarketingWpLeadRaw::query()
            ->where('fonte', '!=', WpLeadIngestor::FONTE_TESTE)
            ->where('recebido_em', '<', now(WpLeadIngestor::TZ)->subDays(self::DIAS_ENVELOPE)->format('Y-m-d H:i:s'))
            ->delete();
    }

    /** Apaga a staging de teste E o lead que ela gerou — nesta ordem, por causa da FK. */
    private function expurgarTestes(): void
    {
        $limite = now(WpLeadIngestor::TZ)->subHours(self::HORAS_TESTE)->format('Y-m-d H:i:s');

        $stagings = MarketingWpLeadRaw::query()
            ->where('fonte', WpLeadIngestor::FONTE_TESTE)
            ->where('recebido_em', '<', $limite)
            ->get(['id', 'lead_id']);

        if ($stagings->isEmpty()) {
            return;
        }

        $leadIds = $stagings->pluck('lead_id')->filter()->unique()->values();

        MarketingWpLeadRaw::query()->whereIn('id', $stagings->pluck('id'))->delete();

        if ($leadIds->isNotEmpty()) {
            // Guarda de segurança: só remove lead que continua sendo do site e
            // que nenhuma outra captura referencia.
            Lead::query()
                ->whereIn('id', $leadIds)
                ->where('origem', Lead::ORIGEM_WORDPRESS)
                ->whereDoesntHave('stagingWordpress')
                ->delete();
        }
    }
}
