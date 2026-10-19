<?php

namespace App\Services\VisaoDiretor;

use Illuminate\Support\Facades\DB;

/**
 * Liga cliente a rede pelo PRODUTO PERSONALIZADO que ele compra: a bobina
 * "BOBINA TS KPH BC 80X80M MAX MUFFATO" diz que o código que a compra é do Muffato, mesmo
 * com razão social e fantasia que não lembram a rede (Tony, 2026-10-19).
 *
 * Lê `faturamento_cliente_produto` (derivada, `FaturamentoMensalRollup`), nunca
 * `faturamentos`: procurar o nome na descrição das notas custava 41 s.
 *
 * Travas, todas contra falso positivo:
 * 1. A marca é o nome da rede sem palavra de tipo nas pontas (`CORTAR` e as de
 *    `SugestaoDeVinculo::marca()`), com 5+ letras; palavra única não pode ser genérica.
 * 2. A marca aparece como PALAVRAS INTEIRAS e seguidas na descrição ("MUFFATO" não casa
 *    "MUFFATOS"; "PAO ACUCAR" casa "PAO DE ACUCAR", porque o DE sai dos dois lados).
 * 3. Produto comprado por mais de `MAXIMO_COMPRADORES` códigos não é personalizado.
 * 4. Marca presente em mais de `MAXIMO_PRODUTOS` produtos é palavra de produto, não de rede.
 * 5. Marca no nome de mais de `MAXIMO_EMPRESAS_COM_O_NOME` empresas da base é nome comum
 *    (TROPICAL, TREVO, MASTER, DINIZ…): "LIVRO PONTO" ligava gráfica ao Ponto Frio e
 *    "CAIXA … MASTER" o Banco Safra ao Master Supermercados (descoberta de 19/10).
 * 6. O comprador tem que ter filial no segmento da rede, quando ela tem segmento.
 * 7. Código que compra produto de DUAS redes sem produto em comum é revendedor/gráfica:
 *    fica fora das duas e vai para `revendas`.
 */
class VinculoPorProduto
{
    public const MINIMO_LETRAS = 5;

    public const MAXIMO_COMPRADORES = 300;

    public const MAXIMO_PRODUTOS = 100;

    public const MAXIMO_EMPRESAS_COM_O_NOME = 15;

    /** Tipo de estabelecimento ou de sociedade que `marca()` deixa passar: só nas pontas. */
    private const CORTAR = [
        'SUPER', 'HIPER', 'HIPERMERCADO', 'HIPERMERCADOS', 'MERCADO', 'MERCADOS', 'SUPERMERCADISTA',
        'ATACADO', 'ATACADISTA', 'ATACAREJO', 'AUTOSSERVICO', 'COMERCIO', 'COMERCIAL',
        'DISTRIBUIDORA', 'DISTRIBUIDOR', 'CIA', 'COMPANHIA', 'IRMAO', 'IRMAOS', 'FILHO', 'FILHOS',
        'BRASIL', 'CENTER', 'CENTRAL', 'SHOPPING', 'MAGAZINE', 'EMPRESA', 'SOCIEDADE',
        'CONVENIENCIA', 'SERVICO', 'SERVICOS', 'RESTAURANTE', 'RESTAURANTES', 'PIZZARIA',
        'LANCHONETE', 'CAFE', 'PADARIA',
    ];

    public function __construct(private readonly SugestaoDeVinculo $nomes)
    {
    }

