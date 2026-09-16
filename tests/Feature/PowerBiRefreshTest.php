<?php

namespace Tests\Feature;

use App\Jobs\AtualizarPowerBiJob;
use App\Models\TotvsImportacao;
use App\Services\PowerBi\PowerBiIndisponivelException;
use App\Services\PowerBi\PowerBiRefresher;
use App\Services\PowerBi\TravaDeRefreshPowerBi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Refresh do Power BI depois da importação: a chamada à API e a trava de cota do Pro.
 *
 * ⚠️ A trava é o que importa aqui. Passar do limite não dá erro no CRM — o Serviço
 * simplesmente passa a recusar, inclusive o refresh manual que alguém tente pelo portal.
 */
class PowerBiRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.powerbi.com/v1.0/myorg/groups/ws-1/datasets/ds-1/refreshes';

    protected function setUp(): void
    {
        parent::setUp();

        config(['powerbi.refresh' => [
            'habilitado' => true,
            'tenant_id' => 'tenant-1',
            'client_id' => 'cliente-1',
            'client_secret' => 'segredo-que-nao-pode-vazar',
            'workspace_id' => 'ws-1',
            'dataset_id' => 'ds-1',
            'timeout' => 5,
            'max_por_dia' => 6,
            'intervalo_minutos' => 90,
        ]]);

        $this->travelTo(now()->setTime(8, 0));
    }

    private function fingirPowerBi(int $status = 202, array $corpo = []): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3599]),
            self::API => Http::response($corpo, $status, ['RequestId' => 'req-42']),
        ]);
    }

    private function rodada(): TotvsImportacao
    {
        return TotvsImportacao::create([
            'status' => 'sucesso',
            'origem' => 'agendador',
            'iniciada_em' => now(),
            'concluida_em' => now(),
            'passos' => [['comando' => 'totvs:import-clientes', 'segundos' => 1.0, 'falhou' => false, 'saida' => 'ok']],
        ]);
    }

    private function executar(TotvsImportacao $rodada, ?int $agendadoEm = null): void
    {
        (new AtualizarPowerBiJob($rodada->id, $agendadoEm))
            ->handle(app(PowerBiRefresher::class), app(TravaDeRefreshPowerBi::class));
    }

    /** @return array{falhou: bool, saida: string} */
    private function passo(TotvsImportacao $rodada): array
    {
        $passos = collect($rodada->fresh()->passos)->where('comando', AtualizarPowerBiJob::PASSO)->values();
        $this->assertCount(1, $passos, 'um passo powerbi:refresh, nem zero nem repetido');

        return $passos[0];
    }

    private function disparosNaApi(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.powerbi.com'))->count();
    }

    // ─── A chamada ───────────────────────────────────────────────────────────────

    public function test_pede_token_do_service_principal_e_dispara_o_refresh(): void
    {
        $this->fingirPowerBi();
        $rodada = $this->rodada();

        $this->executar($rodada);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token'
            && $r['grant_type'] === 'client_credentials'
            && $r['client_id'] === 'cliente-1'
            && $r['scope'] === 'https://analysis.windows.net/powerbi/api/.default');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://'.self::API
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer tok-abc')
            && $r['notifyOption'] === 'NoNotification');

        $passo = $this->passo($rodada);
        $this->assertFalse($passo['falhou']);
        $this->assertStringContainsString('1/6', $passo['saida']);
        $this->assertStringContainsString('req-42', $passo['saida']);
        $this->assertSame(1, app(TravaDeRefreshPowerBi::class)->disparosNasUltimas24h());

        // O passo substitui o "na fila", não apaga os passos da importação.
        $this->assertSame('totvs:import-clientes', $rodada->fresh()->passos[0]['comando']);
    }

    public function test_token_e_reaproveitado_entre_disparos(): void
    {
        // O token vale ~58 min e a trava pede 90 entre disparos: para caber dois disparos
        // na validade de um token, o intervalo precisa sair do caminho neste teste.
        config(['powerbi.refresh.intervalo_minutos' => 0]);
        $this->fingirPowerBi();

        $this->executar($this->rodada());
        $this->travel(10)->minutes();
        $this->executar($this->rodada());

        $this->assertSame(2, $this->disparosNaApi());
        Http::assertSentCount(3); // 1 token + 2 refreshes
    }

    public function test_token_vencido_e_pedido_de_novo(): void
    {
        $this->fingirPowerBi();

        $this->executar($this->rodada());
        $this->travel(2)->hours();
        $this->executar($this->rodada());

        Http::assertSentCount(4); // 2 tokens + 2 refreshes
    }

    public function test_recusa_vira_passo_vermelho_sem_retentar_nem_gastar_cota(): void
    {
        $this->fingirPowerBi(403, ['error' => ['code' => 'PowerBINotAuthorizedException', 'message' => 'Unauthorized']]);
        $rodada = $this->rodada();

        $this->executar($rodada); // não lança: recusa não retenta

        $passo = $this->passo($rodada);
        $this->assertTrue($passo['falhou']);
        $this->assertStringContainsString('HTTP 403', $passo['saida']);
        $this->assertSame(0, app(TravaDeRefreshPowerBi::class)->disparosNasUltimas24h());
        $this->assertSame('sucesso', $rodada->fresh()->status, 'a importação continua bem-sucedida');
    }

    /** A mensagem vai para a tela; o corpo da chamada do token leva o segredo. */
    public function test_credencial_recusada_nao_vaza_o_segredo(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => 'AADSTS7000215: Invalid client secret provided.',
            ], 401),
        ]);
        $rodada = $this->rodada();

        $this->executar($rodada);

        $passo = $this->passo($rodada);
        $this->assertTrue($passo['falhou']);
        $this->assertStringContainsString('AADSTS7000215', $passo['saida']);
        $this->assertStringNotContainsString('segredo-que-nao-pode-vazar', json_encode($rodada->fresh()->passos));
    }

    public function test_indisponibilidade_retenta_e_a_ultima_falha_fica_registrada(): void
    {
        $this->fingirPowerBi(503);
        $rodada = $this->rodada();

        try {
            $this->executar($rodada);
            $this->fail('503 tem que lançar para a fila retentar.');
        } catch (PowerBiIndisponivelException $e) {
            (new AtualizarPowerBiJob($rodada->id))->failed($e);
        }

        $this->assertTrue($this->passo($rodada)['falhou']);
        $this->assertSame(0, app(TravaDeRefreshPowerBi::class)->disparosNasUltimas24h());
    }

    public function test_configuracao_incompleta_e_recusada_antes_de_chamar_a_microsoft(): void
    {
        Http::fake();
        config(['powerbi.refresh.dataset_id' => '']);
        $rodada = $this->rodada();

        $this->executar($rodada);

        Http::assertNothingSent();
        $this->assertStringContainsString('dataset_id', $this->passo($rodada)['saida']);
    }

    public function test_desligado_nao_chama_nada(): void
    {
        Http::fake();
        config(['powerbi.refresh.habilitado' => false]);
        $rodada = $this->rodada();

        $this->executar($rodada);

        Http::assertNothingSent();
        $this->assertStringContainsString('desligado', $this->passo($rodada)['saida']);
    }

    // ─── A trava ─────────────────────────────────────────────────────────────────

    public function test_segundo_disparo_antes_de_90_minutos_e_adiado_para_a_janela(): void
    {
        $this->fingirPowerBi();
        $this->executar($this->rodada()); // 08:00

        Queue::fake();
        $this->travel(30)->minutes(); // 08:30
        $rodada = $this->rodada();
        $this->executar($rodada);

        $this->assertSame(1, $this->disparosNaApi(), 'o segundo não chegou à API');
        $this->assertStringContainsString('Adiado para', $this->passo($rodada)['saida']);
        $this->assertStringContainsString('09:30', $this->passo($rodada)['saida']);

        Queue::assertPushed(AtualizarPowerBiJob::class, fn ($job) => $job->rodadaId === $rodada->id
            && $job->agendadoEm === now()->getTimestamp()
            && $job->delay->equalTo(now()->setTime(9, 30)));
    }

    /**
     * Três importações num intervalo curto não podem virar três refreshes na fila: o
     * primeiro adiado já vai levar os dados de todas.
     */
    public function test_disparos_barrados_nao_empilham_refreshes_adiados(): void
    {
        $this->fingirPowerBi();
        $this->executar($this->rodada()); // 08:00

        Queue::fake();
        $this->travel(20)->minutes();
        $this->executar($this->rodada());
        $this->travel(20)->minutes();
        $terceira = $this->rodada();
        $this->executar($terceira);

        Queue::assertPushed(AtualizarPowerBiJob::class, 1);
        $this->assertStringContainsString('Já existe um refresh agendado para', $this->passo($terceira)['saida']);
    }

    public function test_setimo_disparo_em_24_horas_espera_o_mais_antigo_sair_da_janela(): void
    {
        $this->fingirPowerBi();

        foreach (range(1, 6) as $i) {
            $this->executar($this->rodada());
            $this->travel(2)->hours(); // 08:00, 10:00, ..., 18:00; agora 20:00
        }

        Queue::fake();
        $setima = $this->rodada();
        $this->executar($setima);

        $this->assertSame(6, $this->disparosNaApi());
        $this->assertStringContainsString('6/6', $this->passo($setima)['saida']);
        // O disparo das 08:00 de hoje sai da janela às 08:00 de amanhã.
        Queue::assertPushed(AtualizarPowerBiJob::class, fn ($job) => $job->delay->equalTo(now()->addDay()->setTime(8, 0)));
    }

    public function test_depois_de_24_horas_a_cota_volta(): void
    {
        $this->fingirPowerBi();

        foreach (range(1, 6) as $i) {
            $this->executar($this->rodada());
            $this->travel(2)->hours();
        }

        $this->travelTo(now()->addDay()->setTime(8, 1));
        $rodada = $this->rodada();
        $this->executar($rodada);

        $this->assertSame(7, $this->disparosNaApi());
        $this->assertFalse($this->passo($rodada)['falhou']);
    }

    public function test_job_adiado_que_acorda_com_a_janela_aberta_dispara(): void
    {
        $this->fingirPowerBi();
        $this->executar($this->rodada()); // 08:00

        Queue::fake();
        $this->travel(30)->minutes();
        $rodada = $this->rodada();
        $this->executar($rodada);
        $agendado = Queue::pushed(AtualizarPowerBiJob::class)->first();

        $this->travelTo(now()->setTime(9, 30));
        $this->executar($rodada, $agendado->agendadoEm);

        $this->assertSame(2, $this->disparosNaApi());
        $this->assertStringContainsString('Refresh pedido', $this->passo($rodada)['saida']);
        $this->assertNull(app(TravaDeRefreshPowerBi::class)->pendentePara(), 'a pendência some depois do disparo');
    }

    /** Se outro disparo aconteceu depois de o job ser adiado, os dados já foram — não gasta cota à toa. */
    public function test_job_adiado_coberto_por_disparo_posterior_nao_chama_a_api(): void
    {
        $this->fingirPowerBi();
        $rodada = $this->rodada();
        $agendadoEm = now()->getTimestamp(); // 08:00

        $this->travel(10)->minutes();
        $this->executar($this->rodada()); // 08:10: disparo normal, depois do agendamento

        $this->travel(2)->hours();
        $this->executar($rodada, $agendadoEm);

        $this->assertSame(1, $this->disparosNaApi());
        $this->assertStringContainsString('Coberto pelo refresh das 08:10', $this->passo($rodada)['saida']);
    }
}
