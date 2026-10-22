<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um produto da lista do cliente, com o que o motor respondeu (`status_motor`,
 * `codigo_sugerido`, `alternativas`) e o estado atual depois da conferência.
 */
class CotacaoItem extends Model
{
    protected $table = 'cotacao_itens';

    protected $fillable = [
        'cotacao_id', 'cod_cliente', 'descricao_cliente', 'texto_normalizado', 'subgrupo',
        'unidade', 'marca', 'qtd_periodo', 'valor_periodo', 'ordens', 'preco_unit',
        'status_motor', 'score', 'motivos', 'codigo_sugerido', 'alternativas', 'status',
        'decisao', 'codigo_escolhido', 'pulado', 'decidido_por', 'decidido_em',
    ];

    protected function casts(): array
    {
        return [
            'motivos' => 'array',
            'alternativas' => 'array',
            'qtd_periodo' => 'float',
            'valor_periodo' => 'float',
            'preco_unit' => 'float',
            'score' => 'float',
            'ordens' => 'integer',
            'pulado' => 'boolean',
            'decidido_em' => 'datetime',
        ];
    }

    public function cotacao(): BelongsTo
    {
        return $this->belongsTo(Cotacao::class);
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    /** O código que vale hoje: o que alguém escolheu, ou o que o motor sugeriu. */
    public function codigoFinal(): ?string
    {
        if ($this->decisao === 'NAO_TEMOS') {
            return null;
        }

        return $this->codigo_escolhido ?? $this->codigo_sugerido;
    }
}
