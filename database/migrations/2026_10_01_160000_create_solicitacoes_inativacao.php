<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pedido de inativação de cliente com CNPJ irregular na Receita, enviado ao Cadastro
 * pela Carteira (botão "Solicitar inativação").
 *
 * O CRM não inativa nada — a Carteira é só leitura (Regra de ouro nº 4) e quem inativa
 * é o Cadastro, no TOTVS. Esta tabela existe só para registrar QUEM pediu e QUANDO:
 * é o que impede o mesmo cliente de ser pedido duas vezes e o que a tela mostra no
 * lugar do botão depois do pedido.
 *
 * `cnpj` e `situacao_receita` são o retrato do momento do pedido, de propósito: se a
 * situação mudar depois, o registro continua dizendo por que o pedido foi feito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitacoes_inativacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->char('cnpj', 14);
            $table->string('situacao_receita', 20);
            $table->foreignId('solicitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cliente_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitacoes_inativacao');
    }
};
