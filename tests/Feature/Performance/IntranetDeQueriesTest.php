<?php

namespace Tests\Feature\Performance;

use App\Models\IntranetPublicacao;
use App\Models\User;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O contador da faixa da intranet no Painel é UMA query, qualquer que seja o volume.
 *
 * Sem cache de propósito: o número tem que zerar no request seguinte ao da leitura.
 * O que impede de virar N+1 (ou um COUNT por publicação) é o `NOT EXISTS` / JOIN
 * agregado em `IntranetPublicacao::contagensPara()`. Este teste trava o CUSTO no
 * caminho real (`GET /dashboard`), não só no resolver — senão alguém poderia
 * "simplificar" o controller para um loop e o teste isolado continuaria verde.
 */
class IntranetDeQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_queries_da_intranet_no_painel_nao_crescem_com_o_volume(): void
    {
        $autor = $this->usuario('admin');
        $vendedor = $this->usuario('vendedor');

        $this->publicar($autor, 'Primeiro aviso');
        $com1 = $this->queriesDaIntranetNoPainel($vendedor);

        foreach (range(2, 16) as $i) {
            $this->publicar($autor, "Aviso {$i}", exigeCiencia: $i % 2 === 0);
        }
        $com16 = $this->queriesDaIntranetNoPainel($vendedor);

        $this->assertSame(1, $com1);
        $this->assertSame($com1, $com16);
    }

    private function queriesDaIntranetNoPainel(User $user): int
    {
        $medicao = ContadorDeQueries::medir(
            fn () => $this->actingAs($user)->get(route('dashboard')),
            capturarSql: true,
        );

        $medicao->resultado->assertOk();

        return collect($medicao->sqls)
            ->filter(fn (string $sql) => str_contains(strtolower($sql), 'intranet_'))
            ->count();
    }

    private function usuario(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function publicar(User $autor, string $titulo, bool $exigeCiencia = false): void
    {
        IntranetPublicacao::create([
            'user_id' => $autor->id,
            'categoria' => $exigeCiencia ? 'regra' : 'aviso',
            'titulo' => $titulo,
            'corpo' => 'Texto.',
            'exige_ciencia' => $exigeCiencia,
            'publicada_em' => now(),
        ]);
    }
}
