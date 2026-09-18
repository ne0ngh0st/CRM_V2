<?php

namespace Tests\Feature;

use App\Http\Controllers\SimulacaoController;
use App\Jobs\NotificarPublicacaoIntranetJob;
use App\Models\IntranetAnexo;
use App\Models\IntranetLeitura;
use App\Models\IntranetPublicacao;
use App\Models\User;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntranetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        // Toda publicação dispara o job de notificação; aqui ele não interessa (tem teste
        // próprio) e, com a fila `sync` da suíte, rodaria de verdade em cada store.
        Queue::fake();
    }

    private function usuario(string $role, array $attrs = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attrs]);
        $user->assignRole($role);

        return $user;
    }

    private function publicacao(User $autor, array $attrs = []): IntranetPublicacao
    {
        return IntranetPublicacao::create([
            'user_id' => $autor->id,
            'categoria' => 'aviso',
            'titulo' => 'Aviso de teste',
            'corpo' => 'Texto do aviso.',
            'publicada_em' => now(),
            ...$attrs,
        ]);
    }

    private function dados(array $attrs = []): array
    {
        return [
            'categoria' => 'regra',
            'titulo' => 'Desconto máximo de 10%',
            'corpo' => "Novo teto de desconto.\n\n- vale a partir de hoje",
            'importante' => false,
            'fixada' => false,
            'exige_ciencia' => true,
            ...$attrs,
        ];
    }

    // ── Permissões ──────────────────────────────────────────────────────────────────

    public function test_todos_os_perfis_leem_a_intranet(): void
    {
        $autor = $this->usuario('admin');
        $publicacao = $this->publicacao($autor);

        foreach (['vendedor', 'representante', 'assistente', 'supervisor', 'diretor', 'admin'] as $papel) {
            $this->actingAs($this->usuario($papel))
                ->get(route('intranet.index'))
                ->assertOk();

            $this->actingAs($this->usuario($papel))
                ->get(route('intranet.show', $publicacao))
                ->assertOk();
        }
    }

    public function test_quem_nao_e_gestor_nao_publica(): void
    {
        foreach (['vendedor', 'representante', 'assistente'] as $papel) {
            $user = $this->usuario($papel);

            $this->actingAs($user)->get(route('intranet.create'))->assertForbidden();
            $this->actingAs($user)->post(route('intranet.store'), $this->dados())->assertForbidden();
            $this->actingAs($user)->get(route('intranet.index'))
                ->assertInertia(fn (Assert $p) => $p->where('podePublicar', false));
        }

        $this->assertSame(0, IntranetPublicacao::count());
    }

    public function test_admin_diretor_e_supervisor_publicam(): void
    {
        foreach (['admin', 'diretor', 'supervisor'] as $papel) {
            $this->actingAs($this->usuario($papel))
                ->post(route('intranet.store'), $this->dados(['titulo' => "Publicado por {$papel}"]))
                ->assertRedirect()
                ->assertSessionHas('recursoCriadoId');
        }

        $this->assertSame(3, IntranetPublicacao::count());
        Queue::assertPushed(NotificarPublicacaoIntranetJob::class, 3);
    }

    public function test_supervisor_nao_edita_nem_exclui_publicacao_de_outro(): void
    {
        $colega = $this->usuario('supervisor');
        $supervisor = $this->usuario('supervisor');
        $publicacao = $this->publicacao($colega);

        $this->actingAs($supervisor)->get(route('intranet.edit', $publicacao))->assertForbidden();
        $this->actingAs($supervisor)->put(route('intranet.update', $publicacao), $this->dados())->assertForbidden();
        $this->actingAs($supervisor)->delete(route('intranet.destroy', $publicacao))->assertForbidden();

        $this->assertNull($publicacao->fresh()->deleted_at);
        $this->assertSame('Aviso de teste', $publicacao->fresh()->titulo);
    }

    public function test_supervisor_edita_a_propria_e_admin_e_diretor_editam_qualquer_uma(): void
    {
        $supervisor = $this->usuario('supervisor');
        $publicacao = $this->publicacao($supervisor);

        foreach ([$supervisor, $this->usuario('admin'), $this->usuario('diretor')] as $i => $quem) {
            $this->actingAs($quem)
                ->put(route('intranet.update', $publicacao), $this->dados(['titulo' => "Edição {$i}"]))
                ->assertRedirect(route('intranet.show', $publicacao));

            $this->assertSame("Edição {$i}", $publicacao->fresh()->titulo);
        }
    }

    public function test_a_prop_de_gerenciar_segue_a_mesma_regra_do_servidor(): void
    {
        $supervisor = $this->usuario('supervisor');
        $daOutra = $this->publicacao($this->usuario('supervisor'));

        $this->actingAs($supervisor)->get(route('intranet.show', $daOutra))
            ->assertInertia(fn (Assert $p) => $p->where('pode.gerenciar', false)->where('ciencia', null));

        $this->actingAs($this->usuario('diretor'))->get(route('intranet.show', $daOutra))
            ->assertInertia(fn (Assert $p) => $p->where('pode.gerenciar', true));
    }

    // ── Validação e segurança do texto ──────────────────────────────────────────────

    public function test_categoria_fora_da_lista_e_recusada(): void
    {
        $this->actingAs($this->usuario('admin'))
            ->post(route('intranet.store'), $this->dados(['categoria' => 'sac']))
            ->assertSessionHasErrors('categoria');

        $this->assertSame(0, IntranetPublicacao::count());
    }

    public function test_html_e_link_javascript_saem_do_texto_renderizado(): void
    {
        $autor = $this->usuario('admin');
        $publicacao = $this->publicacao($autor, [
            'corpo' => "Olá <script>alert('x')</script> **equipe**\n\n[clique](javascript:alert(1))\n\n<img src=x onerror=alert(1)>",
        ]);

        $resposta = $this->actingAs($this->usuario('vendedor'))
            ->get(route('intranet.show', $publicacao))
            ->assertOk();

        $html = (string) data_get($resposta->original->getData(), 'page.props.publicacao.corpoHtml');

        $this->assertStringContainsString('<strong>equipe</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    // ── Leitura, contador e ciência ─────────────────────────────────────────────────

    public function test_abrir_registra_a_leitura_uma_vez_so_e_zera_o_contador_do_painel(): void
    {
        $autor = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');
        $publicacao = $this->publicacao($autor);
        $this->publicacao($autor, ['titulo' => 'Outro aviso']);

        $this->assertSame(2, IntranetPublicacao::contagensPara($vendedor)['naoLidas']);

        $this->actingAs($vendedor)->get(route('intranet.show', $publicacao))->assertOk();
        $primeira = IntranetLeitura::where('user_id', $vendedor->id)->value('lida_em');

        $this->travel(5)->minutes();
        $this->actingAs($vendedor)->get(route('intranet.show', $publicacao))->assertOk();

        $this->assertSame(1, IntranetLeitura::where('user_id', $vendedor->id)->count());
        $this->assertEquals($primeira, IntranetLeitura::where('user_id', $vendedor->id)->value('lida_em'));
        $this->assertSame(1, IntranetPublicacao::contagensPara($vendedor)['naoLidas']);

        $this->actingAs($vendedor)->get(route('dashboard'))
            ->assertInertia(fn (Assert $p) => $p->where('intranet.naoLidas', 1));
    }

    public function test_ler_nao_e_dar_ciencia(): void
    {
        $autor = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');
        $regra = $this->publicacao($autor, ['categoria' => 'regra', 'exige_ciencia' => true]);

        $this->actingAs($vendedor)->get(route('intranet.show', $regra));

        $this->assertSame(['naoLidas' => 0, 'cienciasPendentes' => 1], IntranetPublicacao::contagensPara($vendedor));

        $this->actingAs($vendedor)->post(route('intranet.ciente', $regra))->assertRedirect();

        $this->assertSame(['naoLidas' => 0, 'cienciasPendentes' => 0], IntranetPublicacao::contagensPara($vendedor));
    }

    public function test_dar_ciencia_sem_ter_aberto_conta_como_lida_tambem(): void
    {
        $vendedor = $this->usuario('vendedor');
        $regra = $this->publicacao($this->usuario('admin'), ['exige_ciencia' => true]);

        $this->actingAs($vendedor)->post(route('intranet.ciente', $regra))->assertRedirect();

        $leitura = IntranetLeitura::where('user_id', $vendedor->id)->first();
        $this->assertNotNull($leitura->lida_em);
        $this->assertNotNull($leitura->ciente_em);
    }

    public function test_publicacao_que_nao_pede_ciencia_recusa_ciencia(): void
    {
        $aviso = $this->publicacao($this->usuario('admin'), ['exige_ciencia' => false]);

        $this->actingAs($this->usuario('vendedor'))
            ->post(route('intranet.ciente', $aviso))
            ->assertStatus(422);
    }

    public function test_pedir_ciencia_de_novo_zera_as_confirmacoes_e_renotifica(): void
    {
        $autor = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');
        $regra = $this->publicacao($autor, ['categoria' => 'regra', 'exige_ciencia' => true]);

        $this->actingAs($vendedor)->post(route('intranet.ciente', $regra));
        $this->assertSame(0, IntranetPublicacao::contagensPara($vendedor)['cienciasPendentes']);

        // Edição sem marcar "mudança relevante": a ciência continua.
        $this->actingAs($autor)->put(route('intranet.update', $regra), $this->dados());
        $this->assertSame(0, IntranetPublicacao::contagensPara($vendedor)['cienciasPendentes']);
        Queue::assertNotPushed(NotificarPublicacaoIntranetJob::class);

        $this->actingAs($autor)->put(route('intranet.update', $regra), $this->dados(['pedir_ciencia_de_novo' => true]));

        $this->assertSame(1, IntranetPublicacao::contagensPara($vendedor)['cienciasPendentes']);
        // A leitura fica: ler, a pessoa leu.
        $this->assertSame(0, IntranetPublicacao::contagensPara($vendedor)['naoLidas']);
        $this->assertSame(2, $regra->fresh()->revisao_ciencia);
        Queue::assertPushed(
            NotificarPublicacaoIntranetJob::class,
            fn ($job) => $job->motivo === NotificarPublicacaoIntranetJob::MOTIVO_CIENCIA,
        );
    }

    public function test_painel_de_ciencia_lista_quem_falta(): void
    {
        $autor = $this->usuario('admin');
        $ana = $this->usuario('vendedor', ['name' => 'Ana Cientista']);
        $bruno = $this->usuario('vendedor', ['name' => 'Bruno Pendente']);
        $this->usuario('vendedor', ['name' => 'Carla Inativa', 'is_active' => false]);
        $regra = $this->publicacao($autor, ['exige_ciencia' => true]);

        $this->actingAs($ana)->post(route('intranet.ciente', $regra));

        $this->actingAs($autor)->get(route('intranet.show', $regra))
            ->assertInertia(fn (Assert $p) => $p
                // Universo: ativos menos o autor (Ana e Bruno). Inativo não entra.
                ->where('ciencia.total', 2)
                ->where('ciencia.cientes', 1)
                ->where('ciencia.pendentes', [$bruno->display_name ?: $bruno->name]));
    }

    public function test_simulacao_nao_registra_leitura_nem_ciencia_em_nome_do_alvo(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');
        $regra = $this->publicacao($admin, ['exige_ciencia' => true]);

        $sessao = [SimulacaoController::SESSAO_ADMIN_ID => $admin->id];

        $this->actingAs($vendedor)->withSession($sessao)
            ->get(route('intranet.show', $regra))
            ->assertInertia(fn (Assert $p) => $p->where('pode.darCiencia', false));

        $this->actingAs($vendedor)->withSession($sessao)
            ->post(route('intranet.ciente', $regra))
            ->assertForbidden();

        $this->assertSame(0, IntranetLeitura::count());
    }

    public function test_excluida_some_da_lista_e_do_contador_mas_preserva_as_leituras(): void
    {
        $autor = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');
        $regra = $this->publicacao($autor, ['exige_ciencia' => true]);
        $this->actingAs($vendedor)->post(route('intranet.ciente', $regra));
        $this->publicacao($autor, ['titulo' => 'Não lida e excluída']);
        $naoLida = IntranetPublicacao::latest('id')->first();

        $this->actingAs($autor)->delete(route('intranet.destroy', $regra))->assertRedirect(route('intranet.index'));
        $this->actingAs($autor)->delete(route('intranet.destroy', $naoLida));

        $this->actingAs($vendedor)->get(route('intranet.index'))
            ->assertInertia(fn (Assert $p) => $p->has('publicacoes.data', 0));
        $this->actingAs($vendedor)->get(route('intranet.show', $regra))->assertNotFound();
        $this->assertSame(['naoLidas' => 0, 'cienciasPendentes' => 0], IntranetPublicacao::contagensPara($vendedor));
        $this->assertSame(1, IntranetLeitura::where('publicacao_id', $regra->id)->whereNotNull('ciente_em')->count());
    }

    public function test_lista_filtra_por_categoria_e_poe_fixada_no_topo(): void
    {
        $autor = $this->usuario('admin');
        $this->publicacao($autor, ['titulo' => 'Aviso velho fixado', 'fixada' => true, 'publicada_em' => now()->subDays(10)]);
        $this->publicacao($autor, ['titulo' => 'Aviso novo', 'publicada_em' => now()]);
        $this->publicacao($autor, ['titulo' => 'Manual', 'categoria' => 'documento']);

        $this->actingAs($this->usuario('vendedor'))->get(route('intranet.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('publicacoes.data.0.titulo', 'Aviso velho fixado')
                ->where('publicacoes.data.0.lida', false));

        $this->actingAs($this->usuario('vendedor'))->get(route('intranet.index', ['categoria' => 'documento']))
            ->assertInertia(fn (Assert $p) => $p
                ->has('publicacoes.data', 1)
                ->where('publicacoes.data.0.titulo', 'Manual'));

        // Categoria fora da lista é ignorada, não vira WHERE com valor arbitrário.
        $this->actingAs($this->usuario('vendedor'))->get(route('intranet.index', ['categoria' => 'x']))
            ->assertInertia(fn (Assert $p) => $p->has('publicacoes.data', 3)->where('filtros.categoria', ''));
    }

    // ── Anexos ──────────────────────────────────────────────────────────────────────

    public function test_anexo_grava_com_extensao_do_mime_e_baixa_com_o_nome_original(): void
    {
        Storage::fake('public');
        $autor = $this->usuario('admin');
        $publicacao = $this->publicacao($autor);

        $pdf = UploadedFile::fake()->createWithContent('Política de descontos.pdf', "%PDF-1.4\n%fake\n");

        $this->actingAs($autor)
            ->post(route('intranet.anexos.store', $publicacao), ['arquivo' => $pdf])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $anexo = IntranetAnexo::first();
        $this->assertSame('application/pdf', $anexo->mime);
        $this->assertStringStartsWith('intranet/', $anexo->caminho);
        $this->assertStringEndsWith('.pdf', $anexo->caminho);
        Storage::disk('public')->assertExists($anexo->caminho);

        $resposta = $this->actingAs($this->usuario('vendedor'))
            ->get(route('intranet.anexos.baixar', $anexo))
            ->assertOk()
            ->assertDownload();

        // O `filename=` ASCII perde o acento (limitação do header HTTP). O nome original
        // viaja em `filename*` (RFC 5987), que é o que o navegador usa pra salvar.
        $disposicao = (string) $resposta->headers->get('content-disposition');
        $this->assertStringContainsString("filename*=utf-8''", strtolower($disposicao));
        $this->assertStringContainsString(rawurlencode('Política de descontos.pdf'), $disposicao);
    }

    public function test_arquivo_disfarcado_e_recusado(): void
    {
        Storage::fake('public');
        $autor = $this->usuario('admin');
        $publicacao = $this->publicacao($autor);

        // Um script com nome de PDF: o MIME detectado é texto, não PDF.
        $falso = UploadedFile::fake()->createWithContent('relatorio.pdf', "<?php system(\$_GET['c']); ?>");

        $this->actingAs($autor)
            ->post(route('intranet.anexos.store', $publicacao), ['arquivo' => $falso])
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(0, IntranetAnexo::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_vendedor_nao_anexa_e_download_exige_login(): void
    {
        Storage::fake('public');
        $autor = $this->usuario('admin');
        $publicacao = $this->publicacao($autor);

        $this->actingAs($this->usuario('vendedor'))
            ->post(route('intranet.anexos.store', $publicacao), [
                'arquivo' => UploadedFile::fake()->image('fluxo.png'),
            ])
            ->assertForbidden();

        $anexo = $publicacao->anexos()->create([
            'nome_original' => 'fluxo.png', 'caminho' => 'intranet/x.png', 'mime' => 'image/png', 'tamanho' => 10,
        ]);

        auth()->logout();
        $this->get(route('intranet.anexos.baixar', $anexo))->assertRedirect(route('login'));
    }

    public function test_imagem_vai_para_a_galeria_e_o_resto_para_a_lista_de_anexos(): void
    {
        Storage::fake('public');
        $autor = $this->usuario('admin');
        $publicacao = $this->publicacao($autor);

        $this->actingAs($autor)->post(route('intranet.anexos.store', $publicacao), [
            'arquivo' => UploadedFile::fake()->image('fluxo.png'),
        ]);
        $this->actingAs($autor)->post(route('intranet.anexos.store', $publicacao), [
            'arquivo' => UploadedFile::fake()->createWithContent('manual.pdf', "%PDF-1.4\n"),
        ]);

        $this->actingAs($this->usuario('vendedor'))->get(route('intranet.show', $publicacao))
            ->assertInertia(fn (Assert $p) => $p
                ->has('publicacao.imagens', 1)
                ->where('publicacao.imagens.0.nome', 'fluxo.png')
                ->has('publicacao.arquivos', 1)
                ->where('publicacao.arquivos.0.nome', 'manual.pdf'));
    }

    public function test_anexo_de_publicacao_excluida_nao_baixa_mais(): void
    {
        Storage::fake('public');
        $autor = $this->usuario('admin');
        $publicacao = $this->publicacao($autor);
        $anexo = $publicacao->anexos()->create([
            'nome_original' => 'a.pdf', 'caminho' => 'intranet/a.pdf', 'mime' => 'application/pdf', 'tamanho' => 10,
        ]);
        Storage::disk('public')->put('intranet/a.pdf', '%PDF');

        $publicacao->delete();

        $this->actingAs($this->usuario('vendedor'))
            ->get(route('intranet.anexos.baixar', $anexo))
            ->assertNotFound();
    }

    // ── Custo ───────────────────────────────────────────────────────────────────────

    public function test_contador_do_painel_e_uma_query_so_qualquer_que_seja_o_volume(): void
    {
        $autor = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->publicacao($autor);
        $com1 = ContadorDeQueries::contar(fn () => IntranetPublicacao::contagensPara($vendedor));

        foreach (range(1, 15) as $i) {
            $this->publicacao($autor, ['titulo' => "Aviso {$i}", 'exige_ciencia' => $i % 2 === 0]);
        }
        $com16 = ContadorDeQueries::contar(fn () => IntranetPublicacao::contagensPara($vendedor));

        $this->assertSame(1, $com1);
        $this->assertSame($com1, $com16);
        $this->assertSame(['naoLidas' => 16, 'cienciasPendentes' => 7], IntranetPublicacao::contagensPara($vendedor));
    }
}
