<?php

namespace App\Jobs;

use App\Models\Exportacao;
use App\Support\Uploads\Disco;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Apaga do disco as planilhas vencidas.
 *
 * ⚠️ Sem isto o disco só cresce: cada export da Carteira completa gera um .xlsx de
 * vários MB, e o servidor não tem como saber sozinho que ninguém mais vai baixá-lo.
 * O legado nunca teve expurgo de nada, e as tabelas dele só cresciam — mesma lição que
 * originou o ExpurgarNotificacoesLidasJob.
 */
class ExpurgarExportacoesJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $vencidas = Exportacao::query()
            ->whereNotNull('expira_em')
            ->where('expira_em', '<', now())
            ->get();

        $apagados = 0;

        foreach ($vencidas as $exportacao) {
            if ($exportacao->caminho && Disco::exports()->exists($exportacao->caminho)) {
                Disco::exports()->delete($exportacao->caminho);
                $apagados++;
            }

            // O registro fica, sem o caminho: preserva o histórico de quem exportou o quê
            // (o arquivo é descartável, a trilha de auditoria não).
            $exportacao->update(['caminho' => null]);
        }

        if ($apagados > 0) {
            Log::info("Expurgo de exportações: {$apagados} arquivo(s) removido(s).");
        }
    }
}
