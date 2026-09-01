<?php

use App\Http\Controllers\WordpressLeadWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks (entrada de sistemas externos)
|--------------------------------------------------------------------------
|
| Arquivo próprio, carregado em bootstrap/app.php, e NÃO dentro do grupo
| `web`: quem posta aqui é servidor, não navegador — não há sessão para
| iniciar nem token CSRF para validar. Ficar fora do grupo também evita uma
| leitura de sessão no Redis por requisição.
|
| Antes isto morava dentro do próprio bootstrap/app.php. Ninguém procura
| rota lá (Regra de ouro nº 8): rota mora em routes/.
|
| ⚠️ O throttle é a única barreira antes do banco depois que alguém tem o
| token — e o token viaja na URL porque o plugin do site não aceita header.
| Não remover "porque o WordPress nunca manda tanto assim": ele é contra o
| dia em que o token vazar, não contra o uso normal.
|
*/

Route::middleware('throttle:120,1')->group(function () {
    Route::any('/webhooks/wordpress-leads', WordpressLeadWebhookController::class)
        ->name('webhooks.wordpress-leads');
});
