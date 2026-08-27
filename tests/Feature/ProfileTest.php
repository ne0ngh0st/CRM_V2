<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perfil do usuário.
 *
 * ⚠️ Divergências propositais em relação ao scaffold do Breeze, que faziam 4 destes
 * testes falharem desde sempre:
 *
 * 1. O formulário edita `display_name`, não `name`. O `name` é o nome legal vindo do
 *    TOTVS e o usuário não mexe nele (ver ProfileUpdateRequest e ImportUsuariosLegado).
 * 2. Não existe auto-exclusão de conta: usuário vem do TOTVS/admin, quem desliga alguém
 *    é o `is_active`. A rota DELETE /profile foi removida — e o teste abaixo protege isso.
 * 3. `ProfileController::update()` responde com `back()`, então o teste precisa declarar
 *    de onde veio (`from('/profile')`) para o redirect ser verificável.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_dados_do_perfil_podem_ser_atualizados(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'display_name' => 'Tony',
                'email' => 'tony@autopel.com',
                'telefone' => '11 99999-0000',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Tony', $user->display_name);
        $this->assertSame('tony@autopel.com', $user->email);
        $this->assertSame('11 99999-0000', $user->telefone);

        // Trocar de e-mail derruba a verificação — é o comportamento do update().
        $this->assertNull($user->email_verified_at);
    }

    public function test_nome_legal_do_totvs_nao_e_alterado_pelo_formulario(): void
    {
        $user = User::factory()->create(['name' => 'ANTONIO BARBOSA']);

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'display_name' => 'Tony',
                'email' => $user->email,
                'name' => 'Nome Forjado',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('ANTONIO BARBOSA', $user->refresh()->name);
    }

    public function test_verificacao_de_email_continua_quando_o_email_nao_muda(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', [
                'display_name' => 'Tony',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_usuario_nao_pode_excluir_a_propria_conta(): void
    {
        $user = User::factory()->create();

        // 405, não 404: a URI /profile existe (GET e PATCH), o que não existe é o DELETE.
        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertStatus(405);

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticated();
    }
}
