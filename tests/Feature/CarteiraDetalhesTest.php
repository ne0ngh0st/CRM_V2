<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Ver detalhes" do cliente passou a aparecer também pra vendedor/representante
 * (antes era só gestor). O botão sempre existiu na rota — o que mudou foi a
 * visibilidade — então o que precisa estar travado é o ESCOPO: vendedor não pode
 * abrir a ficha de cliente que não é da carteira dele.
 */
class CarteiraDetalhesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(string $codVendedor): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor]);

        return $user;
    }

    private function cliente(string $codVendedor, string $codCliente): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $codCliente,
            'loja' => '01',
            'razao_social' => "CLIENTE {$codCliente}",
            'cod_vendedor' => $codVendedor,
        ]);
    }

    public function test_vendedor_abre_detalhes_de_cliente_da_propria_carteira(): void
    {
        $vendedor = $this->vendedor('001');
        $cliente = $this->cliente('001', '100');

        $this->actingAs($vendedor)
            ->get(route('carteira.detalhes', $cliente->id))
            ->assertOk();
    }

    public function test_vendedor_nao_abre_detalhes_de_cliente_de_outro(): void
    {
        $vendedor = $this->vendedor('001');
        $this->vendedor('002');
        $clienteAlheio = $this->cliente('002', '200');

        $this->actingAs($vendedor)
            ->get(route('carteira.detalhes', $clienteAlheio->id))
            ->assertForbidden();
    }

    public function test_admin_abre_detalhes_de_qualquer_cliente(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->vendedor('001');
        $cliente = $this->cliente('001', '100');

        $this->actingAs($admin)
            ->get(route('carteira.detalhes', $cliente->id))
            ->assertOk();
    }
}
