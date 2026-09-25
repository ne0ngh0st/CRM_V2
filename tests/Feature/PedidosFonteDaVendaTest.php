<?php

namespace Tests\Feature;

use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\Pedidos\StatusPedidoResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EscreveRelatorio200;
use Tests\Concerns\EscreveRelatorio232;
use Tests\TestCase;

/**
 * 🥇 O 232 é a fonte da venda; o 200 só dá a etapa (decisão do Tony, 2026-09-25).
 *
 * Caso real: setembro/2026 saiu R$ 61,5 mi no CRM e no BI contra R$ 58,4 mi no Excel do
 * 232. O 200 trazia remessa sem financeiro (almoxarifado virtual, transferência) que o
 * 232 não traz, e estava três dias mais velho. Estes testes travam o contrato novo: o
 * total do mês é o que o 232 soma, e o 200 não cria, não apaga e não muda valor.
 *
 * ⚠️ Valores do fixture todos diferentes entre si: com números parecidos, somar a
 * linha errada (ou a mesma duas vezes) passaria verde.
 */
class PedidosFonteDaVendaTest extends TestCase
{
    use EscreveRelatorio200;
    use EscreveRelatorio232;
    use RefreshDatabase;

    /** Linha do 232 ainda sem nota fiscal. */
    private const ABERTA = ['DT_FATURAMENTO' => '', 'NOTA_FISCAL' => '', 'SERIE' => '', 'TP_FAT' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->prepararRelatorios();
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();

        parent::tearDown();
    }

