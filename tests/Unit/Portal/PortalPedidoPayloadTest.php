<?php

namespace Tests\Unit\Portal;

use App\Models\Cliente;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\User;
use App\Models\VendedorPerfil;
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
 * Desde 2026-09-25 o payload é 100% CHAVE DE NEGÓCIO do TOTVS (`sellerCode`,
 * `clientCode`+`clientStore`, `productCode`) — o Portal resolve os ids dele. Não há
 * mais de-para nem `$ids`.
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

    /**
     * @param  array<int, array<string, mixed>>  $itens
     */
    private function orcamento(array $itens, array $atributos = [], array $opcoes = []): Orcamento
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

        // Cliente do TOTVS vinculado (o Portal casa por code+store), salvo quando o teste
        // pede o caso "sem cliente".
        if (! ($opcoes['semCliente'] ?? false)) {
            $orcamento->setRelation('cliente', (new Cliente())->forceFill([
                'cod_cliente' => $opcoes['cod_cliente'] ?? '001122',
                'loja' => $opcoes['loja'] ?? '01',
            ]));
        } else {
            $orcamento->setRelation('cliente', null);
        }

        $perfil = ($opcoes['semVendedor'] ?? false)
            ? null
            : (new VendedorPerfil())->forceFill(['cod_vendedor' => $opcoes['cod_vendedor'] ?? '000123']);

        $user = (new User())->forceFill(['id' => 5, 'name' => 'Vend']);
        $user->setRelation('vendedorPerfil', $perfil);
        $orcamento->setRelation('user', $user);

        return $orcamento;
    }

    public function test_preco_vai_em_centavos_e_nao_em_reais(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]]));

        // R$ 12,50 → 1250. Se algum dia sair 12 ou 12.5, o pedido nasce com doze
        // centavos e meio e o Portal aceita sem reclamar.
        $this->assertSame(1250, $corpo['products'][0]['unitPrice']);
        $this->assertIsInt($corpo['products'][0]['unitPrice']);
    }

    /**
     * O interruptor DESLIGADO continua coberto: a resposta do time do Portal pode
     * mudar (se um dia eles aplicarem o `ipi_rate` do produto por cima), e a volta
     * precisa ser um `.env`, não código novo.
     */
    public function test_interruptor_desligado_manda_o_valor_sem_ipi(): void
    {
        config()->set('portal.preco_com_ipi', false);

        $corpo = $this->payload()->montar(
            $this->orcamento([['valor_unitario' => 10.325, 'calcula_ipi' => true]])
        );

        $this->assertSame(1000, $corpo['products'][0]['unitPrice']);
    }

    /** ✅ O comportamento de produção: "o preço deve vir já com IPI" (14/09/2026). */
    public function test_preco_vai_com_ipi_embutido_como_o_portal_pede(): void
    {
        config()->set('portal.preco_com_ipi', true);

        $corpo = $this->payload()->montar(
            $this->orcamento([['valor_unitario' => 10.325, 'calcula_ipi' => true]])
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
            ]])
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
            )
        );

        $this->assertSame(1033, $corpo['products'][0]['unitPrice']);
    }

    public function test_produto_vai_pelo_codigo_do_totvs_nao_por_id(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([['cod_produto' => 'PA0001']]));

        $this->assertSame('PA0001', $corpo['products'][0]['productCode']);
        $this->assertArrayNotHasKey('productId', $corpo['products'][0]);
    }

    public function test_tipo_de_nota_vai_como_string_do_enum(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]]));

        // Default do config: SALE (Venda Consumo). Nunca o inteiro 2 antigo.
        $this->assertSame('SALE', $corpo['products'][0]['invoiceType']);
    }

    public function test_chaves_de_negocio_vao_como_estao_no_orcamento(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]], [], [
            'cod_cliente' => '041626',
            'loja' => '0002',
            'cod_vendedor' => '010150',
        ]));

        $this->assertSame('010150', $corpo['sellerCode']);
        $this->assertSame('041626', $corpo['clientCode']);
        $this->assertSame('0002', $corpo['clientStore']);
        // deliveryClient* fica de fora: o CRM não modela endereço de entrega alternativo.
        $this->assertArrayNotHasKey('deliveryClientCode', $corpo);
    }

    public function test_orcamento_sem_cliente_vinculado_e_recusado(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/cliente do TOTVS/');

        $this->payload()->montar($this->orcamento([[]], [], ['semCliente' => true]));
    }

    public function test_usuario_sem_codigo_de_vendedor_e_recusado(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/código de vendedor/');

        $this->payload()->montar($this->orcamento([[]], [], ['semVendedor' => true]));
    }

    public function test_quantidade_fracionada_e_recusada_antes_de_chamar_a_api(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/quantidade inteira/');

        $this->payload()->montar($this->orcamento([['quantidade' => 2.5]]));
    }

    public function test_quantidade_menor_que_um_e_recusada(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);

        $this->payload()->montar($this->orcamento([['quantidade' => 0.5]]));
    }

    public function test_item_sem_codigo_de_produto_explica_o_caso_da_etiqueta(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/etiqueta/i');

        $this->payload()->montar(
            $this->orcamento([['cod_produto' => null, 'tipo_item' => 'etiqueta']])
        );
    }

    public function test_produto_repetido_e_recusado_com_saida_pratica(): void
    {
        $this->expectException(PortalPedidoInvalidoException::class);
        $this->expectExceptionMessageMatches('/some as quantidades/');

        $this->payload()->montar($this->orcamento([[], []]));
    }

    public function test_frete_ausente_e_omitido_e_nao_vai_como_nulo(): void
    {
        // O corpo é validado de forma estrita do outro lado: campo desconhecido OU
        // nulo indevido responde 400.
        $corpo = $this->payload()->montar(
            $this->orcamento([[]], ['tipo_frete' => null])
        );

        $this->assertArrayNotHasKey('shippingType', $corpo);
        $this->assertArrayNotHasKey('orderNote', $corpo);
    }

    public function test_frete_preenchido_vai_no_payload(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]], ['tipo_frete' => 'FOB']));

        $this->assertSame('FOB', $corpo['shippingType']);
    }

    public function test_referencia_liga_o_pedido_ao_orcamento(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([[]]));

        $this->assertSame('ORC-77', $corpo['orderReference']);
        $this->assertSame('900', $corpo['products'][0]['orderLine']);
    }

    public function test_quantidade_inteira_entra_como_int(): void
    {
        $corpo = $this->payload()->montar($this->orcamento([['quantidade' => 10]]));

        $this->assertSame(10, $corpo['products'][0]['quantity']);
    }
}
