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
    /**
     * Texto do relatório com a codificação consertada.
     *
     * ⚠️ O item 3 do docblock do `LeitorRelatorio` dizia que os relatórios são "UTF-8,
     * não cp1252", conferido em DOIS dos doze arquivos. Isso vale para o arquivo INTEIRO
     * e não valia para todo CAMPO: em 2026-09-08 a rodada manual de produção morreu com
     * `1366 Incorrect string value: '\xA0'` ao gravar `grupos_cliente.nome`, porque o
     * `199 - ULTIMO FATURAMENTO` trazia 210 valores com um NBSP de cp1252 (byte `A0`
     * solto, sem o `C2` que o UTF-8 exige) — alguém colou o texto de uma planilha no
     * cadastro do TOTVS. O arquivo é ASCII em 99,99%, então conferir o encoding do
     * arquivo passava; quem reprovava era o MySQL, no meio do import.
     *
     * ⚠️ A CONVERSÃO É CIRÚRGICA, byte a byte, e não pode virar um
     * `mb_convert_encoding($v, 'UTF-8', 'Windows-1252')` no campo inteiro: os outros
     * relatórios TÊM UTF-8 legítimo (8.587 sequências no cadastro de clientes — `Ç`,
     * travessão), e converter em bloco um campo misto transformaria `AÇO` em `AÃ‡O`.
     * Aqui cada sequência UTF-8 válida passa intacta e só o byte solto é reinterpretado
     * como cp1252 — assim um `\xC7` futuro vira `Ç`, em vez do `?` que uma substituição
     * cega produziria.
     *
     * ⚠️ NBSP VIRA ESPAÇO COMUM, inclusive o que já estava em UTF-8 válido. Sem isso
     * `POSTOS<NBSP>LAURINDAO` e `POSTOS LAURINDAO` seriam dois grupos distintos na tela,
     * com a diferença invisível — e o `trim()` do PHP também não morde NBSP, então o
     * padding de largura fixa do TOTVS sobreviveria num campo e não no vizinho.
     */
    public static function textoUtf8(mixed $valor): string
    {
        $valor = (string) $valor;

        if ($valor === '') {
            return '';
        }

        // Caminho rápido: quase todo campo já é válido, e `mb_check_encoding` é ordens de
        // grandeza mais barato que a regex abaixo. São ~6 milhões de campos por rodada —
        // pagar a regex em todos custaria minutos à toa.
        if (! mb_check_encoding($valor, 'UTF-8')) {
            $valor = self::recuperarBytesInvalidos($valor);
        }

        return str_replace("\u{A0}", ' ', $valor);
    }

    /**
     * Reinterpreta como cp1252 apenas os bytes que não formam sequência UTF-8 válida.
     *
     * A alternância abaixo é a gramática do UTF-8 (RFC 3629): o que casar em qualquer
     * ramo antes do `(.)` final é sequência legítima e sai como entrou. `(.)` só alcança
     * o que sobrou, byte a byte — e `/s` está lá para que ele também pegue `\n`.
     */
    private static function recuperarBytesInvalidos(string $valor): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x7F]+'
            .'|[\xC2-\xDF][\x80-\xBF]'
            .'|\xE0[\xA0-\xBF][\x80-\xBF]'
            .'|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            .'|\xED[\x80-\x9F][\x80-\xBF]'
            .'|\xF0[\x90-\xBF][\x80-\xBF]{2}'
            .'|[\xF1-\xF3][\x80-\xBF]{3}'
            .'|\xF4[\x80-\x8F][\x80-\xBF]{2}'
            .'|(.)/s',
            fn (array $m): string => ($m[1] ?? '') !== ''
                ? (string) mb_convert_encoding($m[1], 'UTF-8', 'Windows-1252')
                : $m[0],
            $valor
        );
    }

    public static function valorOuNull(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Sigla de UF para uma coluna `varchar(2)`.
     *
     * ⚠️ O que não tiver exatamente duas letras vira NULL, nunca é cortado: `EX`
     * (exterior) passa, mas um "SAO PAULO" truncado viraria `SA` e o mapa do BI passaria
     * a mentir em silêncio. Mesmo raciocínio do `normalizarUf` dos leads do site.
     */
    public static function uf(mixed $valor): ?string
    {
        $valor = strtoupper(trim((string) $valor));

        return preg_match('/^[A-Z]{2}$/', $valor) === 1 ? $valor : null;
    }

    /** Filial da Autopel ("05") como inteiro — o mesmo tipo de `faturamentos.filial`. */
    public static function filial(mixed $valor): ?int
    {
        $valor = trim((string) $valor);

        return ctype_digit($valor) ? (int) $valor : null;
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

    /**
     * As chaves de um array indexado por `numero_pedido`, devolvidas SEMPRE como texto.
     *
     * 🚨 ISTO NÃO É COSMÉTICO, e o custo de não ter foi a importação parada por 5 dias.
     * O PHP converte para int toda chave de array que pareça um número: `$cab['992086']`
     * vira a chave `992086` (int), enquanto `$cab['A00051']` continua string. Um
     * `array_keys()` sobre isso devolve uma lista de TIPOS MISTOS.
     *
     * Entregue essa lista mista a um `whereIn`/`whereNotIn` sobre `pedidos.numero_pedido`
     * (que é varchar) e o MySQL decide comparar NUMERICAMENTE por causa dos inteiros —
     * aí `'A00051'` vira `0` e, em statement de escrita com strict mode, estoura
     * `SQLSTATE[22007] ... Truncated incorrect DOUBLE value`. Foi exatamente o que
     * aconteceu quando o TOTVS estourou o contador numérico e passou a emitir a série
     * `A00063`, `A00064`, … em 2026-09-08: das 13h de 2026-09-10 em diante, 94 rodadas
     * seguidas de `totvs:atualizar` morreram no DELETE de pedidos obsoletos, e os pedidos
     * em aberto congelaram no retrato de 09/09.
     *
     * ⚠️ O sintoma em SELECT é PIOR que o erro, porque não há erro: a mesma comparação
     * numérica converte todo alfanumérico da coluna para `0`, então um `whereIn` casa
     * pedidos que não estavam na lista. Um `SELECT` não estoura — ele responde errado.
     *
     * Seguro comparar como texto nesta base: conferido em produção (2026-09-14) que
     * nenhum `numero_pedido` tem zero à esquerda nem espaço, então o texto do relatório
     * e o texto gravado são o mesmo byte a byte.
     *
     * @param  array<array-key, mixed>  $indexadoPorNumero
     * @return list<string>
     */
    public static function numerosDePedido(array $indexadoPorNumero): array
    {
        return array_map(strval(...), array_keys($indexadoPorNumero));
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
     * Data e hora vindas de duas colunas separadas do relatório (`DATA_HIST`/`HORA_HIST`).
     *
     * ⚠️ Sem hora ainda vale como data: no relatório 200 as duas colunas andam juntas,
     * mas descartar o movimento inteiro por falta da hora trocaria uma informação
     * imprecisa por nenhuma. Meia-noite é o menor palpite que preserva o dia.
     *
     * O guard de 01/01/1900 vem de graça: `data()` já o aplica.
     */
    public static function dataHora(mixed $data, mixed $hora): ?string
    {
        $dia = self::data($data);

        if ($dia === null) {
            return null;
        }

        $hora = trim((string) $hora);
        $valida = preg_match('/^(\d{2}):(\d{2})(:(\d{2}))?$/', $hora) === 1;

        return $dia.' '.($valida ? substr($hora.':00', 0, 8) : '00:00:00');
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
