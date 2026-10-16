<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\RedeMercado;
use App\Models\RedeMercadoVinculo;
use App\Models\User;
use App\Services\VisaoDiretor\Redes\MaioresRedes;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Página Visão Diretor → Maiores redes: acesso, números batendo com a lista, e o vínculo
 * feito pela tela sobrevivendo à reconstrução automática.
 */
class MaioresRedesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $loja = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->filial('000010', '112', 'CACAU SHOW SHOPPING NORTE', now()->subDays(20));
        $this->filial('000011', '112', 'CACAU SHOW MOOCA', now()->subDays(500));
        $this->filial('000020', '112', 'KOPENHAGEN IBIRAPUERA', now()->subDays(400));
        $this->filial('000030', '101', 'PADARIA DO ZE', now()->subDays(5));

        app(MaioresRedes::class)->importar(['redes' => [
            $this->rede(1, 'CACAU SHOW', 4781),
            $this->rede(2, 'KOPENHAGEN', 850),
            $this->rede(3, 'BRASIL CACAU', 500),
        ]]);
        app(MaioresRedes::class)->reconstruir();
    }

    private function filial(string $codigo, string $segmento, string $fantasia, $ultimaCompra): void
    {
        $this->loja++;
        Cliente::create([
            'cod_cliente' => $codigo, 'loja' => str_pad((string) $this->loja, 4, '0', STR_PAD_LEFT),
            'razao_social' => 'EMPRESA '.$codigo.' LTDA', 'nome_fantasia' => $fantasia, 'cod_grupo' => '9998',
            'cod_segmento' => $segmento, 'cod_vendedor' => '000001', 'estado' => 'SP', 'data_ultima_compra' => $ultimaCompra,
        ]);
    }

    private function rede(int $posicao, string $nome, ?int $lojas): array
    {
        return ['posicao' => $posicao, 'categoria' => 'alimentacao', 'nome' => $nome, 'apelidos' => [], 'uf' => 'SP', 'lojas' => $lojas,
            'data_base' => 'dez/25', 'qualidade' => 'atualizado', 'fonte' => null, 'linha_original' => $nome];
    }

    private function rede_(string $nome): RedeMercado
    {
        return RedeMercado::where('nome', $nome)->firstOrFail();
    }

    public function test_so_admin_e_diretor_entram(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        $rede = $this->rede_('CACAU SHOW');

        $this->actingAs($vendedor)->get(route('visao-diretor.redes.index'))->assertForbidden();
        $this->actingAs($vendedor)->getJson(route('visao-diretor.redes.vinculos', $rede))->assertForbidden();
        $this->actingAs($vendedor)->postJson(route('visao-diretor.redes.vincular', $rede), ['tipo' => 'cliente', 'codigo' => '000030'])->assertForbidden();
        $this->actingAs($vendedor)->deleteJson(route('visao-diretor.redes.remover', $rede), ['tipo' => 'cliente', 'codigo' => '000010'])->assertForbidden();
    }

    public function test_contadores_e_status_da_rede(): void
    {
        $cacau = $this->rede_('CACAU SHOW');
        $kop = $this->rede_('KOPENHAGEN');

        $this->assertSame([2, 2, 1, 'ativo'], [$cacau->clientes, $cacau->filiais, $cacau->clientes_ativos, $cacau->status]);
        $this->assertSame(now()->subDays(20)->toDateString(), $cacau->ultima_compra->toDateString());
        // Só cliente parado: A trabalhar. Rede sem nada: A trabalhar.
        $this->assertSame('a_trabalhar', $kop->status);
        $this->assertSame([0, 'a_trabalhar'], [$this->rede_('BRASIL CACAU')->clientes, $this->rede_('BRASIL CACAU')->status]);
    }

    /** Os tiles contam a categoria com a busca e SEM o próprio filtro de status, e batem com a lista. */
    public function test_tiles_batem_com_a_lista(): void
    {
        $this->actingAs($this->admin)
            ->get(route('visao-diretor.redes.index', ['categoria' => 'alimentacao', 'status' => 'a_trabalhar']))
            ->assertInertia(fn (Assert $p) => $p
                ->component('VisaoDiretor/MaioresRedes')
                ->where('lista.contagens', ['total' => 3, 'ativo' => 1, 'a_trabalhar' => 2])
                ->has('lista.linhas', 2)
                ->where('lista.linhas.0.nome', 'KOPENHAGEN')
                ->where('resumo', fn ($r) => collect($r)->firstWhere('categoria', 'alimentacao')['ativo'] === 1));
    }

    public function test_status_invalido_na_url_e_ignorado(): void
    {
        $this->actingAs($this->admin)
            ->get(route('visao-diretor.redes.index', ['categoria' => 'alimentacao', 'status' => "ativo' OR 1=1"]))
            ->assertInertia(fn (Assert $p) => $p->where('filtros.status', '')->has('lista.linhas', 3));
    }

    /** Vínculo à mão move o cliente (uma rede só) e a reconstrução não o desfaz. */
    public function test_vinculo_manual_move_e_sobrevive_a_reconstrucao(): void
    {
        $kop = $this->rede_('KOPENHAGEN');

        $this->actingAs($this->admin)
            ->postJson(route('visao-diretor.redes.vincular', $kop), ['tipo' => 'cliente', 'codigo' => '000010'])
            ->assertOk()
            ->assertJsonPath('rede.clientes', 2)
            ->assertJsonPath('rede.status', 'ativo');

        app(MaioresRedes::class)->reconstruir();

        $this->assertSame([$kop->id], RedeMercadoVinculo::where('codigo', '000010')->where('recusado', false)->pluck('rede_id')->all());
        $this->assertSame(1, $this->rede_('CACAU SHOW')->clientes);
        $this->assertSame('manual', RedeMercadoVinculo::where('codigo', '000010')->value('nivel'));
    }

    /** Remover grava a recusa: a reconstrução não religa o cliente àquela rede. */
    public function test_remover_nao_volta_na_reconstrucao(): void
    {
        $cacau = $this->rede_('CACAU SHOW');

        $this->actingAs($this->admin)
            ->deleteJson(route('visao-diretor.redes.remover', $cacau), ['tipo' => 'cliente', 'codigo' => '000010'])
            ->assertOk()
            ->assertJsonPath('rede.clientes', 1)
            ->assertJsonPath('rede.status', 'a_trabalhar');

        app(MaioresRedes::class)->reconstruir();

        $this->assertSame(1, $this->rede_('CACAU SHOW')->clientes);
        $this->assertFalse(RedeMercadoVinculo::where('codigo', '000010')->where('recusado', false)->exists());
    }

    public function test_vincular_codigo_inexistente_e_recusado(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('visao-diretor.redes.vincular', $this->rede_('KOPENHAGEN')), ['tipo' => 'cliente', 'codigo' => '999999'])
            ->assertStatus(422);
    }

    /** O banco garante: o mesmo cliente vivo em duas redes não grava. */
    public function test_banco_recusa_cliente_vivo_em_duas_redes(): void
    {
        $this->expectException(QueryException::class);

        DB::table('redes_mercado_vinculos')->insert([
            'rede_id' => $this->rede_('BRASIL CACAU')->id, 'tipo' => 'cliente', 'codigo' => '000010', 'nivel' => 'manual', 'recusado' => false,
        ]);
    }

    /** Rede que saiu do JSON some da tela, sem perder vínculo manual. */
    public function test_rede_fora_do_json_e_arquivada(): void
    {
        $r = app(MaioresRedes::class)->importar(['redes' => [$this->rede(1, 'CACAU SHOW', 4781), $this->rede(2, 'KOPENHAGEN', 850)]]);

        $this->assertSame(1, $r['arquivadas']);
        $this->actingAs($this->admin)
            ->get(route('visao-diretor.redes.index', ['categoria' => 'alimentacao']))
            ->assertInertia(fn (Assert $p) => $p->has('lista.linhas', 2));
    }

    public function test_linha_aberta_lista_clientes_com_responsavel_e_status(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('visao-diretor.redes.vinculos', $this->rede_('CACAU SHOW')))
            ->assertOk()
            ->assertJsonCount(2, 'clientes')
            // Ativos primeiro.
            ->assertJsonPath('clientes.0.codigo', '000010')
            ->assertJsonPath('clientes.0.status', 'ativo')
            ->assertJsonPath('clientes.1.status', 'a_trabalhar')
            ->assertJsonStructure(['clientes' => [['nome', 'responsavel', 'ultima_compra', 'status', 'nivel', 'cliente_id']], 'leads']);
    }
}
