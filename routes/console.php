<?php

use App\Jobs\AquecerCacheDashboardJob;
use App\Jobs\ExpurgarExportacoesJob;
use App\Jobs\ExpurgarNotificacoesLidasJob;
use App\Jobs\NotificarAgendamentosDoDiaJob;
use App\Jobs\NotificarPedidosAtencaoJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * ⚠️ `onOneServer()` em TUDO daqui.
 *
 * Com dois app nodes atrás do ALB, o cron roda nos dois — e cada tarefa executaria em
 * duplicata. Nos jobs de notificação isso é contido pela idempotência
 * (`referencia_tipo`/`referencia_id` impedem notificação repetida), mas no expurgo e no
 * aquecimento seria só trabalho jogado fora, e no pior caso duas agregações pesadas
 * disputando o mesmo banco ao mesmo tempo.
 *
 * `onOneServer()` depende de um lock atômico compartilhado, ou seja, do Redis — é por
 * isso que a troca de driver precisou vir antes desta fase.
 */

// Sistema de Notificações (ver CLAUDE.md) — geração diária dos gatilhos que
// dependem de sync do TOTVS, não de uma ação do usuário no CRM.
Schedule::job(new NotificarAgendamentosDoDiaJob)->dailyAt('07:00')->onOneServer();
Schedule::job(new NotificarPedidosAtencaoJob)->dailyAt('07:05')->onOneServer();
Schedule::job(new ExpurgarNotificacoesLidasJob)->weeklyOn(1, '03:00')->onOneServer();
// Planilhas vencidas: sem expurgo o disco do servidor so cresce (ver ExpurgarExportacoesJob).
Schedule::job(new ExpurgarExportacoesJob)->dailyAt('03:30')->onOneServer();

/*
 * Cache warming do Dashboard (ver docs/performance.md §1.3).
 *
 * A cada 10 minutos contra um TTL de 30 — margem de 3x, para que uma rodada perdida do
 * worker não deixe a chave expirar na cara de um usuário. O objetivo não é "ter cache",
 * é nunca ter cache FRIO: sem isto, quem chega no instante da expiração paga 40 queries
 * e ~5 s de SQL, enquanto todo mundo com cache quente paga 5 queries.
 *
 * `withoutOverlapping(15)`: se uma rodada demorar mais que o intervalo, a seguinte espera
 * em vez de empilhar duas agregações pesadas sobre o mesmo banco.
 */
Schedule::job(new AquecerCacheDashboardJob)
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping(15)
    ->name('aquecer-cache-dashboard');
