<?php

namespace Tests\Feature;

use App\Services\PowerBi\SchemaBi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Schema do Power BI: tabelas de referência, carga dos CSVs e o script de infra.
 *
 * ⚠️ A carga usa os CSVs REAIS de `database/dados-bi/`, não fixtures. O que se quer
 * provar é que o formato exato do export do phpMyAdmin (BOM, `;`, vírgula decimal,
 * data pt-BR) entra certo — um fixture escrito à mão testaria o formato que eu acho
 * que o arquivo tem.
 */
class SchemaBiTest extends TestCase
{
    use RefreshDatabase;

    public function test_suite_usa_um_schema_descartavel(): void
    {
        $this->assertSame('bi_test', SchemaBi::nome());
    }

    public function test_carrega_os_csvs_reais(): void
    {
        $this->artisan('bi:carregar-referencias', ['--force' => true])->assertSuccessful();

        $this->assertSame(5571, DB::table('bi_test.ibge_municipios')->count());
        $this->assertSame(5623, DB::table('bi_test.de_para_municipio')->count());
        $this->assertSame(5570, DB::table('bi_test.indicadores_municipio')->count());
        $this->assertSame(27, DB::table('bi_test.potencial_mercado_estado')->count());
    }

    public function test_decimal_e_data_do_export_pt_br_entram_certos(): void
    {
        $this->artisan('bi:carregar-referencias', ['--force' => true])->assertSuccessful();

        // Primeira linha do CSV: "10526,00";...;"919520000,00";...;"21/07/2026 15:42:52"
        $ind = DB::table('bi_test.indicadores_municipio')->where('cod_municipio', 1100015)->sole();
        $this->assertSame('10526.00', $ind->pea_total);
        $this->assertSame('919520000.00', $ind->pib_total_reais);
        $this->assertSame('2026-07-21 15:42:52', $ind->atualizado_em);

        $ac = DB::table('bi_test.potencial_mercado_estado')->where('uf', 'AC')->sole();
        $this->assertSame('29728.440068', $ac->pib_per_capita);
        $this->assertSame('0.3470196347', $ac->ipm);
    }

    /** As exceções de grafia do legado vieram junto — é o que fecha a cobertura do mapa. */
    public function test_de_para_traz_as_excecoes_de_grafia(): void
    {
        $this->artisan('bi:carregar-referencias', ['--force' => true])->assertSuccessful();

        $this->assertSame(
            3530607,
            DB::table('bi_test.de_para_municipio')->where(['uf' => 'SP', 'nome_norm' => 'MOJI DAS CRUZES'])->value('cod_municipio')
        );
        $this->assertSame(
            'SÃO PAULO',
            DB::table('bi_test.de_para_municipio')->where('cod_municipio', 3550308)->value('nome_norm')
        );
    }

    /** O legado casava 'SAO PAULO' com 'SÃO PAULO' pela collation; aqui tem que continuar. */
    public function test_collation_ignora_acento_no_join_com_o_app(): void
    {
        $this->artisan('bi:carregar-referencias', ['--force' => true])->assertSuccessful();

        DB::table('clientes')->insert(['cod_cliente' => '1', 'loja' => '0001', 'razao_social' => 'X', 'municipio' => 'SAO PAULO', 'estado' => 'SP']);

        $cod = DB::selectOne(
            'SELECT dp.cod_municipio FROM '.SchemaBi::app('clientes').' c
             JOIN '.SchemaBi::tabela('de_para_municipio').' dp ON dp.uf = c.estado AND dp.nome_norm = c.municipio'
        );

        $this->assertSame(3550308, $cod->cod_municipio);
    }

    public function test_recarregar_nao_duplica(): void
    {
        $this->artisan('bi:carregar-referencias', ['--force' => true])->assertSuccessful();
        $this->artisan('bi:carregar-referencias', ['--force' => true])->assertSuccessful();

        $this->assertSame(27, DB::table('bi_test.potencial_mercado_estado')->count());
    }

    /**
     * Um reexport com opções diferentes (vírgula como separador) tem que parar a carga —
     * e sem apagar o que já estava carregado.
     */
    public function test_cabecalho_diferente_para_a_carga_sem_apagar_o_anterior(): void
    {
        $this->artisan('bi:carregar-referencias', ['--force' => true])->assertSuccessful();

        $pasta = storage_path('framework/testing/dados-bi-'.uniqid());
        File::copyDirectory(base_path('database/dados-bi'), $pasta);
        file_put_contents($pasta.'/IBGE_MUNICIPIOS.csv', "id,cod_municipio\n1,1100015\n");

        try {
            $this->artisan('bi:carregar-referencias', ['--force' => true, '--pasta' => str_replace(base_path().'/', '', $pasta)])
                ->expectsOutputToContain('cabeçalho inesperado')
                ->assertFailed();
        } finally {
            File::deleteDirectory($pasta);
        }

        $this->assertSame(5571, DB::table('bi_test.ibge_municipios')->count());
    }

    /**
     * O script que dá GRANT ao `bi_leitura` tem a lista de tabelas escrita à mão (é bash,
     * não lê PHP). Se as duas listas divergirem, o primeiro refresh do Power BI falha com
     * "SELECT command denied" — e só no RDS de produção.
     */
    public function test_script_de_infra_da_grant_nas_mesmas_tabelas_que_as_views_leem(): void
    {
        $script = file_get_contents(base_path('infra/bi/criar-schema-e-usuario.sh'));

        $this->assertSame(1, preg_match('/^TABELAS_DO_APP="([^"]+)"/m', $script, $m));

        $noScript = preg_split('/\s+/', trim($m[1]));
        sort($noScript);
        $noCodigo = SchemaBi::TABELAS_DO_APP;
        sort($noCodigo);

        $this->assertSame($noCodigo, $noScript);
    }
}
