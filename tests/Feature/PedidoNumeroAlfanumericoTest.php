<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\Totvs\Normalizador;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscreveRelatorio200;
use Tests\TestCase;

/**
 * O número do pedido é TEXTO, e a série alfanumérica do TOTVS prova isso.
 *
 * 🚨 O defeito que originou este arquivo parou a importação por 5 dias sem ninguém ver.
 * Em 2026-09-08 o TOTVS estourou o contador numérico e passou a emitir `A00063`,
 * `A00064`, … Das 13h de 2026-09-10 em diante, 94 rodadas seguidas de `totvs:atualizar`
 * morreram no DELETE de pedidos obsoletos com
 * `SQLSTATE[22007] ... Truncated incorrect DOUBLE value: 'A00051'`, e os pedidos em
 * aberto congelaram no retrato de 09/09 — enquanto clientes e faturamento seguiam
 * atualizando normalmente, porque o passo quebrado é o ÚLTIMO da corrente.
 *
 * A causa é do PHP, não do MySQL: chave de array que pareça número vira `int`
 * (`$cabecalhos['992086']` → `992086`), enquanto `'A00051'` continua `string`. O
 * `array_keys()` disso devolve TIPOS MISTOS, e o MySQL, ao ver inteiros numa comparação
 * contra uma coluna varchar, passa a comparar numericamente. Ver
 * {@see Normalizador::numerosDePedido()}.
 *
 * ⚠️ Por que um teste com relatório 100% alfanumérico NÃO morderia: sem nenhum número
 * puro na lista, todas as chaves continuam string e a comparação nunca vira numérica —
 * o teste passaria com o código quebrado. **A mistura é o fixture.** Verificado por
 * mutação (2026-09-14): revertendo `numerosDePedido()` para `array_keys()` cru, os dois
 * primeiros testes daqui falham com o mesmo `QueryException` que derrubou a produção.
 *
 * E é por não haver mistura que a suíte inteira ficou verde enquanto o import morria: até
 * este arquivo, NENHUM fixture de teste usava número de pedido alfanumérico. O defeito
 * não estava numa linha de código nova — estava esperando um dado que só o TOTVS emitiu.
 */
class PedidoNumeroAlfanumericoTest extends TestCase
{
    use EscreveRelatorio200;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Cliente::create([
            'cod_cliente' => '042932',
            'loja' => 'E004',
            'razao_social' => 'TRIBUNAL DE JUSTICA DO ESTADO DE RONDONIA',
            'cnpj' => '10.466.386/0001-85',
            'cod_vendedor' => '010585',
        ]);

        $this->prepararRelatorios();
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();

        parent::tearDown();
    }

    /**
     * O caso exato de produção: o relatório traz a série antiga e a nova no mesmo arquivo,
     * e existe pedido em aberto na base que precisa ser removido por já ter saído do
     * relatório — é esse DELETE que estourava.
     */
    public function test_relatorio_com_numero_antigo_e_serie_alfanumerica_importa(): void
    {
        $this->pedidoEmAberto('994017');
        $this->pedidoEmAberto('A00062');

        $this->escreverRelatorio200([
            ['992086', 'PEDIDO 992086 INCLUIDO NA CARGA 190050'],
            ['A00051', 'PEDIDO A00051 COM BLOQUEIO DE ESTOQUE'],
            ['A02092', 'ENVIO DO PEDIDO PARA O WMS - ORDEM DE SEPARACAO 876758'],
        ]);

        $this->artisan('totvs:import-pedidos-abertos')->assertSuccessful();

        $this->assertSame(
            ['992086', 'A00051', 'A02092'],
            $this->numerosNaBase(),
            'os três do relatório entram; os dois que saíram dele são removidos'
        );
    }

    /**
     * ⚠️ O oposto do teste acima, e o mais perigoso dos dois: aqui não há erro nenhum.
     *
     * Na comparação numérica, TODO alfanumérico da coluna vira `0` — então
     * `whereNotIn('numero_pedido', [992086, 'A00051'])` deixa de excluir `A00062`, que
     * também vale `0`. O pedido que deveria ter sido removido sobrevive, e o que deveria
     * sobreviver some. Um SELECT não estoura: ele responde errado.
     */
    public function test_pedido_alfanumerico_fora_do_relatorio_e_removido(): void
    {
        $this->pedidoEmAberto('A00062');   // saiu do relatório: foi faturado ou cancelado
        $this->pedidoEmAberto('A00051');   // continua no relatório: fica

        $this->escreverRelatorio200([
            ['992086', 'PEDIDO 992086 INCLUIDO NA CARGA 190050'],
            ['A00051', 'PEDIDO A00051 COM BLOQUEIO DE ESTOQUE'],
        ]);

        $this->artisan('totvs:import-pedidos-abertos')->assertSuccessful();

        $this->assertSame(['992086', 'A00051'], $this->numerosNaBase());
        $this->assertNull(Pedido::where('numero_pedido', 'A00062')->first());
    }

    /**
     * O pedido faturado que volta a aparecer como aberto é CONTADO e AVISADO (ver o
     * cabeçalho do import). Com a comparação numérica, esse aviso conta errado: qualquer
     * alfanumérico faturado na base casa com qualquer alfanumérico do relatório.
     */
    public function test_aviso_de_reabertos_nao_conta_alfanumerico_por_engano(): void
    {
        $this->pedidoEmAberto('A00900', faturadoEm: '2026-09-01');

        $this->escreverRelatorio200([
            ['992086', 'PEDIDO 992086 INCLUIDO NA CARGA 190050'],
            ['A00051', 'PEDIDO A00051 COM BLOQUEIO DE ESTOQUE'],
        ]);

        $this->artisan('totvs:import-pedidos-abertos')
            ->doesntExpectOutputToContain('Estavam FATURADOS e voltaram a aberto')
            ->assertSuccessful();
    }

    /** Os itens seguem o pedido alfanumérico — é o `pluck` que os localiza. */
    public function test_itens_do_pedido_alfanumerico_sao_gravados(): void
    {
        $this->escreverRelatorio200([
            ['992086', 'PEDIDO 992086 INCLUIDO NA CARGA 190050'],
            ['A00051', 'PEDIDO A00051 COM BLOQUEIO DE ESTOQUE'],
        ]);

        $this->artisan('totvs:import-pedidos-abertos')->assertSuccessful();

        foreach (['992086', 'A00051'] as $numero) {
            $pedido = Pedido::where('numero_pedido', $numero)->firstOrFail();

            $this->assertSame(
                1,
                PedidoItem::where('pedido_id', $pedido->id)->count(),
                "o pedido {$numero} ficou sem item"
            );
        }
    }

    /**
     * Os números gravados, em ordem de texto.
     *
     * ⚠️ Ordenado pelo VALOR, nunca por `id`: o pedido que já existia na base entra com
     * id menor que o recém-importado, e a asserção passaria a depender de qual deles o
     * fixture criou primeiro — que não é o que estes testes afirmam.
     *
     * @return list<string>
     */
    private function numerosNaBase(): array
    {
        return Pedido::query()->pluck('numero_pedido')->sort()->values()->all();
    }

    private function pedidoEmAberto(string $numero, ?string $faturadoEm = null): void
    {
        DB::table('pedidos')->insert([
            'numero_pedido' => $numero,
            'cod_vendedor' => '010585',
            'data_pedido' => '2026-09-01',
            'data_faturamento' => $faturadoEm,
            'valor_total' => 100,
            'status' => 'pendente_totvs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
