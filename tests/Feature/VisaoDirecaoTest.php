<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Escopo\ModoVisao;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtro de visão em três níveis (Direção → Supervisão → Vendedor) e o alternador
 * "Empresa / Minha carteira" de admin e diretor (2026-11-12).
 *
 * A Direção é o GERENTE do TOTVS (`cod_gerente`); a Supervisão é o `cod_super`. As duas
 * hierarquias não coincidem, e a montagem reproduz isso de propósito:
 *
 *   nome     perfil         código   supervisor   gerente
 *   PAULO    diretor        010395   —            —
 *   BAROLI   diretor        010002   —            —
 *   DANIEL   diretor        000054   —            —        (inativo: só dá nome ao código)
 *   AMERICO  supervisor     010389   PAULO        DANIEL
 *   CLEBER   supervisor     000006   BAROLI       DANIEL
 *   JUDE     supervisor     000197   PAULO        —        (sem equipe)
 *   ALICE    vendedor       010001   AMERICO      PAULO
 *   DIRETO   vendedor       010003   PAULO        PAULO
 *   BRUNO    representante  020002   CLEBER       BAROLI
 *
 * Cada código tem UM cliente com o nome do dono, então a lista da Carteira diz
 * exatamente quais códigos entraram no escopo.
 */
class VisaoDirecaoTest extends TestCase
{
    use RefreshDatabase;

    private const PAULO = '010395';

    private const BAROLI = '010002';

    private const DANIEL = '000054';

    private const AMERICO = '010389';

    private const CLEBER = '000006';

    private const JUDE = '000197';

    private const TODOS = 9;

    private User $admin;

    private User $paulo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->paulo = $this->usuario('diretor', 'PAULO', self::PAULO);
        $this->usuario('diretor', 'BAROLI', self::BAROLI);
        $this->usuario('diretor', 'DANIEL', self::DANIEL, ativo: false);
        $this->usuario('supervisor', 'AMERICO', self::AMERICO, self::PAULO, self::DANIEL);
        $this->usuario('supervisor', 'CLEBER', self::CLEBER, self::BAROLI, self::DANIEL);
        $this->usuario('supervisor', 'JUDE', self::JUDE, self::PAULO);
        $this->usuario('vendedor', 'ALICE', '010001', self::AMERICO, self::PAULO);
        $this->usuario('vendedor', 'DIRETO', '010003', self::PAULO, self::PAULO);
        $this->usuario('representante', 'BRUNO', '020002', self::CLEBER, self::BAROLI);

