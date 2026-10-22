<?php

namespace Tests\Feature;

use App\Console\Commands\ImportEstoqueRevendaTotvs;
use App\Models\EstoqueRevenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaDiretorioDeRelatorios;
use Tests\TestCase;

/**
 * Import do Relatório de Revenda (RV) para o estoque de revenda (catálogo da Cotação).
 *
 * O layout dos fixtures é o do primeiro export real (22/10/2026): cabeçalho com padding na
 * 1ª linha, BOM, quantidades inteiras e preço "R$ 1.234,56" / "R$ -". E o comando nunca
 * pode falhar a corrente do `totvs:atualizar`.
 */
class EstoqueRevendaImportTest extends TestCase
{
    use RefreshDatabase;
    use UsaDiretorioDeRelatorios;

    private const CABECALHO = "\u{FEFF}Comprador;Codigo      ;Descricao   ;Marca       ; PontoPedido;Comprar.;  Ult.Preco ; Valor Total  ; MedContr;MedVenda;  Total Media ;  Vlr Estoque ; Fisico; Reserva; Disponivel; VendProt; Ruptura; Comprado;DT_ENTREGA; Prc_Compra.;ULT_COMPRA;PEDIDO; EstDias;Grupo       ";

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRelatorios();
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();
        parent::tearDown();
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}>  $itens  codigo, descricao, marca, preco, fisico, reserva, disponivel, comprado */
    private function escrever(array $itens): void
    {
        $linhas = array_map(
            fn ($i) => "CRISTIANE ;{$i[0]};{$i[1]};{$i[2]};3;0;{$i[3]};R$ -;2;12;R$ -;R$ -;{$i[4]};{$i[5]};{$i[6]};0;0;{$i[7]};;0;19/11/2024;;840;0002",
            $itens
        );

        file_put_contents(
            $this->diretorioTotvs.'/CSV/Relatorio de Revenda - SQL.csv',
            self::CABECALHO."\n".implode("\n", $linhas)."\n"
        );
    }

    private function item(string $codigo, string $preco = ' R$ 1,00 '): array
    {
        return [$codigo, "ITEM {$codigo}", 'MARCA', $preco, '1', '0', '1', '0'];
    }

    public function test_importa_o_rv_com_o_preco_em_reais(): void
    {
        $this->escrever([
            ['V1483', 'FITA ADESIVA PP. 12X30M TRANSP.', ' ADELBRAS', ' R$ 1,08 ', '58', '2', '56', '0'],
            ['V900', 'BOBINA 80X40', 'AUTOPEL', ' R$ 1.234,56 ', '1200', '0', '1200', '300'],
            ['V3347', 'FITA CORRETIVA', '', ' R$ - ', '0', '0', '0', '0'],
        ]);

        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $fita = EstoqueRevenda::where('codigo', 'V1483')->first();
        $this->assertSame('ADELBRAS', $fita->marca);
        $this->assertSame([58.0, 2.0, 56.0, 1.08], [$fita->fisico, $fita->reserva, $fita->disponivel, $fita->ult_preco]);

        $bobina = EstoqueRevenda::where('codigo', 'V900')->first();
        $this->assertSame([1200.0, 300.0, 1234.56], [$bobina->fisico, $bobina->comprado, $bobina->ult_preco]);

        $this->assertSame(0.0, EstoqueRevenda::where('codigo', 'V3347')->value('ult_preco'));
        $this->assertNull(EstoqueRevenda::where('codigo', 'V3347')->value('marca'));
    }

    public function test_preco_do_rv_aceita_os_formatos_do_relatorio(): void
    {
        $this->assertSame(37.0, ImportEstoqueRevendaTotvs::reais(' R$ 37,00 '));
        $this->assertSame(1234.56, ImportEstoqueRevendaTotvs::reais('R$ 1.234,56'));
        $this->assertSame(-57.24, ImportEstoqueRevendaTotvs::reais('-R$ 57,24 '));
        $this->assertSame(0.0, ImportEstoqueRevendaTotvs::reais(' R$ -   '));
    }

    public function test_o_que_sumiu_do_arquivo_sai_do_estoque(): void
    {
        $this->escrever([$this->item('V1'), $this->item('V2')]);
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->travel(1)->minutes();
        $this->escrever([$this->item('V1'), $this->item('V3')]);
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['V1', 'V3'], EstoqueRevenda::pluck('codigo')->all());
    }

    public function test_arquivo_truncado_nao_apaga_o_estoque(): void
    {
        $this->escrever(array_map(fn ($i) => $this->item("V{$i}"), range(1, 10)));
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->travel(1)->minutes();
        $this->escrever([$this->item('V1')]);
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertSame(10, EstoqueRevenda::count());
    }

    public function test_sem_arquivo_ou_com_colunas_erradas_nao_falha_a_corrente(): void
    {
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        file_put_contents($this->diretorioTotvs.'/CSV/Relatorio de Revenda - SQL.csv', "Codigo;Descricao\nV1;A\n");
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertSame(0, EstoqueRevenda::count());
    }
}
