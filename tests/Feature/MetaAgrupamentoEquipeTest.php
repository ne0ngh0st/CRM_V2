<?php

namespace Tests\Feature;

use App\Exports\MetasExport;
use App\Http\Controllers\MetaController;
use App\Models\Faturamento;
use App\Models\MetaMensal;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * /metas agrupado por equipe, com subtotal — visão do admin/diretor.
 *
 * O cenário reproduz a hierarquia real: o `cod_super` de um SUPERVISOR aponta para o
 * diretor (CLEBER 000006 → ROBERTO 010002), e há vendedor respondendo direto ao diretor e
 * vendedor sem supervisor. As metas são escolhidas para que a ordem dos grupos por % seja
 * diferente da ordem alfabética e da ordem de criação.
 *
 *   CLEBER (000006)   meta 1.000 + 2.000 = 3.000, realizado 2.900  → 96,7%
 *   ROBERTO (010002)  meta 500, realizado 450                      → 90%
 *   SANDRA (000115)   meta 400 + 600 = 1.000, realizado 150        → 15%
 *   Sem supervisor    meta 800, realizado 800                      → 100%, mas sempre por último
 *
 * ⚠️ 010617 pertence a DUAS contas. O número aparece nas duas linhas e só pode contar
 * uma vez no subtotal — é o que diferencia "somar as linhas" de "somar os vendedores".
 */
class MetaAgrupamentoEquipeTest extends TestCase
{
    use RefreshDatabase;

    private int $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->ano = (int) now()->year;
        Carbon::setTestNow(Carbon::create($this->ano, 6, 17, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pessoa(string $role, string $cod, ?string $codSuper, string $nome): User
    {
        $user = User::factory()->create(['display_name' => $nome]);
        $user->assignRole($role);
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod, 'cod_super' => $codSuper]);

        return $user;
    }

    private function numeros(string $cod, float $meta, float $realizado, float $aberto = 0): void
    {
        MetaMensal::create(['cod_vendedor' => $cod, 'ano' => $this->ano, 'mes' => 6, 'tipo' => 'faturamento', 'valor_meta' => $meta]);
        Faturamento::create([
            'nota_fiscal' => (string) fake()->unique()->numberBetween(1, 999999),
            'data_emissao' => "{$this->ano}-06-05", 'cod_vendedor' => $cod,
            'valor_total' => $realizado, 'quantidade' => 1, 'valor_unitario' => $realizado,
        ]);
        if ($aberto > 0) {
            Pedido::create([
                'numero_pedido' => 'P'.fake()->unique()->numberBetween(1, 999999),
                'cod_vendedor' => $cod,
                'data_pedido' => "{$this->ano}-06-10",
                'data_previsao_faturamento' => "{$this->ano}-06-25",
                'valor_total' => $aberto,
                'status' => 'pendente_totvs',
            ]);
        }
    }

    /** @return array{admin: User, cleber: User} */
    private function cenario(): array
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->pessoa('diretor', '010002', null, 'ROBERTO');
        $cleber = $this->pessoa('supervisor', '000006', '010002', 'CLEBER');
        $this->pessoa('vendedor', '010617', '000006', 'ANA');
        $this->pessoa('vendedor', '010617', '000006', 'ANA (conta 2)');
        $this->pessoa('supervisor', '000115', '010395', 'SANDRA');
        $this->pessoa('vendedor', '010620', '000115', 'BRUNO');
        $this->pessoa('vendedor', '010630', '010002', 'CAIO');
        $this->pessoa('vendedor', '010640', null, 'DORA');

        $this->numeros('000006', 1000, 900, 37);
        $this->numeros('010617', 2000, 2000, 300);
        $this->numeros('000115', 400, 100);
        $this->numeros('010620', 600, 50, 70);
        $this->numeros('010630', 500, 450);
        $this->numeros('010640', 800, 800, 11);

