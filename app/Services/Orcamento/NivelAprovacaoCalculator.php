<?php

namespace App\Services\Orcamento;

/**
 * Nível de aprovação é sempre derivado do maior desconto percentual entre os itens
 * do orçamento (valor_unitario vs. preco_tabela) — nunca confiado do cliente.
 * Regra de negócio real do legado: <10% auto-aprova, 10-15% supervisor, >15% diretor.
 */
class NivelAprovacaoCalculator
{
    /**
     * @param  iterable<array{valor_unitario: float|string, preco_tabela: float|string|null}>  $itens
     */
    public function calcular(iterable $itens): string
    {
        $maiorDesconto = 0.0;
        $itemSemPrecoTabela = false;

        foreach ($itens as $item) {
            $precoTabela = $item['preco_tabela'] ?? null;

            if ($precoTabela === null || (float) $precoTabela <= 0) {
                $itemSemPrecoTabela = true;

                continue;
            }

            $desconto = (1 - ((float) $item['valor_unitario'] / (float) $precoTabela)) * 100;
            $maiorDesconto = max($maiorDesconto, $desconto);
        }

        $nivel = match (true) {
            $maiorDesconto > 15 => 'diretor',
            $maiorDesconto >= 10 => 'supervisor',
            default => 'nenhum',
        };

        if ($itemSemPrecoTabela && $nivel === 'nenhum') {
            return 'supervisor';
        }

        return $nivel;
    }

    /**
     * @param  iterable<array{valor_unitario: float|string, preco_tabela: float|string|null}>  $itens
     */
    public function maiorDesconto(iterable $itens): float
    {
        $maiorDesconto = 0.0;

        foreach ($itens as $item) {
            $precoTabela = $item['preco_tabela'] ?? null;

            if ($precoTabela === null || (float) $precoTabela <= 0) {
                continue;
            }

            $desconto = (1 - ((float) $item['valor_unitario'] / (float) $precoTabela)) * 100;
            $maiorDesconto = max($maiorDesconto, $desconto);
        }

        return round($maiorDesconto, 2);
    }
}
