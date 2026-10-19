<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\Lead;
use App\Models\Segmento;
use App\Services\Leads\SegmentoDosLeads;
use App\Services\Receita\SituacaoCadastral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Aba SUPERMERCADISTA da Maiores por Segmento (2026-10-08): os leads da base antiga ganham
 * segmento pelo CNAE da Receita, e as contas da aba nascem do ranking da ABRAS.
 */
class SupermercadistaAbrasTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $supermercadista;

    private Segmento $drogarias;

    private ?string $arquivo = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supermercadista = Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        $this->drogarias = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
    }

    protected function tearDown(): void
    {
        if ($this->arquivo) {
            @unlink(base_path($this->arquivo));
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- classificação

    public function test_lead_da_base_antiga_ganha_o_segmento_do_cnae(): void
    {
        $super = $this->lead('11111111000191', cnae: '4711302');
        $mini = $this->lead('22222222000191', cnae: '4712100');
        $hiper = $this->lead('33333333000191', cnae: '4711301');
        $atacado = $this->lead('44444444000191', cnae: '4639701');
        $hortifruti = $this->lead('55555555000191', cnae: '4724500');

        $r = app(SegmentoDosLeads::class)->classificar();

        $this->assertSame(['SUPERMERCADISTA' => 5], $r['classificados']);
        foreach ([$super, $mini, $hiper, $atacado, $hortifruti] as $id) {
            $this->assertSame('SUPERMERCADISTA', $this->segmentoDo($id));
        }
    }

    /** Mutação: classificar todo lead com CNAE conhecido, sem olhar o mapa. */
    public function test_cnae_fora_do_mapa_e_lead_sem_cnae_ficam_sem_segmento(): void
    {
        $transportadora = $this->lead('11111111000191', cnae: '4930202');
        $semCnae = $this->lead('22222222000191');

        $r = app(SegmentoDosLeads::class)->classificar();

        $this->assertNull($this->segmentoDo($transportadora));
        $this->assertNull($this->segmentoDo($semCnae));
        $this->assertSame(1, $r['foraDoMapa']);
        $this->assertSame(1, $r['semCnae']);
        $this->assertSame(['4930202' => 1], $r['cnaesForaDoMapa']);
    }

    /** Mutação: tirar o filtro de segmento vazio, ou o de origem. */
    public function test_nunca_sobrescreve_segmento_nem_toca_lead_manual_ou_do_site(): void
    {
        $comSegmento = $this->lead('11111111000191', cnae: '4711302', segmento: 'DROGARIAS', origem: 'prospeccao');
        $manual = $this->lead('22222222000191', cnae: '4711302', origem: 'manual');
        $site = $this->lead('33333333000191', cnae: '4711302', origem: 'wordpress');

        app(SegmentoDosLeads::class)->classificar();

        $this->assertSame('DROGARIAS', $this->segmentoDo($comSegmento));
        $this->assertNull($this->segmentoDo($manual));
        $this->assertNull($this->segmentoDo($site));
    }

    public function test_comando_em_dry_run_nao_grava(): void
    {
        $id = $this->lead('11111111000191', cnae: '4711302');

        $this->artisan('leads:classificar-segmento', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($this->segmentoDo($id));

        $this->artisan('leads:classificar-segmento')->assertSuccessful();
        $this->assertSame('SUPERMERCADISTA', $this->segmentoDo($id));
    }

    // ---------------------------------------------------------------- ranking ABRAS

    public function test_ranking_cria_as_contas_na_ordem_da_posicao(): void
    {
        $this->ranking([
            [2, 'ASSAÍ ATACADISTA', 'ASSAÍ ATACADISTA', 'SP'],
            [1, 'CARREFOUR', 'CARREFOUR COMÉRCIO E INDÚSTRIA LTDA.', 'SP'],
            [3, 'MATEUS SUPERMERCADOS', 'MATEUS SUPERMERCADOS S.A.', 'MA'],
        ]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertSame(
            [1 => 'CARREFOUR', 2 => 'ASSAÍ ATACADISTA', 3 => 'MATEUS SUPERMERCADOS'],
            ContaEstrategica::where('segmento_id', $this->supermercadista->id)->orderBy('ordem')->pluck('nome', 'ordem')->all(),
        );
        $this->assertSame('MA', ContaEstrategica::where('nome', 'MATEUS SUPERMERCADOS')->value('uf'));
    }

    public function test_limite_corta_o_ranking(): void
    {
        $this->ranking([[1, 'CARREFOUR', 'CARREFOUR LTDA', 'SP'], [2, 'ASSAÍ', 'ASSAÍ', 'SP']]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo, '--limite' => 1])->assertSuccessful();

        $this->assertSame(['CARREFOUR'], ContaEstrategica::pluck('nome')->all());
    }

    /** Mutação: criar sempre (sem procurar pela chave do nome). */
    public function test_rodar_de_novo_nao_duplica_e_nao_sobrescreve_edicao(): void
    {
        // Criada antes pela coluna `rede` da prospecção, com outro jeito de escrever.
        $existente = ContaEstrategica::create([
            'segmento_id' => $this->supermercadista->id, 'nome' => 'Rede Savegnago', 'uf' => 'MG',
            'observacao' => 'conversa com o comprador', 'ordem' => 1,
        ]);
        $foraDoRanking = ContaEstrategica::create(['segmento_id' => $this->supermercadista->id, 'nome' => 'MERCADO LOCAL', 'ordem' => 2]);
        $this->ranking([[1, 'CARREFOUR', 'CARREFOUR LTDA', 'SP'], [2, 'SAVEGNAGO SUPERMERCADOS', 'SAVEGNAGO SUPERMERCADOS LTDA.', 'SP']]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();
        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertSame(3, ContaEstrategica::count());
        $existente->refresh();
        $this->assertSame('REDE SAVEGNAGO', $existente->nome, 'nome da tela não é trocado');
        $this->assertSame('MG', $existente->uf, 'UF só é preenchida se vazia');
        $this->assertSame('conversa com o comprador', $existente->observacao);
        $this->assertSame(2, $existente->ordem, 'a ordem é a posição no ranking');
        $this->assertSame(3, $foraDoRanking->refresh()->ordem, 'quem não está no ranking vai para depois dele');
    }

    public function test_dry_run_do_ranking_nao_grava(): void
    {
        $this->ranking([[1, 'CARREFOUR', 'CARREFOUR LTDA', 'SP']]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, ContaEstrategica::count());
    }

    /**
     * Os clientes entram como sugestão (pela razão social), e só nas contas da aba nova: a
     * carga não pode mexer em conta de outra aba sem ninguém pedir.
     */
    public function test_vincula_clientes_so_nas_contas_da_aba_nova(): void
    {
        $this->filial('000100', '101', 'CARREFOUR BAIRRO', 'CARREFOUR COMERCIO E INDUSTRIA LTDA', '45543915000181');
        $this->filial('000200', '109', 'DROGARIA VIDA', 'DROGARIA VIDA LTDA', '12345678000195');
        $drogaria = ContaEstrategica::create(['segmento_id' => $this->drogarias->id, 'nome' => 'DROGARIA VIDA', 'ordem' => 1]);
        $this->ranking([[1, 'CARREFOUR', 'CARREFOUR COMÉRCIO E INDÚSTRIA LTDA.', 'SP']]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $carrefour = ContaEstrategica::where('nome', 'CARREFOUR')->sole();
        $this->assertSame(
            [['cliente', '000100', ContaEstrategicaVinculo::ORIGEM_SUGESTAO]],
            $carrefour->vinculos->map(fn ($v) => [$v->tipo, $v->codigo, $v->origem])->all(),
        );
        $this->assertSame(0, $drogaria->vinculos()->count());
    }

    /**
     * A razão social é o vínculo: usa as `razoes` de quem a ABRAS publica só pela marca, e
     * o nome de marca não entra — o "mercadinho Savegnago" é homônimo.
     */
    public function test_razao_social_e_o_vinculo_forte(): void
    {
        $this->filial('000911', '101', 'PAO DE ACUCAR - LOJA 1314', 'CIA BRASILEIRA DE DISTRIBUICAO', '47508411000156');
        $this->filial('000300', '101', 'SAVEGNAGO CENTRO', 'SAVEGNAGO SUPERMERCADOS LTDA', '71322150000130');
        $this->filial('000301', '101', 'SAVEGNAGO MERCADINHO', 'MERCADINHO SAVEGNAGO DO BAIRRO LTDA', '99999999000191');
        $this->ranking([
            [5, 'GPA', 'GPA', 'SP', ['CIA BRASILEIRA DE DISTRIBUICAO']],
            [17, 'SAVEGNAGO SUPERMERCADOS', 'SAVEGNAGO SUPERMERCADOS LTDA.', 'SP'],
        ]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertSame(['000911'], $this->codigosDa('GPA'));
        $this->assertSame(['000300'], $this->codigosDa('SAVEGNAGO SUPERMERCADOS'), 'o homônimo pelo nome não entra');
    }

    /**
     * Sugestão por nome que a conta já tinha sai quando a razão social não casa; vínculo
     * manual nunca é tocado.
     *
     * Mutação: tirar a limpeza (`sincronizarVinculos($conta, [])`).
     */
    public function test_sem_razao_social_a_sugestao_por_nome_sai_e_o_manual_fica(): void
    {
        $this->filial('000500', '101', 'PAGUE MENOS DE TUPACIGUARA', 'PAGUE MENOS DE TUPACIGUARA LTDA', '55555555000191');
        $this->filial('005507', '101', 'SONDA - ESCRITORIO', 'SONDA SUPERMERCADOS EXPORTACAO E IMPORTA', '66666666000191');
        $pagueMenos = ContaEstrategica::create(['segmento_id' => $this->supermercadista->id, 'nome' => 'PAGUE MENOS', 'ordem' => 1]);
        $pagueMenos->vinculos()->create(['tipo' => 'cliente', 'codigo' => '000500', 'origem' => ContaEstrategicaVinculo::ORIGEM_SUGESTAO]);
        $sonda = ContaEstrategica::create(['segmento_id' => $this->supermercadista->id, 'nome' => 'SONDA SUPERMERCADOS', 'ordem' => 2]);
        $sonda->vinculos()->create(['tipo' => 'cliente', 'codigo' => '999999', 'origem' => ContaEstrategicaVinculo::ORIGEM_MANUAL]);
        $this->ranking([
            [1, 'PAGUE MENOS', 'PAGUE MENOS COM. DE PROD. ALIM. LTDA.', 'SP'],
            [2, 'SONDA SUPERMERCADOS', 'SONDA SUPERMERCADOS EXPORTAÇÃO E IMPORTAÇÃO S.A.', 'SP'],
        ]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertSame([], $this->codigosDa('PAGUE MENOS'));
        $this->assertSame(['999999'], $this->codigosDa('SONDA SUPERMERCADOS'), 'manual não é tocado');
    }

    /**
     * Razão social que não lembra a rede, mas a bobina personalizada tem o nome dela
     * (Muffato, 2026-10-19). Código que a razão social pôs em OUTRA conta fica lá.
     *
     * Mutação: tirar a camada de produto, ou deixar o produto tirar código da razão social.
     */
    public function test_produto_personalizado_liga_o_cliente_a_rede(): void
    {
        $this->filial('003781', '101', 'SUPERMERCADO CENTRO', 'COMERCIAL PR ALIMENTOS LTDA', '76430438000102');
        $this->filial('000100', '101', 'CARREFOUR BAIRRO', 'CARREFOUR COMERCIO E INDUSTRIA LTDA', '45543915000181');
        DB::table('faturamento_cliente_produto')->insert([
            ['cod_produto' => 'V23470', 'cod_cliente' => '003781', 'descricao' => 'BOBINA TS KPH BC 80X80M MAX MUFFATO', 'primeira_emissao' => '2023-01-10', 'ultima_emissao' => '2024-03-26'],
            ['cod_produto' => 'V23470', 'cod_cliente' => '000100', 'descricao' => 'BOBINA TS KPH BC 80X80M MAX MUFFATO', 'primeira_emissao' => '2023-01-10', 'ultima_emissao' => '2024-03-26'],
        ]);
        $this->ranking([
            [1, 'CARREFOUR', 'CARREFOUR COMÉRCIO E INDÚSTRIA LTDA.', 'SP'],
            [6, 'IRMÃOS MUFFATO', 'IRMÃOS MUFFATO S.A', 'SP'],
        ]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertSame(['003781'], $this->codigosDa('IRMÃOS MUFFATO'));
        $this->assertSame(['000100'], $this->codigosDa('CARREFOUR'));
    }

    // ---------------------------------------------------------------- a aba na tela

    /**
     * A aba é SÓ o ranking (Tony, 2026-10-19): uma linha por rede, na ordem da posição.
     * O supermercado fora do ranking, a conta criada à mão e o lead do segmento não entram;
     * a empresa ligada a uma rede sai da aba em que estava (fica dentro da rede).
     *
     * Mutação: tirar o DELETE das empresas, ou o filtro dos leads, ou a ordem por posição.
     */
    public function test_aba_supermercadista_e_so_o_ranking(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $admin = \App\Models\User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->filial('000100', '101', 'CARREFOUR BAIRRO', 'CARREFOUR COMERCIO E INDUSTRIA LTDA', '45543915000181');
        // A mesma empresa, com uma filial cadastrada em Drogarias: vai junto para a rede.
        Cliente::create([
            'cod_cliente' => '000100', 'loja' => '0002', 'razao_social' => 'CARREFOUR COMERCIO E INDUSTRIA LTDA',
            'cnpj' => '45.543.915/0002-62', 'cod_grupo' => '9998', 'cod_segmento' => '109', 'cod_vendedor' => '000001',
            'estado' => 'RJ', 'data_ultima_compra' => now()->subDays(5),
        ]);
        $this->filial('000200', '101', 'MERCADINHO DO ZE', 'MERCADINHO DO ZE LTDA', '11222333000181');
        ContaEstrategica::create(['segmento_id' => $this->supermercadista->id, 'nome' => 'REDE DA PROSPECCAO', 'ordem' => 1]);
        $this->lead('77777777000191', segmento: 'SUPERMERCADISTA', origem: 'prospeccao');
        $this->ranking([
            [2, 'MATEUS SUPERMERCADOS', 'MATEUS SUPERMERCADOS S.A.', 'MA'],
            [1, 'CARREFOUR', 'CARREFOUR COMÉRCIO E INDÚSTRIA LTDA.', 'SP'],
        ]);
        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        app(\App\Services\VisaoDiretor\UniversoDoSegmento::class)->reconstruir();
        $universo = app(\App\Services\VisaoDiretor\UniversoDoSegmento::class);
        $lista = $universo->lista('101');
        $linhas = collect($lista['linhas']->items());

        $this->assertSame([[1, 'CARREFOUR'], [2, 'MATEUS SUPERMERCADOS']], $linhas->map(fn ($l) => [$l['posicao'], $l['nome']])->all());
        $this->assertSame(2, $lista['contagens']['total']);

        $carrefour = $linhas->firstWhere('nome', 'CARREFOUR');
        $this->assertSame('cliente', $carrefour['tipo']);
        $this->assertSame(2, $carrefour['filiais']);
        $this->assertSame('ativo', $carrefour['status'], 'a última compra é a de todas as filiais da rede');
        $this->assertSame('CARREFOUR COMERCIO E INDUSTRIA LTDA', $carrefour['nomeFantasia']);

        $mateus = $linhas->firstWhere('nome', 'MATEUS SUPERMERCADOS');
        $this->assertSame('lead', $mateus['status']);
        $this->assertNull($mateus['filiais']);

        // A empresa ligada à rede não aparece de novo em Drogarias.
        $this->assertSame(0, $universo->lista('109')['contagens']['total']);

        // As filiais da rede == a Carteira que o clique abre.
        $this->actingAs($admin)
            ->get(route('carteira.index', ['conta_alvo' => $carrefour['contaId'], 'agrupar' => 0]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('clientes.total', 2));
    }

    /** Conta que saiu do ranking perde a posição (e sai da aba). */
    public function test_conta_fora_do_ranking_perde_a_posicao(): void
    {
        $antiga = ContaEstrategica::create(['segmento_id' => $this->supermercadista->id, 'nome' => 'REDE ANTIGA', 'ordem' => 1]);
        $antiga->forceFill(['posicao_abras' => 1])->save();
        $this->ranking([[1, 'CARREFOUR', 'CARREFOUR LTDA', 'SP']]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertNull($antiga->fresh()->posicao_abras);
        $this->assertSame(1, ContaEstrategica::where('nome', 'CARREFOUR')->value('posicao_abras'));
    }

    private function codigosDa(string $nome): array
    {
        return ContaEstrategica::where('nome', $nome)->sole()->vinculos()->orderBy('codigo')->pluck('codigo')->all();
    }

    // ---------------------------------------------------------------- apoio

    private function lead(string $cnpj, ?string $cnae = null, ?string $segmento = null, string $origem = 'sistema', ?string $razao = null): int
    {
        if ($cnae !== null) {
            DB::table('cnpj_situacoes')->insert([
                'cnpj' => $cnpj, 'situacao' => 'ATIVA', 'cnae_principal' => $cnae,
                'fonte' => SituacaoCadastral::FONTE_BASE, 'referencia' => '2026-09', 'atualizado_em' => now(),
            ]);
        }

        $razao ??= 'EMPRESA '.$cnpj;

        return DB::table('leads')->insertGetId([
            'origem' => $origem, 'nome' => $razao, 'razao_social' => $razao, 'segmento' => $segmento,
            'cnpj' => vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj)),
            'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function segmentoDo(int $id): ?string
    {
        return DB::table('leads')->where('id', $id)->value('segmento');
    }

    private function filial(string $codigo, string $segmento, string $fantasia, string $razao, ?string $cnpj = null): void
    {
        Cliente::create([
            'cod_cliente' => $codigo, 'loja' => '0001', 'razao_social' => $razao, 'nome_fantasia' => $fantasia,
            'cnpj' => $cnpj ? vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj)) : null,
            'cod_grupo' => '9998', 'cod_segmento' => $segmento, 'cod_vendedor' => '000001', 'estado' => 'SP',
        ]);
    }

    /**
     * O faturamento do PDF vai para a conta e para a âncora da empresa, por cima de um
     * valor que a web tinha achado. Rede sem cliente fica na chave da própria conta.
     * JSON sem a chave não apaga; sair do ranking apaga.
     */
    public function test_faturamento_da_abras_prevalece_e_sai_com_a_conta(): void
    {
        $this->filial('000100', '101', 'BOM PRECO', 'BOM PRECO COMERCIO DE ALIMENTOS LTDA', '11222333000181');
        DB::table('faturamento_mercado')->insert([
            'chave' => '11222333000181', 'valor' => 999, 'ano' => 2020, 'fonte' => 'web',
            'url' => 'https://exemplo.com/x', 'consultado_em' => now(),
        ]);
        $this->gravarRanking(2025, [
            [1, 'BOM PRECO', 'BOM PRECO COMÉRCIO DE ALIMENTOS LTDA.', 'SP', 1_500_000],
            [2, 'REDE SEM LOJA', 'REDE SEM LOJA LTDA', 'RJ', 80_000],
        ]);

        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();

        $conta = ContaEstrategica::where('nome', 'BOM PRECO')->sole();
        $this->assertSame(1_500_000, $conta->faturamento_abras);
        $this->assertSame(2025, $conta->faturamento_abras_ano);
        $this->assertSame(['000100'], $conta->vinculos->pluck('codigo')->all());
        $linha = DB::table('faturamento_mercado')->where('chave', '11222333000181')->first();
        $this->assertSame('abras', $linha->fonte);
        $this->assertSame(1_500_000, (int) $linha->valor);
        $this->assertSame(2025, (int) $linha->ano);
        $this->assertNull($linha->url);

        $sem = ContaEstrategica::where('nome', 'REDE SEM LOJA')->sole();
        $daRede = DB::table('faturamento_mercado')->where('chave', 'c'.$sem->id)->first();
        $this->assertSame(80_000, (int) $daRede->valor);
        $this->assertSame('abras', $daRede->fonte);

        $this->gravarRanking(2025, [
            [1, 'BOM PRECO', 'BOM PRECO COMÉRCIO DE ALIMENTOS LTDA.', 'SP', null],
            [2, 'REDE SEM LOJA', 'REDE SEM LOJA LTDA', 'RJ', null],
        ]);
        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();
        $this->assertSame(1_500_000, $conta->refresh()->faturamento_abras, 'JSON sem a chave não apaga');
        $this->assertSame('abras', DB::table('faturamento_mercado')->where('chave', '11222333000181')->value('fonte'));

        $this->gravarRanking(2025, [
            [1, 'OUTRA REDE', 'OUTRA REDE LTDA', 'SP', 10_000],
        ]);
        $this->artisan('diretor:importar-ranking-abras', ['--arquivo' => $this->arquivo])->assertSuccessful();
        $this->assertNull($conta->refresh()->faturamento_abras);
        $this->assertNull($conta->faturamento_abras_ano);
        $this->assertNull(DB::table('faturamento_mercado')->where('chave', '11222333000181')->first());
        $this->assertNull(DB::table('faturamento_mercado')->where('chave', 'c'.$sem->id)->first());
    }

    /** @param  list<array{0: int, 1: string, 2: string, 3: string, 4: ?int}>  $linhas */
    private function gravarRanking(int $ano, array $linhas): void
    {
        if ($this->arquivo) {
            @unlink(base_path($this->arquivo));
        }
        $this->arquivo = 'storage/framework/testing/ranking-abras-'.uniqid().'.json';
        @mkdir(dirname(base_path($this->arquivo)), 0777, true);

        file_put_contents(base_path($this->arquivo), json_encode([
            'ano_faturamento' => $ano,
            'ranking' => array_map(fn ($l) => array_filter([
                'posicao' => $l[0], 'nome' => $l[1], 'razao_social' => $l[2], 'uf' => $l[3],
                'faturamento' => $l[4],
            ], fn ($v) => $v !== null), $linhas),
        ]));
    }

    /** @param  list<array{0: int, 1: string, 2: string, 3: string}>  $linhas  posição, nome, razão social, UF */
    private function ranking(array $linhas): void
    {
        $this->arquivo = 'storage/framework/testing/ranking-abras-'.uniqid().'.json';
        @mkdir(dirname(base_path($this->arquivo)), 0777, true);

        file_put_contents(base_path($this->arquivo), json_encode(['ranking' => array_map(fn ($l) => [
            'posicao' => $l[0], 'posicao_2025' => null, 'nome' => $l[1], 'razao_social' => $l[2], 'uf' => $l[3],
            'razoes' => $l[4] ?? [],
        ], $linhas)]));
    }
}
