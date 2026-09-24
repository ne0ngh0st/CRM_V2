<?php

namespace App\Models;

use App\Http\Controllers\SimulacaoController;
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

    /**
     * Toda mudança de observação vira uma versão no histórico — criar, editar, apagar,
     * pela tela ou pela carga da planilha.
     *
     * ⚠️ É gancho de model, e não código no controller, pelo mesmo motivo do
     * `Ligacao::created()`: são três caminhos que escrevem a observação (store, update e o
     * `diretor:importar-maiores-segmento`), e o que ficar de fora abre um buraco no
     * histórico sem erro nenhum aparecer.
     */
    protected static function booted(): void
    {
        static::saved(function (ContaEstrategica $conta) {
            $mudou = $conta->wasRecentlyCreated
                ? filled($conta->observacao)
                : $conta->wasChanged('observacao');

            if (! $mudou) {
                return;
            }

            $conta->observacoes()->create([
                'user_id' => self::autorDaEdicao(),
                'texto' => filled($conta->observacao) ? $conta->observacao : null,
            ]);
        });
    }

    /**
     * Durante a simulação o guard devolve o ALVO, mas quem escreveu foi o admin — mesma
     * regra da presença (`RegistrarAtividade`): a autoria segue a pessoa, não o guard.
     */
    private static function autorDaEdicao(): ?int
    {
        $request = request();

        if ($request->hasSession() && $request->session()->has(SimulacaoController::SESSAO_ADMIN_ID)) {
            return (int) $request->session()->get(SimulacaoController::SESSAO_ADMIN_ID);
        }

        return auth()->id();
    }

    public function observacoes(): HasMany
    {
        return $this->hasMany(ContaEstrategicaObservacao::class, 'conta_id');
    }

    public function segmento(): BelongsTo
    {
        return $this->belongsTo(Segmento::class);
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(ContaEstrategicaVinculo::class, 'conta_id');
    }
}
