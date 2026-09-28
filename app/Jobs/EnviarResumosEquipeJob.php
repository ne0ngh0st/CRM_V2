<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Disparo agendado do resumo diário (dias úteis, 18:00 — ver routes/console.php).
 *
 * Só distribui: um {@see EnviarResumoEquipeJob} por destinatário, para que uma equipe
 * com dado problemático (ou um SMTP recusando um endereço) não impeça os outros de
 * receber.
 *
 * ⚠️ Nasce desligado (`resumo_equipe.habilitado`). O caminho para validar antes de ligar
 * é o comando `resumo-equipe:enviar --para=...`, que não passa por aqui.
 */
class EnviarResumosEquipeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (! config('resumo_equipe.habilitado')) {
            return;
        }

        User::query()
            ->where('is_active', true)
            ->whereIn('resumo_diario', ['equipe', 'consolidado'])
            ->pluck('id')
            ->each(fn (int $id) => EnviarResumoEquipeJob::dispatch($id));
    }
}
