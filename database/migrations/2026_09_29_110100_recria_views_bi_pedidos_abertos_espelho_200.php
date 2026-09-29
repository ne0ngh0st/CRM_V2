<?php

use App\Services\PowerBi\ViewsBi;
use Illuminate\Database\Migrations\Migration;

/**
 * `vw_bi_fato_pedidos_abertos` passa a ignorar pedido marcado `fora_do_200`, pelo mesmo
 * motivo de `/pedidos-abertos`: pedido de mês anterior que já faturou ficava em aberto
 * para sempre. A SQL mora em `ViewsBi`; aqui só se recria.
 */
return new class extends Migration
{
    public function up(): void
    {
        ViewsBi::recriar();
    }

    public function down(): void
    {
        ViewsBi::recriar();
    }
};
