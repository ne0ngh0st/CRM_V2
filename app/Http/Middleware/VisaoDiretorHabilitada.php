<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rotas da Visão Diretor respondem 404 enquanto a seção estiver suprimida. */
class VisaoDiretorHabilitada
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('visao_diretor.habilitada'), 404);

        return $next($request);
    }
}
