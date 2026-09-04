<?php

namespace Tests\Feature;

use App\Models\Faturamento;
use App\Models\MetaMensal;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Cache\ChaveEscopo;
use App\Services\Dashboard\DashboardBlocos;
use App\Services\Metas\MetaRankingResolver;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O gauge "Performance Comercial" do Painel medindo VENDA (pedido emitido), não só
 * faturamento.
 *
 * ⚠️ Os valores do fixture são propositalmente TODOS DIFERENTES entre si (meta de venda
 * 4.000, meta de faturamento 250, pedido 1.000, nota 500). Venda e faturamento têm formato
 * de retorno idêntico: com números parecidos, trocar a tabela de um pela do outro passaria
 * verde e o card mostraria o número errado sob o rótulo certo — que é exatamente o modo de
 * falha que estes testes existem para pegar. Cada asserção abaixo foi conferida por
 * mutação (quebrar a regra faz o teste falhar).
 */
class DashboardMetaVendaTest extends TestCase
{
    use RefreshDatabase;

    private const COD = '010617';

    private const OUTRO_COD = '999999';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        /*
         * Relógio congelado no dia 21 de junho, e não em "hoje", por dois motivos: a
         * janela do realizado é D-1 (D-3 na segunda), então rodar a suíte no dia 1º de um
         * mês daria intervalo vazio; e a janela do ANO precisa de um mês anterior dentro
         * do mesmo ano civil para distinguir "no mês" de "no ano".
         */
        Carbon::setTestNow(Carbon::create((int) now()->year, 6, 21, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vendedor(string $cod = self::COD): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);

        return $user;
    }

    private function pedido(string $data, float $valor, string $cod = self::COD): void
    {
        Pedido::create([
            'numero_pedido' => 'P'.fake()->unique()->numberBetween(1, 999999),
            'cod_vendedor' => $cod,
            'data_pedido' => $data,
            'valor_total' => $valor,
            'status' => 'faturado',
        ]);
    }

    private function faturamento(string $data, float $valor, string $cod = self::COD): void
    {
        Faturamento::create([
            'nota_fiscal' => (string) fake()->unique()->numberBetween(1, 999999),
            'data_emissao' => $data,
            'cod_vendedor' => $cod,
            'valor_total' => $valor,
            'quantidade' => 1,
            'valor_unitario' => $valor,
        ]);
    }

    private function meta(string $tipo, float $valor, int $mes = 6, string $cod = self::COD): void
    {
        MetaMensal::create([
            'cod_vendedor' => $cod,
            'ano' => (int) now()->year,
            'mes' => $mes,
            'tipo' => $tipo,
            'valor_meta' => $valor,
        ]);
    }

    /** O cenário completo, usado pela maioria dos testes. */
    private function cenario(): array
    {
        $ano = (int) now()->year;

        $this->pedido("{$ano}-06-05", 1000);   // dentro do mês e do ano
        $this->pedido("{$ano}-03-10", 2000);   // só no ano
        $this->pedido("{$ano}-06-25", 9999);   // posterior a D-1: fora das duas janelas
        $this->faturamento("{$ano}-06-05", 500);

        $this->meta('venda', 4000);
        $this->meta('faturamento', 250);

        return app(DashboardBlocos::class)
            ->comRecalculoForcado()
            ->metaGauge(ChaveEscopo::deCodVendedores([self::COD]), [self::COD]);
    }

    #[Test]
    public function test_bloco_entrega_os_dois_tipos_com_mes_e_ano(): void
    {
        $gauge = $this->cenario();

        $this->assertArrayHasKey('venda', $gauge);
        $this->assertArrayHasKey('faturamento', $gauge);

        foreach (['venda', 'faturamento'] as $tipo) {
            foreach (['mes', 'ano'] as $periodo) {
                $this->assertArrayHasKey($periodo, $gauge[$tipo]);
                $this->assertSame($tipo, $gauge[$tipo][$periodo]['tipo']);
            }
        }
    }

    /**
     * ⚠️ O teste central deste arquivo: venda tem que somar `pedidos`, faturamento tem que
     * somar `faturamentos`, e cada um tem que ler a meta do PRÓPRIO tipo. Trocar qualquer
     * um dos quatro cruzamentos não gera erro nenhum — só um número errado no card.
     */
    #[Test]
    public function test_venda_soma_pedidos_e_faturamento_soma_notas(): void
    {
        $gauge = $this->cenario();

        $this->assertSame(1000.0, $gauge['venda']['mes']['realizado'], 'venda do mês tem que vir de pedidos');
        $this->assertSame(4000.0, $gauge['venda']['mes']['meta'], 'venda tem que ler a meta tipo=venda');
        $this->assertSame(25.0, $gauge['venda']['mes']['percentual']);

        $this->assertSame(500.0, $gauge['faturamento']['mes']['realizado'], 'faturamento do mês tem que vir de faturamentos');
        $this->assertSame(250.0, $gauge['faturamento']['mes']['meta'], 'faturamento tem que ler a meta tipo=faturamento');
        $this->assertSame(200.0, $gauge['faturamento']['mes']['percentual']);
    }

