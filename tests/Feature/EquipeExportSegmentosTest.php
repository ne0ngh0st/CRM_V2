<?php

namespace Tests\Feature;

use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Exportacao\CatalogoDeExportacoes;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A coluna "Segmentos" do Excel da Equipe, que a tela já mostrava e a planilha não.
 *
 * O que estes testes protegem:
 *  1. a coluna sai com os mesmos nomes que a tela mostra (uma fonte só — Regra nº 8);
 *  2. quem não tem segmento (ou não tem código de vendedor) sai em branco, e não com
 *     o segmento da linha anterior — é o erro típico de um mapa memoizado;
 *  3. o custo é constante, não uma consulta por linha.
 */
class EquipeExportSegmentosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(string $nome, ?string $codVendedor): User
    {
        $user = User::factory()->create(['display_name' => $nome, 'is_active' => true]);
        $user->assignRole('vendedor');

        if ($codVendedor !== null) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor]);
        }

        return $user;
    }

    private function atribuirSegmento(string $codVendedor, string $codigo, string $nome): void
    {
        $segmento = Segmento::firstOrCreate(['codigo' => $codigo], ['nome' => $nome]);
        SegmentoVendedor::create(['cod_vendedor' => $codVendedor, 'segmento_id' => $segmento->id]);
    }

    /** @return array<string, array<int, string>> nome do usuário => linha da planilha */
    private function linhas(User $admin): array
    {
        $request = Request::create('/equipe/exportar', 'POST');
        $request->setUserResolver(fn () => $admin);

        $export = app(CatalogoDeExportacoes::class)->plano('equipe', $request, $admin)->planilha;

        $linhas = [];
        foreach ($export->query()->get() as $usuario) {
            $linha = $export->map($usuario);
            $linhas[$linha[0]] = $linha;
        }

        return $linhas;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['display_name' => 'Admin', 'is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_coluna_segmentos_existe_no_cabecalho(): void
    {
        $admin = $this->admin();

        $request = Request::create('/equipe/exportar', 'POST');
        $request->setUserResolver(fn () => $admin);

        $export = app(CatalogoDeExportacoes::class)->plano('equipe', $request, $admin)->planilha;

        $this->assertSame(
            ['Usuário', 'E-mail', 'Perfil', 'Status', 'Estado', 'Cód. Vendedor', 'Cód. Supervisor', 'Segmentos', 'Último Login'],
            $export->headings(),
        );
    }

    public function test_segmentos_do_vendedor_saem_na_linha_dele(): void
    {
        $admin = $this->admin();
        $this->vendedor('Fernanda', '000123');
        $this->atribuirSegmento('000123', '101', 'SUPERMERCADISTA');
        $this->atribuirSegmento('000123', '109', 'DROGARIAS');

        $linhas = $this->linhas($admin);

        // Ordem alfabética, igual à da tela.
        $this->assertSame('DROGARIAS, SUPERMERCADISTA', $linhas['Fernanda'][7]);
    }

    public function test_quem_nao_tem_segmento_ou_codigo_sai_em_branco(): void
    {
        $admin = $this->admin();
        $this->vendedor('Com segmento', '000123');
        $this->atribuirSegmento('000123', '101', 'SUPERMERCADISTA');
        $this->vendedor('Sem segmento', '000456');
        $this->vendedor('Sem codigo', null);

        $linhas = $this->linhas($admin);

        $this->assertSame('SUPERMERCADISTA', $linhas['Com segmento'][7]);
        $this->assertSame('', $linhas['Sem segmento'][7]);
        $this->assertSame('', $linhas['Sem codigo'][7]);
    }

    public function test_cada_vendedor_recebe_o_proprio_segmento(): void
    {
        $admin = $this->admin();
        $this->vendedor('Vendedor A', '000123');
        $this->vendedor('Vendedor B', '000456');
        $this->atribuirSegmento('000123', '101', 'SUPERMERCADISTA');
        $this->atribuirSegmento('000456', '103', 'ORGAO PUBLICO');

        $linhas = $this->linhas($admin);

        $this->assertSame('SUPERMERCADISTA', $linhas['Vendedor A'][7]);
        $this->assertSame('ORGAO PUBLICO', $linhas['Vendedor B'][7]);
    }

    /**
     * ⚠️ Teto de queries: `map()` roda por usuário, então uma consulta de segmentos ali
     * dentro seria N+1 dentro de cada chunk — invisível com 3 linhas de fixture e caro
     * com os 201 usuários reais. Se alguém trocar o mapa memoizado por consulta por
     * linha, este teste é quem acusa.
     */
    public function test_segmentos_custam_o_mesmo_para_qualquer_numero_de_linhas(): void
    {
        $admin = $this->admin();
        foreach (range(1, 8) as $i) {
            $cod = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $this->vendedor("Vendedor {$i}", $cod);
            $this->atribuirSegmento($cod, '101', 'SUPERMERCADISTA');
        }

        $request = Request::create('/equipe/exportar', 'POST');
        $request->setUserResolver(fn () => $admin);
        $export = app(CatalogoDeExportacoes::class)->plano('equipe', $request, $admin)->planilha;
        $usuarios = $export->query()->get();

        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach ($usuarios as $usuario) {
            $export->map($usuario);
        }
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 2 do mapa de segmentos (códigos + vínculos). O resto seria N+1.
        $this->assertLessThanOrEqual(3, $queries, "map() gastou {$queries} queries para 9 usuários");
    }
}
