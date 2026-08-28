<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma planilha pedida por um usuário e gerada em segundo plano.
 *
 * Ver a migration `create_exportacoes_table` para o porquê de a Carteira ser assíncrona.
 */
class Exportacao extends Model
{
    use HasFactory;

    protected $table = 'exportacoes';

    protected $fillable = [
        'user_id', 'recurso', 'filtros', 'status',
        'caminho', 'nome_arquivo', 'linhas', 'erro', 'expira_em',
    ];

    protected function casts(): array
    {
        return [
            'filtros' => 'array',
            'linhas' => 'integer',
            'expira_em' => 'datetime',
        ];
    }

    public const STATUS_PROCESSANDO = 'processando';

    public const STATUS_PRONTO = 'pronto';

    public const STATUS_ERRO = 'erro';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * O arquivo pode ser baixado?
     *
     * ⚠️ Checa a validade além do status: um registro `pronto` cujo arquivo já foi
     * expurgado do disco continuaria oferecendo um download que falharia.
     */
    public function disponivel(): bool
    {
        return $this->status === self::STATUS_PRONTO
            && $this->caminho !== null
            && ($this->expira_em === null || $this->expira_em->isFuture());
    }
}
