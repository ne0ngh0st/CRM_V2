<?php

namespace Tests\Unit\Pedidos;

use App\Services\Pedidos\StatusPedidoResolver;
use PHPUnit\Framework\TestCase;

/**
 * Trava a tradução do movimento do TOTVS para a etapa do pedido.
 *
 * ⚠️ TODAS as frases aqui foram COPIADAS do relatório 200 real (arquivo de 2026-09-08,
 * 3.478 pedidos), com só os números trocados quando o número não importa. Frase inventada
 * não prova nada sobre um formato que vem de fora — foi lendo o arquivo, e não o código,
 * que se descobriu que "texto livre sem padrão" eram 11 moldes estáveis.
 *
 * O resolver foi aferido contra o arquivo inteiro antes destes testes existirem: 99,86%
 * de cobertura (3.473 de 3.478), com os 5 restantes sendo NF cancelada, entrega
 * importada do SAC e eliminação de resíduo.
 */
class StatusPedidoResolverTest extends TestCase
{
    private StatusPedidoResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new StatusPedidoResolver;
    }

    /**
     * Os moldes que cobrem 99,86% dos pedidos em aberto.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function moldesReais(): array
    {
        return [
            'em carga (44,0% da base)' => [
                'PEDIDO 989123 INCLUIDO NA CARGA 190050',
                StatusPedidoResolver::EM_CARGA,
            ],
            'bloqueio de estoque (29,5%)' => [
                'PEDIDO 984613 COM BLOQUEIO DE ESTOQUE',
                StatusPedidoResolver::BLOQUEIO_ESTOQUE,
            ],
            'liberado para carga (12,0%)' => [
                'PEDIDO 994544 LIBERADO PARA MONTAGEM DE CARGA / FATURAMENTO',
                StatusPedidoResolver::LIBERADO,
            ],
            'em separação (7,2%)' => [
                'ENVIO DO PEDIDO PARA O WMS - ORDEM DE SEPARACAO 876758',
                StatusPedidoResolver::SEPARACAO,
            ],
            'bloqueio de crédito (3,7%)' => [
                'PEDIDO 999152 COM BLOQUEIO DE CREDITO',
                StatusPedidoResolver::BLOQUEIO_CREDITO,
            ],
            'bloqueio de arte (0,6%)' => [
                'PEDIDO 987073 COM BLOQUEIO DE ARTE',
                StatusPedidoResolver::BLOQUEIO_ARTE,
            ],
            'separado, retorno do WMS (0,4%)' => [
                'RETORNO DO PEDIDO DO WMS - ORDEM DE SEPARACAO 876854 STATUS COLETADO (TOTALMENTE SEPARADO)',
                StatusPedidoResolver::SEPARADO,
            ],
            'faturando, impressão de NF' => [
                'IMPRESSAO DE 2 VIA(S) DA NF 123456  /001',
                StatusPedidoResolver::FATURANDO,
            ],
            'faturando, impressão de boleto' => [
                'IMPRESSAO DE BOLETO',
                StatusPedidoResolver::FATURANDO,
            ],
        ];
    }

    /**
     * @dataProvider moldesReais
     */
    public function test_traduz_os_moldes_reais_do_relatorio(string $historico, string $esperado): void
    {
        $this->assertSame($esperado, $this->resolver->resolver($historico));
    }

    /**
     * As ~25 grafias de rejeição de crédito, todas do arquivo real — incluindo os erros
     * de digitação do pessoal do financeiro.
     *
     * ⚠️ É a prova de que o molde ancora no MIOLO e ignora a cauda. Um casamento por
     * frase inteira exigiria conhecer cada variação, e a próxima que alguém digitasse
     * cairia fora sem ninguém perceber.
     *
     * @return list<array{0: string}>
     */
    public static function grafiasDeRejeicao(): array
    {
        return array_map(fn (string $f) => [$f], [
            'PEDIDO 1 COM REJEICAO DE CREDITO - NF 123456, VENCIDA',
            'PEDIDO 1 COM REJEICAO DE CREDITO - NFS VENCIDAS 1,  2,  3',
            'PEDIDO 1 COM REJEICAO DE CREDITO - RPS 987, VENCIDA',
            'PEDIDO 1 COM REJEICAO DE CREDITO - DIVERSOS TIT.VENCIDOS',
            'PEDIDO 1 COM REJEICAO DE CREDITO - SEM MOVIMENT.FINANC.VENDAAVISTA',
            'PEDIDO 1 COM REJEICAO DE CREDITO - NF123, PGTO PARCIAL',
            // Erros de digitação reais, copiados do arquivo:
            'PEDIDO 1 COM REJEICAO DE CREDITO - NF456, VENCDA',
            'PEDIDO 1 COM REJEICAO DE CREDITO - PRS VENCIDA, 789',
            'PEDIDO 1 COM REJEICAO DE CREDITO - RPS, VEN CIDA 1',
            'PEDIDO 1 COM REJEICAO DE CREDITO - DIVERSOS TITULSO VENCIDOS',
            'PEDIDO 1 COM REJEICAO DE CREDITO - .',
        ]);
    }

    /**
     * @dataProvider grafiasDeRejeicao
     */
    public function test_pega_rejeicao_de_credito_em_qualquer_grafia(string $historico): void
    {
        $this->assertSame(StatusPedidoResolver::REJEICAO_CREDITO, $this->resolver->resolver($historico));
    }

    /**
     * ⚠️ ESTE É O CASO QUE QUEBRAVA O LEGADO. Lá o casamento era por substring solta
     * (`LIBER|FATUR|CONFIRM` contra `BLOQ|REJEIC|CANCEL`), e esta frase contém "FATURADO"
     * e "WMS" ao mesmo tempo — casava em duas regras e o resultado dependia da ordem dos
     * ifs. Aqui os moldes são frases específicas: "ENVIO DO PEDIDO PARA O WMS", nunca
     * "WMS" sozinho.
     */
    public function test_frase_com_wms_no_fim_nao_vira_separacao(): void
    {
        $this->assertSame(
            StatusPedidoResolver::FATURANDO,
            $this->resolver->resolver('PEDIDO 986336 FATURADO POR PEDIDO NA NF 12345  /001 - WMS')
        );
    }

    /**
     * O bloqueio duplo, que existe uma vez no arquivo real.
     *
     * ⚠️ Cai em crédito porque a frase contém "COM BLOQUEIO DE CREDITO" e NÃO contém
     * "COM BLOQUEIO DE ESTOQUE" ("CREDITO E ESTOQUE" é outra coisa) — não porque crédito
     * venha antes na lista. A primeira versão deste teste afirmava travar a ordem, e a
     * verificação por mutação provou que não travava: inverter a lista o mantinha verde.
     * Quem trava a propriedade que importa é o teste de exclusividade abaixo.
     */
    public function test_bloqueio_duplo_cai_no_credito(): void
    {
        $this->assertSame(
            StatusPedidoResolver::BLOQUEIO_CREDITO,
            $this->resolver->resolver('PEDIDO 1 COM BLOQUEIO DE CREDITO E ESTOQUE')
        );
    }

    /**
     * 🚨 A INVARIANTE DO RESOLVER: nenhuma frase casa em mais de um molde.
     *
     * É o que torna a classificação previsível e o que separa isto da regex do legado.
     * Enquanto vale, acrescentar um molde novo não pode mudar a classificação de nenhum
     * pedido que já existe, e a ordem da lista não decide nada.
     *
     * ⚠️ Quebra assim que alguém encurtar um molde para algo genérico — "WMS" no lugar
     * de "ENVIO DO PEDIDO PARA O WMS" faz duas frases reais casarem duas vezes. Foi por
     * aí que o legado errava.
     *
     * @dataProvider frasesReaisDoArquivo
     */
    public function test_nenhuma_frase_real_casa_em_mais_de_um_molde(string $frase): void
    {
        $normalizada = mb_strtoupper($frase);

        $casam = array_keys(array_filter(
            StatusPedidoResolver::MOLDES,
            fn (string $status, string $molde) => str_contains($normalizada, $molde),
            ARRAY_FILTER_USE_BOTH
        ));

        $this->assertLessThanOrEqual(
            1,
            count($casam),
            "A frase \"{$frase}\" casa em mais de um molde (".implode(' + ', $casam).'), '
            .'então a classificação passou a depender da ordem da lista.'
        );
    }

    /**
     * Uma frase de cada molde do arquivo real, mais as que não são reconhecidas.
     *
     * @return list<array{0: string}>
     */
    public static function frasesReaisDoArquivo(): array
    {
        $frases = array_map(fn (array $caso) => $caso[0], array_values(self::moldesReais()));

        return array_map(fn (string $f) => [$f], array_merge($frases, [
            'PEDIDO 1 COM REJEICAO DE CREDITO - NF 123456, VENCIDA',
            'PEDIDO 1 COM BLOQUEIO DE CREDITO E ESTOQUE',
            'PEDIDO 986336 FATURADO POR PEDIDO NA NF 12345  /001 - WMS',
            'NOTA FISCAL CANCELADA - NF 1  /001123689',
            'NF 1  /000888071 COM ENTREGA REALIZADA NO DIA 11/06/25 - IMPORTADA SAC',
            'ELIMINACAO DE RESIDUOS - PEDIDO 930643 ITEM 01',
        ]));
    }

    /**
     * Os 5 movimentos do arquivo real que o CRM não traduz, mais o caso vazio.
     *
     * ⚠️ O comportamento correto é NÃO CLASSIFICAR, nunca chutar. O legado pintava de
     * cinza o que não reconhecia, fingindo que era uma etapa; aqui a pill some e o texto
     * cru fica gravado.
     *
     * @return array<string, array{0: ?string}>
     */
    public static function movimentosNaoReconhecidos(): array
    {
        return [
            'nota fiscal cancelada' => ['NOTA FISCAL CANCELADA - NF 1  /001123689'],
            'entrega importada do SAC' => ['NF 1  /000888071 COM ENTREGA REALIZADA NO DIA 11/06/25 - IMPORTADA SAC'],
            'eliminação de resíduo' => ['ELIMINACAO DE RESIDUOS - PEDIDO 930643 ITEM 01'],
            'redação futura que ninguém previu' => ['PEDIDO 1 AGUARDANDO CONFERENCIA FISCAL'],
            'string vazia' => [''],
            'só espaços' => ['   '],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider movimentosNaoReconhecidos
     */
    public function test_movimento_desconhecido_nao_vira_etapa_chutada(?string $historico): void
    {
        $status = $this->resolver->resolver($historico);

        $this->assertSame(StatusPedidoResolver::DESCONHECIDO, $status);
        // E o mais importante: sem rótulo, a tela não desenha pill nenhuma.
        $this->assertNull($this->resolver->rotulo($status));
    }

    /**
     * ⚠️ O TOTVS manda sem acento hoje, mas isso é observação do arquivo, não garantia
     * do formato. Um acento a mais faria o molde deixar de casar em silêncio — que é
     * justamente a falha invisível que este resolver existe para evitar.
     */
    public function test_acento_e_caixa_nao_impedem_o_casamento(): void
    {
        $this->assertSame(
            StatusPedidoResolver::SEPARACAO,
            $this->resolver->resolver('Envio do pedido para o WMS - Ordem de Separação 876758')
        );
    }

    /**
     * ⚠️ Oferecer `faturado` no filtro de /pedidos-abertos daria uma opção que sempre
     * devolve tela vazia, porque a query filtra `data_faturamento IS NULL`. Era o defeito
     * da versão anterior — 5 das 6 opções não retornavam nada.
     */
    public function test_filtro_de_abertos_nao_oferece_faturado(): void
    {
        $emAberto = StatusPedidoResolver::statusEmAberto();

        $this->assertNotContains(StatusPedidoResolver::FATURADO, $emAberto);
        $this->assertContains(StatusPedidoResolver::BLOQUEIO_ESTOQUE, $emAberto);
        $this->assertContains(StatusPedidoResolver::EM_CARGA, $emAberto);
    }

    /**
     * ⚠️ Toda etapa precisa de rótulo. Sem esta checagem, acrescentar um status novo em
     * ETAPAS e esquecer de nomeá-lo passaria despercebido até alguém ver a coluna vazia
     * na tela — e a pill sumindo pareceria "movimento não reconhecido", que é outra
     * coisa completamente diferente.
     */
    public function test_toda_etapa_tem_rotulo_e_o_desconhecido_nao_tem(): void
    {
        foreach (StatusPedidoResolver::ETAPAS as $etapa) {
            $this->assertNotNull($this->resolver->rotulo($etapa), "A etapa {$etapa} está sem rótulo.");
        }

        $this->assertNull($this->resolver->rotulo(StatusPedidoResolver::DESCONHECIDO));
        $this->assertContains(StatusPedidoResolver::DESCONHECIDO, StatusPedidoResolver::todos());
    }

    /**
     * ⚠️ A ordem de ETAPAS é a do PROCESSO, e veio do Tony: "incluído na carga significa
     * que montaram a carga, o próximo estágio é a separação". A leitura ingênua colocaria
     * a carga depois da separação — este teste é o que impede alguém de "corrigir" isso.
     *
     * ⚠️ Não confundir com a ordem de MOLDES, que não decide nada (ver o teste de
     * exclusividade). ETAPAS ordena o que o usuário vê no filtro; MOLDES é só a lista de
     * frases a testar.
     */
    public function test_carga_vem_antes_da_separacao_no_processo(): void
    {
        $ordem = array_flip(StatusPedidoResolver::ETAPAS);

        $this->assertLessThan($ordem[StatusPedidoResolver::SEPARACAO], $ordem[StatusPedidoResolver::EM_CARGA]);
        $this->assertLessThan($ordem[StatusPedidoResolver::EM_CARGA], $ordem[StatusPedidoResolver::LIBERADO]);
        $this->assertLessThan($ordem[StatusPedidoResolver::LIBERADO], $ordem[StatusPedidoResolver::BLOQUEIO_ESTOQUE]);
    }
}
