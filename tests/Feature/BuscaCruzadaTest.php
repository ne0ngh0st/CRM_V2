<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Lead;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aviso cruzado Carteira ↔ Leads: "N leads também correspondem a 'kntt'".
 *
 * 🚨 A invariante é uma só: o número do aviso == o total que a outra página mostra ao
 * abrir o link. Por isso os testes principais comparam o endpoint COM A PRÓPRIA TELA, e
 * não com número escrito à mão — é o que continua valendo se alguém mexer na regra de
 * busca de um lado só.
 *
 * ⚠️ Fixture montado para distinguir o que importa:
 *  - o cliente 100 tem DUAS filiais que casam: contar filial daria 3 onde a Carteira
 *    agrupada mostra 2;
 *  - "SUPERKNTT" casa só por substring — trava o `%termo%`;
 *  - um lead casa só pelo e-mail — trava que o aviso usa a regra INTEIRA da tela de Leads;
 *  - um lead excluído casa o termo e não pode contar;
 *  - o vendedor 002 tem um cliente e um lead que casam — escopo.
 */
class BuscaCruzadaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => '001']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->cliente('100', '0001', 'KNTT COMERCIO LTDA', '001');
        $this->cliente('100', '0002', 'KNTT FILIAL SUL', '001');
        $this->cliente('200', '0001', 'SUPERKNTT MERCADO', '001');
        $this->cliente('300', '0001', 'KNTT DE OUTRO VENDEDOR', '002');
        $this->cliente('400', '0001', 'FARMACIA SEM RELACAO', '001');

        $this->lead(['razao_social' => 'Kntt Etiquetas', 'cod_vendedor' => '001']);
        $this->lead(['razao_social' => 'ABC Embalagens', 'email' => 'compras@kntt.com.br', 'cod_vendedor' => '001']);
        $this->lead(['razao_social' => 'Kntt Apagado', 'cod_vendedor' => '001', 'status' => 'excluido']);
        $this->lead(['razao_social' => 'Kntt Rival', 'cod_vendedor' => '002']);
        $this->lead(['razao_social' => 'Padaria Sem Relacao', 'cod_vendedor' => '001']);
    }

    private function cliente(string $cod, string $loja, string $razao, string $vendedor): void
    {
        Cliente::create([
            'cod_cliente' => $cod,
            'loja' => $loja,
            'razao_social' => $razao,
            'cod_vendedor' => $vendedor,
        ]);
    }

    private function lead(array $atributos): void
    {
        Lead::create([
            'origem' => Lead::ORIGEM_MANUAL,
            'nome' => 'Contato',
            ...$atributos,
        ]);
    }

    private function aviso(User $como, string $alvo, string $busca): int
    {
        return $this->actingAs($como)
            ->getJson(route('buscaCruzada', ['alvo' => $alvo, 'busca' => $busca]))
            ->assertOk()
            ->json('total');
    }

    private function totalDaCarteira(User $como, string $busca): int
    {
        return $this->actingAs($como)
            ->get(route('carteira.index', ['busca' => $busca]))
            ->assertOk()
            ->viewData('page')['props']['clientes']['total'];
    }

    private function totalDosLeads(User $como, string $busca): int
    {
        return $this->actingAs($como)
            ->get(route('leads.index', ['busca' => $busca]))
            ->assertOk()
            ->viewData('page')['props']['leads']['total'];
    }

    public function test_aviso_de_leads_bate_com_a_tela_de_leads(): void
    {
        foreach ([[$this->vendedor, 2], [$this->admin, 3]] as [$usuario, $esperado]) {
            $aviso = $this->aviso($usuario, 'leads', 'kntt');

            $this->assertSame($esperado, $aviso);
            $this->assertSame($this->totalDosLeads($usuario, 'kntt'), $aviso);
        }
    }

    public function test_aviso_de_clientes_bate_com_a_carteira_agrupada(): void
    {
        foreach ([[$this->vendedor, 2], [$this->admin, 3]] as [$usuario, $esperado]) {
            $aviso = $this->aviso($usuario, 'clientes', 'kntt');

            $this->assertSame($esperado, $aviso, 'conta CLIENTE, não filial');
            $this->assertSame($this->totalDaCarteira($usuario, 'kntt'), $aviso);
        }
    }

    public function test_vendedor_nao_conta_carteira_de_outra_pessoa(): void
    {
        $this->assertSame(0, $this->aviso($this->vendedor, 'clientes', 'outro vendedor'));
        $this->assertSame(0, $this->aviso($this->vendedor, 'leads', 'rival'));
        $this->assertSame(1, $this->aviso($this->admin, 'leads', 'rival'));
    }

    public function test_termo_curto_nao_consulta(): void
    {
        $this->assertSame(0, $this->aviso($this->admin, 'leads', 'kn'));
        $this->assertSame(0, $this->aviso($this->admin, 'clientes', '  '));
    }

    public function test_alvo_desconhecido_nao_existe(): void
    {
        $this->actingAs($this->admin)->getJson('/busca-cruzada/pedidos?busca=kntt')->assertNotFound();
    }

    public function test_exige_login(): void
    {
        $this->getJson(route('buscaCruzada', ['alvo' => 'leads', 'busca' => 'kntt']))->assertUnauthorized();
    }
}
