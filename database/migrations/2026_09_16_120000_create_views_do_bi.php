<?php

use App\Services\PowerBi\ViewsBi;
use Illuminate\Database\Migrations\Migration;

/**
 * As views `vw_bi_*` que o Power BI lê, no schema `bi`.
 *
 * A SQL NÃO mora aqui: está em `App\Services\PowerBi\ViewsBi` (ver o porquê lá — a view
 * grava o nome do banco do app, que muda entre dev e suíte). Mudar uma view depois
 * disto é uma migration nova chamando `ViewsBi::recriar()`, ou `bi:recriar-views`.
 */
return new class extends Migration
{
    public function up(): void
    {
        ViewsBi::recriar();
    }

    public function down(): void
    {
        ViewsBi::remover();
    }
};
