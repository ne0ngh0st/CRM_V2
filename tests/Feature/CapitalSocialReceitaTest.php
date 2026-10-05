<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Services\Exportacao\CatalogoDeExportacoes;
use App\Services\Receita\CartaoCnpjService;
use App\Services\Receita\PorteEmpresa;
use App\Services\Receita\SituacaoCadastral;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Capital social e porte da Receita na Carteira e nos Leads (pedido do Tony, 2026-10-05).
 *
 * Na Receita os dois são da EMPRESA (raiz de 8 dígitos); aqui ficam repetidos em cada CNPJ
 * de 14 dígitos — o fixture tem duas filiais da mesma raiz para travar isso.
 */
class CapitalSocialReceitaTest extends TestCase
{
    use RefreshDatabase;

    private const MATRIZ = '11111111000191';

    private const FILIAL = '11111111000272';

    private const SEM_PORTE = '22222222000191';

    private const OUTRA = '33333333000191';

    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->zips);

        parent::tearDown();
    }

    public function test_carga_grava_capital_e_porte_da_empresa_em_cada_filial(): void
    {
        foreach ([self::MATRIZ, self::FILIAL, self::SEM_PORTE] as $cnpj) {
            $this->lead($cnpj);
        }

        $this->artisan('receita:importar-situacoes', [
            '--arquivo' => [$this->zip('ESTABELE', [
                $this->linhaEstabelecimento(self::MATRIZ),
                $this->linhaEstabelecimento(self::FILIAL),
                $this->linhaEstabelecimento(self::SEM_PORTE),
            ])],
            '--empresas' => [$this->zip('EMPRECSV', [
                '"11111111";"MERCADO TESTE LTDA";"2062";"49";"1250000,50";"03";""',
                '"22222222";"FULANO";"2135";"50";"0,00";"00";""',
                '"99999999";"FORA DO INTERESSE";"2062";"49";"5000,00";"05";""',
            ])],
            '--referencia' => '2026-09',
        ])->assertSuccessful();

        $linhas = DB::table('cnpj_situacoes')->get()->keyBy('cnpj');

        // A filial herda o capital e o porte da empresa.
        foreach ([self::MATRIZ, self::FILIAL] as $cnpj) {
            $this->assertEquals(1250000.50, (float) $linhas[$cnpj]->capital_social);
            $this->assertSame(PorteEmpresa::EPP, $linhas[$cnpj]->porte);
        }

        // Capital zero é valor real; porte "00" é não informado.
        $this->assertSame(0.0, (float) $linhas[self::SEM_PORTE]->capital_social);
        $this->assertNull($linhas[self::SEM_PORTE]->porte);
    }

    public function test_carga_sem_o_arquivo_de_empresas_nao_apaga_o_capital(): void
    {
        $this->lead(self::MATRIZ);
        $this->situacao(self::MATRIZ, 1000, PorteEmpresa::ME);

        $this->artisan('receita:importar-situacoes', [
            '--arquivo' => [$this->zip('ESTABELE', [$this->linhaEstabelecimento(self::MATRIZ)])],
            '--referencia' => '2026-09',
        ])->assertSuccessful();

        $linha = DB::table('cnpj_situacoes')->where('cnpj', self::MATRIZ)->first();
        $this->assertEquals(1000, (float) $linha->capital_social);
        $this->assertSame(PorteEmpresa::ME, $linha->porte);
    }

    public function test_carteira_mostra_capital_e_rotulo_do_porte(): void
    {
        $admin = $this->admin();
        $this->cliente('100', self::MATRIZ, 'COM CAPITAL');
        $this->cliente('200', self::OUTRA, 'SEM DADO');
        $this->situacao(self::MATRIZ, 1250000.5, PorteEmpresa::EPP);

        $linhas = collect($this->actingAs($admin)->get(route('carteira.index'))
            ->assertOk()->viewData('page')['props']['clientes']['data'])->keyBy('razaoSocial');

        $this->assertSame(1250000.5, $linhas['COM CAPITAL']['receita']['capitalSocial']);
        $this->assertSame('Pequeno porte', $linhas['COM CAPITAL']['receita']['porte']);
        $this->assertNull($linhas['SEM DADO']['receita']['capitalSocial']);
        $this->assertNull($linhas['SEM DADO']['receita']['porte']);
    }

    public function test_leads_mostram_capital_e_porte_com_cnpj_mascarado(): void
    {
        $admin = $this->admin();
        $this->lead(self::MATRIZ);
        $this->situacao(self::MATRIZ, 5000, PorteEmpresa::DEMAIS);

        $lead = $this->actingAs($admin)->get(route('leads.index'))
            ->assertOk()->viewData('page')['props']['leads']['data'][0];

        $this->assertSame(5000.0, $lead['receita']['capitalSocial']);
        $this->assertSame('Demais portes', $lead['receita']['porte']);
    }

    public function test_excel_da_carteira_leva_capital_como_numero_e_porte(): void
    {
        $admin = $this->admin();
        $this->cliente('100', self::MATRIZ, 'COM CAPITAL');
        $this->situacao(self::MATRIZ, 1250000.5, PorteEmpresa::ME);

        $request = Request::create('/carteira/exportar', 'POST');
        $request->setUserResolver(fn () => $admin);
        $export = app(CatalogoDeExportacoes::class)->plano('carteira', $request, $admin)->planilha;

        $cabecalho = $export->headings();
        $linha = $export->map($export->query()->get()->sole());

        $this->assertSame(1250000.5, $linha[array_search('Capital social', $cabecalho, true)]);
        $this->assertSame('Microempresa', $linha[array_search('Porte', $cabecalho, true)]);
    }

    public function test_excel_dos_leads_leva_capital_e_porte(): void
    {
        $admin = $this->admin();
        $this->lead(self::MATRIZ);
        $this->situacao(self::MATRIZ, 5000, PorteEmpresa::EPP);

        $request = Request::create('/leads/exportar', 'POST');
        $request->setUserResolver(fn () => $admin);
        $export = app(CatalogoDeExportacoes::class)->plano('leads', $request, $admin)->planilha;

        $linhas = $export->prepareRows($export->query()->get());
        $linha = $export->map($linhas->sole());
        $cabecalho = $export->headings();

        $this->assertSame(5000.0, $linha[array_search('Capital social', $cabecalho, true)]);
        $this->assertSame('Pequeno porte', $linha[array_search('Porte', $cabecalho, true)]);
    }

    public function test_cartao_grava_porte_normalizado_e_fonte_sem_capital_nao_apaga(): void
    {
        config(['receita.fontes' => ['brasilapi']]);
        $this->situacao(self::MATRIZ, 777, PorteEmpresa::EPP);

        Http::fake(['brasilapi.com.br/*' => Http::response([
            'cnpj' => self::MATRIZ,
            'razao_social' => 'MERCADO TESTE LTDA',
            'descricao_situacao_cadastral' => 'ATIVA',
            'porte' => 'MICRO EMPRESA',
        ])]);

        app(CartaoCnpjService::class)->consultar(self::MATRIZ);

        $linha = DB::table('cnpj_situacoes')->where('cnpj', self::MATRIZ)->first();
        $this->assertSame(PorteEmpresa::ME, $linha->porte);
        $this->assertEquals(777, (float) $linha->capital_social);
    }

    public function test_porte_por_texto_das_apis(): void
    {
        $this->assertSame(PorteEmpresa::ME, PorteEmpresa::deTexto('Micro Empresa'));
        $this->assertSame(PorteEmpresa::ME, PorteEmpresa::deTexto('ME'));
        $this->assertSame(PorteEmpresa::EPP, PorteEmpresa::deTexto('EMPRESA DE PEQUENO PORTE'));
        $this->assertSame(PorteEmpresa::DEMAIS, PorteEmpresa::deTexto('Demais'));
        $this->assertNull(PorteEmpresa::deTexto('NÃO INFORMADO'));
        $this->assertNull(PorteEmpresa::deCodigo('00'));
        $this->assertSame(PorteEmpresa::EPP, PorteEmpresa::deCodigo('3'));
    }

    // ---------------------------------------------------------------------------------

    private function admin(): User
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function cliente(string $cod, string $cnpj, string $razao): void
    {
        Cliente::create([
            'cod_cliente' => $cod,
            'loja' => '0001',
            'razao_social' => $razao,
            'cnpj' => $this->mascara($cnpj),
            'cod_vendedor' => '001',
            'estado' => 'SP',
        ]);
    }

    private function lead(string $cnpj): void
    {
        DB::table('leads')->insert([
            'origem' => 'manual', 'nome' => 'EMPRESA '.$cnpj, 'razao_social' => 'EMPRESA '.$cnpj,
            'cnpj' => $this->mascara($cnpj), 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function situacao(string $cnpj, float $capital, string $porte): void
    {
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => $cnpj, 'situacao' => 'ATIVA', 'capital_social' => $capital, 'porte' => $porte,
            'fonte' => SituacaoCadastral::FONTE_BASE, 'referencia' => '2026-08', 'atualizado_em' => now(),
        ]);
    }

    private function linhaEstabelecimento(string $cnpj): string
    {
        return sprintf('"%s";"%s";"%s";"1";"NOME";"02";"20200101";"00";"";"";"20200101";"4761003"',
            substr($cnpj, 0, 8), substr($cnpj, 8, 4), substr($cnpj, 12, 2));
    }

    /** @param list<string> $linhas */
    private function zip(string $sufixo, array $linhas): string
    {
        $caminho = sys_get_temp_dir().'/receita-capital-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::CREATE);
        $zip->addFromString("K3241.K03200Y0.D60912.{$sufixo}", implode("\n", $linhas)."\n");
        $zip->close();

        return $this->zips[] = $caminho;
    }

    private function mascara(string $cnpj): string
    {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj));
    }
}
