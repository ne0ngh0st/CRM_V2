<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteParaCadastro extends Model
{
    protected $table = 'clientes_para_cadastro';

    protected $fillable = [
        'user_id',
        'cnpj_faturamento',
        'vendedor_autopel',
        'razao_social',
        'nome_fantasia',
        'endereco',
        'complemento',
        'cep',
        'bairro',
        'municipio',
        'estado',
        'telefone',
        'email',
        'inscricao_estadual',
        'segmento_atuacao',
        'condicao_pagamento',
        'grupo_vendas',
        'grupo_vendas_codigo',
        'tabela_preco',
        'tabela_preco_codigo',
        'observacoes',
        'entrega_endereco',
        'entrega_complemento',
        'entrega_cep',
        'entrega_bairro',
        'entrega_municipio',
        'entrega_estado',
        'cadastro_raiz_opcao',
        'cod_vendedor_carteira',
        'nome_vendedor_carteira',
        'cod_vendedor_solicitante',
        'nome_solicitante',
        'cadastro_proxy',
        'status',
        'processado_em',
        'observacoes_processamento',
    ];

    protected function casts(): array
    {
        return [
            'cadastro_proxy' => 'boolean',
            'processado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
