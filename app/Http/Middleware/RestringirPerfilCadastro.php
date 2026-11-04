<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O perfil "Cadastro" (User::PERFIL_CADASTRO) só alcança /cadastros e o que é da própria
 * conta. Quem restringe é este middleware, não o menu: esconder um item no Vue não impede
 * ninguém de digitar /carteira.
 *
 * É uma LISTA DO QUE PODE, e não do que não pode, de propósito: página nova do sistema
 * nasce fechada para esse perfil, em vez de aberta até alguém lembrar de fechar.
 *
 * GET fora da lista volta para /cadastros (é o que acontece no login, que manda para o
 * Painel); escrita fora da lista é 403.
 *
 * ⚠️ Rota SEM nome passa (o `broadcasting/auth` do sino, o `/up` do ALB). Toda rota de
 * tela deste projeto tem nome; se uma nova nascer sem, ela fica aberta para o perfil.
 */
class RestringirPerfilCadastro
{
    /** Padrões de nome de rota (`Str::is`) que o perfil alcança. */
    private const ROTAS_PERMITIDAS = [
        'cadastros.*',
        'profile.*',
        'notificacoes.*',
        'exportacoes.*',
        'intranet.*',
        'password.*',
        'verification.*',
        'logout',
        'simulacao.encerrar',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasRole(User::PERFIL_CADASTRO)) {
            return $next($request);
        }

        $rota = $request->route()?->getName();

        if ($rota === null || $request->routeIs(...self::ROTAS_PERMITIDAS)) {
            return $next($request);
        }

        if ($request->isMethod('GET')) {
            return redirect()->route('cadastros.index');
        }

        abort(403);
    }
}
