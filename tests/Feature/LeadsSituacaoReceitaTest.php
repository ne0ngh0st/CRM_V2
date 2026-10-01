<?php

namespace Tests\Feature;

use App\Services\Receita\SituacaoCadastral;
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

    private const ATIVA = '11111111000111';

    private const INAPTA = '22222222000122';

    private const BAIXADA = '33333333000133';

    private const DESCONHECIDA = '44444444000144';

    private const INEXISTENTE = '55555555000155';

    private array $zips = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRelatorios();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();
        array_map('unlink', $this->zips);

        parent::tearDown();
    }

    public function test_import_so_deixa_entrar_lead_com_cnpj_ativo(): void
    {
        $this->situacao(self::ATIVA, 'ATIVA');
        $this->situacao(self::INAPTA, 'INAPTA');
        $this->situacao(self::BAIXADA, 'BAIXADA');
        $this->situacao(self::INEXISTENTE, SituacaoCadastral::INEXISTENTE);
        $this->escreverBase([self::ATIVA, self::INAPTA, self::BAIXADA, self::INEXISTENTE, self::DESCONHECIDA]);

        $this->artisan('totvs:import-leads')->assertSuccessful();

        $this->assertSame([self::ATIVA], $this->cnpjsNoCrm());
    }

    public function test_lead_novo_de_situacao_desconhecida_entra_depois_da_carga(): void
    {
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

        app(SituacaoCadastral::class)->excluirLeadsNaoAtivos();

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

    // ---------------------------------------------------------------------------------

    private function situacao(string $cnpj, string $situacao): void
    {
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => $cnpj, 'situacao' => $situacao, 'fonte' => SituacaoCadastral::FONTE_BASE,
            'referencia' => '2026-09', 'atualizado_em' => now(),
        ]);
    }

    private function lead(string $cnpj, string $origem = 'sistema'): int
    {
        return DB::table('leads')->insertGetId([
            'origem' => $origem, 'nome' => 'EMPRESA '.$cnpj, 'razao_social' => 'EMPRESA '.$cnpj,
            'cnpj' => $this->mascara($cnpj), 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function cnpjsNoCrm(): array
    {
        return DB::table('leads')->where('status', '!=', 'excluido')->orderBy('cnpj')->pluck('cnpj')
            ->map(fn ($c) => preg_replace('/\D/', '', $c))->all();
    }

    /** @param list<string> $cnpjs */
    private function escreverBase(array $cnpjs): void
    {
        $colunas = [
            'cnpj', 'RAZAO SOCIAL', 'NOME FANTASIA', 'nome final', 'E-mail',
            'Telefone Principal (FINAL)', 'endereçoCNPJJA', 'CIDADE (arrumada)', 'CIDADE',
            'UF', 'Codigo Vendedor', 'projeção R$ (mês)', 'MARCAÇÃO PROSPECT',
        ];
        $linhas = [implode(';', $colunas)];

        foreach ($cnpjs as $cnpj) {
            $linhas[] = implode(';', [
                $this->mascara($cnpj), 'EMPRESA '.$cnpj, '', 'EMPRESA '.$cnpj, '', '', '', 'SAO PAULO', '',
                'SP', '010617', '', 'SAI PROSPECT',
            ]);
        }

        file_put_contents($this->diretorioTotvs.'/CSV/base_marco - SQL.csv', implode("\n", $linhas)."\n");
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
