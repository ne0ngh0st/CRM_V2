<?php

namespace App\Jobs;

use App\Models\AgendamentoLigacao;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roda 1x/dia (ver routes/console.php). Idempotente via referencia_tipo/id —
 * pode rodar mais de uma vez no mesmo dia sem duplicar a notificação.
 */
class NotificarAgendamentosDoDiaJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificacaoService $notificacaoService): void
    {
        AgendamentoLigacao::query()
            ->where('status', 'agendado')
            ->whereDate('data_agendamento', now()->toDateString())
            ->with(['cliente:id,razao_social', 'lead:id,razao_social,nome', 'user'])
            ->get()
            ->each(function (AgendamentoLigacao $agendamento) use ($notificacaoService) {
                $nome = $agendamento->cliente?->razao_social
                    ?? $agendamento->lead?->razao_social
                    ?? $agendamento->lead?->nome
                    ?? 'contato';

                $notificacaoService->notificar(
                    destinatario: $agendamento->user,
                    tipo: 'agendamento_ligacao_hoje',
                    titulo: "Ligação agendada hoje: {$nome}",
                    mensagem: optional($agendamento->data_agendamento)->format('H:i'),
                    link: $agendamento->cliente_id ? route('carteira.index') : route('leads.index'),
                    referenciaTipo: 'agendamento_ligacao',
                    referenciaId: $agendamento->id,
                );
            });
    }
}
