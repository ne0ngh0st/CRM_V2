<?php

namespace Tests\Feature\VisaoDiretor;

use App\Http\Controllers\SimulacaoController;
use App\Models\ContaEstrategica;
use App\Models\Segmento;
use App\Models\User;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Histórico da observação das contas-alvo (Visão Diretor).
 *
 * O que protege: toda mudança do texto vira versão — pela tela (criar, editar, apagar) e
 * pela carga da planilha —, salvar SEM mudar o texto não gera versão, e a autoria é da
 * pessoa, não do guard (simulação).
 */
class HistoricoObservacaoContaTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $segmento;

    private User $diretor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->segmento = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        $this->diretor = $this->usuario('diretor');
    }

    private function usuario(string $papel): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($papel);

        return $user;
    }

    private function salvar(ContaEstrategica $conta, ?string $observacao, ?User $como = null): void
    {
        $this->actingAs($como ?? $this->diretor)
            ->patch(route('visao-diretor.maiores.update', $conta), [
                'segmento_id' => $this->segmento->id,
                'nome' => $conta->nome,
                'observacao' => $observacao,
                'vinculos' => [],
            ])
            ->assertSessionHasNoErrors();
    }

    private function historico(ContaEstrategica $conta): array
    {
        return $this->actingAs($this->diretor)
            ->getJson(route('visao-diretor.maiores.observacoes', $conta))
            ->assertOk()
            ->json();
    }

    public function test_criar_pela_tela_registra_a_primeira_versao_com_autor(): void
    {
        $this->actingAs($this->diretor)
            ->post(route('visao-diretor.maiores.store'), [
                'segmento_id' => $this->segmento->id,
                'nome' => 'RAIA',
                'observacao' => 'Trabalhar a matriz',
            ])
            ->assertSessionHasNoErrors();

        $versoes = $this->historico(ContaEstrategica::firstWhere('nome', 'RAIA'));

        $this->assertCount(1, $versoes);
        $this->assertSame('Trabalhar a matriz', $versoes[0]['texto']);
        $this->assertSame($this->diretor->display_name ?: $this->diretor->name, $versoes[0]['autor']);
    }

    public function test_conta_criada_sem_observacao_nao_tem_versao(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA']);

        $this->assertSame([], $this->historico($conta));
    }

    public function test_cada_edicao_vira_versao_e_a_mais_recente_vem_primeiro(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA', 'observacao' => 'v1']);

        $this->salvar($conta, 'v2');
        $this->salvar($conta->fresh(), 'v3');

        $this->assertSame(['v3', 'v2', 'v1'], array_column($this->historico($conta), 'texto'));
        $this->assertSame('v3', $conta->fresh()->observacao);
    }

    public function test_salvar_sem_mudar_o_texto_nao_gera_versao(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA', 'observacao' => 'v1']);

        // Editar outro campo (ou reabrir e salvar) não é uma observação nova.
        $this->salvar($conta, 'v1');

        $this->assertCount(1, $this->historico($conta));
    }

    public function test_apagar_a_observacao_fica_registrado(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA', 'observacao' => 'v1']);

        $this->salvar($conta, '');

        $versoes = $this->historico($conta);
        $this->assertCount(2, $versoes);
        $this->assertNull($versoes[0]['texto']);
        $this->assertSame('v1', $versoes[1]['texto']);
    }

    public function test_sem_usuario_a_versao_e_da_planilha(): void
    {
        // Caminho do `diretor:importar-maiores-segmento`: sem ninguém autenticado.
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA', 'observacao' => 'da planilha']);

        $this->assertSame('Planilha da diretoria', $this->historico($conta)[0]['autor']);
    }

    public function test_na_simulacao_o_autor_e_o_admin_e_nao_o_alvo(): void
    {
        $admin = $this->usuario('admin');
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA']);

        $this->actingAs($admin)->post(route('simulacao.iniciar', $this->diretor->id));
        $this->assertSame($admin->id, session(SimulacaoController::SESSAO_ADMIN_ID));

        $this->salvar($conta, 'escrito pelo admin simulando', $this->diretor);

        $this->assertDatabaseHas('conta_estrategica_observacoes', [
            'conta_id' => $conta->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_a_linha_da_tabela_traz_a_contagem_de_versoes(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA', 'observacao' => 'v1']);
        $this->salvar($conta, 'v2');

        $linha = app(MaioresPorSegmentoResolver::class)->linhas()->firstWhere('id', $conta->id);

        $this->assertSame(2, $linha['versoesObservacao']);
    }

    public function test_excluir_a_conta_leva_o_historico_junto(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->segmento->id, 'nome' => 'RAIA', 'observacao' => 'v1']);

        $this->actingAs($this->diretor)->delete(route('visao-diretor.maiores.destroy', $conta));

        $this->assertDatabaseMissing('conta_estrategica_observacoes', ['conta_id' => $conta->id]);
    }
}
