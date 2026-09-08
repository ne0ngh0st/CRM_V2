<?php

namespace App\Services\Carteira;

use App\Models\Cliente;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;

/**
 * Quantos clientes INATIVOS há em cada segmento que o escopo ATENDE.
 *
 * ⚠️ O QUADRO LISTA TODOS OS SEGMENTOS onde o escopo tem cliente inativo, e os atendidos
 * vêm marcados. Isso já foi ao contrário: em 08/09/2026 restringi a lista aos segmentos
 * atendidos, e o Tony mandou voltar no mesmo dia com o motivo — **"os dois números têm que
 * bater em todos os casos"**. Com a restrição, o total daqui (66.753) discordava do card
 * "Carteira por Segmento" ao lado (73.940), e o vendedor não tem como saber em qual dos
 * dois acreditar. Explicar a diferença no subtítulo NÃO resolveu: número que precisa de
 * legenda para não parecer errado já custou a confiança.
 *
 * ⚠️ Por isso a INVARIANTE deste bloco é `total` == inativos da carteira do escopo, o mesmo
 * número do card vizinho. Qualquer filtro de linha que se pense em acrescentar aqui — por
 * segmento, por peso, por qualquer coisa — quebra essa invariante: filtre na EXIBIÇÃO (o
 * "ver mais" do front) e nunca no somatório.
 *
 * ⚠️ Segmento ATENDIDO sem nenhum inativo entra assim mesmo, com zero, e marcado — pedido
 * do diretor em 08/09 ("colocar todos os segmentos que essa pessoa atende, e destacar na
 * listagem"). Somar zero não mexe na invariante acima.
 *
 * Pedido do diretor em 2026-09-06, com a régua dele: "MENOS É MAIS, nada de análises
 * complicadas, simples e direto". Substituiu o quadro anterior, que cruzava família de
 * produto e trazia ativos, inativos, cobertura e peso por segmento.
 *
 * ⚠️ "Inativo" aqui é EXATAMENTE o mesmo que na Carteira: `clientes.data_ultima_compra`
 * anterior ao corte de {@see ClienteStatusResolver::DIAS_INATIVANDO} dias, ou nula. A
 * versão anterior deste quadro usava outra definição ("sem nota deste vendedor nos
 * últimos 12 meses") e o resultado foi dois números chamados "Inativos" na mesma tela,
 * com valores diferentes (188 no card ao lado, 132 aqui). Uma pergunta, uma resposta.
 *
 * ⚠️ POTENCIAL = inativos × peso do segmento, e a UNIDADE É CAIXA (Tony, 08/09/2026): o
 * peso é quantas caixas um cliente daquele segmento tende a comprar. Por isso o número
 * aparece sempre com a unidade na tela — potencial sem unidade seria lido como reais, que é
 * o que todo outro número grande do Painel significa.
 *
 * O peso (0-20 caixas por cliente) veio da diretoria em 08/09/2026 e mora em
 * `segmentos.peso_potencial`; a conta é feita aqui, e só aqui. Peso 0 zera o potencial de
 * propósito: é a diretoria dizendo que aquele segmento não é alvo de reativação, e não um
 * dado faltando. Por isso o ranking do quadro passou a ser por potencial, com inativos como
 * desempate — ordenar por inativos deixaria no topo justamente o segmento que eles marcaram
 * como fora do alvo.
 *
 * ⚠️ `totalCarteira` existe para os dois cards da tela CONVERSAREM. Este quadro conta só os
 * inativos dos segmentos atendidos; o card "Carteira por Segmento", ao lado, conta a
 * carteira inteira — e ver "0 inativos" aqui e "188 inativos" ali, sem explicação, é como o
 * vendedor deixa de confiar na tela (foi a reclamação do Tony em 06/09 e de novo em 08/09).
 * Com o total da carteira junto, a tela diz "0 dos 188", que é a mesma verdade dita inteira.
 * Sai de graça: é a soma das linhas que a agregação já traz, incluindo as não atendidas.
 *
 * ⚠️ NÃO consulta `faturamentos`. É por isso que este quadro cabe no orçamento de latência
 * em qualquer escopo: a versão com família de produto custava 41,5 s na empresa inteira,
 * porque cruzava `produtos` sobre as 5,87 M linhas de faturamento. Esta sai de `clientes`,
 * que já tem índice em `cod_vendedor` — medido em 39 ms por vendedor e 261 ms na empresa
 * inteira. Foi o que permitiu liberar o quadro para o ADM sem tabela de apoio.
 */
