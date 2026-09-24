<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cartão CNPJ consultado na Receita (via API pública), um por CNPJ.
 *
 * Chaveado pelo CNPJ de 14 dígitos, NÃO pelo cliente: o mesmo documento pode estar
 * num lead e num cliente, e a Receita responde por documento. Também não é por raiz
 * (Regra de ouro nº 3) — cada filial tem o próprio cartão.
 *
 * `situacao` fica em coluna à parte do JSON para poder virar filtro da Carteira sem
 * `JSON_EXTRACT` (a pergunta que motivou tudo: "este inativo ainda existe?").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnpj_consultas', function (Blueprint $table) {
            $table->id();
            $table->char('cnpj', 14)->unique();
            $table->string('situacao', 20)->nullable()->index();
            $table->json('dados');
            $table->string('fonte', 20);
            $table->dateTime('consultado_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnpj_consultas');
    }
};
