<?php

namespace App\Services\Potencial;

use InvalidArgumentException;

/**
 * As três famílias de produto que o Potencial da Carteira acompanha, e o de-para delas
 * para `produtos.categoria`.
 *
 * Fonte única (Regra de ouro nº 8): a chave, o rótulo e a categoria correspondente moram
 * aqui e só aqui. O front NÃO ganha cópia desta lista — o rótulo viaja no payload do
 * bloco. Foi exatamente uma cópia local de mapa de rótulos que fez a Carteira/Detalhes
 * exibir a string crua `pendente_totvs` quando um valor novo entrou no enum de status.
 *
 * ⚠️ `produtos.categoria` chega SEMPRE em maiúscula: `ImportProdutosLegado::categoriaOuNull()`
 * aplica `mb_strtoupper` justamente porque o legado grava em case misto (bobina/BOBINA).
 * Comparar em outro case aqui devolveria zero em silêncio.
 *
 * ⚠️ As demais categorias da base ficam DE FORA de propósito, e a maior delas é a maior
 * de todas: conferido no banco de dev em 05/09/2026, sobre os últimos 12 meses,
 * `SUPLY` são 74,8% das linhas de faturamento (sacola, papel A4, café, caneta — a linha
 * de suprimentos corporativos), contra 16,2% de ETIQUETA, 5,3% de BOBINA e 0,35% de TAG.
 * `SUPLY`/`VOLANTE`/`OUTROS` não são "resto não classificado": são outra linha de negócio,
 * e o Potencial não fala sobre elas.
 *
 * ⚠️ Sem filtro por `produtos.unidade`, ao contrário do legado, que exigia
 * `UN_PROD IN ('CX','RL')` para converter em caixas. Aqui contamos CLIENTES, não caixas —
 * e `unidade` é nula para boa parte do catálogo, então filtrar por ela descartaria venda
 * real sem deixar rastro.
 */
final class FamiliaProduto
{
    /** chave => [rótulo exibido, valor em `produtos.categoria`] */
    private const FAMILIAS = [
        'bobina' => ['Bobina', 'BOBINA'],
        'etiqueta' => ['Etiqueta', 'ETIQUETA'],
        'tag' => ['Tag de gôndola', 'TAG'],
    ];

    /** @return list<string> */
    public static function chaves(): array
    {
        return array_keys(self::FAMILIAS);
    }

    /**
     * Categorias aceitas em `produtos.categoria`, na ordem das chaves.
     *
     * @return list<string>
     */
    public static function categorias(): array
    {
        return array_map(fn (array $f) => $f[1], array_values(self::FAMILIAS));
    }

    public static function categoriaDe(string $familia): string
    {
        return self::FAMILIAS[self::garantir($familia)][1];
    }

    public static function rotuloDe(string $familia): string
    {
        return self::FAMILIAS[self::garantir($familia)][0];
    }

    /**
     * ⚠️ Estoura em vez de cair num default. A família chega de fora (CSV de pesos, linha
     * gravada em `potencial_pesos`) e escolhe QUAL CATEGORIA responde — um default
     * silencioso mostraria o número de outra família sob o rótulo certo. Mesma decisão de
     * `MetaRankingResolver::garantirTipo()`.
     */
    public static function garantir(string $familia): string
    {
        if (! isset(self::FAMILIAS[$familia])) {
            throw new InvalidArgumentException("Família de produto desconhecida: {$familia}.");
        }

        return $familia;
    }
}
