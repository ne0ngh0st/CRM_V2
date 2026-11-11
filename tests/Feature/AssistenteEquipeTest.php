<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\SolicitacaoBobina;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Escopo\ModoVisao;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assistente com o alternador do supervisor e cadastrando pela equipe (2026-11-04).
 *
 * Montagem: Sandra (supervisora, 000115), Ana Clara (assistente, 000115 / cod_super
 * 000115, como está em produção), Alessandra (vendedora da Sandra) e Bruno (vendedor de
 * outra equipe).
 */
class AssistenteEquipeTest extends TestCase
{
    use RefreshDatabase;

    private const COD_SANDRA = '000115';

    private User $sandra;

    private User $ana;

    private User $alessandra;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->sandra = $this->usuario('supervisor', 'SANDRA', self::COD_SANDRA, '010395');
        $this->alessandra = $this->usuario('vendedor', 'ALESSANDRA', '010586', self::COD_SANDRA);
        $this->bruno = $this->usuario('vendedor', 'BRUNO', '010999', '000006');
        $this->ana = $this->usuario('assistente', 'ANA CLARA', self::COD_SANDRA, self::COD_SANDRA);

        Cliente::create(['cod_cliente' => '100', 'loja' => '0001', 'razao_social' => 'DA SANDRA', 'cod_vendedor' => self::COD_SANDRA]);
        Cliente::create(['cod_cliente' => '200', 'loja' => '0001', 'razao_social' => 'DA ALESSANDRA', 'cod_vendedor' => '010586']);
        Cliente::create(['cod_cliente' => '300', 'loja' => '0001', 'razao_social' => 'DO BRUNO', 'cod_vendedor' => '010999']);
    }

    private function usuario(string $perfil, string $nome, string $codigo, ?string $codSuper): User
    {
        $user = User::factory()->create(['name' => $nome, 'display_name' => $nome, 'is_active' => true]);
        $user->assignRole($perfil);
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codigo, 'cod_super' => $codSuper]);

        return $user;
    }

    /** @return list<string> */
    private function carteira(User $user, array $query = []): array
    {
        return collect($this->actingAs($user)->get(route('carteira.index', $query))->assertOk()
            ->viewData('page')['props']['clientes']['data'])->pluck('razaoSocial')->sort()->values()->all();
    }

    public function test_assistente_alterna_entre_equipe_e_minha_carteira(): void
    {
        $this->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::EQUIPE]);
        // Equipe PURA, igual ao supervisor: sem a carteira pessoal da Sandra.
        $this->assertSame(['DA ALESSANDRA'], $this->carteira($this->ana));

        $this->actingAs($this->ana)->post(route('visao.alternar'), ['modo' => ModoVisao::PESSOAL])->assertRedirect();
        $this->assertSame(['DA SANDRA'], $this->carteira($this->ana));
    }

    public function test_assistente_filtra_um_vendedor_da_equipe_e_nao_de_outra(): void
    {
        $this->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::EQUIPE]);

        $this->assertSame(['DA ALESSANDRA'], $this->carteira($this->ana, ['visao_vendedor' => '010586']));
        // Vendedor de fora da equipe é ignorado: volta a equipe, nunca a carteira dele.
        $this->assertNotContains('DO BRUNO', $this->carteira($this->ana, ['visao_vendedor' => '010999']));
    }

    public function test_o_alternador_aparece_para_o_assistente(): void
    {
        $props = $this->actingAs($this->ana)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['modoVisao']['disponivel']);
        $this->assertSame(['010586'], collect($props['visao']['vendedores'])->pluck('cod_vendedor')->all());
    }

    public function test_assistente_entra_em_minha_carteira_e_o_supervisor_em_equipe(): void
    {
        $this->ana->forceFill(['password' => bcrypt('senha-teste-123')])->save();

        $this->post(route('login'), ['email' => $this->ana->email, 'password' => 'senha-teste-123']);

        $this->assertSame(ModoVisao::PESSOAL, session(ModoVisao::CHAVE_SESSAO));
    }

    public function test_a_assistente_nao_entra_na_equipe_pura_do_supervisor(): void
    {
        // Ana tem cod_super = 000115 (o próprio código da Sandra). Sem `titulares()` a
        // carteira pessoal da Sandra apareceria no modo Equipe dela.
        $this->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::EQUIPE]);

        $this->assertSame(['DA ALESSANDRA'], $this->carteira($this->sandra));
    }

    private function bobina(array $extra = []): array
    {
        return ['nomenclatura' => 'BOBINA TESTE', 'papel' => 'offset', 'largura' => '80', 'metragem' => '40', ...$extra];
    }

    public function test_assistente_pede_cadastro_em_nome_de_vendedor_da_equipe(): void
    {
        $this->actingAs($this->ana)
            ->post(route('cadastros.bobinas.store'), $this->bobina(['em_nome_de' => $this->alessandra->id]))
            ->assertSessionHasNoErrors();

        $s = SolicitacaoBobina::query()->sole();
        $this->assertSame($this->alessandra->id, $s->user_id);
        $this->assertSame('010586', $s->cod_vendedor);
        $this->assertSame('ALESSANDRA', $s->solicitante_nome);

        // E a assistente continua vendo a solicitação na lista dela.
        $lista = $this->actingAs($this->ana)->get(route('cadastros.index', ['aba' => 'bobinas']))
            ->viewData('page')['props']['bobinas']['data'];
        $this->assertCount(1, $lista);
    }

    public function test_nao_pede_em_nome_de_quem_esta_fora_da_equipe(): void
    {
        $this->actingAs($this->ana)
            ->post(route('cadastros.bobinas.store'), $this->bobina(['em_nome_de' => $this->bruno->id]))
            ->assertSessionHasErrors('em_nome_de');

        $this->assertSame(0, SolicitacaoBobina::query()->count());
    }

    public function test_vendedor_nao_pede_em_nome_de_ninguem(): void
    {
        $this->actingAs($this->alessandra)
            ->post(route('cadastros.bobinas.store'), $this->bobina(['em_nome_de' => $this->ana->id]))
            ->assertSessionHasErrors('em_nome_de');

        $props = $this->actingAs($this->alessandra)->get(route('cadastros.index'))->viewData('page')['props'];
        $this->assertSame([], $props['solicitantes']);
    }

    public function test_a_lista_de_solicitantes_da_assistente_e_a_equipe(): void
    {
        $props = $this->actingAs($this->ana)->get(route('cadastros.index'))->viewData('page')['props'];

        $this->assertSame(['ANA CLARA', 'ALESSANDRA', 'SANDRA'], collect($props['solicitantes'])->pluck('nome')->all());
    }
}
