<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relatório de uma rodada do `totvs:import-leads` — pelo terminal ou pelo botão da
 * /atualizacoes (`ImportarLeadsProspeccaoJob`). Só o comando e o controller escrevem; só a
 * /atualizacoes lê. Ver as migrations `2026_10_02_110000` e `120000`.
 */
class LeadImportacao extends Model
{
    protected $table = 'leads_importacoes';

    /** Quantas linhas recusadas guardar. O resto o comando mostra no terminal. */
    public const MAXIMO_RECUSADAS = 200;

    /**
     * Passou disto ainda "executando", o worker morreu no meio (deploy, timeout) e a
     * rodada nunca vai terminar. Sem este corte, o botão ficaria travado para sempre —
     * mesma família da exportação órfã de 09/09. O import leva segundos; 30 min é folga.
     */
    public const MINUTOS_ATE_TRAVAR = 30;

    /** Teto de linhas guardadas por lista de detalhe. A contagem em `resultado` é sempre a real. */
    public const MAXIMO_POR_DETALHE = 2000;

    /**
     * As listas que o card abre no clique, com o rótulo de cada uma. A chave é o contrato
     * entre `ImportLeadsTotvs` (que grava) e o card (que pede): mudou aqui, muda lá.
     */
    public const DETALHES = [
        'novos' => 'Leads novos',
        'atualizados' => 'Já no CRM, atualizados',
        'jaClientes' => 'Já eram clientes (TOTVS)',
        'naoAtivos' => 'CNPJ não ativo na Receita',
        'segurados' => 'Esperando a Receita',
        'deOutraOrigem' => 'Já eram lead manual/site',
        'viraramCliente' => 'Leads que viraram cliente (não mexidos)',
        'redesCriadas' => 'Redes criadas',
        'ligados' => 'Leads ligados a rede',
    ];

    protected $fillable = [
        'simulacao', 'user_id', 'status', 'arquivos', 'resultado', 'recusadas', 'detalhes', 'erro', 'iniciada_em', 'concluida_em',
    ];

    protected function casts(): array
    {
        return [
            'simulacao' => 'boolean',
            'arquivos' => 'array',
            'resultado' => 'array',
            'recusadas' => 'array',
            'detalhes' => 'array',
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Rodada em andamento DE VERDADE: as travadas não contam (e não seguram o botão). */
    public function scopeEmAndamento(Builder $query): void
    {
        $query->where('status', 'executando')
            ->where('iniciada_em', '>', now()->subMinutes(self::MINUTOS_ATE_TRAVAR));
    }

    public function travou(): bool
    {
        return $this->status === 'executando'
            && $this->iniciada_em->lt(now()->subMinutes(self::MINUTOS_ATE_TRAVAR));
    }
}
