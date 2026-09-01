<?php

namespace App\Console\Commands;

use App\Models\MarketingWpLeadRaw;
use App\Services\Marketing\WpLeadIngestor;
use Illuminate\Console\Command;

/**
 * Reprocessa capturas do site que desistiram de virar lead.
 *
 * O caso real que isto atende: o plugin do site posta num formato que o
 * WpLeadPayloadParser ainda não reconhece. As capturas entram, o envelope é
 * guardado (nada se perde), mas a promoção marca
 * `payload_sem_campos_comerciais` e zera as tentativas de propósito — retentar
 * o mesmo parser daria o mesmo resultado para sempre.
 *
 * Depois de ensinar o alias novo ao parser, este comando zera o contador e
 * manda promover de novo. Só toca no que NÃO virou lead: captura já promovida
 * é ignorada, então rodar duas vezes não duplica nada.
 *
 *   php artisan marketing:repromover-wp --dry-run
 *   php artisan marketing:repromover-wp
 */
class RepromoverCapturasWp extends Command
{
    protected $signature = 'marketing:repromover-wp
        {--dry-run : só mostra o que seria reprocessado}';

    protected $description = 'Reprocessa capturas do site que não viraram lead (use depois de ajustar o parser)';

    public function handle(WpLeadIngestor $ingestor): int
    {
        $travadas = MarketingWpLeadRaw::query()->travadas()->orderBy('id')->get();

        if ($travadas->isEmpty()) {
            $this->info('Nenhuma captura travada. Nada a fazer.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'recebido em', 'fonte', 'erro'],
            $travadas->map(fn (MarketingWpLeadRaw $s) => [
                $s->id,
                $s->recebido_em?->format('d/m/Y H:i'),
                $s->fonte,
                $s->erro,
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment($travadas->count().' captura(s) seriam reprocessadas. Nada foi alterado.');

            return self::SUCCESS;
        }

        $ok = 0;
        foreach ($travadas as $staging) {
            $staging->forceFill(['tentativas' => 0, 'erro' => null])->save();
            if ($ingestor->promover($staging) !== null) {
                $ok++;
            }
        }

        $this->info("Promovidas: {$ok} de {$travadas->count()}.");

        if ($ok < $travadas->count()) {
            $this->warn('As que sobraram continuam com o motivo na coluna `erro` de marketing_wp_leads_raw.');
        }

        return self::SUCCESS;
    }
}
