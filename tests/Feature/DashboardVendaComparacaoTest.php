<?php

namespace Tests\Feature;

use App\Models\Faturamento;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A aba VENDA do card de comparação do painel.
 *
 * Venda (pedido emitido) é o que o vendedor consegue influenciar hoje; faturamento é
 * consequência e chega depois. Por isso venda virou a aba padrão.
 */
class DashboardVendaComparacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(string $cod = '010617'): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);

        return $user;
    }

    private function pedido(string $data, float $valor, string $cod = '010617'): void
    {
        Pedido::create([
            'numero_pedido' => 'P'.fake()->unique()->numberBetween(1, 999999),
            'cod_vendedor' => $cod,
            'data_pedido' => $data,
            'valor_total' => $valor,
            'status' => 'faturado',
        ]);
    }

    #[Test]
    public function test_vendedor_recebe_a_aba_venda_no_painel(): void
    {
        $this->pedido(now()->startOfYear()->addMonths(1)->toDateString(), 1000);

        $props = $this->actingAs($this->vendedor())
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNotNull($props['vendaComparacao']);
        $this->assertSame('venda', $props['vendaComparacao']['metrica']);
        $this->assertSame((int) now()->year, $props['vendaComparacao']['anoAtual']);
    }

    /** Os 12 meses sempre vêm preenchidos — mês sem pedido é zero, não buraco no gráfico. */
    #[Test]
    public function test_serie_tem_sempre_doze_meses(): void
    {
        $bloco = app(DashboardBlocos::class);
        $dados = $bloco->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        $this->assertCount(12, $dados['valoresAnoAtual']);
        $this->assertCount(12, $dados['valoresAnoAnterior']);
        $this->assertSame(array_fill(0, 12, 0.0), $dados['valoresAnoAtual']);
    }

    #[Test]
    public function test_soma_por_mes_respeita_o_escopo_do_vendedor(): void
    {
        $fevereiro = now()->startOfYear()->addMonth();
        $this->pedido($fevereiro->toDateString(), 1000, '010617');
        $this->pedido($fevereiro->toDateString(), 500, '010617');
        $this->pedido($fevereiro->toDateString(), 9999, '999999');

        $dados = app(DashboardBlocos::class)
            ->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        // Índice 1 = fevereiro.
        $this->assertSame(1500.0, $dados['valoresAnoAtual'][1]);
    }

    /**
     * ⚠️ O teste mais importante deste arquivo. Venda e faturamento têm o MESMO formato de
     * retorno, então uma chave de cache compartilhada não causaria erro nenhum — só
     * entregaria o número errado, na aba errada, silenciosamente. É o pior tipo de bug de
     * cache: nada quebra, o valor só fica mentiroso.
     */
    #[Test]
    public function test_venda_e_faturamento_nao_dividem_a_chave_de_cache(): void
    {
        $fevereiro = now()->startOfYear()->addMonth();
        $this->pedido($fevereiro->toDateString(), 1000, '010617');

        Faturamento::create([
            'nota_fiscal' => '123',
            'data_emissao' => $fevereiro->toDateString(),
            'cod_vendedor' => '010617',
            'valor_total' => 7777,
            'quantidade' => 1,
            'valor_unitario' => 7777,
        ]);

        $bloco = app(DashboardBlocos::class);
        $escopo = ChaveEscopo::deCodVendedores(['010617']);

        $venda = $bloco->vendaComparacao($escopo, ['010617']);
        $faturamento = $bloco->faturamentoComparacao($escopo, ['010617']);

        $this->assertSame(1000.0, $venda['valoresAnoAtual'][1], 'a aba Venda tem que somar pedidos');
        $this->assertSame(7777.0, $faturamento['valoresAnoAtual'][1], 'a aba Faturamento tem que somar faturamento');
        $this->assertSame('venda', $venda['metrica']);
        $this->assertSame('faturamento', $faturamento['metrica']);
    }

    /**
     * ⚠️ Trava de regressão de um bug que viveu escondido: `somaMensal()` chamava
     * `intervaloDatas($ano, 1, 12)`, e para o ANO CORRENTE `fimRealizado($ano, 12)` cai no
     * ramo "mês futuro" e devolve 30/11 — a série não era cortada em D-1, ao contrário do
     * que o docblock do método afirmava. Não aparecia porque não há linha datada depois de
     * hoje enquanto a sincronização está atrasada.
     *
     * Passou a importar quando o subtotal desta série substituiu o "acumulado do ano" do
     * card de Performance Comercial: com janelas diferentes, um pedido lançado hoje entra
     * num número e não no outro, lado a lado na mesma tela.
     *
     * A data é congelada num dia 16 justamente para o teste valer tanto no corte D-1
     * quanto no D-3 de segunda-feira: 01/09 entra nos dois, 16/09 fica fora nos dois.
     */
    #[Test]
    public function test_serie_do_ano_corrente_para_no_corte_d1(): void
    {
        $this->travelTo('2026-09-16 10:00:00');

        $this->pedido('2026-09-01', 1000);
        $this->pedido('2026-09-16', 7777);

        $dados = app(DashboardBlocos::class)
            ->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        // Índice 8 = setembro.
        $this->assertSame(
            1000.0,
            $dados['valoresAnoAtual'][8],
            'pedido datado de hoje não pode entrar na série: a janela é D-1',
        );
    }

    /**
     * ⚠️ A invariante que AUTORIZA ter tirado o "acumulado do ano" do card de Performance
     * Comercial. O card agora mostra só o percentual da meta anual; o valor realizado
     * passou a ser o subtotal desta tabela. Se as duas janelas divergirem, a Home volta a
     * mostrar dois números diferentes para a mesma coisa — e ninguém liga um ao outro.
     */
    #[Test]
    public function test_acumulado_da_serie_bate_com_o_realizado_do_ano_do_gauge(): void
    {
        $this->travelTo('2026-09-16 10:00:00');

        $this->pedido('2026-02-10', 1000);
        $this->pedido('2026-07-05', 2500);
        $this->pedido('2026-09-01', 400);
        // Fora da janela D-1 nos dois lados — tem que ficar fora dos DOIS números.
        $this->pedido('2026-09-16', 9999);

        $bloco = app(DashboardBlocos::class);
        $escopo = ChaveEscopo::deCodVendedores(['010617']);

        $serie = $bloco->vendaComparacao($escopo, ['010617']);
        $gauge = $bloco->metaGauge($escopo, ['010617']);

        $this->assertSame(3900.0, array_sum($serie['valoresAnoAtual']));
        $this->assertSame(
            $gauge['venda']['ano']['realizado'],
            array_sum($serie['valoresAnoAtual']),
            'o subtotal da tabela e o acumulado do ano do gauge têm que ser o mesmo número',
        );
    }

    /**
     * ⚠️ A INVARIANTE que amarra as duas abas do card: o total da aba Dia (soma dos dias
     * do mês corrente) tem que ser IGUAL ao valor do mês corrente na aba Mês.
     *
     * As duas séries saem de queries diferentes — `somaDiaria()` e `somaMensal()` — sobre
     * a mesma tabela. Se as janelas divergirem, o mesmo card mostra dois números para o
     * mesmo mês, um em cada aba, e ninguém liga um ao outro.
     */
    #[Test]
    public function test_soma_dos_dias_bate_com_o_mes_corrente_da_aba_mes(): void
    {
        $this->travelTo('2026-09-16 10:00:00');

        $this->pedido('2026-09-01', 1000);
        $this->pedido('2026-09-02', 500);
        $this->pedido('2026-09-10', 250);
        $this->pedido('2026-08-20', 9999); // outro mês: não entra em nenhuma das duas

        $dados = app(DashboardBlocos::class)
            ->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        // Índice 8 = setembro.
        $this->assertSame(1750.0, $dados['valoresAnoAtual'][8]);
        $this->assertSame(1750.0, array_sum($dados['dias']));
        $this->assertSame(9, $dados['mesCorrente']);
    }

    /**
     * ⚠️ Mesmo corte D-1 da série mensal. Sem isto a aba Dia mostraria o pedido de hoje e
     * a aba Mês não — o card se contradiria sozinho.
     */
    #[Test]
    public function test_serie_diaria_para_no_corte_d1(): void
    {
        $this->travelTo('2026-09-16 10:00:00');

        $this->pedido('2026-09-01', 1000);
        $this->pedido('2026-09-16', 7777);

        $dados = app(DashboardBlocos::class)
            ->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        $this->assertSame(1000.0, $dados['dias'][0], 'dia 1 entra');
        $this->assertSame(0.0, $dados['dias'][15], 'dia de hoje (16) não entra: a janela é D-1');
    }

    /** A série cobre o mês inteiro, para a tabela ter uma coluna por dia. */
    #[Test]
    public function test_serie_diaria_cobre_todos_os_dias_do_mes(): void
    {
        $this->travelTo('2026-09-16 10:00:00');

        $dados = app(DashboardBlocos::class)
            ->vendaComparacao(ChaveEscopo::deCodVendedores(['010617']), ['010617']);

        $this->assertCount(30, $dados['dias'], 'setembro tem 30 dias');

        $this->travelTo('2026-02-10 10:00:00');
        app(DashboardBlocos::class)->comRecalculoForcado();

        $fevereiro = app(DashboardBlocos::class)
            ->vendaComparacao(ChaveEscopo::deCodVendedores(['010618']), ['010618']);

        $this->assertCount(28, $fevereiro['dias'], 'fevereiro de 2026 tem 28 dias');
    }

    /**
     * ⚠️ Mesmo risco da série mensal: venda e faturamento têm o mesmo formato, então uma
     * chave compartilhada não daria erro — entregaria o número errado na aba errada.
     */
    #[Test]
    public function test_serie_diaria_de_venda_e_faturamento_nao_se_misturam(): void
    {
        $this->travelTo('2026-09-16 10:00:00');

        $this->pedido('2026-09-03', 1000, '010617');

        Faturamento::create([
            'nota_fiscal' => '999',
            'data_emissao' => '2026-09-03',
            'cod_vendedor' => '010617',
            'valor_total' => 4242,
            'quantidade' => 1,
            'valor_unitario' => 4242,
        ]);

        $bloco = app(DashboardBlocos::class);
        $escopo = ChaveEscopo::deCodVendedores(['010617']);

        $this->assertSame(1000.0, $bloco->vendaComparacao($escopo, ['010617'])['dias'][2]);
        $this->assertSame(4242.0, $bloco->faturamentoComparacao($escopo, ['010617'])['dias'][2]);
    }

    /**
     * ⚠️ Regressão do pedido do diretor em 2026-09-06: "na tela inicial dos ADM devem ter
     * essas informações também". Até então gestor recebia `null` nos dois blocos, porque
     * via o embed do Power BI no lugar — que virou só um botão.
     */
    #[Test]
    public function test_gestor_passou_a_receber_as_duas_agregacoes(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $props = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('page')['props'];

        $this->assertNotNull($props['vendaComparacao']);
        $this->assertNotNull($props['faturamentoComparacao']);
        $this->assertArrayHasKey('dias', $props['vendaComparacao']);
    }
}
