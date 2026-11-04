<?php

namespace Tests\Feature;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Equipe\OrganogramaBuilder;
use App\Services\Vendedores\NomeVendedorResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O assistente usa o código do gestor que apoia e nunca é dono dele (2026-11-04).
 *
 * Caso real: a Ana Clara foi cadastrada com o código da Sandra (000115) e, por ser a
 * conta mais nova, a equipe da Sandra passou a aparecer como "Ana Clara" na Equipe — e o
 * vendedor de toda a carteira dela também.
 *
 * ⚠️ Em todo teste a assistente é criada DEPOIS do gestor: era a ordem de criação que
 * fazia ela vencer. Criada antes, os testes passariam com o defeito.
 */
class AssistenteEspelhoTest extends TestCase
{
    use RefreshDatabase;

    private const COD_SANDRA = '000115';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $perfil, string $nome, ?string $codigo, ?string $codSuper = null): User
    {
        $user = User::factory()->create(['name' => $nome, 'display_name' => $nome, 'is_active' => true]);
        $user->assignRole($perfil);
        if ($codigo) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codigo, 'cod_super' => $codSuper]);
        }

        return $user;
    }

    public function test_o_nome_do_codigo_e_do_gestor_e_nao_da_assistente(): void
    {
        $this->usuario('supervisor', 'SANDRA', self::COD_SANDRA, '010395');
        $this->usuario('assistente', 'ANA CLARA', self::COD_SANDRA, self::COD_SANDRA);

        $resolver = app(NomeVendedorResolver::class);

        $this->assertSame('SANDRA', $resolver->para(self::COD_SANDRA));
        $this->assertSame('SANDRA', $resolver->todos()->get(self::COD_SANDRA));
    }

    public function test_o_grupo_da_equipe_tem_o_nome_do_gestor(): void
    {
        $admin = $this->usuario('admin', 'ADMIN', null);
        $this->usuario('supervisor', 'SANDRA', self::COD_SANDRA, '010395');
        $this->usuario('vendedor', 'ALESSANDRA', '010586', self::COD_SANDRA);
        $this->usuario('assistente', 'ANA CLARA', self::COD_SANDRA, self::COD_SANDRA);

        $grupos = collect($this->actingAs($admin)->get(route('equipe.index'))->assertOk()
            ->viewData('page')['props']['usuarios']);

        $grupo = $grupos->firstWhere('supervisorCod', self::COD_SANDRA);
        $this->assertSame('SANDRA', $grupo['supervisorNome']);
        $this->assertEqualsCanonicalizing(['ALESSANDRA', 'ANA CLARA'], collect($grupo['usuarios'])->pluck('nome')->all());
    }

    public function test_assistente_nao_aparece_como_responsavel_do_cliente(): void
    {
        $this->usuario('supervisor', 'SANDRA', self::COD_SANDRA);
        $this->usuario('assistente', 'ANA CLARA', self::COD_SANDRA);

        $metodo = new \ReflectionMethod(\App\Services\Cadastros\BuscaTitularidade::class, 'responsaveisPorCodigo');
        $mapa = $metodo->invoke(app(\App\Services\Cadastros\BuscaTitularidade::class), [self::COD_SANDRA]);

        $this->assertSame(['SANDRA'], $mapa[self::COD_SANDRA]['responsaveis']);
    }

    public function test_assistente_nao_vira_no_pai_no_organograma(): void
    {
        $nos = collect([
            // A assistente primeiro: com `first()` cru ela viraria a dona do código.
            ['id' => 2, 'codVendedor' => self::COD_SANDRA, 'codSuper' => self::COD_SANDRA, 'nome' => 'ANA', 'perfil' => 'assistente'],
            ['id' => 1, 'codVendedor' => self::COD_SANDRA, 'codSuper' => null, 'nome' => 'SANDRA', 'perfil' => 'supervisor'],
            ['id' => 3, 'codVendedor' => '010586', 'codSuper' => self::COD_SANDRA, 'nome' => 'ALESSANDRA', 'perfil' => 'vendedor'],
        ]);

        $arvore = app(OrganogramaBuilder::class)->construir($nos);

        $this->assertSame(['SANDRA'], collect($arvore)->pluck('nome')->all());
        $this->assertEqualsCanonicalizing(['ANA', 'ALESSANDRA'], collect($arvore[0]['filhos'])->pluck('nome')->all());
    }

    public function test_editar_a_assistente_nao_apaga_os_segmentos_do_gestor(): void
    {
        $admin = $this->usuario('admin', 'ADMIN', null);
        $this->usuario('supervisor', 'SANDRA', self::COD_SANDRA);
        $ana = $this->usuario('assistente', 'ANA CLARA', self::COD_SANDRA, self::COD_SANDRA);

        $segmento = Segmento::create(['codigo' => '777', 'nome' => 'TESTE']);
        SegmentoVendedor::create(['cod_vendedor' => self::COD_SANDRA, 'segmento_id' => $segmento->id]);

        $payload = [
            'name' => 'ANA CLARA', 'email' => $ana->email, 'perfil' => 'assistente',
            'cod_vendedor' => self::COD_SANDRA, 'cod_super' => self::COD_SANDRA, 'segmentos' => [],
        ];

        $this->actingAs($admin)->patch(route('equipe.update', $ana), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, SegmentoVendedor::where('cod_vendedor', self::COD_SANDRA)->count());

        // Tirar o código dela também não apaga o do gestor.
        $this->actingAs($admin)->patch(route('equipe.update', $ana), [...$payload, 'cod_vendedor' => '', 'cod_super' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, SegmentoVendedor::where('cod_vendedor', self::COD_SANDRA)->count());
    }

    public function test_codigo_digitado_sem_zero_a_esquerda_e_completado(): void
    {
        $admin = $this->usuario('admin', 'ADMIN', null);
        $melissa = $this->usuario('assistente', 'MELISSA', null);

        $this->actingAs($admin)->patch(route('equipe.update', $melissa), [
            'name' => 'MELISSA', 'email' => $melissa->email, 'perfil' => 'assistente',
            'cod_vendedor' => '00006', 'cod_super' => '6',
        ])->assertSessionHasNoErrors();

        $perfil = $melissa->fresh()->vendedorPerfil;
        $this->assertSame('000006', $perfil->cod_vendedor);
        $this->assertSame('000006', $perfil->cod_super);
    }
}
