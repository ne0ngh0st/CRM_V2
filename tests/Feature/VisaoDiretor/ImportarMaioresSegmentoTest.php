<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Models\Segmento;
use App\Models\User;
use App\Services\VisaoDiretor\ClientesDaConta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * A carga inicial da planilha da diretoria.
 *
 * ⚠️ O fixture reproduz o que a planilha real tem de traiçoeiro: a aba DROGARIAS com uma
 * coluna extra ANTES do NOME (título em B1), e a REDE LOJAS com uma coluna SITE que as
 * outras não têm. Leitura posicional passaria nos casos simples e leria o nome errado aqui.
 */
class ImportarMaioresSegmentoTest extends TestCase
{
    use RefreshDatabase;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();

        Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        Segmento::create(['codigo' => '108', 'nome' => 'REDE DE LOJAS']);

        GrupoCliente::create(['codigo' => '10', 'nome' => 'RAIA DROGASIL SP']);
        GrupoCliente::create(['codigo' => '11', 'nome' => 'RAIA DROGASIL - RJ']);
        GrupoCliente::create(['codigo' => '12', 'nome' => 'MAGAZINE LUIZA']);
        GrupoCliente::create(['codigo' => '9998', 'nome' => 'CLIENTES DIVERSOS']);

        User::factory()->create(['is_active' => true, 'display_name' => 'INAYA LIRIAN', 'name' => 'Inaya Lirian']);

