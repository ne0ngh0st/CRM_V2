<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visão Diretor → Maiores redes: as maiores redes do MERCADO (planilha da diretoria 2026,
 * carregada de `database/data/visao-diretor/redes-mercado-2026.json`) e quais clientes e
 * leads nossos são de cada uma.
 *
 * ⚠️ Não é `contas_estrategicas`. Aquelas eram as contas da Maiores por Segmento antiga,
 * fora da tela desde 2026-10-14; estas são outra lista ("é outra coisa, não é pra juntar",
 * Tony, 2026-10-16).
 *
 * Os contadores da rede (clientes, ativos, vendedor, última compra, status) moram na
 * própria linha e são recalculados por `MaioresRedes` — a página lê uma tabela de ~900
 * linhas sem agregar nada ao vivo (Regra de ouro nº 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redes_mercado', function (Blueprint $table) {
            $table->id();
            // Chave de `CategoriasDeRede` (drogarias, supermercados, ...). Não é segmento do TOTVS.
            $table->string('categoria', 30);
            $table->unsignedInteger('posicao');
            $table->string('nome', 150);
            $table->json('apelidos')->nullable();
            $table->string('uf', 2)->nullable();
            $table->unsignedInteger('lojas')->nullable();
            $table->string('data_base', 20)->nullable();
            $table->string('qualidade', 20);
            $table->string('fonte', 500)->nullable();
            $table->string('linha_original');
            // Saiu do JSON numa recarga: some da tela, vínculos manuais ficam guardados.
            $table->boolean('ativa')->default(true);

            $table->unsignedInteger('clientes')->default(0);
            $table->unsignedInteger('filiais')->default(0);
            $table->unsignedInteger('clientes_ativos')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->date('ultima_compra')->nullable();
            $table->string('vendedor', 120)->nullable();
            $table->enum('status', ['ativo', 'a_trabalhar'])->default('a_trabalhar');
            $table->timestamps();

            $table->unique(['categoria', 'nome']);
            $table->index(['ativa', 'categoria', 'status']);
        });

        Schema::create('redes_mercado_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rede_id')->constrained('redes_mercado')->cascadeOnDelete();
            $table->enum('tipo', ['cliente', 'lead']);
            // cod_cliente (o cliente inteiro, todas as filiais) ou leads.id.
            $table->string('codigo', 20);
            $table->enum('nivel', ['manual', 'nome', 'grupo', 'mesma_empresa']);
            // Removido pela tela: o motor não liga de novo ESTE código a ESTA rede.
            $table->boolean('recusado')->default(false);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['rede_id', 'tipo', 'codigo']);
            $table->index(['tipo', 'codigo']);
        });

        // ⚠️ CADA CLIENTE PERTENCE A UMA REDE SÓ, garantido pelo banco: o vínculo vivo
        // (não recusado) gera uma chave única; o recusado gera NULL, que o índice único
        // aceita repetido. Sem isso o mesmo cliente contaria em duas linhas da página.
        DB::statement("ALTER TABLE redes_mercado_vinculos ADD COLUMN dono CHAR(30)
            GENERATED ALWAYS AS (IF(recusado, NULL, CONCAT(tipo, ':', codigo))) STORED,
            ADD UNIQUE INDEX redes_mercado_vinculos_dono_unique (dono)");
    }

    public function down(): void
    {
        Schema::dropIfExists('redes_mercado_vinculos');
        Schema::dropIfExists('redes_mercado');
    }
};
