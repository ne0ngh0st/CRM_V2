<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SimulacaoController;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'roles' => $request->user()?->getRoleNames() ?? [],
            ],
            // Usado por telas que precisam saber o id do registro recém-criado pra
            // continuar operando nele (ex.: anexar imagens à faca logo após cadastrar).
            'flash' => [
                'recursoCriadoId' => fn () => $request->session()->get('recursoCriadoId'),
            ],
            // Banner de simulação de usuário. Lê SÓ da sessão (o nome do admin é gravado
            // lá no início) — nenhuma query por request, em nenhuma página.
            'simulacao' => [
                'ativa' => $request->session()->has(SimulacaoController::SESSAO_ADMIN_ID),
                'adminNome' => $request->session()->get(SimulacaoController::SESSAO_ADMIN_NOME),
            ],
        ];
    }
}
