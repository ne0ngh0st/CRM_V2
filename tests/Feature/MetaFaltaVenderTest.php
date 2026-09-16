<?php

namespace Tests\Feature;

use App\Models\Faturamento;
use App\Models\MetaMensal;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Falta vender" em /metas: meta de faturamento − faturado − carteira em aberto que ainda
 * conta para o mês.
 *
 * ⚠️ O fixture tem valores TODOS DIFERENTES, e nenhum dos que ficam de fora soma o mesmo
 * que um dos que entram. O cenário é montado para que cada regra do recorte
 * (Pedido::scopeContaParaFaturamentoDe) mude o total de um jeito próprio — com valores
 * parecidos, quebrar uma regra e quebrar outra dariam o mesmo número e o teste passaria
 * com o código errado. Cada asserção foi conferida por mutação.
 *
 *   entram (60.000):  40.000 previsto no mês · 15.000 atrasado · 5.000 previsto no último dia
 *   ficam de fora:    700 já faturado · 1.300 previsto p/ julho · 2.900 pedido de +180 dias
 *                     · 6.100 de um código fora do ranking
 */
class MetaFaltaVenderTest extends TestCase
{
    use RefreshDatabase;

    private const COD = '010617';

    private const SEM_META = '010700';

    private int $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        /*
         * Quarta-feira, 17 de junho. Não é "hoje": a janela do realizado é D-1 (D-3 na
         * segunda) e o recorte do aberto depende do fim do mês — a suíte não pode mudar
         * de resultado conforme o dia em que roda.
         */
        $this->ano = (int) now()->year;
        Carbon::setTestNow(Carbon::create($this->ano, 6, 17, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function vendedor(string $cod): User
    {
        $user = User::factory()->create(['display_name' => "V{$cod}"]);
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);

        return $user;
    }

    private function pedido(string $cod, string $dataPedido, ?string $previsao, float $valor, ?string $faturadoEm = null): void
    {
        Pedido::create([
            'numero_pedido' => 'P'.fake()->unique()->numberBetween(1, 999999),
            'cod_vendedor' => $cod,
            'data_pedido' => $dataPedido,
            'data_previsao_faturamento' => $previsao,
            'data_faturamento' => $faturadoEm,
            'valor_total' => $valor,
            'status' => $faturadoEm ? 'faturado' : 'pendente_totvs',
        ]);
    }

    private function cenario(): void
    {
        $a = $this->ano;

        $this->vendedor(self::COD);
        MetaMensal::create(['cod_vendedor' => self::COD, 'ano' => $a, 'mes' => 6, 'tipo' => 'faturamento', 'valor_meta' => 120000]);
        Faturamento::create([
            'nota_fiscal' => '1', 'data_emissao' => "{$a}-06-05", 'cod_vendedor' => self::COD,
            'valor_total' => 30000, 'quantidade' => 1, 'valor_unitario' => 30000,
        ]);

        // Entram.
        $this->pedido(self::COD, "{$a}-06-10", "{$a}-06-25", 40000);
        $this->pedido(self::COD, "{$a}-05-10", "{$a}-05-28", 15000);   // atrasado: previsão venceu no mês PASSADO
        $this->pedido(self::COD, "{$a}-06-12", "{$a}-06-30", 5000);    // último dia do mês

        // Ficam de fora.
        $this->pedido(self::COD, "{$a}-06-01", "{$a}-06-20", 700, "{$a}-06-03");  // já faturado
        $this->pedido(self::COD, "{$a}-06-11", "{$a}-07-01", 1300);                // previsto p/ julho
        $this->pedido(self::COD, now()->subDays(198)->toDateString(), now()->subDays(190)->toDateString(), 2900); // resíduo
        $this->pedido('999999', "{$a}-06-10", "{$a}-06-25", 6100);                 // fora do ranking
    }

    /** @return array{linhas: array, totais: array, periodo: array} */
    private function metas(array $filtros = []): array
    {
        $props = $this->actingAs($this->admin())
            ->get(route('metas.index', $filtros + ['ano' => $this->ano, 'mes' => 6]))
            ->assertOk()
            ->viewData('page')['props'];

        return ['linhas' => $props['linhas'], 'totais' => $props['totais'], 'periodo' => $props['periodo']];
    }

    private function linha(array $linhas, string $cod): array
    {
        return collect($linhas)->firstWhere('codVendedor', $cod);
    }

    #[Test]
    public function test_em_aberto_soma_so_o_que_ainda_fatura_no_mes(): void
    {
        $this->cenario();

        $linha = $this->linha($this->metas()['linhas'], self::COD);

        // 700 = faturado entrou · 1.300 = previsão de julho entrou · 2.900 = corte de 180 dias
        // sumiu · 5.000 a menos = `<` no lugar de `<=` · 15.000 a menos = virou whereBetween.
        $this->assertEqualsWithDelta(60000, $linha['emAberto'], 0.001);
    }

    #[Test]
    public function test_falta_vender_desconta_o_faturado_e_o_em_aberto(): void
    {
        $this->cenario();

        $linha = $this->linha($this->metas()['linhas'], self::COD);

        // 120.000 − 30.000 − 60.000. Esquecer o faturado daria 60.000; esquecer o aberto, 90.000.
        $this->assertEqualsWithDelta(30000, $linha['faltaVender'], 0.001);
    }

    #[Test]
    public function test_carteira_que_ja_cobre_a_meta_da_falta_negativa(): void
    {
        $this->cenario();
        MetaMensal::where('cod_vendedor', self::COD)->update(['valor_meta' => 50000]);

        $linha = $this->linha($this->metas()['linhas'], self::COD);

        // A tela escreve "Coberto"; o número continua com sinal para o Excel.
        $this->assertEqualsWithDelta(-40000, $linha['faltaVender'], 0.001);
    }

    /**
     * A borda do corte de 180 dias sai da MESMA constante do model — se alguém trocar o
     * corte por outro número ou criar uma constante nova para ele, este teste acusa.
     */
    #[Test]
    public function test_corte_de_idade_usa_a_constante_do_pedido(): void
    {
        $this->vendedor(self::COD);
        $limite = now()->subDays(Pedido::DIAS_MAXIMO_EM_ABERTO);
        $previsao = "{$this->ano}-06-20";

        $this->pedido(self::COD, $limite->toDateString(), $previsao, 800);             // na borda: entra
        $this->pedido(self::COD, $limite->copy()->subDay()->toDateString(), $previsao, 90); // um dia antes: fora
        $this->pedido(self::COD, now()->subDays(120)->toDateString(), $previsao, 4);    // 120 dias: entra

        $linha = $this->linha($this->metas()['linhas'], self::COD);

        $this->assertEqualsWithDelta(804, $linha['emAberto'], 0.001);
    }

    #[Test]
    public function test_codigo_fora_do_ranking_nao_entra_no_total(): void
    {
        $this->cenario();

        $totais = $this->metas()['totais'];

        // Com o 999999 (sem conta no CRM) o total seria 66.100.
        $this->assertEqualsWithDelta(60000, $totais['emAberto'], 0.001);
        $this->assertEqualsWithDelta(30000, $totais['faltaVender'], 0.001);
    }

    /**
     * Vendedor sem meta: o aberto aparece, a falta NÃO. Sem o guard ele mostraria
     * −8.300, um número negativo sem significado nenhum.
     */
    #[Test]
    public function test_sem_meta_nao_tem_falta_mas_tem_aberto(): void
    {
        $this->cenario();
        $this->vendedor(self::SEM_META);
        $this->pedido(self::SEM_META, "{$this->ano}-06-09", "{$this->ano}-06-26", 8300);

        $dados = $this->metas();
        $linha = $this->linha($dados['linhas'], self::SEM_META);

        $this->assertEqualsWithDelta(8300, $linha['emAberto'], 0.001);
        $this->assertNull($linha['faltaVender']);

        // O total de "falta" é a soma das linhas COM meta: o aberto de quem não tem meta
        // não abate a meta de ninguém. O total de aberto soma todo mundo.
        $this->assertEqualsWithDelta(68300, $dados['totais']['emAberto'], 0.001);
        $this->assertEqualsWithDelta(30000, $dados['totais']['faltaVender'], 0.001);
    }

    /**
     * Carteira em aberto é foto de HOJE. Num mês fechado ela não diz nada sobre aquele mês,
     * e mostrar zero leria como "carteira vazia" — por isso nulo, e não 0.
     */
    #[Test]
    public function test_mes_passado_nao_tem_aberto_nem_falta(): void
    {
        $this->cenario();
        MetaMensal::create(['cod_vendedor' => self::COD, 'ano' => $this->ano, 'mes' => 5, 'tipo' => 'faturamento', 'valor_meta' => 777]);

        $dados = $this->metas(['mes' => 5]);
        $linha = $this->linha($dados['linhas'], self::COD);

        $this->assertNull($linha['emAberto']);
        $this->assertNull($linha['faltaVender']);
        $this->assertNull($dados['totais']['emAberto']);
        $this->assertNull($dados['totais']['faltaVender']);
        $this->assertFalse($dados['periodo']['abertoAplicavel']);
    }

    #[Test]
    public function test_mes_corrente_marca_o_aberto_como_aplicavel(): void
    {
        $this->cenario();

        $periodo = $this->metas()['periodo'];

        $this->assertTrue($periodo['abertoAplicavel']);
        $this->assertSame(now()->toDateString(), $periodo['abertoEm']);
    }

    /** Mês futuro: o que tem previsão até lá conta, inclusive o de julho. */
    #[Test]
    public function test_mes_futuro_estende_o_teto_da_previsao(): void
    {
        $this->cenario();

        $linha = $this->linha($this->metas(['mes' => 7])['linhas'], self::COD);

        $this->assertEqualsWithDelta(61300, $linha['emAberto'], 0.001);
    }

    /**
     * No acumulado (jan..jun) o teto da previsão continua sendo o fim de JUNHO. Com o fim
     * de janeiro (o mês inicial) a carteira inteira sumiria.
     */
    #[Test]
    public function test_acumulado_usa_o_fim_do_mes_escolhido(): void
    {
        $this->cenario();

        $linha = $this->linha($this->metas(['modo' => 'acumulado'])['linhas'], self::COD);

        $this->assertEqualsWithDelta(60000, $linha['emAberto'], 0.001);
        $this->assertEqualsWithDelta(30000, $linha['faltaVender'], 0.001);
    }
}
