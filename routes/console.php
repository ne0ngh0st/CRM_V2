<?php

use App\Jobs\AquecerCacheDashboardJob;
use App\Jobs\ExpurgarCapturasWpJob;
use App\Jobs\ExpurgarExportacoesJob;
use App\Jobs\ExpurgarNotificacoesLidasJob;
use App\Jobs\NotificarAgendamentosDoDiaJob;
use App\Jobs\NotificarPedidosAtencaoJob;
use App\Jobs\PromoverCapturasWpPendentesJob;
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

/*
 * Publica no CloudWatch os números que só a aplicação conhece — profundidade da fila,
 * idade do último aquecimento e jobs falhados.
 *
 * ⚠️ POR QUE A CADA MINUTO, e não a cada dez: isto é detecção de incidente, não relatório.
 * No incidente de 2026-08-29 a fila travou às 07:00 e só foi notada às 13:00, porque
 * nenhum alarme nativo da AWS enxerga fila — CPU, memória e ALB ficaram verdes as seis
 * horas inteiras. Com um minuto de granularidade, o alarme sai às 07:03.
 *
 * Custo: três métricas customizadas (~US$ 0,90/mês) mais as chamadas de API (~US$ 0,43).
 * Menos de um dólar e meio por mês para não repetir seis horas de fila parada.
 *
 * `onOneServer()` porque a fila é compartilhada: os dois nós publicariam o mesmo número.
 */
Schedule::command(\App\Console\Commands\PublicarMetricas::class)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->name('publicar-metricas');

/*
 * Captura de leads do site (WordPress).
 *
 * A promoção roda a cada minuto porque ela é a rede de segurança da captura:
 * o envelope é gravado sozinho e commitado (ver WpLeadIngestor), então tudo
 * que falhar em virar lead na hora do POST fica pendente esperando este job.
 * Um minuto é o intervalo entre "o cliente preencheu o form" e "o vendedor
 * vê o lead" no pior caso — o caminho feliz continua sendo síncrono.
 *
 * ⚠️ Desligar isto não quebra nada de forma visível: o webhook continua
 * respondendo 201 e a staging continua enchendo. O que para é a promoção do
 * que falhou — silenciosamente. Se um dia o agendamento sair daqui, a barra
 * da /leads passa a ser o único aviso.
 */
Schedule::job(new PromoverCapturasWpPendentesJob)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping()
    ->name('promover-capturas-wp');

// Envelope cru é longText e a tabela nunca para de crescer — mesmo motivo do
// expurgo de notificações. Também é o que apaga o lead de teste depois de 24h.
Schedule::job(new ExpurgarCapturasWpJob)->dailyAt('03:40')->onOneServer();
