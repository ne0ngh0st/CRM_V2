<?php

namespace Tests\Feature;

use App\Models\ContaEstrategica;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * O especialista do segmento — a estrela do quadro de Segmentos da Equipe.
 *
 * O que estes testes protegem, em ordem de importância:
 *  1. UMA fonte só: o que é marcado no quadro é o que o Resumo da Visão Diretor e o card
 *     do Painel mostram — e o Painel mostra no request SEGUINTE, apesar de o bloco de
 *     segmentos ser cacheado por 30 min;
 *  2. quem marca é admin/diretor (gate da Visão Diretor); supervisor arrasta pessoas no
 *     quadro, mas não aponta o responsável da empresa pelo segmento;
 *  3. um por segmento: a estrela em outra pessoa tira a anterior;
 *  4. o seletor antigo da Visão Diretor não existe mais (não pode haver dois lugares).
 */
class EspecialistaSegmentoTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $drogarias;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->drogarias = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
    }

    private function usuario(string $papel, ?string $cod = null, array $attrs = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attrs]);
        $user->assignRole($papel);

        if ($cod) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);
            SegmentoVendedor::create(['cod_vendedor' => $cod, 'segmento_id' => $this->drogarias->id]);
        }

        return $user;
    }

    private function marcar(User $quem, ?User $especialista, ?Segmento $segmento = null)
    {
        return $this->actingAs($quem)->patch(
            route('equipe.segmentos.especialista', $segmento ?? $this->drogarias),
            ['especialista_user_id' => $especialista?->id],
        );
    }

    public function test_diretor_marca_e_o_quadro_mostra_a_estrela(): void
    {
        $diretor = $this->usuario('diretor');
        $inaya = $this->usuario('vendedor', '001', ['display_name' => 'INAYA']);

        $this->marcar($diretor, $inaya)->assertSessionHasNoErrors();

        $this->assertSame($inaya->id, $this->drogarias->fresh()->especialista_user_id);

        $quadro = $this->actingAs($diretor)->get(route('equipe.segmentos'))
            ->assertOk()->viewData('page')['props'];

        $this->assertTrue($quadro['podeDefinirEspecialista']);
        $segmento = collect($quadro['quadro']['segmentos'])->firstWhere('codigo', '109');
        $this->assertSame($inaya->id, $segmento['especialista']['id']);
        $this->assertSame('INAYA', $segmento['especialista']['nome']);
        $this->assertArrayHasKey('fotoUrl', $segmento['especialista']);
    }

    public function test_marcar_outra_pessoa_tira_a_anterior_e_null_desmarca(): void
    {
        $admin = $this->usuario('admin');
        $a = $this->usuario('vendedor', '001');
        $b = $this->usuario('vendedor', '002');

        $this->marcar($admin, $a);
        $this->marcar($admin, $b);
        $this->assertSame($b->id, $this->drogarias->fresh()->especialista_user_id);

        $this->marcar($admin, null);
        $this->assertNull($this->drogarias->fresh()->especialista_user_id);
    }

    public function test_supervisor_e_vendedor_nao_marcam(): void
    {
        $alvo = $this->usuario('vendedor', '001');

        foreach (['supervisor', 'vendedor', 'representante', 'assistente'] as $papel) {
            $this->marcar($this->usuario($papel), $alvo)->assertForbidden();
        }

        $this->assertNull($this->drogarias->fresh()->especialista_user_id);

        // Com código: supervisor sem equipe nem entra no quadro (é redirecionado).
        $supervisor = $this->usuario('supervisor', '900');
        $props = $this->actingAs($supervisor)->get(route('equipe.segmentos'))->viewData('page')['props'];
        $this->assertFalse($props['podeDefinirEspecialista']);
    }

    public function test_usuario_inativo_nao_pode_ser_especialista(): void
    {
        $inativo = $this->usuario('vendedor', '001', ['is_active' => false]);

        $this->marcar($this->usuario('admin'), $inativo)->assertSessionHasErrors('especialista_user_id');

        $this->assertNull($this->drogarias->fresh()->especialista_user_id);
    }

    /**
     * Quem saiu da empresa deixa de aparecer como responsável, mesmo com a coluna gravada.
     */
    public function test_especialista_que_ficou_inativo_some_das_telas(): void
    {
        $inaya = $this->usuario('vendedor', '001');
        $this->marcar($this->usuario('admin'), $inaya);

        $inaya->update(['is_active' => false]);

        $segmento = collect($this->actingAs($this->usuario('admin'))->get(route('equipe.segmentos'))
            ->viewData('page')['props']['quadro']['segmentos'])->firstWhere('codigo', '109');

        $this->assertNull($segmento['especialista']);
    }

    /**
     * ⚠️ O bloco de segmentos do Painel é cacheado por 30 min. O especialista vem numa prop
     * à parte justamente para a estrela aparecer no request seguinte — este teste falha se
     * alguém o puser dentro do bloco cacheado.
     */
    public function test_painel_mostra_o_especialista_no_request_seguinte(): void
    {
        $admin = $this->usuario('admin');
        $inaya = $this->usuario('vendedor', '001', ['display_name' => 'INAYA']);

        $antes = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertArrayNotHasKey('109', (array) $antes['especialistasSegmento']);

        $this->marcar($admin, $inaya);

        $depois = $this->actingAs($admin)->get(route('dashboard'))->viewData('page')['props'];
        $this->assertSame('INAYA', $depois['especialistasSegmento']['109']['nome']);
    }

    public function test_resumo_da_visao_diretor_le_a_mesma_marcacao(): void
    {
        $admin = $this->usuario('admin');
        $inaya = $this->usuario('vendedor', '001', ['display_name' => 'INAYA']);
        ContaEstrategica::create(['segmento_id' => $this->drogarias->id, 'nome' => 'RAIA']);

        $this->marcar($admin, $inaya);

        $resumo = collect($this->actingAs($admin)->get(route('visao-diretor.maiores.index'))
            ->assertOk()->viewData('page')['props']['dados']['resumoPorSegmento'])->firstWhere('codigo', '109');

        $this->assertSame($inaya->id, $resumo['especialista']['id']);
    }

    public function test_o_seletor_antigo_da_visao_diretor_nao_existe_mais(): void
    {
        $this->assertFalse(Route::has('visao-diretor.maiores.especialista'));
    }
}
