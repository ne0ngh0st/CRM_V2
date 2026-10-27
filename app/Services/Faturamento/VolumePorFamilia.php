<?php

namespace App\Services\Faturamento;

/**
 * O que conta como "caixa" faturada, por família.
 *
 * Bobina: a quantidade da nota só vira caixa pelo que o CÓDIGO VENDIDO declara.
 * - Unidade CX: a quantidade já é a caixa. "CX C/30" no nome é o que vem dentro
 *   (30 unidades por caixa); dividir de novo erraria a conta que o diretor confere.
 * - Outra unidade, com "C/30", "CX/16" ou "CX6" no nome: caixas = quantidade ÷ esse número.
 * - Sem tamanho no nome: não entra em caixa. Fica em `unidades_sem_caixa`.
 *
 * Etiqueta e tag continuam na unidade da nota (CX ou RL): o nome não tem o mesmo
 * contrato de "caixa com N".
 *
 * ⚠️ Não mora em `FamiliaProduto`. Aquela classe conta CLIENTES do potencial e, de
 * propósito, não filtra unidade. Mudar lá faria o potencial descartar venda.
 */
final class VolumePorFamilia
{
    /** @var list<string> */
    public const FAMILIAS = ['bobina', 'etiqueta', 'tag'];

    /** @return list<string> */
    public static function familias(): array
    {
        return self::FAMILIAS;
    }

    public static function definicao(string $familia): string
    {
        return match ($familia) {
            'bobina' => 'Caixas faturadas de bobina, pelo código do produto na nota. Unidade CX: a quantidade já é a caixa (o "C/30" do nome é quantas unidades vêm dentro — não dividir). Outra unidade com "C/30", "CX C/30", "CX/16" ou "CX6" no nome: caixas = quantidade ÷ esse número. Código sem tamanho de caixa no nome não entra em caixas; vai em unidades_sem_tamanho_de_caixa. Não divida esse resto por 30. Não é valor em reais nem pedido em aberto.',
            'etiqueta' => 'Quantidade faturada de etiqueta em CX ou RL (a unidade da nota). Não é valor em reais.',
            'tag' => 'Quantidade faturada de tag de gôndola em CX ou RL (a unidade da nota). Não é valor em reais.',
            default => 'Quantidade faturada na unidade da nota. Não é valor em reais.',
        };
    }

    /**
     * Número que o nome do produto declara ("CX C/30", "CX/16 UN", "CX6").
     * NULL quando o catálogo não diz o tamanho. Constantes daqui, nunca entrada do usuário.
     */
    public static function fatorSql(string $alias = 'p'): string
    {
        $padrao = 'C[[:space:]]*/[[:space:]]*[0-9]+|CX[[:space:]]*/[[:space:]]*[0-9]+|CX[[:space:]]*[0-9]+';

        return "CAST(NULLIF(REGEXP_SUBSTR(REGEXP_SUBSTR({$alias}.descricao, '{$padrao}'), '[0-9]+'), '') AS UNSIGNED)";
    }

    /** Família da linha, ou NULL se ela não entra no volume. */
    public static function familiaSql(string $alias = 'p'): string
    {
        return "CASE
            WHEN {$alias}.categoria = 'BOBINA' AND ({$alias}.unidade IS NULL OR {$alias}.unidade <> 'KG') THEN 'bobina'
            WHEN {$alias}.categoria = 'ETIQUETA' AND {$alias}.unidade IN ('CX', 'RL') THEN 'etiqueta'
            WHEN {$alias}.categoria = 'TAG' AND {$alias}.unidade IN ('CX', 'RL') THEN 'tag'
        END";
    }

    /** Caixas da linha. NULL quando a linha não vira caixa. */
    public static function caixasSql(string $produto = 'p', string $nota = 'f'): string
    {
        $fator = self::fatorSql($produto);

        return "CASE
            WHEN {$produto}.categoria = 'BOBINA' AND {$produto}.unidade = 'CX' THEN {$nota}.quantidade
            WHEN {$produto}.categoria = 'BOBINA' AND {$fator} > 0 THEN {$nota}.quantidade / {$fator}
            WHEN {$produto}.categoria = 'ETIQUETA' AND {$produto}.unidade IN ('CX', 'RL') THEN {$nota}.quantidade
            WHEN {$produto}.categoria = 'TAG' AND {$produto}.unidade IN ('CX', 'RL') THEN {$nota}.quantidade
        END";
    }

    /**
     * Quantidade de bobina que não virou caixa porque o nome não declara o tamanho.
     * CX já é caixa; KG não é unidade de bobina.
     */
    public static function semCaixaSql(string $produto = 'p', string $nota = 'f'): string
    {
        $fator = self::fatorSql($produto);

        return "CASE
            WHEN {$produto}.categoria = 'BOBINA'
             AND ({$produto}.unidade IS NULL OR {$produto}.unidade NOT IN ('CX', 'KG'))
             AND ({$fator} IS NULL OR {$fator} = 0)
            THEN {$nota}.quantidade
        END";
    }
}
