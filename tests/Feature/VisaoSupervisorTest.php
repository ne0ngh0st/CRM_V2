<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Escopo\ModoVisao;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Supervisor também é vendedor — alternador "Equipe / Minha carteira".
 *
 * Na Autopel o supervisor atende clientes diretamente. O sistema resolvia o escopo dele
 * como apenas a equipe, então 9.387 clientes (10,2% da base) que são carteira PESSOAL de
 * supervisor não eram vistos por ninguém nesse perfil — e o supervisor de equipe vazia
 * via tela em branco no sistema inteiro.
 */
class VisaoSupervisorTest extends TestCase
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

    private function vendedorDaEquipe(string $cod, string $codSuper = '000006'): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod, 'cod_super' => $codSuper]);

        return $user;
    }

    private function cliente(string $codVendedor, string $razao): Cliente
    {
        return Cliente::create([
            'cod_cliente' => (string) fake()->unique()->numberBetween(1000, 99999),
            'loja' => '01',
            'razao_social' => $razao,
            'cod_vendedor' => $codVendedor,
        ]);
    }

    /** Um supervisor com equipe e com carteira própria — o caso real do CLEBER. */
    private function cenario(): User
    {
        $supervisor = $this->supervisor('000006');
        $this->vendedorDaEquipe('010617');

        $this->cliente('000006', 'CLIENTE PESSOAL DO SUPERVISOR');
        $this->cliente('010617', 'CLIENTE DA EQUIPE');

        return $supervisor;
    }

    // ------------------------------------------------------------------ o escopo

    /**
     * Modo Equipe é equipe PURA (decisão do Tony): a carteira pessoal do supervisor não
     * se mistura com a da equipe.
     */
    #[Test]
    public function test_modo_equipe_mostra_so_a_equipe(): void
    {
        $supervisor = $this->cenario();

        $resposta = $this->actingAs($supervisor)->get(route('carteira.index'))->assertOk();
        $razoes = collect($resposta->viewData('page')['props']['clientes']['data'])->pluck('razaoSocial');

        $this->assertContains('CLIENTE DA EQUIPE', $razoes);
        $this->assertNotContains('CLIENTE PESSOAL DO SUPERVISOR', $razoes);
    }

    #[Test]
    public function test_modo_pessoal_mostra_so_a_carteira_propria(): void
    {
        $supervisor = $this->cenario();

        $resposta = $this->actingAs($supervisor)
            ->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::PESSOAL])
            ->get(route('carteira.index'))
            ->assertOk();

        $razoes = collect($resposta->viewData('page')['props']['clientes']['data'])->pluck('razaoSocial');

        $this->assertContains('CLIENTE PESSOAL DO SUPERVISOR', $razoes);
        $this->assertNotContains('CLIENTE DA EQUIPE', $razoes);
    }

    /**
     * ⚠️ O alternador tem que valer no sistema INTEIRO, não numa tela. É a diferença
     * entre este desenho e o do legado, onde `supervisor_apenas_proprios` foi threaded à
     * mão por ~15 arquivos e ficou inconsistente entre telas.
     *
     * Os filtros na query string continuam funcionando porque o escopo deriva do usuário
     * e do modo, nunca da URL.
     */
    #[Test]
    #[DataProvider('telasComEscopo')]
    public function test_modo_pessoal_vale_em_todas_as_telas(string $rota, array $params): void
    {
        $supervisor = $this->cenario();

        $this->actingAs($supervisor)
            ->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::PESSOAL])
            ->get(route($rota, $params))
            ->assertOk();
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function telasComEscopo(): array
    {
        return [
            'painel' => ['dashboard', []],
            'carteira com filtro' => ['carteira.index', ['estado' => 'SP', 'ordenar' => 'nome_asc']],
            'leads com filtro' => ['leads.index', ['origem' => 'sistema']],
            'pedidos abertos' => ['pedidos.index', []],
            'pedidos emitidos' => ['pedidos.emitidos', []],
            'orcamentos' => ['orcamentos.index', []],
            'metas' => ['metas.index', []],
        ];
    }

    // ------------------------------------------------------------------ o alternador

    #[Test]
    public function test_supervisor_alterna_o_modo(): void
    {
        $supervisor = $this->cenario();

        $this->actingAs($supervisor)
            ->from(route('carteira.index'))
            ->post(route('visao.alternar'), ['modo' => ModoVisao::PESSOAL])
            ->assertRedirect(route('carteira.index'))
            ->assertSessionHas(ModoVisao::CHAVE_SESSAO, ModoVisao::PESSOAL);
    }

    #[Test]
    public function test_modo_invalido_e_recusado(): void
    {
        $this->actingAs($this->cenario())
            ->postJson(route('visao.alternar'), ['modo' => 'qualquer_coisa'])
            ->assertStatus(422);
    }

    /** Vendedor já é pessoal por definição; admin/diretor têm os dropdowns de visão. */
    #[Test]
    public function test_quem_nao_e_supervisor_nao_alterna(): void
    {
        $vendedor = $this->vendedorDaEquipe('010617');

        $this->actingAs($vendedor)
            ->post(route('visao.alternar'), ['modo' => ModoVisao::PESSOAL])
            ->assertForbidden();
    }

    /** Sem código de vendedor não existe carteira pessoal — o modo resolveria vazio. */
    #[Test]
    public function test_supervisor_sem_codigo_nao_alterna(): void
    {
        $user = User::factory()->create();
        $user->assignRole('supervisor');

        $this->actingAs($user)
            ->post(route('visao.alternar'), ['modo' => ModoVisao::PESSOAL])
            ->assertStatus(422);
    }

    /**
     * ⚠️ O compromisso central deste desenho, e o mesmo que a simulação de usuário assumiu:
     * o alternador NÃO pode custar uma query por request. A prop vem da sessão, que já é
     * carregada de qualquer forma. Se alguém trocar isto por uma consulta ao banco — por
     * exemplo para checar `vendedorPerfil` antes de desenhar o botão —, vira uma query a
     * mais em TODA página de TODO usuário (Regra de ouro nº 9), e este teste é o que avisa.
     */
    #[Test]
    public function test_alternador_nao_adiciona_query_por_request(): void
    {
        $supervisor = $this->cenario();

        $medir = function (array $sessao) use ($supervisor): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($supervisor)->withSession($sessao)->get(route('carteira.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // ⚠️ Aquece primeiro. A Carteira usa cache de agregação, e a PRIMEIRA requisição
        // paga as queries que as seguintes não pagam — sem este passo a comparação mede a
        // temperatura do cache, não o custo do alternador. (A primeira versão deste teste
        // comparava 19 com 7 e "acusava" um problema que não existia.)
        $medir([]);

        $semChaveNaSessao = $medir([]);
        $comAlternador = $medir([ModoVisao::CHAVE_SESSAO => ModoVisao::EQUIPE]);

        $this->assertSame(
            $semChaveNaSessao,
            $comAlternador,
            'ler o modo da sessão não pode custar query nenhuma',
        );
    }

    /**
     * ⚠️ A prop do alternador chega às telas com o nome `modoVisao`, e o nome NÃO é
     * detalhe: seis páginas (Carteira, Painel, Leads, Orçamentos e as duas de Pedidos) já
     * mandam uma prop de PÁGINA chamada `visao`, com os dropdowns de supervisor/vendedor.
     * No Inertia a prop de página sobrescreve a compartilhada — com o nome colidindo, o
     * botão simplesmente não aparecia nessas telas, sem erro nenhum.
     *
     * Nenhum teste de escopo pegaria isso: o servidor resolvia o escopo certo, quem sumia
     * era o botão. Foi encontrado abrindo a Carteira no navegador; este teste existe para
     * que não volte.
     */
    #[Test]
    #[DataProvider('telasComEscopo')]
    public function test_prop_do_alternador_sobrevive_nas_telas_que_ja_usam_visao(string $rota, array $params): void
    {
        $supervisor = $this->cenario();

        $props = $this->actingAs($supervisor)
            ->get(route($rota, $params))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayHasKey('modoVisao', $props);
        $this->assertTrue($props['modoVisao']['disponivel'], "o alternador tem que chegar em {$rota}");
        $this->assertSame(ModoVisao::EQUIPE, $props['modoVisao']['modo']);
    }

    /** Quem não é supervisor recebe a prop, mas com o alternador desligado. */
    #[Test]
    public function test_vendedor_nao_recebe_o_alternador(): void
    {
        $vendedor = $this->vendedorDaEquipe('010617');

        $props = $this->actingAs($vendedor)->get(route('carteira.index'))->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['modoVisao']['disponivel']);
    }

    // ------------------------------------------------------------------ o caso ROBERTO

    /**
     * ⚠️ CASO REAL EM PRODUÇÃO. ROBERTO (000197) é supervisor com equipe VAZIA: o escopo
     * resolve `[]`, e `whereIn([])` devolve zero linhas — ele via tela em branco no sistema
     * inteiro, apesar de ter 1.649 clientes no próprio nome. O modo pessoal é o que devolve
     * a carteira dele.
     */
    #[Test]
    public function test_supervisor_sem_equipe_enxerga_a_propria_carteira(): void
    {
        $roberto = $this->supervisor('000197');
        $this->cliente('000197', 'CLIENTE DO ROBERTO');

        $equipe = $this->actingAs($roberto)->get(route('carteira.index'))->assertOk();
        $this->assertCount(0, $equipe->viewData('page')['props']['clientes']['data'], 'equipe vazia continua vazia');

        $pessoal = $this->actingAs($roberto)
            ->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::PESSOAL])
            ->get(route('carteira.index'))
            ->assertOk();

        $razoes = collect($pessoal->viewData('page')['props']['clientes']['data'])->pluck('razaoSocial');
        $this->assertContains('CLIENTE DO ROBERTO', $razoes);
    }

    // ------------------------------------------------------------------ fila e console

    /**
     * ⚠️ Fora de contexto HTTP não há sessão — fila, `cache:aquecer`, comandos. O modo tem
     * que ser sempre EQUIPE ali: o job de aquecimento monta chaves de cache a partir do
     * escopo resolvido, e se "herdasse" um modo pessoal aqueceria uma chave que nenhuma
     * requisição procura.
     */
    #[Test]
    public function test_fora_de_requisicao_o_modo_e_sempre_equipe(): void
    {
        session([ModoVisao::CHAVE_SESSAO => ModoVisao::PESSOAL]);
        $this->assertTrue(app(ModoVisao::class)->pessoal());

        session()->forget(ModoVisao::CHAVE_SESSAO);
        $this->assertSame(ModoVisao::EQUIPE, app(ModoVisao::class)->atual());
    }
}
