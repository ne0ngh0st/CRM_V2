<?php

namespace Tests\Feature;

use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Diretor (e supervisor) entram em Orçamentos na fila de aprovação, não na base inteira.
 *
 * Pedido do Tony em 2026-09-17: o diretor abria a tela e via tudo, e no celular o
 * cartão escondia o desconto até expandir — então ele não sabia o que estava
 * aprovando. A fila é o recorte padrão; `ver=todos` é o escape do tile Total.
 *
 * O teste que sustenta o card é `test_todo_numero_do_card_bate_com_a_lista_que_ele_abre`:
 * cada tile tem que abrir exatamente aquele tanto de linhas. É a invariante do Tony
 * ("os dois números têm que bater") escrita como código.
 *
 * ⚠️ Fixture com contagens TODAS diferentes: 3 / 2 / 1 / 4 / 5. Com números
 * parecidos, filtrar por status no KPI (o bug) passaria verde.
 */
class OrcamentoFilaAprovacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $diretor;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');

        $this->diretor = User::factory()->create(['is_active' => true]);
        $this->diretor->assignRole('diretor');

        $this->criar(3, 'pendente', 'diretor', 18);
        $this->criar(2, 'pendente', 'supervisor', 12);
        $this->criar(1, 'pendente', 'nenhum', 0);
        $this->criar(4, 'aprovado', 'diretor', 20);
        $this->criar(5, 'rejeitado', 'diretor', 22);
    }

    public function test_diretor_cai_na_fila_ao_entrar_sem_filtro(): void
    {
        $this->actingAs($this->diretor)
            ->get(route('orcamentos.index'))
            ->assertRedirect(route('orcamentos.index', [
                'status' => 'pendente',
                'nivel' => 'diretor',
            ]));
    }

    public function test_supervisor_cai_na_fila_de_supervisor(): void
    {
        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');

        $this->actingAs($supervisor)
            ->get(route('orcamentos.index'))
            ->assertRedirect(route('orcamentos.index', [
                'status' => 'pendente',
                'nivel' => 'supervisor',
            ]));
    }

    public function test_admin_nao_tem_fila(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('orcamentos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros.status', '')
                ->where('filtros.nivel', '')
                ->where('filaAprovacao', null)
                ->where('kpis.total', 15)
                ->has('orcamentos.data', 15));
    }

    public function test_vendedor_nao_tem_fila(): void
    {
        $this->actingAs($this->vendedor)
            ->get(route('orcamentos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros.status', '')
                ->where('filaAprovacao', null)
                ->has('orcamentos.data', 15));
    }

    public function test_ver_todos_nao_redireciona_e_mostra_a_base(): void
    {
        $this->actingAs($this->diretor)
            ->get(route('orcamentos.index', ['ver' => 'todos']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros.ver', 'todos')
                ->where('filtros.status', '')
                ->where('kpis.total', 15)
                ->where('orcamentos.total', 15));
    }

    public function test_status_escolhido_nao_e_sobrescrito_pela_fila(): void
    {
        $this->actingAs($this->diretor)
            ->get(route('orcamentos.index', ['status' => 'aprovado']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros.status', 'aprovado')
                ->where('orcamentos.total', 4));
    }

    public function test_busca_sobrevive_ao_redirect_da_fila(): void
    {
        $this->actingAs($this->diretor)
            ->get(route('orcamentos.index', ['busca' => 'KNTT']))
            ->assertRedirect(route('orcamentos.index', [
                'busca' => 'KNTT',
                'status' => 'pendente',
                'nivel' => 'diretor',
            ]));
    }

    public function test_na_fila_os_kpis_nao_zeram_os_vizinhos(): void
    {
        /*
         * 🚨 Sem `comFacetas: false` o card na fila diria "0 aprovados · 3 pendentes" —
         * "quantos aprovados entre os que pedem diretor?". O tile existe para dizer
         * quantos há na carteira inteira, com o recorte da lista à parte.
         */
        $this->actingAs($this->diretor)
            ->get(route('orcamentos.index', ['status' => 'pendente', 'nivel' => 'diretor']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.total', 15)
                ->where('kpis.aguardandoDiretor', 3)
                ->where('kpis.aguardandoSupervisor', 2)
                ->where('kpis.aprovados', 4)
                ->where('kpis.rejeitados', 5)
                ->where('orcamentos.total', 3));
    }

    public function test_todo_numero_do_card_bate_com_a_lista_que_ele_abre(): void
    {
        $kpis = $this->props(['ver' => 'todos'])['kpis'];

        $this->assertSame($kpis['total'], $this->totalDaLista(['ver' => 'todos']), 'tile total');
        $this->assertSame($kpis['aguardandoSupervisor'], $this->totalDaLista([
            'status' => 'pendente',
            'nivel' => 'supervisor',
        ]), 'tile aguard. supervisor');
        $this->assertSame($kpis['aguardandoDiretor'], $this->totalDaLista([
            'status' => 'pendente',
            'nivel' => 'diretor',
        ]), 'tile aguard. diretor');
        $this->assertSame($kpis['aprovados'], $this->totalDaLista(['status' => 'aprovado']), 'tile aprovados');
        $this->assertSame($kpis['rejeitados'], $this->totalDaLista(['status' => 'rejeitado']), 'tile rejeitados');
    }

    public function test_busca_continua_valendo_no_card(): void
    {
        $this->criar(1, 'pendente', 'diretor', 19, 'SUPERMERCADO KNTT');

        $comBusca = $this->props(['ver' => 'todos', 'busca' => 'KNTT']);

        $this->assertSame(1, $comBusca['kpis']['total']);
        $this->assertSame(1, $comBusca['kpis']['aguardandoDiretor']);
        $this->assertGreaterThan(1, $this->props(['ver' => 'todos'])['kpis']['total']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function props(array $query): array
    {
        return $this->actingAs($this->diretor)
            ->get(route('orcamentos.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    /** @param  array<string, mixed>  $query */
    private function totalDaLista(array $query): int
    {
        return $this->props($query)['orcamentos']['total'];
    }

    private function criar(
        int $quantidade,
        string $status,
        string $nivel,
        float $desconto,
        ?string $cliente = null,
    ): void {
        for ($i = 0; $i < $quantidade; $i++) {
            $orcamento = Orcamento::query()->create([
                'user_id' => $this->vendedor->id,
                'cliente_nome' => $cliente ?? "Cliente {$status} {$nivel} {$i}",
                'valor_total' => 100 + $i,
                'tipo_produto_servico' => 'produto',
                'status_gestor' => $status,
                'nivel_aprovacao' => $nivel,
                'desconto_pct_max' => $desconto,
            ]);

            OrcamentoItem::query()->create([
                'orcamento_id' => $orcamento->id,
                'descricao' => 'BOBINA TESTE',
                'quantidade' => 1,
                'valor_unitario' => 100,
                'valor_total' => 100,
            ]);
        }
    }
}
