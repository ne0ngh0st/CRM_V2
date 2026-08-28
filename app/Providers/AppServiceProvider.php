<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Bloqueia migrate:fresh, migrate:refresh, migrate:reset e db:wipe em produção
        // (Forge/AWS), onde não têm uso legítimo e apagariam o banco real — lá não
        // existe espelho pra reimportar. Ver Regra de ouro nº 7 no CLAUDE.md.
        //
        // Só produção de propósito: em `testing` o RefreshDatabase PRECISA do
        // migrate:fresh, e gatear isto em "não-local" quebra a suíte inteira sem dar
        // erro óbvio (as migrations simplesmente não rodam e todo teste morre com
        // "table doesn't exist"). Quem protege o banco de dev contra os testes é a trava
        // em tests/TestCase.php, não esta linha — são problemas diferentes.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
