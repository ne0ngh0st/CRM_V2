<?php

namespace App\Jobs;

use App\Services\Receita\PedidoDeInativacao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lista diária de pedidos de inativação para o Cadastro (dias úteis, 18:00 — ver
 * routes/console.php). Um e-mail só com tudo que foi pedido desde a última lista; sem
 * pedido pendente, não manda nada. A regra mora em `PedidoDeInativacao::enviarPendentes()`.
 */
class EnviarInativacoesDoDiaJob implements ShouldQueue
{
    use Queueable;

    public function handle(PedidoDeInativacao $pedidos): void
    {
        // Em manutenção não sai lista; o pendente espera a feature ser religada.
        if (! config('receita.inativacao_habilitada')) {
            return;
        }

        $pedidos->enviarPendentes();
    }
}
