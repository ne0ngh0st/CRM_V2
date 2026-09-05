<?php

namespace Tests\Feature;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O segmento de quem está olhando, explícito na Home.
 *
 * Até 2026-09-05 o nome do segmento não aparecia em lugar nenhum do Painel: o card
 * "Carteira por Segmento" falava em dentro/fora do segmento sem jamais dizer aderência a
 * QUÊ. Agora sai numa pill no topo (identidade) e em chips no header do card (contexto).
 *
 * ⚠️ A regra é "escopo de UM vendedor", não "perfil de vendedor" — é o que faz o gestor,
 * ao entrar na visão de um vendedor, ver a mesma tela que ele. Em escopo de equipe ou
 * empresa a prop vem vazia: "o segmento" seriam os 23, o que não informa nada.
 */
class DashboardSegmentoVendedorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function segmento(string $codigo, string $nome): Segmento
    {
        return Segmento::create(['codigo' => $codigo, 'nome' => $nome]);
    }

    private function usuario(string $role, ?string $cod = null, ?string $codSuper = null): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($cod !== null) {
            VendedorPerfil::create([
                'user_id' => $user->id,
                'cod_vendedor' => $cod,
                'cod_super' => $codSuper,
            ]);
        }

        return $user;
    }

    private function atribuir(string $cod, Segmento ...$segmentos): void
    {
        foreach ($segmentos as $segmento) {
            SegmentoVendedor::create(['cod_vendedor' => $cod, 'segmento_id' => $segmento->id]);
        }
    }

    private function propsDe(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('dashboard', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    #[Test]
    public function test_vendedor_com_um_segmento_recebe_o_nome(): void
    {
        $super = $this->segmento('101', 'SUPERMERCADISTA');
        $this->atribuir('010617', $super);

        $props = $this->propsDe($this->usuario('vendedor', '010617'));

        $this->assertSame(['SUPERMERCADISTA'], $props['segmentosVendedor']);
    }

    /** Ordem alfabética: a pill mostra o primeiro e resume o resto em "+N". */
    #[Test]
    public function test_vendedor_com_varios_segmentos_recebe_todos_em_ordem(): void
    {
        $this->atribuir(
            '010617',
            $this->segmento('101', 'SUPERMERCADISTA'),
            $this->segmento('103', 'ORGAO PUBLICO'),
            $this->segmento('109', 'DROGARIAS'),
        );

        $props = $this->propsDe($this->usuario('vendedor', '010617'));

        $this->assertSame(['DROGARIAS', 'ORGAO PUBLICO', 'SUPERMERCADISTA'], $props['segmentosVendedor']);
    }

    /**
     * Vendedor sem segmento definido é caso normal (recém-chegado, generalista) — a prop
     * vem vazia e a pill some, em vez de aparecer um "—" sem sentido no topo da página.
     */
    #[Test]
    public function test_vendedor_sem_segmento_recebe_lista_vazia(): void
    {
        $props = $this->propsDe($this->usuario('vendedor', '010617'));

        $this->assertSame([], $props['segmentosVendedor']);
    }

    #[Test]
    public function test_representante_tambem_recebe(): void
    {
        $this->atribuir('020001', $this->segmento('101', 'SUPERMERCADISTA'));

        $props = $this->propsDe($this->usuario('representante', '020001'));

        $this->assertSame(['SUPERMERCADISTA'], $props['segmentosVendedor']);
    }

    /**
     * ⚠️ Escopo de equipe não tem "o segmento". O supervisor em modo Equipe enxerga vários
     * vendedores, cada um com os seus.
     */
    #[Test]
    public function test_supervisor_em_escopo_de_equipe_nao_recebe(): void
    {
        $this->atribuir('010617', $this->segmento('101', 'SUPERMERCADISTA'));
        $this->atribuir('010618', $this->segmento('103', 'ORGAO PUBLICO'));

        $supervisor = $this->usuario('supervisor', '000006');
        $this->usuario('vendedor', '010617', '000006');
        $this->usuario('vendedor', '010618', '000006');

        $props = $this->propsDe($supervisor);

        $this->assertSame([], $props['segmentosVendedor']);
    }

    #[Test]
    public function test_admin_em_escopo_de_empresa_nao_recebe(): void
    {
        $this->atribuir('010617', $this->segmento('101', 'SUPERMERCADISTA'));

        $props = $this->propsDe($this->usuario('admin'));

        $this->assertSame([], $props['segmentosVendedor']);
    }

    /**
     * ⚠️ O gestor que entra na visão de UM vendedor passa a ver a tela dele — inclusive o
     * segmento. É por isso que a condição no controller olha o escopo resolvido, e não o
     * perfil de quem está logado.
     */
    #[Test]
    public function test_admin_com_drill_down_num_vendedor_recebe_o_segmento_dele(): void
    {
        $this->atribuir('010617', $this->segmento('101', 'SUPERMERCADISTA'));
        $this->usuario('vendedor', '010617');

        $props = $this->propsDe($this->usuario('admin'), ['visao_vendedor' => '010617']);

        $this->assertSame(['SUPERMERCADISTA'], $props['segmentosVendedor']);
    }
}
