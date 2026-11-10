<?php

namespace Tests\Feature;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Equipe\QuadroSegmentosResolver;
use App\Support\Perf\ContadorDeQueries;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tabela de cobertura por segmento (`/equipe/segmentos`).
 *
 * O que estes testes protegem:
 *  1. a pergunta é "quem é de cada segmento na EQUIPE", não a lista filtrada;
 *  2. supervisor vê só a própria equipe e consegue atribuir nela;
 *  3. representante não sai de SUPERMERCADISTA, nem pela tela nem pelo modal
 *     da lista — a regra mora na escrita, não no Vue;
 *  4. o custo não cresce com o número de vendedores (N+1 no map).
 */
class EquipeQuadroSegmentosTest extends TestCase
{
    use RefreshDatabase;

    private const EQUIPE = QuadroSegmentosResolver::EQUIPES_INTERNAS[0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function segmento(string $codigo, string $nome): Segmento
    {
        return Segmento::create(['codigo' => $codigo, 'nome' => $nome]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function usuario(string $role, ?string $cod = null, ?string $codSuper = null, array $attrs = [], ?string $display = null): User
    {
        $user = User::factory()->create(array_merge(
            ['is_active' => true],
            $display ? ['display_name' => $display] : [],
            $attrs,
        ));
        $user->assignRole($role);

        if ($cod !== null) {
            VendedorPerfil::create([
                'user_id' => $user->id,
                'cod_vendedor' => $cod,
                'cod_super' => $codSuper,
            ]);
        }

        return $user;
    }

    private function atribuir(string $cod, Segmento ...$segmentos): void
    {
        foreach ($segmentos as $segmento) {
            SegmentoVendedor::create(['cod_vendedor' => $cod, 'segmento_id' => $segmento->id]);
        }
    }

    private function admin(): User
    {
        return $this->usuario('admin');
    }

    private function quadroDe(User $user): array
    {
        return $this->actingAs($user)
            ->get(route('equipe.segmentos'))
            ->assertOk()
            ->viewData('page')['props']['quadro'];
    }

    public function test_admin_ve_quem_e_de_cada_segmento(): void
    {
        $super = $this->segmento('101', 'SUPERMERCADISTA');
        $drogaria = $this->segmento('109', 'DROGARIAS');

        $this->usuario('vendedor', '000111', self::EQUIPE, display: 'Ana');
        $this->usuario('vendedor', '000222', self::EQUIPE);
        $this->atribuir('000111', $super, $drogaria);
        $this->atribuir('000222', $super);

        $quadro = $this->quadroDe($this->admin());

        $ana = collect($quadro['pessoas'])->firstWhere('codVendedor', '000111');
        $this->assertEqualsCanonicalizing([$super->id, $drogaria->id], $ana['segmentosIds']);
        $this->assertSame(2, $quadro['totais']['comSegmento']);
        $this->assertSame(0, $quadro['totais']['semSegmento']);
        $this->assertSame(2, $quadro['totais']['segmentosComGente']);
    }

    public function test_sem_segmento_entra_no_total_e_inativo_fica_de_fora(): void
    {
        $this->segmento('101', 'SUPERMERCADISTA');
        $this->usuario('vendedor', '000111', self::EQUIPE);
        $this->usuario('vendedor', '000222', self::EQUIPE, attrs: ['is_active' => false]);
        $this->usuario('vendedor', null);

        $quadro = $this->quadroDe($this->admin());

        $codigos = collect($quadro['pessoas'])->pluck('codVendedor')->all();
        $this->assertSame(['000111'], $codigos);
        $this->assertSame(1, $quadro['totais']['semSegmento']);
        $this->assertSame(0, $quadro['totais']['comSegmento']);
    }

    public function test_supervisor_ve_so_a_equipe_e_atribui_segmento(): void
    {
        $drogaria = $this->segmento('109', 'DROGARIAS');
        $supervisor = $this->usuario('supervisor', '000100');
        $daEquipe = $this->usuario('vendedor', '000111', '000100');
        $deFora = $this->usuario('vendedor', '000999', '000200');

        $quadro = $this->quadroDe($supervisor);
        $codigos = collect($quadro['pessoas'])->pluck('codVendedor')->all();
        // O supervisor é o cabeçalho do grupo, não uma linha (ele não tem segmento gravado).
        $this->assertSame(['000111'], $codigos);
        $this->assertSame(['000100'], array_column($quadro['equipes'], 'codigo'));
        $this->assertSame('000100', $quadro['pessoas'][0]['equipe']);

        $this->actingAs($supervisor)
            ->patch(route('equipe.atualizarSegmentos', $daEquipe), [
                'segmentos' => [$drogaria->id],
            ])
            ->assertRedirect();

        $this->assertTrue(
            SegmentoVendedor::query()
                ->where('cod_vendedor', '000111')
                ->where('segmento_id', $drogaria->id)
                ->exists(),
        );

        $this->actingAs($supervisor)
            ->patch(route('equipe.atualizarSegmentos', $deFora), [
                'segmentos' => [$drogaria->id],
            ])
            ->assertForbidden();
    }

    public function test_so_entram_as_equipes_internas_e_nunca_representante(): void
    {
        [$americo, $sandra] = QuadroSegmentosResolver::EQUIPES_INTERNAS;
        $this->usuario('supervisor', $americo);
        $this->usuario('supervisor', $sandra);
        $this->usuario('vendedor', '000111', $americo);
        $this->usuario('vendedor', '000222', $sandra);
        $this->usuario('representante', '000333', $americo);
        $this->usuario('vendedor', '000444', '000006');

        $codigos = collect($this->quadroDe($this->admin())['pessoas'])->pluck('codVendedor')->all();

        $this->assertEqualsCanonicalizing(['000111', '000222'], $codigos);
    }

    /**
     * O layout é SUPERVISOR → VENDEDOR → SEGMENTOS: cada pessoa cai na equipe do gestor
     * dela, e as equipes saem com nome, na ordem de EQUIPES_INTERNAS.
     */
    public function test_cada_vendedor_cai_na_equipe_do_seu_supervisor(): void
    {
        [$americo, $sandra] = QuadroSegmentosResolver::EQUIPES_INTERNAS;
        $this->usuario('supervisor', $americo, display: 'Americo');
        $this->usuario('supervisor', $sandra, display: 'Sandra');
        $this->usuario('vendedor', '000111', $americo);
        $this->usuario('vendedor', '000222', $sandra);

        $quadro = $this->quadroDe($this->admin());

        $this->assertSame(
            [['codigo' => $americo, 'nome' => 'Americo'], ['codigo' => $sandra, 'nome' => 'Sandra']],
            $quadro['equipes'],
        );
        $porCodigo = collect($quadro['pessoas'])->pluck('equipe', 'codVendedor')->all();
        $this->assertSame(['000111' => $americo, '000222' => $sandra], $porCodigo);
    }

    /**
     * O gestor só é cabeçalho. Mas se o CÓDIGO dele tem segmento gravado, ele vira linha da
     * própria equipe — senão o segmento existiria sem aparecer em lugar nenhum da tela.
     * O assistente usa o mesmo código e segue a mesma regra.
     */
    public function test_gestor_so_vira_linha_quando_o_codigo_tem_segmento(): void
    {
        [$americo, $sandra] = QuadroSegmentosResolver::EQUIPES_INTERNAS;
        $drogaria = $this->segmento('109', 'DROGARIAS');
        $this->usuario('supervisor', $americo);
        $this->usuario('supervisor', $sandra);
        $this->usuario('assistente', $sandra, $sandra);
        $this->atribuir($americo, $drogaria);

        $pessoas = collect($this->quadroDe($this->admin())['pessoas']);

        $this->assertSame([$americo], $pessoas->pluck('codVendedor')->all());
        $this->assertSame($americo, $pessoas[0]['equipe']);
    }

    public function test_vendedor_nao_acessa_o_quadro(): void
    {
        $vendedor = $this->usuario('vendedor', '000111');

        $this->actingAs($vendedor)
            ->get(route('equipe.segmentos'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($vendedor)
            ->patch(route('equipe.atualizarSegmentos', $vendedor), ['segmentos' => []])
            ->assertForbidden();
    }

    public function test_representante_fica_em_supermercadista_mesmo_mandando_outro(): void
    {
        $super = $this->segmento('101', 'SUPERMERCADISTA');
        $drogaria = $this->segmento('109', 'DROGARIAS');
        $rep = $this->usuario('representante', '000333');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('equipe.atualizarSegmentos', $rep), [
                'segmentos' => [$drogaria->id],
            ])
            ->assertRedirect();

        $ids = SegmentoVendedor::query()->where('cod_vendedor', '000333')->pluck('segmento_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$super->id], $ids);
    }

    /**
     * A regra tem que valer no modal antigo também — senão o quadro "trava" e a
     * lista destrava, e ninguém liga uma coisa na outra.
     */
    public function test_editar_usuario_tambem_forca_supermercadista_no_representante(): void
    {
        $super = $this->segmento('101', 'SUPERMERCADISTA');
        $drogaria = $this->segmento('109', 'DROGARIAS');
        $rep = $this->usuario('representante', '000333');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('equipe.update', $rep), [
                'name' => $rep->name,
                'display_name' => $rep->display_name,
                'email' => $rep->email,
                'perfil' => 'representante',
                'cod_vendedor' => '000333',
                'segmentos' => [$drogaria->id],
            ])
            ->assertRedirect();

        $ids = SegmentoVendedor::query()->where('cod_vendedor', '000333')->pluck('segmento_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$super->id], $ids);
    }

    public function test_codigo_compartilhado_muda_os_dois(): void
    {
        $drogaria = $this->segmento('109', 'DROGARIAS');
        $a = $this->usuario('vendedor', '000111', self::EQUIPE);
        $this->usuario('vendedor', '000111', self::EQUIPE);

        $this->actingAs($this->admin())
            ->patch(route('equipe.atualizarSegmentos', $a), [
                'segmentos' => [$drogaria->id],
            ])
            ->assertRedirect();

        $quadro = $this->quadroDe($this->admin());
        $doCodigo = collect($quadro['pessoas'])->where('codVendedor', '000111');
        $this->assertCount(2, $doCodigo);
        $this->assertTrue($doCodigo->every(fn (array $p) => $p['compartilhado'] === true));
        $this->assertTrue($doCodigo->every(fn (array $p) => $p['segmentosIds'] === [$drogaria->id]));
    }

    public function test_sem_codigo_de_vendedor_nao_grava(): void
    {
        $drogaria = $this->segmento('109', 'DROGARIAS');
        $semCodigo = $this->usuario('vendedor');

        $this->actingAs($this->admin())
            ->patch(route('equipe.atualizarSegmentos', $semCodigo), [
                'segmentos' => [$drogaria->id],
            ])
            ->assertStatus(422);
    }

    public function test_segmento_invalido_nao_grava_nada(): void
    {
        $vendedor = $this->usuario('vendedor', '000111');

        $this->actingAs($this->admin())
            ->patch(route('equipe.atualizarSegmentos', $vendedor), [
                'segmentos' => [999999],
            ])
            ->assertSessionHasErrors('segmentos.0');

        $this->assertSame(0, SegmentoVendedor::query()->where('cod_vendedor', '000111')->count());
    }

    /**
     * ⚠️ Teto de queries: o quadro mapeia pessoas em PHP. Uma consulta de
     * segmento por pessoa seria N+1 invisível com 3 linhas de fixture. Se o
     * número crescer com a equipe, este teste é quem acusa.
     */
    public function test_custo_nao_cresce_com_o_numero_de_vendedores(): void
    {
        $this->segmento('101', 'SUPERMERCADISTA');
        $admin = $this->admin();

        foreach (range(1, 3) as $i) {
            $cod = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $this->usuario('vendedor', $cod);
        }

        $this->actingAs($admin)->get(route('equipe.segmentos'))->assertOk();

        $comTres = ContadorDeQueries::contar(
            fn () => $this->actingAs($admin)->get(route('equipe.segmentos')),
        );

        foreach (range(4, 12) as $i) {
            $cod = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $this->usuario('vendedor', $cod);
        }

        $comDoze = ContadorDeQueries::contar(
            fn () => $this->actingAs($admin)->get(route('equipe.segmentos')),
        );

        $this->assertSame(
            $comTres,
            $comDoze,
            "3 vendedores: {$comTres} queries; 12 vendedores: {$comDoze}",
        );
        $this->assertLessThanOrEqual(15, $comDoze);
    }
}
