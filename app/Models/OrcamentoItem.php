<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrcamentoItem extends Model
{
    protected $table = 'orcamento_itens';

    protected $fillable = [
        'orcamento_id',
        'tipo_item',
        'cod_produto',
        'descricao',
        'quantidade',
        'valor_unitario',
        'valor_total',
        'preco_tabela',
        'calcula_ipi',
        'etiqueta_calc',
        'materia_prima_id',
    ];

    protected function casts(): array
    {
        return [
            'calcula_ipi' => 'boolean',
            'etiqueta_calc' => 'array',
        ];
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    public function materiaPrima(): BelongsTo
    {
        return $this->belongsTo(EtiquetaMateriaPrima::class, 'materia_prima_id');
    }

    /**
     * Se o IPI (3,25% embutido no preço) incide sobre este item: só quando o
     * orçamento é modo "produto", o item não é etiqueta (etiqueta nunca tem IPI)
     * e o checkbox por linha está marcado.
     */
    public function participaIpi(string $tipoProdutoServico): bool
    {
        return $tipoProdutoServico === 'produto'
            && $this->tipo_item !== 'etiqueta'
            && (bool) $this->calcula_ipi;
    }
}
