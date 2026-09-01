<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Observacao extends Model
{
    /**
     * Autor exibido quando não há usuário do CRM por trás da nota — hoje, a
     * mensagem que o cliente escreveu no formulário do site.
     */
    public const AUTOR_SISTEMA = 'Formulário do site';

    protected $table = 'observacoes';

    protected $fillable = [
        'user_id',
        'cliente_id',
        'lead_id',
        'cnpj',
        'mensagem',
        'fixada',
    ];

    protected function casts(): array
    {
        return [
            'fixada' => 'boolean',
        ];
    }

    /**
     * Nome do autor para exibição — o ÚNICO lugar que resolve isso.
     *
     * ⚠️ Antes cada controller fazia `$o->user->display_name ?: $o->user->name`
     * direto. Como `user_id` passou a ser nulo para nota vinda do site, isso
     * estourava com "property on null" em quatro telas. Regra de ouro nº 8.
     */
    public function nomeAutor(): string
    {
        return $this->user?->display_name
            ?: $this->user?->name
            ?: self::AUTOR_SISTEMA;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
