<?php

namespace Tests\Feature;

use App\Jobs\GerarExportacaoCarteiraJob;
use App\Models\Cliente;
use App\Models\Exportacao;
use App\Models\Notificacao;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exportação assíncrona da Carteira.
 *
 * O export completo leva ~95 s contra os 60 s de idle timeout do ALB — em produção seria
 * 504 garantido. Estes testes cobrem o caminho novo e, principalmente, o controle de
 * acesso: o arquivo contém a carteira inteira de alguém.
 */
class ExportacaoCarteiraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    private function usuario(string $role = 'admin'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    public function test_pedido_de_exportacao_enfileira_e_nao_devolve_arquivo(): void
    {
        Queue::fake();
        $user = $this->usuario();

        $this->actingAs($user)
            ->post(route('carteira.exportar'), ['estado' => 'SP'])
            ->assertRedirect();

        Queue::assertPushed(GerarExportacaoCarteiraJob::class);

        $exportacao = Exportacao::sole();
        $this->assertSame($user->id, $exportacao->user_id);
        $this->assertSame(Exportacao::STATUS_PROCESSANDO, $exportacao->status);
        // Os filtros ficam guardados: é o que deixa o arquivo auditável depois.
        $this->assertSame('SP', $exportacao->filtros['estado']);
    }

    public function test_job_gera_arquivo_e_notifica_o_dono(): void
    {
        $user = $this->usuario();
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '000123']);

        // Cliente não tem factory (é tabela espelho do TOTVS, populada por import).
        foreach (range(1, 3) as $i) {
            Cliente::create([
                'cod_cliente' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'loja' => '01',
                'razao_social' => "Cliente Teste {$i}",
                'cod_vendedor' => '000123',
            ]);
        }

        $exportacao = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'filtros' => [],
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);

        (new GerarExportacaoCarteiraJob($exportacao->id))->handle(
            app(\App\Services\Notificacao\NotificacaoService::class),
            app(\App\Services\Carteira\ClienteStatusResolver::class),
        );

        $exportacao->refresh();
        $this->assertSame(Exportacao::STATUS_PRONTO, $exportacao->status, "Erro: {$exportacao->erro}");
        $this->assertNotNull($exportacao->caminho);
        Storage::disk('local')->assertExists($exportacao->caminho);

        // Sem a notificação o usuário não teria como saber que o arquivo ficou pronto.
        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $user->id,
            'tipo' => 'exportacao_pronta',
        ]);
    }

    public function test_download_funciona_para_o_dono(): void
    {
        $user = $this->usuario();
        $exportacao = $this->exportacaoPronta($user);

        $this->actingAs($user)
            ->get(route('exportacoes.download', $exportacao))
            ->assertOk();
    }

    public function test_ninguem_baixa_a_exportacao_de_outro_nem_sendo_admin(): void
    {
        $dono = $this->usuario('vendedor');
        $outroAdmin = $this->usuario('admin');
        $exportacao = $this->exportacaoPronta($dono);

        /*
         * O id é sequencial e aparece na URL da notificação: sem esta checagem, trocar o
         * número na barra de endereço entregaria a carteira inteira de outra pessoa.
         * Nem admin passa — se precisar dos dados, gera a própria exportação.
         */
        $this->actingAs($outroAdmin)
            ->get(route('exportacoes.download', $exportacao))
            ->assertForbidden();
    }

    public function test_exportacao_expirada_nao_e_baixavel(): void
    {
        $user = $this->usuario();
        $exportacao = $this->exportacaoPronta($user);
        $exportacao->update(['expira_em' => now()->subDay()]);

        $this->actingAs($user)
            ->get(route('exportacoes.download', $exportacao))
            ->assertNotFound();
    }

    public function test_exportacao_ainda_processando_nao_e_baixavel(): void
    {
        $user = $this->usuario();
        $exportacao = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);

        $this->actingAs($user)
            ->get(route('exportacoes.download', $exportacao))
            ->assertNotFound();
    }

    public function test_falha_no_job_marca_erro_e_avisa_em_vez_de_silenciar(): void
    {
        $user = $this->usuario();
        $exportacao = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);

        // Simula o job morrendo sem passar pelo catch (timeout, OOM do worker).
        (new GerarExportacaoCarteiraJob($exportacao->id))->failed(new \RuntimeException('estourou'));

        $exportacao->refresh();
        $this->assertSame(Exportacao::STATUS_ERRO, $exportacao->status);
        $this->assertStringContainsString('estourou', $exportacao->erro);
    }

    private function exportacaoPronta(User $user): Exportacao
    {
        $caminho = 'exports/teste/carteira.xlsx';
        Storage::disk('local')->put($caminho, 'conteudo');

        return Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'status' => Exportacao::STATUS_PRONTO,
            'caminho' => $caminho,
            'nome_arquivo' => 'carteira.xlsx',
            'linhas' => 3,
            'expira_em' => now()->addDays(7),
        ]);
    }
}
