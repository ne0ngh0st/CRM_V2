<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\RedefinirSenhaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_de_pedir_link_renderiza(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_link_de_redefinicao_e_enviado(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, RedefinirSenhaNotification::class);
    }

    public function test_email_sai_em_portugues_e_nao_assinado_como_laravel(): void
    {
        Notification::fake();

        $user = User::factory()->create(['display_name' => 'FULANO DE TAL']);

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, RedefinirSenhaNotification::class, function ($n) use ($user) {
            $html = $n->toMail($user)->render();
            $texto = strip_tags($html);

            $this->assertStringContainsString('Redefinição de senha', $texto);
            $this->assertStringContainsString('FULANO DE TAL', $texto);
            $this->assertStringContainsString('Autopel Soluções', $texto);
            $this->assertStringContainsString('Criar nova senha', $texto);
            $this->assertStringContainsString('Se o botão não funcionar', $texto);

            /*
             * As frases que o template padrão do Laravel injeta. Foram exatamente elas
             * que sobraram em inglês na primeira versão desta tela, então ficam travadas
             * aqui: se alguém voltar a usar MailMessage, o teste acusa.
             */
            $this->assertStringNotContainsString('Regards', $texto);
            $this->assertStringNotContainsString('having trouble clicking', $texto);
            $this->assertStringNotContainsString('All rights reserved', $texto);

            return true;
        });
    }

    public function test_tela_de_redefinir_renderiza_com_o_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, RedefinirSenhaNotification::class, function ($n) {
            $this->get('/reset-password/'.$n->token)->assertStatus(200);

            return true;
        });
    }

    public function test_senha_e_trocada_com_token_valido(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, RedefinirSenhaNotification::class, function ($n) use ($user) {
            $this->post('/reset-password', [
                'token' => $n->token,
                'email' => $user->email,
                'password' => 'SenhaNova#2026',
                'password_confirmation' => 'SenhaNova#2026',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('SenhaNova#2026', $user->fresh()->password),
            'A senha nova deveria valer depois da redefinição.'
        );
    }

    /**
     * O scaffold do Breeze devolvia erro de validação quando a conta não existia, o que
     * permite descobrir quais e-mails têm acesso testando um a um — grave aqui, onde o
     * endereço corporativo segue um padrão previsível.
     */
    public function test_nao_revela_se_o_email_existe(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $existente = $this->post('/forgot-password', ['email' => $user->email]);
        $inexistente = $this->post('/forgot-password', ['email' => 'ninguem@autopel.com']);

        $existente->assertSessionHasNoErrors();
        $inexistente->assertSessionHasNoErrors();
        $this->assertSame(
            $existente->getSession()->get('status'),
            $inexistente->getSession()->get('status'),
            'A resposta tem que ser idêntica, senão dá para enumerar usuários.'
        );

        Notification::assertNothingSentTo(User::factory()->make(['email' => 'ninguem@autopel.com']));
    }

    public function test_usuario_inativo_nao_recebe_link(): void
    {
        Notification::fake();

        $inativo = User::factory()->create(['is_active' => false]);

        $this->post('/forgot-password', ['email' => $inativo->email])
            ->assertSessionHasNoErrors();

        Notification::assertNothingSentTo($inativo);
    }
}
