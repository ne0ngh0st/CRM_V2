<?php

namespace App\Services\Portal;

use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Services\Orcamento\OrcamentoCalculoService;

/**
 * Traduz um orçamento aprovado no corpo do `POST /v1/api/orders` do Portal Autopel.
 *
 * É o ÚNICO lugar que conhece o formato do payload (Regra de ouro nº 8). O job e o
 * controller passam os ids já resolvidos e recebem um array pronto — assim toda a
 * matemática perigosa fica testável sem tocar em HTTP nem em banco.
 *
 * Ver docs/integracao-portal-pedidos.md §3 (as três armadilhas) e §5.1 (IPI).
 */
class PortalPedidoPayload
{
    public function __construct(
        private readonly OrcamentoCalculoService $calculo,
    ) {
    }

    /**
     * @param  array{clientId:int, clientRepresentativeId:int, deliveryClientId:int, createdBy:int, produtos: array<string,int>}  $ids
     *                                                                                                                                 `produtos` é um mapa cod_produto => id do produto no Portal.
     * @return array<string, mixed>
     */
    public function montar(Orcamento $orcamento, array $ids): array
    {
        $itens = $orcamento->itens;

        if ($itens->isEmpty()) {
            throw new PortalPedidoInvalidoException('O orçamento não tem itens.');
        }

        $products = $itens
            ->map(fn (OrcamentoItem $item) => $this->montarItem($item, $orcamento, $ids['produtos']))
            ->values()
            ->all();

        $this->garantirProdutoUnico($products);
        $this->garantirTiposDeNotaCompativeis($products);

        $corpo = [
            'clientId' => $ids['clientId'],
            'clientRepresentativeId' => $ids['clientRepresentativeId'],
            'deliveryClientId' => $ids['deliveryClientId'],
            'createdBy' => $ids['createdBy'],
            'products' => $products,
        ];

        /*
         * ⚠️ A API valida o corpo de forma ESTRITA: qualquer campo fora dos documentados
         * responde 400 em vez de ser ignorado. Por isso campo opcional vazio é OMITIDO,
         * nunca mandado como null.
         */
        if (in_array($orcamento->tipo_frete, ['CIF', 'FOB'], true)) {
            $corpo['shippingType'] = $orcamento->tipo_frete;
        }

        $corpo['orderReference'] = 'ORC-'.$orcamento->id;

        if (filled($orcamento->observacoes)) {
            $corpo['orderNote'] = mb_substr((string) $orcamento->observacoes, 0, 1000);
        }

        return $corpo;
    }

    /**
     * @param  array<string,int>  $produtosPortal
     * @return array<string, mixed>
     */
    private function montarItem(OrcamentoItem $item, Orcamento $orcamento, array $produtosPortal): array
    {
        $codigo = trim((string) $item->cod_produto);

        /*
         * ⚠️ Item de ETIQUETA nasce sem código de propósito — é precificado pela
         * calculadora, não sai do catálogo. Não é dado faltando: é produto que não
         * existe no Portal, e não há `productId` que se possa inventar.
         */
        if ($codigo === '') {
            throw new PortalPedidoInvalidoException(
                "O item \"{$item->descricao}\" não tem código de produto e por isso não pode virar pedido. ".
                'Itens de etiqueta precificados pela calculadora precisam de um produto cadastrado no Portal.'
            );
        }

        if (! isset($produtosPortal[$codigo])) {
            throw new PortalPedidoInvalidoException(
                "O produto {$codigo} (\"{$item->descricao}\") não foi encontrado no Portal."
            );
        }

        return [
            'productId' => $produtosPortal[$codigo],
            'quantity' => $this->quantidadeInteira($item),
            'unitPrice' => $this->precoEmCentavos($item, $orcamento),
            'invoiceTypeId' => (int) config('portal.tipo_nota_padrao'),
            'orderLine' => (string) $item->id,
        ];
    }

