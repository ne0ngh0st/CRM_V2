<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Orcamento;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcessoAssistenteTest extends TestCase
{
    use RefreshDatabase;

    private function assistente(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('assistente');

        return $user;
    }

    private function vendedor(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('vendedor');

        return $user;
    }

    private function lead(string $origem, array $attrs = []): Lead
    {
        return Lead::query()->create(array_merge([
            'origem' => $origem,
            'nome' => 'Lead '.$origem,
            'razao_social' => 'Empresa '.$origem,
            'email' => $origem.'@exemplo.com',
            'cod_vendedor' => '010617',
            'status' => 'ativo',
        ], $attrs));
    }

    public function test_assistente_ve_so_leads_do_wordpress(): void
    {
        $assistente = $this->assistente();
        $this->lead(Lead::ORIGEM_WORDPRESS, ['razao_social' => 'Site Ltda']);
        $this->lead(Lead::ORIGEM_MANUAL, ['razao_social' => 'Manual Ltda']);
        $this->lead(Lead::ORIGEM_SISTEMA, ['razao_social' => 'Sistema Ltda']);

        $this->actingAs($assistente)
            ->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Leads/Index')
                ->where('somenteWordpress', true)
                ->where('filtros.origem', Lead::ORIGEM_WORDPRESS)
                ->where('kpis.total', 1)
                ->where('kpis.wordpress', 1)
                ->where('kpis.manual', 0)
                ->where('kpis.sistema', 0)
                ->has('leads.data', 1)
                ->where('leads.data.0.origem', Lead::ORIGEM_WORDPRESS)
                ->where('leads.data.0.razaoSocial', 'Site Ltda')
            );
    }

    public function test_assistente_nao_fura_o_filtro_trocando_origem_na_url(): void
    {
        $assistente = $this->assistente();
        $this->lead(Lead::ORIGEM_WORDPRESS);
        $this->lead(Lead::ORIGEM_MANUAL);

        $this->actingAs($assistente)
            ->get(route('leads.index', ['origem' => Lead::ORIGEM_MANUAL]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros.origem', Lead::ORIGEM_WORDPRESS)
                ->has('leads.data', 1)
                ->where('leads.data.0.origem', Lead::ORIGEM_WORDPRESS)
            );
    }

    public function test_assistente_nao_age_em_lead_que_nao_e_wordpress(): void
    {
        $assistente = $this->assistente();
        $manual = $this->lead(Lead::ORIGEM_MANUAL);

        $this->actingAs($assistente)
            ->post(route('leads.ligacao', $manual))
            ->assertForbidden();

        $this->actingAs($assistente)
            ->delete(route('leads.destroy', $manual))
            ->assertForbidden();
    }

    public function test_assistente_liga_em_lead_wordpress(): void
    {
        $assistente = $this->assistente();
        $wp = $this->lead(Lead::ORIGEM_WORDPRESS);

        $this->actingAs($assistente)
            ->post(route('leads.ligacao', $wp))
            ->assertRedirect();

        $this->assertDatabaseHas('ligacoes', [
            'lead_id' => $wp->id,
            'usuario_id' => $assistente->id,
        ]);
    }

    public function test_assistente_acessa_catalogo_tabela_e_orcamentos(): void
    {
        $assistente = $this->assistente();

        $this->actingAs($assistente)
            ->get(route('catalogo-facas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalogo/Facas')
                ->where('podeGerenciar', false)
            );

        $this->actingAs($assistente)
            ->get(route('tabela-precos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('TabelaPrecos/Index'));

        $this->actingAs($assistente)
            ->get(route('orcamentos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Orcamentos/Index'));

        $this->actingAs($assistente)
            ->get(route('orcamentos.novo'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Orcamentos/Form'));
    }

    public function test_assistente_so_ve_os_proprios_orcamentos(): void
    {
        $assistente = $this->assistente();
        $vendedor = $this->vendedor();

        Orcamento::query()->create([
            'user_id' => $assistente->id,
            'cliente_nome' => 'Cliente da assistente',
            'valor_total' => 100,
            'tipo_produto_servico' => 'produto',
        ]);
        Orcamento::query()->create([
            'user_id' => $vendedor->id,
            'cliente_nome' => 'Cliente do vendedor',
            'valor_total' => 200,
            'tipo_produto_servico' => 'produto',
        ]);

        $this->actingAs($assistente)
            ->get(route('orcamentos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.total', 1)
                ->has('orcamentos.data', 1)
                ->where('orcamentos.data.0.clienteNome', 'Cliente da assistente')
            );
    }
}
