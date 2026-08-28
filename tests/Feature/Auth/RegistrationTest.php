<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O CRM-V2 NÃO tem registro público — usuários nascem no TOTVS e entram pelo
 * `legado:import-usuarios` ou pela tela de Equipe (admin). É a mesma regra do PALMA legado.
 *
 * ⚠️ Estes testes vinham do scaffold do Breeze verificando que qualquer pessoa podia se
 * cadastrar, e falhavam desde que as rotas foram removidas. Em vez de deletá-los, foram
 * invertidos: agora protegem a decisão. Se alguém reativar `Route::get('/register')` sem
 * querer (um `php artisan breeze:install` refeito, por exemplo), a suíte acusa — que é
 * exatamente o cenário perigoso, já que abriria criação de conta num sistema comercial.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_de_registro_publico_nao_existe(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nao_e_possivel_criar_conta_pela_web(): void
    {
        $this->post('/register', [
            'name' => 'Invasor',
            'email' => 'invasor@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'invasor@example.com']);
    }
}
