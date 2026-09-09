<?php

namespace App\Services\Pedidos;

use App\Models\Pedido;

/**
 * Traduz o último movimento do pedido no TOTVS (texto livre) para o status do CRM.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUE ISTO EXISTE
 *
 * Até 2026-09-09 todo pedido em aberto entrava com `pendente_totvs` e a tela mostrava
 * "Aguardando classificação do TOTVS" em 100% das linhas — uma coluna inteira sem
 * informação, ao lado de outra que já dizia aberto/faturado. Conferido no banco de
 * produção naquele dia: 3.478 pedidos em aberto, TODOS `pendente_totvs`; 88.486
 * faturados, TODOS `faturado`. A coluna era uma função de `data_faturamento IS NULL`.
 *
 * A saída não foi esconder a coluna: o dado existia e estava sendo jogado fora. O
 * relatório 200 traz `HISTORICO` preenchido em 100% das linhas e o import nem o lia.
 *
 * ⚠️ A DOCUMENTAÇÃO DIZIA QUE ISSO ERA "TEXTO LIVRE SEM PADRÃO" — E ESTAVA ERRADA.
 * Medido nos 3.478 pedidos do arquivo real de 2026-09-08, normalizando os números:
 *
 *   PEDIDO # INCLUIDO NA CARGA #                              1.529   44,0%
 *   PEDIDO # COM BLOQUEIO DE ESTOQUE                          1.025   29,5%
 *   PEDIDO # LIBERADO PARA MONTAGEM DE CARGA / FATURAMENTO      419   12,0%
 *   ENVIO DO PEDIDO PARA O WMS - ORDEM DE SEPARACAO #           249    7,2%
 *   PEDIDO # COM BLOQUEIO DE CREDITO                            128    3,7%
 *   PEDIDO # COM REJEICAO DE CREDITO - <texto livre>             75    2,2%
 *   PEDIDO # COM BLOQUEIO DE ARTE                                20    0,6%
 *   RETORNO DO WMS ... COLETADO (TOTALMENTE SEPARADO)            14    0,4%
 *   IMPRESSAO DE NF / BOLETO / FATURADO POR PEDIDO NA NF         17    0,5%
 *   resto (NF cancelada, eliminação de resíduo, importada SAC)    5    0,1%
 *
 * É texto livre na CAUDA, não no começo: "REJEICAO DE CREDITO" tem ~25 grafias
 * diferentes depois do travessão (`NF #, VENCIDA`, `NFS VENCIDAS`, `SEM
 * MOVIMENT.FINANC.`, além de erros de digitação como `VENCDA` e `PRS`). Por isso os
 * moldes abaixo casam pelo MIOLO INVARIANTE da frase e ignoram o que vem depois.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMO ISTO DIFERE DA GAMBIARRA DO LEGADO (Regra de ouro nº 1)
 *
 * O legado também derivava status do mesmo texto, e era ruim por três motivos que aqui
 * não se repetem:
 *
 *   1. Ele fazia isso no JS do FRONT (`assets/js/pedidos-abertos.js`), espalhado. Aqui
 *      é um lugar só, e o resultado é gravado no banco (Regra de ouro nº 8).
 *   2. Ele casava por SUBSTRING SOLTA e genérica — `BLOQ|REJEIC|CANCEL` de um lado,
 *      `LIBER|FATUR|CONFIRM` do outro. Uma frase como "PEDIDO FATURADO POR PEDIDO NA NF
 *      - WMS" casava em duas regras ao mesmo tempo. Aqui cada molde é uma frase inteira
 *      e específica: `ENVIO DO PEDIDO PARA O WMS`, nunca `WMS`.
 *   3. Ele pintava de cinza o que não reconhecia, fingindo classificação. Aqui o que não
 *      casa vira `DESCONHECIDO`, a pill SOME da tela, e o texto cru do TOTVS continua
 *      gravado e visível ao expandir a linha — nada se perde e nada é inventado.
 *
 * ⚠️ OS MOLDES SÃO MUTUAMENTE EXCLUSIVOS, E ISSO É A INVARIANTE — não a ordem da lista.
 * Nenhuma frase do arquivo real casa em mais de um molde, então o resultado não depende
 * de qual vem primeiro (verificado, não suposto: uma primeira versão deste comentário
 * afirmava que a ordem decidia, e a mutação mostrou que não). O que a exclusividade
 * compra é que acrescentar um molde nunca muda a classificação dos que já existem.
 *
 * ⚠️ Molde GENÉRICO destrói essa propriedade e é o erro a evitar: trocar
 * "ENVIO DO PEDIDO PARA O WMS" por "WMS" faz "PEDIDO FATURADO POR PEDIDO NA NF - WMS"
 * casar em dois lugares, e aí a ordem passa a decidir em silêncio. Há teste que falha se
 * dois moldes voltarem a casar na mesma frase.
 *
 * ⚠️ SE O TOTVS MUDAR A REDAÇÃO, o pedido cai em `DESCONHECIDO` e a pill simplesmente
 * some — nunca vira status errado. E o import CONTA e AVISA quantos não casaram
 * (`ImportPedidosAbertosTotvs`), que é o que impede a mudança de passar despercebida.
 * Esse aviso é a única coisa que separa "degradou com elegância" de "quebrou em
 * silêncio".
 * ─────────────────────────────────────────────────────────────────────────────
 */
