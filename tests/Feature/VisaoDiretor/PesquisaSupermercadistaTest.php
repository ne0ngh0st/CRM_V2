<?php

namespace Tests\Feature\VisaoDiretor;

use App\Exports\ClientesLeadsSegmentoExport;
use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Models\Lead;
use App\Models\Segmento;
use App\Models\User;
use App\Services\Exportacao\CatalogoDeExportacoes;
use App\Services\Receita\LojasNaReceita;
use App\Services\VisaoDiretor\FaturamentoDeMercado;
use App\Services\VisaoDiretor\PesquisaSupermercadista;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * A planilha do supermercadista é a pesquisa (cliente + lead + rede sem cliente),
 * com lojas na Receita e faturamento. A tela e o Excel dos outros segmentos não mudam.
 */
class PesquisaSupermercadistaTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->super = Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        GrupoCliente::create(['codigo' => '10', 'nome' => 'GRUPO BOM']);
    }

    public function test_excel_do_101_traz_cliente_lead_e_rede_sem_repetir_empresa(): void
    {
        $matriz = $this->cliente('000100', '0001', '101', '11111111000181', 'BOM PRECO', '10');
        $this->cliente('000100', '0002', '101', '11111111000262', 'BOM PRECO FILIAL');
        $this->receita('11111111000181', 'ATIVA', 4);
        $this->receita('11111111000262', 'ATIVA', 4);
        $this->faturamento('11111111000181', 1_500_000, 2025, 'abras');

        // Fechada, outra aba, a mesma empresa do cliente, outros, excluído, manual: fora.
        $this->cliente('000200', '0001', '101', '22222222000181', 'FECHADA');
        $this->receita('22222222000181', 'BAIXADA', 1);
        $this->cliente('000300', '0001', '109', '33333333000181', 'DROGARIA VIDA');
        $this->lead('MESMA EMPRESA', '11111111000999', 'sistema');
        $this->lead('FORA DO FUNIL', '44444444000181', 'sistema', ['etapa' => 'outros']);
        $this->lead('EXCLUIDO', '55555555000181', 'sistema', ['status' => 'excluido']);
        $this->lead('MANUAL', '66666666000181', 'manual');
        $lead = $this->lead('MERCADO DO ZE', '77777777000181', 'prospeccao', ['estado' => 'RJ']);
        $this->receita('77777777000181', 'ATIVA', 2);
        $this->faturamento('77777777000181', 2_500_000, 2023, 'web', 'https://www.valor.com.br/balanco');
        $semNumero = $this->cliente('000400', '0001', '101', '88888888000181', 'SEM NUMERO');
        $this->receita('88888888000181', 'ATIVA', 0);

        $ligada = ContaEstrategica::create(['segmento_id' => $this->super->id, 'nome' => 'BOM PRECO', 'uf' => 'SP', 'ordem' => 1]);
        $ligada->posicao_abras = 1;
        $ligada->save();
        ContaEstrategicaVinculo::create(['conta_id' => $ligada->id, 'tipo' => 'cliente', 'codigo' => '000100', 'origem' => 'sugestao']);

        $solta = ContaEstrategica::create(['segmento_id' => $this->super->id, 'nome' => 'REDE SOLTA', 'uf' => 'MG', 'ordem' => 2]);
        $solta->posicao_abras = 2;
        $solta->save();
        $this->faturamento('c'.$solta->id, 80_000, 2025, 'abras', contaId: $solta->id);

        $export = new ClientesLeadsSegmentoExport('101', []);
        $this->assertSame(
            ['Razão social', 'Nome fantasia', 'Tipo', 'CNPJ', 'Grupo', 'UF', 'Filiais', 'Lojas ativas na Receita', 'Faturamento anual (R$)', 'Ano do faturamento', 'Fonte', 'Status', 'Vendedor', 'Segmento'],
            $export->headings(),
        );

        $linhas = $export->array();
        $this->assertCount(count($export->headings()), $linhas[0]);
        $nomes = array_column($linhas, 0);

        $this->assertSame(['1. BOM PRECO', '2. REDE SOLTA'], array_slice($nomes, 0, 2));
        $this->assertContains('MERCADO DO ZE', $nomes);
        $this->assertContains('SEM NUMERO', $nomes);
        $this->assertNotContains('FECHADA', $nomes);
        $this->assertNotContains('DROGARIA VIDA', $nomes);
        $this->assertNotContains('MESMA EMPRESA', $nomes);
        $this->assertNotContains('FORA DO FUNIL', $nomes);
        $this->assertNotContains('EXCLUIDO', $nomes);
        $this->assertNotContains('MANUAL', $nomes);
        $this->assertCount(4, $linhas);

        $porNome = collect($linhas)->keyBy(0);
        $cliente = $porNome['1. BOM PRECO'];
        $this->assertSame('Cliente', $cliente[2]);
        $this->assertSame('GRUPO BOM', $cliente[4]);
        $this->assertSame(2, $cliente[6]);
        $this->assertSame(4, $cliente[7]);
        $this->assertSame(1_500_000, $cliente[8]);
        $this->assertSame(2025, $cliente[9]);
        $this->assertSame('ABRAS', $cliente[10]);

        $rede = $porNome['2. REDE SOLTA'];
        $this->assertSame('Rede ABRAS', $rede[2]);
        $this->assertSame('', $rede[7]);
        $this->assertSame(80_000, $rede[8]);
        $this->assertSame('ABRAS', $rede[10]);

        $doLead = $porNome['MERCADO DO ZE'];
        $this->assertSame('Lead', $doLead[2]);
        $this->assertSame(2, $doLead[7]);
        $this->assertSame(2_500_000, $doLead[8]);
        $this->assertSame(2023, $doLead[9]);
        $this->assertSame('valor.com.br', $doLead[10]);

        $vazio = $porNome['SEM NUMERO'];
        $this->assertSame(0, $vazio[7]);
        $this->assertSame(['', '', ''], array_slice($vazio, 8, 3));

        app(UniversoDoSegmento::class)->reconstruir();
        $tela = app(UniversoDoSegmento::class)->lista('101')['linhas']->total();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $plano = app(CatalogoDeExportacoes::class)->plano(
            'clientes-leads-segmento',
            Request::create('/exportacoes', 'POST', ['segmento' => '101']),
            $admin,
        );
        $this->assertSame(4, $plano->linhas);
        $this->assertNotSame($tela, $plano->linhas);

        $this->assertNotNull($matriz);
        $this->assertNotNull($lead);
        $this->assertNotNull($semNumero);
    }

    public function test_excel_do_109_continua_saindo_do_universo(): void
    {
        $this->cliente('000300', '0001', '109', '33333333000181', 'DROGARIA VIDA');
        app(UniversoDoSegmento::class)->reconstruir();

        $export = new ClientesLeadsSegmentoExport('109', []);
        $linhas = $export->array();

        $this->assertSame('Segmento', last($export->headings()));
        $this->assertNotContains('Lojas ativas na Receita', $export->headings());
        $this->assertSame(['DROGARIAS'], array_map(fn ($l) => last($l), $linhas));
        $this->assertSame(
            array_column(app(UniversoDoSegmento::class)->tudo('109'), 'nome'),
            array_column($linhas, 0),
        );
    }

    public function test_lojas_contam_so_estabelecimento_ativo_de_loja_e_o_arquivo_parcial_nao_grava(): void
    {
        $this->assertTrue(LojasNaReceita::eLoja('02', '4711302'));
        $this->assertFalse(LojasNaReceita::eLoja('08', '4711302'));
        $this->assertFalse(LojasNaReceita::eLoja('02', '4761003'));

        $this->receita('11222333000181', 'ATIVA', 9);
        $this->receita('11222333000262', 'ATIVA', null);
        LojasNaReceita::gravar(['11222333' => 6]);
        $this->assertSame(6, (int) DB::table('cnpj_situacoes')->where('cnpj', '11222333000181')->value('lojas_receita'));
        $this->assertSame(6, (int) DB::table('cnpj_situacoes')->where('cnpj', '11222333000262')->value('lojas_receita'));

        $this->cliente('000500', '0001', '101', '11222333000181', 'CONTA LOJA');
        $zip = $this->zip([
            $this->linha('11222333000181', '02', '4711302'),
        ]);
        $this->artisan('receita:importar-situacoes', ['--arquivo' => [$zip]])->assertSuccessful();
        $this->assertSame(6, (int) DB::table('cnpj_situacoes')->where('cnpj', '11222333000181')->value('lojas_receita'));

        $varredura = $this->zip([
            $this->linha('99887766000100', '02', '4711302'),
            $this->linha('99887766000200', '08', '4711302'),
            $this->linha('99887766000300', '02', '4761003'),
        ]);
        $comando = app(\App\Console\Commands\ImportarSituacoesReceita::class);
        $metodo = new \ReflectionMethod($comando, 'varrer');
        $achados = [];
        $lojas = ['99887766' => 0];
        $metodo->invokeArgs($comando, [$varredura, ['99887766000100' => true], &$achados, &$lojas]);
        $this->assertSame(1, $lojas['99887766']);
        $this->assertArrayHasKey('99887766000100', $achados);

        @unlink($zip);
        @unlink($varredura);
    }

    public function test_busca_na_web_grava_ano_e_url_e_nao_substitui_a_abras(): void
    {
        $this->lead('MERCADO DO ZE', '77777777000181', 'sistema', ['estado' => 'SP']);
        $this->faturamento('11111111000181', 1_500_000, 2025, 'abras');

        config(['pesquisa.firecrawl_key' => '']);
        Http::preventStrayRequests();
        $this->artisan('pesquisa:faturamento-mercado')->assertFailed();

        Http::fake([
            'api.firecrawl.dev/*' => Http::response(['data' => [[
                'url' => 'https://www.valor.com.br/balanco',
                'title' => 'Balanço',
                'description' => 'A empresa faturou R$ 2,5 milhões em 2024.',
            ]]]),
        ]);
        config(['pesquisa.firecrawl_key' => 'teste']);

        $this->artisan('pesquisa:faturamento-mercado', ['--limite' => 1])->assertSuccessful();

        $linha = DB::table('faturamento_mercado')->where('chave', '77777777000181')->first();
        $this->assertSame(2_500_000, (int) $linha->valor);
        $this->assertSame(2024, (int) $linha->ano);
        $this->assertSame('web', $linha->fonte);
        $this->assertSame('https://www.valor.com.br/balanco', $linha->url);
        $this->assertSame(1_500_000, (int) DB::table('faturamento_mercado')->where('chave', '11111111000181')->value('valor'));
        $this->assertSame('abras', DB::table('faturamento_mercado')->where('chave', '11111111000181')->value('fonte'));

        $this->artisan('pesquisa:faturamento-mercado')->assertSuccessful();
        Http::assertSentCount(1);

        $this->assertFalse(app(FaturamentoDeMercado::class)->gravarWeb('11111111000181', 1, 2024, 'https://x', null));
        $this->assertSame('abras', DB::table('faturamento_mercado')->where('chave', '11111111000181')->value('fonte'));
    }

    private function cliente(string $codigo, string $loja, string $segmento, string $cnpj, string $nome, ?string $grupo = null): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $codigo, 'loja' => $loja, 'cnpj' => $this->mascara($cnpj),
            'razao_social' => $nome, 'cod_vendedor' => '000010', 'cod_segmento' => $segmento,
            'cod_grupo' => $grupo ?? '9998', 'estado' => 'SP', 'data_ultima_compra' => now()->subDays(10),
        ]);
    }

    private function lead(string $nome, string $cnpj, string $origem, array $extra = []): Lead
    {
        return Lead::forceCreate([
            'origem' => $origem, 'nome' => $nome, 'razao_social' => $nome,
            'cnpj' => $this->mascara($cnpj), 'segmento' => 'SUPERMERCADISTA',
            'status' => 'ativo', 'etapa' => 'novo', ...$extra,
        ]);
    }

    private function receita(string $cnpj, string $situacao, ?int $lojas): void
    {
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => $cnpj, 'situacao' => $situacao, 'lojas_receita' => $lojas,
            'fonte' => 'base', 'atualizado_em' => now(),
        ]);
    }

    private function faturamento(string $chave, int $valor, int $ano, string $fonte, ?string $url = null, ?int $contaId = null): void
    {
        DB::table('faturamento_mercado')->insert([
            'chave' => $chave, 'conta_id' => $contaId, 'valor' => $valor, 'ano' => $ano,
            'fonte' => $fonte, 'url' => $url, 'consultado_em' => now(),
        ]);
    }

    private function mascara(string $d): string
    {
        return substr($d, 0, 2).'.'.substr($d, 2, 3).'.'.substr($d, 5, 3).'/'.substr($d, 8, 4).'-'.substr($d, 12, 2);
    }

    private function linha(string $cnpj, string $situacao, string $cnae): string
    {
        return sprintf('"%s";"%s";"%s";"1";"NOME";"%s";"20200101";"00";"";"";"20200101";"%s"',
            substr($cnpj, 0, 8), substr($cnpj, 8, 4), substr($cnpj, 12, 2), $situacao, $cnae);
    }

    /** @param  list<string>  $linhas */
    private function zip(array $linhas): string
    {
        $caminho = sys_get_temp_dir().'/receita-lojas-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::CREATE);
        $zip->addFromString('ESTABELE', implode("\n", $linhas)."\n");
        $zip->close();

        return $caminho;
    }
}
