<?php

namespace App\Jobs;

use App\Models\Pedido;
use App\Models\User;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roda 1x/dia (ver routes/console.php). Reusa o mesmo critério de
 * DashboardController::pedidosAtencao, mas notifica por pedido individual em
 * vez de recomputar o digest a cada leitura. Idempotente via referencia_tipo/id
 * — um pedido só gera uma notificação de "atrasado" e uma de "vencendo".
 */
class NotificarPedidosAtencaoJob implements ShouldQueue
{
    use Queueable;

    public function handle(NotificacaoService $notificacaoService): void
    {
        $hoje = now()->toDateString();
        $em7Dias = now()->addDays(7)->toDateString();

        $emAberto = Pedido::query()->whereNull('data_faturamento')->with('cliente:id,razao_social');

        (clone $emAberto)
            ->whereDate('data_previsao_faturamento', '<', $hoje)
            ->get()
            ->each(fn (Pedido $p) => $this->notificarVendedores($notificacaoService, $p, 'pedido_atrasado', "Pedido #{$p->numero_pedido} atrasado"));

        (clone $emAberto)
            ->whereBetween('data_previsao_faturamento', [$hoje, $em7Dias])
            ->get()
            ->each(fn (Pedido $p) => $this->notificarVendedores($notificacaoService, $p, 'pedido_vencendo', "Pedido #{$p->numero_pedido} vence em breve"));
    }

    private function notificarVendedores(NotificacaoService $notificacaoService, Pedido $pedido, string $tipo, string $titulo): void
    {
        $vendedores = User::whereHas('vendedorPerfil', fn ($q) => $q->where('cod_vendedor', $pedido->cod_vendedor))->get();

        foreach ($vendedores as $vendedor) {
            $notificacaoService->notificar(
                destinatario: $vendedor,
                tipo: $tipo,
                titulo: $titulo,
                mensagem: $pedido->cliente?->razao_social,
                link: route('pedidos.index'),
                referenciaTipo: 'pedido',
                referenciaId: $pedido->id,
            );
        }
    }
}
