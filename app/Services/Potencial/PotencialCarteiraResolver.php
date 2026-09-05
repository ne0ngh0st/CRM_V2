<?php

namespace App\Services\Potencial;

use App\Models\Cliente;
use App\Models\Faturamento;
use App\Models\PotencialPeso;
use Illuminate\Database\Eloquent\Builder;

/**
 * Potencial da Carteira: de quem já compra de mim, quem ainda NÃO compra cada família
 * de produto (bobina, etiqueta, tag de gôndola).
 *
 * A pergunta que o card responde é de cross-sell: "quais dos meus clientes eu ainda não
 * vendi etiqueta?". Com todos os pesos em 1, toda família é candidata em todo segmento;
 * quando a matriz da direção chegar, o peso passa a dizer quais famílias entram na cesta
 * de cada segmento — a leitura do card não muda, os números ficam melhores.
 *
 * ═══ As três decisões que definem o número, e que foram tomadas OLHANDO O DADO ═══
 *
 * ⚠️ 1. O denominador é a CARTEIRA INTEIRA, e o número vem QUEBRADO em ativos × inativos.
 * Potencial é tudo que o vendedor deixa de vender, então não pode excluir quem parou de
 * comprar — é ali que está o volume (medido em dev: um vendedor com 172 códigos tem só 40
 * ativos, 132 inativos).
 *
 * Mas o total sozinho não serve para escolher a família, e a razão é estrutural: quem está
 * inativo não compra NENHUMA das três, então entra igual nas três e não carrega sinal
 * nenhum de família. No mesmo vendedor, contar a carteira inteira dá 172/172/149 (as três
 * empatadas); contar só os ativos dá 40/40/17, que é onde aparece que ele vende bobina e
 * não vende etiqueta.
 *
 * Por isso `potencial` (a carteira toda) vem acompanhado de `potencialAtivos`
 * (cross-sell: já compra de mim, não leva esta família) e `potencialInativos`
 * (reativação: sem compra na janela). São ações diferentes, e a segunda é idêntica nas três
 * famílias de propósito — fingir o contrário seria inventar precisão que o dado não tem.
 *
 * ⚠️ 2. O grão é `cod_cliente` (a EMPRESA), não a filial — e isso é imposto pelo dado,
 * não escolhido: `faturamentos` guarda `cod_cliente` mas NÃO guarda `loja`, enquanto
 * `clientes` é chaveado por `(cod_cliente, loja)`. Não há como saber qual filial comprou.
 * O `cnpj` não salva: 8.094 CNPJs aparecem em mais de uma filial.
 * A diferença é grande e o card DECLARA o grão em vez de deixar dois totais discordarem
 * em silêncio: a base tem 92.209 filiais para 39.692 códigos, e há vendedor com 14.304
 * filiais em 635 códigos (redes com muitas lojas). Também é o grão certo para a ação:
 * quem se liga para oferecer etiqueta é a empresa, não cada loja.
 *
 * ⚠️ 3. "Já compra" significa "EU já vendi" — o filtro é `faturamentos.cod_vendedor`,
 * como em toda métrica de venda do Painel (`somaMensal`, `metaVsRealizado`). Cliente
 * transferido há pouco tem o histórico no código do vendedor anterior e aparece aqui como
 * "ainda não compra". É coerente com a pergunta (eu não vendi a ele) e é o que mantém a
 * consulta barata, porque entra por
 * `fat_vend_data_valor_idx (cod_vendedor, data_emissao, valor_total)`. Trocar por
 * "qualquer vendedor" tira o índice do caminho — `EXPLAIN` vira `type: ALL` sobre as
 * 5,87 M linhas de `faturamentos`.
 *
 * ═══ Custo medido (Regra de ouro nº 6), banco de dev em 05/09/2026 ═══
 *
 * Rodado para os 121 vendedores/representantes ativos com código:
 * **mediana 12,7 ms · p95 50,0 ms · máximo 220,7 ms**, em 3 queries. Dentro do orçamento
 * de 400 ms da Regra nº 9 mesmo antes do cache de 30 min do bloco. (Era 8,2 / 41,5 /
 * 131,2 ms antes de a carteira passar pela tabela derivada que corrige a dupla contagem
 * de código multi-segmento — o custo a mais compra um denominador correto.)
 *
 * ⚠️ Existe UM código para o qual esta consulta leva 32 SEGUNDOS: `010002`, que sozinho
 * responde por 93,9% das linhas de faturamento da janela (1,57 M de 1,68 M). Aí o filtro
 * por vendedor não corta nada, o otimizador larga o índice e varre a tabela inteira —
 * exatamente a lição de que índice só ajuda quando a condição elimina uma fração real das
 * linhas. Hoje isso é inofensivo porque `010002` é o **diretor**, e o bloco não é exposto
 * a gestor (ver a condição em DashboardController). **Se algum dia este bloco for liberado
 * para gestor, ou se um vendedor chegar a esse volume, a resposta NÃO é criar índice em
 * `faturamentos`** (índice novo ali custou 10m40s contra 66s numa recarga de ano, medido
 * em 31/08) — é uma tabela de rollup pré-agregada, atualizada pelo scheduler.
 */
