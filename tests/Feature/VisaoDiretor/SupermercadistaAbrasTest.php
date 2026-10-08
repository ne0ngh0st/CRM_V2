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

    /**
     * O lead classificado entra como SUGESTÃO da rede cujo nome casa — mesma regra da
     * prospecção, a diretoria confirma na tela.
     */
    public function test_lead_classificado_ganha_sugestao_da_rede(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->supermercadista->id, 'nome' => 'SAVEGNAGO SUPERMERCADOS', 'ordem' => 1]);
        $id = $this->lead('11111111000191', cnae: '4711302', razao: 'SAVEGNAGO SUPERMERCADOS LTDA');
        $outro = $this->lead('22222222000191', cnae: '4711302', razao: 'MERCADINHO DO BAIRRO LTDA');

        $this->artisan('leads:classificar-segmento')->assertSuccessful();

        $lead = Lead::find($id);
        $this->assertSame($conta->id, $lead->conta_estrategica_id);
        $this->assertSame(Lead::CONTA_SUGERIDA, $lead->conta_vinculo);
        $this->assertNull(Lead::find($outro)->conta_estrategica_id, 'mercado pequeno continua só lead');
    }

    /**
     * Só pelo começo do nome. Caso real de produção (08/10): a camada de token distintivo
     * ligava a rede "CRESTANI & FILHOS" a todo mercadinho com FILHOS no nome.
     *
     * Mutação: tirar o `soPrefixo: true` de `sugerirParaLeadsSemConta()`.
     */
    public function test_sugestao_de_lead_nao_casa_por_sobrenome_no_meio_do_nome(): void
    {
        ContaEstrategica::create(['segmento_id' => $this->supermercadista->id, 'nome' => 'CRESTANI & FILHOS', 'ordem' => 1]);
        $outro = $this->lead('11111111000191', cnae: '4711302', razao: '4 FILHOS SUPERMERCADO LTDA');
        $mesmo = $this->lead('22222222000191', cnae: '4711302', razao: 'CRESTANI & FILHOS LTDA');

        $this->artisan('leads:classificar-segmento')->assertSuccessful();

        $this->assertNull(Lead::find($outro)->conta_estrategica_id);
        $this->assertSame(Lead::CONTA_SUGERIDA, Lead::find($mesmo)->conta_vinculo);
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
