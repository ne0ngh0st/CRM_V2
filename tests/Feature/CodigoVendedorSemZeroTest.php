<?php

namespace Tests\Feature;

use App\Models\Faturamento;
use App\Models\Pedido;
use App\Services\Metas\MetaRankingResolver;
use App\Services\Totvs\Normalizador;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EscreveRelatorio198;
use Tests\Concerns\EscreveRelatorio232;
use Tests\TestCase;

/**
 * O relatório pode chegar com o zero à esquerda do código de vendedor "comido".
 *
 * 🚨 Caso real de 2026-09-16: o 232 de setembro foi enviado com `10755` no lugar de
 * `010755` (CSV salvo pelo Excel) em 97 mil linhas. O import gravou o texto como veio e,
 * até o arquivo correto chegar na manhã seguinte, os faturados de setembro de quase toda
 * a equipe não casavam com `vendedor_perfis.cod_vendedor`. O gauge da Inaya mostrou
 * R$ 9.266,80 — só os pedidos em aberto, que vêm do 200 — contra R$ 113.903,94 reais.
 * Nenhum erro, nenhum alarme. Ver {@see Normalizador::codigoVendedor()}.
 *
 * ⚠️ Os testes passam pelo MESMO caminho do gauge (`metaVsRealizado` com o código de 6
 * dígitos), não por uma leitura da coluna: é a igualdade com o perfil que importa.
 */
class CodigoVendedorSemZeroTest extends TestCase
{
    use EscreveRelatorio198;
    use EscreveRelatorio232;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->prepararRelatorios();
        Carbon::setTestNow('2026-09-17 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->removerRelatorios();

        parent::tearDown();
    }

    public function test_pedido_emitido_com_codigo_sem_zero_conta_para_o_vendedor(): void
    {
        $this->escreverRelatorio232([
            ['PEDIDO' => '997238', 'COD_VENDEDOR' => '10755', 'DT_EMISSAO' => '02/09/2026', 'VLR_TOTAL' => '307,80'],
            // Formato correto no mesmo arquivo: não pode ser alterado.
            ['PEDIDO' => '997239', 'COD_VENDEDOR' => '010755', 'DT_EMISSAO' => '02/09/2026', 'VLR_TOTAL' => '19,17'],
        ]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $this->assertSame(['010755'], Pedido::query()->distinct()->pluck('cod_vendedor')->all());

        $venda = app(MetaRankingResolver::class)->metaVsRealizado(['010755'], 2026, 9, 9, 'venda');
        $this->assertEqualsWithDelta(326.97, $venda['realizado'], 0.001);
    }

    public function test_faturamento_com_codigo_sem_zero_conta_para_o_vendedor(): void
    {
        $this->escreverRelatorio198([
            ['COD_VENDEDOR' => '359', 'VLR_TOTAL' => '500,00'],
        ]);

        $this->artisan('totvs:import-faturamento')->assertSuccessful();

        $this->assertSame(['000359'], Faturamento::query()->distinct()->pluck('cod_vendedor')->all());

        $fat = app(MetaRankingResolver::class)->metaVsRealizado(['000359'], 2026, 9, 9, 'faturamento');
        $this->assertEqualsWithDelta(500.0, $fat['realizado'], 0.001);
    }

    public function test_regra_do_codigo_de_vendedor(): void
    {
        $this->assertSame('010755', Normalizador::codigoVendedor('10755'));
        $this->assertSame('010755', Normalizador::codigoVendedor(' 010755 '));
        $this->assertSame('000006', Normalizador::codigoVendedor('6'));
        // Não numérico ou já maior que 6: passa como está (não é o formato que o Excel estraga).
        $this->assertSame('V00123', Normalizador::codigoVendedor('V00123'));
        $this->assertSame('1234567', Normalizador::codigoVendedor('1234567'));
        $this->assertNull(Normalizador::codigoVendedor('   '));
    }
}
