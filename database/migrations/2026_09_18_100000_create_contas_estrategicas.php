<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visão Diretor — "Maiores por Segmento" (2026-09-18).
 *
 * A conta-alvo é uma rede do MERCADO (Raia Drogasil, 2.390 filiais no Brasil), não um
 * cliente nosso. O que a liga ao CRM são os vínculos: grupos de cliente do TOTVS
 * (`cod_grupo`, o caso comum — a ESTAPAR são 21 grupos, um por UF) e códigos de cliente
 * avulsos. Tudo o que a planilha preenchia à mão (quem atende, status) passa a ser
 * derivado desses vínculos. Ver docs/visao-diretor.md.
 *
 * ⚠️ `vinculos_versao` existe para o CACHE da Carteira, não para auditoria: o filtro
 * `?conta_alvo=` entra na chave do total cacheado junto com esta versão. Sem ela, editar um
 * vínculo aqui deixaria a Carteira mostrando o total antigo por até 10 minutos — e o número
 * clicado deixaria de bater com a lista aberta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contas_estrategicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segmento_id')->constrained('segmentos');
            $table->string('nome', 150);
            $table->char('uf', 2)->nullable();
            // Filiais que a rede tem no MERCADO (dado da diretoria), não as nossas.
            $table->unsignedInteger('filiais_mercado')->nullable();
            $table->string('site', 150)->nullable();
            $table->text('observacao')->nullable();
            // Preserva a ordem da planilha; conta nova entra no fim do segmento.
            $table->unsignedInteger('ordem')->default(0);
            $table->unsignedInteger('vinculos_versao')->default(0);
            $table->timestamps();

            $table->unique(['segmento_id', 'nome']);
        });

        Schema::create('conta_estrategica_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conta_id')->constrained('contas_estrategicas')->cascadeOnDelete();
            // grupo => clientes.cod_grupo ; cliente => clientes.cod_cliente
            $table->enum('tipo', ['grupo', 'cliente']);
            $table->string('codigo', 20);
            // sugestao = casado pelo nome na carga inicial, ainda não revisado.
            $table->enum('origem', ['sugestao', 'manual'])->default('manual');
            $table->timestamps();

            $table->unique(['conta_id', 'tipo', 'codigo']);
            // O caminho inverso ("de que conta é este grupo?") serve o relatório da carga.
            $table->index(['tipo', 'codigo']);
        });

        Schema::table('segmentos', function (Blueprint $table) {
            // O responsável do segmento na diretoria ("DROGARIAS - Inaya"). 1:1 com o
            // segmento, mesmo raciocínio do `peso_potencial`.
            $table->foreignId('especialista_user_id')->nullable()->after('peso_potencial')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('segmentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('especialista_user_id');
        });

        Schema::dropIfExists('conta_estrategica_vinculos');
        Schema::dropIfExists('contas_estrategicas');
    }
};