        return ['admin' => $admin, 'cleber' => $cleber];
    }

    private function props(User $user, array $filtros = []): array
    {
        return $this->actingAs($user)
            ->get(route('metas.index', $filtros + ['ano' => $this->ano, 'mes' => 6]))
            ->assertOk()
            ->viewData('page')['props'];
    }

    private function grupo(array $props, ?string $chave): array
    {
        return collect($props['grupos'])->firstWhere('chave', $chave);
    }

    #[Test]
    public function test_supervisor_encabeca_a_propria_equipe(): void
    {
        $props = $this->props($this->cenario()['admin']);

        $linha = collect($props['linhas'])->firstWhere('codVendedor', '000006');
        $this->assertSame('000006', $linha['grupoChave'], 'agrupar por cod_super cru jogaria o CLEBER na equipe do diretor');
        $this->assertSame('CLEBER', $this->grupo($props, '000006')['nome']);
        $this->assertSame('ROBERTO', $this->grupo($props, '010002')['nome']);
    }

    /**
     * A invariante que dá sentido ao agrupamento: o subtotal da equipe do CLEBER, visto
     * pelo admin, é exatamente o "Totais" que o próprio CLEBER vê logado.
     */
    #[Test]
    public function test_subtotal_da_equipe_e_o_total_que_o_supervisor_ve(): void
    {
        $cenario = $this->cenario();

        $subtotal = $this->grupo($this->props($cenario['admin']), '000006')['subtotais'];
        $totalDoCleber = $this->props($cenario['cleber'])['totais'];

        $this->assertEquals($totalDoCleber, $subtotal);
        // Código compartilhado conta uma vez: 1.000 + 2.000, e não 1.000 + 2.000 + 2.000.
        $this->assertEqualsWithDelta(3000, $subtotal['fatMeta'], 0.001);
        $this->assertEqualsWithDelta(337, $subtotal['emAberto'], 0.001);
    }

    #[Test]
    public function test_soma_dos_subtotais_e_o_total(): void
    {
        $props = $this->props($this->cenario()['admin']);

        foreach (['fatRealizado', 'fatMeta', 'emAberto', 'faltaVender'] as $campo) {
            $this->assertEqualsWithDelta(
                $props['totais'][$campo],
                collect($props['grupos'])->sum(fn ($g) => $g['subtotais'][$campo]),
                0.001,
                "subtotais de {$campo} não fecham com o total",
            );
        }
    }

    /**
     * Busca que esvazia uma equipe tira a equipe da tela — subtotal zero leria como "não
     * vendeu nada". E a soma continua fechando com o total filtrado.
     */
    #[Test]
    public function test_grupos_seguem_o_filtro_da_tela(): void
    {
        $props = $this->props($this->cenario()['admin'], ['busca' => 'BRUNO']);

        $this->assertSame(['000115'], collect($props['grupos'])->pluck('chave')->all());
        $this->assertEquals($props['totais'], $props['grupos'][0]['subtotais']);
    }

    #[Test]
    public function test_grupos_ordenados_por_percentual_com_sem_supervisor_no_fim(): void
    {
        $props = $this->props($this->cenario()['admin']);

        $this->assertSame(
            ['000006', '010002', '000115', null],
            collect($props['grupos'])->pluck('chave')->all(),
        );
        $this->assertSame('Sem supervisor', $this->grupo($props, null)['nome']);
    }

    #[Test]
    public function test_supervisor_logado_ve_lista_plana(): void
    {
        $this->assertSame([], $this->props($this->cenario()['cleber'])['grupos']);
    }

    #[Test]
    public function test_admin_com_supervisor_escolhido_ve_lista_plana(): void
    {
        $props = $this->props($this->cenario()['admin'], ['visao_supervisor' => '000006']);

        $this->assertSame([], $props['grupos']);
    }

    /**
     * Cabeçalho e linha da planilha são duas listas mantidas à mão; desalinhadas, o Excel
     * sai com os valores sob o rótulo errado e ninguém percebe.
     */
    #[Test]
    public function test_planilha_tem_as_colunas_alinhadas_e_a_equipe(): void
    {
        $cenario = $this->cenario();
        $request = Request::create('/metas/exportar', 'POST', ['ano' => $this->ano, 'mes' => 6]);

        [$linhas] = app(MetaController::class)->linhasDoRanking($request, $cenario['admin']);
        $export = new MetasExport($linhas);
        $dados = $export->array();

        $this->assertCount(count($export->headings()), $dados[0]);

        $cleber = collect($dados)->first(fn ($l) => $l[1] === '000006');
        $this->assertSame('CLEBER', $cleber[array_search('Equipe', $export->headings(), true)]);
        // 1.000 − 900 − 37.
        $this->assertSame(63.0, $cleber[array_search('Falta vender', $export->headings(), true)]);
    }
}
