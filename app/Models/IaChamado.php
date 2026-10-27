<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IaChamado extends Model
{
    protected $table = 'ia_chamados';

    /** Assuntos que o assistente pode escolher ao abrir um chamado. */
    public const CATEGORIAS = [
        'erro_sistema' => 'Erro no sistema',
        'dado_errado' => 'Dado errado ou desatualizado',
        'duvida' => 'Dúvida que o assistente não resolveu',
        'acesso' => 'Acesso ou permissão',
        'sugestao' => 'Sugestão de melhoria',
    ];

    protected $fillable = ['user_id', 'conversa_id', 'categoria', 'resumo', 'tela', 'status', 'resposta', 'resolvido_por', 'resolvido_em'];

    protected function casts(): array
    {
        return ['resolvido_em' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(IaConversa::class, 'conversa_id');
    }

    public function rotuloCategoria(): string
    {
        return self::CATEGORIAS[$this->categoria] ?? $this->categoria;
    }
}
