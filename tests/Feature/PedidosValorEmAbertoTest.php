<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O valor EM ABERTO — a carteira inteira de pedidos não faturados.
 *
 * Antes só existia o "valor em risco" (atrasado + vencendo em 7 dias), e um número sem o
 * total ao lado não se interpreta: R$ 27 mi em risco pode ser 71% da carteira ou 7%.
 *
 * ⚠️ Os valores dos pedidos deste fixture são TODOS DIFERENTES e escolhidos para que
 * nenhum subconjunto some o mesmo que outro. Com valores parecidos, trocar "todos os
 * abertos" por "os que estão em risco" passaria verde — que é exatamente o erro que este
 * teste existe para pegar.
 */
class PedidosValorEmAbertoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => '010585']);

        $cliente = $this->cliente('010585');
        $outro = $this->cliente('019999');

        // Em aberto, do vendedor: 1.000 (atrasado) + 200 (vence em 3 dias) + 30 (no prazo).
        $this->pedido('900001', $cliente, '010585', 1000, now()->subDays(5));
        $this->pedido('900002', $cliente, '010585', 200, now()->addDays(3));
        $this->pedido('900003', $cliente, '010585', 30, now()->addDays(60));

        // Em aberto, de outro vendedor — fora do escopo dele.
        $this->pedido('900004', $outro, '019999', 7, now()->addDays(2));

        // Faturado: não é carteira em aberto, não entra em nenhum dos dois valores.
        $this->pedido('900005', $cliente, '010585', 5000, now()->subDays(9), faturado: true);
    }

    public function test_a_tela_soma_todos_os_pedidos_em_aberto_do_escopo(): void
    {
        $kpis = $this->kpisDaTela($this->admin);

        // 1.000 + 200 + 30 + 7 — o faturado de 5.000 fica de fora.
        $this->assertEqualsWithDelta(1237.0, $kpis['valorEmAberto'], 0.001);
        $this->assertSame(4, $kpis['totalAberto']);
    }

    public function test_valor_em_aberto_e_maior_que_o_valor_em_risco(): void
    {
        $kpis = $this->kpisDaTela($this->admin);

        // Em risco = 1.000 (atrasado) + 200 + 7 (vencendo). Fora: os 30 no prazo.
        $this->assertEqualsWithDelta(1207.0, $kpis['valorEmRisco'], 0.001);
        $this->assertEqualsWithDelta(1237.0, $kpis['valorEmAberto'], 0.001);
        $this->assertNotEquals($kpis['valorEmRisco'], $kpis['valorEmAberto']);
    }

    public function test_os_contadores_continuam_batendo_depois_da_agregacao_fundida(): void
    {
        $kpis = $this->kpisDaTela($this->admin);

        $this->assertSame(1, $kpis['atrasados']);
        $this->assertSame(2, $kpis['vencendo']); // 900002 (3 dias) e 900004 (2 dias)
    }

    public function test_o_valor_em_aberto_respeita_o_escopo_do_vendedor(): void
    {
        $kpis = $this->kpisDaTela($this->vendedor);

        $this->assertSame(3, $kpis['totalAberto']);
        $this->assertEqualsWithDelta(1230.0, $kpis['valorEmAberto'], 0.001); // sem os 7 do outro vendedor
    }

    public function test_o_filtro_da_tela_vale_para_o_valor_em_aberto(): void
    {
        $kpis = $this->kpisDaTela($this->admin, ['busca' => '900003']);

        $this->assertSame(1, $kpis['totalAberto']);
        $this->assertEqualsWithDelta(30.0, $kpis['valorEmAberto'], 0.001);
    }

    public function test_o_bloco_do_painel_devolve_os_mesmos_numeros_da_tela(): void
    {
        $kpis = $this->kpisDaTela($this->admin);

        $bloco = app(DashboardBlocos::class)->pedidosAtencao(ChaveEscopo::deCodVendedores(null), null);

        $this->assertSame($kpis['totalAberto'], $bloco['totalAberto']);
        $this->assertSame($kpis['atrasados'], $bloco['atrasados']);
        $this->assertSame($kpis['vencendo'], $bloco['vencendo']);
        $this->assertEqualsWithDelta($kpis['valorEmAberto'], $bloco['valorEmAberto'], 0.001);
        $this->assertEqualsWithDelta($kpis['valorEmRisco'], $bloco['valorEmRisco'], 0.001);
    }

    public function test_o_bloco_do_painel_escopado_ignora_pedido_de_outro_vendedor(): void
    {
        $bloco = app(DashboardBlocos::class)
            ->pedidosAtencao(ChaveEscopo::deCodVendedores(['010585']), ['010585']);

        $this->assertSame(3, $bloco['totalAberto']);
        $this->assertEqualsWithDelta(1230.0, $bloco['valorEmAberto'], 0.001);
    }

    /**
     * ⚠️ Dinheiro é comparado com `assertEqualsWithDelta`, nunca `assertSame`: os KPIs
     * chegam aqui pelo payload JSON do Inertia, e um float redondo (1237.0) volta do
     * `json_decode` como int. A distinção float/int não existe na serialização — insistir
     * nela transformaria o teste numa asserção sobre o transporte, não sobre o número.
     *
     * @return array<string, mixed>
     */
    private function kpisDaTela(User $user, array $filtros = []): array
    {
        $kpis = [];

        $this->actingAs($user)
            ->get(route('pedidos.index', $filtros))
            ->assertOk()
            ->assertInertia(function ($page) use (&$kpis) {
                $page->component('Pedidos/Index');
                $kpis = $page->toArray()['props']['kpis'];
            });

        return $kpis;
    }

    private function cliente(string $codVendedor): Cliente
    {
        return Cliente::create([
            'cod_cliente' => substr('00'.$codVendedor, -6),
            'loja' => '0001',
            'razao_social' => "CLIENTE {$codVendedor}",
            'cnpj' => '10.466.386/0001-85',
            'cod_vendedor' => $codVendedor,
        ]);
    }

    private function pedido(string $numero, Cliente $cliente, string $codVendedor, float $valor, $previsao, bool $faturado = false): Pedido
    {
        return Pedido::create([
            'numero_pedido' => $numero,
            'cliente_id' => $cliente->id,
            'cod_vendedor' => $codVendedor,
            'data_pedido' => now()->subDays(10),
            'data_previsao_faturamento' => $previsao,
            'data_faturamento' => $faturado ? now()->subDays(8) : null,
            'status' => $faturado ? 'faturado' : 'pendente_totvs',
            'valor_total' => $valor,
        ]);
    }
}
