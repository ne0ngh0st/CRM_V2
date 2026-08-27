<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Porta de entrada do sistema: manda pro dashboard quem já está logado, pro login quem não está.
 *
 * ⚠️ Existe como controller, e não como Closure em routes/web.php, porque Closure NÃO é
 * serializável — `php artisan route:cache` aborta o arquivo inteiro com "Unable to prepare
 * route [/] for serialization". Como o route:cache é item obrigatório do deploy de produção
 * (ver docs/performance.md), uma única rota com Closure bloqueava o ganho de todas as outras.
 */
class InicioController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return redirect()->route(Auth::check() ? 'dashboard' : 'login');
    }
}
