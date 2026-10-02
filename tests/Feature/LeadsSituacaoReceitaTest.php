<?php

namespace Tests\Feature;

use App\Console\Commands\ImportLeadsTotvs;
use App\Models\User;
use App\Services\Receita\SituacaoCadastral;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsaDiretorioDeRelatorios;
use Tests\TestCase;
use ZipArchive;

/**
 * Lead da base de prospecção com CNPJ que não está ATIVO na Receita não entra no CRM
 * (decisão do Tony, 2026-10-01): inapta, suspensa, baixada, nula e inexistente.
 */
class LeadsSituacaoReceitaTest extends TestCase
{
    use RefreshDatabase;
    use UsaDiretorioDeRelatorios;

    private const ATIVA = '11111111000191';

    private const INAPTA = '22222222000191';

    private const BAIXADA = '33333333000191';

    private const DESCONHECIDA = '44444444000191';

    private const INEXISTENTE = '55555555000191';

    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRelatorios();
        Http::preventStrayRequests();
        DB::table('segmentos')->insert([
            ['codigo' => '101', 'nome' => 'SUPERMERCADISTA'],
            ['codigo' => '109', 'nome' => 'DROGARIAS'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();
        array_map('unlink', $this->zips);

        parent::tearDown();
    }

    public function test_import_so_deixa_entrar_lead_com_cnpj_ativo(): void
    {
        // Receita fora do ar na consulta na hora: o desconhecido continua segurado.
        Http::fake(['*' => Http::response('fora', 503)]);
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->situacao(self::INAPTA, 'INAPTA');
        $this->situacao(self::BAIXADA, 'BAIXADA');
        $this->situacao(self::INEXISTENTE, SituacaoCadastral::INEXISTENTE);
        $this->escreverBase([self::ATIVA, self::INAPTA, self::BAIXADA, self::INEXISTENTE, self::DESCONHECIDA]);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame([self::ATIVA], $this->cnpjsNoCrm());
    }

    public function test_cnpj_que_ja_e_cliente_nao_entra_como_lead(): void
    {
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->situacao(self::BAIXADA, 'ATIVA');
        DB::table('clientes')->insert([
            'cod_cliente' => '000123', 'loja' => '02', 'razao_social' => 'JA CLIENTE',
            'cnpj' => $this->mascara(self::BAIXADA), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->escreverBase([self::ATIVA, self::BAIXADA]);

        $this->artisan('totvs:import-leads')
            ->expectsOutputToContain('barrados (CNPJ já é cliente na carteira): 1')
            ->assertSuccessful();

        $this->assertSame([self::ATIVA], $this->cnpjsNoCrm());
    }

    public function test_lead_que_virou_cliente_nao_e_atualizado_pelo_csv(): void
    {
        $this->situacao(self::BAIXADA, 'ATIVA');
        $id = $this->lead(self::BAIXADA);
        DB::table('clientes')->insert([
            'cod_cliente' => '000123', 'loja' => '01', 'razao_social' => 'VIROU CLIENTE',
            'cnpj' => $this->mascara(self::BAIXADA), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->escreverBase([self::BAIXADA]);

        $this->artisan('totvs:import-leads')
            ->expectsOutputToContain('leads já no CRM cujo CNPJ hoje é cliente (não mexidos): 1')
            ->assertSuccessful();

        $this->assertSame('EMPRESA '.self::BAIXADA, DB::table('leads')->where('id', $id)->value('razao_social'));
        $this->assertSame('sistema', DB::table('leads')->where('id', $id)->value('origem'));
    }

    public function test_cnpj_sem_o_zero_a_esquerda_e_reconhecido(): void
    {
        $cnpj = '06666666000160';
        $this->situacao($cnpj, 'ATIVA');
        $this->escreverBase([$cnpj], semMascara: true);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame([$cnpj], $this->cnpjsNoCrm());
    }

    public function test_le_todos_os_csvs_da_pasta_e_nao_duplica_cnpj_entre_eles(): void
    {
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->situacao(self::BAIXADA, 'ATIVA');
        $this->escreverBase([self::ATIVA], arquivo: 'Leads - Supermercados.csv');
        $this->escreverBase([self::ATIVA, self::BAIXADA], arquivo: 'Leads - Drogarias.csv');

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame([self::ATIVA, self::BAIXADA], $this->cnpjsNoCrm());
    }

    public function test_segmento_aceita_codigo_ou_nome_e_grava_o_nome_oficial(): void
    {
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->situacao(self::BAIXADA, 'ATIVA');
        $this->escreverLinhas([
            ['cnpj' => self::ATIVA, 'razao_social' => 'A', 'segmento' => '109'],
            ['cnpj' => self::BAIXADA, 'razao_social' => 'B', 'segmento' => 'supermercadista'],
        ]);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertEquals(['DROGARIAS', 'SUPERMERCADISTA'],
            DB::table('leads')->orderBy('segmento')->pluck('segmento')->all());
    }

    public function test_linha_fora_do_padrao_e_recusada_com_arquivo_e_linha(): void
    {
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->escreverLinhas([
            ['cnpj' => self::ATIVA, 'razao_social' => 'OK', 'segmento' => '101'],
            ['cnpj' => '123', 'razao_social' => 'CNPJ CURTO', 'segmento' => '101'],
            ['cnpj' => self::INAPTA, 'razao_social' => 'X', 'segmento' => 'PADARIA'],
        ], 'Leads - x.csv');

        $this->artisan('totvs:import-leads')
            ->expectsOutputToContain('Leads - x.csv:3 — CNPJ inválido')
            ->expectsOutputToContain("Leads - x.csv:4 — segmento desconhecido ('PADARIA')")
            ->assertSuccessful();

        $this->assertSame([self::ATIVA], $this->cnpjsNoCrm());
    }

    public function test_cabecalho_diferente_do_template_para_o_import(): void
    {
        $this->escreverLinhas([['cnpj' => self::ATIVA]], colunas: ['cnpj', 'RAZAO SOCIAL']);

        $this->expectException(\RuntimeException::class);
        $this->artisan('totvs:import-leads');
    }

    public function test_sem_vendedor_ou_zerado_nasce_sem_dono_e_em_lead_existente_nao_apaga_o_dono(): void
    {
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->situacao(self::INAPTA, 'ATIVA');
        $existente = $this->lead(self::BAIXADA);
        DB::table('leads')->where('id', $existente)->update(['cod_vendedor' => '000197']);
        $this->escreverLinhas([
            ['cnpj' => self::ATIVA, 'razao_social' => 'NOVO VAZIO', 'segmento' => '101'],
            ['cnpj' => self::INAPTA, 'razao_social' => 'NOVO ZERADO', 'segmento' => '101', 'cod_vendedor' => '0'],
            ['cnpj' => self::BAIXADA, 'razao_social' => 'EXISTENTE', 'segmento' => '101'],
        ]);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertNull(DB::table('leads')->where('razao_social', 'NOVO VAZIO')->value('cod_vendedor'));
        $this->assertNull(DB::table('leads')->where('razao_social', 'NOVO ZERADO')->value('cod_vendedor'));
        $this->assertSame('000197', DB::table('leads')->where('id', $existente)->value('cod_vendedor'));
        $this->assertSame('EXISTENTE', DB::table('leads')->where('id', $existente)->value('razao_social'));
    }

    public function test_atribuir_dono_e_preencher_o_codigo_e_importar_de_novo(): void
    {
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->escreverLinhas([['cnpj' => self::ATIVA, 'razao_social' => 'SEM DONO', 'segmento' => '101']]);
        $this->artisan('totvs:import-leads')->assertSuccessful();
        $id = DB::table('leads')->value('id');

        $this->escreverLinhas([['cnpj' => self::ATIVA, 'razao_social' => 'SEM DONO', 'segmento' => '101', 'cod_vendedor' => '10755']]);
        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame([$id], DB::table('leads')->pluck('id')->all(), 'é o mesmo lead, não um segundo');
        $this->assertSame('010755', DB::table('leads')->where('id', $id)->value('cod_vendedor'));
    }

    public function test_template_do_repositorio_tem_o_cabecalho_do_import(): void
    {
        $cabecalho = trim(preg_replace('/^\xEF\xBB\xBF/', '', strtok(file_get_contents(base_path('docs/leads-template.csv')), "\n")));

        $this->assertSame(ImportLeadsTotvs::COLUNAS, explode(';', $cabecalho));
    }

    public function test_desconhecido_e_consultado_na_hora_e_entra_se_ativo(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response([
            'cnpj' => self::DESCONHECIDA, 'razao_social' => 'EMPRESA NOVA LTDA',
            'descricao_situacao_cadastral' => 'ATIVA', 'data_situacao_cadastral' => '2020-01-01',
        ])]);
        $this->escreverBase([self::DESCONHECIDA]);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame([self::DESCONHECIDA], $this->cnpjsNoCrm());
        $this->assertSame('ATIVA', DB::table('cnpj_situacoes')->where('cnpj', self::DESCONHECIDA)->value('situacao'));
        $this->assertSame(1, \App\Models\LeadImportacao::sole()->resultado['consultadosNaHora']);
    }

    public function test_cnpj_que_nenhuma_fonte_conhece_vira_inexistente_e_nao_entra(): void
    {
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);
        $this->escreverBase([self::DESCONHECIDA]);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame([], $this->cnpjsNoCrm());
        $this->assertSame(SituacaoCadastral::INEXISTENTE, DB::table('cnpj_situacoes')->where('cnpj', self::DESCONHECIDA)->value('situacao'));
        $this->assertSame(0, \App\Models\LeadImportacao::sole()->resultado['segurados']);
    }

    public function test_consulta_na_hora_respeita_o_teto_e_para_quando_a_receita_cai(): void
    {
        Http::fake(['*' => Http::response('fora', 503)]);
        $cnpjs = array_map(fn ($i) => sprintf('%08d000199', $i), range(1, 20));

        $teto = app(SituacaoCadastral::class)->consultarDesconhecidos($cnpjs, maximo: 3);
        $this->assertSame(3, $teto['consultados']);
        $this->assertSame(17, $teto['restantes']);

        // Sem teto apertado: para depois de 5 falhas seguidas, sem martelar as 20.
        $queda = app(SituacaoCadastral::class)->consultarDesconhecidos($cnpjs);
        $this->assertSame(5, $queda['consultados']);
    }

    public function test_lead_novo_de_situacao_desconhecida_entra_depois_da_carga(): void
    {
        Http::fake(['*' => Http::response('fora', 503)]);
        $this->escreverBase([self::DESCONHECIDA]);

        $this->artisan('totvs:import-leads')->assertSuccessful();
        $this->assertSame([], $this->cnpjsNoCrm());

        $this->situacao(self::DESCONHECIDA, 'ATIVA');
        $this->artisan('totvs:import-leads')->assertSuccessful();
        $this->assertSame([self::DESCONHECIDA], $this->cnpjsNoCrm());
    }

    public function test_lead_que_ja_estava_no_crm_vira_excluido_sem_perder_o_id(): void
    {
        $id = $this->lead(self::INAPTA);
        DB::table('observacoes')->insert([
            'lead_id' => $id, 'cnpj' => $this->mascara(self::INAPTA), 'mensagem' => 'ligou ontem', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->situacao(self::INAPTA, 'INAPTA');
        $this->escreverBase([self::INAPTA]);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame('excluido', DB::table('leads')->where('id', $id)->value('status'));
        $this->assertSame($id, DB::table('observacoes')->value('lead_id'));
    }

    public function test_lead_do_site_e_manual_nao_sao_tocados(): void
    {
        $site = $this->lead(self::INAPTA, 'wordpress');
        $manual = $this->lead(self::BAIXADA, 'manual');
        $this->situacao(self::INAPTA, 'INAPTA');
        $this->situacao(self::BAIXADA, 'BAIXADA');

        app(SituacaoCadastral::class)->sincronizarLeads();

        $this->assertSame('ativo', DB::table('leads')->where('id', $site)->value('status'));
        $this->assertSame('ativo', DB::table('leads')->where('id', $manual)->value('status'));
    }

    public function test_carga_da_receita_le_situacao_e_marca_o_que_nao_existe(): void
    {
        // Um lead já no CRM e um CNPJ que só está na base de prospecção ainda não importada.
        $this->lead(self::INAPTA);
        $this->escreverBase([self::ATIVA, self::INEXISTENTE]);

        $zip = $this->zipDaReceita([
            $this->linhaReceita(self::ATIVA, '02', '20240718'),
            $this->linhaReceita(self::INAPTA, '04', '20230102'),
            $this->linhaReceita('99999999000199', '08', '20200101'), // fora do interesse
        ]);

        $this->artisan('receita:importar-situacoes', ['--arquivo' => [$zip], '--referencia' => '2026-09'])
            ->assertSuccessful();

        $this->assertEquals([
            self::ATIVA => 'ATIVA',
            self::INAPTA => 'INAPTA',
            self::INEXISTENTE => SituacaoCadastral::INEXISTENTE,
        ], DB::table('cnpj_situacoes')->orderBy('cnpj')->pluck('situacao', 'cnpj')->all());

        $this->assertSame('2023-01-02', DB::table('cnpj_situacoes')->where('cnpj', self::INAPTA)->value('data_situacao'));
        $this->assertSame('2026-09', DB::table('cnpj_situacoes')->where('cnpj', self::ATIVA)->value('referencia'));
        // O lead que já estava no CRM saiu na mesma rodada.
        $this->assertSame('excluido', DB::table('leads')->value('status'));
    }

    public function test_carga_nao_baixa_de_novo_mes_ja_carregado(): void
    {
        Http::fake(['arquivos.receitafederal.gov.br/*' => Http::response(
            '<d:href>/public.php/webdav/2026-08/</d:href><d:href>/public.php/webdav/2026-09/</d:href>'
        )]);
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => self::ATIVA, 'situacao' => 'ATIVA', 'fonte' => SituacaoCadastral::FONTE_BASE,
            'referencia' => '2026-09', 'atualizado_em' => now(),
        ]);

        $this->artisan('receita:importar-situacoes')
            ->expectsOutputToContain('2026-09 já carregada')
            ->assertSuccessful();

        Http::assertSentCount(1); // só a listagem, nenhum zip
    }

    public function test_dry_run_nao_grava(): void
    {
        $this->lead(self::INAPTA);
        $zip = $this->zipDaReceita([$this->linhaReceita(self::INAPTA, '04', '20230102')]);

        $this->artisan('receita:importar-situacoes', ['--arquivo' => [$zip], '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('cnpj_situacoes')->count());
        $this->assertSame('ativo', DB::table('leads')->value('status'));
    }

    public function test_exclusao_pela_receita_grava_o_carimbo(): void
    {
        $id = $this->lead(self::BAIXADA);
        $this->situacao(self::BAIXADA, 'BAIXADA');

        app(SituacaoCadastral::class)->sincronizarLeads();

        $this->assertNotNull(DB::table('leads')->where('id', $id)->value('excluido_pela_receita_em'));
    }

    public function test_lead_excluido_pela_receita_volta_quando_o_cnpj_regulariza(): void
    {
        $id = $this->lead(self::INAPTA);
        $this->situacao(self::INAPTA, 'INAPTA');
        app(SituacaoCadastral::class)->sincronizarLeads();
        $this->assertSame('excluido', DB::table('leads')->where('id', $id)->value('status'));

        DB::table('cnpj_situacoes')->where('cnpj', self::INAPTA)->update(['situacao' => 'ATIVA']);
        $resultado = app(SituacaoCadastral::class)->sincronizarLeads();

        $this->assertSame(1, $resultado['reativados']);
        $this->assertSame('ativo', DB::table('leads')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('leads')->where('id', $id)->value('excluido_pela_receita_em'));
    }

    public function test_lead_excluido_a_mao_nao_volta(): void
    {
        $id = $this->lead(self::ATIVA, 'sistema', 'excluido');
        $this->situacao(self::ATIVA, 'ATIVA');

        app(SituacaoCadastral::class)->sincronizarLeads();

        $this->assertSame('excluido', DB::table('leads')->where('id', $id)->value('status'));
    }

    public function test_lead_da_receita_com_situacao_desconhecida_continua_fora(): void
    {
        $id = $this->lead(self::DESCONHECIDA, 'sistema', 'excluido', carimbado: true);

        app(SituacaoCadastral::class)->sincronizarLeads();

        $this->assertSame('excluido', DB::table('leads')->where('id', $id)->value('status'));
    }

    public function test_metrica_sem_carga_da_999(): void
    {
        $this->artisan('metricas:publicar', ['--mostrar' => true])
            ->expectsOutputToContain(sprintf('  %-26s %s', 'ReceitaBaseIdadeDias', 999))
            ->assertSuccessful();
    }

    public function test_idade_da_base_conta_dias_desde_a_carga(): void
    {
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => self::ATIVA, 'situacao' => 'ATIVA', 'fonte' => SituacaoCadastral::FONTE_BASE,
            'referencia' => '2026-08', 'atualizado_em' => now()->subDays(50),
        ]);
        // Cartão consultado ontem NÃO conta como carga da base.
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => self::INAPTA, 'situacao' => 'INAPTA', 'fonte' => 'brasilapi',
            'referencia' => null, 'atualizado_em' => now()->subDay(),
        ]);

        $idade = app(SituacaoCadastral::class)->idadeDaBase();

        $this->assertSame('2026-08', $idade['referencia']);
        $this->assertSame(50, $idade['dias']);
        $this->assertSame('warn', $idade['tom']);
    }

    public function test_atualizacoes_mostra_a_base_da_receita(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->situacao(self::BAIXADA, 'BAIXADA');

        $receita = $this->actingAs($admin)->get(route('atualizacoes.index'))
            ->assertOk()->viewData('page')['props']['receita'];

        $this->assertSame('2026-09', $receita['referencia']);
        $this->assertSame('ok', $receita['tom']);
        $this->assertEquals(['ATIVA' => 1, 'BAIXADA' => 1], $receita['porSituacao']);
    }

    // ---------------------------------------------------------------------------------

    private function situacao(string $cnpj, string $situacao): void
    {
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => $cnpj, 'situacao' => $situacao, 'fonte' => SituacaoCadastral::FONTE_BASE,
            'referencia' => '2026-09', 'atualizado_em' => now(),
        ]);
    }

    private function lead(string $cnpj, string $origem = 'sistema', string $status = 'ativo', bool $carimbado = false): int
    {
        return DB::table('leads')->insertGetId([
            'origem' => $origem, 'nome' => 'EMPRESA '.$cnpj, 'razao_social' => 'EMPRESA '.$cnpj,
            'cnpj' => $this->mascara($cnpj), 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            'excluido_pela_receita_em' => $carimbado ? now() : null,
        ]);
    }

    /** @return list<string> */
    private function cnpjsNoCrm(): array
    {
        return DB::table('leads')->where('status', '!=', 'excluido')->orderBy('cnpj')->pluck('cnpj')
            ->map(fn ($c) => preg_replace('/\D/', '', $c))->all();
    }

    /** @param list<string> $cnpjs */
    private function escreverBase(array $cnpjs, bool $semMascara = false, string $arquivo = 'Leads - teste.csv'): void
    {
        $this->escreverLinhas(array_map(fn ($cnpj) => [
            // Sem máscara: como o Excel grava CNPJ salvo como número, sem o zero da frente.
            'cnpj' => $semMascara ? ltrim($cnpj, '0') : $this->mascara($cnpj),
            'razao_social' => 'EMPRESA '.$cnpj,
            'segmento' => 'SUPERMERCADISTA',
            'cod_vendedor' => '010617',
            'cidade' => 'SAO PAULO',
            'uf' => 'SP',
        ], $cnpjs), $arquivo);
    }

    /**
     * Escreve um CSV no layout do template; coluna ausente sai em branco.
     *
     * @param  list<array<string, string>>  $linhas
     */
    private function escreverLinhas(array $linhas, string $arquivo = 'Leads - teste.csv', ?array $colunas = null): void
    {
        $colunas ??= ImportLeadsTotvs::COLUNAS;
        $saida = [implode(';', $colunas)];

        foreach ($linhas as $linha) {
            $saida[] = implode(';', array_map(fn ($c) => $linha[$c] ?? '', $colunas));
        }

        @mkdir($this->diretorioTotvs.'/Leads', 0777, true);
        file_put_contents($this->diretorioTotvs.'/Leads/'.$arquivo, implode("\n", $saida)."\n");
    }

    private function linhaReceita(string $cnpj, string $codigo, string $data): string
    {
        return sprintf('"%s";"%s";"%s";"1";"NOME";"%s";"%s";"00";"";"";"20200101";"4761003"',
            substr($cnpj, 0, 8), substr($cnpj, 8, 4), substr($cnpj, 12, 2), $codigo, $data);
    }

    /** @param list<string> $linhas */
    private function zipDaReceita(array $linhas): string
    {
        $caminho = sys_get_temp_dir().'/receita-teste-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::CREATE);
        $zip->addFromString('K3241.K03200Y0.D60912.ESTABELE', implode("\n", $linhas)."\n");
        $zip->close();

        return $this->zips[] = $caminho;
    }

    private function mascara(string $cnpj): string
    {
        return vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj));
    }
}
