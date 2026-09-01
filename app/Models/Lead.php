<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    public const ORIGEM_SISTEMA = 'sistema';

    public const ORIGEM_MANUAL = 'manual';

    public const ORIGEM_WORDPRESS = 'wordpress';

    /** @var list<string> */
    public const ORIGENS = [
        self::ORIGEM_SISTEMA,
        self::ORIGEM_MANUAL,
        self::ORIGEM_WORDPRESS,
    ];

    protected $fillable = [
        'origem',
        'user_id',
        'cod_vendedor',
        'nome',
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'email',
        'telefone',
        'endereco',
        'cidade',
        'estado',
        'segmento',
        'valor_estimado',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'valor_estimado' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function observacoes(): HasMany
    {
        return $this->hasMany(Observacao::class);
    }

    public function agendamentos(): HasMany
    {
        return $this->hasMany(AgendamentoLigacao::class);
    }

    public function stagingWordpress(): HasOne
    {
        return $this->hasOne(MarketingWpLeadRaw::class, 'lead_id');
    }

    public static function rotuloOrigem(string $origem): string
    {
        return match ($origem) {
            self::ORIGEM_MANUAL => 'Manual',
            self::ORIGEM_WORDPRESS => 'WordPress',
            default => 'Sistema',
        };
    }

    public function scopeVisivel($query)
    {
        return $query->where('status', '!=', 'excluido');
    }
}
