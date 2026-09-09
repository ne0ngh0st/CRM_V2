<?php

namespace App\Jobs;

use App\Models\Exportacao;
use App\Support\Uploads\Disco;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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

        $this->fecharOrfas();
    }

    /**
     * Fecha as que ficaram "processando" para sempre.
     *
     * ⚠️ O `failed()` do job só roda se o WORKER ESTIVER VIVO para chamá-lo. Quando ele
     * morre com o job na mão — foi o que aconteceu em 2026-09-09, com o
     * `ProcessTimedOutException` do `queue:listen` — ninguém marca nada, e o registro fica
     * eternamente em "Preparando". A tela já mostra "Interrompida" pela idade
     * (`Exportacao::travou()`); aqui o estado é corrigido de fato no banco, para a lista
     * não carregar registros zumbis por meses.
     */
    private function fecharOrfas(): void
    {
        $orfas = Exportacao::query()
            ->where('status', Exportacao::STATUS_PROCESSANDO)
            ->where('created_at', '<', now()->subMinutes(Exportacao::MINUTOS_ATE_ORFA))
            ->update([
                'status' => Exportacao::STATUS_ERRO,
                'erro' => 'A geração foi interrompida antes de terminar. Gere a planilha de novo.',
            ]);

        if ($orfas > 0) {
            Log::warning("Expurgo de exportações: {$orfas} exportação(ões) órfã(s) marcada(s) como erro.");
        }
    }
}
