<?php

namespace Tests\Feature;

use App\Jobs\ExpurgarNotificacoesLidasJob;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HistoricoNotificacoesTest extends TestCase
{
    use RefreshDatabase;

    private function notificacao(User $user, string $titulo, bool $lida = false, string $tipo = 'teste'): Notificacao
    {
        return Notificacao::create([
            'user_id' => $user->id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'link' => '/dashboard',
            'lida_em' => $lida ? now() : null,
        ]);
    }

    public function test_lista_lidas_e_nao_lidas_da_propria_pessoa(): void
    {
        $eu = User::factory()->create(['is_active' => true]);
        $outro = User::factory()->create(['is_active' => true]);

        $this->notificacao($eu, 'Lida', lida: true);
        $this->notificacao($eu, 'Nao lida');
        $this->notificacao($outro, 'De outra pessoa');

        $this->actingAs($eu)->get(route('notificacoes.historico'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Notificacoes/Index')
                ->has('notificacoes.data', 2)
                ->where('naoLidas', 1)
                ->where('diasGuarda', Notificacao::DIAS_GUARDA_LIDAS)
                // Mais recente primeiro.
                ->where('notificacoes.data.0.titulo', 'Nao lida')
                ->where('notificacoes.data.0.lidaEm', null)
                ->where('notificacoes.data.1.titulo', 'Lida')
                ->whereType('notificacoes.data.1.lidaEm', 'string'));
    }

    public function test_filtra_por_situacao(): void
    {
        $eu = User::factory()->create(['is_active' => true]);
        $this->notificacao($eu, 'Lida', lida: true);
        $this->notificacao($eu, 'Nao lida');

        $this->actingAs($eu)->get(route('notificacoes.historico', ['situacao' => 'lidas']))
            ->assertInertia(fn (Assert $p) => $p
                ->has('notificacoes.data', 1)
                ->where('notificacoes.data.0.titulo', 'Lida'));

        $this->actingAs($eu)->get(route('notificacoes.historico', ['situacao' => 'nao_lidas']))
            ->assertInertia(fn (Assert $p) => $p
                ->has('notificacoes.data', 1)
                ->where('notificacoes.data.0.titulo', 'Nao lida'));

        // Valor fora da whitelist vira "todas", não erro.
        $this->actingAs($eu)->get(route('notificacoes.historico', ['situacao' => 'xpto']))
            ->assertInertia(fn (Assert $p) => $p
                ->has('notificacoes.data', 2)
                ->where('filtros.situacao', ''));
    }

    public function test_reaproveita_o_payload_do_sino(): void
    {
        $eu = User::factory()->create(['is_active' => true]);
        $this->notificacao($eu, 'Planilha', tipo: 'exportacao_pronta');

        $this->actingAs($eu)->get(route('notificacoes.historico'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('notificacoes.data.0.download', true)
                ->where('notificacoes.data.0.destaque', false));
    }

    public function test_exige_login(): void
    {
        $this->get(route('notificacoes.historico'))->assertRedirect(route('login'));
    }

    public function test_expurgo_usa_o_prazo_que_a_tela_anuncia(): void
    {
        $eu = User::factory()->create(['is_active' => true]);
        $velha = $this->notificacao($eu, 'Velha', lida: true);
        $velha->update(['lida_em' => now()->subDays(Notificacao::DIAS_GUARDA_LIDAS + 1)]);
        $recente = $this->notificacao($eu, 'Recente', lida: true);
        $recente->update(['lida_em' => now()->subDays(Notificacao::DIAS_GUARDA_LIDAS - 1)]);
        $naoLida = $this->notificacao($eu, 'Nao lida');

        (new ExpurgarNotificacoesLidasJob)->handle();

        $this->assertModelMissing($velha);
        $this->assertModelExists($recente);
        $this->assertModelExists($naoLida);
    }
}
