<?php

namespace App\Services\Portal;

use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Services\Orcamento\OrcamentoCalculoService;

/**
 * Traduz um orçamento aprovado no corpo do `POST /v1/api/orders` do Portal Autopel.
 *
 * É o ÚNICO lugar que conhece o formato do payload (Regra de ouro nº 8), e monta tudo
 * a partir do PRÓPRIO orçamento — sem de-para nenhum.
 *
 * 🚨 Desde 2026-09-25 a API aceita as CHAVES DE NEGÓCIO do TOTVS e resolve os ids dela
 * do lado deles: `sellerCode` (cod_vendedor), `clientCode`+`clientStore` (cod_cliente +
 * loja), `productCode` (cod_produto). Não existe mais tabela `portal_*`, nem tradução de
 * id, nem guarda de CNPJ do nosso lado — a resolução (e o risco do par code+store que
 * aponta para empresa diferente, §4.4) passou a ser deles, por decisão do Tony em
 * 2026-09-25. Mandamos o mesmo código que o TOTVS usa; a conferência é lá.
 *
 * Ver docs/integracao-portal-pedidos.md §3 (as armadilhas que continuam nossas) e §5.1 (IPI).
 */
class PortalPedidoPayload
{
    public function __construct(
        private readonly OrcamentoCalculoService $calculo,
    ) {
    }

    /**
     * `$dataEntrega` (Y-m-d) e `$transportadora` NÃO moram no orçamento: são decididos
     * na hora de transformar em pedido, no modal — a data é o que o cliente pediu hoje,
     * não o que estava no papel semanas atrás.
     *
     * @return array<string, mixed>
     */
    public function montar(Orcamento $orcamento, string $dataEntrega, ?string $transportadora = null): array
    {
        $itens = $orcamento->itens;

        if ($itens->isEmpty()) {
            throw new PortalPedidoInvalidoException('O orçamento não tem itens.');
        }

        $cliente = $orcamento->cliente;

        /*
         * ⚠️ Orçamento de LEAD não tem cliente do TOTVS vinculado, e sem `clientCode`+
         * `clientStore` o Portal não resolve o cliente. Barrar aqui com mensagem clara é
         * melhor que um 400 cru — o caminho é concluir o cadastro do cliente primeiro.
         */
        if ($cliente === null) {
            throw new PortalPedidoInvalidoException(
                'Este orçamento não está vinculado a um cliente do TOTVS. '.
                'Orçamento de lead precisa que o cadastro do cliente seja concluído antes de virar pedido.'
            );
        }

        $sellerCode = trim((string) $orcamento->user?->vendedorPerfil?->cod_vendedor);

        /*
         * ⚠️ O pedido fica REGISTRADO no vendedor (`sellerCode`), e o Portal resolve o
         * representante do cliente a partir dele. Usuário sem código de vendedor (ex.:
         * assistente) não pode responder por uma venda — recusa com mensagem, não manda vazio.
         */
        if ($sellerCode === '') {
            throw new PortalPedidoInvalidoException(
                'O usuário que criou este orçamento não tem código de vendedor, '.
                'então o pedido não pode ser registrado no Portal em nome dele.'
            );
        }

        $products = $itens
            ->map(fn (OrcamentoItem $item) => $this->montarItem($item, $orcamento))
            ->values()
            ->all();

        $this->garantirProdutoUnico($products);
        $this->garantirTiposDeNotaCompativeis($products);

        /*
         * ⚠️ Desde a versão de 2026-09-30 da API o frete é OBRIGATÓRIO: o pedido nasce
         * direto na fila de aprovação, e no CIF é o ERP que escolhe a transportadora na
         * criação. Orçamento antigo pode ter o campo vazio (a coluna é nullable).
         */
        $frete = $orcamento->tipo_frete;

        if (! in_array($frete, ['CIF', 'FOB'], true)) {
            throw new PortalPedidoInvalidoException(
                'O orçamento não tem o tipo de frete (CIF ou FOB) definido. Edite o orçamento e escolha o frete antes de transformar em pedido.'
            );
        }

        $corpo = [
            'sellerCode' => $sellerCode,
            'clientCode' => trim((string) $cliente->cod_cliente),
            'clientStore' => trim((string) $cliente->loja),
            'shippingType' => $frete,
            // É um PEDIDO de data, não uma definição: quem valida é o ERP, e a data que
            // vale é a que volta na resposta (ver GeradorDePedidoNoPortal::enviar()).
            'deliveryTime' => $dataEntrega,
            'products' => $products,
        ];

        /*
         * ⚠️ `carrierCode` só no FOB, e NUNCA no CIF — ali quem escolhe é o ERP, e a API
         * documenta que não se envia. Mandar "por via das dúvidas" é pedir um 400.
         */
        if ($frete === 'FOB') {
            $transportadora = trim((string) $transportadora);

            if ($transportadora === '') {
                throw new PortalPedidoInvalidoException(
                    'Frete FOB exige o código da transportadora que o cliente contratou.'
                );
            }

            $corpo['carrierCode'] = $transportadora;
        }

        /*
         * ⚠️ A API valida o corpo de forma ESTRITA: qualquer campo fora dos documentados
         * responde 400 em vez de ser ignorado. Por isso campo opcional vazio é OMITIDO,
         * nunca mandado como null. O CRM não modela endereço de entrega alternativo, então
         * `deliveryClient*` fica de fora — o Portal entrega no próprio cliente.
         */
        $corpo['orderReference'] = 'ORC-'.$orcamento->id;

        if (filled($orcamento->observacoes)) {
            $corpo['orderNote'] = mb_substr((string) $orcamento->observacoes, 0, 1000);
        }

        return $corpo;
    }

