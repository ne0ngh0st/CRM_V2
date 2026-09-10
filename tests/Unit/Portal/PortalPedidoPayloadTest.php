<?php

namespace Tests\Unit\Portal;

use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Services\Orcamento\OrcamentoCalculoService;
use App\Services\Portal\PortalPedidoInvalidoException;
use App\Services\Portal\PortalPedidoPayload;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Trava a tradução orçamento → `POST /v1/api/orders`.
 *
 * ⚠️ Roda SEM banco de propósito (modelos montados em memória): a matemática de
 * centavos e IPI é onde mora o erro caro desta integração, e teste que precisa de
 * fixture no banco é teste que ninguém roda enquanto desenvolve.
 *
 * Os valores dos fixtures são escolhidos para DISTINGUIR o certo do errado, não por
 * serem bonitos — R$ 10,325 com IPI vira exatamente 1000 centavos sem imposto e 1033
 * com, então trocar o lado do interruptor não passa despercebido.
 */
class PortalPedidoPayloadTest extends TestCase
{
    private function payload(): PortalPedidoPayload
    {
        return new PortalPedidoPayload(new OrcamentoCalculoService());
    }

    /** @param array<int, array<string, mixed>> $itens */
    private function orcamento(array $itens, array $atributos = []): Orcamento
    {
        $orcamento = (new Orcamento())->forceFill(array_merge([
            'id' => 77,
            'tipo_produto_servico' => 'produto',
            'tipo_frete' => 'CIF',
            'observacoes' => null,
        ], $atributos));

        $orcamento->setRelation('itens', new Collection(array_map(
            fn (array $i, int $pos) => (new OrcamentoItem())->forceFill(array_merge([
                'id' => 900 + $pos,
                'tipo_item' => 'bobina',
                'cod_produto' => 'P1',
                'descricao' => 'Bobina 80x40',
                'quantidade' => 10,
                'valor_unitario' => 12.50,
                'calcula_ipi' => false,
            ], $i)),
            $itens,
            array_keys($itens)
        )));

        return $orcamento;
    }

    /** @return array{clientId:int, clientRepresentativeId:int, deliveryClientId:int, createdBy:int, produtos: array<string,int>} */
    private function ids(array $produtos = ['P1' => 811]): array
    {
        return [
            'clientId' => 12,
            'clientRepresentativeId' => 34,
            'deliveryClientId' => 12,
            'createdBy' => 7,
            'produtos' => $produtos,
        ];
    }

    public function test_preco_vai_em_centavos_e_nao_em_reais(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]]), $this->ids());

        // R$ 12,50 → 1250. Se algum dia sair 12 ou 12.5, o pedido nasce com doze
        // centavos e meio e o Portal aceita sem reclamar.
        $this->assertSame(1250, $corpo['products'][0]['unitPrice']);
        $this->assertIsInt($corpo['products'][0]['unitPrice']);
    }

    public function test_item_com_ipi_vai_sem_o_imposto_por_padrao(): void
    {
        config()->set('portal.preco_com_ipi', false);

        $corpo = $this->payload()->montar(
            $this->orcamento([['valor_unitario' => 10.325, 'calcula_ipi' => true]]),
            $this->ids()
        );

        $this->assertSame(1000, $corpo['products'][0]['unitPrice']);
    }

    public function test_interruptor_de_ipi_manda_o_valor_cheio(): void
    {
        config()->set('portal.preco_com_ipi', true);

        $corpo = $this->payload()->montar(
            $this->orcamento([['valor_unitario' => 10.325, 'calcula_ipi' => true]]),
            $this->ids()
        );

        $this->assertSame(1033, $corpo['products'][0]['unitPrice']);
    }

    public function test_etiqueta_nunca_tem_ipi_removido_mesmo_em_modo_produto(): void
    {
        config()->set('portal.preco_com_ipi', false);

        $corpo = $this->payload()->montar(
            $this->orcamento([[
                'tipo_item' => 'etiqueta',
                'cod_produto' => 'E9',
                'valor_unitario' => 10.325,
                'calcula_ipi' => true,
            ]]),
            $this->ids(['E9' => 500])
        );

        $this->assertSame(1033, $corpo['products'][0]['unitPrice']);
    }

    public function test_modo_servico_nao_remove_ipi(): void
    {
        config()->set('portal.preco_com_ipi', false);

        $corpo = $this->payload()->montar(
            $this->orcamento(
                [['valor_unitario' => 10.325, 'calcula_ipi' => true]],
                ['tipo_produto_servico' => 'servico']
            ),
            $this->ids()
        );

        $this->assertSame(1033, $corpo['products'][0]['unitPrice']);
    }

    public function test_quantidade_fracionada_e_recusada_antes_de_chamar_a_api(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/quantidade inteira/');

        $this->payload()->montar($this->orcamento([['quantidade' => 2.5]]), $this->ids());
    }

    public function test_quantidade_menor_que_um_e_recusada(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);

        $this->payload()->montar($this->orcamento([['quantidade' => 0.5]]), $this->ids());
    }

    public function test_item_sem_codigo_de_produto_explica_o_caso_da_etiqueta(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/etiqueta/i');

        $this->payload()->montar(
            $this->orcamento([['cod_produto' => null, 'tipo_item' => 'etiqueta']]),
            $this->ids()
        );
    }

    public function test_produto_fora_do_de_para_e_recusado(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/não foi encontrado no Portal/');

        $this->payload()->montar($this->orcamento([['cod_produto' => 'DESCONHECIDO']]), $this->ids());
    }

    public function test_produto_repetido_e_recusado_com_saida_pratica(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/some as quantidades/');

        $this->payload()->montar($this->orcamento([[], []]), $this->ids());
    }

    public function test_frete_ausente_e_omitido_e_nao_vai_como_nulo(): void
    {
        // O corpo é validado de forma estrita do outro lado: campo desconhecido OU
        // nulo indevido responde 400.
        $corpo = $this->payload()->montar(
            $this->orcamento([[]], ['tipo_frete' => null]),
            $this->ids()
        );

        $this->assertArrayNotHasKey('shippingType', $corpo);
        $this->assertArrayNotHasKey('orderNote', $corpo);
    }

    public function test_frete_preenchido_vai_no_payload(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]], ['tipo_frete' => 'FOB']), $this->ids());

        $this->assertSame('FOB', $corpo['shippingType']);
    }

    public function test_referencia_liga_o_pedido_ao_orcamento(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]]), $this->ids());

        $this->assertSame('ORC-77', $corpo['orderReference']);
        $this->assertSame('900', $corpo['products'][0]['orderLine']);
    }

    public function test_ids_resolvidos_entram_como_vieram(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]]), $this->ids());

        $this->assertSame(12, $corpo['clientId']);
        $this->assertSame(34, $corpo['clientRepresentativeId']);
        $this->assertSame(12, $corpo['deliveryClientId']);
        $this->assertSame(7, $corpo['createdBy']);
        $this->assertSame(10, $corpo['products'][0]['quantity']);
    }
}
