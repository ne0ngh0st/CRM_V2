<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Login e sessão com SESSION_DRIVER=redis.
 *
 * ⚠️ POR QUE ESTE TESTE EXISTE SEPARADO:
 * o phpunit.xml força `SESSION_DRIVER=array` para isolar os testes uns dos outros, o que
 * é o certo — mas significa que a suíte inteira NUNCA exercita o driver que roda em
 * produção. Trocar o driver de sessão é a mudança com pior modo de falha do projeto:
 * se quebrar, ninguém consegue logar, e a suíte verde não avisaria nada.
 *
 * Aqui o driver é forçado para `redis` de propósito, então este é o único ponto da suíte
 * que prova que o caminho real funciona: sessão gravada, autenticação persistindo entre
 * requisições e logout limpando.
 *
 * A senha usada é a fixture da UserFactory ('password'), não credencial de ninguém.
 */
class SessaoRedisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'redis',
            'session.connection' => 'default',
        ]);
    }

    public function test_login_persiste_entre_requisicoes_com_sessao_no_redis(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        // A prova de que a sessão sobrevive: uma requisição nova, sem actingAs,
        // continua reconhecendo o usuário. Se o driver estivesse quebrado, cairia no login.
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_sessao_e_gravada_no_redis_e_nao_no_mysql(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $chaves = Redis::connection('default')->keys('*');

        $this->assertNotEmpty(
            $chaves,
            'Nenhuma chave no Redis após o login — a sessão não está indo pro driver certo.',
        );

        // A tabela `sessions` do MySQL não pode receber nada: se receber, o driver
        // continua em `database` e o ganho de 2 queries por request não existe.
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');

        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
    }
}
