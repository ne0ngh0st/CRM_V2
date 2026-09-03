<?php

namespace Tests\Feature;

use App\Models\Faturamento;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A aba VENDA do card de comparação do painel.
 *
 * Venda (pedido emitido) é o que o vendedor consegue influenciar hoje; faturamento é
 * consequência e chega depois. Por isso venda virou a aba padrão.
 */
class DashboardVendaComparacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(string $cod = '010617'): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);

        return $user;
    }

    private function pedido(string $data, float $valor, string $cod = '010617'): void
    {
        Pedido::create([
            'numero_pedido' => 'P'.fake()->unique()->numberBetween(1, 999999),
            'cod_vendedor' => $cod,
            'data_pedido' => $data,
            'valor_total' => $valor,
            'status' => 'faturado',
        ]);
    }

    #[Test]
    public function test_vendedor_recebe_a_aba_venda_no_painel(): void
    {
        $this->pedido(now()->startOfYear()->addMonths(1)->toDateString(), 1000);

        $props = $this->actingAs($this->vendedor())
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNotNull($props['vendaComparacao']);
        $this->assertSame('venda', $props['vendaComparacao']['metrica']);
        $this->assertSame((int) now()->year, $props['vendaComparacao']['anoAtual']);
    }

    /** Os 12 meses sempre vêm preenchidos — mês sem pedido é zero, não buraco no gráfico. */
    #[Test]
    public function test_serie_tem_sempre_doze_meses(): void
    {
        $bloco = app(DashboardBlocos::class);
        $dados = $bloco->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        $this->assertCount(12, $dados['valoresAnoAtual']);
        $this->assertCount(12, $dados['valoresAnoAnterior']);
        $this->assertSame(array_fill(0, 12, 0.0), $dados['valoresAnoAtual']);
    }

    #[Test]
    public function test_soma_por_mes_respeita_o_escopo_do_vendedor(): void
    {
        $fevereiro = now()->startOfYear()->addMonth();
        $this->pedido($fevereiro->toDateString(), 1000, '010617');
        $this->pedido($fevereiro->toDateString(), 500, '010617');
        $this->pedido($fevereiro->toDateString(), 9999, '999999');

        $dados = app(DashboardBlocos::class)
            ->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        // Índice 1 = fevereiro.
        $this->assertSame(1500.0, $dados['valoresAnoAtual'][1]);
    }

    /**
     * ⚠️ O teste mais importante deste arquivo. Venda e faturamento têm o MESMO formato de
     * retorno, então uma chave de cache compartilhada não causaria erro nenhum — só
     * entregaria o número errado, na aba errada, silenciosamente. É o pior tipo de bug de
     * cache: nada quebra, o valor só fica mentiroso.
     */
    #[Test]
    public function test_venda_e_faturamento_nao_dividem_a_chave_de_cache(): void
    {
        $fevereiro = now()->startOfYear()->addMonth();
        $this->pedido($fevereiro->toDateString(), 1000, '010617');

        Faturamento::create([
            'nota_fiscal' => '123',
            'data_emissao' => $fevereiro->toDateString(),
            'cod_vendedor' => '010617',
            'valor_total' => 7777,
            'quantidade' => 1,
            'valor_unitario' => 7777,
        ]);

        $bloco = app(DashboardBlocos::class);
        $escopo = ChaveEscopo::deCodVendedores(['010617']);

        $venda = $bloco->vendaComparacao($escopo, ['010617']);
        $faturamento = $bloco->faturamentoComparacao($escopo, ['010617']);

        $this->assertSame(1000.0, $venda['valoresAnoAtual'][1], 'a aba Venda tem que somar pedidos');
        $this->assertSame(7777.0, $faturamento['valoresAnoAtual'][1], 'a aba Faturamento tem que somar faturamento');
        $this->assertSame('venda', $venda['metrica']);
        $this->assertSame('faturamento', $faturamento['metrica']);
    }

    /**
     * Gestor vê o Power BI, não o Chart.js — a agregação (a mais cara da Home no escopo
     * empresa) não roda para quem não vai ler o resultado.
     */
    #[Test]
    public function test_gestor_nao_recebe_a_agregacao_de_venda(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $props = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertNull($props['vendaComparacao']);
        $this->assertNull($props['faturamentoComparacao']);
    }
}
