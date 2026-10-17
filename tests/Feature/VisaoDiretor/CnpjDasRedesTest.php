<?php

namespace Tests\Feature\VisaoDiretor;

use App\Exports\MaioresRedesExport;
use App\Models\Cliente;
use App\Models\CnpjConsulta;
use App\Models\RedeMercado;
use App\Models\User;
use App\Services\VisaoDiretor\Redes\CnpjDasRedes;
use App\Services\VisaoDiretor\Redes\MaioresRedes;
use App\Services\VisaoDiretor\Redes\MaioresRedesResolver;
use App\Services\VisaoDiretor\Redes\SituacaoDaRede;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

/**
 * CNPJ das redes do mercado (Maiores redes): a carga escolhe a empresa dona das lojas na
 * base aberta da Receita, a tela mostra a situação dela e a das filiais dos nossos
 * clientes, e o CNPJ informado à mão nunca é sobrescrito pela carga.
 */
class CnpjDasRedesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var list<string> */
    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        app(MaioresRedes::class)->importar(['redes' => [
            $this->rede(1, 'drogarias', 'DROGARIA ALFA', 6),
            $this->rede(2, 'estacionamentos', 'BETA PARK', 20),
            $this->rede(3, 'alimentacao', 'GAMA BURGER', 400),
            $this->rede(4, 'drogarias', 'DROGARIA DELTA', 30),
        ]]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', $this->zips);
        parent::tearDown();
    }

    private function rede(int $posicao, string $categoria, string $nome, ?int $lojas): array
    {
        return ['posicao' => $posicao, 'categoria' => $categoria, 'nome' => $nome, 'apelidos' => [], 'uf' => 'SP', 'lojas' => $lojas,
            'data_base' => 'dez/25', 'qualidade' => 'atualizado', 'fonte' => null, 'linha_original' => $nome];
    }

    private function rede_(string $nome): RedeMercado
    {
        return RedeMercado::where('nome', $nome)->firstOrFail();
    }

    /** Uma linha de Estabelecimentos no layout da Receita (30 campos, entre aspas, latin1). */
    private function estabelecimento(string $raiz, string $ordem, bool $matriz, string $fantasia, string $situacao, string $cnae): string
    {
        $c = array_fill(0, 30, '');
        [$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $c[11], $c[19]] =
            [$raiz, $ordem, '00', $matriz ? '1' : '2', $fantasia, $situacao, '20200101', $cnae, 'SP'];

        return '"'.implode('";"', $c).'"';
    }

    private function empresa(string $raiz, string $razao, string $natureza = '2062'): string
    {
        return '"'.implode('";"', [$raiz, $razao, $natureza, '49', '1000000,00', '05', '']).'"';
    }

    private function zip(array $linhas): string
    {
        $caminho = sys_get_temp_dir().'/redes-receita-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::CREATE);
        $zip->addFromString('K3241.CSV', mb_convert_encoding(implode("\n", $linhas)."\n", 'ISO-8859-1', 'UTF-8'));
        $zip->close();

        return $this->zips[] = $caminho;
    }

    /**
     * ALFA: a empresa viva (5 lojas ativas) e uma antiga com o mesmo nome, toda baixada.
     * BETA PARK: só empresa inapta. GAMA BURGER: 12 franqueados, nenhum domina.
     */
    private function carregar(array $extras = []): void
    {
        $estab = [
            $this->estabelecimento('11111111', '0001', true, 'DROGARIA ALFA', '02', '4771701'),
            ...array_map(fn ($i) => $this->estabelecimento('11111111', sprintf('%04d', $i + 2), false, 'DROGARIA ALFA', '02', '4771701'), range(0, 3)),
            $this->estabelecimento('22222222', '0001', true, 'ALFA', '08', '4771701'),
            ...array_map(fn ($i) => $this->estabelecimento('22222222', sprintf('%04d', $i + 2), false, 'ALFA', '08', '4771701'), range(0, 5)),
            $this->estabelecimento('33333333', '0001', true, 'BETA PARK', '04', '5223100'),
            $this->estabelecimento('33333333', '0002', false, 'BETA PARK', '04', '5223100'),
            ...array_map(fn ($i) => $this->estabelecimento(sprintf('4%07d', $i), '0001', true, 'GAMA BURGER', '02', '5611201'), range(1, 12)),
            $this->estabelecimento('55555555', '0001', true, 'DROGARIA DELTA', '02', '4771701'),
            ...$extras,
        ];
        $empresas = [
            $this->empresa('11111111', 'ALFA DROGARIAS LTDA'),
            $this->empresa('22222222', 'ANTIGA COMERCIO DE MEDICAMENTOS LTDA'),
            $this->empresa('33333333', 'BETA PARK ESTACIONAMENTOS LTDA'),
            $this->empresa('55555555', 'DELTA DROGARIAS LTDA'),
            ...array_map(fn ($i) => $this->empresa(sprintf('4%07d', $i), "FRANQUEADO {$i} LANCHES LTDA"), range(1, 12)),
        ];

        $this->artisan('visao-diretor:cnpj-das-redes', [
            '--empresas' => [$this->zip($empresas)],
            '--estabelecimentos' => [$this->zip($estab)],
            '--referencia' => '2026-10',
        ])->assertSuccessful();
    }

    public function test_escolhe_a_empresa_com_lojas_vivas_e_nao_a_antiga_baixada(): void
    {
        $this->carregar();

        $alfa = $this->rede_('DROGARIA ALFA');
        $this->assertSame('11111111000100', $alfa->cnpj);
        $this->assertSame('ALFA DROGARIAS LTDA', $alfa->cnpj_razao_social);
        $this->assertSame(CnpjDasRedes::ALTA, $alfa->cnpj_confianca);
        $this->assertSame(5, $alfa->cnpj_lojas_ativas);
        $this->assertSame('2026-10', $alfa->cnpj_referencia);

        // A situação da matriz vai para o cnpj_situacoes, a resposta única da Receita.
        $this->assertSame('ATIVA', DB::table('cnpj_situacoes')->where('cnpj', '11111111000100')->value('situacao'));
    }

    public function test_rede_so_com_empresa_fechada_fica_com_o_cnpj_inapto(): void
    {
        $this->carregar();

        $beta = $this->rede_('BETA PARK');
        $this->assertSame(CnpjDasRedes::SO_FECHADAS, $beta->cnpj_confianca);
        $this->assertSame('33333333000100', $beta->cnpj);
        $this->assertSame('INAPTA', DB::table('cnpj_situacoes')->where('cnpj', '33333333000100')->value('situacao'));
    }

    public function test_franquia_nao_grava_cnpj(): void
    {
        $this->carregar();

        $gama = $this->rede_('GAMA BURGER');
        $this->assertSame(CnpjDasRedes::FRANQUIA, $gama->cnpj_confianca);
        $this->assertNull($gama->cnpj);
    }

    public function test_loja_fora_do_ramo_da_categoria_nao_conta(): void
    {
        // 8 "postos" com o nome da DELTA: casam o nome, mas o CNAE não é de drogaria.
        $this->carregar(array_map(fn ($i) => $this->estabelecimento('66666666', sprintf('%04d', $i), $i === 1, 'DELTA', '02', '4731800'), range(1, 8)));

        $delta = $this->rede_('DROGARIA DELTA');
        $this->assertSame('55555555000100', $delta->cnpj);
    }

    public function test_cnpj_informado_a_mao_nao_e_sobrescrito_pela_carga(): void
    {
        $alfa = $this->rede_('DROGARIA ALFA');
        app(MaioresRedes::class)->informarCnpj($alfa, '99999999000199', 'OUTRA EMPRESA SA', $this->admin->id);

        $this->carregar();

        $alfa->refresh();
        $this->assertSame('99999999000199', $alfa->cnpj);
        $this->assertSame(CnpjDasRedes::MANUAL, $alfa->cnpj_confianca);
        $this->assertSame($this->admin->id, $alfa->cnpj_user_id);
    }

    public function test_dry_run_nao_grava(): void
    {
        $this->artisan('visao-diretor:cnpj-das-redes', [
            '--empresas' => [$this->zip([$this->empresa('11111111', 'ALFA DROGARIAS LTDA')])],
            '--estabelecimentos' => [$this->zip([$this->estabelecimento('11111111', '0001', true, 'DROGARIA ALFA', '02', '4771701')])],
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertNull($this->rede_('DROGARIA ALFA')->cnpj_confianca);
        $this->assertSame(0, DB::table('cnpj_situacoes')->count());
    }

    public function test_tela_mostra_a_situacao_e_o_filtro_traz_so_as_irregulares(): void
    {
        $this->carregar();

        $this->actingAs($this->admin)
            ->get(route('visao-diretor.redes.index', ['categoria' => 'estacionamentos']))
            ->assertInertia(fn (Assert $p) => $p
                ->where('lista.contagens.fechada', 1)
                ->where('lista.linhas.0.cnpj', '33.333.333/0001-00')
                ->where('lista.linhas.0.cnpjSituacao', 'INAPTA')
                ->where('lista.linhas.0.situacaoRede', SituacaoDaRede::FECHADA)
                ->where('lista.linhas.0.cnpjConfiancaRotulo', CnpjDasRedes::CONFIANCAS[CnpjDasRedes::SO_FECHADAS]));

        // Drogarias: ALFA ativa, DELTA ativa → o filtro não traz nenhuma.
        $this->actingAs($this->admin)
            ->get(route('visao-diretor.redes.index', ['categoria' => 'drogarias', 'receita' => 'fechada']))
            ->assertInertia(fn (Assert $p) => $p->where('lista.contagens.fechada', 0)->has('lista.linhas', 0));
    }

    /** Franquia não tem CNPJ único, mas as lojas ativas com o nome provam que ela existe. */
    public function test_franquia_com_lojas_ativas_e_rede_ativa(): void
    {
        $this->carregar();

        $gama = $this->rede_('GAMA BURGER');
        $this->assertSame(12, $gama->cnpj_lojas_com_nome_ativas);
        $this->assertSame(SituacaoDaRede::ATIVA, $gama->situacao_rede);
        $this->assertSame(SituacaoDaRede::FECHADA, $this->rede_('BETA PARK')->situacao_rede);
    }

    /**
     * É pesquisa de MERCADO: filial nossa com CNPJ fechado não fecha a rede (o Burger King
     * compra da gente com o CNPJ antigo baixado). O contador existe, o veredito não o usa.
     */
    public function test_filial_nossa_fechada_nao_fecha_a_rede(): void
    {
        $this->carregar();
        foreach (['0001' => '12345678000190', '0002' => '12345678000270'] as $loja => $cnpj) {
            Cliente::create([
                'cod_cliente' => '000777', 'loja' => $loja, 'razao_social' => 'DELTA DROGARIAS LTDA', 'nome_fantasia' => 'DROGARIA DELTA',
                'cnpj' => vsprintf('%s.%s.%s/%s-%s', sscanf($cnpj, '%2s%3s%3s%4s%2s')), 'cod_grupo' => '9998', 'cod_segmento' => '109',
                'cod_vendedor' => '000001', 'estado' => 'SP', 'data_ultima_compra' => now()->subDays(10),
            ]);
        }
        DB::table('cnpj_situacoes')->insert([
            ['cnpj' => '12345678000190', 'situacao' => 'ATIVA', 'fonte' => 'receita_base', 'atualizado_em' => now()],
            ['cnpj' => '12345678000270', 'situacao' => 'BAIXADA', 'fonte' => 'receita_base', 'atualizado_em' => now()],
        ]);
        DB::table('cnpj_situacoes')->where('cnpj', '12345678000190')->update(['situacao' => 'BAIXADA']);
        app(MaioresRedes::class)->reconstruir();

        $delta = $this->rede_('DROGARIA DELTA');
        $this->assertSame(2, $delta->filiais_cnpj_irregular);
        $this->assertSame(SituacaoDaRede::ATIVA, $delta->situacao_rede);

        $this->actingAs($this->admin)
            ->get(route('visao-diretor.redes.index', ['categoria' => 'drogarias', 'receita' => 'fechada']))
            ->assertInertia(fn (Assert $p) => $p->has('lista.linhas', 0));
    }

    public function test_corrigir_cnpj_pela_tela_usa_o_cartao_e_vira_manual(): void
    {
        CnpjConsulta::create(['cnpj' => '12345678000190', 'situacao' => 'ATIVA', 'fonte' => 'brasilapi', 'consultado_em' => now(),
            'dados' => ['razaoSocial' => 'ALFA HOLDING SA', 'situacao' => 'ATIVA']]);
        DB::table('cnpj_situacoes')->insert(['cnpj' => '12345678000190', 'situacao' => 'ATIVA', 'fonte' => 'brasilapi', 'atualizado_em' => now()]);
        $alfa = $this->rede_('DROGARIA ALFA');

        $this->actingAs($this->admin)
            ->putJson(route('visao-diretor.redes.cnpj', $alfa), ['cnpj' => '12.345.678/0001-90'])
            ->assertOk()
            ->assertJsonPath('rede.cnpj', '12.345.678/0001-90')
            ->assertJsonPath('rede.cnpjSituacao', 'ATIVA')
            ->assertJsonPath('rede.cnpjConfianca', CnpjDasRedes::MANUAL);

        $alfa->refresh();
        $this->assertSame('ALFA HOLDING SA', $alfa->cnpj_razao_social);

        // "Sem CNPJ único" também é decisão à mão.
        $this->actingAs($this->admin)->putJson(route('visao-diretor.redes.cnpj', $alfa), ['cnpj' => null])->assertOk();
        $alfa->refresh();
        $this->assertNull($alfa->cnpj);
        $this->assertSame(CnpjDasRedes::MANUAL, $alfa->cnpj_confianca);
    }

    public function test_corrigir_cnpj_recusa_invalido_e_exige_o_gate(): void
    {
        $alfa = $this->rede_('DROGARIA ALFA');

        $this->actingAs($this->admin)->putJson(route('visao-diretor.redes.cnpj', $alfa), ['cnpj' => '123'])->assertStatus(422);
        $this->assertNull($alfa->refresh()->cnpj_confianca);

        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        $this->actingAs($vendedor)->putJson(route('visao-diretor.redes.cnpj', $alfa), ['cnpj' => null])->assertForbidden();
    }

    public function test_excel_leva_o_cnpj_e_a_situacao(): void
    {
        $this->carregar();

        [$redes] = (new MaioresRedesExport(MaioresRedesResolver::filtros(['categoria' => 'estacionamentos'])))->sheets();
        $linha = array_combine($redes->headings(), $redes->array()[0]);

        $this->assertSame('33.333.333/0001-00', $linha['CNPJ da rede']);
        $this->assertSame('INAPTA', $linha['Situação na Receita']);
        $this->assertSame('01/01/2020', $linha['Situação desde']);
        $this->assertSame(CnpjDasRedes::CONFIANCAS[CnpjDasRedes::SO_FECHADAS], $linha['Confiança do CNPJ']);
    }
}
