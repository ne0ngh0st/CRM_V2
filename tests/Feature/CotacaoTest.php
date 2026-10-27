<?php

namespace Tests\Feature;

use App\Models\Cotacao;
use App\Models\CotacaoDecisao;
use App\Models\CotacaoItem;
use App\Models\CotacaoMemoria;
use App\Models\EstoqueRevenda;
use App\Models\User;
use App\Services\Cotacao\CotacaoService;
use App\Services\Exportacao\CatalogoDeExportacoes;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Cotação ponta a ponta, com o MOTOR PYTHON DE VERDADE (não `Process::fake`): a chave da
 * memória é produzida por ele, e um teste com motor falso não provaria que a decisão de
 * hoje casa com a lista de amanhã — que é a feature inteira.
 *
 * A fila roda síncrona nos testes (`phpunit.xml`), então `iniciar()` já processa.
 */
class CotacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable(config('cotacao.python'))) {
            $this->markTestSkipped('Python da cotação não instalado (ver docker/dev/Dockerfile).');
        }

        $this->seed(RoleSeeder::class);
        Storage::fake('local');

        foreach ([
            ['V10001', 'ALCOOL LIQUIDO 70% 1LT', 'TUPI', 50, 9.5],
            ['V10002', 'ALCOOL LIQUIDO 70% 500ML', 'TUPI', 10, 5.2],
            ['V20001', 'PAPEL SULFITE A4 75G BRANCO C/500 FLS', 'CHAMEX', 300, 22.9],
            ['V30001', 'COPO DESCARTAVEL 200ML C/100 UND', 'COPOBRAS', 0, 4.1],
        ] as [$codigo, $descricao, $marca, $disp, $custo]) {
            EstoqueRevenda::query()->insert([
                'codigo' => $codigo, 'descricao' => $descricao, 'marca' => $marca,
                'fisico' => $disp, 'disponivel' => $disp, 'ult_preco' => $custo,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function usuario(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function lista(array $linhas = null): UploadedFile
    {
        $linhas ??= [
            ['Descricao do produto', 'Quantidade', 'Valor total'],
            ['ALCOOL 70 1000 ML', '10', '95,00'],
            ['PAPEL SULFITE A4 75G', '20', '458,00'],
            ['CANETA ESFEROGRAFICA AZUL', '30', '45,00'],
        ];
        $csv = implode("\n", array_map(fn ($l) => implode(';', $l), $linhas));

        return UploadedFile::fake()->createWithContent('lista-cliente.csv', $csv);
    }

    private function cotacaoDe(User $user, ?UploadedFile $arquivo = null): Cotacao
    {
        return app(CotacaoService::class)->iniciar($user, $arquivo ?? $this->lista(), null)->refresh();
    }

    // ---------------------------------------------------------------- acesso

    public function test_so_os_perfis_da_decisao_usam_a_cotacao(): void
    {
        foreach (['vendedor', 'representante', 'supervisor', 'diretor', 'admin'] as $role) {
            $this->actingAs($this->usuario($role))->get(route('cotacoes.index'))->assertOk();
        }

        foreach (['venda_interna', 'assistente'] as $role) {
            $this->actingAs($this->usuario($role))->get(route('cotacoes.index'))->assertForbidden();
        }
    }

    public function test_vendedor_nao_ve_a_cotacao_de_outro(): void
    {
        $cotacao = $this->cotacaoDe($this->usuario('vendedor'));

        $this->actingAs($this->usuario('vendedor'))->get(route('cotacoes.show', $cotacao))->assertNotFound();
        $this->actingAs($this->usuario('admin'))->get(route('cotacoes.show', $cotacao))->assertOk();
    }

    // ---------------------------------------------------------------- motor

    public function test_a_lista_e_comparada_pelo_motor_e_vira_itens(): void
    {
        $cotacao = $this->cotacaoDe($this->usuario('vendedor'));

        $this->assertSame(Cotacao::STATUS_PRONTA, $cotacao->status, (string) $cotacao->erro);
        $this->assertSame(3, $cotacao->itens_total);

        // Volume casa (1000 ML = 1LT) e o 500ML é vetado pelo atributo: o motor tem que
        // ter sugerido o de 1 litro.
        $alcool = $cotacao->itens()->where('descricao_cliente', 'ALCOOL 70 1000 ML')->first();
        $this->assertSame('V10001', $alcool->codigo_sugerido);
        $this->assertNotSame('', $alcool->texto_normalizado);
    }

    public function test_lista_sem_coluna_de_descricao_falha_com_mensagem_para_o_usuario(): void
    {
        $cotacao = $this->cotacaoDe($this->usuario('vendedor'), $this->lista([['1', '2'], ['3', '4']]));

        $this->assertSame(Cotacao::STATUS_FALHA, $cotacao->status);
        $this->assertNotEmpty($cotacao->erro);
    }

    // ---------------------------------------------------------------- custo

    public function test_custo_nao_aparece_para_representante(): void
    {
        $representante = $this->usuario('representante');
        $cotacao = $this->cotacaoDe($representante);

        $conferir = $this->actingAs($representante)->get(route('cotacoes.conferir', $cotacao))->assertOk();
        $candidatos = $conferir->viewData('page')['props']['item']['candidatos'];
        $this->assertNotEmpty($candidatos);
        foreach ($candidatos as $c) {
            $this->assertArrayNotHasKey('custo', $c);
        }

        $busca = $this->actingAs($representante)->getJson(route('cotacoes.produtos', ['q' => 'alcool']))->json();
        $this->assertNotEmpty($busca);
        $this->assertArrayNotHasKey('custo', $busca[0]);
    }

    public function test_vendedor_e_gestor_veem_custo(): void
    {
        $vendedor = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($vendedor);

        foreach (['vendedor' => $vendedor, 'admin' => $this->usuario('admin')] as $perfil => $user) {
            $candidatos = $this->actingAs($user)->get(route('cotacoes.conferir', $cotacao))
                ->viewData('page')['props']['item']['candidatos'];
            $this->assertArrayHasKey('custo', $candidatos[0], $perfil);
        }

        $busca = $this->actingAs($vendedor)->getJson(route('cotacoes.produtos', ['q' => 'alcool']))->json();
        $this->assertArrayHasKey('custo', $busca[0]);
    }

    public function test_planilha_do_representante_nao_tem_coluna_de_custo(): void
    {
        $representante = $this->usuario('representante');
        $cotacao = $this->cotacaoDe($representante);
        $request = Request::create('/', 'GET', ['cotacao' => $cotacao->id]);

        $plano = app(CatalogoDeExportacoes::class)->plano('cotacao', $request, $representante);
        $this->assertNotContains('Custo (últ. compra)', $plano->planilha->headings());

        $vendedor = $this->usuario('vendedor');
        $request = Request::create('/', 'GET', ['cotacao' => $this->cotacaoDe($vendedor)->id]);
        $plano = app(CatalogoDeExportacoes::class)->plano('cotacao', $request, $vendedor);
        $this->assertContains('Custo (últ. compra)', $plano->planilha->headings());

        $plano = app(CatalogoDeExportacoes::class)->plano('cotacao', $request, $this->usuario('admin'));
        $this->assertContains('Custo (últ. compra)', $plano->planilha->headings());
    }

    // ---------------------------------------------------------------- memória

    public function test_decisao_grava_historico_e_memoria_e_vale_na_proxima_lista_de_outra_pessoa(): void
    {
        $sthefany = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($sthefany);
        $caneta = $cotacao->itens()->where('descricao_cliente', 'CANETA ESFEROGRAFICA AZUL')->first();

        $this->actingAs($sthefany)
            ->postJson(route('cotacoes.itens.decidir', $caneta), ['acao' => 'escolher', 'codigo' => 'V20001'])
            ->assertOk();

        $this->assertSame('APRENDIDO', $caneta->refresh()->status);
        $this->assertSame('TROCAR', $caneta->decisao);
        $this->assertSame(1, CotacaoDecisao::count());
        $this->assertSame('V20001', CotacaoMemoria::find($caneta->texto_normalizado)->codigo);

        // Outra pessoa, outra cotação, mesma descrição: o motor já devolve APRENDIDO.
        $outra = $this->cotacaoDe($this->usuario('representante'));
        $deNovo = $outra->itens()->where('descricao_cliente', 'CANETA ESFEROGRAFICA AZUL')->first();
        $this->assertSame('APRENDIDO', $deNovo->status_motor);
        $this->assertSame('V20001', $deNovo->codigo_sugerido);
    }

    public function test_confirmar_a_sugestao_e_ok_e_nao_temos_nao_guarda_codigo(): void
    {
        $user = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($user);
        $alcool = $cotacao->itens()->where('descricao_cliente', 'ALCOOL 70 1000 ML')->first();
        $caneta = $cotacao->itens()->where('descricao_cliente', 'CANETA ESFEROGRAFICA AZUL')->first();

        $this->actingAs($user)->postJson(route('cotacoes.itens.decidir', $alcool), ['acao' => 'escolher', 'codigo' => 'V10001'])->assertOk();
        $this->actingAs($user)->postJson(route('cotacoes.itens.decidir', $caneta), ['acao' => 'nao_temos'])->assertOk();

        $this->assertSame('OK', $alcool->refresh()->decisao);
        $this->assertSame('SEM_MATCH_CONFIRMADO', $caneta->refresh()->status);
        $this->assertNull(CotacaoMemoria::find($caneta->texto_normalizado)->codigo);
    }

    public function test_codigo_fora_do_estoque_e_recusado_sem_gravar(): void
    {
        $user = $this->usuario('vendedor');
        $item = $this->cotacaoDe($user)->itens()->first();

        $this->actingAs($user)
            ->postJson(route('cotacoes.itens.decidir', $item), ['acao' => 'escolher', 'codigo' => 'NAO-EXISTE'])
            ->assertStatus(422);

        $this->assertSame(0, CotacaoDecisao::count());
        $this->assertSame(0, CotacaoMemoria::count());
    }

    public function test_desfazer_anula_no_historico_apaga_a_memoria_e_volta_para_a_fila(): void
    {
        $user = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($user);
        $caneta = $cotacao->itens()->where('descricao_cliente', 'CANETA ESFEROGRAFICA AZUL')->first();
        $statusMotor = $caneta->status_motor;

        $this->actingAs($user)->postJson(route('cotacoes.itens.decidir', $caneta), ['acao' => 'nao_temos']);
        $this->actingAs($user)->post(route('cotacoes.itens.desfazer', $caneta))->assertRedirect();

        $caneta->refresh();
        $this->assertSame($statusMotor, $caneta->status);
        $this->assertNull($caneta->decisao);
        $this->assertSame(0, CotacaoMemoria::count());
        $this->assertSame(['NAO_TEMOS', 'ANULADO'], CotacaoDecisao::orderBy('id')->pluck('decisao')->all());
    }

    public function test_pular_tira_da_fila_sem_tocar_na_memoria(): void
    {
        $user = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($user);
        $antes = app(CotacaoService::class)->pendentes($cotacao)->count();
        $item = app(CotacaoService::class)->pendentes($cotacao)->first();

        $this->actingAs($user)->postJson(route('cotacoes.itens.decidir', $item), ['acao' => 'pular'])->assertOk();

        $this->assertSame($antes - 1, app(CotacaoService::class)->pendentes($cotacao)->count());
        $this->assertSame(0, CotacaoMemoria::count());
    }

    public function test_importar_memoria_do_exe_e_idempotente_e_recalcula_a_chave(): void
    {
        $arquivo = storage_path('framework/testing/snapshot-'.uniqid().'.json');
        @mkdir(dirname($arquivo), 0777, true);
        file_put_contents($arquivo, json_encode(['decisoes' => [[
            'texto_normalizado' => 'CHAVE VELHA QUE NAO BATE',
            'descricao_cliente' => 'CANETA ESFEROGRAFICA AZUL',
            'decisao' => 'NAO_TEMOS', 'codigo' => '', 'descricao_nossa' => '',
            'usuario' => 'Fulano.Silva', 'data' => '2026-08-31', 'origem' => 'lista.xlsx',
        ]]]));

        try {
            $this->artisan('cotacao:importar-memoria', ['arquivo' => $arquivo])->assertSuccessful();
            $this->artisan('cotacao:importar-memoria', ['arquivo' => $arquivo])->assertSuccessful();
        } finally {
            @unlink($arquivo);
        }

        $this->assertSame(1, CotacaoMemoria::count());
        $this->assertSame(1, CotacaoDecisao::count());

        // A chave saiu do motor: a lista nova casa com a decisão importada.
        $item = $this->cotacaoDe($this->usuario('vendedor'))->itens()
            ->where('descricao_cliente', 'CANETA ESFEROGRAFICA AZUL')->first();
        $this->assertSame('SEM_MATCH_CONFIRMADO', $item->status_motor);
    }

    /**
     * O RFP de material de escritório (22/10) tem a lista a cotar numa aba e o
     * histórico numa aba "Volumetria" mais cheia. Quem envia escolhe a aba; o
     * motor lê só essa. Sem a escolha, a cotação não compara nada.
     */
    public function test_excel_com_varias_abas_espera_a_pessoa_escolher(): void
    {
        $user = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($user, $this->xlsx($this->rfpComVolumetria(), 'rfp-escritorio.xlsx'));

        $this->assertSame(Cotacao::STATUS_AGUARDANDO_ABA, $cotacao->status);
        $this->assertSame(0, $cotacao->itens_total);
        $this->assertSame(
            ['Volumetria 30set22-30set23', 'RFP'],
            array_column($cotacao->leitura['abas'], 'nome'),
        );

        $this->actingAs($user)
            ->get(route('cotacoes.index', ['escolher' => $cotacao->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('escolher.id', $cotacao->id)
                ->where('escolher.arquivo', 'rfp-escritorio.xlsx')
                ->has('escolher.abas', 2));

        $this->actingAs($user)
            ->get(route('cotacoes.show', $cotacao))
            ->assertRedirect(route('cotacoes.index', ['escolher' => $cotacao->id]));

        $this->actingAs($user)
            ->post(route('cotacoes.aba', $cotacao), ['aba' => 'RFP'])
            ->assertRedirect(route('cotacoes.show', $cotacao));

        $cotacao->refresh();
        $this->assertSame('RFP', $cotacao->aba);
        $this->assertSame(Cotacao::STATUS_PRONTA, $cotacao->status, (string) $cotacao->erro);
        $this->assertSame(23, $cotacao->itens_total);
        $this->assertNotNull($cotacao->itens()->where('descricao_cliente', 'ALCOOL 70 1000 ML')->first());
        $this->assertNull($cotacao->itens()->where('descricao_cliente', 'like', 'PRODUTO HISTORICO%')->first());
    }

    public function test_a_aba_escolhida_e_a_unica_que_o_motor_le(): void
    {
        $user = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($user, $this->xlsx($this->rfpComVolumetria(), 'rfp-escritorio.xlsx'));

        $this->actingAs($user)
            ->post(route('cotacoes.aba', $cotacao), ['aba' => 'Volumetria 30set22-30set23'])
            ->assertRedirect(route('cotacoes.show', $cotacao));

        $cotacao->refresh();
        $this->assertSame(Cotacao::STATUS_PRONTA, $cotacao->status, (string) $cotacao->erro);
        $this->assertSame(40, $cotacao->itens_total);
        $this->assertNotNull($cotacao->itens()->where('descricao_cliente', 'PRODUTO HISTORICO 1')->first());
        $this->assertNull($cotacao->itens()->where('descricao_cliente', 'ALCOOL 70 1000 ML')->first());
    }

    public function test_aba_fora_do_arquivo_nao_dispara_a_comparacao(): void
    {
        $user = $this->usuario('vendedor');
        $cotacao = $this->cotacaoDe($user, $this->xlsx($this->rfpComVolumetria(), 'rfp-escritorio.xlsx'));

        $this->actingAs($user)
            ->post(route('cotacoes.aba', $cotacao), ['aba' => 'Capa'])
            ->assertSessionHasErrors('aba');

        $this->assertSame(Cotacao::STATUS_AGUARDANDO_ABA, $cotacao->refresh()->status);
        $this->assertSame(0, $cotacao->itens()->count());
    }

    public function test_outro_vendedor_nao_escolhe_a_aba(): void
    {
        $cotacao = $this->cotacaoDe($this->usuario('vendedor'), $this->xlsx($this->rfpComVolumetria(), 'rfp-escritorio.xlsx'));

        $this->actingAs($this->usuario('vendedor'))
            ->post(route('cotacoes.aba', $cotacao), ['aba' => 'RFP'])
            ->assertNotFound();

        $this->assertSame(Cotacao::STATUS_AGUARDANDO_ABA, $cotacao->refresh()->status);
    }

    /**
     * Na mesma aba, "Categoria Descrição" e "Descrição" casam com o mesmo nome.
     * A categoria vem primeiro; sem o corte, as linhas colapsam nas 2 categorias.
     */
    public function test_coluna_categoria_descricao_nao_engole_o_produto(): void
    {
        $wb = new Spreadsheet();
        $wb->getActiveSheet()->fromArray([
            ['Categoria Descrição', 'Descrição', 'Quant.'],
            ['Escritório e Papelaria', 'COLA BASTAO 10G', 4],
            ['Escritório e Papelaria', 'ALCOOL 70 1000 ML', 2],
            ['Copa e alimento', 'CAFE PO VACUO 500G', 6],
            ['Copa e alimento', 'AÇUCAR REFINADO 1KG', 3],
        ]);

        $cotacao = $this->cotacaoDe($this->usuario('vendedor'), $this->xlsx($wb, 'lista.xlsx'));

        $this->assertSame(Cotacao::STATUS_PRONTA, $cotacao->status, (string) $cotacao->erro);
        $this->assertSame(4, $cotacao->itens_total);
        $nomes = $cotacao->itens()->pluck('descricao_cliente')->all();
        $this->assertContains('COLA BASTAO 10G', $nomes);
        $this->assertContains('ALCOOL 70 1000 ML', $nomes);
        $this->assertNotContains('Escritório e Papelaria', $nomes);
        $this->assertNotContains('Copa e alimento', $nomes);
    }

    private function rfpComVolumetria(): Spreadsheet
    {
        $wb = new Spreadsheet();
        $vol = $wb->getActiveSheet();
        $vol->setTitle('Volumetria 30set22-30set23');
        $linhas = [['Data Pedido', 'Categoria Descrição', 'Descrição', 'Quant.', 'Cidade']];
        for ($i = 1; $i <= 40; $i++) {
            $linhas[] = ['2024-03-08', 'Escritório e Papelaria', 'PRODUTO HISTORICO '.$i, 3, 'SAO PAULO'];
        }
        $vol->fromArray($linhas);

        $rfp = $wb->createSheet();
        $rfp->setTitle('RFP');
        $rfp->setCellValue('A1', 'Escopo');
        $rfp->setCellValue('A4', 'Descritivo do Escopo:');
        $rfp->setCellValue('B4', 'Materiais de escritório');
        $itens = [['Codigo do Fornecedor', 'DESCRIÇÃO DO ITEM', 'UNIDADE', 'QTDE', 'MARCA', 'VALOR C/ IMPOSTOS']];
        $itens[] = [null, 'ALCOOL 70 1000 ML', 'UN', 1, 'TUPI', null];
        $itens[] = [null, 'PAPEL SULFITE A4 75G', 'CX', 1, 'CHAMEX', null];
        $itens[] = [null, 'CANETA ESFEROGRAFICA AZUL', 'UN', 1, 'BIC', null];
        for ($i = 1; $i <= 20; $i++) {
            $itens[] = [null, 'ITEM RFP '.$i, 'UN', 1, 'MARCA', null];
        }
        $rfp->fromArray($itens, null, 'A8');

        return $wb;
    }

    private function xlsx(Spreadsheet $wb, string $nome): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cotacao');
        (new Xlsx($wb))->save($path);
        $conteudo = file_get_contents($path);
        @unlink($path);

        return UploadedFile::fake()->createWithContent($nome, $conteudo);
    }
}
