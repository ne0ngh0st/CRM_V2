<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\GrupoCliente;
use App\Services\VisaoDiretor\SugestaoDeVinculo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A regra de palpite da carga inicial. Conservadora: errar o vínculo é pior do que
 * deixar a conta no relatório. Cada caso aqui nasceu de um falso positivo (ou de um
 * miss) medido na planilha real contra o palma_v2 em 18/09/2026.
 */
class SugestaoDeVinculoTest extends TestCase
{
    use RefreshDatabase;

    private SugestaoDeVinculo $sugestao;

    private int $filiais = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sugestao = app(SugestaoDeVinculo::class);

        GrupoCliente::create(['codigo' => '9998', 'nome' => 'CLIENTES DIVERSOS']);
    }

    private function grupo(string $codigo, string $nome): void
    {
        GrupoCliente::create(['codigo' => $codigo, 'nome' => $nome]);
    }

    private function filial(string $grupo, string $segmento): void
    {
        $this->filiais++;
        Cliente::create([
            'cod_cliente' => str_pad((string) $this->filiais, 6, '0', STR_PAD_LEFT),
            'loja' => '01',
            'razao_social' => "CLIENTE {$this->filiais}",
            'cod_grupo' => $grupo,
            'cod_segmento' => $segmento,
            'cod_vendedor' => '001',
            'estado' => 'SP',
        ]);
    }

    /** @return list<string> */
    private function codigos(string $nome, string $segmento): array
    {
        $r = $this->sugestao->sugerir($nome, $segmento, $this->sugestao->catalogo());
        $this->assertFalse($r['ambiguo'], "\"{$nome}\" não deveria ser ambígua");

        return $r['grupos']->pluck('codigo')->all();
    }

    public function test_prefixo_casa_grupo_da_mesma_marca_em_outro_estado(): void
    {
        $this->grupo('10', 'ESTAPAR BA');
        $this->grupo('11', 'ESTAPAR - CAMPINAS');
        $this->grupo('12', 'OUTRO PARK');

        $this->assertEqualsCanonicalizing(['10', '11'], $this->codigos('ESTAPAR', '113'));
    }

    /**
     * Mutação: tirar LOJAS de PREFIXOS. "LOJASRENNER" não é prefixo de "RENNER".
     */
    public function test_tira_lojas_do_comeco_antes_de_casar(): void
    {
        $this->grupo('20', 'RENNER');

        $this->assertSame(['20'], $this->codigos('Lojas Renner', '108'));
    }

    /**
     * Mutação: voltar ao prefixo puro. "RAIADROGASIL" não é prefixo de "DROGASIL".
     */
    public function test_token_distintivo_casa_quando_o_prefixo_nao_alcanca(): void
    {
        $this->grupo('30', 'DROGASIL');
        $this->filial('30', '109');
        $this->filial('30', '109');

        $this->assertSame(['30'], $this->codigos('Raia Drogasil', '109'));
    }

    /**
     * Mutação: remover a trava de segmento. O grupo DROGASIL de outro setor entraria.
     */
    public function test_token_nao_casa_grupo_majoritariamente_de_outro_segmento(): void
    {
        $this->grupo('30', 'DROGASIL');
        $this->filial('30', '112');
        $this->filial('30', '112');

        $this->assertSame([], $this->codigos('Raia Drogasil', '109'));
    }

    /**
     * Mutação: casar por palavra comum (PAULO/JOAO) sem concatenar. "Drogaria São Paulo"
     * pegava PREFEITURAS DE SAO PAULO na base real.
     */
    public function test_nao_casa_prefeitura_no_lugar_da_drogaria_sao_paulo(): void
    {
        $this->grupo('40', 'DROGARIA SAO PAULO');
        $this->grupo('41', 'PREFEITURAS DE SAO PAULO');
        $this->filial('40', '109');
        $this->filial('41', '103');
        $this->filial('41', '103');

        $this->assertSame(['40'], $this->codigos('Drogaria São Paulo', '109'));
    }

    /**
     * Mutação: prefixo sem fronteira de token. GRUPOFARMA é prefixo de GRUPOFARMAVOCE.
     */
    public function test_prefixo_respeita_fronteira_de_token(): void
    {
        $this->grupo('50', 'GRUPO FARMAVOCE');

        $this->assertSame([], $this->codigos('GRUPOFARMA', '109'));
    }

    /**
     * Mutação: FARMA contar como token distintivo. "RM FARMA" casava FARMA CONDE e o resto.
     */
    public function test_farma_sozinho_nao_e_marca(): void
    {
        $this->grupo('60', 'FARMA CONDE');
        $this->filial('60', '109');

        $this->assertSame([], $this->codigos('RM FARMA / GRUPO HIPER', '109'));
    }

    /**
     * Magazine Luiza no TOTVS é segmento 115, não 108 (a aba da planilha). O NOME está
     * certo — a trava de segmento vale só para o palpite por token, não para o prefixo.
     */
    public function test_prefixo_nao_exige_mesmo_segmento(): void
    {
        $this->grupo('70', 'MAGAZINE LUIZA');
        $this->filial('70', '115');
        $this->filial('70', '115');

        $this->assertSame(['70'], $this->codigos('Magazine Luiza', '108'));
    }

    public function test_nunca_sugere_clientes_diversos(): void
    {
        $this->assertSame([], $this->codigos('CLIENTES DIVERSOS', '109'));
    }

    public function test_sao_joao_concatena_os_fracos(): void
    {
        $this->grupo('80', 'FARMACIAS SAO JOAO');
        $this->filial('80', '109');

        $this->assertSame(['80'], $this->codigos('São João Farmácias', '109'));
    }

    public function test_posto_central_nao_casa_qualquer_central(): void
    {
        $this->grupo('90', 'CENTRALPLAST');
        $this->grupo('91', 'CENTRAL DAS ETIQUETAS');

        $this->assertSame([], $this->codigos('POSTO CENTRAL', '114'));
    }

    public function test_sorvetes_nao_e_marca(): void
    {
        $this->grupo('a1', 'ALESSANDRA SORVETES');
        $this->filial('a1', '112');

        $this->assertSame([], $this->codigos('CHIQUINHO SORVETES', '112'));
        $this->assertSame([], $this->codigos('OGGI SORVETES', '112'));
    }

    /**
     * Mutação: ESTACIONAMENTOS contar como marca. Na planilha real, 12 contas "LEAD"
     * herdavam as 47 lojas de um único grupo genérico.
     */
    public function test_estacionamentos_nao_e_marca(): void
    {
        $this->grupo('e1', 'ESTAPAR SP');
        $this->grupo('e2', 'EMPRESA BRASILEIRA DE ESTACIONAMENTOS');
        $this->filial('e1', '113');
        $this->filial('e2', '113');
        $this->filial('e2', '113');

        $this->assertSame(['e1'], $this->codigos('ESTAPAR', '113'));
        $this->assertSame([], $this->codigos('JLN ESTACIONAMENTOS', '113'));
        $this->assertSame([], $this->codigos('NEE ESTACIONAMENTOS', '113'));
    }
}
