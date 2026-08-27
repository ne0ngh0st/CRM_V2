<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Models\VendedorPerfil;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guarda-corpo permanente contra regressão de performance.
 *
 * ⚠️ POR QUE ISTO FUNCIONA COM O BANCO DE TESTE VAZIO:
 * contagem de query NÃO depende de volume de dados. Uma página que faz N+1 faz N+1 com
 * 3 registros e com 90 mil; um `count()` duplicado é duplicado nos dois casos; um
 * `resolve()` chamado 4x por request é 4x sempre. Então este teste pega exatamente a
 * classe de problema que mais aparece aqui, rodando em segundos na suíte normal.
 *
 * E é por isso também que NÃO existe asserção de milissegundos aqui: tempo depende de
 * volume, e um teste de ms contra banco vazio passaria sempre — a confiança falsa que a
 * Regra de ouro nº 6 proíbe. Latência se mede com `perf:baseline` (volume real) e, de
 * verdade, no `TargetResponseTime` do ALB.
 *
 * A lista de rotas e os tetos vivem em config/perf.php (Regra de ouro nº 8), a mesma
 * fonte que o comando usa.
 */
class OrcamentoDeQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function rotasCore(): array
    {
        // Lido do arquivo direto: data provider roda antes do boot da aplicação,
        // então config() ainda não existe aqui.
        $conf = require __DIR__.'/../../../config/perf.php';

        $casos = [];
        foreach ($conf['rotas_core'] as $chave => $definicao) {
            $casos[$chave] = [$chave, $definicao];
        }

        return $casos;
    }

    /**
     * @param  array{rota: string, params?: array<string, mixed>, perfis?: list<string>}  $conf
     */
    #[DataProvider('rotasCore')]
    public function test_rota_fica_dentro_do_orcamento_de_queries(string $chave, array $conf): void
    {
        $maximo = config("perf.orcamento_por_rota.{$chave}.queries_max")
            ?? config('perf.orcamento.queries_max');
        $perfis = $conf['perfis'] ?? ['admin', 'vendedor'];

        foreach ($perfis as $perfil) {
            $usuario = $this->usuarioCom($perfil);
            $url = route($conf['rota'], $conf['params'] ?? []);

            $resposta = null;
            $medicao = ContadorDeQueries::medir(function () use ($usuario, $url, &$resposta) {
                return $resposta = $this->actingAs($usuario)->get($url);
            });

            $this->assertSame(
                200,
                $resposta->getStatusCode(),
                "[{$chave}/{$perfil}] devolveu HTTP {$resposta->getStatusCode()} — a medição seria da página de erro.",
            );

            $this->assertLessThanOrEqual(
                $maximo,
                $medicao->queries,
                "[{$chave}/{$perfil}] fez {$medicao->queries} queries (teto {$maximo}). ".
                'Suspeitas usuais: N+1 sem eager loading, count() repetido sobre a mesma base, '.
                'ou o resolver de escopo sendo chamado mais de uma vez por request.',
            );
        }
    }

    private function usuarioCom(string $perfil): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($perfil);

        // Perfis comerciais precisam de código de vendedor, senão o escopo resolve
        // vazio e a página medida não é a real (lista sem nenhuma linha).
        if (in_array($perfil, ['vendedor', 'representante', 'supervisor'], true)) {
            VendedorPerfil::create([
                'user_id' => $user->id,
                'cod_vendedor' => str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            ]);
        }

        return $user;
    }
}
