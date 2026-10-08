<?php

namespace App\Services\VisaoDiretor;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vínculo conta → clientes pela IDENTIDADE JURÍDICA: a razão social da rede igual à dos
 * nossos clientes, expandida para todas as filiais do mesmo CNPJ raiz.
 *
 * Existe porque comparar pelo nome de marca (`VinculoPorCliente`) é fraco nas duas pontas
 * (Tony, 2026-10-08): o Sonda está no TOTVS como "SONDA SUPERMERCADOS EXPORTACAO E IMPORTA"
 * e a marca "SONDA" sozinha é curta demais para casar; o Assaí é "SENDAS DISTRIBUIDORA" e o
 * GPA é "CIA BRASILEIRA DE DISTRIBUICAO" — marca nenhuma aparece no cliente. E a marca
 * puxa homônimo ("Supermercado da Família" PE × "Família Gaúcha" RS); a razão social não.
 * Medido em produção: 124 das 200 redes da ABRAS casam assim, todas conferidas.
 *
 * As regras, cada uma contra um erro visto no teste em produção:
 *
 * 1. **Razão social IGUAL, não prefixo.** Começo de nome casava "SUPERMERCADO BEL" com
 *    BELTRAME e "CASA SANTA" com CASA SANTA LUZIA — empresas diferentes. Comparação depois
 *    de tirar acento, pontuação e sufixo societário (LTDA, S/A, EIRELI…).
 * 2. **Prefixo só quando o TOTVS cortou o nome**: a razão social do cliente com 38+
 *    caracteres que é o começo da razão da rede (o corte cai no meio da palavra).
 * 3. **Expansão pelo CNPJ raiz** (os 8 primeiros dígitos, calculados em memória — nunca
 *    uma coluna, Regra de ouro nº 3): traz as filiais cuja razão foi digitada diferente
 *    ("SUPERMERCADO BAKLIZI LTDA. - FILIAL", endereço no lugar do nome).
 * 4. **Código do cliente só se a MAIORIA das filiais dele está nessas raízes** — mesmo
 *    cuidado do `VinculoPorCliente`: um código que mistura empresas não entra por uma loja.
 */
class VinculoPorRazaoSocial
{
    /** A partir daqui a razão social do TOTVS pode estar cortada (o campo tem 40). */
    private const TAMANHO_CORTADO = 38;

    /**
     * Os clientes em memória, UMA vez por rodada.
     *
     * @return array{porRazao: array<string, array<string, true>>, cortadas: array<string, array<string, true>>, filiaisPorCodigo: array<string, array<string, int>>, totalPorCodigo: array<string, int>}
     */
    public function catalogo(): array
    {
        $porRazao = [];
        $cortadas = [];
        $filiaisPorCodigo = [];
        $totalPorCodigo = [];

        $linhas = DB::table('clientes')
            ->select('cod_cliente', 'razao_social', 'cnpj_digitos')
            ->orderBy('id')
            ->cursor();

        foreach ($linhas as $c) {
            $codigo = (string) $c->cod_cliente;
            $totalPorCodigo[$codigo] = ($totalPorCodigo[$codigo] ?? 0) + 1;

            if (strlen((string) $c->cnpj_digitos) !== 14) {
                continue;
            }

            $raiz = substr($c->cnpj_digitos, 0, 8);
            $filiaisPorCodigo[$codigo][$raiz] = ($filiaisPorCodigo[$codigo][$raiz] ?? 0) + 1;

            $chave = self::normalizar((string) $c->razao_social);
            if ($chave === '') {
                continue;
            }

            $porRazao[$chave][$raiz] = true;
            if (mb_strlen(trim((string) $c->razao_social)) >= self::TAMANHO_CORTADO) {
                $cortadas[$chave][$raiz] = true;
            }
        }

        return compact('porRazao', 'cortadas', 'filiaisPorCodigo', 'totalPorCodigo');
    }

    /**
     * @param  list<string>  $razoes  razões sociais da rede (a da ABRAS e as conhecidas)
     * @return list<string> códigos de cliente da rede
     */
    public function clientes(array $razoes, array $catalogo): array
    {
        $raizes = [];

        foreach ($razoes as $razao) {
            $alvo = self::normalizar($razao);
            if ($alvo === '') {
                continue;
            }

            $raizes += $catalogo['porRazao'][$alvo] ?? [];

            foreach ($catalogo['cortadas'] as $chave => $dela) {
                // Cortada no meio da palavra ("…E IMPORTA"): sem exigir fronteira.
                if (str_starts_with($alvo, $chave)) {
                    $raizes += $dela;
                }
            }
        }

        if ($raizes === []) {
            return [];
        }

        $codigos = [];
        foreach ($catalogo['filiaisPorCodigo'] as $codigo => $porRaiz) {
            $naRede = array_sum(array_intersect_key($porRaiz, $raizes));

            if ($naRede > 0 && $naRede * 2 >= $catalogo['totalPorCodigo'][$codigo]) {
                $codigos[] = (string) $codigo;
            }
        }

        sort($codigos);

        return $codigos;
    }

    /** "SONDA SUPERMERCADOS EXPORTAÇÃO E IMPORTAÇÃO S.A." → "SONDA SUPERMERCADOS EXPORTACAO E IMPORTACAO" */
    public static function normalizar(string $razao): string
    {
        $s = mb_strtoupper(Str::ascii($razao));
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);
        // Sufixo societário, "& CIA"/"E CIA" e conectivos: a mesma empresa aparece como
        // "GIASSI & CIA" e "GIASSI E CIA", "CASA VISCARDI S/A COMERCIO E IMPORTACAO" e
        // "… S.A. COMÉRCIO IMPORTAÇÃO".
        $s = preg_replace('/\b(LTDA|LIMITADA|EIRELI|EIRELE|EPP|ME|S A|SA|CIA|COMPANHIA|E|DE|DA|DO|DAS|DOS)\b/', ' ', $s);
        // "SUPERMERCADO JUBA" × "SUPERMERCADOS JUBA".
        $s = preg_replace('/\b(SUPERMERCADO|HIPERMERCADO|MERCADO)S\b/', '$1', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