class PotencialCarteiraResolver
{
    public const JANELA_MESES = 12;

    /**
     * @param  array<string>|null  $codVendedores  null = empresa inteira (não usado hoje:
     *                                             o bloco só é exposto a vendedor)
     * @return array{janelaMeses:int, carteira:int, ativos:int, inativos:int, pesosPadrao:bool, familias:list<array<string,mixed>>}
     */
    public function resolver(?array $codVendedores): array
    {
        $pesos = $this->pesosPorSegmento();

        $carteiraPorSegmento = $this->carteiraPorSegmento($codVendedores);
        $compraPorSegmento = $this->compraPorSegmento($codVendedores);

        $carteira = (int) array_sum($carteiraPorSegmento);
        $ativos = (int) array_sum(array_column($compraPorSegmento, 'ativos'));

        // União das chaves: um segmento pode ter cliente na carteira e nenhuma nota na
        // janela (só entra em `carteiraPorSegmento`), ou o contrário quando um código tem
        // filiais em segmentos diferentes e o MIN() escolhe outro.
        $segmentos = array_unique(array_merge(
            array_keys($carteiraPorSegmento),
            array_keys($compraPorSegmento),
        ));

        $familias = [];

        foreach (FamiliaProduto::chaves() as $familia) {
            $candidatos = 0;
            $ativosCandidatos = 0;
            $compram = 0;
            $pesoMax = 0.0;

            foreach ($segmentos as $codSegmento) {
                $peso = $pesos[$codSegmento][$familia] ?? 1.0;

                if ($peso <= 0) {
                    continue;
                }

                $pesoMax = max($pesoMax, $peso);
                $candidatos += $carteiraPorSegmento[$codSegmento] ?? 0;
                $ativosCandidatos += $compraPorSegmento[$codSegmento]['ativos'] ?? 0;
                $compram += $compraPorSegmento[$codSegmento][$familia] ?? 0;
            }

            $familias[] = [
                'familia' => $familia,
                'rotulo' => FamiliaProduto::rotuloDe($familia),
                'candidatos' => $candidatos,
                'ativosCandidatos' => $ativosCandidatos,
                'compram' => $compram,
                // Cross-sell: já compra de mim, não leva esta família. É o número que
                // diferencia as famílias entre si.
                'potencialAtivos' => $ativosCandidatos - $compram,
                // Reativação: sem compra nenhuma na janela. Igual nas três famílias por
                // construção — quem não compra nada não compra nenhuma delas.
                'potencialInativos' => $candidatos - $ativosCandidatos,
                /*
                 * ⚠️ Não leva `max(0, …)`, e a ausência é deliberada: a subtração não pode
                 * dar negativo por CONSTRUÇÃO. `candidatos` e `compram` são acumulados no
                 * mesmo laço, sobre os mesmos segmentos aprovados pelo peso, e dentro de
                 * um segmento quem compra a família é subconjunto de quem comprou algo
                 * (as duas colunas saem do mesmo COUNT(DISTINCT) na mesma agregação).
                 *
                 * A primeira versão tinha o `max(0, …)` "por segurança". A verificação por
                 * mutação mostrou que removê-lo não quebrava teste nenhum — era código
                 * morto defendendo um cenário impossível, e o comentário afirmava um risco
                 * que não existe. O que protege de verdade é o teste que trava a
                 * invariante: mover o `compram +=` para fora do filtro de peso a quebra.
                 */
                'potencial' => $candidatos - $compram,
                'cobertura' => $candidatos > 0 ? round($compram / $candidatos * 100, 1) : 0.0,
                'peso' => round($pesoMax, 2),
            ];
        }

        return [
            'janelaMeses' => self::JANELA_MESES,
            'carteira' => $carteira,
            'ativos' => $ativos,
            'inativos' => $carteira - $ativos,
            'pesosPadrao' => $this->todosOsPesosSaoNeutros($pesos),
            'familias' => $familias,
        ];
    }

