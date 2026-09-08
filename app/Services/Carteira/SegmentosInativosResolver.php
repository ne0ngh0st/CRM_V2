<?php

namespace App\Services\Carteira;

use App\Models\Cliente;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;

/**
 * Quantos clientes INATIVOS há em cada segmento que o escopo ATENDE.
 *
 * ⚠️ SÓ OS SEGMENTOS ATENDIDOS entram, e o nome do card é literal. Uma versão intermediária
 * listava todo segmento onde a pessoa tivesse cliente inativo — o Tony corrigiu em
 * 2026-09-08: "não é pra mostrar todos os segmentos nos segmentos atendidos, é só os
 * atendidos mesmo; o resto é na pill".
 *
 * ⚠️ Consequência conhecida e aceita: o segmento atendido aparece MESMO COM ZERO inativos,
 * e para parte da equipe o card fica todo em zero. Medido em 2026-09-06: 24 dos 142
 * vendedores com segmento cadastrado não têm nenhum inativo no próprio segmento. Zero aqui
 * é resposta boa — quer dizer que o segmento de cadastro está em dia —, e quem quiser o
 * total da carteira inteira tem o card "Carteira por Segmento" ao lado.
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
     * @return array{total:int, totalCarteira:int, totalPotencial:int, linhas:list<array{codigo:string, nome:string, inativos:int, peso:float, potencial:int}>}
     */
    public function resolver(?array $codVendedores): array
    {
        $atendidos = $this->segmentosAtendidos($codVendedores);

        $inativosPorCodigo = [];
        $totalCarteira = 0;

        foreach ($this->inativosPorSegmento($codVendedores) as $l) {
            $inativosPorCodigo[$l['codigo']] = $l['inativos'];
            $totalCarteira += $l['inativos'];
        }

        /*
         * ⚠️ A lista PARTE DOS SEGMENTOS ATENDIDOS, não do que existe de cliente inativo.
         * Segmento atendido sem nenhum inativo entra com zero; segmento onde a pessoa tem
         * inativo mas que não é dela NÃO entra — é o card "Carteira por Segmento" que
         * responde por esses (o balde "fora do segmento").
         *
         * ⚠️ `(string)` no código não é redundante: `pluck('nome', 'codigo')` devolve array
         * PHP, e o PHP converte chave numérica em INTEIRO. Todas as comparações aqui são
         * estritas, então sem o cast `in_array(109, ['109'], true)` dá false e o segmento
         * atendido some da lista em silêncio — foi assim que este bug apareceu.
         */
        $linhas = [];

        foreach ($this->segmentosPorCodigo($atendidos) as $codigo => $segmento) {
            $codigo = (string) $codigo;
            $inativos = $inativosPorCodigo[$codigo] ?? 0;
            $peso = (float) $segmento->peso_potencial;

            $linhas[] = [
                // O CÓDIGO viaja junto porque a linha é link para a Carteira, e o filtro de
                // lá compara `clientes.cod_segmento`, que é o código bruto. Mandar o nome
                // faria o filtro não casar nada.
                'codigo' => $codigo,
                'nome' => (string) $segmento->nome,
                'inativos' => $inativos,
                // O peso viaja junto para a tela poder mostrar a conta ("188 × 8"). Número
                // que ninguém consegue conferir é número em que ninguém confia.
                'peso' => $peso,
                'potencial' => (int) round($inativos * $peso),
            ];
        }

        /*
         * ⚠️ Ordena por POTENCIAL, com inativos como desempate — é o que a coluna existe
         * para responder. Dois segmentos com peso 0 ficam empatados em 0 e o desempate por
         * inativos os deixa em ordem estável entre si.
         */
        usort($linhas, fn (array $a, array $b) => [$b['potencial'], $b['inativos']] <=> [$a['potencial'], $a['inativos']]);

        // ⚠️ Os totais são a soma DAS LINHAS, sempre. É o que impede o rodapé de anunciar um
        // número que a tabela não explica.
        return [
            'total' => (int) array_sum(array_column($linhas, 'inativos')),
            // Inativos da carteira INTEIRA, atendidos ou não — é o número que o card
            // "Carteira por Segmento" mostra, e existe aqui só para a tela não se
            // contradizer. Nunca usar como denominador de potencial.
            'totalCarteira' => (int) $totalCarteira,
            'totalPotencial' => (int) array_sum(array_column($linhas, 'potencial')),
            'linhas' => $linhas,
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
     * Nome e peso de cada código informado. Uma query numa tabela de 23 linhas.
     *
     * ⚠️ `keyBy('codigo')` devolve array PHP, e o PHP converte chave numérica em INTEIRO —
     * daí o `(string)` em quem consome. Foi assim que o segmento atendido sumiu da lista em
     * silêncio na primeira execução desta regra, porque as comparações aqui são estritas.
     *
     * @param  list<string>  $codigos
     * @return array<string, Segmento>
     */
    private function segmentosPorCodigo(array $codigos): array
    {
        if ($codigos === []) {
            return [];
        }

        return Segmento::query()
            ->whereIn('codigo', $codigos)
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
