<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Exportacao\CatalogoDeExportacoes;
use App\Services\Receita\SituacaoCadastral;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Situação do CNPJ na Receita evidenciada na Carteira (decisão do Tony, 2026-10-01: só
 * evidenciar — a Carteira continua só leitura).
 *
 * A invariante que sustenta tudo: no modo agrupado, a pill "N filiais irregulares" e o
 * filtro `?receita=irregular` são a MESMA conta.
 *
 * ⚠️ Fixture assimétrico de propósito: o cliente MISTO tem uma filial baixada e uma
 * ativa — agrupado ele é irregular, por filial só a linha baixada entra. E o cliente
 * irregular de OUTRO vendedor trava o escopo da consolidação.
 */
class CarteiraSituacaoReceitaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => '001']);

        // MISTO: uma filial baixada (parada há anos), uma ativa (comprou há 5 dias) →
        // cliente irregular, pill "1 filial irregular", e status ATIVO pela filial boa.
        $this->cliente('100', '0001', 'MISTO', '11111111000111', '001', now()->subDays(900)->toDateString());
        $this->cliente('100', '0002', 'MISTO FILIAL', '11111111000222', '001', now()->subDays(5)->toDateString());
        $this->situacao('11111111000111', 'BAIXADA', '20240102');
        $this->situacao('11111111000222', 'ATIVA');

        // SÓ ATIVA (duas filiais, uma sem situação conhecida) → "ativa".
        $this->cliente('200', '0001', 'ATIVO', '22222222000122', '001');
        $this->cliente('200', '0002', 'ATIVO FILIAL', '22222222000233', '001');
        $this->situacao('22222222000122', 'ATIVA');

        // NUNCA VERIFICADO → "desconhecida", sem pill.
        $this->cliente('300', '0001', 'DESCONHECIDO', '33333333000133', '001');

        // FILIAL ÚNICA INAPTA → pill com a situação, "CNPJ inapta".
        $this->cliente('400', '0001', 'INAPTO', '44444444000144', '001');
        $this->situacao('44444444000144', 'INAPTA');

        // Outro vendedor, irregular: não pode aparecer nem contar para o 001.
        $this->cliente('500', '0001', 'DE OUTRO', '55555555000155', '002');
        $this->situacao('55555555000155', 'BAIXADA');

        // DIVIDIDO entre vendedores: a filial do 001 está ativa, a irregular é do 002.
        // Para o 001 o cliente NÃO é irregular — consolidar sem escopo o traria.
        $this->cliente('600', '0001', 'DIVIDIDO', '66666666000166', '001');
        $this->cliente('600', '0002', 'DIVIDIDO DO OUTRO', '66666666000277', '002');
        $this->situacao('66666666000166', 'ATIVA');
        $this->situacao('66666666000277', 'BAIXADA');
    }

    public function test_filtro_agrupado_traz_cliente_com_qualquer_filial_irregular(): void
    {
        // MISTO entra pela filial baixada; DE OUTRO (vendedor 002) não pode aparecer.
        $this->assertSame(['100', '400'], $this->codigos(['receita' => 'irregular']));
        $this->assertSame(2, $this->props(['receita' => 'irregular'])['clientes']['total']);
        $this->assertSame(5, $this->props()['clientes']['total']);
    }

    /**
     * Os KPIs do topo classificam o cliente filtrado por TODAS as filiais dele, como a pill
     * de status da linha. Filtrar por filial jogaria fora a filial boa do MISTO e o card o
     * contaria como inativo — e a linha, logo abaixo, dizendo "Ativo".
     */
    public function test_kpis_do_cliente_filtrado_olham_todas_as_filiais(): void
    {
        $kpis = $this->props(['receita' => 'irregular'])['kpis'];
        $somar = fn (string $faixa) => $kpis['dentroSegmento'][$faixa]
            + $kpis['foraSegmento'][$faixa]
            + $kpis['semSegmentoDefinido'][$faixa];

        $this->assertSame(2, $kpis['total']);
        $this->assertSame(1, $somar('ativos'), 'o MISTO comprou há 5 dias pela filial ativa');
        $this->assertSame(1, $somar('inativos'), 'o INAPTO nunca comprou');
    }

    public function test_pill_da_linha_bate_com_o_filtro(): void
    {
        $linhas = collect($this->props()['clientes']['data'])->keyBy('codCliente');

        $this->assertSame(1, $linhas['100']['receita']['irregulares']);
        $this->assertSame(0, $linhas['200']['receita']['irregulares']);
        $this->assertSame(0, $linhas['300']['receita']['irregulares']);
        $this->assertSame(0, $linhas['600']['receita']['irregulares'], 'a filial irregular é do outro vendedor');
        $this->assertSame(1, $linhas['400']['receita']['irregulares']);
        $this->assertTrue($linhas['400']['receita']['irregular']);
        $this->assertSame('INAPTA', $linhas['400']['receita']['situacao']);

        // Quem tem pill (irregulares > 0) é exatamente quem o filtro traz.
        $comPill = $linhas->filter(fn ($l) => $l['receita']['irregulares'] > 0)
            ->pluck('codCliente')->sort()->values()->all();
        $this->assertSame($this->codigos(['receita' => 'irregular']), $comPill);
    }

    public function test_por_filial_filtra_a_propria_linha(): void
    {
        $irregulares = collect($this->props(['receita' => 'irregular', 'agrupar' => '0'])['clientes']['data'])
            ->pluck('razaoSocial')->sort()->values()->all();

        // Por filial, só a linha baixada do MISTO entra — a filial ativa dele fica fora.
        $this->assertSame(['INAPTO', 'MISTO'], $irregulares);
    }

    public function test_valor_desconhecido_no_filtro_nao_filtra(): void
    {
        // 'ativa' existiu e saiu por latência: link velho com ele não pode filtrar nada.
        foreach (['qualquercoisa', 'ativa', 'desconhecida'] as $valor) {
            $this->assertSame(5, $this->props(['receita' => $valor])['clientes']['total']);
            $this->assertSame('', $this->props(['receita' => $valor])['filtros']['receita']);
        }
    }

    public function test_filiais_e_ficha_trazem_a_situacao(): void
    {
        $filiais = $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', '100'))
            ->assertOk()->json('filiais');
        $porLoja = collect($filiais)->keyBy('loja');

        $this->assertSame('BAIXADA', $porLoja['0001']['receita']['situacao']);
        $this->assertSame('2024-01-02', $porLoja['0001']['receita']['data']);
        $this->assertTrue($porLoja['0001']['receita']['irregular']);
        $this->assertFalse($porLoja['0002']['receita']['irregular']);

        $ficha = $this->actingAs($this->vendedor)
            ->get(route('carteira.detalhes', Cliente::where('razao_social', 'MISTO')->sole()))
            ->assertOk()->viewData('page')['props']['cliente']['receita'];

        $this->assertSame('BAIXADA', $ficha['situacao']);
        $this->assertTrue($ficha['irregular']);
        $this->assertSame('2026-09', $ficha['referencia']);
    }

    public function test_excel_leva_a_situacao_de_cada_filial(): void
    {
        $request = Request::create('/carteira/exportar', 'POST');
        $request->setUserResolver(fn () => $this->vendedor);
        $export = app(CatalogoDeExportacoes::class)->plano('carteira', $request, $this->vendedor)->planilha;

        $coluna = array_search('Situação Receita', $export->headings(), true);
        $linhas = $export->query()->get()->map(fn ($c) => $export->map($c))->keyBy(0);

        $this->assertSame('BAIXADA', $linhas['MISTO'][$coluna]);
        $this->assertSame('ATIVA', $linhas['MISTO FILIAL'][$coluna]);
        $this->assertNull($linhas['DESCONHECIDO'][$coluna]);
    }

    // ---------------------------------------------------------------------------------

    private function cliente(string $cod, string $loja, string $razao, string $cnpj, string $vendedor, ?string $compra = null): void
    {
        Cliente::create([
            'cod_cliente' => $cod,
            'loja' => $loja,
            'razao_social' => $razao,
            'cnpj' => vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj)),
            'cod_vendedor' => $vendedor,
            'estado' => 'SP',
            'data_ultima_compra' => $compra,
        ]);
    }

    private function situacao(string $cnpj, string $situacao, ?string $data = null): void
    {
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => $cnpj,
            'situacao' => $situacao,
            'data_situacao' => SituacaoCadastral::data($data),
            'fonte' => SituacaoCadastral::FONTE_BASE,
            'referencia' => '2026-09',
            'atualizado_em' => now(),
        ]);
    }

    private function props(array $params = []): array
    {
        return $this->actingAs($this->vendedor)->get(route('carteira.index', $params))
            ->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> */
    private function codigos(array $params): array
    {
        return collect($this->props($params)['clientes']['data'])->pluck('codCliente')->sort()->values()->all();
    }
}
