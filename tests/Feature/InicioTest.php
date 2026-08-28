<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Porta de entrada do sistema (`/` → InicioController).
 *
 * Substitui o `ExampleTest` do scaffold, que afirmava que `/` devolvia HTTP 200 e falhava
 * desde sempre: aqui a raiz nunca renderiza nada, ela só redireciona.
 *
 * Vale como regressão do InicioController, criado em 2026-08-27 para tirar a Closure de
 * routes/web.php — Closure não é serializável e fazia `php artisan route:cache` abortar
 * o arquivo inteiro (ver docs/performance.md).
 */
class InicioTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_usuario_logado_e_mandado_para_o_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }
}
