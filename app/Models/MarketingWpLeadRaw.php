<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Envelope cru de cada captura do site. É a prova de que o POST chegou.
 *
 * Esta linha é gravada e commitada ANTES de qualquer tentativa de virar lead
 * comercial — o WordPress não reenvia, então perder o envelope é perder o
 * lead para sempre. `lead_id` nulo significa "capturado, ainda não promovido",
 * e é o job de reconciliação que fecha isso depois.
 */
class MarketingWpLeadRaw extends Model
{
    public $timestamps = false;

    /** Depois disto o job de reconciliação desiste e a linha fica visível como erro. */
    public const MAX_TENTATIVAS = 5;

    protected $table = 'marketing_wp_leads_raw';

    protected $fillable = [
        'recebido_em',
        'payload_json',
        'payload_hash',
        'remote_addr',
        'user_agent',
        'fonte',
        'formulario_id',
        'lead_id',
        'tentativas',
        'erro',
    ];

    /**
     * Coluna naive em horário de São Paulo — o cast datetime do Eloquent
     * converteria para APP_TIMEZONE (UTC) e as janelas de "hoje"/"últimos N
     * minutos" comparadas por WpLeadCapturaStatus sairiam deslocadas em 3h.
     */
    protected function recebidoEm(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Carbon::parse($value, 'America/Sao_Paulo') : null,
            set: fn ($value) => $value instanceof Carbon
                ? $value->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s')
                : $value,
        );
    }

    /** Capturas que chegaram mas ainda não viraram lead — a fila do job de reconciliação. */
    public function scopePendentes(Builder $query): Builder
    {
        return $query->whereNull('lead_id')
            ->where('tentativas', '<', self::MAX_TENTATIVAS);
    }

    /** Desistiu depois de MAX_TENTATIVAS: exige olho humano, aparece na barra da /leads. */
    public function scopeTravadas(Builder $query): Builder
    {
        return $query->whereNull('lead_id')
            ->where('tentativas', '>=', self::MAX_TENTATIVAS);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function formulario(): BelongsTo
    {
        return $this->belongsTo(MarketingWpFormulario::class, 'formulario_id');
    }
}
