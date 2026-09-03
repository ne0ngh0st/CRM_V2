<?php

namespace Tests\Unit\Orcamento;

use App\Services\Orcamento\OrcamentoCalculoService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Trava a matemática de IPI e o contrato dos totais do orçamento.
 *
 * ⚠️ ESTES FIXTURES SÃO COMPARTILHADOS COM O FRONT. Os mesmos casos rodam em
 * `resources/js/utils/orcamento.js`, e o teste de paridade lá embaixo guarda os números
 * que o JS produziu. O par PHP↔JS existe porque a tela precisa calcular sem ida ao
 * servidor; o que não pode é cada lado ter a sua própria aritmética — foi assim que a
 * tela e o PDF passaram a divergir em centavos.
 *
 * Até 2026-09-03 não havia NENHUM teste sobre IPI, desconto ou subtotal neste projeto.
 */
class OrcamentoCalculoServiceTest extends TestCase
{
    private OrcamentoCalculoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OrcamentoCalculoService;
    }

    /** @return array<string, array{0: string, 1: list<array<string, mixed>>}> */
    public static function documentos(): array
    {
        return [
            // O caso real que originou a mudança: orçamento 2110 (KNTT), todo de etiquetas,
            // em modo Serviço. Antes imprimia "Subtotal s/ IPI 2.224,80" + "Subtotal
            // etiquetas 8.832,00" — dois baldes de mesma natureza com nomes que sugeriam
            // tributação diferente, num documento sem IPI nenhum.
            'orcamento 2110 - servico, so etiquetas' => ['servico', [
                ['tipo_item' => 'outro', 'quantidade' => 360, 'valor_unitario' => 6.18, 'calcula_ipi' => false],
                ['tipo_item' => 'etiqueta', 'quantidade' => 960, 'valor_unitario' => 9.20, 'calcula_ipi' => false],
            ]],
            'produto com IPI em todos os itens' => ['produto', [
                ['tipo_item' => 'bobina', 'quantidade' => 100, 'valor_unitario' => 10.00, 'calcula_ipi' => true],
                ['tipo_item' => 'bobina', 'quantidade' => 7, 'valor_unitario' => 3.33, 'calcula_ipi' => true],
            ]],
            'produto misto - bobina com IPI e etiqueta sem' => ['produto', [
                ['tipo_item' => 'bobina', 'quantidade' => 100, 'valor_unitario' => 10.00, 'calcula_ipi' => true],
                ['tipo_item' => 'etiqueta', 'quantidade' => 960, 'valor_unitario' => 9.20, 'calcula_ipi' => true],
            ]],
            // ⚠️ Fixture SENSÍVEL À ORDEM DE OPERAÇÃO, escolhido por busca e não por intuição.
            // Com qtd=2 e unit=1,51: round(2 x 1,51, 2)/1,0325 = 2,92, mas 2 x (1,51/1,0325)
            // = 2,93. Um centavo — e era exatamente essa a divergência estrutural entre a
            // tela (que multiplicava depois de dividir) e o PDF (que divide o total).
            // Sem um caso assim, a suíte inteira passa com as duas ordens e não prova nada.
            'ordem de operacao — divergem em 1 centavo' => ['produto', [
                ['tipo_item' => 'bobina', 'quantidade' => 2, 'valor_unitario' => 1.51, 'calcula_ipi' => true],
            ]],
            // ⚠️ Fixture SENSÍVEL À DERIVAÇÃO DO IPI, também achado por busca. Aqui a
            // subtração (total - subtotal) dá 9,44, enquanto somar o valorIpiTotal linha a
            // linha dá 9,45 — porque cada linha arredonda em 2 casas antes de somar. É a
            // sobra de centavo que faz as três linhas do documento não fecharem.
            'derivacao do IPI — subtrair difere de somar por linha' => ['produto', [
                ['tipo_item' => 'bobina', 'quantidade' => 41, 'valor_unitario' => 5.22, 'calcula_ipi' => true],
                ['tipo_item' => 'bobina', 'quantidade' => 10, 'valor_unitario' => 6.37, 'calcula_ipi' => true],
                ['tipo_item' => 'bobina', 'quantidade' => 22, 'valor_unitario' => 1.01, 'calcula_ipi' => true],
            ]],
            'valores quebrados que forcam arredondamento' => ['produto', [
                ['tipo_item' => 'bobina', 'quantidade' => 3, 'valor_unitario' => 0.07, 'calcula_ipi' => true],
                ['tipo_item' => 'bobina', 'quantidade' => 11, 'valor_unitario' => 1.01, 'calcula_ipi' => true],
                ['tipo_item' => 'outro', 'quantidade' => 1, 'valor_unitario' => 0.01, 'calcula_ipi' => true],
            ]],
        ];
    }

    /**
     * A propriedade que o contrato antigo NÃO tinha: as linhas do documento fecham.
     * Antes, com IPI, `subtotalProdutos + subtotalEtiquetas` não dava `totalGeral`.
     *
     * ⚠️ Hoje o fechamento é estrutural (valorIpi nasce de uma subtração), então este teste
     * NÃO pega erro de valor — quem faz isso é o de paridade com o JS. O que ele protege é
     * a tentativa futura, plausível, de derivar o IPI de forma independente: somar o
     * `valorIpiTotal` de cada item, por exemplo, reintroduz sobra de centavo. Verificado por
     * mutação: com a soma por item, este teste falha.
     */
    #[Test]
    #[DataProvider('documentos')]
    public function test_subtotal_mais_ipi_sempre_fecha_com_o_total(string $tipo, array $itens): void
    {
        $resumo = $this->resumoDe($tipo, $itens);

        $this->assertSame(
            $resumo['totalGeral'],
            round($resumo['subtotalSemIpi'] + $resumo['valorIpi'], 2),
            'subtotalSemIpi + valorIpi tem que dar exatamente totalGeral, em centavos',
        );
    }

    #[Test]
    public function test_orcamento_2110_reproduz_os_numeros_do_pdf_real(): void
    {
        [$tipo, $itens] = self::documentos()['orcamento 2110 - servico, so etiquetas'];

        $resumo = $this->resumoDe($tipo, $itens);

        $this->assertSame(11056.80, $resumo['totalGeral']);
        $this->assertSame(11056.80, $resumo['subtotalSemIpi']);
        // O ponto da correção: sem IPI, não há linha de IPI para exibir.
        $this->assertSame(0.0, $resumo['valorIpi']);
    }

    #[Test]
    public function test_documento_de_servico_nunca_tem_ipi(): void
    {
        $resumo = $this->resumoDe('servico', [
            // calcula_ipi = true de propósito: em modo Serviço o flag do item é irrelevante.
            ['tipo_item' => 'bobina', 'quantidade' => 100, 'valor_unitario' => 10.00, 'calcula_ipi' => true],
        ]);

        $this->assertSame(0.0, $resumo['valorIpi']);
        $this->assertSame(1000.00, $resumo['subtotalSemIpi']);
    }

    /** Regra fiscal real da Autopel, e a origem da confusão do 2110. */
    #[Test]
    public function test_etiqueta_nunca_participa_de_ipi_mesmo_marcada_em_documento_de_produto(): void
    {
        $item = ['tipo_item' => 'etiqueta', 'calcula_ipi' => true];

        $this->assertFalse($this->service->itemParticipaIpi('produto', $item));

        $resumo = $this->resumoDe('produto', [
            ['tipo_item' => 'etiqueta', 'quantidade' => 960, 'valor_unitario' => 9.20, 'calcula_ipi' => true],
        ]);

        $this->assertSame(0.0, $resumo['valorIpi'], 'etiqueta entra pelo valor de face');
        $this->assertSame(8832.00, $resumo['subtotalSemIpi']);
    }

    /**
     * `baseParaDesconto` alimenta o NivelAprovacaoCalculator. Se comparasse o valor COM IPI
     * contra o preço de tabela (que é sem IPI), o desconto sairia inflado em 3,25% e itens
     * cruzariam as fronteiras de 10%/15% sem motivo. O legado faz exatamente isso; aqui é
     * deliberadamente diferente.
     */
    #[Test]
    public function test_base_de_desconto_remove_o_ipi_embutido_so_de_quem_participa(): void
    {
        $comIpi = ['valor_unitario' => 10.00, 'tipo_item' => 'bobina', 'calcula_ipi' => true];
        $etiqueta = ['valor_unitario' => 10.00, 'tipo_item' => 'etiqueta', 'calcula_ipi' => true];

        $this->assertSame(9.6852, $this->service->baseParaDesconto('produto', $comIpi));
        $this->assertSame(10.00, $this->service->baseParaDesconto('produto', $etiqueta));
        $this->assertSame(10.00, $this->service->baseParaDesconto('servico', $comIpi));
    }

    /**
     * Paridade com o front: os valores esperados aqui foram GERADOS rodando os mesmos
     * fixtures em `resources/js/utils/orcamento.js`, não escritos à mão. Se este teste
     * falhar, os dois lados divergiram — é exatamente o defeito que ele existe para pegar.
     */
    #[Test]
    #[DataProvider('paridadeComOFront')]
    public function test_php_produz_os_mesmos_totais_que_o_js(string $caso, array $esperado): void
    {
        [$tipo, $itens] = self::documentos()[$caso];

        $this->assertSame($esperado, $this->resumoDe($tipo, $itens));
    }

    /** @return array<string, array{0: string, 1: array<string, float>}> */
    public static function paridadeComOFront(): array
    {
        return [
            'orcamento 2110' => ['orcamento 2110 - servico, so etiquetas',
                ['subtotalSemIpi' => 11056.80, 'valorIpi' => 0.0, 'totalGeral' => 11056.80]],
            'produto com IPI' => ['produto com IPI em todos os itens',
                ['subtotalSemIpi' => 991.10, 'valorIpi' => 32.21, 'totalGeral' => 1023.31]],
            'produto misto' => ['produto misto - bobina com IPI e etiqueta sem',
                ['subtotalSemIpi' => 9800.52, 'valorIpi' => 31.48, 'totalGeral' => 9832.00]],
            'ordem de operacao' => ['ordem de operacao — divergem em 1 centavo',
                ['subtotalSemIpi' => 2.92, 'valorIpi' => 0.10, 'totalGeral' => 3.02]],
            'derivacao do IPI' => ['derivacao do IPI — subtrair difere de somar por linha',
                ['subtotalSemIpi' => 290.50, 'valorIpi' => 9.44, 'totalGeral' => 299.94]],
            'valores quebrados' => ['valores quebrados que forcam arredondamento',
                ['subtotalSemIpi' => 10.97, 'valorIpi' => 0.36, 'totalGeral' => 11.33]],
        ];
    }

    /**
     * Reproduz o que o controller faz: grava `valor_total = round(qtd * unit, 2)` e só
     * então pede o cálculo. A ordem importa — ver o docblock de utils/orcamento.js.
     *
     * @param  list<array<string, mixed>>  $itens
     * @return array{subtotalSemIpi: float, valorIpi: float, totalGeral: float}
     */
    private function resumoDe(string $tipo, array $itens): array
    {
        $calculados = array_map(function (array $item) use ($tipo) {
            $item['valor_total'] = round($item['quantidade'] * $item['valor_unitario'], 2);

            return $this->service->calcularItem($item, $tipo);
        }, $itens);

        return $this->service->resumo($calculados);
    }
}
