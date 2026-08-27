<?php

namespace Tests\Unit;

use App\Services\Cadastros\SolicitacaoTituloResolver;
use PHPUnit\Framework\TestCase;

/**
 * Trava as regras de nome do título TOTVS contra o legado.
 *
 * Os valores esperados aqui foram gerados rodando as funções originais
 * (bobina_gerar_titulo_padronizado / gerar_titulo_padronizado_etiqueta),
 * não escritos à mão. Se um caso quebrar, a pergunta é se o legado mudou —
 * o time de Cadastro usa esse texto literalmente pra abrir o item no TOTVS.
 */
class SolicitacaoTituloResolverTest extends TestCase
{
    private SolicitacaoTituloResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SolicitacaoTituloResolver;
    }

    /** A gramatura tem precedência sobre o campo `papel`. */
    public function test_bobina_usa_nomenclatura_da_gramatura(): void
    {
        $this->assertSame(
            'BOBINA TS KPH BC 80X30M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'termicco', '80', '30', '44'),
        );

        $this->assertSame(
            'BOBINA TERMICO 80X30M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'kpr', '80', '30', '48'),
        );

        $this->assertSame(
            'BOBINA TERMOSCRIPT 80X30M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'kpr', '80', '30', '55'),
        );
    }

    /** Sem papel preenchido, a gramatura ainda resolve o tipo sozinha. */
    public function test_bobina_resolve_tipo_sem_papel(): void
    {
        $this->assertSame(
            'BOBINA TERMICO 80X30M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', null, '80', '30', '48'),
        );
    }

    /** Gramatura ausente ou fora do mapa (72/105/167) cai no papel, como no legado. */
    public function test_bobina_cai_no_papel_quando_gramatura_nao_mapeia(): void
    {
        $this->assertSame(
            'BOBINA KPR 57X22M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'kpr', '57', '22', null),
        );

        $this->assertSame(
            'BOBINA TERMOTICKET 57X22M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'termoticket', '57', '22', '105'),
        );
    }

    public function test_bobina_formata_dimensoes(): void
    {
        $this->assertSame(
            'BOBINA TERMICO 80X30.5M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'termicco', '80', '30,5', '48'),
        );

        // Só largura → sufixo MM; só metragem → sufixo M.
        $this->assertSame(
            'BOBINA TERMICO 80MM CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'termicco', '80', null, '48'),
        );

        $this->assertSame(
            'BOBINA TERMICO 30M CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'termicco', null, '30', '48'),
        );

        // Zero não é dimensão válida.
        $this->assertSame(
            'BOBINA TERMICO CLIENTE X',
            $this->resolver->bobina('CLIENTE X', 'termicco', '0', '0', '48'),
        );
    }

    /**
     * A descrição das medidas vem ANTES da dimensão, a metragem é sufixada na
     * dimensão, e o adesivo é sufixo do título inteiro separado por " - ".
     */
    public function test_etiqueta_separa_descricao_da_dimensao(): void
    {
        $this->assertSame(
            'ETIQUETA SEGURANÇA LATERAIS 40X40X30M BARONESA - TÉRMICO BORRACHA COM BARREIRA',
            $this->resolver->etiqueta(
                'BARONESA',
                '40X40 SEGURANÇA LATERAIS',
                'Térmico Borracha com Barreira',
                30,
            ),
        );
    }

    public function test_etiqueta_sem_descricao_nas_medidas(): void
    {
        $this->assertSame(
            'ETIQUETA 40X30X50M BARONESA - ACRÍLICO',
            $this->resolver->etiqueta('BARONESA', '40X30', 'Acrílico', 50),
        );
    }

    /** Medida que não começa com dimensão vira descrição inteira, sem perder espaço. */
    public function test_etiqueta_medida_fora_do_padrao_dimensional(): void
    {
        $this->assertSame(
            'ETIQUETA PICOTE X + LATERAIS 40X25 100M ACME - HOTMELT',
            $this->resolver->etiqueta('ACME', 'PICOTE X + LATERAIS 40X25', 'Hotmelt', 100),
        );
    }

    public function test_etiqueta_sem_metragem_e_sem_adesivo(): void
    {
        $this->assertSame(
            'ETIQUETA 20X15 ACME',
            $this->resolver->etiqueta('ACME', '20X15', null, null),
        );
    }

    public function test_etiqueta_sem_medidas(): void
    {
        $this->assertSame(
            'ETIQUETA 40M ACME - ACRÍLICO',
            $this->resolver->etiqueta('ACME', null, 'Acrílico', 40),
        );
    }

    /**
     * A saída de rolo (F1–F4) fica na arte e nunca compõe o título — era o que
     * o v2 fazia de errado, e como o default era 'f1', TODA etiqueta saía com ele.
     */
    public function test_etiqueta_nao_leva_saida_de_rolo_no_titulo(): void
    {
        $titulo = $this->resolver->etiqueta('ACME', '40X30', 'Acrílico', 50);

        $this->assertStringNotContainsStringIgnoringCase(' F1', $titulo);
        $this->assertStringNotContainsStringIgnoringCase(' F2', $titulo);
        $this->assertStringNotContainsStringIgnoringCase(' F3', $titulo);
        $this->assertStringNotContainsStringIgnoringCase(' F4', $titulo);
    }

    /** Espaço extra em qualquer parte não sobrevive à normalização. */
    public function test_normaliza_espacos(): void
    {
        $this->assertSame(
            'ETIQUETA SEGURANÇA PADRÃO 40X40 ACME',
            $this->resolver->etiqueta('  ACME  ', '40X40    SEGURANÇA   PADRÃO', null, null),
        );

        $this->assertSame(
            'BOBINA TERMICO 80X30M CLIENTE X',
            $this->resolver->bobina('  CLIENTE X ', 'termicco', '80', '30', '48'),
        );
    }
}
