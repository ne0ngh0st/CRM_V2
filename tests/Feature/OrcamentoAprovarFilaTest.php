<?php

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\Orcamento;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Aprovar todos": o supervisor aprova a própria fila num clique.
 *
 * O que estes testes travam é o ALCANCE do clique — é uma escrita em massa, e cada
 * fronteira (equipe, nível, status, filtro, instante da leitura) é um orçamento que
 * poderia ser aprovado sem ninguém ter decidido aprová-lo.
 */
class OrcamentoAprovarFilaTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $vendedor;

    private User $vendedorDeFora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->supervisor = $this->usuario('supervisor', '000100', null);
        $this->vendedor = $this->usuario('vendedor', '000101', '000100');
        $this->vendedorDeFora = $this->usuario('vendedor', '000201', '000200');
    }

    public function test_aprova_a_fila_inteira_da_equipe_e_avisa_o_vendedor(): void
    {
        $a = $this->orcamento($this->vendedor);
        $b = $this->orcamento($this->vendedor);

        $this->actingAs($this->supervisor)
            ->post(route('orcamentos.aprovarFila'), ['ate' => now()->toIso8601String()])
            ->assertRedirect()
            ->assertSessionHas('orcamentoAviso.mensagem', '2 orçamentos aprovados.');

        foreach ([$a, $b] as $orcamento) {
            $orcamento->refresh();
            $this->assertSame('aprovado', $orcamento->status_gestor);
            $this->assertSame($this->supervisor->id, $orcamento->aprovado_por_id);
            $this->assertNotNull($orcamento->aprovado_em);
        }

        $this->assertSame(2, Notificacao::query()
            ->where('user_id', $this->vendedor->id)
            ->where('tipo', 'orcamento_decidido')
            ->count());
    }

    public function test_nao_alcanca_outra_equipe_nem_o_nivel_diretor_nem_o_que_ja_foi_decidido(): void
    {
        $deFora = $this->orcamento($this->vendedorDeFora);
        $nivelDiretor = $this->orcamento($this->vendedor, nivel: 'diretor');
        $rejeitado = $this->orcamento($this->vendedor, status: 'rejeitado');
        $daFila = $this->orcamento($this->vendedor);

        $this->actingAs($this->supervisor)
            ->post(route('orcamentos.aprovarFila'), ['ate' => now()->toIso8601String()])
            ->assertSessionHas('orcamentoAviso.mensagem', '1 orçamento aprovado.');

        $this->assertSame('pendente', $deFora->refresh()->status_gestor);
        $this->assertSame('pendente', $nivelDiretor->refresh()->status_gestor);
        $this->assertSame('rejeitado', $rejeitado->refresh()->status_gestor);
        $this->assertSame('aprovado', $daFila->refresh()->status_gestor);
    }

    public function test_diretor_aprova_so_a_fila_dele_nao_a_do_supervisor(): void
    {
        $diretor = User::factory()->create(['is_active' => true]);
        $diretor->assignRole('diretor');

        $doSupervisor = $this->orcamento($this->vendedor);
        $doDiretor = $this->orcamento($this->vendedor, nivel: 'diretor');

        $this->actingAs($diretor)
            ->post(route('orcamentos.aprovarFila'), ['ate' => now()->toIso8601String()]);

        $this->assertSame('pendente', $doSupervisor->refresh()->status_gestor);
        $this->assertSame('aprovado', $doDiretor->refresh()->status_gestor);
    }

    public function test_respeita_os_filtros_da_tela(): void
    {
        $outroVendedor = $this->usuario('vendedor', '000102', '000100');

        $filtrado = $this->orcamento($this->vendedor);
        $foraDoFiltro = $this->orcamento($outroVendedor);

        $this->actingAs($this->supervisor)
            ->post(route('orcamentos.aprovarFila'), [
                'visao_vendedor' => '000101',
                'ate' => now()->toIso8601String(),
            ]);

        $this->assertSame('aprovado', $filtrado->refresh()->status_gestor);
        $this->assertSame('pendente', $foraDoFiltro->refresh()->status_gestor);
    }

    public function test_orcamento_que_chegou_ou_mudou_depois_da_leitura_fica_de_fora(): void
    {
        $visto = $this->orcamento($this->vendedor);
        $lidoEm = now()->toIso8601String();

        $this->travel(5)->minutes();
        $novo = $this->orcamento($this->vendedor);
        $editado = $this->orcamento($this->vendedor);
        $editado->update(['desconto_pct_max' => 14.9]);

        $this->actingAs($this->supervisor)
            ->post(route('orcamentos.aprovarFila'), ['ate' => $lidoEm])
            ->assertSessionHas('orcamentoAviso.mensagem', '1 orçamento aprovado.');

        $this->assertSame('aprovado', $visto->refresh()->status_gestor);
        $this->assertSame('pendente', $novo->refresh()->status_gestor);
        $this->assertSame('pendente', $editado->refresh()->status_gestor);
    }

    public function test_quem_nao_tem_fila_nao_aprova_em_lote(): void
    {
        $orcamento = $this->orcamento($this->vendedor);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        foreach ([$this->vendedor, $admin] as $usuario) {
            $this->actingAs($usuario)
                ->post(route('orcamentos.aprovarFila'), ['ate' => now()->toIso8601String()])
                ->assertForbidden();
        }

        $this->assertSame('pendente', $orcamento->refresh()->status_gestor);
    }

    public function test_sem_o_instante_da_leitura_nada_e_aprovado(): void
    {
        $orcamento = $this->orcamento($this->vendedor);

        $this->actingAs($this->supervisor)
            ->post(route('orcamentos.aprovarFila'), [])
            ->assertSessionHasErrors('ate');

        $this->assertSame('pendente', $orcamento->refresh()->status_gestor);
    }

    public function test_a_tela_recebe_o_valor_da_fila_e_o_instante_da_leitura(): void
    {
        $this->orcamento($this->vendedor, valor: 150);
        $this->orcamento($this->vendedor, valor: 275);
        $this->orcamento($this->vendedor, nivel: 'diretor', valor: 900);

        $this->actingAs($this->supervisor)
            ->get(route('orcamentos.index', ['status' => 'pendente', 'nivel' => 'supervisor']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.aguardandoSupervisor', 2)
                ->where('kpis.valorAguardandoSupervisor', fn ($v) => abs($v - 425) < 0.001)
                ->where('kpis.valorAguardandoDiretor', fn ($v) => abs($v - 900) < 0.001)
                ->where('orcamentos.total', 2)
                ->has('filaLidaEm'));
    }

    public function test_aprovar_de_novo_o_que_ja_foi_aprovado_nao_e_erro(): void
    {
        // Clique duplo, ou linha que a lista ainda mostrava: era o "409 Conflict" na tela.
        $orcamento = $this->orcamento($this->vendedor);
        $rota = route('orcamentos.aprovar', $orcamento);

        $this->actingAs($this->supervisor)->patch($rota)->assertRedirect();
        $aprovadoEm = $orcamento->refresh()->aprovado_em;

        $this->travel(1)->minutes();
        $this->actingAs($this->supervisor)->patch($rota)
            ->assertRedirect()
            ->assertSessionMissing('orcamentoAviso');

        $this->assertEquals($aprovadoEm, $orcamento->refresh()->aprovado_em);
        $this->assertSame(1, Notificacao::query()->where('tipo', 'orcamento_decidido')->count());
    }

    public function test_aprovar_o_que_outro_rejeitou_avisa_e_nao_muda_nada(): void
    {
        $orcamento = $this->orcamento($this->vendedor, status: 'rejeitado');

        $this->actingAs($this->supervisor)
            ->patch(route('orcamentos.aprovar', $orcamento))
            ->assertRedirect()
            ->assertSessionHas('orcamentoAviso.tipo', 'erro');

        $this->assertSame('rejeitado', $orcamento->refresh()->status_gestor);
    }

    private function usuario(string $perfil, string $codigo, ?string $codSuper): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($perfil);
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codigo, 'cod_super' => $codSuper]);

        return $user;
    }

    private function orcamento(User $dono, string $status = 'pendente', string $nivel = 'supervisor', float $valor = 100): Orcamento
    {
        return Orcamento::query()->create([
            'user_id' => $dono->id,
            'cliente_nome' => 'Cliente Teste',
            'valor_total' => $valor,
            'tipo_produto_servico' => 'produto',
            'status_gestor' => $status,
            'nivel_aprovacao' => $nivel,
            'desconto_pct_max' => 12,
        ]);
    }
}
