<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * Atrás do Application Load Balancer da AWS, é o ALB que termina o TLS e fala
         * HTTP com as instâncias. Sem confiar nos headers X-Forwarded-*:
         *
         * - url()/route() geram "http://" → mixed content, e o Inertia quebra ao seguir
         *   um redirect que muda de esquema;
         * - $request->ip() devolve o IP do próprio ALB, o que envenena a auditoria de
         *   `simulacoes_usuario` (que grava o IP de quem simulou) e qualquer rate limit.
         *
         * ⚠️ `at: '*'` confia em QUALQUER proxy, o que só é seguro porque as instâncias
         * não são alcançáveis diretamente: o security group aceita a porta 80 apenas do
         * security group do ALB. Se um dia a instância ganhar acesso público direto,
         * trocar '*' pelos CIDRs das subnets do balanceador.
         */
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
