<?php

namespace App\Console\Commands;

use App\Services\VisaoDiretor\UniversoDoSegmento;
use Illuminate\Console\Command;

/**
 * Refaz `visao_diretor_universo` (a lista de clientes e leads por segmento da Visão
 * Diretor). Seguro rodar sempre: a tabela é derivada de `clientes`, `leads` e
 * `cnpj_situacoes`, e a reconstrução acontece numa transação só.
 *
 * Roda sozinho no fim do `totvs:atualizar` (DERIVADOS), do `totvs:import-leads` e de hora
 * em hora (lead criado, excluído ou reclassificado entre uma importação e outra).
 */
class ReconstruirUniversoDiretor extends Command
{
    protected $signature = 'visao-diretor:reconstruir-universo';

    protected $description = 'Refaz a lista de clientes e leads por segmento da Visão Diretor';

    public function handle(UniversoDoSegmento $universo): int
    {
        $inicio = microtime(true);
        $r = $universo->reconstruir();

        $this->info(sprintf(
            'Universo reconstruído: %s clientes e %s leads em %.1f s.',
            number_format($r['clientes'], 0, ',', '.'),
            number_format($r['leads'], 0, ',', '.'),
            microtime(true) - $inicio,
        ));

        return self::SUCCESS;
    }
}