    /**
     * @param  array<int|string, array{termos: list<string>, segmento: ?string}>  $alvos  índice → nomes da rede (nome + apelidos)
     * @return array{
     *     clientes: array<string, list<int|string>>,
     *     revendas: array<string, list<int|string>>,
     *     produtos: array<int|string, list<string>>,
     * } `clientes`: código → alvos que ele casou; `produtos`: alvo → descrições que casaram
     */
    public function casar(array $alvos): array
    {
        [$palavras, $porPalavra, $codigos, $descricoes] = $this->catalogo();
        $empresasPorPalavra = $this->empresasPorPalavra();

        $produtosDoAlvo = [];
        foreach ($alvos as $i => $alvo) {
            $achados = [];
            foreach (array_unique(array_map(fn ($t) => implode(' ', $this->marcaDoNome($t)), $alvo['termos'])) as $termo) {
                $marca = $termo === '' ? [] : explode(' ', $termo);
                if (! $this->marcaValida($marca) || $this->nomeComum($marca, $empresasPorPalavra)) {
                    continue;
                }

                $deste = $this->produtosCom($marca, $palavras, $porPalavra);
                if (count($deste) > self::MAXIMO_PRODUTOS) {
                    continue;
                }
                $achados += array_fill_keys($deste, true);
            }

            if ($achados !== []) {
                $produtosDoAlvo[$i] = array_keys($achados);
            }
        }

        if ($produtosDoAlvo === []) {
            return ['clientes' => [], 'revendas' => [], 'produtos' => []];
        }

        $compradores = $this->compradores(array_unique(array_merge(...array_map(
            fn ($ps) => array_map(fn ($p) => $codigos[$p], $ps),
            array_values($produtosDoAlvo),
        ))));
        $segmentos = $this->segmentosPorCodigo();

        // código → alvo → produtos
        $porCodigo = [];
        foreach ($produtosDoAlvo as $i => $produtos) {
            $segmento = $alvos[$i]['segmento'];
            foreach ($produtos as $p) {
                foreach ($compradores[$codigos[$p]] ?? [] as $codigo) {
                    if (! isset($segmentos[$codigo]) || ($segmento !== null && ! isset($segmentos[$codigo][$segmento]))) {
                        continue;
                    }
                    $porCodigo[$codigo][$i][$p] = true;
                }
            }
        }

        $clientes = [];
        $revendas = [];
        foreach ($porCodigo as $codigo => $porAlvo) {
            if ($this->revenda($porAlvo)) {
                $revendas[(string) $codigo] = array_keys($porAlvo);

                continue;
            }
            $clientes[(string) $codigo] = array_keys($porAlvo);
        }

        $exemplos = [];
        foreach ($produtosDoAlvo as $i => $produtos) {
            $exemplos[$i] = array_map(fn ($p) => $descricoes[$p], array_slice($produtos, 0, 5));
        }

        return ['clientes' => $clientes, 'revendas' => $revendas, 'produtos' => $exemplos];
    }

    /**
     * Os produtos PERSONALIZADOS (poucos compradores) já faturados, em memória uma vez por
     * rodada. O GROUP BY segue a chave primária (cod_produto, cod_cliente).
     *
     * @return array{0: list<list<string>>, 1: array<string, array<int, true>>, 2: list<string>, 3: list<string>}
     */
    private function catalogo(): array
    {
        $palavras = [];
        $porPalavra = [];
        $codigos = [];
        $descricoes = [];

        $linhas = DB::table('faturamento_cliente_produto')
            ->selectRaw('cod_produto, MAX(descricao) AS descricao')
            ->groupBy('cod_produto')
            ->havingRaw('COUNT(*) <= ?', [self::MAXIMO_COMPRADORES])
            ->cursor();

        foreach ($linhas as $l) {
            $tokens = $this->nomes->marca((string) $l->descricao);
            if ($tokens === []) {
                continue;
            }

            $p = count($codigos);
            $codigos[] = (string) $l->cod_produto;
            $descricoes[] = (string) $l->descricao;
            $palavras[] = $tokens;
            foreach ($tokens as $t) {
                $porPalavra[$t][$p] = true;
            }
        }

        return [$palavras, $porPalavra, $codigos, $descricoes];
    }

    /** @return list<string> */
    private function marcaDoNome(string $nome): array
    {
        $p = $this->nomes->marca($nome);

        while ($p !== [] && in_array($p[0], self::CORTAR, true)) {
            array_shift($p);
        }
        while ($p !== [] && in_array($p[count($p) - 1], self::CORTAR, true)) {
            array_pop($p);
        }

        return $p;
    }

    /** @param  list<string>  $marca */
    private function marcaValida(array $marca): bool
    {
        if (strlen((string) preg_replace('/[^A-Z]/', '', implode('', $marca))) < self::MINIMO_LETRAS) {
            return false;
        }

        return count($marca) > 1 || ! $this->nomes->palavraGenerica($marca[0]);
    }

