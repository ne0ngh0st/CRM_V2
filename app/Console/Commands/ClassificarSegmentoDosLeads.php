<?php

namespace App\Console\Commands;

use App\Services\Leads\SegmentoDosLeads;
use App\Services\VisaoDiretor\ContaDoLead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dá segmento aos leads das bases importadas pelo CNAE da Receita (`SegmentoDosLeads`) e
 * sugere, para os que caíram num segmento da Maiores por Segmento, a rede a que pertencem
 * (`ContaDoLead::sugerirParaLeadsSemConta`).
 *
 * A carga mensal da Receita já faz os dois passos sozinha; este comando existe para rodar
 * fora dela (primeira vez, mapa CNAE → segmento alterado, contas novas na Visão Diretor).
 *
 * ⚠️ O dry-run roda tudo numa transação e desfaz no fim: sem gravar a classificação, a
 * sugestão de rede não teria lead nenhum para sugerir e o relatório mentiria.
 */
class ClassificarSegmentoDosLeads extends Command
{
    protected $signature = 'leads:classificar-segmento
        {--dry-run : faz tudo numa transação e desfaz no fim (mostra o relatório sem gravar)}';

    protected $description = 'Preenche o segmento dos leads importados pelo CNAE da Receita';

    public function handle(SegmentoDosLeads $segmentos, ContaDoLead $contaDoLead): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('Banco alvo: '.DB::connection()->getDatabaseName().($dryRun ? ' (dry-run: nada será gravado)' : ''));

        DB::beginTransaction();

        try {
            $r = $segmentos->classificar();

            $this->info('Leads que ganharam segmento: '.array_sum($r['classificados']));
            foreach ($r['classificados'] as $segmento => $total) {
                $this->line("  {$segmento}: {$total}");
            }
            $this->line("Sem CNAE conhecido (rodar receita:importar-situacoes): {$r['semCnae']}");
            $this->line("CNAE fora do mapa (ficam sem segmento): {$r['foraDoMapa']}");
            foreach ($r['cnaesForaDoMapa'] as $cnae => $total) {
                $this->line(sprintf('  %s-%s/%s: %d', substr($cnae, 0, 4), substr($cnae, 4, 1), substr($cnae, 5, 2), $total));
            }

            $c = $contaDoLead->sugerirParaLeadsSemConta();
            $this->info("Leads sugeridos para uma rede da Maiores por Segmento: {$c['sugeridos']}");
            if ($c['ambiguos'] > 0) {
                $this->warn("  sem sugestão (o nome casa com mais de uma rede): {$c['ambiguos']}");
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry-run: tudo desfeito.');
            } else {
                DB::commit();
                $this->info('Gravado.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