    /**
     * @return array<string, mixed>
     */
    private function montarItem(OrcamentoItem $item, Orcamento $orcamento): array
    {
        $codigo = trim((string) $item->cod_produto);

        /*
         * ⚠️ Item de ETIQUETA nasce sem código de propósito — é precificado pela
         * calculadora, não sai do catálogo. Não é dado faltando: é produto que não
         * existe no Portal, e não há `productCode` que se possa inventar.
         */
        if ($codigo === '') {
            throw new PortalPedidoInvalidoException(
                "O item \"{$item->descricao}\" não tem código de produto e por isso não pode virar pedido. ".
                'Itens de etiqueta precificados pela calculadora precisam de um produto cadastrado no Portal.'
            );
        }

        return [
            'productCode' => $codigo,
            'quantity' => $this->quantidadeInteira($item),
            'unitPrice' => $this->precoEmCentavos($item, $orcamento),
            'invoiceType' => (string) config('portal.tipo_nota_padrao'),
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
     * ⚠️ O IPI é decidido por `config('portal.preco_com_ipi')`, hoje `true` (manda o
     * valor COM IPI embutido, como o Portal pediu e como o pedido 1129 confirmou). O
     * caminho SEM IPI continua aqui como interruptor caso eles passem a somar por cima.
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
     * ⚠️ O mesmo produto não pode aparecer duas vezes no pedido (409 do Portal).
     * Barrar aqui com uma mensagem útil é melhor que devolver o erro cru deles, porque
     * o vendedor precisa saber que a saída é somar as quantidades numa linha só.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    private function garantirProdutoUnico(array $products): void
    {
        $codigos = array_column($products, 'productCode');
        $repetidos = array_diff_assoc($codigos, array_unique($codigos));

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
        $tipos = array_unique(array_column($products, 'invoiceType'));

        $consumo = (string) config('portal.tipos_nota.venda_consumo');
        $revenda = (string) config('portal.tipos_nota.venda_revenda');

        if (in_array($consumo, $tipos, true) && in_array($revenda, $tipos, true)) {
            throw new PortalPedidoInvalidoException(
                'O pedido tem itens de Venda (Consumo) e Venda (Revenda) juntos. '.
                'O Portal exige dois pedidos separados nesse caso.'
            );
        }
    }
}
