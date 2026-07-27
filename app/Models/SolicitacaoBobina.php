<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitacaoBobina extends Model
{
    protected $table = 'solicitacoes_bobinas';

    protected $fillable = [
        'user_id',
        'solicitante_nome',
        'cod_vendedor',
        'nomenclatura',
        'titulo_padronizado',
        'personalizacao',
        'unidade_venda',
        'quantidade_caixa',
        'papel',
        'gramatura',
        'largura',
        'metragem',
        'diametro_tubete',
        'estoque_seguranca',
        'estoque_seguranca_sn',
        'impressao',
        'rebobinamento',
        'observacoes',
        'nf_pedido_tipo',
        'status',
        'enviado_por',
        'enviado_em',
    ];

    protected function casts(): array
    {
        return [
            'metragem' => 'decimal:2',
            'diametro_tubete' => 'decimal:2',
            'enviado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
