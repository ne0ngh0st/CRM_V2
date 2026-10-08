<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Services\VisaoDiretor\VinculoPorRazaoSocial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vínculo pela identidade jurídica (razão social + CNPJ raiz). Cada caso nasceu do teste
 * contra produção em 08/10/2026 com as 200 redes da ABRAS.
 */
class VinculoPorRazaoSocialTest extends TestCase
{
    use RefreshDatabase;

    private int $loja = 0;

    private function filial(string $codigo, string $cnpj, string $razao): void
    {
        $this->loja++;
        Cliente::create([
            'cod_cliente' => $codigo, 'loja' => str_pad((string) $this->loja, 4, '0', STR_PAD_LEFT),
            'razao_social' => $razao, 'cnpj' => vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj)),
            'cod_grupo' => '9998', 'cod_segmento' => '101', 'cod_vendedor' => '000001', 'estado' => 'SP',
        ]);
    }

    private function clientes(string ...$razoes): array
    {
        $servico = app(VinculoPorRazaoSocial::class);

        return $servico->clientes($razoes, $servico->catalogo());
    }

    /** A filial com razão digitada diferente vem pelo CNPJ raiz. */
    public function test_razao_igual_e_as_outras_filiais_da_mesma_raiz(): void
    {
        $this->filial('000100', '11111111000101', 'SUPERMERCADOS BAKLIZI LTDA');
        $this->filial('000100', '11111111000282', 'SUPERMERCADOS BAKLIZI LTDA. - FILIAL');
        $this->filial('000101', '11111111000363', 'END.ENTREGA E001');
        $this->filial('000200', '22222222000101', 'OUTRO MERCADO LTDA');

        $this->assertSame(['000100', '000101'], $this->clientes('SUPERMERCADOS BAKLIZI LTDA.'));
    }

    /** Mutação: trocar a igualdade por "começa com". */
    public function test_comeco_de_nome_nao_basta(): void
    {
        $this->filial('000100', '11111111000101', 'SUPERMERCADO BEL LTDA');
        $this->filial('000200', '22222222000101', 'CASA SANTA LUZIA IMPORTADORA LTDA');

        $this->assertSame([], $this->clientes('SUPERMERCADO BELTRAME LTDA.'));
        $this->assertSame([], $this->clientes('CASA SANTA LTDA.'));
    }

    /** O TOTVS corta a razão social em 40 caracteres, no meio da palavra. */
    public function test_razao_cortada_pelo_totvs_casa_pelo_comeco(): void
    {
        $this->filial('005507', '11111111000101', 'SONDA SUPERMERCADOS EXPORTACAO E IMPORTA');

        $this->assertSame(['005507'], $this->clientes('SONDA SUPERMERCADOS EXPORTAÇÃO E IMPORTAÇÃO S.A.'));
    }

    /** Sufixo societário e pontuação não distinguem: S/A, LTDA, "A.C.D.A". */
    public function test_ignora_sufixo_e_pontuacao(): void
    {
        $this->filial('000100', '11111111000101', 'A. C. D. A. IMPORTACAO E EXPORTACAO S/A');

        $this->assertSame(['000100'], $this->clientes('A.C.D.A IMPORTAÇÃO E EXPORTAÇÃO LTDA.'));
    }

    /** "& CIA" × "E CIA" e plural: casos reais (Giassi, Juba) que o nome exato perdia. */
    public function test_ignora_cia_conectivos_e_plural(): void
    {
        $this->filial('000100', '11111111000101', 'GIASSI & CIA LTDA');
        $this->filial('000200', '22222222000101', 'JUBA SUPERMERCADOS LTDA');

        $this->assertSame(['000100'], $this->clientes('GIASSI E CIA. LTDA.'));
        $this->assertSame(['000200'], $this->clientes('JUBA SUPERMERCADO LTDA.'));
    }

    /**
     * Abreviação da ABRAS e "auto serviço" separado: casos reais que a regra estrita apagou
     * em produção (Pague Menos, Roldão com 53 lojas, Agricer).
     *
     * Mutação: tirar a chamada de `abreviada()`.
     */
    public function test_abreviacao_de_termo_e_auto_servico(): void
    {
        $this->filial('000100', '11111111000101', 'PAGUE MENOS COMERCIO DE PRODUTOS ALIMENTICIOS LTDA');
        $this->filial('000200', '22222222000101', 'ROLDAO AUTO SERVICO COMERCIO DE ALIMENTOS S/A');
        $this->filial('000300', '33333333000101', 'AGRICER DIST. E COM. DE PRODUTOS ALIMENTICIOS LTDA');

        $this->assertSame(['000100'], $this->clientes('PAGUE MENOS COM. DE PROD. ALIM. LTDA.'));
        $this->assertSame(['000200'], $this->clientes('ROLDÃO AUTOSSERVICO COMÉRCIO DE ALIMENTOS LTDA.'));
        $this->assertSame(['000300'], $this->clientes('AGRICER DISTRIBIDORA E COMERCIAL DE PROD. ALIM. LTDA.'));
    }

    /**
     * Abreviação vale só para palavra de tipo de negócio: na marca, "SUPER" não é
     * "SUPERBOM" e "BEL" não é "BELTRAME".
     *
     * Mutação: aceitar qualquer palavra como abreviação (tirar o filtro de `TERMOS`).
     */
    public function test_abreviacao_nao_vale_para_a_marca(): void
    {
        $this->filial('000100', '11111111000101', 'SUPERBOM COMERCIO DE ALIMENTOS LTDA');
        $this->filial('000200', '22222222000101', 'SUPERMERCADO BEL LTDA');

        $this->assertSame([], $this->clientes('SUPER COMERCIO DE ALIMENTOS LTDA.'));
        $this->assertSame([], $this->clientes('SUPERMERCADO BELTRAME LTDA.'));
    }

    /** Mutação: tirar a regra da maioria. */
    public function test_codigo_com_maioria_de_outra_empresa_fica_de_fora(): void
    {
        $this->filial('000100', '11111111000101', 'HIGA PRODUTOS ALIMENTICIOS LTDA');
        $this->filial('000900', '11111111000282', 'HIGA PRODUTOS ALIMENTICIOS LTDA');
        $this->filial('000900', '33333333000101', 'ESCOLA A');
        $this->filial('000900', '44444444000101', 'ESCOLA B');

        $this->assertSame(['000100'], $this->clientes('HIGA PRODUTOS ALIMENTÍCIOS LTDA.'));
    }
}
