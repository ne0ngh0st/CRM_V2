<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma rodada de `totvs:atualizar` — venha do cron ou do botão da tela.
 *
 * Ver a migration `2026_09_04_120000` para o porquê de isto morar no banco e não no disco.
 */
class TotvsImportacao extends Model
{
    use HasFactory;

    protected $table = 'totvs_importacoes';

    protected $fillable = [
        'status', 'origem', 'user_id', 'iniciada_em', 'concluida_em', 'passos', 'erro',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
            'passos' => 'array',
        ];
    }

    /**
     * Quanto tempo uma rodada pode ficar em `executando` antes de ser considerada
     * interrompida.
     *
     * ⚠️ ISTO NÃO É COSMÉTICO: é o que impede a tela de travar para sempre. Se o worker
     * morrer no meio (aconteceu em 2026-08-28 com `ProcessTimedOutException`), a linha
     * fica `executando` eternamente e o botão "Atualizar agora" nunca mais libera — o
     * usuário ficaria sem nenhuma saída pela interface, que é justamente o que esta tela
     * existe para evitar.
     *
     * 30 minutos contra uma corrente que leva ~2 min: folga de 15x, para nunca declarar
     * morta uma rodada que só está lenta (uma reimportação de todos os meses de pedido
     * chega perto de 10 min).
     */
    public const MINUTOS_ATE_CONSIDERAR_TRAVADA = 30;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emAndamento(): bool
    {
        return $this->status === 'executando'
            && $this->iniciada_em?->diffInMinutes(now()) < self::MINUTOS_ATE_CONSIDERAR_TRAVADA;
    }

    public function travada(): bool
    {
        return $this->status === 'executando' && ! $this->emAndamento();
    }

    public function duracaoSegundos(): ?float
    {
        if ($this->concluida_em === null || $this->iniciada_em === null) {
            return null;
        }

        return (float) $this->iniciada_em->diffInMilliseconds($this->concluida_em) / 1000;
    }
}
