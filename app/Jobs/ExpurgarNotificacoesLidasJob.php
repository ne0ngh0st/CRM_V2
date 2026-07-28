<?php

namespace App\Jobs;

use App\Models\Notificacao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roda 1x/semana (ver routes/console.php). Evita o crescimento sem fim que o
 * legado tinha nas tabelas de notificação (nunca tinham expurgo de verdade).
 */
class ExpurgarNotificacoesLidasJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Notificacao::query()
            ->whereNotNull('lida_em')
            ->where('lida_em', '<', now()->subDays(30))
            ->delete();
    }
}
