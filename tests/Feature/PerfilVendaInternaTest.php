<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Dashboard\DashboardScopeResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perfil `venda_interna`: opera como vendedor, pelo próprio `cod_vendedor`.
 *
 * Até 2026-09-24 a conta da venda interna (comercial@autopel.com, 010617) era
 * `assistente` — Painel sem blocos e Carteira vazia, apesar de ter clientes no código.
 * O contraste com o assistente, no mesmo fixture, é o que prova que o perfil novo não é
 * só um rótulo diferente.
 */
class PerfilVendaInternaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Cliente::create(['cod_cliente' => '100', 'loja' => '0001', 'razao_social' => 'DA VENDA INTERNA', 'cod_vendedor' => '010617']);
        Cliente::create(['cod_cliente' => '200', 'loja' => '0001', 'razao_social' => 'DE OUTRO VENDEDOR', 'cod_vendedor' => '010999']);
    }

    private function usuario(string $perfil, string $codigo = '010617'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($perfil);
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codigo, 'cod_super' => '010389']);

        return $user;
    }

    public function test_venda_interna_ve_a_propria_carteira_e_so_ela(): void
    {
        $resposta = $this->actingAs($this->usuario('venda_interna'))->get(route('carteira.index'));

        $resposta->assertOk();
        $razoes = collect($resposta->viewData('page')['props']['clientes']['data'])->pluck('razaoSocial')->all();
        $this->assertSame(['DA VENDA INTERNA'], $razoes);
    }

    public function test_assistente_com_o_mesmo_codigo_continua_sem_carteira(): void
    {
        $resposta = $this->actingAs($this->usuario('assistente'))->get(route('carteira.index'));

        $resposta->assertOk();
        $this->assertSame([], $resposta->viewData('page')['props']['clientes']['data']);
    }

    public function test_painel_da_venda_interna_tem_os_blocos_comerciais(): void
    {
        $props = $this->actingAs($this->usuario('venda_interna'))->get(route('dashboard'))
            ->assertOk()->viewData('page')['props'];

        $this->assertNotNull($props['metaGauge']);
        $this->assertNotNull($props['carteiraSegmento']);
        $this->assertNotNull($props['vendaComparacao']);
        $this->assertFalse($props['metaGauge']['isRepresentante']);
    }

    public function test_venda_interna_entra_no_dropdown_de_vendedores_do_supervisor(): void
    {
        $this->usuario('venda_interna');
        $supervisor = $this->usuario('supervisor', '010389');

        $codigos = app(DashboardScopeResolver::class)->opcoesVendedores($supervisor, null)->pluck('cod_vendedor')->all();

        $this->assertContains('010617', $codigos);
    }

    public function test_perfil_aparece_nas_opcoes_da_equipe(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $perfis = $this->actingAs($admin)->get(route('equipe.index'))
            ->assertOk()->viewData('page')['props']['opcoes']['perfis'];

        $this->assertContains('venda_interna', collect($perfis)->all());
    }
}
