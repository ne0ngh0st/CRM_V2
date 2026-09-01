<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campos vindos do RLT 232 na ficha do cliente (RPS, natureza, nota fiscal, peso).
 *
 * ⚠️ POR QUE ESTE TESTE EXISTE: as colunas foram criadas antes de o TOTVS passar a
 * fornecer o dado, entao na pratica elas estao vazias em 100% dos registros hoje. Uma
 * tela construida assim nunca foi vista funcionando -- e "o payload tem a chave" nao
 * prova que o valor certo chega, nem que o peso da linha e calculado direito. Aqui o
 * dado e preenchido a mao para percorrer o caminho real.
 *
 * O outro lado importa tanto quanto: com os campos vazios a tela NAO pode ganhar
 * coluna em branco. Ja existe esse defeito na pagina (data de entrega, PCP, carga e
 * condicao de pagamento estao vazias em todos os pedidos faturados, porque o relatorio
 * nao as fornece) e a ideia era nao piora-lo.
 */
class CarteiraDetalhesCamposFiscaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function gestor(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function clienteComPedido(array $pedido = [], array $item = []): Cliente
    {
        $cliente = Cliente::create([
            'cod_cliente' => '000123',
            'loja' => '01',
            'razao_social' => 'CLIENTE TESTE',
            'cod_vendedor' => '010002',
        ]);

        $p = Pedido::create(array_merge([
            'numero_pedido' => '900001',
            'cliente_id' => $cliente->id,
            'cod_vendedor' => '010002',
            'data_pedido' => '2026-08-01',
            'status' => 'faturado',
            'valor_total' => 1000,
        ], $pedido));

        PedidoItem::create(array_merge([
            'pedido_id' => $p->id,
            'cod_produto' => 'V0222',
            'descricao' => 'BOBINA TESTE',
            'quantidade' => 10,
            'valor_unitario' => 100,
            'valor_total' => 1000,
        ], $item));

        return $cliente;
    }

    public function test_campos_ficam_nulos_quando_o_totvs_ainda_nao_fornece(): void
    {
        $cliente = $this->clienteComPedido();

        $this->actingAs($this->gestor())
            ->get(route('carteira.detalhes', $cliente))
            ->assertOk()
            ->assertInertia(function ($page) {
                $pedido = $page->toArray()['props']['pedidos']['data'][0];

                // Nulo, e não ausente: a tela decide o que mostrar a partir disso.
                $this->assertNull($pedido['rps']);
                $this->assertNull($pedido['tipoFaturamento']);
                $this->assertNull($pedido['pesoTotal']);
                $this->assertNull($pedido['itens'][0]['notaFiscal']);
                $this->assertNull($pedido['itens'][0]['pesoLinha']);
            });
    }

    public function test_campos_chegam_na_tela_quando_o_dado_existe(): void
    {
        $cliente = $this->clienteComPedido(
            [
                'rps' => 'RPS-4455',
                'tipo_faturamento' => 'servico',
                'condicao_pagamento' => '28/56 DDL',
            ],
            [
                'nota_fiscal' => '000917915',
                'peso_liquido' => 0.100,
            ],
        );

        $this->actingAs($this->gestor())
            ->get(route('carteira.detalhes', $cliente))
            ->assertOk()
            ->assertInertia(function ($page) {
                $pedido = $page->toArray()['props']['pedidos']['data'][0];

                $this->assertSame('RPS-4455', $pedido['rps']);
                $this->assertSame('servico', $pedido['tipoFaturamento']);
                $this->assertSame('28/56 DDL', $pedido['condicaoPagamento']);
                $this->assertSame('000917915', $pedido['itens'][0]['notaFiscal']);
            });
    }

    /**
     * O peso do TOTVS e unitario, nao o da linha: o mesmo produto sai com 0,100 tanto
     * na quantidade 1 quanto na 10 (confirmado nos 1.884 produtos do relatorio real,
     * todos com um unico valor de PESO_LIQ). Exibir o valor cru mostraria 0,100 kg num
     * pedido de 10 unidades -- por isso a multiplicacao, e por isso este teste.
     */
    public function test_peso_da_linha_multiplica_o_unitario_pela_quantidade(): void
    {
        $cliente = $this->clienteComPedido([], ['peso_liquido' => 0.100, 'quantidade' => 10]);

        $this->actingAs($this->gestor())
            ->get(route('carteira.detalhes', $cliente))
            ->assertOk()
            ->assertInertia(function ($page) {
                $pedido = $page->toArray()['props']['pedidos']['data'][0];

                $this->assertEqualsWithDelta(1.0, $pedido['itens'][0]['pesoLinha'], 0.0001);
                $this->assertEqualsWithDelta(0.1, $pedido['itens'][0]['pesoLiquido'], 0.0001);
                // Peso do pedido é a soma das linhas, não a soma dos unitários.
                $this->assertEqualsWithDelta(1.0, $pedido['pesoTotal'], 0.0001);
            });
    }

    public function test_peso_do_pedido_soma_todas_as_linhas(): void
    {
        $cliente = $this->clienteComPedido([], ['peso_liquido' => 0.100, 'quantidade' => 10]);

        PedidoItem::create([
            'pedido_id' => Pedido::first()->id,
            'cod_produto' => 'V0256',
            'descricao' => 'ETIQUETA TESTE',
            'quantidade' => 4,
            'peso_liquido' => 0.520,
            'valor_unitario' => 50,
            'valor_total' => 200,
        ]);

        $this->actingAs($this->gestor())
            ->get(route('carteira.detalhes', $cliente))
            ->assertOk()
            ->assertInertia(function ($page) {
                $pedido = $page->toArray()['props']['pedidos']['data'][0];

                // 10 × 0,100 + 4 × 0,520 = 3,080
                $this->assertEqualsWithDelta(3.08, $pedido['pesoTotal'], 0.0001);
            });
    }

    /**
     * Item sem peso no meio de itens com peso nao pode zerar nem quebrar o total --
     * o cenario real do periodo de transicao, em que parte do dado ja chegou.
     */
    public function test_item_sem_peso_nao_quebra_o_total_do_pedido(): void
    {
        $cliente = $this->clienteComPedido([], ['peso_liquido' => 0.100, 'quantidade' => 10]);

        PedidoItem::create([
            'pedido_id' => Pedido::first()->id,
            'cod_produto' => 'SEM-PESO',
            'descricao' => 'ITEM SEM PESO',
            'quantidade' => 7,
            'valor_unitario' => 10,
            'valor_total' => 70,
        ]);

        $this->actingAs($this->gestor())
            ->get(route('carteira.detalhes', $cliente))
            ->assertOk()
            ->assertInertia(function ($page) {
                $pedido = $page->toArray()['props']['pedidos']['data'][0];

                $this->assertEqualsWithDelta(1.0, $pedido['pesoTotal'], 0.0001);
                $this->assertNull($pedido['itens'][1]['pesoLinha']);
            });
    }
}
