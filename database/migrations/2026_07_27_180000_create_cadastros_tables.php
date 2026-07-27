<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitacoes_bobinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('solicitante_nome');
            $table->string('cod_vendedor')->nullable();
            $table->string('nomenclatura');
            $table->string('titulo_padronizado');
            $table->string('personalizacao')->nullable();
            $table->string('unidade_venda')->nullable();
            $table->unsignedInteger('quantidade_caixa')->nullable();
            $table->string('papel')->nullable();
            $table->string('gramatura')->nullable();
            $table->string('largura')->nullable();
            $table->decimal('metragem', 10, 2)->nullable();
            $table->decimal('diametro_tubete', 10, 2)->nullable();
            $table->unsignedInteger('estoque_seguranca')->nullable();
            $table->string('estoque_seguranca_sn', 3)->nullable();
            $table->string('impressao')->nullable();
            $table->string('rebobinamento')->nullable();
            $table->text('observacoes')->nullable();
            $table->string('nf_pedido_tipo')->nullable();
            $table->string('status', 20)->default('pendente');
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            $table->index(['cod_vendedor', 'status']);
            $table->index('status');
        });

        Schema::create('solicitacoes_etiquetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('solicitante_nome');
            $table->string('cod_vendedor')->nullable();
            $table->string('nomenclatura');
            $table->string('titulo_padronizado');
            $table->string('personalizacao')->nullable();
            $table->string('unidade_venda')->nullable();
            $table->unsignedInteger('quantidade_caixa')->nullable();
            $table->decimal('metragem', 10, 2)->nullable();
            $table->string('medidas')->nullable();
            $table->string('diametro_tubete')->nullable();
            $table->string('aplicacao')->nullable();
            $table->string('tipo_adesivo')->nullable();
            $table->unsignedInteger('estoque_seguranca')->nullable();
            $table->string('estoque_seguranca_sn', 3)->nullable();
            $table->string('saida_rolo', 5)->default('f1');
            $table->text('observacoes')->nullable();
            $table->string('status', 20)->default('pendente');
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            $table->index(['cod_vendedor', 'status']);
            $table->index('status');
        });

        Schema::create('clientes_para_cadastro', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('cnpj_faturamento', 18);
            $table->string('vendedor_autopel');
            $table->string('razao_social')->nullable();
            $table->string('nome_fantasia')->nullable();
            $table->string('endereco');
            $table->string('complemento')->nullable();
            $table->string('cep', 10);
            $table->string('bairro', 100);
            $table->string('municipio', 100);
            $table->string('estado', 2);
            $table->string('telefone', 20);
            $table->string('email', 100);
            $table->string('inscricao_estadual', 30);
            $table->string('segmento_atuacao', 50);
            $table->string('condicao_pagamento', 120)->default('');
            $table->string('grupo_vendas', 10)->default('nao');
            $table->string('grupo_vendas_codigo', 80)->nullable();
            $table->string('tabela_preco', 10)->default('nao');
            $table->string('tabela_preco_codigo', 120)->nullable();
            $table->text('observacoes')->nullable();
            $table->string('entrega_endereco')->nullable();
            $table->string('entrega_complemento')->nullable();
            $table->string('entrega_cep', 12)->nullable();
            $table->string('entrega_bairro', 100)->nullable();
            $table->string('entrega_municipio', 100)->nullable();
            $table->string('entrega_estado', 2)->nullable();
            $table->string('cadastro_raiz_opcao', 20)->nullable();
            $table->string('cod_vendedor_carteira')->nullable();
            $table->string('nome_vendedor_carteira')->nullable();
            $table->string('cod_vendedor_solicitante')->nullable();
            $table->string('nome_solicitante');
            $table->boolean('cadastro_proxy')->default(false);
            $table->string('status', 20)->default('pendente');
            $table->timestamp('processado_em')->nullable();
            $table->text('observacoes_processamento')->nullable();
            $table->timestamps();

            $table->index('cnpj_faturamento');
            $table->index('status');
            $table->index('user_id');
        });

        Schema::create('leads_manuais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nome');
            $table->string('razao_social');
            $table->string('nome_fantasia')->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->string('email');
            $table->string('telefone', 20);
            $table->string('endereco');
            $table->string('cod_vendedor')->nullable();
            $table->string('status', 20)->default('ativo');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('cod_vendedor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads_manuais');
        Schema::dropIfExists('clientes_para_cadastro');
        Schema::dropIfExists('solicitacoes_etiquetas');
        Schema::dropIfExists('solicitacoes_bobinas');
    }
};
