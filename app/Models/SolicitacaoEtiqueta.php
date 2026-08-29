<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitacaoEtiqueta extends Model
{
    protected $table = 'solicitacoes_etiquetas';

    protected $fillable = [
        'user_id',
        'solicitante_nome',
        'cod_vendedor',
        'nomenclatura',
        'titulo_padronizado',
        'personalizacao',
        'unidade_venda',
        'quantidade_caixa',
        'metragem',
        'medidas',
        'diametro_tubete',
        'aplicacao',
        'tipo_adesivo',
        'estoque_seguranca',
        'estoque_seguranca_sn',
        'saida_rolo',
        'observacoes',
        'status',
        'enviado_por',
        'enviado_em',
    ];

    protected function casts(): array
    {
        return [
            'metragem' => 'decimal:2',
            'enviado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quem despachou a ficha pro Cadastro/PCP (coluna `enviado_por`). */
    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
