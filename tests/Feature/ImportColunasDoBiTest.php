<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Pedido;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscreveRelatorio198;
use Tests\Concerns\EscreveRelatorio200;
use Tests\Concerns\EscreveRelatorio232;
use Tests\TestCase;

/**
 * As colunas que o Power BI lê e que os imports do TOTVS descartavam até 2026-09-16:
 * loja, estado, município e família no faturamento; filial nos dois fatos de pedido.
 */
class ImportColunasDoBiTest extends TestCase
{
    use EscreveRelatorio198;
    use EscreveRelatorio200;
    use EscreveRelatorio232;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->prepararRelatorios();
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();

        parent::tearDown();
    }

    public function test_faturamento_grava_loja_estado_municipio_e_familia(): void
    {
        $this->escreverRelatorio198([[]]);

        $this->artisan('totvs:import-faturamento')->assertSuccessful();

        $linha = DB::table('faturamentos')->sole();

        $this->assertSame('0001', $linha->loja, 'loja com o zero à esquerda do relatório');
        $this->assertSame('SP', $linha->estado);
        $this->assertSame('SUMARE', $linha->municipio, 'sem o padding de largura fixa');
        $this->assertSame('BOBINA TERMICA KPH 44G', $linha->desc_familia);
    }

    /**
     * ⚠️ O 198 tem DUAS colunas de município. Com o mesmo valor nas duas (o caso comum),
     * ler a errada passaria verde — por isso os valores aqui são diferentes.
     */
    public function test_municipio_e_o_da_coluna_ao_lado_do_estado(): void
    {
        $this->escreverRelatorio198([[
            'Municipio' => 'CAMPINAS',
            'MUNICIPIO' => 'HORTOLANDIA',
        ]]);

        $this->artisan('totvs:import-faturamento')->assertSuccessful();

        $this->assertSame('CAMPINAS', DB::table('faturamentos')->value('municipio'));
    }

    public function test_estado_que_nao_e_sigla_vira_nulo_em_vez_de_cortado(): void
    {
        $this->escreverRelatorio198([
            ['Estado' => 'SAO PAULO', 'PEDIDO' => '1'],
            ['Estado' => 'ex', 'PEDIDO' => '2'],
        ]);

        $this->artisan('totvs:import-faturamento')->assertSuccessful();

        $this->assertSame(
            ['1' => null, '2' => 'EX'],
            DB::table('faturamentos')->orderBy('pedido')->pluck('estado', 'pedido')->all()
        );
    }

    public function test_arquivo_sem_familia_importa_com_familia_nula(): void
    {
        $this->escreverRelatorio198([[]], comFamilia: false);

        $this->artisan('totvs:import-faturamento')
            ->expectsOutputToContain('sem a coluna DESC_FAMILIA')
            ->assertSuccessful();

        $linha = DB::table('faturamentos')->sole();
        $this->assertNull($linha->desc_familia);
        $this->assertSame('SUMARE', $linha->municipio, 'o resto da linha continua sendo gravado');
    }

    public function test_pedidos_abertos_gravam_a_filial(): void
    {
        $this->escreverRelatorio200([['992086', 'PEDIDO 992086 INCLUIDO NA CARGA 190050']]);

        $this->artisan('totvs:import-pedidos-abertos')->assertSuccessful();

        $this->assertSame(5, Pedido::where('numero_pedido', '992086')->value('filial'));
    }

    public function test_pedidos_emitidos_gravam_a_filial(): void
    {
        Cliente::create(['cod_cliente' => '000054', 'loja' => '0066', 'razao_social' => 'TENDA ATACADO']);

        $this->escreverRelatorio232([['FILIAL' => '07']]);

        $this->artisan('totvs:import-pedidos-emitidos')->assertSuccessful();

        $this->assertSame(7, Pedido::where('numero_pedido', '994867')->value('filial'));
    }

    /**
     * A filial vai no UPDATE do upsert, não só no INSERT: pedido que já existia antes da
     * coluna nascer é preenchido pela próxima rodada, sem precisar apagar nada.
     */
    public function test_pedido_existente_ganha_a_filial_na_proxima_rodada(): void
    {
        Pedido::create([
            'numero_pedido' => '992086',
            'cod_vendedor' => '010585',
            'data_pedido' => '2026-08-14',
            'status' => 'pendente_totvs',
            'valor_total' => 0,
        ]);

        $this->escreverRelatorio200([['992086', 'PEDIDO 992086 INCLUIDO NA CARGA 190050']]);

        $this->artisan('totvs:import-pedidos-abertos')->assertSuccessful();

        $this->assertSame(5, Pedido::where('numero_pedido', '992086')->value('filial'));
    }
}