    /**
     * Os códigos de cliente por trás de um número do card: quem está ativo e NÃO comprou
     * aquela família na janela.
     *
     * Existe para o card poder responder "quem são?" — sem isso o vendedor lê "40 clientes
     * sem etiqueta" e não tem o que fazer com a informação. A `/carteira` filtra por este
     * conjunto e o vendedor cai numa tela onde consegue ligar, mandar WhatsApp e abrir
     * orçamento.
     *
     * ⚠️ UMA passada sobre `faturamentos`, com `HAVING MAX(categoria = ?) = 0`, e não duas
     * subconsultas (`IN` dos ativos + `NOT IN` de quem compra). A versão de duas passadas
     * custava 388 ms num vendedor médio; esta custa **72,7 ms** no mesmo (3,7 ms num
     * vendedor pequeno). O `HAVING` é o que junta "é ativo" e "não comprou a família" numa
     * pergunta só: quem não aparece no resultado ou não comprou nada, ou comprou a família.
     *
     * ⚠️ `LEFT JOIN`, pelo mesmo motivo do `compraPorSegmento()`: cliente que só comprou
     * produto sem cadastro em `produtos` continua sendo ativo, e continua não tendo a
     * família. Com INNER ele sumiria da lista e o total do card não bateria com ela.
     *
     * ⚠️ Custo no código extremo (`010002`, 94% das linhas da tabela): 30 segundos. Por
     * isso quem chama CACHEIA por dia — ver CarteiraController::codigosSemFamilia().
     *
     * @param  array<string>|null  $codVendedores
     * @return list<string>
     */
    public function codigosSemFamilia(?array $codVendedores, string $familia): array
    {
        $categoria = FamiliaProduto::categoriaDe($familia);
        $desde = now()->subMonths(self::JANELA_MESES)->toDateString();

        $query = Faturamento::query()
            ->select([])
            ->leftJoin('produtos', 'produtos.cod_produto', '=', 'faturamentos.cod_produto')
            /*
             * ⚠️ O JOIN com a carteira é OBRIGATÓRIO, e a falta dele foi um bug real
             * (auditoria de 2026-09-05, 130 divergências em 121 vendedores): sem ele a
             * lista incluía quem comprou com o código deste vendedor mas JÁ SAIU da
             * carteira dele — cliente transferido continua com o histórico no código
             * antigo. O contador do card (`compraPorSegmento`) sempre juntou a carteira,
             * então a lista vinha MAIOR que o número anunciado. As duas pontas têm que
             * partir exatamente do mesmo universo.
             */
            ->joinSub($this->carteiraPorCodigo($codVendedores), 'cs', fn ($join) => $join->on('cs.cod_cliente', '=', 'faturamentos.cod_cliente'))
            ->selectRaw('faturamentos.cod_cliente as cod')
            ->where('faturamentos.data_emissao', '>=', $desde)
            ->whereNotNull('faturamentos.cod_cliente')
            ->where('faturamentos.cod_cliente', '<>', '')
            ->groupBy('faturamentos.cod_cliente')
            // ⚠️ COALESCE, e não `MAX(categoria = ?)` puro: em produto órfão a categoria é
            // NULL, `NULL = 'BOBINA'` é NULL, `MAX(...)` de só-NULL é NULL, e `NULL = 0`
            // não é verdadeiro — o cliente sumia da lista embora contasse como ativo no
            // card. A lista ficava MENOR que o número anunciado. Coberto por teste.
            ->havingRaw('MAX(COALESCE(produtos.categoria = ?, 0)) = 0', [$categoria]);

        if ($codVendedores !== null) {
            $query->whereIn('faturamentos.cod_vendedor', $codVendedores);
        }

        return $query->pluck('cod')->map(fn ($c) => (string) $c)->all();
    }

