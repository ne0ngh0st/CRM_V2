<?php

use App\Jobs\ExpurgarNotificacoesLidasJob;
use App\Jobs\NotificarAgendamentosDoDiaJob;
use App\Jobs\NotificarPedidosAtencaoJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sistema de Notificações (ver CLAUDE.md) — geração diária dos gatilhos que
// dependem de sync do TOTVS, não de uma ação do usuário no CRM.
Schedule::job(new NotificarAgendamentosDoDiaJob)->dailyAt('07:00');
Schedule::job(new NotificarPedidosAtencaoJob)->dailyAt('07:05');
Schedule::job(new ExpurgarNotificacoesLidasJob)->weeklyOn(1, '03:00');
