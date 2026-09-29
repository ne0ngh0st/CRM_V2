<?php

namespace Tests\Feature;

use App\Exports\CarteiraExport;
use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Models\VendedorTotvs;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Vendedores\NomeVendedorResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EscreveRelatoriosDeCliente;
use Tests\TestCase;

/**
 * O nome do vendedor na tela, do relatório do TOTVS até a célula.
 *
 * O defeito que originou isto: a Carteira exibia "010148" no lugar de "RICARDO CAMPOS
 * SANTANA" para 25.208 clientes (27% da base), porque o único fallback era o próprio
 * código e 320 dos 444 códigos da base não têm conta no CRM.
 *
 * ⚠️ Os testes de IMPORT escrevem CSV de verdade e rodam o comando de verdade. Sem isso,
 * "o resolver sabe cair para o TOTVS" e "o import gravou o nome certo" seriam duas
 * afirmações com só a primeira provada — e a segunda é onde mora a armadilha (o 199 tem
 * DUAS colunas `Nome`, uma do cliente e uma do vendedor).
 */
class NomeVendedorTest extends TestCase
{
    use EscreveRelatoriosDeCliente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->prepararRelatorios();
    }

    protected function tearDown(): void
    {
        $this->removerRelatorios();

        parent::tearDown();
    }

    // ───────────────────────── o import ─────────────────────────

    #[Test]
    public function test_import_grava_o_nome_do_vendedor_vindo_do_relatorio_199(): void
    {
        $this->escreverRelatorio210([
            ['cod' => '006878', 'loja' => '0001', 'nome' => 'DROGARIA UNIFARMA LTDA. ME', 'vendedor' => '010148'],
        ]);
        $this->escreverRelatorio199([
            ['cod' => '006878', 'loja' => '0001', 'cliente' => 'DROGARIA UNIFARMA LTDA. ME',
                'codVendedor' => '010148', 'nomeVendedor' => 'RICARDO CAMPOS SANTANA', 'reduzido' => 'RICARDO CAMPOS - TLMK'],
        ]);

        $this->artisan('totvs:import-clientes')->assertSuccessful();

        $vendedor = VendedorTotvs::query()->where('codigo', '010148')->firstOrFail();

        $this->assertSame('RICARDO CAMPOS SANTANA', $vendedor->nome);
        $this->assertSame('RICARDO CAMPOS - TLMK', $vendedor->nome_reduzido);
    }

    /**
     * ⚠️ A coluna `Nome` aparece DUAS vezes no 199 — a primeira é do cliente. Ler a
     * errada não dá erro nenhum: passa a escrever razão social na coluna Vendedor. É o
     * mesmo tipo de troca silenciosa do par `Descricao`/`Descricao_2` (grupo × segmento).
     */
    #[Test]
    public function test_import_nao_confunde_o_nome_do_cliente_com_o_do_vendedor(): void
    {
        $this->escreverRelatorio210([
            ['cod' => '006878', 'loja' => '0001', 'nome' => 'DROGARIA UNIFARMA LTDA. ME', 'vendedor' => '010148'],
        ]);
        $this->escreverRelatorio199([
            ['cod' => '006878', 'loja' => '0001', 'cliente' => 'DROGARIA UNIFARMA LTDA. ME',
                'codVendedor' => '010148', 'nomeVendedor' => 'RICARDO CAMPOS SANTANA'],
        ]);

        $this->artisan('totvs:import-clientes')->assertSuccessful();

        $this->assertSame(
            ['010148' => 'RICARDO CAMPOS SANTANA'],
            VendedorTotvs::query()->pluck('nome', 'codigo')->all()
        );
    }

    /**
     * ⚠️ O código é gravado CRU, com o zero à esquerda: quem tem que casar com ele é
     * `clientes.cod_vendedor`, que também é cru. Normalizar aqui — como se faz com grupo e
     * segmento, que vêm com padding inconsistente da origem — faria o lookup nunca casar,
     * e em silêncio: a tela seguiria mostrando o código, que é plausível.
     */
    #[Test]
    public function test_codigo_do_vendedor_e_gravado_com_o_zero_a_esquerda(): void
    {
        $this->escreverRelatorio210([
            ['cod' => '006878', 'loja' => '0001', 'nome' => 'CLIENTE X', 'vendedor' => '010148'],
        ]);
        $this->escreverRelatorio199([
            ['cod' => '006878', 'loja' => '0001', 'cliente' => 'CLIENTE X',
                'codVendedor' => '010148', 'nomeVendedor' => 'RICARDO CAMPOS SANTANA'],
        ]);

        $this->artisan('totvs:import-clientes')->assertSuccessful();

        $this->assertDatabaseHas('vendedores_totvs', ['codigo' => '010148']);
        $this->assertDatabaseMissing('vendedores_totvs', ['codigo' => '10148']);
        $this->assertSame(
            'RICARDO CAMPOS SANTANA',
            app(NomeVendedorResolver::class)->para(Cliente::query()->value('cod_vendedor'))
        );
    }

    // ───────────────────────── a regra ─────────────────────────

    #[Test]
    public function test_quem_tem_conta_no_crm_vence_o_nome_do_totvs(): void
    {
        $this->comConta('010617', 'FERNANDA');
        VendedorTotvs::create(['codigo' => '010617', 'nome' => 'FERNANDA ROSSI DE ALMEIDA']);

        $resolver = app(NomeVendedorResolver::class);

        $this->assertSame('FERNANDA', $resolver->para('010617'));
        $this->assertSame('FERNANDA', $resolver->todos()->get('010617'));
    }

    #[Test]
    public function test_sem_conta_no_crm_cai_para_o_nome_do_totvs(): void
    {
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);

        $this->assertSame('RICARDO CAMPOS SANTANA', app(NomeVendedorResolver::class)->para('010148'));
    }

    /**
     * ⚠️ O último degrau não é decoração. Sem ele a célula sairia VAZIA, que é pior que o
     * código — o código pelo menos é procurável no TOTVS.
     */
    #[Test]
    public function test_codigo_que_ninguem_conhece_continua_aparecendo_como_codigo(): void
    {
        $resolver = app(NomeVendedorResolver::class);

        $this->assertSame('010165', $resolver->para('010165'));
        $this->assertSame(['010165' => '010165'], $resolver->porCodigo(['010165']));
    }

    /**
     * Conta cujo nome está EM BRANCO não vence o degrau do TOTVS — senão a célula sai
     * vazia, que é pior que o código.
     *
     * ⚠️ A primeira versão deste teste apagava o usuário para simular perfil órfão, e
     * passava com o `filter()` REMOVIDO: `vendedor_perfis.user_id` é ON DELETE CASCADE,
     * então o perfil ia junto e o resolver caía no TOTVS por outro caminho. A hipótese
     * que sobrevive à mutação é esta — e foi a mutação que corrigiu o entendimento, não
     * a leitura do código.
     */
    #[Test]
    public function test_conta_sem_nome_cai_para_o_totvs_em_vez_de_deixar_a_celula_vazia(): void
    {
        $semNome = User::factory()->create(['name' => '', 'display_name' => null]);
        VendedorPerfil::create(['user_id' => $semNome->id, 'cod_vendedor' => '010148']);
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);

        $resolver = app(NomeVendedorResolver::class);

        $this->assertSame('RICARDO CAMPOS SANTANA', $resolver->para('010148'));
        $this->assertSame('RICARDO CAMPOS SANTANA', $resolver->todos()->get('010148'));
    }

    #[Test]
    public function test_codigo_vazio_ou_nulo_nao_vira_linha_no_mapa(): void
    {
        $resolver = app(NomeVendedorResolver::class);

        $this->assertSame([], $resolver->porCodigo([null, '', '   ']));
        $this->assertNull($resolver->para(null));
    }

    // ───────────────────────── as telas ─────────────────────────

    #[Test]
    public function test_carteira_mostra_o_nome_do_totvs_no_lugar_do_codigo(): void
    {
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);
        $this->cliente('010148');

        $props = $this->props($this->actingAs($this->admin())->get(route('carteira.index')));

        $this->assertSame('RICARDO CAMPOS SANTANA', $props['clientes']['data'][0]['vendedorNome']);
    }

    #[Test]
    public function test_ficha_do_cliente_usa_a_mesma_regra_da_listagem(): void
    {
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);
        $cliente = $this->cliente('010148');

        $props = $this->props($this->actingAs($this->admin())->get(route('carteira.detalhes', $cliente)));

        $this->assertSame('RICARDO CAMPOS SANTANA', $props['cliente']['vendedorNome']);
    }

    #[Test]
    public function test_pedidos_em_aberto_usam_a_mesma_regra(): void
    {
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);
        $cliente = $this->cliente('010148');
        Pedido::create([
            'numero_pedido' => '990001',
            'cliente_id' => $cliente->id,
            'cod_vendedor' => '010148',
            'data_pedido' => now()->subDays(3),
            'valor_total' => 1000,
        ]);

        $props = $this->props($this->actingAs($this->admin())->get(route('pedidos.index')));

        $this->assertSame('RICARDO CAMPOS SANTANA', $props['pedidos']['data'][0]['vendedorNome']);
    }

    #[Test]
    public function test_leads_usam_a_mesma_regra(): void
    {
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);
        Lead::create([
            'nome' => 'PROSPECT X',
            'razao_social' => 'PROSPECT X LTDA',
            'cod_vendedor' => '010148',
            'origem' => 'sistema',
        ]);

        $props = $this->props($this->actingAs($this->admin())->get(route('leads.index')));

        $this->assertSame('RICARDO CAMPOS SANTANA', $props['leads']['data'][0]['vendedorNome']);
    }

    /**
     * O Excel percorre a carteira em chunks e por isso usa `todos()` em vez de
     * `porCodigo()` — mas a resposta tem que ser a MESMA da tela, senão o arquivo diverge
     * do que a pessoa viu antes de clicar em exportar.
     */
    #[Test]
    public function test_excel_da_carteira_leva_o_mesmo_nome_da_tela(): void
    {
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);
        $cliente = $this->cliente('010148');

        $export = new CarteiraExport(
            Cliente::query()->whereKey($cliente->id),
            app(ClienteStatusResolver::class),
        );

        $linha = $export->map($cliente);
        $posicaoVendedor = array_search('Vendedor', $export->headings(), true);

        $this->assertSame('RICARDO CAMPOS SANTANA', $linha[$posicaoVendedor]);
    }

    /**
     * ⚠️ "sem responsável", numa busca cuja pergunta é "posso prospectar?", soa como
     * autorização. Era o que a tela respondia para 27% dos clientes.
     */
    #[Test]
    public function test_titularidade_responde_com_o_nome_do_totvs_em_vez_de_sem_responsavel(): void
    {
        VendedorTotvs::create(['codigo' => '010148', 'nome' => 'RICARDO CAMPOS SANTANA']);
        $this->cliente('010148');

        $this->actingAs($this->comConta('999999', 'OUTRO'))
            ->getJson(route('cadastros.titularidade', ['termo' => 'DROGARIA']))
            ->assertOk()
            ->assertJsonPath('resultados.0.responsaveis.0', 'RICARDO CAMPOS SANTANA');
    }

    /**
     * ⚠️ Aqui o degrau do código cru NÃO entra: quem o TOTVS também não conhece continua
     * saindo como "sem responsável" (lista vazia), e o código já aparece na própria linha.
     */
    #[Test]
    public function test_titularidade_sem_nome_em_lugar_nenhum_nao_inventa_o_codigo(): void
    {
        $this->cliente('010165');

        $this->actingAs($this->comConta('999999', 'OUTRO'))
            ->getJson(route('cadastros.titularidade', ['termo' => 'DROGARIA']))
            ->assertOk()
            ->assertJsonPath('resultados.0.responsaveis', []);
    }

    // ───────────────────────── apoio ─────────────────────────

    /** @return array<string, mixed> */
    private function props(\Illuminate\Testing\TestResponse $resposta): array
    {
        $resposta->assertOk();

        return $resposta->viewData('page')['props'];
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function comConta(string $cod, string $nome): User
    {
        $user = User::factory()->create(['display_name' => $nome, 'is_active' => true]);
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);

        return $user;
    }

    private function cliente(string $codVendedor): Cliente
    {
        return Cliente::create([
            'cod_cliente' => '006878',
            'loja' => '0001',
            'cnpj' => '07.761.027/0001-46',
            'razao_social' => 'DROGARIA UNIFARMA LTDA. ME',
            'cod_vendedor' => $codVendedor,
            'data_ultima_compra' => now()->subDays(10),
        ]);
    }
}