    public function test_232_grava_pedido_em_aberto_com_o_valor_do_relatorio(): void
    {
        $this->escreverRelatorio232([['PEDIDO' => '995001', 'VLR_TOTAL' => '1.234,56'] + self::ABERTA]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $pedido = Pedido::where('numero_pedido', '995001')->firstOrFail();
        $this->assertNull($pedido->data_faturamento);
        $this->assertEqualsWithDelta(1234.56, (float) $pedido->valor_total, 0.001);
        $this->assertSame(StatusPedidoResolver::DESCONHECIDO, $pedido->status);
    }

    public function test_faturamento_parcial_fica_em_aberto_com_o_valor_cheio(): void
    {
        $this->escreverRelatorio232([
            ['PEDIDO' => '995002', 'COD_PROD' => 'A', 'VLR_TOTAL' => '300,00'],
            ['PEDIDO' => '995002', 'COD_PROD' => 'B', 'VLR_TOTAL' => '45,00'] + self::ABERTA,
        ]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $pedido = Pedido::where('numero_pedido', '995002')->firstOrFail();
        $this->assertNull($pedido->data_faturamento, 'basta um item sem nota para o pedido seguir em aberto');
        $this->assertEqualsWithDelta(345.0, (float) $pedido->valor_total, 0.001);
        $this->assertSame(2, PedidoItem::where('pedido_id', $pedido->id)->count());
    }

    public function test_pedido_inteiramente_faturado_leva_a_ultima_data_de_nota(): void
    {
        $this->escreverRelatorio232([
            ['PEDIDO' => '995003', 'COD_PROD' => 'A', 'DT_FATURAMENTO' => '05/09/2026', 'TP_FAT' => 'SERVICO', 'SERIE' => 'RPS', 'NOTA_FISCAL' => '299240'],
            ['PEDIDO' => '995003', 'COD_PROD' => 'B', 'DT_FATURAMENTO' => '09/09/2026'],
        ]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $pedido = Pedido::where('numero_pedido', '995003')->firstOrFail();
        $this->assertSame('2026-09-09', $pedido->data_faturamento->format('Y-m-d'));
        $this->assertSame(StatusPedidoResolver::FATURADO, $pedido->status);
        $this->assertSame('servico', $pedido->tipo_faturamento);
        $this->assertSame('299240', $pedido->rps);
    }

    /** É o recorte que tira as remessas que o 200 gravou antes de 2026-09-25. */
    public function test_recorte_remove_so_o_que_esta_na_faixa_do_arquivo(): void
    {
        $this->pedido('995100', '2026-09-02');   // na faixa, fora do arquivo: sai
        $this->pedido('995101', '2026-08-31');   // fora da faixa: fica

        $this->escreverRelatorio232([
            ['PEDIDO' => '995004', 'DT_EMISSAO' => '01/09/2026'],
            ['PEDIDO' => '995005', 'DT_EMISSAO' => '03/09/2026'],
        ]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $this->assertSame(
            ['995004', '995005', '995101'],
            Pedido::query()->orderBy('numero_pedido')->pluck('numero_pedido')->all()
        );
    }

    public function test_200_nao_cria_pedido_nem_muda_valor(): void
    {
        $this->pedido('990001', '2026-08-14', valor: 777.0);

        $this->escreverRelatorio200([
            ['990001', 'PEDIDO 990001 COM BLOQUEIO DE ESTOQUE'],
            ['990002', 'PEDIDO 990002 INCLUIDO NA CARGA 190050'], // remessa: não está no CRM
        ]);

        $this->artisan('totvs:import-pedidos-abertos')
            ->expectsOutputToContain('No 200 e não no CRM (não gravados): 1 pedidos')
            ->assertSuccessful();

        $this->assertSame(['990001'], Pedido::query()->pluck('numero_pedido')->all());

        $pedido = Pedido::where('numero_pedido', '990001')->firstOrFail();
        $this->assertEqualsWithDelta(777.0, (float) $pedido->valor_total, 0.001, 'o 200 diz 1.000,00, mas valor é do 232');
        $this->assertSame(StatusPedidoResolver::BLOQUEIO_ESTOQUE, $pedido->status);
        $this->assertSame('PEDIDO 990001 COM BLOQUEIO DE ESTOQUE', $pedido->historico_totvs);
    }

    public function test_200_nao_mexe_em_pedido_faturado(): void
    {
        $this->pedido('990003', '2026-08-14', faturadoEm: '2026-09-01');

        $this->escreverRelatorio200([['990003', 'PEDIDO 990003 INCLUIDO NA CARGA 190050']]);

        $this->artisan('totvs:import-pedidos-abertos')->assertSuccessful();

        $pedido = Pedido::where('numero_pedido', '990003')->firstOrFail();
        $this->assertSame('2026-09-01', $pedido->data_faturamento->format('Y-m-d'));
        $this->assertSame(StatusPedidoResolver::FATURADO, $pedido->status);
    }

    /** O 232 roda antes do 200: reimportar não pode apagar a etapa que o 200 deu. */
    public function test_232_preserva_a_etapa_do_pedido_em_aberto(): void
    {
        $this->pedido('995006', '2026-09-01', status: StatusPedidoResolver::EM_CARGA);

        $this->escreverRelatorio232([['PEDIDO' => '995006'] + self::ABERTA]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $this->assertSame(StatusPedidoResolver::EM_CARGA, Pedido::where('numero_pedido', '995006')->value('status'));
    }

    /** Faturado que voltou a ter item em aberto perde o selo de faturado. */
    public function test_faturado_que_vira_parcial_perde_o_selo(): void
    {
        $this->pedido('995007', '2026-09-01', faturadoEm: '2026-09-02');

        $this->escreverRelatorio232([
            ['PEDIDO' => '995007', 'COD_PROD' => 'A'],
            ['PEDIDO' => '995007', 'COD_PROD' => 'B'] + self::ABERTA,
        ]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $pedido = Pedido::where('numero_pedido', '995007')->firstOrFail();
        $this->assertNull($pedido->data_faturamento);
        $this->assertSame(StatusPedidoResolver::DESCONHECIDO, $pedido->status);
    }

    private function pedido(string $numero, string $data, ?string $faturadoEm = null, float $valor = 100.0, ?string $status = null): void
    {
        Pedido::create([
            'numero_pedido' => $numero,
            'cod_vendedor' => '010585',
            'data_pedido' => $data,
            'data_faturamento' => $faturadoEm,
            'status' => $status ?? ($faturadoEm ? StatusPedidoResolver::FATURADO : StatusPedidoResolver::DESCONHECIDO),
            'valor_total' => $valor,
        ]);
    }
}
