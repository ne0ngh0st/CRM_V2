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

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class);
    }

    public function usaIpi(): bool
    {
        return $this->tipo_produto_servico === 'produto';
    }
}
