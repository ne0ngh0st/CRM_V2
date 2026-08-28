<?php

namespace Tests\Feature;

use App\Http\Controllers\SimulacaoController;
use App\Models\SimulacaoUsuario;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SimulacaoUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $role, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attrs));
        $user->assignRole($role);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_simula_vendedor_e_passa_a_ser_ele(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->actingAs($admin)
            ->post(route('simulacao.iniciar', $vendedor->id))
            ->assertRedirect(route('dashboard'));

        // O guard passou a devolver o alvo — é isso que faz valer em toda página.
        $this->assertSame($vendedor->id, Auth::id());
        $this->assertSame($admin->id, session(SimulacaoController::SESSAO_ADMIN_ID));
    }

    public function test_simulacao_persiste_entre_paginas_e_respeita_o_escopo_do_alvo(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => '010772']);

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));

        // Várias páginas diferentes, com e sem filtro na query string: em todas o usuário
        // autenticado continua sendo o alvo (o legado perdia isso a partir da 2ª página).
        foreach ([
            route('dashboard'),
            route('carteira.index'),
            route('carteira.index', ['aderencia' => 'dentro', 'busca' => 'teste']),
            route('leads.index', ['status' => 'novo']),
            route('orcamentos.index'),
            route('pedidos.index'),
            route('tabela-precos.index', ['busca' => 'bobina']),
            route('catalogo-facas.index', ['tipo' => 'balanca']),
        ] as $url) {
            $this->get($url)->assertSuccessful();
            $this->assertSame($vendedor->id, Auth::id(), "Perdeu a simulação em {$url}");
        }
    }

    public function test_banner_aparece_em_toda_pagina_e_some_ao_encerrar(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
            ->where('simulacao.ativa', true)
            ->where('simulacao.adminNome', $admin->display_name ?: $admin->name));

        $this->get(route('carteira.index'))->assertInertia(fn ($page) => $page->where('simulacao.ativa', true));

        $this->post(route('simulacao.encerrar'))->assertRedirect(route('equipe.index'));

        $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('simulacao.ativa', false));
    }

    public function test_encerrar_devolve_o_admin(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));
        $this->assertSame($vendedor->id, Auth::id());

        $this->post(route('simulacao.encerrar'));

        $this->assertSame($admin->id, Auth::id());
        $this->assertNull(session(SimulacaoController::SESSAO_ADMIN_ID));
    }

    public function test_o_simulado_consegue_encerrar_mesmo_nao_sendo_admin(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));

        // Quem está autenticado agora é o vendedor — encerrar não pode exigir perfil admin.
        $this->post(route('simulacao.encerrar'))->assertRedirect(route('equipe.index'));
        $this->assertSame($admin->id, Auth::id());
    }

    public function test_registra_auditoria_com_inicio_e_fim(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));

        $log = SimulacaoUsuario::first();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame($vendedor->id, $log->alvo_id);
        $this->assertNotNull($log->iniciada_em);
        $this->assertNull($log->encerrada_em);

        $this->post(route('simulacao.encerrar'));
        $this->assertNotNull($log->fresh()->encerrada_em);
    }

    public function test_nao_admin_nao_simula(): void
    {
        $alvo = $this->usuario('vendedor');

        foreach (['vendedor', 'supervisor', 'diretor', 'assistente'] as $role) {
            $this->actingAs($this->usuario($role))
                ->post(route('simulacao.iniciar', $alvo->id))
                ->assertForbidden();
        }

        $this->assertSame(0, SimulacaoUsuario::count());
    }

    public function test_nao_simula_outro_admin_nem_a_si_mesmo_nem_inativo(): void
    {
        $admin = $this->usuario('admin');

        $this->actingAs($admin)
            ->post(route('simulacao.iniciar', $this->usuario('admin')->id))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('simulacao.iniciar', $admin->id))
            ->assertStatus(422);

        $this->actingAs($admin)
            ->post(route('simulacao.iniciar', $this->usuario('vendedor', ['is_active' => false])->id))
            ->assertStatus(422);
    }

    public function test_nao_aninha_simulacao(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');
        $outro = $this->usuario('representante');

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));

        // Já simulando: tentar trocar de alvo direto tem que falhar (e nem seria admin).
        $this->post(route('simulacao.iniciar', $outro->id))->assertForbidden();
        $this->assertSame($vendedor->id, Auth::id());
        $this->assertSame(1, SimulacaoUsuario::count());
    }

    public function test_encerrar_sem_simulacao_ativa_nao_quebra(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->post(route('simulacao.encerrar'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_simulacao_nao_adiciona_query_por_request(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $semSimulacao = $this->contarQueries(fn () => $this->actingAs($vendedor)->get(route('tabela-precos.index')));

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));
        $comSimulacao = $this->contarQueries(fn () => $this->get(route('tabela-precos.index')));

        // O banner lê só da sessão. Tolerância de 1 pra ruído de sessão/auth entre runs.
        $this->assertLessThanOrEqual(
            $semSimulacao + 1,
            $comSimulacao,
            "Simular passou a custar queries extras: {$semSimulacao} → {$comSimulacao}.",
        );
    }

    /**
     * Delega pro contador compartilhado (app/Support/Perf/ContadorDeQueries.php).
     *
     * A versão anterior registrava um `DB::listen` novo a cada chamada, e como listener
     * não pode ser removido, o contador da 1ª chamada continuava sendo incrementado
     * pela 2ª. Aqui não acontece: o listener é único e só acumula durante a medição.
     */
    private function contarQueries(callable $acao): int
    {
        return ContadorDeQueries::contar(fn () => $acao());
    }
}
