<?php

use App\Services\PowerBi\ViewsBi;
use Illuminate\Database\Migrations\Migration;

/**
 * `vw_bi_fato_orcamentos` passa a ter `status_cliente` e `data_aprovacao_cliente` (antes
 * NULL fixo: o CRM não registrava o aceite do cliente). A SQL mora em `ViewsBi`.
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
