<?php

namespace Tests\Feature\Performance;

use App\Jobs\AquecerCacheDashboardJob;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Dashboard\EscoposAquecidos;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * O ciclo completo do cache warming: o job aquece, e a requisição real colhe.
 *
 * O AquecimentoDeCacheTest prova que aquecer um bloco faz a leitura seguinte custar zero
 * queries. Aqui a pergunta é maior e mais próxima do usuário: **depois do job rodar, o
 * Dashboard de verdade ficou mais barato?** É a diferença entre o mecanismo funcionar
 * isolado e funcionar ligado nas pontas.
 */
class CacheWarmingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_dashboard_do_admin_fica_mais_barato_depois_do_job(): void
    {
        $admin = $this->admin();

        $semAquecer = ContadorDeQueries::contar(
            fn () => $this->actingAs($admin)->get(route('dashboard'))->assertOk(),
        );

        Cache::flush();
        $this->job()->handle(app(\App\Services\Dashboard\DashboardBlocos::class), app(EscoposAquecidos::class));

        $aquecido = ContadorDeQueries::contar(
            fn () => $this->actingAs($admin)->get(route('dashboard'))->assertOk(),
        );

        $this->assertLessThan(
            $semAquecer,
            $aquecido,
            "O Dashboard não ficou mais barato após o aquecimento ({$semAquecer} → {$aquecido} queries). ".
            'Provavelmente o job aqueceu um escopo diferente do que a requisição resolve.',
        );
    }

    public function test_job_registra_marca_de_ultimo_aquecimento(): void
    {
        Cache::forget(AquecerCacheDashboardJob::CHAVE_ULTIMO_AQUECIMENTO);

        $this->job()->handle(app(\App\Services\Dashboard\DashboardBlocos::class), app(EscoposAquecidos::class));

        $marca = Cache::get(AquecerCacheDashboardJob::CHAVE_ULTIMO_AQUECIMENTO);

        // Sem esta marca, um worker morto passaria despercebido: o cache esfriaria e
        // ninguém saberia até um usuário reclamar de lentidão.
        $this->assertNotNull($marca, 'O job não registrou que rodou.');
        $this->assertSame(0, $marca['falhas']);
        $this->assertGreaterThan(0, $marca['escopos']);
    }

    public function test_escopos_incluem_empresa_e_equipes_mas_nao_vendedores_individuais(): void
    {
        $supervisor = $this->comPerfil('supervisor', '000100');
        $this->comPerfil('vendedor', '000101', codSuper: '000100');
        $this->comPerfil('vendedor', '000102', codSuper: '000100');
        $this->comPerfil('vendedor', '000200'); // sem supervisor

        $rotulos = array_column(app(EscoposAquecidos::class)->listar(), 'rotulo');

        $this->assertContains('empresa inteira', $rotulos);
        $this->assertContains('equipe do supervisor 000100', $rotulos);

        // ~200 vendedores a 6-9 ms cada não valem o trabalho do worker (config
        // escopos_aquecidos.vendedores = false). Ver EscoposAquecidos.
        $this->assertNotContains('vendedor 000200', $rotulos);
        $this->assertCount(2, $rotulos);
    }

    public function test_escopo_de_equipe_resolve_os_usuarios_da_equipe(): void
    {
        $this->comPerfil('supervisor', '000100');
        $v1 = $this->comPerfil('vendedor', '000101', codSuper: '000100');
        $v2 = $this->comPerfil('vendedor', '000102', codSuper: '000100');
        $foraDaEquipe = $this->comPerfil('vendedor', '000200');

        $equipe = collect(app(EscoposAquecidos::class)->listar())
            ->firstWhere('rotulo', 'equipe do supervisor 000100');

        $this->assertEqualsCanonicalizing([$v1->id, $v2->id], $equipe['usuarioIds']);
        $this->assertNotContains($foraDaEquipe->id, $equipe['usuarioIds']);
    }

    private function job(): AquecerCacheDashboardJob
    {
        // Chamado direto, não via dispatch(): em teste a fila é `sync`, então dispatch
        // executaria inline de qualquer forma, mas chamar handle() deixa explícito que
        // estamos testando o trabalho, não o enfileiramento.
        return new AquecerCacheDashboardJob;
    }

    private function admin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');

        return $user;
    }

    private function comPerfil(string $role, string $codVendedor, ?string $codSuper = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        VendedorPerfil::create([
            'user_id' => $user->id,
            'cod_vendedor' => $codVendedor,
            'cod_super' => $codSuper,
        ]);

        return $user;
    }
}
