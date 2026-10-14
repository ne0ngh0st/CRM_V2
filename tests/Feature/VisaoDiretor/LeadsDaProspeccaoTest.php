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
        Segmento::create(['codigo' => '103', 'nome' => 'ORGAO PUBLICO']);
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();

        parent::tearDown();
    }

    public function test_lead_novo_nasce_com_a_origem_prospeccao(): void
    {
        $this->importar([$this->linha('11111111000191', 'EMPRESA A', '101')]);

        $this->assertSame(Lead::ORIGEM_PROSPECCAO, Lead::sole()->origem);
    }

    public function test_rede_que_bate_com_duas_contas_nao_liga_nem_cria_outra(): void
    {
        $this->conta('DROGARIA SÃO PAULO', ordem: 1);
        $this->conta('FARMACIAS SÃO PAULO', ordem: 2);

        $this->importar([$this->linha('11111111000191', 'DROGARIA SAO PAULO S/A', '109', rede: 'Drogarias São Paulo')]);

        $this->assertSame(2, ContaEstrategica::count());
        $this->assertNull(Lead::sole()->conta_estrategica_id);
    }

    public function test_filiais_do_csv_nao_sobrescreve_numero_ja_digitado(): void
    {
        $conta = $this->conta('RAIA DROGASIL', filiais: 2390);

        $this->importar([$this->linha('11111111000191', 'RAIA', '109', rede: 'RAIA DROGASIL', filiais: '10')]);

        $this->assertSame(2390, $conta->fresh()->filiais_mercado);
    }

    public function test_sugestao_ambigua_nao_vira_conta_pela_marca(): void
    {
        $this->conta('DROGARIA SÃO PAULO', ordem: 1);
        $this->conta('FARMACIAS SÃO PAULO', ordem: 2);

        $this->importar([$this->linha('11111111000191', 'DROGARIA SAO PAULO S/A', '109', fantasia: 'São Paulo')]);

        $this->assertSame(2, ContaEstrategica::count());
        $this->assertNull(Lead::sole()->conta_vinculo);
    }

    public function test_base_antiga_nao_vira_conta_pela_marca(): void
    {
        Lead::create([
            'nome' => 'BOTICA DO BAIRRO', 'razao_social' => 'BOTICA DO BAIRRO ME', 'cnpj' => '11.111.111/0001-91',
            'segmento' => 'DROGARIAS', 'origem' => Lead::ORIGEM_SISTEMA, 'status' => 'ativo', 'cod_vendedor' => '010617',
        ]);

        app(ContaDoLead::class)->sugerirParaLeadsSemConta();

        $this->assertSame(0, ContaEstrategica::count());
    }

    public function test_rede_de_grupo_com_razao_que_casa_com_duas_contas_nao_cria_nada(): void
    {
        $this->conta('DROGARIA SÃO PAULO', ordem: 1);
        $this->conta('FARMACIAS SÃO PAULO', ordem: 2);

        $this->importar([$this->linha('11111111000191', 'DROGARIA SAO PAULO S.A.', '109', rede: 'Grupo DPSP')]);

        $this->assertSame(2, ContaEstrategica::count());
        $this->assertNull(Lead::sole()->conta_estrategica_id);
    }

    public function test_segmento_fora_das_abas_fica_so_em_leads(): void
    {
        // 103 (órgão público) não é aba. Até 2026-10-08 o exemplo era o 101, que virou aba.
        $this->importar([$this->linha('11111111000191', 'PREFEITURA X', '103', rede: 'PREFEITURA X')]);

        $this->assertSame(0, ContaEstrategica::count());
        $this->assertNull(Lead::sole()->conta_estrategica_id);
    }

    public function test_recorte_por_conta_e_ignorado_fora_da_visao_diretor(): void
    {
        $conta = $this->conta('FARMA NOVA');
        $this->importar([
            $this->linha('11111111000191', 'FARMA NOVA 1', '109', rede: 'FARMA NOVA'),
            $this->linha('33333333000191', 'OUTRA COISA', '109'),
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
            'cnpj' => '11.111.111/0001-91', 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->importar([$this->linha('11111111000191', 'EMPRESA A', '101')]);

        $this->assertSame([$id], Lead::pluck('id')->all());
        $this->assertSame(Lead::ORIGEM_PROSPECCAO, Lead::sole()->origem);
    }

    public function test_cnpj_que_ja_e_lead_manual_nao_duplica_nem_e_tocado(): void
    {
        DB::table('leads')->insert([
            'origem' => Lead::ORIGEM_MANUAL, 'nome' => 'MEU LEAD', 'razao_social' => 'MEU LEAD',
            'cnpj' => '11.111.111/0001-91', 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->importar([$this->linha('11111111000191', 'OUTRO NOME', '101')]);

        $this->assertSame(1, Lead::count());
        $this->assertSame('MEU LEAD', Lead::sole()->razao_social);
        $this->assertSame(Lead::ORIGEM_MANUAL, Lead::sole()->origem);
    }

    /** O que já apontava para o lead que sai passa para o que fica. */
    public function test_juntar_leva_o_historico_do_lead_que_sai(): void
    {
        $conta = $this->conta('AGAFARMA');
        $fica = Lead::create(['origem' => Lead::ORIGEM_MANUAL, 'cod_vendedor' => '010755', 'nome' => 'AGAFARMA', 'razao_social' => 'AGAFARMA', 'status' => 'ativo', 'etapa' => Lead::ETAPA_NOVO]);
        $sai = Lead::create(['origem' => Lead::ORIGEM_PROSPECCAO, 'nome' => 'Agafarma', 'razao_social' => 'AGAFARMA LTDA', 'cnpj' => '11.111.111/0001-91', 'cidade' => 'Porto Alegre', 'status' => 'ativo', 'etapa' => Lead::ETAPA_NOVO]);
        foreach ([$fica, $sai] as $l) {
            $l->forceFill(['conta_estrategica_id' => $conta->id, 'conta_vinculo' => Lead::CONTA_CONFIRMADA])->save();
        }
        $obs = \App\Models\Observacao::create(['lead_id' => $sai->id, 'cnpj' => '', 'mensagem' => 'liguei']);

        $this->artisan('visao-diretor:juntar-leads')->assertSuccessful();

        $this->assertSame([$fica->id], Lead::pluck('id')->all());
        $this->assertSame($fica->id, $obs->fresh()->lead_id);
        $this->assertSame('Porto Alegre', $fica->fresh()->cidade);
    }

    /** Dois CNPJs diferentes na mesma conta são duas empresas, não duplicata. */
    public function test_dois_cnpjs_na_mesma_conta_nao_sao_juntados(): void
    {
        $conta = $this->conta('AGAFARMA');
        foreach (['11.111.111/0001-91', '22.222.222/0001-91'] as $cnpj) {
            Lead::create(['origem' => Lead::ORIGEM_PROSPECCAO, 'nome' => 'X', 'razao_social' => 'X', 'cnpj' => $cnpj, 'status' => 'ativo', 'etapa' => Lead::ETAPA_NOVO])
                ->forceFill(['conta_estrategica_id' => $conta->id, 'conta_vinculo' => Lead::CONTA_CONFIRMADA])->save();
        }

        $this->artisan('visao-diretor:juntar-leads')->assertSuccessful();

        $this->assertSame(2, Lead::count());
    }

    public function test_receita_tambem_tira_lead_da_prospeccao(): void
    {
        $this->importar([$this->linha('11111111000191', 'EMPRESA A', '101')]);
        DB::table('cnpj_situacoes')->where('cnpj', '11111111000191')->update(['situacao' => 'BAIXADA']);

        app(SituacaoCadastral::class)->sincronizarLeads();

        $this->assertSame('excluido', Lead::sole()->status);
    }

    public function test_rodada_grava_o_relatorio_e_a_atualizacoes_mostra(): void
    {
        DB::table('clientes')->insert([
            'cod_cliente' => '000123', 'loja' => '01', 'razao_social' => 'JA CLIENTE',
            'cnpj' => '22.222.222/0001-91', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $linhas = [
            $this->linha('11111111000191', 'NOVO', '101'),
            $this->linha('22222222000191', 'JA CLIENTE', '101'),
            $this->linha('33333333000191', 'BAIXADO', '101'),
            ['cnpj' => '123', 'razao_social' => 'CURTO', 'segmento' => '101'],
        ];
        $this->escrever($linhas);
        DB::table('cnpj_situacoes')->where('cnpj', '33333333000191')->update(['situacao' => 'BAIXADA']);
        $this->artisan('totvs:import-leads')->assertSuccessful();

        $r = \App\Models\LeadImportacao::sole();
        $this->assertFalse($r->simulacao);
        $this->assertSame('sucesso', $r->status);
        $this->assertSame(1, $r->resultado['novos']);
        $this->assertSame(1, $r->resultado['jaClientes']);
        $this->assertSame(['BAIXADA' => 1], $r->resultado['receitaPorSituacao']);
        $this->assertSame(1, $r->resultado['recusadas']);
        $this->assertStringContainsString('Leads - teste.csv:5', $r->recusadas[0]);
        $this->assertSame([['nome' => 'Leads - teste.csv', 'linhas' => 4]], $r->arquivos);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get(route('atualizacoes.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('leadsProspeccao.ultima.resultado.jaClientes', 1)
                ->where('leadsProspeccao.prospeccaoNoCrm', 1)
                ->where('leadsProspeccao.ultima.simulacao', false)
                ->where('leadsProspeccao.emAndamento', false));
    }

    public function test_cnpj_com_digito_errado_e_recusado_sem_consultar_a_receita(): void
    {
        // O caso real da primeira planilha: Nissei com -10 no lugar de -22. Sem a checagem,
        // as fontes respondem 400 e o lead ficava "esperando a Receita" para sempre.
        $this->escrever([['cnpj' => '79430682000110', 'razao_social' => 'NISSEI', 'segmento' => '109']]);
        Http::fake(); // qualquer consulta seria um erro deste teste

        $this->artisan('totvs:import-leads')->assertSuccessful();

        Http::assertNothingSent();
        $r = \App\Models\LeadImportacao::sole();
        $this->assertSame(0, $r->resultado['segurados']);
        $this->assertSame(1, $r->resultado['recusadas']);
        $this->assertStringContainsString('dígito verificador errado', $r->recusadas[0]);
        $this->assertStringContainsString('-22', $r->recusadas[0]);
    }

    public function test_cada_numero_do_card_abre_a_lista_com_o_porque(): void
    {
        DB::table('clientes')->insert([
            'cod_cliente' => '000123', 'loja' => '01', 'razao_social' => 'CLIENTE ANTIGO SA',
            'cnpj' => '22.222.222/0001-91', 'cod_vendedor' => '010617', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->escrever([
            $this->linha('11111111000191', 'NOVO', '101'),
            $this->linha('22222222000191', 'JA CLIENTE', '101'),
            $this->linha('33333333000191', 'BAIXADO', '101'),
        ]);
        DB::table('cnpj_situacoes')->where('cnpj', '33333333000191')->update(['situacao' => 'BAIXADA']);
        $this->artisan('totvs:import-leads')->assertSuccessful();
        $rodada = \App\Models\LeadImportacao::sole();
        $admin = $this->admin();

        $clientes = $this->actingAs($admin)->getJson(route('atualizacoes.leads.detalhe', [$rodada, 'jaClientes']))
            ->assertOk()->json();
        $this->assertSame('Já eram clientes (TOTVS)', $clientes['titulo']);
        $this->assertSame('22.222.222/0001-91', $clientes['itens'][0]['cnpj']);
        $this->assertStringContainsString('CLIENTE ANTIGO SA', $clientes['itens'][0]['info']);

        $receita = $this->actingAs($admin)->getJson(route('atualizacoes.leads.detalhe', [$rodada, 'naoAtivos']))->json();
        $this->assertSame('BAIXADO', $receita['itens'][0]['nome']);
        $this->assertSame('Receita: Baixada', $receita['itens'][0]['info']);

        $novos = $this->actingAs($admin)->getJson(route('atualizacoes.leads.detalhe', [$rodada, 'novos']))->json();
        $this->assertSame(['NOVO'], array_column($novos['itens'], 'nome'));
        $this->assertSame(1, $novos['total']);

        $this->actingAs($admin)->getJson(route('atualizacoes.leads.detalhe', [$rodada, 'qualquer']))->assertNotFound();
        $this->actingAs($this->diretor())->getJson(route('atualizacoes.leads.detalhe', [$rodada, 'novos']))->assertForbidden();
    }

    public function test_simulacao_e_falha_tambem_ficam_registradas(): void
    {
        $this->escrever([$this->linha('11111111000191', 'NOVO', '101')]);
        $this->artisan('totvs:import-leads', ['--dry-run' => true])->assertSuccessful();

        file_put_contents($this->diretorioTotvs.'/Leads/Leads - teste.csv', "cnpj;RAZAO\n1;X\n");
        try {
            $this->artisan('totvs:import-leads');
        } catch (\RuntimeException) {
        }

        [$simulacao, $falha] = \App\Models\LeadImportacao::orderBy('id')->get()->all();
        $this->assertTrue($simulacao->simulacao);
        $this->assertSame(1, $simulacao->resultado['novos']);
        $this->assertSame('falhou', $falha->status);
        $this->assertStringContainsString('razao_social', $falha->erro);
    }

    public function test_botao_cria_a_rodada_executando_e_manda_para_a_fila(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('atualizacoes.leads'), ['simulacao' => true])
            ->assertRedirect()->assertSessionHas('sucesso');

        $rodada = \App\Models\LeadImportacao::sole();
        $this->assertSame('executando', $rodada->status);
        $this->assertTrue($rodada->simulacao);
        $this->assertSame($admin->id, $rodada->user_id);
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\ImportarLeadsProspeccaoJob::class,
            fn ($job) => $job->rodadaId === $rodada->id && $job->simulacao === true,
        );

        // A tela já entra em modo de acompanhamento.
        $this->actingAs($admin)->get(route('atualizacoes.index'))
            ->assertInertia(fn (Assert $p) => $p->where('leadsProspeccao.emAndamento', true));
    }

    public function test_botao_recusa_segunda_rodada_mas_nao_fica_preso_na_travada(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('atualizacoes.leads'));

        $this->actingAs($admin)->post(route('atualizacoes.leads'))->assertSessionHas('erro');
        $this->assertSame(1, \App\Models\LeadImportacao::count());

        // Worker morreu no meio: depois do corte, a rodada não segura mais o botão.
        \App\Models\LeadImportacao::query()->update(['iniciada_em' => now()->subHour()]);
        $this->actingAs($admin)->post(route('atualizacoes.leads'))->assertSessionHas('sucesso');
        $this->assertSame(2, \App\Models\LeadImportacao::count());
    }

    public function test_so_admin_dispara(): void
    {
        $this->actingAs($this->diretor())->post(route('atualizacoes.leads'))->assertForbidden();
    }

    public function test_comando_com_rodada_preenche_a_linha_do_botao(): void
    {
        $this->escrever([$this->linha('11111111000191', 'NOVO', '101')]);
        $rodada = \App\Models\LeadImportacao::create(['simulacao' => false, 'status' => 'executando', 'iniciada_em' => now()]);

        $this->artisan('totvs:import-leads', ['--rodada' => $rodada->id])->assertSuccessful();

        $this->assertSame(1, \App\Models\LeadImportacao::count(), 'usa a linha do botão, não cria outra');
        $this->assertSame('sucesso', $rodada->fresh()->status);
        $this->assertSame(1, $rodada->fresh()->resultado['novos']);
    }

    public function test_job_que_quebra_fora_do_comando_fecha_a_rodada(): void
    {
        $rodada = \App\Models\LeadImportacao::create(['simulacao' => false, 'status' => 'executando', 'iniciada_em' => now()]);

        (new \App\Jobs\ImportarLeadsProspeccaoJob($rodada->id, false))->failed(new \RuntimeException('S3 fora do ar'));

        $this->assertSame('falhou', $rodada->fresh()->status);
        $this->assertSame('S3 fora do ar', $rodada->fresh()->erro);
    }

    // ---------------------------------------------------------------------------------

    private function admin(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('admin');

        return $u;
    }

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

    private function linha(string $cnpj, string $razao, string $segmento, string $rede = '', string $filiais = '', string $fantasia = ''): array
    {
        return [
            'cnpj' => $cnpj, 'razao_social' => $razao, 'nome_fantasia' => $fantasia, 'segmento' => $segmento,
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
