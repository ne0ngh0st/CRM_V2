<?php

namespace App\Services\Totvs;

use DateTime;

/**
 * Regras de limpeza do dado que vem do TOTVS.
 *
 * Ficam aqui porque valem para os DOIS caminhos de importação que convivem hoje — o
 * `legado:import-*`, que lê o espelho do PALMA v1, e o `totvs:import-*`, que lê o
 * relatório direto. São a mesma origem por dois canais: se as regras fossem copiadas,
 * o mesmo cliente entraria diferente dependendo do caminho, e ninguém perceberia até a
 * aderência da Carteira zerar de novo (foi o que aconteceu em julho, quando o nome do
 * segmento era comparado com o código).
 */
class Normalizador
{
    public static function valorOuNull(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Código do TOTVS com zero-padding inconsistente: o MESMO segmento aparece como
     * "101" e "000101" dependendo do registro, e o mesmo vale para `GrpVendas`. Sem
     * normalizar, o join com `segmentos.codigo` / `grupos_cliente.codigo` não bate e o
     * cálculo de aderência dá zero sem erro nenhum.
     *
     * Só mexe em valor totalmente numérico: há loja "E001" na base, e `(int)` disso
     * daria 0.
     */
    public static function codigo(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        return ctype_digit($valor) ? (string) ((int) $valor) : $valor;
    }

    /**
     * Chave de comparação de cliente, imune a zero à esquerda.
     *
     * ⚠️ Só serve para COMPARAR, nunca para gravar. O espelho do v1 e o relatório do
     * TOTVS escrevem a mesma loja de formas diferentes — `001209` lá, `1209` aqui — e
     * o cadastro do cliente é idêntico nos dois. Medido sobre a base inteira: 7.976 dos
     * 92.163 clientes só casam depois de normalizar, e NENHUM caso é ambíguo.
     *
     * Sem isso, o upsert por (cod_cliente, loja) não encontra o cliente existente e
     * insere um segundo — foi exatamente o que aconteceu na primeira execução deste
     * import, que criou 8.846 duplicatas antes de ser revertida.
     *
     * `cod_cliente` entra junto por simetria, ainda que hoje ele nunca divirja: se um
     * dia divergir, o sintoma seria o mesmo e igualmente silencioso.
     */
    public static function chaveCliente(mixed $codCliente, mixed $loja): string
    {
        return self::semZeroAEsquerda($codCliente).'|'.self::semZeroAEsquerda($loja);
    }

    private static function semZeroAEsquerda(mixed $valor): string
    {
        $valor = trim((string) $valor);

        // Loja pode ser "E001"/"X001" na base real — não é número, fica como está.
        if (! ctype_digit($valor)) {
            return $valor;
        }

        return ltrim($valor, '0') ?: '0';
    }

    /** O TOTVS usa "." para e-mail vazio em alguns cadastros. */
    public static function email(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return ($valor === '' || $valor === '.') ? null : $valor;
    }

    /** O DDD vem com zero à esquerda ("000031" em vez de "31"). */
    public static function telefone(mixed $ddd, mixed $telefone): ?string
    {
        $ddd = ltrim(trim((string) $ddd), '0');
        $telefone = trim((string) $telefone);

        if ($telefone === '') {
            return null;
        }

        return $ddd !== '' ? "({$ddd}) {$telefone}" : $telefone;
    }

    /** CNPJ/CPF chega só com dígitos; a base guarda formatado. */
    public static function documento(mixed $bruto): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $bruto);

        if ($digitos === '') {
            return null;
        }

        if (strlen($digitos) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digitos);
        }

        if (strlen($digitos) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digitos);
        }

        return $digitos;
    }

    /**
     * Data brasileira do relatório (dd/mm/aaaa, às vezes com hora colada) para Y-m-d.
     * Devolve null no que não for data — o TOTVS deixa o campo em branco e também
     * escreve "  /  /    " em alguns relatórios.
     */
    /**
     * ⚠️ "01/01/1900" é o EPOCH SENTINEL do TOTVS para "sem data", não uma data real —
     * conferido em `DATA_PCP` do relatório 232: 100% das 22.226 linhas trazem esse valor
     * exato quando o campo não foi preenchido, nunca uma data de fato próxima de 1900.
     * Sem este guard, `DateTime::createFromFormat` parseia normalmente e a coluna grava
     * "1900-01-01" como se fosse PCP real — o tipo de erro que só aparece na tela, nunca
     * num teste que gera data com Faker.
     */
    private const EPOCH_SENTINEL = '01/01/1900';

    public static function data(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '' || str_starts_with($valor, self::EPOCH_SENTINEL)) {
            return null;
        }

        $data = DateTime::createFromFormat('d/m/Y', substr($valor, 0, 10));

        return $data ? $data->format('Y-m-d') : null;
    }

    /**
     * PESO_LIQ do relatório do TOTVS é o peso UNITÁRIO do produto (confirmado sobre a
     * base real: nenhum produto distinto tem mais de um valor). Zero vira null de
     * propósito: mais da metade das linhas vem com "0,00", que significa "o TOTVS não
     * informou" — gravar 0 faria a tela exibir "0,000 kg" como se fosse peso medido.
     */
    public static function pesoOuNull(mixed $valor): ?float
    {
        $peso = self::numero($valor);

        return $peso > 0 ? $peso : null;
    }

    /**
     * Número do relatório: vem no formato brasileiro ("1.234,56"). `(float)` direto
     * pararia no primeiro ponto e leria 1,00 — erro que só aparece em valor de milhar,
     * ou seja, exatamente nos registros que mais pesam na soma.
     */
    public static function numero(mixed $valor): float
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return 0.0;
        }

        if (str_contains($valor, ',')) {
            $valor = str_replace(['.', ','], ['', '.'], $valor);
        }

        return (float) $valor;
    }
}