    /** O acumulado do ano soma os meses anteriores; a janela final continua sendo D-1. */
    #[Test]
    public function test_acumulado_do_ano_inclui_meses_anteriores_e_respeita_o_corte_d1(): void
    {
        $gauge = $this->cenario();

        $this->assertSame(3000.0, $gauge['venda']['ano']['realizado'], '1.000 de junho + 2.000 de março, e nada do dia 25');
        $this->assertSame(1000.0, $gauge['venda']['mes']['realizado'], 'o pedido de março não pode entrar no mês');
    }

    /**
     * ⚠️ Garantia estrutural: o "Valor no mês" do bloco de tiles é o MESMO número do
     * "Realizado" da aba Venda, não uma segunda soma. Se alguém voltar a recalcular esse
     * valor em separado, os dois podem divergir na tela sem nada quebrar.
     */
    #[Test]
    public function test_valor_dos_tiles_e_o_mesmo_realizado_da_aba_venda(): void
    {
        $gauge = $this->cenario();

        $this->assertSame($gauge['venda']['mes']['realizado'], $gauge['pedidosEmitidos']['mes']['valor']);
        $this->assertSame($gauge['venda']['ano']['realizado'], $gauge['pedidosEmitidos']['ano']['valor']);
    }

    /**
     * As duas contagens saem de uma query só (`COUNT(*)` para o ano, `SUM(data >= início
     * do mês)` para o mês). Trocar as duas de lugar dá números plausíveis e errados.
     */
    #[Test]
    public function test_contagem_de_pedidos_separa_mes_de_ano(): void
    {
        $gauge = $this->cenario();

        $this->assertSame(1, $gauge['pedidosEmitidos']['mes']['pedidos']);
        $this->assertSame(2, $gauge['pedidosEmitidos']['ano']['pedidos']);
    }

    #[Test]
    public function test_escopo_do_vendedor_e_respeitado_nos_dois_tipos(): void
    {
        $ano = (int) now()->year;
        $this->pedido("{$ano}-06-05", 1000);
        $this->pedido("{$ano}-06-05", 7777, self::OUTRO_COD);
        $this->faturamento("{$ano}-06-05", 500);
        $this->faturamento("{$ano}-06-05", 8888, self::OUTRO_COD);

        $gauge = app(DashboardBlocos::class)
            ->comRecalculoForcado()
            ->metaGauge(ChaveEscopo::deCodVendedores([self::COD]), [self::COD]);

        $this->assertSame(1000.0, $gauge['venda']['mes']['realizado']);
        $this->assertSame(500.0, $gauge['faturamento']['mes']['realizado']);
        $this->assertSame(1, $gauge['pedidosEmitidos']['mes']['pedidos']);
    }

    /** Vendedor sem código de vendedor: escopo vazio zera tudo, sem erro. */
    #[Test]
    public function test_escopo_vazio_zera_os_dois_tipos(): void
    {
        $ano = (int) now()->year;
        $this->pedido("{$ano}-06-05", 1000);

        $gauge = app(DashboardBlocos::class)
            ->comRecalculoForcado()
            ->metaGauge(ChaveEscopo::deCodVendedores([]), []);

        $this->assertSame(0.0, $gauge['venda']['mes']['realizado']);
        $this->assertSame(0.0, $gauge['faturamento']['ano']['realizado']);
        $this->assertSame(0, $gauge['pedidosEmitidos']['ano']['pedidos']);
    }

    /**
     * ⚠️ O tipo vem da aba do card e escolhe QUAL TABELA responde. Um valor desconhecido
     * caindo num default silencioso mostraria o realizado errado sob o rótulo certo.
     */
    #[Test]
    public function test_tipo_de_meta_desconhecido_estoura(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(MetaRankingResolver::class)->metaVsRealizado([self::COD], (int) now()->year, 6, 6, 'comissao');
    }

    /**
     * ⚠️ Orçamento de queries. O bloco faz QUATRO agregações de meta × realizado; se o
     * escopo voltar a ser resolvido dentro de cada uma (6 queries de roles/perfis por
     * resolução), a conta explode sem que nenhum resultado mude — degradação muda, que é
     * a que ninguém percebe.
     */
    #[Test]
    public function test_bloco_nao_resolve_o_escopo_uma_vez_por_agregacao(): void
    {
        $ano = (int) now()->year;
        $this->pedido("{$ano}-06-05", 1000);
        $this->meta('venda', 4000);

        $blocos = app(DashboardBlocos::class)->comRecalculoForcado();
        $chave = ChaveEscopo::deCodVendedores([self::COD]);

        $queries = ContadorDeQueries::contar(fn () => $blocos->metaGauge($chave, [self::COD]));

        // 4 metas + 2 somas de pedido + 2 somas de faturamento + 1 contagem = 9.
        $this->assertLessThanOrEqual(9, $queries, "metaGauge fez {$queries} queries; o teto é 9.");
    }

    /** O Painel entrega ao front o payload com os dois tipos — contrato do card. */
    #[Test]
    public function test_painel_entrega_os_dois_tipos_ao_front(): void
    {
        $props = $this->actingAs($this->vendedor())
            ->get(route('dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayHasKey('venda', $props['metaGauge']);
        $this->assertArrayHasKey('faturamento', $props['metaGauge']);
        $this->assertArrayHasKey('realizado', $props['metaGauge']['venda']['mes']);
        $this->assertArrayHasKey('isRepresentante', $props['metaGauge']);
    }
}
