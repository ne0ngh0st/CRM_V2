<?php

namespace Tests\Feature;

use App\Console\Commands\ImportEstoqueRevendaTotvs;
use App\Models\EstoqueRevenda;
use App\Models\EstoqueRevendaCarga;
use App\Services\Totvs\AtualizadorTotvs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\UsaDiretorioDeRelatorios;
use Tests\TestCase;

/**
 * Import do Relatório de Revenda (RV) para o estoque da Cotação.
 *
 * O fluxo real (22/10/2026): um xlsx NOVO por dia na pasta `Relatório revenda/`, sem
 * sobrescrever. O que estes testes travam: a versão que vale é a data do NOME (nunca o
 * mtime), RV já carregado não é relido, a aba `Geral` é lida pelo nome das colunas, e
 * nada disso derruba a corrente do `totvs:atualizar` nem entra na impressão digital dela.
 */
class EstoqueRevendaImportTest extends TestCase
{
    use RefreshDatabase;
    use UsaDiretorioDeRelatorios;

    private const CABECALHO = ['Comprador', 'Codigo      ', 'Descricao   ', 'Marca       ', ' PontoPedido', ' Ult.Preco',
        ' Fisico', ' Reserva', ' Disponivel', ' Comprado', 'Grupo       '];

    private string $pastaRv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRelatorios();
        $this->pastaRv = $this->diretorioTotvs.'/Relatório revenda';
        mkdir($this->pastaRv);
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();
        parent::tearDown();
    }

    /**
     * Grava um RV no formato real: aba de comprador antes da Geral (o leitor não pode
     * depender da posição da aba) e cabeçalho com o padding do TOTVS.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: float, 4: float, 5: float, 6: float, 7: float}>  $itens  codigo, descricao, marca, preco, fisico, reserva, disponivel, comprado
     */
    private function rv(string $nome, array $itens, ?int $mtime = null, string $aba = 'Geral'): string
    {
        $planilha = new Spreadsheet;
        $planilha->getActiveSheet()->setTitle('Cristiane');
        $geral = $planilha->createSheet()->setTitle($aba);
        $geral->fromArray(self::CABECALHO, null, 'A1');

        foreach ($itens as $n => $i) {
            $geral->fromArray(['CRISTIANE ', $i[0], $i[1], $i[2], 3, $i[3], $i[4], $i[5], $i[6], $i[7], '0002'], null, 'A'.($n + 2));
        }

        $caminho = $this->pastaRv.'/'.$nome;
        (new Xlsx($planilha))->save($caminho);
        if ($mtime !== null) {
            touch($caminho, $mtime);
        }

        return $caminho;
    }

    private function itens(string ...$codigos): array
    {
        return array_map(fn ($c) => [$c, "ITEM {$c}", 'MARCA', 1.0, 1.0, 0.0, 1.0, 0.0], $codigos);
    }

    public function test_le_a_aba_geral_pelo_nome_das_colunas(): void
    {
        $this->rv('Relatorio de Revanda 22-10-2026.xlsx', [
            ['V1483', 'FITA ADESIVA PP. 12X30M TRANSP.', ' ADELBRAS', 1.08, 58, 2, 56, 0],
            ['V900', 'BOBINA 80X40', 'AUTOPEL', 1234.56, 1200, 0, 1200, 300],
        ]);

        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $fita = EstoqueRevenda::where('codigo', 'V1483')->first();
        $this->assertSame('ADELBRAS', $fita->marca);
        $this->assertSame([58.0, 2.0, 56.0, 1.08], [$fita->fisico, $fita->reserva, $fita->disponivel, $fita->ult_preco]);
        $bobina = EstoqueRevenda::where('codigo', 'V900')->first();
        $this->assertSame([1200.0, 300.0, 1234.56], [$bobina->fisico, $bobina->comprado, $bobina->ult_preco]);
        $this->assertSame('2026-10-22', EstoqueRevendaCarga::atual()->data_rv->toDateString());
    }

    public function test_vale_a_data_do_nome_e_nao_a_do_arquivo_modificado_por_ultimo(): void
    {
        // O RV de 21/10 foi aberto e salvo DEPOIS do de 22/10: mtime mais novo, data mais velha.
        $this->rv('Relatorio de Revenda 22-10-2026.xlsx', $this->itens('NOVO'), mtime: strtotime('2026-10-22 09:00'));
        $this->rv('Relatorio de Revenda 21-10-2026.xlsx', $this->itens('VELHO'), mtime: strtotime('2026-10-22 15:00'));

        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertSame(['NOVO'], EstoqueRevenda::pluck('codigo')->all());
    }

    public function test_ignora_trava_do_excel_e_arquivo_sem_data(): void
    {
        $this->rv('Relatorio de Revenda 20-10-2026.xlsx', $this->itens('CERTO'));
        $this->rv('~$Relatorio de Revenda 25-10-2026.xlsx', $this->itens('TRAVA'));
        $this->rv('Relatorio de Revenda final.xlsx', $this->itens('SEMDATA'));

        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertSame(['CERTO'], EstoqueRevenda::pluck('codigo')->all());
    }

    public function test_rv_ja_carregado_nao_e_relido_e_o_mais_novo_substitui(): void
    {
        $this->rv('Relatorio de Revenda 20-10-2026.xlsx', $this->itens('V1', 'V2'), mtime: strtotime('2026-10-20 08:00'));
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->artisan('totvs:import-estoque-revenda')->expectsOutputToContain('já carregado')->assertSuccessful();
        $this->assertSame(1, EstoqueRevendaCarga::count());

        $this->travel(1)->minutes();
        $this->rv('Relatorio de Revenda 21-10-2026.xlsx', $this->itens('V1', 'V3'));
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['V1', 'V3'], EstoqueRevenda::pluck('codigo')->all());
        $this->assertSame('2026-10-21', EstoqueRevendaCarga::atual()->data_rv->toDateString());
    }

    public function test_forcar_relê_o_mesmo_rv(): void
    {
        $this->rv('Relatorio de Revenda 20-10-2026.xlsx', $this->itens('V1'), mtime: strtotime('2026-10-20 08:00'));
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();
        $this->artisan('totvs:import-estoque-revenda', ['--forcar' => true])->assertSuccessful();

        $this->assertSame(2, EstoqueRevendaCarga::count());
    }

    public function test_rv_truncado_ou_sem_a_aba_nao_mexe_no_estoque_nem_falha(): void
    {
        $this->rv('Relatorio de Revenda 20-10-2026.xlsx', $this->itens(...array_map(fn ($i) => "V{$i}", range(1, 10))));
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->travel(1)->minutes();
        $this->rv('Relatorio de Revenda 21-10-2026.xlsx', $this->itens('V1'));
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();
        $this->assertSame(10, EstoqueRevenda::count());

        $this->rv('Relatorio de Revenda 22-10-2026.xlsx', $this->itens('V1'), aba: 'Planilha1');
        $this->artisan('totvs:import-estoque-revenda')->expectsOutputToContain('NÃO importado')->assertSuccessful();
        $this->assertSame(10, EstoqueRevenda::count());
        $this->assertSame('2026-10-20', EstoqueRevendaCarga::atual()->data_rv->toDateString());
    }

    public function test_pasta_vazia_nao_falha(): void
    {
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();
        $this->assertSame(0, EstoqueRevenda::count());
    }

    public function test_rv_novo_nao_muda_a_impressao_digital_da_rodada_horaria(): void
    {
        file_put_contents($this->diretorioTotvs.'/CSV/Clientes - SQL.csv', "x\n");
        $antes = app(AtualizadorTotvs::class)->impressaoDigital();

        $this->rv('Relatorio de Revenda 22-10-2026.xlsx', $this->itens('V1'));

        $this->assertSame($antes, app(AtualizadorTotvs::class)->impressaoDigital());
    }

    public function test_data_do_nome_e_numero_do_rv(): void
    {
        $this->assertSame('2026-10-22', ImportEstoqueRevendaTotvs::dataDoNome('Relatorio de Revanda 22-10-2026.xlsx')->toDateString());
        $this->assertSame('2026-01-05', ImportEstoqueRevendaTotvs::dataDoNome('RV 5_1_2026.xlsx')->toDateString());
        $this->assertNull(ImportEstoqueRevendaTotvs::dataDoNome('RV 31-02-2026.xlsx'));
        $this->assertNull(ImportEstoqueRevendaTotvs::dataDoNome('Relatorio de Revenda.xlsx'));

        $this->assertSame(37.0, ImportEstoqueRevendaTotvs::numero(37));
        $this->assertSame(1234.56, ImportEstoqueRevendaTotvs::numero('R$ 1.234,56'));
        $this->assertSame(-57.24, ImportEstoqueRevendaTotvs::numero('-R$ 57,24 '));
        $this->assertSame(0.0, ImportEstoqueRevendaTotvs::numero(' R$ -   '));
    }
}
