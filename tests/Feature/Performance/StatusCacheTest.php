<?php

namespace Tests\Feature\Performance;

use App\Jobs\AquecerCacheDashboardJob;
use App\Models\User;
use App\Services\Dashboard\DashboardBlocos;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A pill de fogo do Painel: sinaliza se o cache warming está vivo.
 *
 * ⚠️ Estes testes existem por causa de um bug real: a primeira versão calculava a idade
 * com `now()->diffInMinutes($passado)`, e no Carbon 3 (Laravel 11) esse método passou a
 * devolver valor COM SINAL — o resultado vinha negativo, e um cache velho de 100 minutos
 * seria classificado como "aquecido" porque -100 <= 20. O sintoma era invisível no caso
 * feliz, que é justamente o que torna esse tipo de erro perigoso.
 */
class StatusCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Cache::forget(AquecerCacheDashboardJob::CHAVE_ULTIMO_AQUECIMENTO);
    }

    private function comAquecimentoHa(int $minutos): array
    {
        Cache::put(AquecerCacheDashboardJob::CHAVE_ULTIMO_AQUECIMENTO, [
            'em' => now()->subMinutes($minutos)->toIso8601String(),
            'escopos' => 10,
            'falhas' => 0,
        ], now()->addDay());

        return app(DashboardBlocos::class)->statusCache();
    }

    public function test_idade_do_cache_nunca_e_negativa(): void
    {
        $status = $this->comAquecimentoHa(35);

        $this->assertGreaterThanOrEqual(0, $status['minutos'], 'Idade negativa: a ordem do diffInMinutes está invertida.');
        $this->assertSame(35, $status['minutos']);
    }

    public function test_classifica_por_faixa_de_idade(): void
    {
        $this->assertSame('aquecido', $this->comAquecimentoHa(5)['status']);
        $this->assertSame('aquecido', $this->comAquecimentoHa(20)['status']);
        $this->assertSame('esfriando', $this->comAquecimentoHa(25)['status']);
        $this->assertSame('frio', $this->comAquecimentoHa(120)['status']);
    }

    public function test_sem_marca_nenhuma_devolve_ausente(): void
    {
        $status = app(DashboardBlocos::class)->statusCache();

        // Estado normal em dev, onde não há cron chamando schedule:run — por isso é
        // 'ausente' (neutro) e não 'frio' (alarme).
        $this->assertSame('ausente', $status['status']);
        $this->assertNull($status['minutos']);
    }

    public function test_virada_de_hora_nao_quebra_a_contagem(): void
    {
        Carbon::setTestNow('2026-08-27 00:05:00');

        $status = $this->comAquecimentoHa(10); // 23:55 do dia anterior

        Carbon::setTestNow();

        $this->assertSame(10, $status['minutos']);
        $this->assertSame('aquecido', $status['status']);
    }

    public function test_pill_so_aparece_para_admin(): void
    {
        foreach (['admin' => true, 'supervisor' => false, 'vendedor' => false] as $role => $deveVer) {
            $user = User::factory()->create(['is_active' => true]);
            $user->assignRole($role);

            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertInertia(
                    fn ($page) => $deveVer
                        ? $page->where('statusCache.status', 'ausente')
                        : $page->where('statusCache', null),
                );
        }
    }
}