    /**
     * Palavra do nome (razão social e fantasia) → empresas que a têm. A empresa é a raiz do
     * CNPJ, calculada aqui e descartada (Regra de ouro nº 3); sem CNPJ, o código.
     *
     * @return array<string, array<string, true>>
     */
    private function empresasPorPalavra(): array
    {
        $saida = [];
        foreach (DB::table('clientes')->select('cod_cliente', 'cnpj_digitos', 'razao_social', 'nome_fantasia')->cursor() as $c) {
            $empresa = strlen((string) $c->cnpj_digitos) === 14 ? substr((string) $c->cnpj_digitos, 0, 8) : '#'.$c->cod_cliente;
            foreach ([...$this->nomes->marca((string) $c->razao_social), ...$this->nomes->marca((string) $c->nome_fantasia)] as $w) {
                $saida[$w][$empresa] = true;
            }
        }

        return $saida;
    }

    /**
     * @param  list<string>  $marca
     * @param  array<string, array<string, true>>  $empresasPorPalavra
     */
    private function nomeComum(array $marca, array $empresasPorPalavra): bool
    {
        $empresas = $empresasPorPalavra[$marca[0]] ?? [];
        foreach (array_slice($marca, 1) as $w) {
            $empresas = array_intersect_key($empresas, $empresasPorPalavra[$w] ?? []);
        }

        return count($empresas) > self::MAXIMO_EMPRESAS_COM_O_NOME;
    }

    /**
     * Produtos em que a marca aparece como palavras inteiras e seguidas.
     *
     * @param  list<string>  $marca
     * @return list<int>
     */
    private function produtosCom(array $marca, array $palavras, array $porPalavra): array
    {
        $candidatos = $porPalavra[$marca[0]] ?? [];
        foreach (array_slice($marca, 1) as $w) {
            $candidatos = array_intersect_key($candidatos, $porPalavra[$w] ?? []);
        }

        $n = count($marca);
        $saida = [];
        foreach (array_keys($candidatos) as $p) {
            $tokens = $palavras[$p];
            for ($k = 0; $k + $n <= count($tokens); $k++) {
                if (array_slice($tokens, $k, $n) === $marca) {
                    $saida[] = $p;
                    break;
                }
            }
        }

        return $saida;
    }

    /**
     * @param  list<string>  $produtos
     * @return array<string, list<string>> produto → códigos que o compraram
     */
    private function compradores(array $produtos): array
    {
        $saida = [];
        foreach (array_chunk($produtos, 1000) as $lote) {
            foreach (DB::table('faturamento_cliente_produto')->whereIn('cod_produto', $lote)->get(['cod_produto', 'cod_cliente']) as $l) {
                $saida[(string) $l->cod_produto][] = (string) $l->cod_cliente;
            }
        }

        return $saida;
    }

    /** @return array<string, array<string, true>> código → segmentos das filiais */
    private function segmentosPorCodigo(): array
    {
        $saida = [];
        foreach (DB::table('clientes')->select('cod_cliente', 'cod_segmento')->distinct()->cursor() as $c) {
            $saida[(string) $c->cod_cliente][(string) $c->cod_segmento] = true;
        }

        return $saida;
    }

    /**
     * Duas redes cujos produtos não têm NENHUM em comum: o código compra para terceiros.
     * Com produto em comum ("BOBINA CARREFOUR EXPRESS" casa CARREFOUR e CARREFOUR EXPRESS)
     * é a mesma empresa, e quem decide é o resolver de quem chamou.
     *
     * @param  array<int|string, array<int, true>>  $porAlvo
     */
    private function revenda(array $porAlvo): bool
    {
        $conjuntos = array_values($porAlvo);
        for ($a = 0; $a < count($conjuntos); $a++) {
            for ($b = $a + 1; $b < count($conjuntos); $b++) {
                if (array_intersect_key($conjuntos[$a], $conjuntos[$b]) === []) {
                    return true;
                }
            }
        }

        return false;
    }
}
