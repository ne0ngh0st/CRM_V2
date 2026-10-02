<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Relatório de uma rodada do `totvs:import-leads`. Só o comando escreve; só a
 * `/atualizacoes` lê. Ver a migration `2026_10_02_110000`.
 */
class LeadImportacao extends Model
{
    protected $table = 'leads_importacoes';

    /** Quantas linhas recusadas guardar. O resto o comando mostra no terminal. */
    public const MAXIMO_RECUSADAS = 200;

    protected $fillable = [
        'simulacao', 'status', 'arquivos', 'resultado', 'recusadas', 'erro', 'iniciada_em', 'concluida_em',
    ];

    protected function casts(): array
    {
        return [
            'simulacao' => 'boolean',
            'arquivos' => 'array',
            'resultado' => 'array',
            'recusadas' => 'array',
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
        ];
    }
}
