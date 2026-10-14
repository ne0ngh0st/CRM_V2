<?php

namespace App\Services\VisaoDiretor;

use Illuminate\Support\Str;

/**
 * Como a busca da Visão Diretor → Maiores por Segmento casa um texto: pelo COMEÇO DE
 * PALAVRA, não por "contém". "OBA" casa com "OBA HORTIFRUTI" e com "SUPER OBA", e não com
 * "SOROCOBA" nem "COBASI" — que é o que "contém" trazia, enterrando o que se procurava
 * (13/10/2026). Dígitos (4+) também casam pelo começo do CNPJ.
 *
 * Mora aqui porque são QUATRO lugares procurando a mesma coisa: nome/apelido da rede (PHP),
 * filiais e leads dentro da rede, clientes/leads da tabela e a ordem da linha expandida
 * (SQL). Regra de ouro nº 8: se cada um tivesse o seu "LIKE", divergiriam no primeiro ajuste.
 */
final class TermoDeBusca
{
    /** O que conta como separador antes de uma palavra no SQL (além do começo do texto). */
    private const SEPARADORES = [' ', '-', '/', '(', '.', '&', ','];

    /** A busca casa com este texto? (ignora maiúsculas e acentos, como a collation do MySQL) */
    public static function casa(?string $texto, string $busca): bool
    {
        $busca = self::normalizar($busca);
        if ($busca === '' || $texto === null) {
            return false;
        }

        return (bool) preg_match('/(^|[^A-Z0-9])'.preg_quote($busca, '/').'/', self::normalizar($texto));
    }

    /**
     * Condição SQL "alguma destas colunas tem uma palavra começando pela busca", e, com
     * `$colunaDigitos` e 4+ dígitos na busca, "ou o CNPJ começa por eles".
     *
     * @param  list<string>  $colunas
     * @return array{0: string, 1: list<string>}  [sql entre parênteses, bindings]
     */
    public static function sql(array $colunas, string $busca, ?string $colunaDigitos = null): array
    {
        $termo = addcslashes(trim($busca), '%_\\');
        $partes = [];
        $bindings = [];

        foreach ($colunas as $coluna) {
            $partes[] = "{$coluna} LIKE ?";
            $bindings[] = "{$termo}%";

            foreach (self::SEPARADORES as $sep) {
                $partes[] = "{$coluna} LIKE ?";
                $bindings[] = "%{$sep}{$termo}%";
            }
        }

        $digitos = preg_replace('/\D/', '', $busca);
        if ($colunaDigitos !== null && strlen($digitos) >= 4) {
            $partes[] = "{$colunaDigitos} LIKE ?";
            $bindings[] = "{$digitos}%";
        }

        return ['('.implode(' OR ', $partes).')', $bindings];
    }

    /**
     * A mesma regra, para uma coluna já normalizada por `expressaoNormalizada()`: o texto
     * guardado tem espaço no começo e cada separador virou UM espaço, então "começa uma
     * palavra" é um único `LIKE '% termo%'` — e não 8 por coluna. Para tabela grande lida
     * inteira (a busca da Visão Diretor atravessa os segmentos: 330 ms → 110 ms em 38 mil
     * linhas, dev 14/10/2026).
     *
     * O termo passa pela mesma troca de separadores: "raia-drogasil" casa com
     * "RAIA DROGASIL" e com "RAIA-DROGASIL".
     *
     * @return array{0: string, 1: list<string>}  [sql entre parênteses, bindings]
     */
    public static function sqlNormalizado(string $colunaTexto, string $busca, ?string $colunaDigitos = null): array
    {
        $termo = trim((string) preg_replace('/['.preg_quote(implode('', self::SEPARADORES), '/').']+/', ' ', $busca));
        $partes = [];
        $bindings = [];

        if ($termo !== '') {
            $partes[] = "{$colunaTexto} LIKE ?";
            $bindings[] = '% '.addcslashes($termo, '%_\\').'%';
        }

        $digitos = preg_replace('/\D/', '', $busca);
        if ($colunaDigitos !== null && strlen($digitos) >= 4) {
            $partes[] = "{$colunaDigitos} LIKE ?";
            $bindings[] = "{$digitos}%";
        }

        return [$partes ? '('.implode(' OR ', $partes).')' : '(1 = 0)', $bindings];
    }

    /**
     * Expressão SQL que grava o texto no formato de `sqlNormalizado()`: as colunas juntas,
     * um espaço no começo e cada sequência de separadores trocada por um espaço.
     *
     * @param  list<string>  $colunas
     */
    public static function expressaoNormalizada(array $colunas): string
    {
        // `-` primeiro na classe: em outro lugar vira intervalo.
        $classe = '[-'.implode('', array_diff(self::SEPARADORES, ['-'])).']+';

        return "CONCAT(' ', REGEXP_REPLACE(CONCAT_WS(' ', ".implode(', ', $colunas)."), '{$classe}', ' '))";
    }

    private static function normalizar(string $texto): string
    {
        return mb_strtoupper(Str::ascii(trim($texto)));
    }
}
