<?php

namespace App\Services\Orcamento;

/**
 * Matemática de IPI (3,25% embutido no preço, regra fiscal real da Autopel) e resumo
 * de totais do orçamento. IPI nunca é armazenado — sempre recalculado a partir do
 * valor_unitario (que já vem com IPI embutido quando aplicável) e do flag calcula_ipi.
 */
class OrcamentoCalculoService
{
    private const IPI_ALIQUOTA = 0.0325;

    public function baseSemIpi(float $valorComIpi): float
    {
        return round($valorComIpi / (1 + self::IPI_ALIQUOTA), 4);
    }

    public function valorIpi(float $valorComIpi): float
    {
        return round($valorComIpi - $this->baseSemIpi($valorComIpi), 4);
    }

    /**
     * @param  array{tipo_item?: ?string, calcula_ipi?: bool|int|null}  $item
     */
    public function itemParticipaIpi(string $tipoProdutoServico, array $item): bool
    {
        return $tipoProdutoServico === 'produto'
            && ($item['tipo_item'] ?? null) !== 'etiqueta'
            && (bool) ($item['calcula_ipi'] ?? true);
    }

    /**
     * Base usada pra comparar com preco_tabela e decidir o nível de aprovação — remove
     * o IPI embutido quando o item participa de IPI, senão usa o valor como está.
     * Sem essa normalização, orçamentos em modo "produto" teriam desconto% inflado
     * artificialmente pelos 3,25% de IPI embutidos no preço digitado.
     *
     * @param  array{valor_unitario: float|string, tipo_item?: ?string, calcula_ipi?: bool|int|null}  $item
     */
    public function baseParaDesconto(string $tipoProdutoServico, array $item): float
    {
        $valorUnitario = (float) $item['valor_unitario'];

        return $this->itemParticipaIpi($tipoProdutoServico, $item)
            ? $this->baseSemIpi($valorUnitario)
            : $valorUnitario;
    }

    /**
     * @param  array{valor_unitario: float|string, valor_total: float|string, tipo_item?: ?string, calcula_ipi?: bool|int|null}  $item
     * @return array{tipoItem: ?string, valorUnitarioSemIpi: float, valorUnitarioComIpi: float, valorTotalSemIpi: float, valorTotalComIpi: float, valorIpiTotal: float, participaIpi: bool}
     */
    public function calcularItem(array $item, string $tipoProdutoServico): array
    {
        $valorUnitarioComIpi = (float) $item['valor_unitario'];
        $valorTotalComIpi = (float) $item['valor_total'];
        $participaIpi = $this->itemParticipaIpi($tipoProdutoServico, $item);

        $valorUnitarioSemIpi = $participaIpi ? $this->baseSemIpi($valorUnitarioComIpi) : $valorUnitarioComIpi;
        $valorTotalSemIpi = $participaIpi ? $this->baseSemIpi($valorTotalComIpi) : $valorTotalComIpi;

        return [
            'tipoItem' => $item['tipo_item'] ?? null,
            'valorUnitarioSemIpi' => $valorUnitarioSemIpi,
            'valorUnitarioComIpi' => $valorUnitarioComIpi,
            'valorTotalSemIpi' => $valorTotalSemIpi,
            'valorTotalComIpi' => $valorTotalComIpi,
            'valorIpiTotal' => round($valorTotalComIpi - $valorTotalSemIpi, 2),
            'participaIpi' => $participaIpi,
        ];
    }

    /**
     * Totais do documento. As três chaves SEMPRE fecham: subtotalSemIpi + valorIpi === totalGeral.
     *
     * ⚠️ Os totais falam de IMPOSTO, nunca de categoria de produto. A versão anterior devolvia
     * `subtotalProdutosSemIpi` / `subtotalProdutosComIpi` / `subtotalEtiquetas`, e isso confundia
     * de duas formas ao mesmo tempo (orçamento 2110, relatado pelo beta): num orçamento de
     * Serviço, "Subtotal s/ IPI" era só o nome do balde PRODUTOS, então dois itens de etiqueta
     * apareciam em linhas diferentes sem que houvesse IPI nenhum em jogo; e, quando havia IPI,
     * `subtotalProdutos + subtotalEtiquetas` NÃO batia com o total. Agora bate por construção.
     *
     * Etiqueta nunca participa de IPI (itemParticipaIpi), então o valorTotalSemIpi dela já é o
     * valor de face — somar todo mundo por essa chave é o que torna a etiqueta um caso comum
     * em vez de um balde à parte.
     *
     * @param  iterable<array{valorTotalSemIpi: float, valorTotalComIpi: float}>  $itensCalculados  resultado de calcularItem(), um por item
     * @return array{subtotalSemIpi: float, valorIpi: float, totalGeral: float}
     */
    public function resumo(iterable $itensCalculados): array
    {
        $subtotalSemIpi = 0.0;
        $totalGeral = 0.0;

        foreach ($itensCalculados as $item) {
            $subtotalSemIpi += $item['valorTotalSemIpi'];
            $totalGeral += $item['valorTotalComIpi'];
        }

        // Arredonda ANTES de subtrair: é o que garante o fechamento exato em centavos.
        // Calcular o IPI sobre os valores cheios e arredondar depois deixaria sobra de 1 centavo.
        $subtotalSemIpi = round($subtotalSemIpi, 2);
        $totalGeral = round($totalGeral, 2);

        return [
            'subtotalSemIpi' => $subtotalSemIpi,
            'valorIpi' => round($totalGeral - $subtotalSemIpi, 2),
            'totalGeral' => $totalGeral,
        ];
    }
}