        $this->arquivo = tempnam(sys_get_temp_dir(), 'maiores').'.xlsx';
        $this->planilha();
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        parent::tearDown();
    }

    private function planilha(): void
    {
        $xlsx = new Spreadsheet;

        $drog = $xlsx->getActiveSheet()->setTitle('DROGARIAS');
        $drog->fromArray([
            [null, 'DROGARIAS - Inaya'],
            [null, 'NOME', 'UF', 'FILIAIS', 'ATENDIMENTO', 'STATUS', 'OBS'],
            ['Raia Drogasil', 'RAIA DROGASIL', 'SP', 2390, 'ROBERTO', 'ATIVO', 'OK'],
            ['Pague Menos', 'PAGUE MENOS', 'ce', '1.123', '', 'LEAD', 'Trabalhar cliente'],
        ]);

        $lojas = $xlsx->createSheet()->setTitle('REDE LOJAS');
        $lojas->fromArray([
            ['REDE DE LOJAS - Ninguém'],
            ['NOME', 'UF', 'FILIAIS', 'ATENDIMENTO', 'STATUS', 'OBS', 'SITE'],
            ['Magazine Luiza', 'SP', 1570, 'CLEBER', 'ATIVO', 'Vendemos bobinas', 'magazineluiza.com.br'],
        ]);

        (new Xlsx($xlsx))->save($this->arquivo);
    }

    public function test_importa_as_abas_pelo_cabecalho(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $raia = ContaEstrategica::where('nome', 'RAIA DROGASIL')->firstOrFail();
        $this->assertSame('SP', $raia->uf);
        $this->assertSame(2390, $raia->filiais_mercado);
        // "OK" não é observação.
        $this->assertNull($raia->observacao);
        $this->assertSame(1, $raia->ordem);

        $pague = ContaEstrategica::where('nome', 'PAGUE MENOS')->firstOrFail();
        $this->assertSame('CE', $pague->uf);
        $this->assertSame(1123, $pague->filiais_mercado);
        $this->assertSame('Trabalhar cliente', $pague->observacao);

        $magalu = ContaEstrategica::where('nome', 'Magazine Luiza')->firstOrFail();
        $this->assertSame('magazineluiza.com.br', $magalu->site);
        $this->assertSame('Vendemos bobinas', $magalu->observacao);
    }

    public function test_sugere_grupos_pelo_prefixo_com_fronteira_de_token(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $raia = ContaEstrategica::where('nome', 'RAIA DROGASIL')->firstOrFail();
        $this->assertEqualsCanonicalizing(['10', '11'], $raia->vinculos()->pluck('codigo')->all());
        $this->assertSame([ContaEstrategicaVinculo::ORIGEM_SUGESTAO], $raia->vinculos()->distinct()->pluck('origem')->all());

        $this->assertSame(0, ContaEstrategica::where('nome', 'PAGUE MENOS')->firstOrFail()->vinculos()->count());
    }

    public function test_especialista_sai_do_titulo_da_aba_quando_casa_um_usuario_so(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertNotNull(Segmento::where('codigo', '109')->value('especialista_user_id'));
        $this->assertNull(Segmento::where('codigo', '108')->value('especialista_user_id'));
    }

    public function test_dry_run_nao_grava_nada(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, ContaEstrategica::count());
        $this->assertSame(0, ContaEstrategicaVinculo::count());
    }

    /**
     * Rodar de novo depois de editar na tela não pode desfazer a edição: campo preenchido
     * fica, e conta com vínculo manual não tem os vínculos tocados.
     */
    public function test_rodar_de_novo_nao_desfaz_edicao_da_tela(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $raia = ContaEstrategica::where('nome', 'RAIA DROGASIL')->firstOrFail();
        $raia->update(['observacao' => 'editado na tela', 'filiais_mercado' => 3000]);
        app(ClientesDaConta::class)->sincronizarVinculos($raia, [['tipo' => 'grupo', 'codigo' => '12']]);

        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $raia->refresh();
        $this->assertSame('editado na tela', $raia->observacao);
        $this->assertSame(3000, $raia->filiais_mercado);
        $this->assertSame(['12'], $raia->vinculos()->pluck('codigo')->all());
        $this->assertSame(3, ContaEstrategica::count());
    }

    public function test_nunca_sugere_o_grupo_clientes_diversos(): void
    {
        $xlsx = new Spreadsheet;
        $xlsx->getActiveSheet()->setTitle('DROGARIAS')->fromArray([
            ['DROGARIAS'],
            ['NOME', 'UF'],
            ['CLIENTES DIVERSOS', 'SP'],
        ]);
        (new Xlsx($xlsx))->save($this->arquivo);

        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $this->assertSame(0, ContaEstrategicaVinculo::count());
    }

    // ─── Modo "só observações" (2026-09-25) ─────────────────────────────────────

    /**
     * Uma segunda planilha, com OBS revisada — é o caso real: a diretoria mexe só no texto
     * e quer trazê-lo para o CRM sem que o resto seja tocado.
     */
    private function planilhaDeObservacoes(array $linhasDrogarias, array $linhasLojas = []): string
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'obs').'.xlsx';
        $xlsx = new Spreadsheet;

        $xlsx->getActiveSheet()->setTitle('DROGARIAS')->fromArray(array_merge([
            [null, 'DROGARIAS - Inaya'],
            [null, 'NOME', 'UF', 'FILIAIS', 'ATENDIMENTO', 'STATUS', 'OBS'],
        ], $linhasDrogarias));

        $xlsx->createSheet()->setTitle('REDE LOJAS')->fromArray(array_merge([
            ['REDE DE LOJAS'],
            ['NOME', 'UF', 'FILIAIS', 'ATENDIMENTO', 'STATUS', 'OBS', 'SITE'],
        ], $linhasLojas));

        (new Xlsx($xlsx))->save($arquivo);

        return $arquivo;
    }

    public function test_so_observacoes_atualiza_o_texto_e_nao_toca_no_resto(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $pague = ContaEstrategica::where('nome', 'PAGUE MENOS')->firstOrFail();
        $vinculosAntes = $pague->vinculos()->count();

        $arquivo = $this->planilhaDeObservacoes([
            [null, 'PAGUE MENOS', 'SP', 9999, 'OUTRO', 'ATIVO', 'Revisada pela diretoria'],
        ]);

        $this->artisan('diretor:importar-maiores-segmento', [
            'arquivo' => $arquivo,
            '--somente-observacoes' => true,
        ])->assertSuccessful();

        $pague->refresh();
        $this->assertSame('Revisada pela diretoria', $pague->observacao);
        // UF e filiais da planilha nova NÃO entram: o modo é só observação.
        $this->assertSame('CE', $pague->uf);
        $this->assertSame(1123, $pague->filiais_mercado);
        $this->assertSame($vinculosAntes, $pague->vinculos()->count());

        @unlink($arquivo);
    }

    /**
     * ⚠️ O caso que motivou o modo: a planilha de 25/09 veio com a aba DROGARIAS em branco
     * enquanto o CRM tinha 61 observações lá. Vazio apagando seria perda silenciosa.
     */
    public function test_obs_vazia_na_planilha_nao_apaga_a_do_crm(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $arquivo = $this->planilhaDeObservacoes([
            [null, 'PAGUE MENOS', 'CE', 1123, '', 'LEAD', ''],
            [null, 'RAIA DROGASIL', 'SP', 2390, '', 'ATIVO', 'OK'],
        ]);

        $this->artisan('diretor:importar-maiores-segmento', [
            'arquivo' => $arquivo,
            '--somente-observacoes' => true,
        ])->assertSuccessful();

        $this->assertSame('Trabalhar cliente', ContaEstrategica::where('nome', 'PAGUE MENOS')->value('observacao'));
        // "OK" continua não sendo observação — nem para gravar, nem para apagar.
        $this->assertNull(ContaEstrategica::where('nome', 'RAIA DROGASIL')->value('observacao'));

        @unlink($arquivo);
    }

    public function test_so_observacoes_nao_cria_conta_que_nao_existe(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();
        $antes = ContaEstrategica::count();

        $arquivo = $this->planilhaDeObservacoes([
            [null, 'DROGARIA INVENTADA', 'SP', 10, '', 'LEAD', 'Texto qualquer'],
        ]);

        $this->artisan('diretor:importar-maiores-segmento', [
            'arquivo' => $arquivo,
            '--somente-observacoes' => true,
        ])->expectsOutputToContain('DROGARIA INVENTADA')->assertSuccessful();

        $this->assertSame($antes, ContaEstrategica::count());

        @unlink($arquivo);
    }

    /** Acento e caixa não podem impedir o casamento: o nome é o mesmo. */
    public function test_so_observacoes_casa_nome_com_acento_e_caixa_diferentes(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $conta = ContaEstrategica::where('nome', 'Magazine Luiza')->firstOrFail();

        $arquivo = $this->planilhaDeObservacoes([], [
            ['MAGAZINE LUÍZA', 'SP', 1570, '', 'ATIVO', 'Ampliar mix', 'magazineluiza.com.br'],
        ]);

        $this->artisan('diretor:importar-maiores-segmento', [
            'arquivo' => $arquivo,
            '--somente-observacoes' => true,
        ])->assertSuccessful();

        $this->assertSame('Ampliar mix', $conta->refresh()->observacao);

        @unlink($arquivo);
    }

    public function test_so_observacoes_com_dry_run_nao_grava(): void
    {
        $this->artisan('diretor:importar-maiores-segmento', ['arquivo' => $this->arquivo])->assertSuccessful();

        $arquivo = $this->planilhaDeObservacoes([
            [null, 'PAGUE MENOS', 'CE', 1123, '', 'LEAD', 'Nao deve gravar'],
        ]);

        $this->artisan('diretor:importar-maiores-segmento', [
            'arquivo' => $arquivo,
            '--somente-observacoes' => true,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame('Trabalhar cliente', ContaEstrategica::where('nome', 'PAGUE MENOS')->value('observacao'));

        @unlink($arquivo);
    }
}
