<?php

namespace Tests\Feature;

use App\Jobs\GerarExportacaoJob;
use App\Models\Cliente;
use App\Models\Exportacao;
use App\Models\Notificacao;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Escopo\ModoVisao;
use App\Services\Exportacao\GeradorDeExportacao;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Central de downloads: toda planilha registra, e o VOLUME escolhe o caminho.
 *
 * O que estes testes protegem, em ordem de importância:
 *  1. o controle de acesso (o arquivo contém a carteira inteira de alguém);
 *  2. a escolha síncrono/fila pelo volume, que é o que mantém a Regra de ouro nº 9;
 *  3. o escopo atravessando a fila — inclusive o modo de visão do supervisor, que a
 *     versão anterior perdia em silêncio.
 */
class CentralDeDownloadsTest extends TestCase
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

    private function clientes(int $quantos, string $codVendedor = '000123'): void
    {
        // Cliente não tem factory (é tabela espelho do TOTVS, populada por import).
        foreach (range(1, $quantos) as $i) {
            Cliente::create([
                'cod_cliente' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'loja' => '01',
                'razao_social' => "Cliente Teste {$i}",
                'cod_vendedor' => $codVendedor,
            ]);
        }
    }

    // ---------------------------------------------------------------- volume decide

    public function test_volume_pequeno_gera_na_hora_e_devolve_o_link(): void
    {
        Queue::fake();
        $user = $this->usuario();
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '000123']);
        $this->clientes(3);

        $resposta = $this->actingAs($user)->post(route('carteira.exportar'));

        // Nada foi para a fila: 3 linhas cabem folgadamente no orçamento da requisição.
        Queue::assertNotPushed(GerarExportacaoJob::class);

        $exportacao = Exportacao::sole();
        $this->assertSame(Exportacao::STATUS_PRONTO, $exportacao->status, "Erro: {$exportacao->erro}");
        Storage::disk('local')->assertExists($exportacao->caminho);

        $resposta->assertSessionHas('exportacao', fn ($flash) => $flash['estado'] === 'pronta'
            && $flash['url'] === route('exportacoes.download', $exportacao->id, false));
    }

    public function test_volume_grande_vai_para_a_fila_em_vez_de_travar_a_requisicao(): void
    {
        Queue::fake();
        $user = $this->usuario();
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '000123']);
        $this->clientes(3);

        // O corte real são 5.000 linhas; aqui ele é baixado para não precisar semear 5 mil
        // clientes só para exercitar a decisão.
        config(['exportacoes.limite_linhas_sincrono' => 2]);

        $resposta = $this->actingAs($user)->post(route('carteira.exportar'));

        Queue::assertPushed(GerarExportacaoJob::class);

        $exportacao = Exportacao::sole();
        $this->assertTrue($exportacao->processando());
        $this->assertNull($exportacao->caminho, 'A requisição não pode ter gerado o arquivo.');
        $resposta->assertSessionHas('exportacao', fn ($flash) => $flash['estado'] === 'enfileirada');
    }

    /**
     * ⚠️ Este é o teste que trava a Regra de ouro nº 9 nesta feature. Sem ele, alguém
     * "simplifica" o gerador para sempre gerar na hora e a Tabela de Preços volta a
     * travar a aba por 19 s — sem erro nenhum, só lentidão que ninguém liga à mudança.
     */
    public function test_o_corte_e_por_linhas_e_nao_por_recurso(): void
    {
        Queue::fake();
        $user = $this->usuario();
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '000123']);
        $this->clientes(3);

        config(['exportacoes.limite_linhas_sincrono' => 3]);
        $this->actingAs($user)->post(route('carteira.exportar'));
        Queue::assertNotPushed(GerarExportacaoJob::class); // 3 <= 3

        Exportacao::query()->delete();

        config(['exportacoes.limite_linhas_sincrono' => 2]);
        $this->actingAs($user)->post(route('carteira.exportar'));
        Queue::assertPushed(GerarExportacaoJob::class); // 3 > 2
    }

    public function test_caminho_sincrono_nao_notifica(): void
    {
        $user = $this->usuario();
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '000123']);
        $this->clientes(2);

        $this->actingAs($user)->post(route('carteira.exportar'));

        /*
         * O arquivo já desceu no navegador: um sino dizendo "está pronto aquilo que você
         * acabou de baixar" é ruído, e ruído é o que faz as pessoas pararem de olhar o sino.
         */
        $this->assertDatabaseCount('notificacoes', 0);
    }

    // ---------------------------------------------------------------- fila

    public function test_job_gera_arquivo_e_notifica_o_dono(): void
    {
        $user = $this->usuario();
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '000123']);
        $this->clientes(3);

        $exportacao = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'filtros' => [],
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);

        (new GerarExportacaoJob($exportacao->id))->handle(app(GeradorDeExportacao::class));

        $exportacao->refresh();
        $this->assertSame(Exportacao::STATUS_PRONTO, $exportacao->status, "Erro: {$exportacao->erro}");
        Storage::disk('local')->assertExists($exportacao->caminho);
        $this->assertSame(3, $exportacao->linhas);
        $this->assertGreaterThan(0, $exportacao->bytes, 'O tamanho do arquivo alimenta a listagem.');

        // Sem a notificação o usuário não teria como saber que o arquivo ficou pronto.
        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $user->id,
            'tipo' => 'exportacao_pronta',
        ]);
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
        (new GerarExportacaoJob($exportacao->id))->failed(new \RuntimeException('estourou'));

        $exportacao->refresh();
        $this->assertSame(Exportacao::STATUS_ERRO, $exportacao->status);
        $this->assertStringContainsString('estourou', $exportacao->erro);
        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $user->id,
            'tipo' => 'exportacao_erro',
        ]);
    }

    /**
     * 🔴 O bug que existia antes desta rodada, e o motivo de `modo_visao` ser coluna.
     *
     * O supervisor da Autopel também vende. Em "Minha carteira" a tela mostra só os
     * clientes dele; o job, que roda sem sessão, resolvia o modo como EQUIPE e gerava a
     * planilha da equipe inteira — escopo mais amplo que o da tela, sem erro nenhum.
     */
    public function test_modo_de_visao_do_supervisor_atravessa_a_fila(): void
    {
        $supervisor = $this->usuario('supervisor');
        VendedorPerfil::create(['user_id' => $supervisor->id, 'cod_vendedor' => '000900']);

        $vendedor = $this->usuario('vendedor');
        VendedorPerfil::create([
            'user_id' => $vendedor->id,
            'cod_vendedor' => '000901',
            'cod_super' => '000900',
        ]);

        $this->clientes(2, '000900');            // carteira pessoal do supervisor
        Cliente::create([
            'cod_cliente' => '000999',
            'loja' => '01',
            'razao_social' => 'Cliente do vendedor',
            'cod_vendedor' => '000901',
        ]);

        // Pede a planilha em "Minha carteira".
        $this->actingAs($supervisor)
            ->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::PESSOAL])
            ->post(route('carteira.exportar'));

        $exportacao = Exportacao::sole();
        $this->assertSame(ModoVisao::PESSOAL, $exportacao->modo_visao);
        $this->assertSame(2, $exportacao->linhas, 'Modo pessoal: só a carteira do próprio supervisor.');

        /*
         * ⚠️ A LIMPEZA DA SESSÃO É O CORAÇÃO DESTE TESTE, não preparação de rotina.
         * Sem ela, o job roda no mesmo processo do teste e enxerga a sessão que a
         * requisição acima deixou no container — o modo continua PESSOAL por acidente e o
         * teste passa mesmo com o bug. Descoberto por mutação: removida a restauração do
         * modo no gerador, a primeira versão deste teste seguia verde.
         *
         * Sessão vazia é exatamente o que o worker tem.
         */
        session()->flush();

        // E o job, sem sessão nenhuma, tem de chegar ao mesmo número.
        $daFila = Exportacao::create([
            'user_id' => $supervisor->id,
            'recurso' => 'carteira',
            'filtros' => [],
            'modo_visao' => ModoVisao::PESSOAL,
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);

        (new GerarExportacaoJob($daFila->id))->handle(app(GeradorDeExportacao::class));

        $this->assertSame(2, $daFila->refresh()->linhas, 'O job perdeu o modo de visão e ampliou o escopo.');
    }

    // ---------------------------------------------------------------- download

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

    // ------------------------------------------------- o sino (fix de 2026-09-08)

    /*
     * Os três casos abaixo vieram de `ExportacaoCarteiraTest`, que esta classe substituiu.
     * Eles cobrem o fix do sino: o clique numa notificação de planilha precisa ENTREGAR o
     * arquivo ao navegador, e não abrir pelo Inertia (que descartava o .xlsx em silêncio).
     *
     * ⚠️ Continuam valendo depois da central de downloads, e são complementares a ela: a
     * central é onde se REENCONTRA a planilha; o sino é o atalho de quem está com ela na
     * frente. Se um dia `exportacao_pronta` sair de `Notificacao::TIPOS_DOWNLOAD`, o
     * clique volta a não baixar nada — e é este teste que acusa.
     */

    public function test_notificacao_de_planilha_e_marcada_como_download(): void
    {
        $user = $this->usuario();
        $exportacao = $this->exportacaoPronta($user);

        Notificacao::create([
            'user_id' => $user->id,
            'tipo' => 'exportacao_pronta',
            'titulo' => 'Planilha da Carteira pronta',
            'link' => route('exportacoes.download', $exportacao->id, false),
        ]);

        $payload = $this->actingAs($user)
            ->getJson(route('notificacoes.index'))
            ->assertOk()
            ->json('naoLidas.0');

        $this->assertTrue($payload['download']);
        $this->assertSame("/exportacoes/{$exportacao->id}/download", $payload['link']);
    }

    /** A contraprova: notificação que aponta pra uma página continua abrindo pelo Inertia. */
    public function test_notificacao_de_pagina_nao_e_marcada_como_download(): void
    {
        $user = $this->usuario();

        Notificacao::create([
            'user_id' => $user->id,
            'tipo' => 'orcamento_pendente',
            'titulo' => 'Orçamento aguardando aprovação',
            'link' => route('orcamentos.index', [], false),
        ]);

        $payload = $this->actingAs($user)
            ->getJson(route('notificacoes.index'))
            ->assertOk()
            ->json('naoLidas.0');

        $this->assertFalse($payload['download']);
    }

    /**
     * O sino monta uma lista só com duas fontes — o GET do histórico e o broadcast do
     * Reverb. Se os payloads divergirem, a mesma notificação se comporta diferente
     * conforme tenha chegado ao vivo ou depois de um F5.
     */
    public function test_broadcast_em_tempo_real_leva_o_mesmo_payload_do_historico(): void
    {
        $user = $this->usuario();
        $exportacao = $this->exportacaoPronta($user);

        $notificacao = Notificacao::create([
            'user_id' => $user->id,
            'tipo' => 'exportacao_pronta',
            'titulo' => 'Planilha da Carteira pronta',
            'link' => route('exportacoes.download', $exportacao->id, false),
        ]);

        $doHistorico = $this->actingAs($user)
            ->getJson(route('notificacoes.index'))
            ->json('naoLidas.0');

        $this->assertSame($doHistorico, (new \App\Events\NotificacaoCriada($notificacao))->broadcastWith());
    }

    /**
     * ⚠️ A notificação de ERRO aponta para a central, não para um arquivo — ela não pode
     * entrar em TIPOS_DOWNLOAD, senão o clique tentaria baixar uma página HTML.
     */
    public function test_notificacao_de_erro_de_exportacao_nao_e_download(): void
    {
        $user = $this->usuario();
        $exportacao = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);

        (new GerarExportacaoJob($exportacao->id))->failed(new \RuntimeException('estourou'));

        $payload = $this->actingAs($user)
            ->getJson(route('notificacoes.index'))
            ->assertOk()
            ->json('naoLidas.0');

        $this->assertSame('exportacao_erro', $payload['tipo']);
        $this->assertFalse($payload['download']);
        $this->assertSame(route('exportacoes.index', absolute: false), $payload['link']);
    }

    // ---------------------------------------------------------------- a tela

    public function test_a_central_lista_so_as_proprias_exportacoes(): void
    {
        $user = $this->usuario('vendedor');
        $outro = $this->usuario('vendedor');

        $minha = $this->exportacaoPronta($user);
        $alheia = $this->exportacaoPronta($outro);

        $this->actingAs($user)
            ->get(route('exportacoes.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Exportacoes/Index')
                ->where('exportacoes.data.0.id', $minha->id)
                ->count('exportacoes.data', 1)
            );

        $this->assertNotSame($minha->id, $alheia->id);
    }

    public function test_a_tela_recebe_rotulo_e_filtros_prontos_do_servidor(): void
    {
        $user = $this->usuario();
        $exportacao = $this->exportacaoPronta($user);
        $exportacao->update([
            'recurso' => 'pedidos-abertos',
            'filtros' => ['estado' => 'SP', 'busca' => '', 'page' => 3],
        ]);

        $this->actingAs($user)
            ->get(route('exportacoes.index'))
            ->assertInertia(fn ($page) => $page
                // Rótulo humano, não a chave crua: o front não tem cópia deste mapa.
                ->where('exportacoes.data.0.recurso', 'Pedidos em aberto')
                ->where('exportacoes.data.0.filtros', [['rotulo' => 'Estado', 'valor' => 'SP']])
                ->where('exportacoes.data.0.disponivel', true)
            );
    }

    public function test_exportacao_vencida_aparece_na_lista_como_indisponivel(): void
    {
        $user = $this->usuario();
        $exportacao = $this->exportacaoPronta($user);
        $exportacao->update(['expira_em' => now()->subDay()]);

        /*
         * Some o download, não a linha: esconder o histórico faria a pessoa achar que
         * nunca exportou aquilo e gerar de novo.
         */
        $this->actingAs($user)
            ->get(route('exportacoes.index'))
            ->assertInertia(fn ($page) => $page
                ->count('exportacoes.data', 1)
                ->where('exportacoes.data.0.disponivel', false)
                ->where('exportacoes.data.0.expirou', true)
                ->where('exportacoes.data.0.url', null)
            );
    }

    /**
     * 🔴 Regressão de um incidente real (2026-09-09): o container da fila morreu com o job
     * na mão e o registro ficou "Preparando" indefinidamente. O `failed()` do job não
     * cobre esse caso — ele só roda se o worker estiver vivo para chamá-lo.
     */
    public function test_exportacao_abandonada_pela_fila_aparece_como_interrompida(): void
    {
        $user = $this->usuario();

        $recente = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);

        $abandonada = Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'leads',
            'status' => Exportacao::STATUS_PROCESSANDO,
        ]);
        $abandonada->forceFill([
            'created_at' => now()->subMinutes(Exportacao::MINUTOS_ATE_ORFA + 1),
        ])->save();

        $this->actingAs($user)
            ->get(route('exportacoes.index'))
            ->assertInertia(fn ($page) => $page
                // A ordem é a da listagem: mais nova primeiro.
                ->where('exportacoes.data.0.id', $recente->id)
                ->where('exportacoes.data.0.travou', false)
                ->where('exportacoes.data.1.id', $abandonada->id)
                ->where('exportacoes.data.1.travou', true)
            );

        // E o expurgo corrige o estado no banco, para a lista não guardar zumbis.
        (new \App\Jobs\ExpurgarExportacoesJob)->handle();

        $this->assertSame(Exportacao::STATUS_ERRO, $abandonada->refresh()->status);
        $this->assertSame(Exportacao::STATUS_PROCESSANDO, $recente->refresh()->status);
    }

    public function test_a_central_exige_login(): void
    {
        $this->get(route('exportacoes.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------- autorização

    public function test_quem_nao_acessa_a_tela_nao_exporta_a_planilha_dela(): void
    {
        $vendedor = $this->usuario('vendedor');

        // A autorização mora no catálogo justamente para valer nos dois caminhos; se ela
        // vivesse só no controller, a fila seria uma porta lateral.
        $this->actingAs($vendedor)->post(route('equipe.exportar'))->assertForbidden();
        $this->actingAs($vendedor)->post(route('metas.exportar'))->assertForbidden();

        $this->assertDatabaseCount('exportacoes', 0);
    }

    public function test_exportar_e_post_porque_cria_registro(): void
    {
        $user = $this->usuario();

        // Como GET, um prefetch do navegador geraria planilha sozinho.
        $this->actingAs($user)->get('/leads/exportar')->assertStatus(405);
    }

    private function exportacaoPronta(User $user): Exportacao
    {
        $caminho = 'exports/teste/carteira-'.uniqid().'.xlsx';
        Storage::disk('local')->put($caminho, 'conteudo');

        return Exportacao::create([
            'user_id' => $user->id,
            'recurso' => 'carteira',
            'status' => Exportacao::STATUS_PRONTO,
            'caminho' => $caminho,
            'nome_arquivo' => 'carteira.xlsx',
            'linhas' => 3,
            'bytes' => 8,
            'expira_em' => now()->addDays(7),
        ]);
    }
}
