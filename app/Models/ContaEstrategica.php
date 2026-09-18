<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma rede do MERCADO que a diretoria acompanha (Visão Diretor → Maiores por Segmento).
 *
 * Não é cliente: é alvo. O que a liga ao CRM são os `vinculos`, e tudo o que se diz dela
 * além de nome/UF/filiais/site/observação é derivado deles — ver `ClientesDaConta` e
 * docs/visao-diretor.md.
 */
class ContaEstrategica extends Model
{
    protected $table = 'contas_estrategicas';

    /*
     * ⚠️ `vinculos_versao` fica FORA de propósito: só `ClientesDaConta::sincronizarVinculos()`
     * mexe nela, porque é ela que invalida o total cacheado da Carteira.
     */
    protected $fillable = [
        'segmento_id',
        'nome',
        'uf',
        'filiais_mercado',
        'site',
        'observacao',
        'ordem',
    ];

    protected $casts = [
        'filiais_mercado' => 'integer',
        'ordem' => 'integer',
        'vinculos_versao' => 'integer',
    ];

    public function segmento(): BelongsTo
    {
        return $this->belongsTo(Segmento::class);
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(ContaEstrategicaVinculo::class, 'conta_id');
    }
}
