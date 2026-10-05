<?php

namespace App\Console\Commands;

use App\Services\VisaoDiretor\FusaoDeLeads;
use Illuminate\Console\Command;

/**
 * Junta, em todas as contas-alvo, o lead aberto pela diretoria (sem CNPJ) com o lead da
 * prospecção da mesma rede. O import faz isso sozinho a cada rodada; este comando existe
 * para os pares que já estavam no banco. Seguro rodar de novo: sem par, não faz nada.
 */
class JuntarLeadsDasContas extends Command
{
    protected $signature = 'visao-diretor:juntar-leads {--dry-run : Só lista os pares, sem mexer em nada}';

    protected $description = 'Junta leads duplicados da mesma conta-alvo (o da diretoria fica, herda o CNPJ)';

    public function handle(FusaoDeLeads $fusao): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $pares = $fusao->fundirDuplicadosDasContas($dryRun);

        if ($pares === []) {
            $this->info('Nenhum par para juntar.');

            return self::SUCCESS;
        }

        $this->table(['Conta', 'Fica (id)', 'Sai (id)', 'CNPJ herdado'], array_map(
            fn (array $p) => [$p['conta'], $p['fica'], $p['sai'], $p['cnpj']],
            $pares,
        ));

        $this->info(($dryRun ? '[dry-run] seriam juntados: ' : 'Juntados: ').count($pares));

        return self::SUCCESS;
    }
}
