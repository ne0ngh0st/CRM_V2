<?php

namespace App\Console\Commands;

use App\Jobs\AquecerCacheDashboardJob;
use App\Services\Dashboard\EscoposAquecidos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Executa o aquecimento de cache na hora, de forma síncrona.
 *
 * Serve para dois momentos:
 *
 * 1. **No fim do deploy**, depois do `config:cache`. Sem isto, o primeiro usuário a abrir
 *    o Dashboard após cada release paga os ~5 s da agregação fria — e "o sistema fica
 *    lento logo depois que você mexe nele" é a pior impressão possível num projeto cuja
 *    razão de existir é performance.
 * 2. **Para diagnosticar**, com `--status`, quando a suspeita for "o cache não está sendo
 *    aquecido" — que localmente é comum, porque o scheduler do Laravel só dispara com um
 *    cron real rodando `schedule:run` a cada minuto.
 */
class AquecerCacheDashboard extends Command
{
    protected $signature = 'cache:aquecer
        {--status : Só mostra quando foi o último aquecimento, sem executar}
        {--listar : Só lista os escopos que seriam aquecidos}';

    protected $description = 'Recalcula as agregações caras do Dashboard e popula o cache';

    public function handle(EscoposAquecidos $escopos): int
    {
        if ($this->option('status')) {
            return $this->mostrarStatus();
        }

        $lista = $escopos->listar();

        if ($this->option('listar')) {
            foreach ($lista as $escopo) {
                $qtd = $escopo['codVendedores'] === null ? 'todos' : count($escopo['codVendedores']);
                $this->line("  {$escopo['rotulo']} · vendedores: {$qtd} · usuários: ".count($escopo['usuarioIds']));
            }

            return self::SUCCESS;
        }

        $this->info(sprintf('Aquecendo %d escopo(s)...', count($lista)));

        $inicio = microtime(true);
        // Executa inline de propósito: no deploy queremos o cache quente ANTES de o
        // servidor começar a receber tráfego, não um job enfileirado para depois.
        dispatch_sync(new AquecerCacheDashboardJob);
        $segundos = round(microtime(true) - $inicio, 1);

        $this->info("Concluído em {$segundos}s.");

        return $this->mostrarStatus();
    }

    private function mostrarStatus(): int
    {
        $ultimo = Cache::get(AquecerCacheDashboardJob::CHAVE_ULTIMO_AQUECIMENTO);

        if (! $ultimo) {
            $this->warn('  Nenhum aquecimento registrado nas últimas 24h.');
            $this->line('  <fg=gray>Localmente isso é esperado: o scheduler só dispara com um cron rodando</>');
            $this->line('  <fg=gray>`php artisan schedule:run` a cada minuto. Em produção, é o toggle Scheduler do Forge.</>');

            return self::SUCCESS;
        }

        $this->line("  Último aquecimento: <fg=green>{$ultimo['em']}</>");
        $this->line("  Escopos aquecidos: {$ultimo['escopos']}");

        if (($ultimo['falhas'] ?? 0) > 0) {
            $this->warn("  Falhas: {$ultimo['falhas']} — ver storage/logs/laravel.log");
        }

        return self::SUCCESS;
    }
}