        // Admin sem código: enxerga a empresa e não encabeça estrutura nenhuma.
        $this->admin = User::factory()->create(['name' => 'ADMIN', 'display_name' => 'ADMIN', 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    private function usuario(string $perfil, string $nome, string $codigo, ?string $codSuper = null, ?string $codGerente = null, bool $ativo = true): User
    {
        $user = User::factory()->create(['name' => $nome, 'display_name' => $nome, 'is_active' => $ativo]);
        $user->assignRole($perfil);
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codigo, 'cod_super' => $codSuper, 'cod_gerente' => $codGerente]);
        Cliente::create(['cod_cliente' => $codigo, 'loja' => '0001', 'razao_social' => $nome, 'cod_vendedor' => $codigo]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function props(User $user, array $query = []): array
    {
        return $this->actingAs($user)->get(route('carteira.index', $query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> Donos dos clientes listados — ou seja, os códigos do escopo. */
    private function carteira(User $user, array $query = []): array
    {
        return collect($this->props($user, $query)['clientes']['data'])->pluck('razaoSocial')->sort()->values()->all();
    }

    /** @return list<string> */
    private function nomes(array $opcoes): array
    {
        return collect($opcoes)->pluck('nome')->all();
    }

    public function test_direcao_lista_os_gerentes_do_totvs(): void
    {
        // DANIEL não supervisiona ninguém e está inativo: entra por ser gerente de alguém.
        $this->assertSame(['BAROLI', 'DANIEL', 'PAULO'], $this->nomes($this->props($this->admin)['visao']['diretores']));
    }

    public function test_supervisao_lista_supervisores_e_os_diretores_que_supervisionam(): void
    {
        // PAULO e BAROLI também são supervisores de alguém; JUDE entra mesmo sem equipe;
        // DANIEL não, porque ninguém tem ele como supervisor.
        $this->assertSame(
            ['AMERICO', 'BAROLI', 'CLEBER', 'JUDE', 'PAULO'],
            $this->nomes($this->props($this->admin)['visao']['supervisores']),
        );
    }

    public function test_o_seletor_de_vendedor_lista_todo_mundo_que_tem_carteira(): void
    {
        // Era a queixa: diretor e supervisor também vendem e não apareciam em filtro nenhum.
        $this->assertSame(
            ['ALICE', 'AMERICO', 'BAROLI', 'BRUNO', 'CLEBER', 'DANIEL', 'DIRETO', 'JUDE', 'PAULO'],
            $this->nomes($this->props($this->admin)['visao']['vendedores']),
        );
    }

    public function test_quem_saiu_nao_vira_nome_no_seletor_mas_a_carteira_continua_no_escopo(): void
    {
        $this->usuario('vendedor', 'SAIU', '010009', self::AMERICO, self::PAULO, ativo: false);

        $this->assertNotContains('SAIU', $this->nomes($this->props($this->admin)['visao']['vendedores']));
        $this->assertContains('SAIU', $this->carteira($this->admin, ['visao_diretor' => self::PAULO]));
        $this->assertContains('SAIU', $this->carteira($this->admin, ['visao_supervisor' => self::AMERICO]));
    }

    public function test_escolher_um_diretor_traz_ele_e_quem_tem_ele_como_gerente(): void
    {
        $this->assertSame(['ALICE', 'DIRETO', 'PAULO'], $this->carteira($this->admin, ['visao_diretor' => self::PAULO]));

        // ⚠️ É o gerente, não a cadeia de supervisão: AMERICO responde ao PAULO como
        // supervisor, mas o gerente dele é o DANIEL — e é na diretoria do DANIEL que ele cai.
        $this->assertSame(['AMERICO', 'CLEBER', 'DANIEL'], $this->carteira($this->admin, ['visao_diretor' => self::DANIEL]));
    }

    public function test_com_diretor_escolhido_os_outros_seletores_se_recortam(): void
    {
        $visao = $this->props($this->admin, ['visao_diretor' => self::DANIEL])['visao'];

        $this->assertSame(self::DANIEL, $visao['visaoDiretor']);
        $this->assertSame(['AMERICO', 'CLEBER'], $this->nomes($visao['supervisores']));
        $this->assertSame(['AMERICO', 'CLEBER', 'DANIEL'], $this->nomes($visao['vendedores']));
    }

    public function test_supervisor_dentro_do_diretor_continua_sendo_equipe_pura(): void
    {
        $query = ['visao_diretor' => self::DANIEL, 'visao_supervisor' => self::AMERICO];

        $this->assertSame(['ALICE'], $this->carteira($this->admin, $query));
        // O próprio supervisor é escolhível no seletor de vendedor da equipe dele.
        $this->assertSame(['ALICE', 'AMERICO'], $this->nomes($this->props($this->admin, $query)['visao']['vendedores']));
        $this->assertSame(['AMERICO'], $this->carteira($this->admin, $query + ['visao_vendedor' => self::AMERICO]));
    }

    public function test_diretor_escolhido_como_supervisor_traz_so_os_diretos_dele(): void
    {
        // Equipe pura, como a de qualquer supervisor: sem o código dele e sem descer à equipe do Américo.
        $this->assertSame(['AMERICO', 'DIRETO', 'JUDE'], $this->carteira($this->admin, ['visao_supervisor' => self::PAULO]));
    }

    public function test_supervisor_sem_equipe_mostra_a_propria_carteira(): void
    {
        $this->assertSame(['JUDE'], $this->carteira($this->admin, ['visao_supervisor' => self::JUDE]));
    }

    public function test_supervisor_e_vendedor_de_outra_diretoria_sao_ignorados(): void
    {
        $estrutura = ['ALICE', 'DIRETO', 'PAULO'];

        // Vêm da URL: quem não está na diretoria escolhida não estreita nem troca o escopo.
        $this->assertSame($estrutura, $this->carteira($this->admin, ['visao_diretor' => self::PAULO, 'visao_supervisor' => self::CLEBER]));
        $this->assertSame($estrutura, $this->carteira($this->admin, ['visao_diretor' => self::PAULO, 'visao_vendedor' => '020002']));
    }

    public function test_diretor_que_nao_existe_e_ignorado(): void
    {
        $props = $this->props($this->admin, ['visao_diretor' => '999999']);

        $this->assertNull($props['visao']['visaoDiretor']);
        $this->assertCount(self::TODOS, $props['clientes']['data']);
    }

    public function test_o_filtro_de_direcao_nao_vale_para_supervisor(): void
    {
        $americo = User::where('name', 'AMERICO')->first();
        $this->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::EQUIPE]);

        // Porta lateral: o parâmetro na URL não pode abrir a diretoria alheia.
        $this->assertSame(['ALICE'], $this->carteira($americo, ['visao_diretor' => self::BAROLI]));
        $this->assertSame([], $this->props($americo)['visao']['diretores']);
    }

    public function test_diretor_alterna_entre_empresa_e_minha_carteira(): void
    {
        $this->assertCount(self::TODOS, $this->carteira($this->paulo));

        $this->actingAs($this->paulo)->post(route('visao.alternar'), ['modo' => ModoVisao::PESSOAL])->assertRedirect();

        $props = $this->props($this->paulo, ['visao_diretor' => self::BAROLI]);
        $this->assertSame(['PAULO'], collect($props['clientes']['data'])->pluck('razaoSocial')->all());
        // Em "Minha carteira" os seletores não teriam efeito, então somem.
        $this->assertFalse($props['visao']['mostrarSeletor']);
        $this->assertSame([], $props['visao']['diretores']);
        $this->assertTrue($props['modoVisao']['disponivel']);
        $this->assertTrue($props['modoVisao']['empresa']);

        $this->actingAs($this->paulo)->post(route('visao.alternar'), ['modo' => ModoVisao::EQUIPE])->assertRedirect();
        $this->assertCount(self::TODOS, $this->carteira($this->paulo));
    }

    public function test_admin_sem_codigo_nao_entra_em_minha_carteira(): void
    {
        $this->actingAs($this->admin)->post(route('visao.alternar'), ['modo' => ModoVisao::PESSOAL])->assertStatus(422);
        $this->assertCount(self::TODOS, $this->carteira($this->admin));
    }

    public function test_vendedor_continua_sem_alternador(): void
    {
        $alice = User::where('name', 'ALICE')->first();

        $this->actingAs($alice)->post(route('visao.alternar'), ['modo' => ModoVisao::PESSOAL])->assertForbidden();
        $this->assertFalse($this->props($alice)['modoVisao']['disponivel']);
    }

    public function test_metas_e_visao_do_gestor_aceitam_o_filtro_de_direcao(): void
    {
        $metas = $this->actingAs($this->admin)->get(route('metas.index', ['visao_diretor' => self::DANIEL]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(self::DANIEL, $metas['filtros']['visao_diretor']);
        $this->assertSame(['BAROLI', 'DANIEL', 'PAULO'], $this->nomes($metas['opcoes']['diretores']));
        $this->assertSame(['AMERICO', 'CLEBER'], $this->nomes($metas['opcoes']['supervisores']));

        $gestor = $this->actingAs($this->admin)->get(route('visao-gestor.index', ['visao_diretor' => self::DANIEL]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(self::DANIEL, $gestor['filtros']['visao_diretor']);
        $this->assertSame(['AMERICO', 'CLEBER'], $this->nomes($gestor['opcoes']['supervisores']));
    }
}
