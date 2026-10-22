<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma lista de cliente comparada com o estoque de revenda. Ver a migration
 * `create_cotacoes_tables` e o `CotacaoService`.
 */
class Cotacao extends Model
{
    protected $table = 'cotacoes';

    protected $fillable = [
        'user_id', 'cliente_id', 'nome_arquivo', 'caminho_arquivo', 'status', 'erro',
        'leitura', 'itens_total', 'valor_total', 'estoque_em', 'processada_em',
    ];

    public const STATUS_PROCESSANDO = 'processando';

    public const STATUS_PRONTA = 'pronta';

    public const STATUS_FALHA = 'falha';

    protected function casts(): array
    {
        return [
            'leitura' => 'array',
            'itens_total' => 'integer',
            'valor_total' => 'float',
            'estoque_em' => 'datetime',
            'processada_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CotacaoItem::class);
    }

    /**
     * Parada em "processando" há tempo demais: o worker morreu ou o deploy derrubou o
     * job. O `failed()` do job não cobre esse caso (só roda com o worker vivo), então a
     * tela decide pela idade — mesmo desenho de `Exportacao::travou()`.
     */
    public function travou(): bool
    {
        return $this->status === self::STATUS_PROCESSANDO
            && $this->created_at !== null
            && $this->created_at->lt(now()->subMinutes((int) config('cotacao.minutos_travada')));
    }
}