class SegmentosInativosResolver
{
    /**
     * Quantos segmentos o quadro mostra antes de precisar de "ver mais".
     *
     * ⚠️ TRÊS, a pedido do Tony em 2026-09-06: o principal fica visível e o resto abre no
     * clique. Nada é descartado — o resolver devolve TODAS as linhas, e é o front que
     * decide quantas pinta de início. Foi por isso que a agregação "Demais segmentos"
     * saiu: ela existia para o total fechar quando a cauda era jogada fora, e agora a
     * cauda é só um clique.
     */
    public const LINHAS_VISIVEIS = 3;

    public function __construct(private readonly ClienteStatusResolver $statusResolver) {}

    /**
     * @param  array<string>|null  $codVendedores  null = empresa inteira
     * @return array{total:int, totalPotencial:int, atendidos:int, linhas:list<array{codigo:string, nome:string, inativos:int, peso:float, potencial:int, atendido:bool}>}
     */
    public function resolver(?array $codVendedores): array
    {
        /*
         * ⚠️ `(string)` em toda chave de código não é redundante: array PHP converte chave
         * numérica em INTEIRO, e as comparações aqui são estritas — sem o cast,
         * `isset($atendidos[109])` contra a chave '109' falha e o segmento atendido perde a
         * marca em silêncio. Foi assim que este bug apareceu na primeira versão da regra.
         */
        $atendidos = [];
        foreach ($this->segmentosAtendidos($codVendedores) as $codigo) {
            $atendidos[(string) $codigo] = true;
        }

        $segmentos = $this->segmentosPorCodigo();

        $linhas = [];

        foreach ($this->inativosPorSegmento($codVendedores) as $l) {
            $codigo = (string) $l['codigo'];

            $linhas[$codigo] = $this->linha(
                $codigo,
                $l['nome'],
                $l['inativos'],
                $segmentos[$codigo] ?? null,
                isset($atendidos[$codigo]),
            );
        }

        /*
         * Segmento atendido que não apareceu na agregação não tem nenhum inativo: entra com
         * zero, para o vendedor ver que ele está em dia em vez de o segmento sumir e virar
         * dúvida. Soma zero, então a invariante do total continua de pé.
         */
        foreach (array_keys($atendidos) as $codigo) {
            if (! isset($linhas[$codigo]) && isset($segmentos[$codigo])) {
                $linhas[$codigo] = $this->linha($codigo, $segmentos[$codigo]->nome, 0, $segmentos[$codigo], true);
            }
        }

        $linhas = array_values($linhas);

        /*
         * ⚠️ Ordena por POTENCIAL, com inativos como desempate — é o que a coluna existe
         * para responder. Dois segmentos de peso 0 empatam em 0, e o desempate por inativos
         * os deixa em ordem estável entre si.
         */
        usort($linhas, fn (array $a, array $b) => [$b['potencial'], $b['inativos']] <=> [$a['potencial'], $a['inativos']]);

        /*
         * ⚠️ Os totais são a soma DAS LINHAS, sempre — e como nenhuma linha é descartada,
         * `total` é igual aos inativos que o card "Carteira por Segmento" mostra. É a
         * invariante do bloco, e há teste comparando os dois caminhos.
         */
        return [
            'total' => (int) array_sum(array_column($linhas, 'inativos')),
            'totalPotencial' => (int) array_sum(array_column($linhas, 'potencial')),
            'atendidos' => count(array_filter(array_column($linhas, 'atendido'))),
            'linhas' => $linhas,
        ];
    }

    /**
     * Uma linha do quadro. Existe para os dois laços de `resolver()` montarem o registro do
     * mesmo jeito — inclusive o peso, que é onde uma divergência passaria despercebida.
     *
     * @return array{codigo:string, nome:string, inativos:int, peso:float, potencial:int, atendido:bool}
     */
    private function linha(string $codigo, ?string $nome, int $inativos, ?Segmento $segmento, bool $atendido): array
    {
        // Segmento fora dos 23 conhecidos (100, 102, 110) e o balde "Sem segmento" não têm
        // peso definido: potencial 0, e não um chute.
        $peso = (float) ($segmento->peso_potencial ?? 0);

        return [
            // O CÓDIGO viaja junto porque a linha é link para a Carteira, e o filtro de lá
            // compara `clientes.cod_segmento`, que é o código bruto. Mandar o nome faria o
            // filtro não casar nada.
            'codigo' => $codigo,
            'nome' => (string) ($nome ?? $codigo),
            'inativos' => $inativos,
            // O peso viaja junto para a tela mostrar a conta ("5.893 × 8"). Número que
            // ninguém consegue conferir é número em que ninguém confia.
            'peso' => $peso,
            'potencial' => (int) round($inativos * $peso),
            'atendido' => $atendido,
        ];
    }

