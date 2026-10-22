<?php

namespace Tests\Feature;

use App\Models\EstoqueRevenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaDiretorioDeRelatorios;
use Tests\TestCase;

/**
 * Import do relatório 026 para o estoque de revenda (catálogo da Cotação).
 *
 * O recorte é o do RV: filial 05, ATIVO, tipo RV/PA. E o comando nunca pode falhar a
 * corrente do `totvs:atualizar` — arquivo ausente ou truncado sai com sucesso e não toca
 * a tabela.
 */
class EstoqueRevendaImportTest extends TestCase
{
    use RefreshDatabase;
    use UsaDiretorioDeRelatorios;

    private const CABECALHO = 'Filial;Usuario;Codigo;Descricao;Unidade;Tipo;Grupo;EST_FISICO;Qtd. Reserva;EST_DISP;PED_EMITID;Ult. Preco;Marca;ATIVO';

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

    private function escrever(array $linhas): void
    {
        file_put_contents(
            $this->diretorioTotvs.'/CSV/Estoque necessidade - SQL.csv',
            "026 - ESTOQUE NECESSIDADE RV - MP;;;;;;;;;;;;;\n".self::CABECALHO."\n".implode("\n", $linhas)."\n"
        );
    }

    public function test_importa_so_filial_05_ativo_e_tipo_rv_ou_pa(): void
    {
        $this->escrever([
            '05;JOAO;V1;ALCOOL 1LT;UN;RV;10;100;10;90;5;9,123456;TUPI;ATIVO',
            // Mesmo formato numérico dos outros relatórios SQL do TOTVS ("1.234,56").
            '05;JOAO;P1;BOBINA 80X40;RL;PA;20;1.200,00;0;1.200,00;0;3,5;AUTOPEL;ATIVO',
            '01;JOAO;V1;ALCOOL 1LT;UN;RV;10;999;0;999;0;9,1;TUPI;ATIVO',
            '05;JOAO;V2;ALCOOL 500ML;UN;RV;10;5;0;5;0;4;TUPI;INATIVO',
            '05;JOAO;MP1;PAPEL TERMICO;KG;MP;30;50;0;50;0;7;X;ATIVO',
        ]);

        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['V1', 'P1'], EstoqueRevenda::pluck('codigo')->all());

        $v1 = EstoqueRevenda::where('codigo', 'V1')->first();
        $this->assertSame(90.0, $v1->disponivel); // filial 05, não a 01 (999) nem a soma
        $this->assertSame(9.123456, $v1->ult_preco);
        $this->assertSame(1200.0, EstoqueRevenda::where('codigo', 'P1')->value('fisico'));
    }

    public function test_o_que_sumiu_do_arquivo_sai_do_estoque(): void
    {
        $this->escrever(['05;J;V1;A;UN;RV;1;1;0;1;0;1;M;ATIVO', '05;J;V2;B;UN;RV;1;1;0;1;0;1;M;ATIVO']);
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->travel(1)->minutes();
        $this->escrever(['05;J;V1;A;UN;RV;1;1;0;1;0;1;M;ATIVO', '05;J;V3;C;UN;RV;1;1;0;1;0;1;M;ATIVO']);
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['V1', 'V3'], EstoqueRevenda::pluck('codigo')->all());
    }

    public function test_arquivo_truncado_nao_apaga_o_estoque(): void
    {
        $this->escrever(array_map(fn ($i) => "05;J;V{$i};ITEM {$i};UN;RV;1;1;0;1;0;1;M;ATIVO", range(1, 10)));
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->travel(1)->minutes();
        $this->escrever(['05;J;V1;ITEM 1;UN;RV;1;1;0;1;0;1;M;ATIVO']);
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertSame(10, EstoqueRevenda::count());
    }

    public function test_sem_arquivo_ou_com_colunas_erradas_nao_falha_a_corrente(): void
    {
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        file_put_contents($this->diretorioTotvs.'/CSV/Estoque necessidade - SQL.csv', "Codigo;Descricao\nV1;A\n");
        $this->artisan('totvs:import-estoque-revenda')->assertSuccessful();

        $this->assertSame(0, EstoqueRevenda::count());
    }
}