class StatusPedidoResolver
{
    public const BLOQUEIO_ESTOQUE = 'bloqueio_estoque';

    public const BLOQUEIO_CREDITO = 'bloqueio_credito';

    public const REJEICAO_CREDITO = 'rejeicao_credito';

    public const BLOQUEIO_ARTE = 'bloqueio_arte';

    public const LIBERADO = 'liberado';

    public const EM_CARGA = 'em_carga';

    public const SEPARACAO = 'separacao';

    public const SEPARADO = 'separado';

    public const FATURANDO = 'faturando';

    public const FATURADO = 'faturado';

    /**
     * Movimento que nenhum molde reconheceu — e o que o `pendente_totvs` virou.
     *
     * ⚠️ Não é "sem informação": o texto cru do TOTVS está gravado em
     * `pedidos.historico_totvs` e aparece ao expandir a linha do pedido. O que este
     * valor diz é que o CRM não sabe traduzir aquela frase para uma etapa do processo —
     * e, por isso, não mostra pill nenhuma em vez de mostrar um rótulo vazio de sentido.
     *
     * ⚠️ O NOME da constante mudou, o VALOR não. Continua `pendente_totvs` de propósito:
     * é o que já está gravado nas 3.478 linhas de produção, e trocar a string exigiria
     * um UPDATE em massa para não ganhar nada.
     */
    public const DESCONHECIDO = 'pendente_totvs';

    /**
     * Molde do TOTVS → status do CRM, na ordem em que são testados.
     *
     * A chave é o miolo invariante da frase, já normalizado (maiúsculas, sem acento,
     * espaços colapsados) — é a forma em que `normalizar()` entrega o texto.
     *
     * ⚠️ Pública para que o teste possa provar a exclusividade mútua descrita no
     * cabeçalho. É dado, não estado: quem classifica é `resolver()`, e ninguém mais
     * deve percorrer este mapa em código de produção.
     */
    public const MOLDES = [
        'COM BLOQUEIO DE CREDITO' => self::BLOQUEIO_CREDITO,
        'COM REJEICAO DE CREDITO' => self::REJEICAO_CREDITO,
        'COM BLOQUEIO DE ESTOQUE' => self::BLOQUEIO_ESTOQUE,
        'COM BLOQUEIO DE ARTE' => self::BLOQUEIO_ARTE,
        'LIBERADO PARA MONTAGEM DE CARGA' => self::LIBERADO,
        'INCLUIDO NA CARGA' => self::EM_CARGA,
        'ENVIO DO PEDIDO PARA O WMS' => self::SEPARACAO,
        'RETORNO DO PEDIDO DO WMS' => self::SEPARADO,
        'IMPRESSAO DE BOLETO' => self::FATURANDO,
        'VIA(S) DA NF' => self::FATURANDO,
        'FATURADO POR PEDIDO NA NF' => self::FATURANDO,
    ];

    /**
     * A ETAPA DO PROCESSO, em ordem — do mais travado ao mais adiantado.
     *
     * ⚠️ A sequência veio do Tony (2026-09-09), não do nome dos moldes: "incluído na
     * carga significa que montaram a carga, o próximo estágio é a separação". Ou seja,
     * `em_carga` vem ANTES de `separacao`, e não depois — que é o que a leitura ingênua
     * de "carga = está saindo" sugeriria.
     *
     * Serve para ordenar a lista do filtro e as opções na tela. Não é usada para
     * comparar dois pedidos: o TOTVS não garante que o processo só ande para a frente
     * (um pedido em carga pode voltar para bloqueio de crédito).
     *
     * @var list<string>
     */
    public const ETAPAS = [
        self::BLOQUEIO_ESTOQUE,
        self::BLOQUEIO_CREDITO,
        self::REJEICAO_CREDITO,
        self::BLOQUEIO_ARTE,
        self::LIBERADO,
        self::EM_CARGA,
        self::SEPARACAO,
        self::SEPARADO,
        self::FATURANDO,
        self::FATURADO,
    ];

