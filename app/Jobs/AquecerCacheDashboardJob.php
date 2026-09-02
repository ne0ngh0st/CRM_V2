<?php

namespace App\Jobs;

use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use App\Services\Dashboard\EscoposAquecidos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalcula as agregações caras do Dashboard antes que alguém precise delas.
 *
 * ⚠️ O PROBLEMA QUE ESTE JOB RESOLVE NÃO É "FALTA CACHE" — É CACHE FRIO.
 * `Cache::remember` já protegia quem chegava em segundo lugar. Quem chega no instante em
 * que a chave expirou paga a conta inteira: 40 queries e ~5 s de SQL no escopo admin,
 * contra 5 queries com cache quente. Com 200 usuários e TTL de 30 minutos, isso acontece
 * dezenas de vezes por dia, sempre na cara de alguém e de forma aparentemente aleatória.
 * É exatamente o padrão que produz a reclamação "às vezes o sistema trava".
 *
 * Rodando a cada 10 minutos contra um TTL de 30, a chave é sempre reescrita antes de
 * expirar: o custo sai do caminho do usuário e vai para o worker.
 *
 * Ver docs/performance.md §1.3.
 */
class AquecerCacheDashboardJob implements ShouldQueue
{
    use Queueable;

    /** Marca de vida, lida por `cache:aquecer --status`. */
    public const CHAVE_ULTIMO_AQUECIMENTO = 'perf:ultimo-aquecimento';

    public function handle(DashboardBlocos $blocos, EscoposAquecidos $escopos): void
    {
        $frios = $blocos->comRecalculoForcado();
        $aquecidos = 0;
        $falhas = 0;

        foreach ($escopos->listar() as $escopo) {
            try {
                $this->aquecerEscopo($frios, $escopo);
                $aquecidos++;
            } catch (Throwable $e) {
                // Um escopo quebrado não pode abortar o aquecimento dos outros: o pior
                // caso passaria a ser "ninguém tem cache" em vez de "um escopo não tem".
                $falhas++;
                Log::warning('Falha ao aquecer cache do Dashboard', [
                    'escopo' => $escopo['rotulo'],
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        Cache::put(self::CHAVE_ULTIMO_AQUECIMENTO, [
            'em' => now()->toIso8601String(),
            'escopos' => $aquecidos,
            'falhas' => $falhas,
        ], now()->addDay());

        Log::info("Cache do Dashboard aquecido: {$aquecidos} escopos, {$falhas} falhas.");
    }

    /**
     * @param  array{rotulo: string, codVendedores: array<string>|null, usuarioIds: array<int>}  $escopo
     */
    private function aquecerEscopo(DashboardBlocos $frios, array $escopo): void
    {
        $porVendedor = ChaveEscopo::deCodVendedores($escopo['codVendedores']);
        $porUsuario = ChaveEscopo::deUsuarioIds($escopo['usuarioIds']);

        // Exatamente os mesmos métodos que o DashboardController chama nestes escopos —
        // só que em modo forçado. É isso que impede o job de gravar numa chave que
        // ninguém lê.
        //
        // faturamentoComparacao saiu daqui de propósito: na Home dos gestores (único
        // público dos escopos aquecidos — empresa e equipes) o gráfico Chart.js foi
        // trocado pelo embed do Power BI. Vendedor/representante ainda chamam o método,
        // mas o escopo individual não é aquecido (config escopos_aquecidos.vendedores).
        $frios->metaGauge($porVendedor, $escopo['codVendedores']);
        $frios->carteiraSegmento($porVendedor, $escopo['codVendedores']);
        $frios->pedidosAtencao($porVendedor, $escopo['codVendedores']);
        $frios->orcamentosStats($porUsuario, $escopo['usuarioIds']);
    }
}
