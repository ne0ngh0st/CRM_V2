<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perfil "Cadastro" (2026-11-04): quem só pede cadastro — o Vini, da licitação, para
 * quem o CRM é a via oficial. Alcança /cadastros e a própria conta, e nada mais.
 */
class PerfilCadastroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuarioCadastro(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('cadastro');

        return $user;
    }

    public function test_alcanca_cadastros_e_a_propria_conta(): void
    {
        $user = $this->usuarioCadastro();

        $this->actingAs($user)->get(route('cadastros.index'))->assertOk();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        $this->actingAs($user)->get(route('exportacoes.index'))->assertOk();
    }

    public function test_o_painel_e_as_telas_comerciais_voltam_para_cadastros(): void
    {
        $user = $this->usuarioCadastro();

        foreach (['dashboard', 'carteira.index', 'leads.index', 'pedidos.index', 'orcamentos.index', 'tabela-precos.index'] as $rota) {
            $this->actingAs($user)->get(route($rota))->assertRedirect(route('cadastros.index'));
        }
    }

    public function test_escrita_fora_de_cadastros_e_recusada(): void
    {
        $this->actingAs($this->usuarioCadastro())
            ->post(route('observacoes.store'), ['observacao' => 'x'])
            ->assertForbidden();
    }

    public function test_outros_perfis_nao_sao_afetados(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $this->actingAs($vendedor)->get(route('dashboard'))->assertOk();
    }
}
