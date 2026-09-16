<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Carga histórica do faturamento com as colunas do Power BI: o CSV do conversor e o
 * de-para de família que preenche os anos que não a têm.
 */
class FaturamentoHistoricoBiTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasta = storage_path('framework/testing/fat-hist-'.uniqid());
        File::ensureDirectoryExists($this->pasta);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pasta);

        parent::tearDown();
    }

    private function csv(string $nome, array $linhas): string
    {
        $caminho = $this->pasta.'/'.$nome;
        file_put_contents($caminho, implode("\n", $linhas)."\n");

        return $caminho;
    }

    private const CABECALHO_V1 = 'filial,nota_fiscal,pedido,data_emissao,cod_cliente,cnpj,cliente_nome,cod_vendedor,cod_produto,produto_desc,segmento,quantidade,valor_unitario,valor_total';

    public function test_csv_novo_grava_as_colunas_do_bi(): void
    {
        $arquivo = $this->csv('novo.csv', [
            self::CABECALHO_V1.',loja,estado,municipio,desc_familia',
            '05,1,2,2019-01-02,1,x,C,000359,V1,P,S,1.0,2.5,2.5,0001,sp,CAMPINAS,BOBINA TERMICA',
        ]);

        $this->artisan('legado:import-faturamento-arquivo', ['arquivo' => $arquivo, '--force' => true])
            ->assertSuccessful();

        $linha = DB::table('faturamentos')->sole();
        $this->assertSame('0001', $linha->loja);
        $this->assertSame('SP', $linha->estado, 'a UF passa pela mesma normalização do 198');
        $this->assertSame('CAMPINAS', $linha->municipio);
        $this->assertSame('BOBINA TERMICA', $linha->desc_familia);
    }

    /** Os CSVs gerados em 30/08 não podem deixar de carregar. */
    public function test_csv_antigo_continua_carregando_sem_as_colunas_do_bi(): void
    {
        $arquivo = $this->csv('antigo.csv', [
            self::CABECALHO_V1,
            '05,1,2,2019-01-02,1,x,C,000359,V1,P,S,1.0,2.5,2.5',
        ]);

        $this->artisan('legado:import-faturamento-arquivo', ['arquivo' => $arquivo, '--force' => true])
            ->expectsOutputToContain('formato antigo')
            ->assertSuccessful();

        $linha = DB::table('faturamentos')->sole();
        $this->assertSame('2.50', $linha->valor_total);
        $this->assertNull($linha->municipio);
    }

    public function test_cabecalho_desconhecido_e_recusado(): void
    {
        $arquivo = $this->csv('errado.csv', ['a,b,c', '1,2,3']);

        $this->artisan('legado:import-faturamento-arquivo', ['arquivo' => $arquivo, '--force' => true])
            ->assertFailed();

        $this->assertSame(0, DB::table('faturamentos')->count());
    }

    /**
     * Produto com duas famílias ao longo do tempo fica com a mais recente, e o código é
     * normalizado (`v1 ` e `V1` são o mesmo produto) — é assim que o conversor procura.
     */
    public function test_de_para_fica_com_a_familia_mais_recente_e_avisa_o_conflito(): void
    {
        $base = ['cod_vendedor' => '000359', 'valor_total' => 1, 'created_at' => now(), 'updated_at' => now()];

        DB::table('faturamentos')->insert([
            $base + ['cod_produto' => 'v1 ', 'desc_familia' => 'ANTIGA', 'data_emissao' => '2024-01-10'],
            $base + ['cod_produto' => 'V1', 'desc_familia' => 'NOVA', 'data_emissao' => '2025-06-10'],
            $base + ['cod_produto' => 'V2', 'desc_familia' => 'ETIQUETA', 'data_emissao' => '2024-03-01'],
            $base + ['cod_produto' => 'V3', 'desc_familia' => null, 'data_emissao' => '2019-03-01'],
        ]);

        $saida = $this->pasta.'/de_para.csv';

        $this->artisan('faturamento:de-para-familias', ['saida' => $saida])
            ->expectsOutputToContain('1 produto(s) com mais de uma família')
            ->assertSuccessful();

        $this->assertSame(
            "cod_produto,desc_familia\nV1,NOVA\nV2,ETIQUETA\n",
            file_get_contents($saida)
        );
    }

    public function test_de_para_sem_nenhuma_familia_falha(): void
    {
        $this->artisan('faturamento:de-para-familias', ['saida' => $this->pasta.'/x.csv'])
            ->assertFailed();
    }
}
