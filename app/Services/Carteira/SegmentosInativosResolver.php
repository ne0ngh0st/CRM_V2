<?php

namespace App\Services\Carteira;

use App\Models\Cliente;

/**
 * Quantos clientes INATIVOS há em cada segmento que o escopo atende.
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
     * @return array{total:int, linhas:list<array{codigo:string, nome:string, inativos:int}>}
     */
    public function resolver(?array $codVendedores): array
    {
        $porSegmento = $this->inativosPorSegmento($codVendedores);

        $total = array_sum(array_column($porSegmento, 'inativos'));

        /*
         * ⚠️ AS LINHAS SÃO OS SEGMENTOS ONDE ELE TEM CLIENTE INATIVO, não os segmentos
         * atribuídos a ele no cadastro — e essa escolha foi corrigida OLHANDO O DADO.
         *
         * O plano original nomeava só os segmentos de `segmentos_vendedor` e jogava o
         * resto em "Outros". Medido em 2026-09-06 sobre os 142 vendedores com segmento
         * atribuído: 24 deles (16,9%) têm ZERO inativo no próprio segmento — o card
         * inteiro viraria uma linha escrita "Outros segmentos" — e, na média, apenas
         * 41,7% dos inativos caem dentro do segmento atribuído. Ou seja: o balde anônimo
         * seria a MAIOR linha da tabela na maioria dos casos.
         *
         * Nomear o que existe resolve os dois problemas e continua respondendo
         * "Segmentos Atendidos": são os segmentos que a carteira dele de fato atende.
         *
         * ⚠️ E NÃO marcamos quais são os oficiais do cadastro. Isso é aderência, e é o
         * assunto do card "Carteira por Segmento" logo ao lado — repetir aqui seria a
         * mesma informação em dois lugares, contra o "menos é mais" que originou este
         * quadro.
         */
        /*
         * ⚠️ Devolve TODAS as linhas, ordenadas por inativos desc. O corte de exibição é
         * decisão de tela, e vive no componente — assim "ver mais" não precisa de uma
         * segunda ida ao servidor, e o total sempre fecha com a soma das linhas por
         * construção.
         */
        $linhas = array_map(fn (array $l) => [
            // ⚠️ O CÓDIGO viaja junto porque a linha é um link para a Carteira, e o filtro
            // de lá compara `clientes.cod_segmento`, que é o código bruto. Mandar o nome
            // faria o filtro não casar nada — foi o mismatch nome×código que quebrou a
            // aderência em silêncio em julho de 2026.
            'codigo' => $l['codigo'],
            'nome' => $l['nome'],
            'inativos' => $l['inativos'],
        ], $porSegmento);

        return ['total' => (int) $total, 'linhas' => $linhas];
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
