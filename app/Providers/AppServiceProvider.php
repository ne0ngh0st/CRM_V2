<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Email\RegistroDeEmails;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        /*
         * Quem entra na Visão Diretor. Uma decisão só para a seção inteira: o grupo de
         * rotas usa `can:ver-visao-diretor`, então página nova nasce protegida e não
         * depende de alguém lembrar de um `hasAnyRole` no controller. A Carteira também
         * pergunta isto antes de aceitar o filtro `?conta_alvo=`. Ver docs/visao-diretor.md.
         *
         * Além dos perfis, entra quem tiver a permissão `ver-visao-diretor` do spatie dada
         * direto ao usuário. Existe para o ROBERTO BAROLI (2026-10-08): no papel ele é
         * diretor, mas no sistema opera como supervisor — só a equipe dele — e mesmo assim
         * usa a Maiores por Segmento. Dar o perfil `diretor` traria junto a empresa
         * inteira em todas as telas.
         *
         * O OR abaixo é declarativo: o spatie já registra um `Gate::before` que libera
         * quem tem uma permissão de mesmo nome (`register_permission_check_method`). Está
         * escrito aqui para a regra inteira ser lida num lugar só. ⚠️ `checkPermissionTo`,
         * nunca `hasPermissionTo`: o segundo LANÇA exceção se a permissão não existir.
         */
        Gate::define('ver-visao-diretor', fn (User $user) => $user->hasAnyRole(['admin', 'diretor'])
            || $user->checkPermissionTo('ver-visao-diretor'));

        // Log do que sai pelo SMTP, para a tela admin /emails.
        Event::listen(MessageSent::class, [RegistroDeEmails::class, 'enviado']);
        Event::listen(JobFailed::class, [RegistroDeEmails::class, 'falhou']);
    }
}
