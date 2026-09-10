<?php

namespace Tests\Feature;

use App\Exports\CarteiraExport;
use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Exportacao\CatalogoDeExportacoes;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Telefone e e-mail no Excel da Carteira — o cadastro já tinha, a planilha não.
 *
 * Pedido real: exportar inativos pra ligar, e a planilha saía sem como contatar.
 * O nome do contato principal NÃO entra: o TOTVS 210 não manda A1_CONTATO, e
 * inventar coluna vazia só faria parecer que o dado existe.
 */
class CarteiraExportContatoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['display_name' => 'Admin', 'is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function planilha(User $admin): CarteiraExport
    {
        $request = Request::create('/carteira/exportar', 'POST');
        $request->setUserResolver(fn () => $admin);

        return app(CatalogoDeExportacoes::class)->plano('carteira', $request, $admin)->planilha;
    }

    public function test_telefone_e_email_existem_no_cabecalho(): void
    {
        $export = $this->planilha($this->admin());

        $this->assertSame(
            ['Cliente', 'CNPJ', 'Telefone', 'E-mail', 'Grupo', 'Vendedor', 'Estado', 'Segmento', 'Status', 'Aderência', 'Última Compra'],
            $export->headings(),
        );
    }

    public function test_telefone_e_email_saem_na_linha_do_cliente(): void
    {
        $admin = $this->admin();
        VendedorPerfil::create(['user_id' => $admin->id, 'cod_vendedor' => '000123']);

        Cliente::create([
            'cod_cliente' => '000001',
            'loja' => '01',
            'razao_social' => 'SUPERMERCADO MODELO',
            'cnpj' => '11.111.111/0001-11',
            'cod_vendedor' => '000123',
            'telefone' => '(11) 98888-7777',
            'email' => 'compras@modelo.com.br',
        ]);

        $export = $this->planilha($admin);
        $linha = $export->map($export->query()->sole());

        $this->assertSame('SUPERMERCADO MODELO', $linha[0]);
        $this->assertSame('(11) 98888-7777', $linha[2]);
        $this->assertSame('compras@modelo.com.br', $linha[3]);
    }

    public function test_cliente_sem_telefone_ou_email_sai_em_branco(): void
    {
        $admin = $this->admin();
        VendedorPerfil::create(['user_id' => $admin->id, 'cod_vendedor' => '000123']);

        Cliente::create([
            'cod_cliente' => '000001',
            'loja' => '01',
            'razao_social' => 'COM TELEFONE',
            'cod_vendedor' => '000123',
            'telefone' => '(11) 98888-7777',
            'email' => 'a@modelo.com.br',
        ]);
        Cliente::create([
            'cod_cliente' => '000002',
            'loja' => '01',
            'razao_social' => 'SEM CONTATO',
            'cod_vendedor' => '000123',
        ]);

        $export = $this->planilha($admin);
        $linhas = $export->query()->get()->map(fn ($c) => $export->map($c))->keyBy(0);

        $this->assertSame('(11) 98888-7777', $linhas['COM TELEFONE'][2]);
        $this->assertSame('a@modelo.com.br', $linhas['COM TELEFONE'][3]);
        $this->assertNull($linhas['SEM CONTATO'][2]);
        $this->assertNull($linhas['SEM CONTATO'][3]);
    }
}
