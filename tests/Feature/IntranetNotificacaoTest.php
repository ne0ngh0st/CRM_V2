<?php

namespace Tests\Feature;

use App\Jobs\NotificarPublicacaoIntranetJob;
use App\Models\IntranetPublicacao;
use App\Models\Notificacao;
use App\Models\User;
use App\Services\Notificacao\NotificacaoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntranetNotificacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->autor = User::factory()->create(['is_active' => true]);
        $this->autor->assignRole('diretor');
    }

    private function vendedores(int $quantos, bool $ativos = true): void
    {
        User::factory()->count($quantos)->create(['is_active' => $ativos])
            ->each(fn (User $u) => $u->assignRole('vendedor'));
    }

    private function publicacao(array $attrs = []): IntranetPublicacao
    {
        return IntranetPublicacao::create([
            'user_id' => $this->autor->id,
            'categoria' => 'regra',
            'titulo' => 'Novo teto de desconto',
            'corpo' => 'Texto.',
            'publicada_em' => now(),
            ...$attrs,
        ]);
    }

    private function rodar(IntranetPublicacao $p, string $motivo = NotificarPublicacaoIntranetJob::MOTIVO_NOVA): void
    {
        (new NotificarPublicacaoIntranetJob($p->id, $motivo))->handle(app(NotificacaoService::class));
    }

    public function test_notifica_todos_os_ativos_menos_o_autor(): void
    {
        $this->vendedores(3);
        $this->vendedores(2, ativos: false);
        $publicacao = $this->publicacao();

        $this->rodar($publicacao);

        $this->assertSame(3, Notificacao::count());
        $this->assertSame(0, Notificacao::where('user_id', $this->autor->id)->count());
        $this->assertSame(
            route('intranet.show', $publicacao->id, false),
            Notificacao::first()->link,
        );
    }

    public function test_rodar_de_novo_nao_duplica(): void
    {
        $this->vendedores(3);
        $publicacao = $this->publicacao();

        // Retry do worker que morreu no meio: a segunda passada não pode dobrar o sino.
        $this->rodar($publicacao);
        $this->rodar($publicacao);

        $this->assertSame(3, Notificacao::count());
    }

    public function test_importante_sai_como_tipo_de_destaque_e_o_resto_nao(): void
    {
        $this->vendedores(1);

        $this->rodar($this->publicacao(['importante' => true]));
        $this->rodar($this->publicacao(['titulo' => 'Recado comum']));

        $importante = Notificacao::where('tipo', 'intranet_importante')->first();
        $comum = Notificacao::where('tipo', 'intranet')->first();

        $this->assertTrue($importante->paraOSino()['destaque']);
        $this->assertFalse($comum->paraOSino()['destaque']);
    }

    public function test_pedir_ciencia_de_novo_avisa_de_novo_quem_ja_tinha_sido_avisado(): void
    {
        $this->vendedores(2);
        $publicacao = $this->publicacao(['exige_ciencia' => true]);

        $this->rodar($publicacao);
        $this->assertSame(2, Notificacao::count());

        // O que o controller faz ao marcar "mudança relevante".
        $publicacao->increment('revisao_ciencia');
        $this->rodar($publicacao->fresh(), NotificarPublicacaoIntranetJob::MOTIVO_CIENCIA);

        $this->assertSame(4, Notificacao::count());
        $this->assertSame(2, Notificacao::where('titulo', 'like', 'Regra atualizada:%')->count());
    }

    public function test_publicacao_excluida_antes_do_job_nao_notifica(): void
    {
        $this->vendedores(2);
        $publicacao = $this->publicacao();
        $publicacao->delete();

        $this->rodar($publicacao);

        $this->assertSame(0, Notificacao::count());
    }

    public function test_publicar_pela_tela_dispara_o_job_de_verdade(): void
    {
        // Fila `sync` na suíte: aqui o caminho real (controller → job → serviço) roda inteiro.
        $this->vendedores(2);

        $this->actingAs($this->autor)->post(route('intranet.store'), [
            'categoria' => 'aviso',
            'titulo' => 'Reunião geral sexta',
            'corpo' => 'Às 9h.',
            'importante' => true,
        ])->assertRedirect();

        $this->assertSame(2, Notificacao::where('tipo', 'intranet_importante')->count());
    }
}