    /**
     * Códigos de segmento que o escopo atende oficialmente.
     *
     * Escopo de equipe ou empresa é a UNIÃO dos segmentos de quem está dentro. Para o ADM
     * sem filtro isso tende a ser quase todos, e é a resposta correta: a empresa atende
     * todos eles.
     *
     * @param  array<string>|null  $codVendedores
     * @return list<string>
     */
    private function segmentosAtendidos(?array $codVendedores): array
    {
        $query = SegmentoVendedor::query()
            ->join('segmentos', 'segmentos.id', '=', 'segmentos_vendedor.segmento_id');

        if ($codVendedores !== null) {
            $query->whereIn('segmentos_vendedor.cod_vendedor', $codVendedores);
        }

        return $query->distinct()->pluck('segmentos.codigo')->map(fn ($c) => (string) $c)->all();
    }

    /**
     * Todos os segmentos conhecidos, indexados pelo código. Uma query numa tabela de 23
     * linhas.
     *
     * ⚠️ Traz TODOS de propósito, sem recortar pelo escopo: o quadro precisa do peso também
     * dos segmentos que a pessoa NÃO atende mas nos quais tem cliente inativo — recortar
     * deixaria essas linhas com potencial 0 por falta de dado, e não por decisão da
     * diretoria.
     *
     * ⚠️ `keyBy('codigo')` devolve array PHP, que converte chave numérica em INTEIRO; por
     * isso quem consome faz `(string)` nas chaves.
     *
     * @return array<string, Segmento>
     */
    private function segmentosPorCodigo(): array
    {
        return Segmento::query()
            ->get(['codigo', 'nome', 'peso_potencial'])
            ->keyBy('codigo')
            ->all();
    }

    /**
     * Inativos por segmento, com o nome já resolvido.
     *
     * ⚠️ `LEFT JOIN segmentos` e `COALESCE` para o código bruto: existem ~331 clientes com
     * `cod_segmento` fora dos 23 conhecidos (100, 102, 110), sem descrição em nenhuma
     * tabela do TOTVS. Eles aparecem pelo código em vez de sumir — mesmo tratamento que a
     * Carteira já dá.
     *
     * @param  array<string>|null  $codVendedores
     * @return list<array{codigo:string, nome:string, inativos:int}>
     */
    private function inativosPorSegmento(?array $codVendedores): array
    {
        $limite = $this->statusResolver->limiteInativando()->toDateString();

        $query = Cliente::query()
            ->select([])
            ->leftJoin('segmentos', 'segmentos.codigo', '=', 'clientes.cod_segmento')
            ->selectRaw("COALESCE(clientes.cod_segmento, '') as codigo")
            ->selectRaw("COALESCE(segmentos.nome, clientes.cod_segmento, 'Sem segmento') as nome")
            ->selectRaw('COUNT(*) as inativos')
            // Mesma regra do ClienteStatusResolver: nunca comprou também é inativo.
            ->where(fn ($q) => $q
                ->whereNull('clientes.data_ultima_compra')
                ->orWhere('clientes.data_ultima_compra', '<', $limite))
            /*
             * ⚠️ Agrupa pelas COLUNAS DE ORIGEM, não pelos aliases. Com
             * `sql_mode=only_full_group_by` (o padrão do MySQL 8 e o que roda aqui),
             * `GROUP BY codigo` faz o servidor recusar a query inteira: ele exige que
             * cada expressão do SELECT seja agregada ou funcionalmente dependente das
             * colunas agrupadas, e o alias não estabelece essa dependência. Agrupando por
             * `clientes.cod_segmento` e `segmentos.nome`, os dois COALESCE do SELECT
             * passam a ser dependentes deles.
             */
            ->groupBy('clientes.cod_segmento', 'segmentos.nome')
            ->orderByDesc('inativos');

        if ($codVendedores !== null) {
            $query->whereIn('clientes.cod_vendedor', $codVendedores);
        }

        return $query->get()
            ->map(fn ($l) => [
                'codigo' => (string) $l->codigo,
                'nome' => (string) $l->nome,
                'inativos' => (int) $l->inativos,
            ])
            ->all();
    }
}