    /**
     * Códigos de cliente da carteira, por segmento. É só contexto do card — o
     * denominador do potencial são os ativos, ver a decisão 1 no topo.
     *
     * @param  array<string>|null  $codVendedores
     * @return array<string, int>
     */
    private function carteiraPorSegmento(?array $codVendedores): array
    {
        $query = Cliente::query()
            ->select([])
            ->fromSub($this->carteiraPorCodigo($codVendedores), 'cs')
            ->selectRaw('cs.seg as seg, COUNT(*) as total')
            ->groupBy('seg');

        return $query->pluck('total', 'seg')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Uma linha por `cod_cliente`, com o segmento predominante resolvido por `MIN()`.
     *
     * ⚠️ Existe como método porque as DUAS pontas da conta precisam usar exatamente a
     * mesma atribuição de segmento. Enquanto a carteira agrupava direto por
     * `cod_segmento` e a compra usava este colapso, um código com filiais em segmentos
     * diferentes era contado uma vez em CADA segmento na carteira e uma só na compra —
     * o total da carteira vinha inflado (178 contra os 172 reais num vendedor de dev) e
     * o denominador do card mentia para mais.
     *
     * @param  array<string>|null  $codVendedores
     */
    private function carteiraPorCodigo(?array $codVendedores): Builder
    {
        $query = Cliente::query()
            ->select([])
            ->selectRaw("cod_cliente, MIN(COALESCE(cod_segmento, '')) as seg")
            ->groupBy('cod_cliente');

        if ($codVendedores !== null) {
            $query->whereIn('cod_vendedor', $codVendedores);
        }

        return $query;
    }

    /**
     * Por segmento: quantos códigos compraram algo na janela, e quantos compraram cada
     * família.
     *
     * ⚠️ A quebra por família sai de graça — são colunas `COUNT(DISTINCT CASE …)` na
     * agregação que já roda, não uma consulta por família. Mesmo padrão da quebra por
     * canal de contato (2026-09-02), que custou +0,53 ms no Painel.
     *
     * ⚠️ `LEFT JOIN produtos`, não INNER: 3,35% das linhas de faturamento têm
     * `cod_produto` sem cadastro em `produtos`. Com INNER, um cliente cujas compras sejam
     * todas de produto órfão sumiria do denominador — deixaria de ser "ativo" e o
     * potencial encolheria em silêncio. Com LEFT ele conta como ativo e como não-comprador
     * das três famílias, que é a leitura honesta do que sabemos. São 351 clientes na base
     * (4,1% dos que compraram nos últimos 12 meses).
     *
     * ⚠️ A tabela derivada existe para evitar fan-out: `clientes` tem uma linha por
     * FILIAL, e juntar direto multiplicaria cada nota pelo número de lojas do código.
     * Colapsar para uma linha por `cod_cliente` antes do join resolve, e a derivada é do
     * tamanho da carteira. `MIN(cod_segmento)` desempata os 1.174 códigos (3% da base)
     * cujas filiais estão em segmentos diferentes — determinístico, e só influi quando os
     * pesos deixarem de ser todos iguais.
     *
     * @param  array<string>|null  $codVendedores
     * @return array<string, array<string, int>>
     */
    private function compraPorSegmento(?array $codVendedores): array
    {
        $desde = now()->subMonths(self::JANELA_MESES)->toDateString();

        $carteira = $this->carteiraPorCodigo($codVendedores);

        $colunas = ['cs.seg as seg', 'COUNT(DISTINCT faturamentos.cod_cliente) as ativos'];
        $bindings = [];

        foreach (FamiliaProduto::chaves() as $familia) {
            $colunas[] = 'COUNT(DISTINCT CASE WHEN produtos.categoria = ? THEN faturamentos.cod_cliente END) as '.$familia;
            $bindings[] = FamiliaProduto::categoriaDe($familia);
        }

        $query = Faturamento::query()
            ->select([])
            ->leftJoin('produtos', 'produtos.cod_produto', '=', 'faturamentos.cod_produto')
            ->joinSub($carteira, 'cs', fn ($join) => $join->on('cs.cod_cliente', '=', 'faturamentos.cod_cliente'))
            ->selectRaw(implode(', ', $colunas), $bindings)
            ->where('faturamentos.data_emissao', '>=', $desde)
            ->groupBy('seg');

        if ($codVendedores !== null) {
            $query->whereIn('faturamentos.cod_vendedor', $codVendedores);
        }

        $resultado = [];

        foreach ($query->get() as $linha) {
            $item = ['ativos' => (int) $linha->ativos];

            foreach (FamiliaProduto::chaves() as $familia) {
                $item[$familia] = (int) $linha->{$familia};
            }

            $resultado[(string) $linha->seg] = $item;
        }

        return $resultado;
    }

    /**
     * Peso por código de segmento e família. Segmento sem linha cadastrada cai em 1,0 no
     * consumo (neutro) — um segmento novo não deve sumir do card por falta de seed.
     *
     * @return array<string, array<string, float>>
     */
    private function pesosPorSegmento(): array
    {
        $linhas = PotencialPeso::query()
            ->join('segmentos', 'segmentos.id', '=', 'potencial_pesos.segmento_id')
            ->get(['segmentos.codigo as codigo', 'potencial_pesos.familia as familia', 'potencial_pesos.peso as peso']);

        $mapa = [];

        foreach ($linhas as $linha) {
            $mapa[(string) $linha->codigo][(string) $linha->familia] = (float) $linha->peso;
        }

        return $mapa;
    }

    /**
     * Enquanto for tudo 1,00, o card avisa na tela que a priorização ainda não existe —
     * sem isso o vendedor leria como ranking de prioridade o que hoje é contagem bruta.
     *
     * @param  array<string, array<string, float>>  $pesos
     */
    private function todosOsPesosSaoNeutros(array $pesos): bool
    {
        foreach ($pesos as $porFamilia) {
            foreach ($porFamilia as $peso) {
                if (abs($peso - 1.0) > 0.001) {
                    return false;
                }
            }
        }

        return true;
    }
}
