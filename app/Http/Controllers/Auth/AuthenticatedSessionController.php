<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Escopo\ModoVisao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $request->user()->forceFill(['last_login_at' => now()])->save();

        /*
         * O assistente entra em "Minha carteira" (o que ele via até 2026-11-04) e alterna
         * para Equipe quando quiser; o supervisor continua entrando em Equipe. Gravado na
         * SESSÃO, e não decidido como default dentro do ModoVisao, para que tela, export e
         * IA leiam o mesmo valor — o export copia o modo da sessão para a fila.
         * Ex.: a equipe do Roberto é vazia, e a Natany entraria numa tela em branco.
         */
        if ($request->user()->hasRole('assistente')) {
            app(ModoVisao::class)->definir(ModoVisao::PESSOAL);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
