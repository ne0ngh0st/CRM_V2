<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As listas por trás de cada número do card "Leads da prospecção" (quais CNPJs já eram
 * clientes, quais a Receita barrou e por quê, quais redes foram criadas…). Pedido do Tony
 * (2026-10-02): "tudo clicável, pra eu saber". `resultado` continua com as contagens; isto
 * é só o detalhe, lido no clique (`AtualizacaoDadosController::detalheLeads`), nunca no
 * carregamento da página.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads_importacoes', function (Blueprint $table) {
            $table->json('detalhes')->nullable()->after('recusadas');
        });
    }

    public function down(): void
    {
        Schema::table('leads_importacoes', function (Blueprint $table) {
            $table->dropColumn('detalhes');
        });
    }
};