    /**
     * O rótulo que aparece na tela e no Excel.
     *
     * ⚠️ MORA SÓ AQUI (Regra de ouro nº 8). O front NÃO tem cópia deste mapa: o
     * controller manda `statusRotulo` pronto em cada pedido, e `constants/pedidos.js`
     * guarda apenas a COR de cada status, que é decisão de front. Antes desta rodada o
     * rótulo existia em duas cópias e uma delas já tinha ficado para trás uma vez — foi
     * assim que `Detalhes.vue` passou a exibir a string crua `pendente_totvs`.
     */
    private const ROTULOS = [
        self::BLOQUEIO_ESTOQUE => 'Bloqueio de estoque',
        self::BLOQUEIO_CREDITO => 'Bloqueio de crédito',
        self::REJEICAO_CREDITO => 'Rejeição de crédito',
        self::BLOQUEIO_ARTE => 'Bloqueio de arte',
        self::LIBERADO => 'Liberado p/ carga',
        self::EM_CARGA => 'Em carga',
        self::SEPARACAO => 'Em separação',
        self::SEPARADO => 'Separado',
        self::FATURANDO => 'Faturando',
        self::FATURADO => 'Faturado',
    ];

    /**
     * O status do CRM para um movimento do TOTVS.
     *
     * Texto vazio e molde não reconhecido caem no mesmo lugar: {@see DESCONHECIDO}.
     */
    public function resolver(?string $historico): string
    {
        $texto = self::normalizar($historico);

        if ($texto === '') {
            return self::DESCONHECIDO;
        }

        foreach (self::MOLDES as $molde => $status) {
            if (str_contains($texto, $molde)) {
                return $status;
            }
        }

        return self::DESCONHECIDO;
    }

    /**
     * O rótulo de exibição, ou null quando não há o que mostrar.
     *
     * ⚠️ Devolver NULL para o desconhecido é deliberado e é o pedido original do Tony:
     * "se não tivermos nada a mostrar talvez seja melhor não mostrar nada". Quem chama
     * (controller, export) repassa o null e a tela não desenha pill nenhuma.
     */
    public function rotulo(?string $status): ?string
    {
        return self::ROTULOS[$status] ?? null;
    }

    /**
     * O bloco de status pronto para a tela, a partir de um pedido.
     *
     * ⚠️ Existe para não ser escrito três vezes. São TRÊS telas mostrando a mesma coisa
     * — `/pedidos-abertos`, `/pedidos-emitidos` e a ficha do cliente — e a última já
     * ficou para trás uma vez, exibindo a string crua `pendente_totvs` porque tinha cópia
     * própria do mapa de rótulos (Regra de ouro nº 8).
     *
     * `statusRotulo` vem NULL quando o movimento não foi reconhecido; a tela não desenha
     * pill nenhuma nesse caso. `movimento` é o texto cru do TOTVS e aparece ao expandir
     * a linha — é o que garante que classificar nunca destrói informação.
     *
     * @return array{status: string, statusRotulo: ?string, movimento: ?string, movimentoEm: ?string}
     */
    public function paraTela(Pedido $pedido): array
    {
        return [
            'status' => $pedido->status,
            'statusRotulo' => $this->rotulo($pedido->status),
            'movimento' => $pedido->historico_totvs,
            'movimentoEm' => optional($pedido->historico_em)->format('d/m/Y H:i'),
        ];
    }

    /**
     * Os status que podem aparecer num pedido EM ABERTO — a lista do filtro da tela.
     *
     * ⚠️ `faturado` fica de fora porque `/pedidos-abertos` filtra por
     * `data_faturamento IS NULL`: oferecê-lo daria uma opção que sempre devolve tela
     * vazia. Era exatamente o defeito da versão anterior, em que 5 das 6 opções do
     * filtro não retornavam nada.
     *
     * @return list<string>
     */
    public static function statusEmAberto(): array
    {
        return array_values(array_filter(self::ETAPAS, fn (string $s) => $s !== self::FATURADO));
    }

    /**
     * Todos os valores aceitos pela coluna `pedidos.status`, incluindo o desconhecido.
     *
     * É a lista que a migration do enum e a validação usam — um lugar só.
     *
     * @return list<string>
     */
    public static function todos(): array
    {
        return [...self::ETAPAS, self::DESCONHECIDO];
    }

    /**
     * Maiúsculas, sem acento, espaços colapsados.
     *
     * O TOTVS já manda sem acento ("REJEICAO", "SEPARACAO"), mas isso é observação do
     * arquivo de hoje, não garantia do formato — e um acento a mais faria o molde deixar
     * de casar em silêncio, que é a falha que este resolver mais precisa evitar.
     */
    private static function normalizar(?string $texto): string
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return '';
        }

        $semAcento = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return preg_replace('/\s+/', ' ', mb_strtoupper($semAcento === false ? $texto : $semAcento));
    }
}
