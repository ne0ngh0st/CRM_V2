<?php

namespace Tests\Feature;

use App\Models\Faca;
use App\Models\User;
use Database\Seeders\FacaSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogoFacaTest extends TestCase
{
    use RefreshDatabase;

    private function vendedor(): User
    {
        $this->seed(RoleSeeder::class);
        $this->seed(FacaSeeder::class);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('vendedor');

        return $user;
    }

    public function test_lista_o_catalogo_completo(): void
    {
        $user = $this->vendedor();

        $this->actingAs($user)
            ->get(route('catalogo-facas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalogo/Facas')
                ->where('kpis.total', 127)
                ->where('kpis.filtradas', 127)
                ->has('facas', 127)
                ->has('opcoes.tipos', 8)
            );
    }

    public function test_filtra_por_catalogo(): void
    {
        $user = $this->vendedor();

        $this->actingAs($user)
            ->get(route('catalogo-facas.index', ['tipo' => 'balanca']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('facas', 27)
                // Total geral não muda com o filtro — só "filtradas".
                ->where('kpis.total', 127)
                ->where('kpis.filtradas', 27)
            );
    }

    public function test_tipo_invalido_nao_filtra_nada(): void
    {
        $user = $this->vendedor();

        $this->actingAs($user)
            ->get(route('catalogo-facas.index', ['tipo' => 'inexistente']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('facas', 127)
                ->where('filtros.tipo', '')
            );
    }

    public function test_busca_por_medida_e_por_recorte(): void
    {
        $user = $this->vendedor();

        $this->actingAs($user)
            ->get(route('catalogo-facas.index', ['busca' => 'corte retangular']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'kpis.filtradas',
                Faca::whereHas('recursos', fn ($q) => $q->where('descricao', 'like', '%corte retangular%'))->count(),
            ));

        $this->actingAs($user)
            ->get(route('catalogo-facas.index', ['busca' => '40x25']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('kpis.filtradas', 1));
    }

    public function test_imagem_vira_url_publica(): void
    {
        $user = $this->vendedor();

        $this->actingAs($user)
            ->get(route('catalogo-facas.index', ['tipo' => 'balanca']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('facas.0.item', 1)
                ->where('facas.0.observacao', 'Para essa faca só temos na versão com picote.')
                ->where('facas.0.recursos.0.imagem', '/images/facas/balanca/X + LATERAIS SUP E INF.png')
                ->where('facas.0.recursos.0.descricao', 'X + laterais sup e inf')
            );
    }

    public function test_exige_login(): void
    {
        $this->get(route('catalogo-facas.index'))->assertRedirect(route('login'));
    }
}