    /**
     * ⚠️ A API exige INTEIRO ≥ 1 e recusa fracionado com 400. O CRM aceita
     * `decimal(12,2)` com mínimo 0,01, então a divergência é real e precisa ser
     * barrada aqui — arredondar por conta própria mudaria o que o cliente pediu.
     */
    private function quantidadeInteira(OrcamentoItem $item): int
    {
        $quantidade = (float) $item->quantidade;

        if ($quantidade < 1 || fmod($quantidade, 1.0) !== 0.0) {
            throw new PortalPedidoInvalidoException(
                "O item \"{$item->descricao}\" tem quantidade {$item->quantidade}. ".
                'O Portal só aceita quantidade inteira e maior que zero.'
            );
        }

        return (int) $quantidade;
    }

    /**
     * 🚨 `unitPrice` É EM CENTAVOS, e mandar reais NÃO DÁ ERRO — `12.5` grava doze
     * centavos e meio, e o `201` volta normal. É a falha mais silenciosa desta
     * integração, apontada pela própria documentação deles.
     *
     * ⚠️ O IPI é decidido por `config('portal.preco_com_ipi')`, e o default manda SEM
     * IPI. Não é chute: `autopel_sic.products` guarda `ipi`/`ipi_rate`/`ncm`, então o
     * Portal calcula o imposto sozinho. Continua sendo hipótese até a confirmação do
     * Marcelo — por isso é um interruptor, e não uma regra espalhada.
     */
    private function precoEmCentavos(OrcamentoItem $item, Orcamento $orcamento): int
    {
        $valor = (float) $item->valor_unitario;

        $participaIpi = $this->calculo->itemParticipaIpi($orcamento->tipo_produto_servico, [
            'tipo_item' => $item->tipo_item,
            'calcula_ipi' => $item->calcula_ipi,
        ]);

        if ($participaIpi && ! config('portal.preco_com_ipi')) {
            $valor = $this->calculo->baseSemIpi($valor);
        }

        $centavos = (int) round($valor * 100);

        if ($centavos < 1) {
            throw new PortalPedidoInvalidoException(
                "O item \"{$item->descricao}\" ficaria com valor unitário de R$ 0,00 no pedido."
            );
        }

        return $centavos;
    }

    /**
     * ⚠️ O mesmo `productId` não pode aparecer duas vezes no pedido (409 do Portal).
     * Barrar aqui com uma mensagem útil é melhor que devolver o erro cru deles, porque
     * o vendedor precisa saber que a saída é somar as quantidades numa linha só.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    private function garantirProdutoUnico(array $products): void
    {
        $ids = array_column($products, 'productId');
        $repetidos = array_diff_assoc($ids, array_unique($ids));

        if ($repetidos !== []) {
            throw new PortalPedidoInvalidoException(
                'O orçamento tem o mesmo produto em mais de uma linha. '.
                'O Portal não aceita produto repetido — some as quantidades numa linha só.'
            );
        }
    }

    /**
     * ⚠️ Um pedido não aceita itens de Venda (Consumo) e Venda (Revenda) ao mesmo
     * tempo. Hoje o CRM manda um tipo só para todos os itens, então isto nunca
     * dispara — existe porque no dia em que houver tipo de nota POR ITEM, a regra tem
     * que estar do nosso lado e não descoberta por um 409.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    private function garantirTiposDeNotaCompativeis(array $products): void
    {
        $tipos = array_unique(array_column($products, 'invoiceTypeId'));

        $consumo = (int) config('portal.tipos_nota.venda_consumo');
        $revenda = (int) config('portal.tipos_nota.venda_revenda');

        if (in_array($consumo, $tipos, true) && in_array($revenda, $tipos, true)) {
            throw new PortalPedidoInvalidoException(
                'O pedido tem itens de Venda (Consumo) e Venda (Revenda) juntos. '.
                'O Portal exige dois pedidos separados nesse caso.'
            );
        }
    }
}
