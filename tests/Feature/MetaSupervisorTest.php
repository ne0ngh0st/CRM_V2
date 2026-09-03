<?php

namespace Tests\Feature;

use App\Models\MetaMensal;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Escopo\ModoVisao;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Meta pessoal do supervisor.
 *
 * Supervisor na Autopel também vende, e tem meta própria além da meta da equipe. O
 * ranking de /metas filtrava `role(['vendedor','representante'])`, então havia R$ 9,04 mi
 * de meta gravados em códigos de supervisor que NUNCA apareciam — nem na linha, nem nos
 * totais. A meta existia na tabela e era invisível.
 */
class MetaSupervisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function supervisor(string $cod = '000006'): User
    {
        $user = User::factory()->create(['display_name' => 'CLEBER']);
        $user->assignRole('supervisor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);

        return $user;
    }

    private function vendedor(string $cod, string $codSuper = '000006', string $nome = 'VENDEDOR A'): User
    {
        $user = User::factory()->create(['display_name' => $nome]);
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod, 'cod_super' => $codSuper]);

        return $user;
    }

    private function meta(string $cod, float $valor): void
    {
        MetaMensal::create([
            'cod_vendedor' => $cod,
            'ano' => now()->year,
            'mes' => now()->month,
            'tipo' => 'faturamento',
            'valor_meta' => $valor,
        ]);
    }

    private function linhas(User $comoUsuario, array $sessao = []): array
    {
        return $this->actingAs($comoUsuario)
            ->withSession($sessao)
            ->get(route('metas.index'))
            ->assertOk()
            ->viewData('page')['props']['linhas'];
    }

    /**
     * ⚠️ Divergência INTENCIONAL em relação ao resto do sistema: na Carteira e no Painel o
     * modo Equipe é equipe PURA, mas /metas é tela de GESTÃO — a pergunta ali é "por quem
     * esta pessoa responde?", e ela responde por si mesma também.
     */
    #[Test]
    public function test_supervisor_aparece_como_linha_junto_da_equipe(): void
    {
        $supervisor = $this->supervisor();
        $this->vendedor('010617');
        $this->meta('000006', 1450000);
        $this->meta('010617', 320000);

        $codigos = collect($this->linhas($supervisor))->pluck('codVendedor');

        $this->assertContains('000006', $codigos, 'a linha do próprio supervisor tem que aparecer');
        $this->assertContains('010617', $codigos);
    }

    /** A meta dele soma no total — era isso que estava sendo perdido. */
    #[Test]
    public function test_meta_do_supervisor_entra_no_total(): void
    {
        $supervisor = $this->supervisor();
        $this->vendedor('010617');
        $this->meta('000006', 1450000);
        $this->meta('010617', 320000);

        $totais = $this->actingAs($supervisor)
            ->get(route('metas.index'))
            ->assertOk()
            ->viewData('page')['props']['totais'];

        $this->assertSame(1770000.0, (float) $totais['fatMeta']);
    }

    /** Admin também passa a enxergar as metas de supervisor, no escopo empresa. */
    #[Test]
    public function test_admin_ve_a_linha_do_supervisor(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->supervisor();
        $this->meta('000006', 1450000);

        $codigos = collect($this->linhas($admin))->pluck('codVendedor');

        $this->assertContains('000006', $codigos);
    }

    /** Em "Minha carteira" o ranking é só ele — que é o que o modo promete. */
    #[Test]
    public function test_modo_pessoal_mostra_so_a_linha_do_supervisor(): void
    {
        $supervisor = $this->supervisor();
        $this->vendedor('010617');
        $this->meta('000006', 1450000);
        $this->meta('010617', 320000);

        $codigos = collect($this->linhas($supervisor, [ModoVisao::CHAVE_SESSAO => ModoVisao::PESSOAL]))
            ->pluck('codVendedor');

        $this->assertSame(['000006'], $codigos->all());
    }

    /**
     * ⚠️ Decisão do Tony: quem atribui meta a supervisor é admin/diretor. O supervisor vê
     * a própria linha mas não a edita — senão ele definiria o próprio compromisso.
     */
    #[Test]
    public function test_supervisor_nao_edita_a_propria_meta(): void
    {
        $supervisor = $this->supervisor();

        $this->actingAs($supervisor)->patch(route('metas.update'), [
            'cod_vendedor' => '000006',
            'ano' => now()->year,
            'mes' => now()->month,
            'meta_faturamento' => 999,
            'meta_venda' => 999,
        ])->assertForbidden();
    }

    /** Mas continua editando a meta de quem é da equipe dele. */
    #[Test]
    public function test_supervisor_edita_meta_da_equipe(): void
    {
        $supervisor = $this->supervisor();
        $this->vendedor('010617');

        $this->actingAs($supervisor)->patch(route('metas.update'), [
            'cod_vendedor' => '010617',
            'ano' => now()->year,
            'mes' => now()->month,
            'meta_faturamento' => 500000,
            'meta_venda' => 400000,
        ])->assertRedirect();

        $this->assertDatabaseHas('metas_mensais', [
            'cod_vendedor' => '010617',
            'tipo' => 'faturamento',
            'valor_meta' => 500000,
        ]);
    }

    #[Test]
    public function test_admin_edita_a_meta_do_supervisor(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->supervisor();

        $this->actingAs($admin)->patch(route('metas.update'), [
            'cod_vendedor' => '000006',
            'ano' => now()->year,
            'mes' => now()->month,
            'meta_faturamento' => 1450000,
            'meta_venda' => 1200000,
        ])->assertRedirect();

        $this->assertDatabaseHas('metas_mensais', [
            'cod_vendedor' => '000006',
            'tipo' => 'venda',
            'valor_meta' => 1200000,
        ]);
    }
}
