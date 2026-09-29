<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Orcamento;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Dashboard\DashboardScopeResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O assistente tem o MESMO `cod_vendedor` do supervisor que apoia (2026-09-28).
 *
 * Por isso enxerga a carteira pessoal desse supervisor — Carteira, Leads, Pedidos e os
 * blocos operacionais do Painel —, mas não é vendedor: não entra no dropdown de
 * vendedores, não vê meta nem Evolução Comercial, e não tem a Visão Gestor.
 *
 * Até esta data ele via só os leads do site (de todos os vendedores) e nada da carteira.
 */
class AcessoAssistenteTest extends TestCase
{
    use RefreshDatabase;

    private const CODIGO_SUPERVISOR = '000197';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $perfil, string $codigo, ?string $codSuper = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($perfil);
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codigo, 'cod_super' => $codSuper]);

        return $user;
    }

    private function assistente(): User
    {
        return $this->usuario('assistente', self::CODIGO_SUPERVISOR);
    }

    private function lead(string $codVendedor, string $razao, string $origem = Lead::ORIGEM_MANUAL): Lead
    {
        return Lead::query()->create([
            'origem' => $origem,
            'nome' => $razao,
            'razao_social' => $razao,
            'email' => md5($razao).'@exemplo.com',
            'cod_vendedor' => $codVendedor,
            'status' => 'ativo',
        ]);
    }

    public function test_assistente_ve_a_carteira_do_proprio_codigo_e_so_ela(): void
    {
        Cliente::create(['cod_cliente' => '100', 'loja' => '0001', 'razao_social' => 'DO SUPERVISOR', 'cod_vendedor' => self::CODIGO_SUPERVISOR]);
        Cliente::create(['cod_cliente' => '200', 'loja' => '0001', 'razao_social' => 'DE OUTRO', 'cod_vendedor' => '010999']);

        $resposta = $this->actingAs($this->assistente())->get(route('carteira.index'))->assertOk();

        $razoes = collect($resposta->viewData('page')['props']['clientes']['data'])->pluck('razaoSocial')->all();
        $this->assertSame(['DO SUPERVISOR'], $razoes);
    }

    public function test_assistente_nao_ve_a_equipe_do_supervisor(): void
    {
        // Vendedor abaixo do supervisor: o supervisor em modo Equipe o veria; o assistente não.
        $this->usuario('vendedor', '010500', self::CODIGO_SUPERVISOR);

        $escopo = app(DashboardScopeResolver::class)->resolve($this->assistente(), null, null);

        $this->assertSame([self::CODIGO_SUPERVISOR], $escopo['codVendedores']);
    }

    public function test_assistente_ve_leads_do_proprio_codigo_de_qualquer_origem(): void
    {
        $this->lead(self::CODIGO_SUPERVISOR, 'Manual Ltda');
        $this->lead(self::CODIGO_SUPERVISOR, 'Site Ltda', Lead::ORIGEM_WORDPRESS);
        $this->lead('010999', 'Site de Outro Ltda', Lead::ORIGEM_WORDPRESS);

        $this->actingAs($this->assistente())
            ->get(route('carteira.index', ['aba' => 'leads']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Carteira/Index')
                ->missing('somenteWordpress')
                ->where('leadsKpis.total', 2)
                ->has('leads.data', 2)
            );
    }

    public function test_assistente_age_so_em_lead_do_proprio_codigo(): void
    {
        $assistente = $this->assistente();
        $meu = $this->lead(self::CODIGO_SUPERVISOR, 'Meu Ltda');
        $alheio = $this->lead('010999', 'Alheio Ltda', Lead::ORIGEM_WORDPRESS);

        $this->actingAs($assistente)->post(route('leads.ligacao', $meu))->assertRedirect();
        $this->assertDatabaseHas('ligacoes', ['lead_id' => $meu->id, 'usuario_id' => $assistente->id]);

        $this->actingAs($assistente)->post(route('leads.ligacao', $alheio))->assertForbidden();
    }

    public function test_assistente_ve_pedidos_do_proprio_codigo(): void
    {
        Pedido::create(['numero_pedido' => '900001', 'cod_vendedor' => self::CODIGO_SUPERVISOR, 'data_pedido' => now()->subDay(), 'valor_total' => 100, 'status' => 'pendente_totvs']);
        Pedido::create(['numero_pedido' => '900002', 'cod_vendedor' => '010999', 'data_pedido' => now()->subDay(), 'valor_total' => 200, 'status' => 'pendente_totvs']);

        $this->actingAs($this->assistente())
            ->get(route('pedidos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pedidos.data', 1)
                ->where('pedidos.data.0.numeroPedido', '900001')
            );
    }

    public function test_painel_do_assistente_tem_blocos_operacionais_sem_desempenho(): void
    {
        $props = $this->actingAs($this->assistente())->get(route('dashboard'))
            ->assertOk()->viewData('page')['props'];

        $this->assertNotNull($props['carteiraSegmento']);
        $this->assertNotNull($props['segmentosInativos']);
        $this->assertNotNull($props['pedidosAtencao']);
        $this->assertNotNull($props['ligacoesStats']);
        $this->assertNotNull($props['orcamentosStats']);

        $this->assertNull($props['metaGauge']);
        $this->assertNull($props['vendaComparacao']);
        $this->assertNull($props['faturamentoComparacao']);
        $this->assertNull($props['biEmbedUrl']);
        $this->assertFalse($props['visao']['mostrarSeletor']);
    }

    public function test_assistente_nao_entra_no_dropdown_de_vendedores(): void
    {
        $this->assistente();
        $supervisor = $this->usuario('supervisor', self::CODIGO_SUPERVISOR, '010002');
        $this->usuario('vendedor', '010500', self::CODIGO_SUPERVISOR);

        $codigos = app(DashboardScopeResolver::class)->opcoesVendedores($supervisor, null)->pluck('cod_vendedor')->all();

        $this->assertSame(['010500'], $codigos);
    }

    public function test_assistente_nao_acessa_visao_gestor(): void
    {
        $assistente = $this->assistente();

        foreach (['equipe.index', 'metas.index', 'visao-gestor.index'] as $rota) {
            $this->actingAs($assistente)->get(route($rota))->assertRedirect(route('dashboard'));
        }

        $this->actingAs($assistente)->get(route('visao-diretor.maiores.index'))->assertForbidden();
    }

    public function test_assistente_acessa_catalogo_tabela_e_orcamentos(): void
    {
        $assistente = $this->assistente();

        $this->actingAs($assistente)->get(route('catalogo-facas.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalogo/Facas')->where('podeGerenciar', false));
        $this->actingAs($assistente)->get(route('tabela-precos.index'))->assertOk();
        $this->actingAs($assistente)->get(route('orcamentos.index'))->assertOk();
        $this->actingAs($assistente)->get(route('orcamentos.novo'))->assertOk();
    }

    public function test_assistente_so_ve_os_proprios_orcamentos(): void
    {
        $assistente = $this->assistente();
        $supervisor = $this->usuario('supervisor', self::CODIGO_SUPERVISOR);

        Orcamento::query()->create(['user_id' => $assistente->id, 'cliente_nome' => 'Cliente da assistente', 'valor_total' => 100, 'tipo_produto_servico' => 'produto']);
        Orcamento::query()->create(['user_id' => $supervisor->id, 'cliente_nome' => 'Cliente do supervisor', 'valor_total' => 200, 'tipo_produto_servico' => 'produto']);

        $this->actingAs($assistente)
            ->get(route('orcamentos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.total', 1)
                ->where('orcamentos.data.0.clienteNome', 'Cliente da assistente')
            );
    }
}
