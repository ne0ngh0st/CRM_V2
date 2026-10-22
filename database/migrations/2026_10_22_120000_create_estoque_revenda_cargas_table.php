<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada Relatório de Revenda carregado em `estoque_revenda`.
 *
 * O RV chega todo dia como um arquivo NOVO (`Relatorio de Revenda DD-MM-AAAA.xlsx`),
 * sem sobrescrever o anterior: a coleção de versões mora na pasta e no S3, e o banco
 * guarda só a última foto. Esta tabela responde duas perguntas sem abrir arquivo
 * nenhum:
 *   - de que DIA é o estoque que a Cotação está usando (a data do NOME do arquivo, não
 *     a hora da importação);
 *   - se o RV mais novo da pasta já foi carregado — é o que deixa o import rodar de
 *     hora em hora sem trabalho repetido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_revenda_cargas', function (Blueprint $table) {
            $table->id();
            $table->string('arquivo');
            $table->date('data_rv');
            $table->unsignedInteger('itens');
            $table->timestamp('importado_em')->useCurrent();

            $table->index('data_rv');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_revenda_cargas');
    }
};
