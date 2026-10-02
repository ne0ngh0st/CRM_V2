<?php

namespace Tests\Feature\VisaoDiretor;

use App\Console\Commands\ImportLeadsTotvs;
use App\Models\ContaEstrategica;
use App\Models\Lead;
use App\Models\Segmento;
use App\Models\User;
use App\Services\Receita\SituacaoCadastral;
use App\Services\VisaoDiretor\ContaDoLead;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\UsaDiretorioDeRelatorios;
use Tests\TestCase;

/**
 * Leads da prospecção (CSVs da pasta Leads/) entrando na Maiores por Segmento — a
 * "mescla" do que a diretoria já tinha com o que vem do time de prospecção (2026-10-02).
 */
class LeadsDaProspeccaoTest extends TestCase
{
    use RefreshDatabase;
    use UsaDiretorioDeRelatorios;

    private Segmento $drogarias;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRelatorios();
        Http::preventStrayRequests();
        $this->seed(RoleSeeder::class);

        $this->drogarias = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();

        parent::tearDown();
    }

    public function test_lead_novo_nasce_com_a_origem_prospeccao(): void
    {
        $this->importar([$this->linha('11111111000111', 'EMPRESA A', '101')]);

        $this->assertSame(Lead::ORIGEM_PROSPECCAO, Lead::sole()->origem);
    }

    public function test_rede_que_bate_com_conta_existente_liga_sem_criar_outra(): void
    {
        $conta = $this->conta('Drogaria São Paulo');

        // Caixa, acento e espaço diferentes: é a mesma rede.
        $this->importar([$this->linha('11111111000111', 'DROGARIA SAO PAULO S/A', '109', rede: 'drogaria sao  paulo')]);

        $this->assertSame(1, ContaEstrategica::count());
        $lead = Lead::sole();
        $this->assertSame($conta->id, $lead->conta_estrategica_id);
        $this->assertSame(Lead::CONTA_CONFIRMADA, $lead->conta_vinculo);
    }

    public function test_rede_que_nao_existe_vira_conta_nova_uma_so_para_varios_cnpjs(): void
    {
        $this->conta('RAIA DROGASIL', ordem: 7);

        $this->importar([
            $this->linha('11111111000111', 'FARMA NOVA LTDA', '109', rede: 'FARMA NOVA', filiais: '42'),
            $this->linha('22222222000122', 'FARMA NOVA FILIAL', '109', rede: 'Farma Nova'),
        ]);

        $nova = ContaEstrategica::where('nome', 'FARMA NOVA')->sole();
        $this->assertSame($this->drogarias->id, $nova->segmento_id);
        $this->assertSame(42, $nova->filiais_mercado);
        $this->assertSame('SP', $nova->uf);
        $this->assertSame(8, $nova->ordem, 'conta nova entra no fim da aba');
        $this->assertSame(2, $nova->leads()->count());
    }

    public function test_filiais_do_csv_nao_sobrescreve_numero_ja_digitado(): void
    {
        $conta = $this->conta('RAIA DROGASIL', filiais: 2390);

        $this->importar([$this->linha('11111111000111', 'RAIA', '109', rede: 'RAIA DROGASIL', filiais: '10')]);

        $this->assertSame(2390, $conta->fresh()->filiais_mercado);
    }

    public function test_sem_rede_sugere_pelo_nome_e_so_conta_depois_de_confirmar(): void
    {
        $conta = $this->conta('RAIA DROGASIL');

        $this->importar([$this->linha('11111111000111', 'RAIA DROGASIL S/A', '109')]);

        $lead = Lead::sole();
        $this->assertSame(Lead::CONTA_SUGERIDA, $lead->conta_vinculo);
        $this->assertSame([], $this->linhaDa($conta)['leads'], 'sugestão não aparece na coluna');
        $this->assertSame([$lead->id], array_column(app(ContaDoLead::class)->sugestoesPendentes(), 'leadId'));

        $this->actingAs($this->diretor())
            ->post(route('visao-diretor.maiores.sugestao.confirmar', $lead))
            ->assertRedirect();

        $this->assertSame(Lead::CONTA_CONFIRMADA, $lead->fresh()->conta_vinculo);
        $this->assertSame([$lead->id], array_column($this->linhaDa($conta)['leads'], 'id'));
        $this->assertSame([], app(ContaDoLead::class)->sugestoesPendentes());
    }

    public function test_sugestao_recusada_nao_volta_no_proximo_import(): void
    {
        $this->conta('RAIA DROGASIL');
        $linhas = [$this->linha('11111111000111', 'RAIA DROGASIL S/A', '109')];
        $this->importar($linhas);

        $this->actingAs($this->diretor())
            ->post(route('visao-diretor.maiores.sugestao.recusar', Lead::sole()))
            ->assertRedirect();

        $this->importar($linhas);

        $this->assertSame(Lead::CONTA_RECUSADA, Lead::sole()->conta_vinculo);
        $this->assertSame([], app(ContaDoLead::class)->sugestoesPendentes());
    }

    public function test_sem_rede_e_sem_nome_parecido_nao_vira_conta(): void
    {
        $this->conta('RAIA DROGASIL');

        $this->importar([$this->linha('11111111000111', 'BOTICA DO BAIRRO ME', '109')]);

        $this->assertSame(1, ContaEstrategica::count());
        $this->assertNull(Lead::sole()->conta_vinculo);
    }

    public function test_segmento_fora_das_abas_fica_so_em_leads(): void
    {
        $this->importar([$this->linha('11111111000111', 'MERCADO X', '101', rede: 'MERCADO X')]);

        $this->assertSame(0, ContaEstrategica::count());
        $this->assertNull(Lead::sole()->conta_estrategica_id);
    }

    public function test_numero_da_coluna_bate_com_a_lista_de_leads_que_abre(): void
    {
        $conta = $this->conta('FARMA NOVA');
        $this->importar([
            $this->linha('11111111000111', 'FARMA NOVA 1', '109', rede: 'FARMA NOVA'),
            $this->linha('22222222000122', 'FARMA NOVA 2', '109', rede: 'FARMA NOVA'),
            $this->linha('33333333000133', 'OUTRA COISA', '109'),
        ]);
        // Excluído sai dos dois lados.
        Lead::where('razao_social', 'FARMA NOVA 2')->update(['status' => 'excluido']);

        $naColuna = count($this->linhaDa($conta)['leads']);

        $this->actingAs($this->diretor())
            ->get(route('leads.index', ['conta_alvo' => $conta->id]))
            ->assertInertia(fn (Assert $p) => $p
                ->where('leads.total', $naColuna)
                ->where('filtros.contaAlvo.nome', 'FARMA NOVA'));

        $this->assertSame(1, $naColuna);
    }

    public function test_recorte_por_conta_e_ignorado_fora_da_visao_diretor(): void
    {
        $conta = $this->conta('FARMA NOVA');
        $this->importar([
            $this->linha('11111111000111', 'FARMA NOVA 1', '109', rede: 'FARMA NOVA'),
            $this->linha('33333333000133', 'OUTRA COISA', '109'),
        ]);

        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        $vendedor->vendedorPerfil()->create(['cod_vendedor' => '010617']);

        $this->actingAs($vendedor)
            ->get(route('leads.index', ['conta_alvo' => $conta->id]))
            ->assertInertia(fn (Assert $p) => $p->where('leads.total', 2)->where('filtros.contaAlvo', null));
    }

    public function test_lead_da_base_antiga_e_adotado_e_passa_a_ser_prospeccao(): void
    {
        $id = DB::table('leads')->insertGetId([
            'origem' => Lead::ORIGEM_SISTEMA, 'nome' => 'A', 'razao_social' => 'A',
            'cnpj' => '11.111.111/0001-11', 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->importar([$this->linha('11111111000111', 'EMPRESA A', '101')]);

        $this->assertSame([$id], Lead::pluck('id')->all());
        $this->assertSame(Lead::ORIGEM_PROSPECCAO, Lead::sole()->origem);
    }

    public function test_cnpj_que_ja_e_lead_manual_nao_duplica_nem_e_tocado(): void
    {
        DB::table('leads')->insert([
            'origem' => Lead::ORIGEM_MANUAL, 'nome' => 'MEU LEAD', 'razao_social' => 'MEU LEAD',
            'cnpj' => '11.111.111/0001-11', 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->importar([$this->linha('11111111000111', 'OUTRO NOME', '101')]);

        $this->assertSame(1, Lead::count());
        $this->assertSame('MEU LEAD', Lead::sole()->razao_social);
        $this->assertSame(Lead::ORIGEM_MANUAL, Lead::sole()->origem);
    }

    public function test_receita_tambem_tira_lead_da_prospeccao(): void
    {
        $this->importar([$this->linha('11111111000111', 'EMPRESA A', '101')]);
        DB::table('cnpj_situacoes')->where('cnpj', '11111111000111')->update(['situacao' => 'BAIXADA']);

        app(SituacaoCadastral::class)->sincronizarLeads();

        $this->assertSame('excluido', Lead::sole()->status);
    }

    public function test_dry_run_nao_cria_conta_nem_liga_lead(): void
    {
        $this->conta('RAIA DROGASIL');
        $this->escrever([$this->linha('11111111000111', 'FARMA NOVA', '109', rede: 'FARMA NOVA')]);

        $this->artisan('totvs:import-leads', ['--dry-run' => true])
            ->expectsOutputToContain('contas novas criadas pela coluna `rede`: 1')
            ->assertSuccessful();

        $this->assertSame(1, ContaEstrategica::count());
        $this->assertSame(0, Lead::count());
    }

    // ---------------------------------------------------------------------------------

    /** @param list<array<string, string>> $linhas */
    private function importar(array $linhas): void
    {
        $this->escrever($linhas);
        $this->artisan('totvs:import-leads')->assertSuccessful();
    }

    /** @param list<array<string, string>> $linhas */
    private function escrever(array $linhas): void
    {
        foreach ($linhas as $l) {
            DB::table('cnpj_situacoes')->updateOrInsert(['cnpj' => $l['cnpj']], [
                'situacao' => 'ATIVA', 'fonte' => SituacaoCadastral::FONTE_BASE,
                'referencia' => '2026-09', 'atualizado_em' => now(),
            ]);
        }

        $saida = [implode(';', ImportLeadsTotvs::COLUNAS)];
        foreach ($linhas as $l) {
            $saida[] = implode(';', array_map(fn ($c) => $l[$c] ?? '', ImportLeadsTotvs::COLUNAS));
        }

        @mkdir($this->diretorioTotvs.'/Leads', 0777, true);
        file_put_contents($this->diretorioTotvs.'/Leads/Leads - teste.csv', implode("\n", $saida)."\n");
    }

    private function linha(string $cnpj, string $razao, string $segmento, string $rede = '', string $filiais = ''): array
    {
        return [
            'cnpj' => $cnpj, 'razao_social' => $razao, 'segmento' => $segmento,
            'cod_vendedor' => '010617', 'uf' => 'SP', 'rede' => $rede, 'filiais_rede' => $filiais,
        ];
    }

    private function conta(string $nome, int $ordem = 1, ?int $filiais = null): ContaEstrategica
    {
        return ContaEstrategica::create([
            'segmento_id' => $this->drogarias->id, 'nome' => $nome, 'uf' => 'SP',
            'filiais_mercado' => $filiais, 'ordem' => $ordem,
        ]);
    }

    private function linhaDa(ContaEstrategica $conta): array
    {
        return app(MaioresPorSegmentoResolver::class)->linhas()->firstWhere('id', $conta->id);
    }

    private function diretor(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('diretor');

        return $u;
    }
}
