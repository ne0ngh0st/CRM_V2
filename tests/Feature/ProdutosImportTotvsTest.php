<?php

namespace Tests\Feature;

use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\UsaDiretorioDeRelatorios;
use Tests\TestCase;

/**
 * `totvs:import-produtos`: tabela de preço (234) + cadastro (005), em xlsx.
 *
 * Nasceu do V40826 (29/10/2026): produto criado no TOTVS depois que o legado parou não
 * entrava em `produtos` e não aparecia na busca do orçamento.
 */
class ProdutosImportTotvsTest extends TestCase
{
    use RefreshDatabase;
    use UsaDiretorioDeRelatorios;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRelatorios();
        mkdir($this->diretorioTotvs.'/produtos');
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();
        parent::tearDown();
    }

    /** Formato real: título do RLT na 1ª linha, cabeçalho com padding na 2ª. */
    private function xlsx(string $nome, string $titulo, array $cabecalho, array $linhas): void
    {
        $planilha = new Spreadsheet;
        $aba = $planilha->getActiveSheet();
        $aba->setCellValue('A1', $titulo);
        $aba->fromArray($cabecalho, null, 'A2');
        $aba->fromArray($linhas, null, 'A3');
        (new Xlsx($planilha))->save($this->diretorioTotvs.'/produtos/'.$nome);
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: float}>  $linhas  codigo, descricao, unidade, preco */
    private function precos(array $linhas): void
    {
        $this->xlsx('TABELA DE PREÇOS PRODUTOS - SQL.xlsx', '234 - TABELA DE PRECO PADRAO.RLT',
            ['CODIGO', 'DESCRICAO', 'UNIDADE', 'PRCVENDA'],
            array_map(fn ($l) => [$l[0].'       ', $l[1].'      ', $l[2], $l[3]], $linhas));
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: string}>  $linhas  codigo, descricao, desc grupo, ativo */
    private function cadastro(array $linhas): void
    {
        $this->xlsx('PROTUDOS TOTVS - SQL.xlsx', '005 - CADASTRO DE PRODUTO COM NCM.RLT',
            ['Codigo      ', 'Descricao   ', 'Grupo       ', 'Desc Grupo  ', 'ATIVO'],
            array_map(fn ($l) => [$l[0].'     ', $l[1], '0003', $l[2].'    ', $l[3]], $linhas));
    }

    public function test_produto_da_tabela_de_preco_entra_com_preco_e_categoria_do_cadastro(): void
    {
        $this->precos([['V40826', 'BOBINA TS KPH BC 57X12M AUTOPEL S/L', 'UN', 0.62528599999999999]]);
        $this->cadastro([['V40826', 'BOBINA TS KPH BC 57X12M AUTOPEL S/L', 'BOBINAS', 'SIM']]);

        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $p = Produto::where('cod_produto', 'V40826')->sole();
        $this->assertSame('BOBINA TS KPH BC 57X12M AUTOPEL S/L', $p->descricao);
        $this->assertSame('UN', $p->unidade);
        $this->assertEqualsWithDelta(0.6253, (float) $p->preco_tabela, 0.00001);
        $this->assertSame('BOBINA', $p->categoria);
    }

    public function test_do_cadastro_so_entra_produto_de_venda_ativo(): void
    {
        $this->precos([['V1', 'BOBINA', 'UN', 1.0]]);
        $this->cadastro([
            ['V1', 'BOBINA', 'BOBINAS', 'SIM'],
            ['V2', 'CANETA AZUL', 'PAPELARIA / OFFICE', 'SIM'],
            ['V3', 'SSD 240GB', 'INFORMÁTICA', 'NAO'],
            ['P4', 'INTERMEDIARIO', 'PRODUTO INTERMEDIÁRIO', 'SIM'],
            ['S5', 'UNIFORMES', '', 'SIM'],
        ]);

        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['V1', 'V2'], Produto::pluck('cod_produto')->all());
        $caneta = Produto::where('cod_produto', 'V2')->sole();
        $this->assertNull($caneta->preco_tabela);
        $this->assertSame('SUPLY', $caneta->categoria);
    }

    public function test_codigo_repetido_no_cadastro_conta_como_ativo_se_alguma_linha_for(): void
    {
        $this->precos([['V1', 'BOBINA', 'UN', 1.0]]);
        $this->cadastro([
            ['V2', 'CANETA', 'PAPELARIA / OFFICE', 'SIM'],
            ['V2', 'CANETA', 'PAPELARIA / OFFICE', 'NAO'],
        ]);

        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $this->assertTrue(Produto::where('cod_produto', 'V2')->exists());
    }

    public function test_atualiza_preco_mas_preserva_a_categoria_existente_e_nao_apaga_nada(): void
    {
        DB::table('produtos')->insert([
            ['cod_produto' => 'V10', 'descricao' => 'TAG ANTIGA', 'categoria' => 'TAG', 'unidade' => 'UN', 'preco_tabela' => 1.0, 'created_at' => now(), 'updated_at' => now()],
            ['cod_produto' => 'V99', 'descricao' => 'SAIU DO TOTVS', 'categoria' => 'BOBINA', 'unidade' => 'UN', 'preco_tabela' => 2.0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->precos([['V10', 'TAG GONDOLA', 'UN', 3.5]]);
        $this->cadastro([['V10', 'TAG GONDOLA', 'ETIQUETAS', 'SIM']]);

        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $tag = Produto::where('cod_produto', 'V10')->sole();
        $this->assertSame('TAG', $tag->categoria);
        $this->assertSame('TAG GONDOLA', $tag->descricao);
        $this->assertEqualsWithDelta(3.5, (float) $tag->preco_tabela, 0.00001);
        $this->assertTrue(Produto::where('cod_produto', 'V99')->exists());
    }

    /** Chave de array que parece número vira int no PHP (lição do contador A00051). */
    public function test_codigo_numerico_convive_com_alfanumerico(): void
    {
        $this->precos([['123456', 'ITEM NUMERICO', 'UN', 1.0], ['V1', 'BOBINA', 'UN', 2.0]]);
        $this->cadastro([['123456', 'ITEM NUMERICO', 'BOBINAS', 'SIM'], ['V1', 'BOBINA', 'BOBINAS', 'SIM']]);

        $this->artisan('totvs:import-produtos')->assertSuccessful();
        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $this->assertSame(2, Produto::count());
    }

    public function test_sem_o_cadastro_nao_grava_nada_e_nao_derruba_a_corrente(): void
    {
        $this->precos([['V1', 'BOBINA', 'UN', 1.0]]);

        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $this->assertSame(0, Produto::count());
    }

    public function test_tabela_de_preco_truncada_nao_altera_nada(): void
    {
        foreach (range(1, 10) as $n) {
            DB::table('produtos')->insert(['cod_produto' => "V{$n}", 'descricao' => 'X', 'preco_tabela' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->precos([['V1', 'BOBINA NOVA', 'UN', 9.0]]);
        $this->cadastro([['V1', 'BOBINA NOVA', 'BOBINAS', 'SIM']]);

        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $this->assertEqualsWithDelta(1.0, (float) Produto::where('cod_produto', 'V1')->value('preco_tabela'), 0.00001);
    }

    public function test_produto_importado_aparece_na_busca_do_orcamento(): void
    {
        $this->precos([['V40826', 'BOBINA TS KPH BC 57X12M AUTOPEL S/L', 'UN', 0.6253]]);
        $this->cadastro([['V40826', 'BOBINA TS KPH BC 57X12M AUTOPEL S/L', 'BOBINAS', 'SIM']]);
        $this->artisan('totvs:import-produtos')->assertSuccessful();

        $this->actingAs(\App\Models\User::factory()->create())
            ->getJson(route('orcamentos.buscaProdutos', ['q' => 'V40826']))
            ->assertOk()
            ->assertJsonPath('0.codProduto', 'V40826');
    }
}
