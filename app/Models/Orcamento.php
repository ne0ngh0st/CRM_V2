<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orcamento extends Model
{
    protected $fillable = [
        'user_id',
        'lead_id',
        /*
         * Vínculo real com `clientes`, necessário para resolver o `clientId` do Portal
         * (o de-para é por cod_cliente + loja). ⚠️ As colunas `portal_*` NÃO entram no
         * fillable de propósito: quem escreve nelas é o GeradorDePedidoNoPortal e mais
         * ninguém — mesmo raciocínio de `clientes.data_ultimo_contato`.
         */
        'cliente_id',
        'cliente_nome',
        'cliente_cnpj',
        'cliente_contato',
        'forma_pagamento',
        'tipo_frete',
        'tipo_produto_servico',
        'valor_total',
        'data_validade',
        'desconto_pct_max',
        'nivel_aprovacao',
        'status_gestor',
        'aprovado_por_id',
        'aprovado_em',
        'motivo_rejeicao',
        'observacoes',
        'variacao_producao_personalizado',
        'prazo_producao',
        'garantia_imagem',
        'texto_importante',
    ];

    protected function casts(): array
    {
        return [
            'data_validade' => 'date',
            'aprovado_em' => 'datetime',
            'valor_total' => 'decimal:2',
            'desconto_pct_max' => 'decimal:2',
            'portal_payload' => 'array',
            'portal_enviado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aprovadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por_id');
    }

    /** De qual lead este orçamento saiu, quando saiu de um. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Cliente do TOTVS, quando o orçamento é de cliente já cadastrado.
     *
     * ⚠️ Nulo em dois casos legítimos: orçamento de LEAD (prospect, que o Portal
     * recusa com 409) e os 1.864 históricos importados do legado, que só têm nome e
     * CNPJ em texto. Nenhum dos dois vira pedido.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** Já foi transformado em pedido no Portal? */
    public function foiEnviadoAoPortal(): bool
    {
        return $this->portal_pedido_id !== null;
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class);
    }

    public function usaIpi(): bool
    {
        return $this->tipo_produto_servico === 'produto';
    }
}
