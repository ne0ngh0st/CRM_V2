<?php

namespace Tests\Feature;

use App\Jobs\AtualizarDadosTotvsJob;
use App\Models\TotvsImportacao;
use App\Models\User;
use App\Services\Totvs\AtualizadorTotvs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AtualizacaoDadosTest extends TestCase
{
    use RefreshDatabase;

    private string $diretorio;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'vendedor', 'diretor'] as $papel) {
            Role::findOrCreate($papel);
        }

        // Diretório de relatórios isolado: o serviço calcula a impressão digital em cima
        // dele e grava o marcador ali.
        $this->diretorio = storage_path('framework/testing/totvs-'.uniqid());
        File::ensureDirectoryExists($this->diretorio);
        config(['totvs.diretorio' => $this->diretorio]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diretorio);

        parent::tearDown();
    }

    private function usuario(string $papel): User
    {
        return User::factory()->create()->assignRole($papel);
    }

    private function relatorio(string $nome, string $conteudo = 'x'): void
    {
        File::put($this->diretorio.'/'.$nome, $conteudo);
    }

    // ─── Autorização ────────────────────────────────────────────────────────────

    public function test_admin_ve_a_tela(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->get(route('atualizacoes.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Atualizacoes/Index')
                ->has('frescor')
                ->has('relatorios')
                ->has('rodadas')
                ->where('emAndamento', false));
    }

    public function test_nao_admin_recebe_403_na_tela_e_no_disparo(): void
    {
        foreach (['vendedor', 'diretor'] as $papel) {
            $usuario = $this->usuario($papel);

            $this->actingAs($usuario)->get(route('atualizacoes.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('atualizacoes.disparar'))->assertForbidden();
        }
    }

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get(route('atualizacoes.index'))->assertRedirect(route('login'));
    }

    /**
     * ⚠️ O nome da prop é contrato com o front. `flash` já é compartilhada pelo
     * HandleInertiaRequests e, no Inertia, a prop de página sobrescreve a compartilhada —
     * reusar o nome apagaria `recursoCriadoId` nesta página. Mesmo tipo de colisão que
     * deixou o alternador de visão invisível em 2026-09-03, e que nenhum teste de escopo
     * pegaria.
     */
    public function test_o_aviso_nao_se_chama_flash(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->get(route('atualizacoes.index'))
            ->assertInertia(fn ($p) => $p->has('aviso')->missing('flash.sucesso'));
    }

    // ─── Disparo pela tela ──────────────────────────────────────────────────────

    public function test_botao_enfileira_o_job_com_o_usuario_que_clicou(): void
    {
        Queue::fake();
        $admin = $this->usuario('admin');

        $this->actingAs($admin)->post(route('atualizacoes.disparar'))->assertRedirect();

        Queue::assertPushed(
            AtualizarDadosTotvsJob::class,
            fn ($job) => $job->userId === $admin->id && $job->forcar === false
        );
    }

    /**
     * ⚠️ Defeito real, encontrado só no navegador: se a linha `executando` nascesse no
     * worker, o redirect voltaria com `emAndamento = false`, o acompanhamento automático
     * nunca começaria e a tela diria "nenhuma rodada registrada" logo depois do clique —
     * como se o botão não tivesse feito nada. Nenhum teste de servidor pegaria isso
     * sozinho; este trava o conserto.
     */
    public function test_o_clique_ja_deixa_a_tela_em_modo_de_acompanhamento(): void
    {
        Queue::fake();
        $admin = $this->usuario('admin');

        $this->actingAs($admin)->post(route('atualizacoes.disparar'));

        $this->assertSame(1, TotvsImportacao::where('status', 'executando')->count());

        Queue::assertPushed(
            AtualizarDadosTotvsJob::class,
            fn ($job) => $job->rodadaId === TotvsImportacao::latest('id')->first()->id
        );

        $this->actingAs($admin)->get(route('atualizacoes.index'))
            ->assertInertia(fn ($p) => $p->where('emAndamento', true));
    }

    /**
     * A linha criada pelo controller é REUSADA pelo worker — sem isso cada clique geraria
     * duas entradas no histórico, uma eternamente `executando`.
     */
    public function test_o_worker_reusa_a_linha_criada_pelo_controller(): void
    {
        // ⚠️ `Queue::fake()` é obrigatório aqui: na suíte a fila é `sync`, então sem ele o
        // job roda DENTRO do POST e a chamada manual abaixo viraria uma segunda rodada —
        // que acharia o marcador já gravado e devolveria `sem_mudanca`. O teste mediria a
        // idempotência, não o reuso da linha.
        Queue::fake();
        $this->relatorio('FAT.csv');
        $this->fingirArtisan();
        $admin = $this->usuario('admin');

        $this->actingAs($admin)->post(route('atualizacoes.disparar'));
        $rodada = TotvsImportacao::latest('id')->first();

        (new AtualizarDadosTotvsJob(userId: $admin->id, rodadaId: $rodada->id))
            ->handle(app(AtualizadorTotvs::class));

        $this->assertSame(1, TotvsImportacao::count());
        $this->assertSame('sucesso', $rodada->refresh()->status);
    }

    public function test_forcar_chega_ao_job(): void
    {
        Queue::fake();

        $this->actingAs($this->usuario('admin'))
            ->post(route('atualizacoes.disparar'), ['forcar' => true]);

        Queue::assertPushed(AtualizarDadosTotvsJob::class, fn ($job) => $job->forcar === true);
    }

    public function test_nao_enfileira_quando_ja_existe_rodada_em_andamento(): void
    {
        Queue::fake();

        TotvsImportacao::create([
            'status' => 'executando',
            'origem' => 'agendador',
            'iniciada_em' => now()->subMinute(),
        ]);

        $this->actingAs($this->usuario('admin'))
            ->post(route('atualizacoes.disparar'))
            ->assertSessionHas('erro');

        Queue::assertNothingPushed();
    }

    /**
     * ⚠️ Sem isto, um worker morto no meio (aconteceu em 2026-08-28) deixaria a linha
     * `executando` para sempre e o botão nunca mais liberaria — o usuário ficaria sem
     * nenhuma saída pela interface, que é o oposto do que esta tela existe para fazer.
     */
    public function test_rodada_travada_nao_bloqueia_um_novo_disparo(): void
    {
        Queue::fake();

        TotvsImportacao::create([
            'status' => 'executando',
            'origem' => 'agendador',
            'iniciada_em' => now()->subMinutes(TotvsImportacao::MINUTOS_ATE_CONSIDERAR_TRAVADA + 5),
        ]);

        $this->actingAs($this->usuario('admin'))
            ->post(route('atualizacoes.disparar'))
            ->assertSessionMissing('erro');

        Queue::assertPushed(AtualizarDadosTotvsJob::class);
    }

    public function test_a_tela_mostra_a_travada_como_interrompida_e_nao_como_rodando(): void
    {
        TotvsImportacao::create([
            'status' => 'executando',
            'origem' => 'agendador',
            'iniciada_em' => now()->subMinutes(TotvsImportacao::MINUTOS_ATE_CONSIDERAR_TRAVADA + 5),
        ]);

        $this->actingAs($this->usuario('admin'))
            ->get(route('atualizacoes.index'))
            ->assertInertia(fn ($p) => $p->where('rodadas.0.status', 'travada')
                ->where('emAndamento', false));
    }

    // ─── O serviço ──────────────────────────────────────────────────────────────

    /**
     * ⚠️ A ordem é regra de negócio (clientes primeiro alimenta o ClientesLookup; abertos
     * por último porque o 200 prevalece no empate). Reordenar sem querer não daria erro
     * nenhum — só pedido órfão e pedido faturado sumindo da tela de pendentes.
     */
    public function test_a_ordem_dos_imports_e_a_documentada(): void
    {
        $this->assertSame([
            'totvs:import-clientes',
            'totvs:import-faturamento',
            'totvs:import-pedidos-emitidos',
            'totvs:import-pedidos-abertos',
        ], AtualizadorTotvs::IMPORTS);
    }

    public function test_importa_e_grava_o_marcador_quando_ha_relatorio_novo(): void
    {
        $this->relatorio('FAT.csv');
        $artisan = $this->fingirArtisan();

        $rodada = app(AtualizadorTotvs::class)->executar(origem: 'manual', userId: null);

        $this->assertSame('sucesso', $rodada->status);
        $this->assertSame(
            array_merge(['totvs:sincronizar-s3'], AtualizadorTotvs::IMPORTS),
            $artisan->chamados
        );
        $this->assertFileExists($this->diretorio.'/.ultima-importacao');
        $this->assertNotNull($rodada->concluida_em);
    }

    public function test_segunda_rodada_sem_mudanca_nao_roda_import_nenhum(): void
    {
        $this->relatorio('FAT.csv');
        $artisan = $this->fingirArtisan();
        app(AtualizadorTotvs::class)->executar();

        $artisan->reiniciar();
        $rodada = app(AtualizadorTotvs::class)->executar();

        $this->assertSame('sem_mudanca', $rodada->status);
        $this->assertSame(['totvs:sincronizar-s3'], $artisan->chamados);
    }

    public function test_relatorio_alterado_volta_a_disparar_os_imports(): void
    {
        $this->relatorio('FAT.csv', 'primeiro');
        $artisan = $this->fingirArtisan();
        app(AtualizadorTotvs::class)->executar();

        // Conteúdo diferente => tamanho diferente => impressão digital diferente.
        $this->relatorio('FAT.csv', 'conteudo bem maior que o primeiro');
        $artisan->reiniciar();
        $rodada = app(AtualizadorTotvs::class)->executar();

        $this->assertSame('sucesso', $rodada->status);
        $this->assertContains('totvs:import-clientes', $artisan->chamados);
    }

    public function test_forcar_importa_mesmo_sem_mudanca(): void
    {
        $this->relatorio('FAT.csv');
        $artisan = $this->fingirArtisan();
        app(AtualizadorTotvs::class)->executar();

        $artisan->reiniciar();
        $rodada = app(AtualizadorTotvs::class)->executar(forcar: true);

        $this->assertSame('sucesso', $rodada->status);
        $this->assertContains('totvs:import-pedidos-abertos', $artisan->chamados);
    }

    /**
     * ⚠️ O teste mais importante daqui. Falha no meio tem que (a) parar a corrente, para
     * não rodar o 200 sobre um 232 que falhou, e (b) NÃO gravar o marcador, senão a
     * rodada seguinte veria "nada mudou" e o dado congelaria em silêncio — sem nenhum
     * erro novo aparecendo em lugar nenhum.
     */
    public function test_falha_no_meio_interrompe_a_corrente_e_nao_grava_o_marcador(): void
    {
        $this->relatorio('FAT.csv');
        $artisan = $this->fingirArtisan('totvs:import-faturamento');

        $rodada = app(AtualizadorTotvs::class)->executar();

        $this->assertSame('falha', $rodada->status);
        $this->assertStringContainsString('totvs:import-faturamento', (string) $rodada->erro);
        $this->assertSame(
            ['totvs:sincronizar-s3', 'totvs:import-clientes', 'totvs:import-faturamento'],
            $artisan->chamados,
            'Os imports seguintes não podiam ter rodado.'
        );
        $this->assertFileDoesNotExist($this->diretorio.'/.ultima-importacao');
    }

    public function test_depois_de_falhar_a_rodada_seguinte_tenta_de_novo(): void
    {
        $this->relatorio('FAT.csv');
        $artisan = $this->fingirArtisan('totvs:import-clientes');
        app(AtualizadorTotvs::class)->executar();

        // Nada mudou no S3, mas a importação anterior não completou: o marcador não foi
        // gravado, então a rodada seguinte tem que tentar de novo em vez de pular.
        $artisan->reiniciar();
        $rodada = app(AtualizadorTotvs::class)->executar();

        $this->assertSame('sucesso', $rodada->status);
        $this->assertContains('totvs:import-clientes', $artisan->chamados);
    }

    public function test_falha_do_sync_nao_importa_nada(): void
    {
        $this->relatorio('FAT.csv');
        $artisan = $this->fingirArtisan('totvs:sincronizar-s3');

        $rodada = app(AtualizadorTotvs::class)->executar();

        $this->assertSame('falha', $rodada->status);
        $this->assertSame(['totvs:sincronizar-s3'], $artisan->chamados);
    }

    public function test_a_rodada_registra_quem_disparou(): void
    {
        $this->relatorio('FAT.csv');
        $this->fingirArtisan();
        $admin = $this->usuario('admin');

        $rodada = app(AtualizadorTotvs::class)->executar(origem: 'manual', userId: $admin->id);

        $this->assertSame('manual', $rodada->origem);
        $this->assertSame($admin->id, $rodada->user_id);

        $this->actingAs($admin)->get(route('atualizacoes.index'))
            ->assertInertia(fn ($p) => $p->where('rodadas.0.origem', 'manual')
                ->where('rodadas.0.quem', $admin->display_name));
    }

    /**
     * ⚠️ A contagem de linhas é cacheada porque `COUNT(*)` em `faturamentos` custa 943 ms
     * em produção. A importação tem que RECALCULAR (não só esquecer): a tela é aberta
     * justamente para conferir se funcionou, então mostraria o número velho no pior
     * momento possível — e só invalidar empurraria os 943 ms para o request de quem
     * abrisse a página, em vez de pagá-los dentro do job.
     */
    public function test_importacao_bem_sucedida_recalcula_a_contagem_cacheada(): void
    {
        $this->relatorio('FAT.csv');
        $this->fingirArtisan();
        $velho = ['faturamentos' => 999999, 'pedidos' => 999999];
        Cache::put(AtualizadorTotvs::CHAVE_CACHE_CONTAGENS, $velho, 600);

        app(AtualizadorTotvs::class)->executar();

        $agora = Cache::get(AtualizadorTotvs::CHAVE_CACHE_CONTAGENS);
        $this->assertNotNull($agora, 'A contagem tem que ficar quente, não sumir.');
        $this->assertNotSame($velho, $agora);
        $this->assertSame(0, $agora['faturamentos'], 'A tabela está vazia neste teste.');
    }

    public function test_rodada_que_falha_nao_mexe_na_contagem(): void
    {
        $this->relatorio('FAT.csv');
        $this->fingirArtisan('totvs:import-clientes');
        $velho = ['faturamentos' => 999999, 'pedidos' => 999999];
        Cache::put(AtualizadorTotvs::CHAVE_CACHE_CONTAGENS, $velho, 600);

        app(AtualizadorTotvs::class)->executar();

        $this->assertSame($velho, Cache::get(AtualizadorTotvs::CHAVE_CACHE_CONTAGENS));
    }

    /**
     * Substitui o `Artisan::call` do serviço: registra a ordem real das chamadas e
     * devolve 1 no comando marcado como falho (0 nos demais).
     *
     * ⚠️ CHAMAR UMA VEZ POR TESTE, e usar `reiniciar()` entre as fases. Um segundo
     * `Artisan::shouldReceive('call')` no mesmo teste ACUMULA expectativas em vez de
     * substituir a anterior — a primeira continua respondendo, e o teste passa a medir o
     * mock em vez do serviço. Foi o que quebrou exatamente os 4 testes de duas fases na
     * primeira versão, enquanto todos os de fase única passavam.
     */
    private function fingirArtisan(?string $falha = null): object
    {
        $fake = new class
        {
            /** @var list<string> */
            public array $chamados = [];

            public ?string $falha = null;

            public function reiniciar(?string $falha = null): void
            {
                $this->chamados = [];
                $this->falha = $falha;
            }
        };

        $fake->falha = $falha;

        Artisan::shouldReceive('call')
            ->andReturnUsing(function (string $comando) use ($fake) {
                $fake->chamados[] = $comando;

                return $comando === $fake->falha ? 1 : 0;
            });

        return $fake;
    }
}
