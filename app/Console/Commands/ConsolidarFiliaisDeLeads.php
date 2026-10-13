<?php

namespace App\Console\Commands;

use App\Services\Leads\ConsolidacaoDeFiliais;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deixa um lead por empresa (raiz do CNPJ): as outras filiais passam o histórico para ele e
 * viram `excluido`. Regras em `ConsolidacaoDeFiliais`. Seguro rodar de novo: empresa com um
 * lead só não é tocada. O import de leads roda isto sozinho a cada carga.
 */
class ConsolidarFiliaisDeLeads extends Command
{
    protected $signature = 'leads:consolidar-filiais
        {--dry-run : Só mostra o que seria feito}
        {--detalhe : Lista as empresas com quem fica e quem sai}';

    protected $description = 'Deixa um lead por empresa (raiz do CNPJ); as outras filiais viram excluídas';

    public function handle(ConsolidacaoDeFiliais $consolidacao): int
    {
        $plano = $consolidacao->plano();
        $saem = array_sum(array_map(fn ($e) => count($e['saem']), $plano));

        if ($plano === []) {
            $this->info('Nenhuma empresa com mais de um lead.');

            return self::SUCCESS;
        }

        if ($this->option('detalhe')) {
            $nomes = DB::table('leads')->whereIn('id', array_column($plano, 'fica'))->pluck('nome', 'id');
            $this->table(['Empresa', 'Fica (id)', 'Nome', 'Saem'], array_map(
                fn (array $e) => [$e['empresa'], $e['fica'], $nomes[$e['fica']] ?? '', count($e['saem'])],
                $plano,
            ));
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('[dry-run] %d empresas; %d leads seriam excluídos.', count($plano), $saem));

            return self::SUCCESS;
        }

        $r = $consolidacao->consolidar();
        $this->info(sprintf('%d empresas consolidadas; %d leads excluídos.', $r['empresas'], $r['excluidos']));

        return self::SUCCESS;
    }
}
