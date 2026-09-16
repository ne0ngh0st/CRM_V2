<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Metas\MetaRankingResolver;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O ranking de /metas custa o mesmo número de consultas com 2 ou com 12 vendedores.
 *
 * Tudo é agregado em lote (metas, realizados, carteira em aberto, nomes das equipes). O
 * jeito mais fácil de quebrar isso sem perceber é resolver algo por linha dentro do
 * `map()` — o nome do supervisor, por exemplo —, e com o seed de dev ninguém sentiria:
 * em produção são ~130 linhas e 6 equipes.
 *
 * ⚠️ Trava o CUSTO, não o resultado. Os números certos estão em MetaFaltaVenderTest e
 * MetaAgrupamentoEquipeTest.
 */
class MetasDeQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** Um supervisor e seus vendedores. */
    private function equipe(string $codSupervisor, int $vendedores): void
    {
        $sup = User::factory()->create();
        $sup->assignRole('supervisor');
        VendedorPerfil::create(['user_id' => $sup->id, 'cod_vendedor' => $codSupervisor, 'cod_super' => '010002']);

        for ($i = 1; $i <= $vendedores; $i++) {
            $user = User::factory()->create();
            $user->assignRole('vendedor');
            VendedorPerfil::create([
                'user_id' => $user->id,
                'cod_vendedor' => $codSupervisor.$i,
                'cod_super' => $codSupervisor,
            ]);
        }
    }

    private function consultas(): int
    {
        // Instância nova a cada medição: nada memoizado de uma para a outra.
        return ContadorDeQueries::contar(fn () => app(MetaRankingResolver::class)
            ->ranking(null, (int) now()->year, (int) now()->month, agruparPorEquipe: true));
    }

    #[Test]
    public function test_custo_nao_cresce_com_vendedores_nem_equipes(): void
    {
        $this->equipe('000006', 1);
        $pouco = $this->consultas();

        $this->equipe('000115', 4);
        $this->equipe('010389', 5);
        $muito = $this->consultas();

        $this->assertSame($pouco, $muito, "ranking com 2 vendedores fez {$pouco} consultas e com 12 fez {$muito}");
    }
}
