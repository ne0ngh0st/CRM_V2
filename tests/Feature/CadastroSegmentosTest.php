<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CadastroSegmentosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = $this->usuario('admin');
    }

    private function usuario(string $perfil): User
    {
        $user = User::factory()->create();
        $user->assignRole($perfil);

        return $user;
    }

    private function segmento(string $codigo = '200', string $nome = 'TESTE'): Segmento
    {
        return Segmento::create(['codigo' => $codigo, 'nome' => $nome, 'peso_potencial' => 5]);
    }

    private function cliente(string $codigo, string $segmento): void
    {
        Cliente::create(['cod_cliente' => $codigo, 'loja' => '01', 'razao_social' => 'CLIENTE', 'cod_segmento' => $segmento]);
    }

    public function test_cria_normalizando_codigo_e_nome(): void
    {
        $this->actingAs($this->admin)
            ->post(route('equipe.segmentos.store'), ['codigo' => '000200', 'nome' => ' academias ', 'peso' => 7.5])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('segmentos', ['codigo' => '200', 'nome' => 'ACADEMIAS', 'peso_potencial' => 7.5]);
    }

    public function test_recusa_codigo_e_nome_repetidos_e_peso_fora_da_escala(): void
    {
        $this->segmento();

        $this->actingAs($this->admin)
            ->post(route('equipe.segmentos.store'), ['codigo' => '200', 'nome' => 'teste', 'peso' => 21])
            ->assertSessionHasErrors(['codigo', 'nome', 'peso']);
    }

    public function test_editar_nao_muda_o_codigo_e_renomeia_os_leads(): void
    {
        $segmento = $this->segmento();
        Lead::create(['origem' => Lead::ORIGEM_MANUAL, 'nome' => 'Contato', 'razao_social' => 'LEAD', 'segmento' => 'TESTE']);
        DB::table('leads')->update(['updated_at' => now()->subDay()]);
        $alteradoEm = DB::table('leads')->value('updated_at');

        $this->actingAs($this->admin)
            ->put(route('equipe.segmentos.update', $segmento), ['codigo' => '999', 'nome' => 'novo nome', 'peso' => 12])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('segmentos', ['id' => $segmento->id, 'codigo' => '200', 'nome' => 'NOVO NOME', 'peso_potencial' => 12]);
        $this->assertSame('NOVO NOME', DB::table('leads')->value('segmento'));
        $this->assertSame($alteradoEm, DB::table('leads')->value('updated_at'));
    }

    public function test_exclui_segmento_sem_uso(): void
    {
        $segmento = $this->segmento();

        $this->actingAs($this->admin)->delete(route('equipe.segmentos.destroy', $segmento))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('segmentos', ['id' => $segmento->id]);
    }

    public function test_nao_exclui_segmento_em_uso(): void
    {
        $comPessoa = $this->segmento('201', 'COM PESSOA');
        SegmentoVendedor::create(['cod_vendedor' => '000001', 'segmento_id' => $comPessoa->id]);
        $comCliente = $this->segmento('202', 'COM CLIENTE');
        $this->cliente('1', '202');
        $comLead = $this->segmento('203', 'COM LEAD');
        Lead::create(['origem' => Lead::ORIGEM_MANUAL, 'nome' => 'Contato', 'razao_social' => 'LEAD', 'segmento' => 'COM LEAD']);
        $supermercadista = $this->segmento(Segmento::CODIGO_SUPERMERCADISTA, 'SUPERMERCADISTA');

        foreach ([$comPessoa, $comCliente, $comLead, $supermercadista] as $segmento) {
            $this->actingAs($this->admin)
                ->delete(route('equipe.segmentos.destroy', $segmento))
                ->assertSessionHasErrors('segmento');
            $this->assertDatabaseHas('segmentos', ['id' => $segmento->id]);
        }
    }

    public function test_supervisor_ve_o_quadro_mas_nao_o_cadastro(): void
    {
        $supervisor = $this->usuario('supervisor');
        VendedorPerfil::create(['user_id' => $supervisor->id, 'cod_vendedor' => '000900']);
        $segmento = $this->segmento();

        $this->actingAs($supervisor)->get(route('equipe.segmentos'))
            ->assertInertia(fn ($page) => $page->where('cadastro', []));

        $this->actingAs($supervisor)->post(route('equipe.segmentos.store'), ['codigo' => '300', 'nome' => 'X', 'peso' => 1])->assertForbidden();
        $this->actingAs($supervisor)->put(route('equipe.segmentos.update', $segmento), ['nome' => 'X', 'peso' => 1])->assertForbidden();
        $this->actingAs($supervisor)->delete(route('equipe.segmentos.destroy', $segmento))->assertForbidden();
    }

    public function test_tela_mostra_o_uso_de_cada_segmento(): void
    {
        $segmento = $this->segmento();
        $this->cliente('1', '200');
        $this->cliente('2', '200');
        SegmentoVendedor::create(['cod_vendedor' => '000001', 'segmento_id' => $segmento->id]);

        $this->actingAs($this->admin)->get(route('equipe.segmentos'))
            ->assertInertia(fn ($page) => $page
                ->where('cadastro.0.clientes', 2)
                ->where('cadastro.0.pessoas', 1)
                ->where('cadastro.0.podeExcluir', false));
    }

    public function test_visao_diretor_suprimida_responde_404_e_some_do_menu_sem_tirar_a_estrela(): void
    {
        config(['visao_diretor.habilitada' => false]);
        $segmento = $this->segmento();

        $this->actingAs($this->admin)->get(route('visao-diretor.maiores.index'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('visao-diretor.redes.index'))->assertNotFound();

        $this->actingAs($this->admin)->get(route('equipe.segmentos'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.veVisaoDiretor', false)
                ->where('podeDefinirEspecialista', true));

        $this->actingAs($this->admin)
            ->patch(route('equipe.segmentos.especialista', $segmento), ['especialista_user_id' => $this->admin->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->admin->id, $segmento->fresh()->especialista_user_id);
    }
}
