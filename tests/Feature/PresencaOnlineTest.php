<?php

namespace Tests\Feature;

use App\Http\Controllers\SimulacaoController;
use App\Models\User;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Presença "online agora" da tela Equipe (App\Http\Middleware\RegistrarAtividade).
 *
 * O bug que originou estes testes: users.last_activity_at não era escrita por NINGUÉM,
 * então a badge dizia "0 online agora" em produção com o sistema em uso. Por isso o
 * primeiro teste começa afirmando que a coluna estava NULL — sem essa asserção, um
 * teste que só olhasse a badge passaria de novo se a escrita sumisse outra vez.
 */
class PresencaOnlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $role, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attrs));
        $user->assignRole($role);

        return $user;
    }

    public function test_request_autenticado_marca_presenca(): void
    {
        $vendedor = $this->usuario('vendedor');
        $this->assertNull($vendedor->last_activity_at);

        $this->actingAs($vendedor)->get(route('tabela-precos.index'))->assertOk();

        $this->assertNotNull($vendedor->fresh()->last_activity_at);
    }

    public function test_visitante_nao_marca_presenca_de_ninguem(): void
    {
        $this->usuario('vendedor');

        $this->get(route('login'))->assertOk();

        $this->assertSame(0, User::whereNotNull('last_activity_at')->count());
    }

    /**
     * O trinco é o que sustenta a Regra de ouro nº 9 aqui: sem ele seria um UPDATE por
     * página aberta, por usuário. Este teste falha se alguém tirar o Cache::add.
     */
    public function test_nao_grava_de_novo_dentro_da_janela(): void
    {
        $vendedor = $this->usuario('vendedor');

        $primeiro = ContadorDeQueries::contar(
            fn () => $this->actingAs($vendedor)->get(route('tabela-precos.index'))
        );
        $marcadoEm = $vendedor->fresh()->last_activity_at;

        $segundo = ContadorDeQueries::contar(
            fn () => $this->actingAs($vendedor)->get(route('tabela-precos.index'))
        );

        $this->assertLessThan($primeiro, $segundo, 'O 2º request devia economizar o UPDATE de presença.');
        $this->assertTrue($marcadoEm->equalTo($vendedor->fresh()->last_activity_at));
    }

    public function test_grava_de_novo_depois_da_janela(): void
    {
        $vendedor = $this->usuario('vendedor');

        $this->actingAs($vendedor)->get(route('tabela-precos.index'));
        $primeiraMarca = $vendedor->fresh()->last_activity_at;

        // O trinco vive no cache com TTL; avançar o relógio não o expira no store de
        // teste, então o expurgo é explícito — o que este teste cobre é o efeito de a
        // janela ter passado, não a expiração do Redis.
        Cache::forget("presenca:{$vendedor->id}");
        Carbon::setTestNow(now()->addMinutes(2));

        $this->actingAs($vendedor)->get(route('tabela-precos.index'));

        $this->assertTrue($vendedor->fresh()->last_activity_at->greaterThan($primeiraMarca));
        Carbon::setTestNow();
    }

    /**
     * Quem está usando o sistema durante uma simulação é o admin. Marcar o alvo botaria
     * "online agora" num vendedor que pode estar em casa — e a Equipe é justamente a
     * tela onde outro gestor leria isso.
     */
    public function test_simulacao_marca_o_admin_e_nao_o_alvo(): void
    {
        $admin = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->actingAs($admin)->post(route('simulacao.iniciar', $vendedor->id));
        $this->assertSame($admin->id, session(SimulacaoController::SESSAO_ADMIN_ID));

        Cache::forget("presenca:{$admin->id}");
        Carbon::setTestNow(now()->addMinutes(2));
        $marcaDoAlvoAntes = $vendedor->fresh()->last_activity_at;

        $this->get(route('tabela-precos.index'))->assertOk();

        $this->assertTrue($admin->fresh()->last_activity_at->greaterThan(now()->subMinute()));
        $this->assertEquals($marcaDoAlvoAntes, $vendedor->fresh()->last_activity_at);
        Carbon::setTestNow();
    }

    public function test_badge_e_filtro_da_equipe_refletem_a_presenca(): void
    {
        $admin = $this->usuario('admin');
        $online = $this->usuario('vendedor', ['last_activity_at' => now()->subMinute()]);
        $ausente = $this->usuario('vendedor', ['last_activity_at' => now()->subHour()]);
        $this->usuario('vendedor');

        // 1, não 2: a presença é gravada em terminate(), depois de a página já ter sido
        // renderizada — quem abre a Equipe só entra na própria contagem no request
        // seguinte (a tela recarrega sozinha a cada 45s). É o preço de não pagar o
        // UPDATE dentro do request, e está documentado aqui para não parecer defeito.
        $this->actingAs($admin)
            ->get(route('equipe.index'))
            ->assertInertia(fn ($page) => $page->where('totalOnline', 1));

        $this->actingAs($admin)
            ->get(route('equipe.index'))
            ->assertInertia(fn ($page) => $page->where('totalOnline', 2));

        $this->actingAs($admin)
            ->get(route('equipe.index', ['online' => 'sim']))
            ->assertInertia(function ($page) use ($online, $ausente) {
                $ids = collect($page->toArray()['props']['usuarios'])
                    ->flatMap(fn ($grupo) => collect($grupo['usuarios'])->pluck('id'))
                    ->all();

                $this->assertContains($online->id, $ids);
                $this->assertNotContains($ausente->id, $ids);
            });
    }
}
