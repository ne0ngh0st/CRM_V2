<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log do que sai pelo SMTP — alimenta a tela admin `/emails`.
 *
 * ⚠️ Guarda só o ENVELOPE (destinos, assunto, anexos), nunca o corpo: o e-mail de
 * "esqueci minha senha" carrega o link de redefinição, e o corpo de Cadastros tem dados
 * de cliente. Para conferir roteamento o envelope basta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails_enviados', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['enviado', 'falhou']);
            $table->string('assunto', 500)->nullable();
            $table->string('remetente')->nullable();
            $table->text('para')->nullable();
            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();
            $table->json('anexos')->nullable();
            $table->string('origem')->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails_enviados');
    }
};
