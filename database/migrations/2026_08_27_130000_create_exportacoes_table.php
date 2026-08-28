<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exportações de planilha geradas em segundo plano.
 *
 * ⚠️ NASCE DE UM PROBLEMA MEDIDO, NÃO DE UMA PREFERÊNCIA POR FILAS.
 * O export da Carteira sem filtro (escopo admin, ~90k clientes) leva ~95 s e consome
 * ~540 MB de pico. Atrás do ALB, cujo idle timeout padrão é 60 s, isso é um 504
 * garantido: o usuário recebe erro enquanto o servidor continua queimando memória para
 * produzir um arquivo que ninguém vai receber.
 *
 * Só a Carteira usa este caminho por enquanto — é a única exportação com custo medido
 * que justifica a assincronia. As outras oito seguem síncronas com o guard do
 * trait ExportaPlanilha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exportacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('recurso', 40);
            // Os filtros ativos no momento do pedido. Guardados para que o arquivo seja
            // auditável depois ("por que este Excel tem 300 linhas e não 90 mil?").
            $table->json('filtros')->nullable();

            $table->string('status', 20)->default('processando');
            $table->string('caminho')->nullable();
            $table->string('nome_arquivo')->nullable();
            $table->unsignedInteger('linhas')->nullable();
            $table->text('erro')->nullable();

            // Arquivo de export é descartável e pesado: sem prazo de validade, o disco
            // do servidor cresce para sempre. O ExpurgarExportacoesJob limpa a partir daqui.
            $table->timestamp('expira_em')->nullable();

            $table->timestamps();

            // A tela lista "minhas exportações recentes"; o expurgo varre por validade.
            $table->index(['user_id', 'created_at']);
            $table->index('expira_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exportacoes');
    }
};
