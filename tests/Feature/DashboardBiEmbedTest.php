<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardBiEmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public static function gestores(): array
    {
        return [
            'supervisor' => ['supervisor'],
            'admin' => ['admin'],
            'diretor' => ['diretor'],
        ];
    }

    /**
     * ⚠️ INVERTIDO em 2026-09-06, não apagado. Este teste afirmava que gestor recebia o
     * embed E `faturamentoComparacao` NULO — o card local era exclusivo de vendedor.
     *
     * O diretor pediu o contrário: "na tela inicial dos ADM devem ter essas informações
     * também" e "retira esse painel de BI e troca por um botão". Agora o gestor recebe as
     * duas agregações E a URL do BI, que virou atalho em vez de iframe.
     *
     * Mantido invertido porque a decisão nova precisa de guarda igual à antiga: se alguém
     * recolocar o `&& ! $eGestor` no controller, a suíte acusa.
     */
    #[DataProvider('gestores')]
    public function test_gestor_recebe_o_atalho_do_bi_e_tambem_as_agregacoes(string $role): void
    {
        /*
         * ⚠️ Supervisor precisa de equipe, senão `codVendedores` volta `[]`, `$temEscopo`
         * é falso e TODO bloco escopado vem nulo. A versão anterior deste teste afirmava
         * justamente que os blocos eram nulos — e passava neste perfil pelo motivo errado,
         * escopo vazio em vez do gate de gestor. Sem esta equipe, o teste novo continuaria
         * sem exercitar o caminho que ele diz cobrir.
         */
        $gestor = $this->usuario($role, comCodigo: true);

        if ($role === 'supervisor') {
            $subordinado = $this->usuario('vendedor', comCodigo: true);
            $subordinado->vendedorPerfil->update(['cod_super' => $gestor->vendedorPerfil->cod_vendedor]);
        }

        $this->actingAs($gestor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('biEmbedUrl', config('powerbi.embed_url'))
                ->where('faturamentoComparacao.anoAtual', (int) now()->year)
                ->where('vendaComparacao.anoAtual', (int) now()->year)
            );
    }

    public function test_vendedor_continua_com_o_grafico_local(): void
    {
        $this->actingAs($this->vendedorComCodigo())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('biEmbedUrl', null)
                ->where('faturamentoComparacao.anoAtual', (int) now()->year)
            );
    }

    public function test_representante_nao_ve_o_embed(): void
    {
        $this->actingAs($this->usuario('representante', comCodigo: true))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('biEmbedUrl', null)
                ->has('faturamentoComparacao')
            );
    }

    public function test_assistente_nao_ve_nem_embed_nem_grafico(): void
    {
        $this->actingAs($this->usuario('assistente'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('biEmbedUrl', null)
                ->where('faturamentoComparacao', null)
            );
    }

    public function test_url_fora_do_power_bi_nao_vai_pro_iframe(): void
    {
        config(['powerbi.embed_url' => 'https://example.com/evil']);

        $this->actingAs($this->usuario('admin'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('biEmbedUrl', null));
    }

    private function usuario(string $role, bool $comCodigo = false): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        if ($comCodigo) {
            VendedorPerfil::create([
                'user_id' => $user->id,
                'cod_vendedor' => str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            ]);
        }

        return $user;
    }

    private function vendedorComCodigo(): User
    {
        return $this->usuario('vendedor', comCodigo: true);
    }
}
