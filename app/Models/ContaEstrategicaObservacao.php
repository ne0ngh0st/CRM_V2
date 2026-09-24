<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma versão da observação de uma conta-alvo. Só `ContaEstrategica` cria estas linhas
 * (gancho `saved`); nada edita nem apaga uma versão — histórico que se reescreve não é
 * histórico.
 */
class ContaEstrategicaObservacao extends Model
{
    protected $table = 'conta_estrategica_observacoes';

    public const UPDATED_AT = null;

    protected $fillable = ['conta_id', 'user_id', 'texto'];

    public function conta(): BelongsTo
    {
        return $this->belongsTo(ContaEstrategica::class, 'conta_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sem usuário, a versão veio da carga da planilha (`diretor:importar-maiores-segmento`). */
    public function nomeAutor(): string
    {
        return $this->user?->display_name ?: ($this->user?->name ?? 'Planilha da diretoria');
    }
}
