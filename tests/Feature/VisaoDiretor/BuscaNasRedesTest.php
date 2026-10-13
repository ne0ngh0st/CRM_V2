<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\Lead;
use App\Models\Segmento;
use App\Models\User;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use App\Services\VisaoDiretor\TermoDeBusca;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A busca da Visão Diretor → Maiores por Segmento (`BuscaNasRedes` + `TermoDeBusca`).
 *
 * O caso que originou tudo (Tony, 2026-10-13): digitar "OBA" tem que trazer a FARTURA,
 * porque o Oba Hortifruti está dentro dela. Antes, o cliente sumia da busca: não aparecia
 * solto (é da rede) e a rede não aparecia (o nome dela é outro).
 */
class BuscaNasRedesTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $mercados;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->mercados = Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);

        $this->cliente('000001', 'GRUPO FARTURA DE HORTIFRUT S A', 'OBA HORTIFRUTI', '91', '11111111000101');
        $this->cliente('000002', 'COBASI COMERCIO DE PRODUTOS', 'COBASI', null, '22222222000101');
        $this->cliente('000003', 'SUPERMERCADO SOROCOBA LTDA', 'SOROCOBA', null, '33333333000101');
        $this->cliente('000004', 'OBA SUPER ATACADO LTDA', 'OBA SUPER ATACADO', null, '44444444000101');
    }

    private function cliente(string $codigo, string $razao, string $fantasia, ?string $grupo, string $cnpj, int $diasDesdeACompra = 10): void
    {
        Cliente::create([
            'cod_cliente' => $codigo,
            'loja' => '01',
            'cnpj' => substr($cnpj, 0, 2).'.'.substr($cnpj, 2, 3).'.'.substr($cnpj, 5, 3).'/'.substr($cnpj, 8, 4).'-'.substr($cnpj, 12, 2),
            'razao_social' => $razao,
            'nome_fantasia' => $fantasia,
            'cod_vendedor' => '000010',
            'cod_grupo' => $grupo,
            'cod_segmento' => '101',
            'estado' => 'SP',
            'data_ultima_compra' => now()->subDays($diasDesdeACompra),
        ]);
    }

    private function rede(string $nome, array $vinculos = [], ?string $apelidos = null, int $ordem = 1): ContaEstrategica
    {
        $conta = ContaEstrategica::create([
            'segmento_id' => $this->mercados->id,
            'nome' => $nome,
            'apelidos' => $apelidos,
            'ordem' => $ordem,
        ]);
        app(ClientesDaConta::class)->sincronizarVinculos($conta, array_map(
            fn (array $v) => ['tipo' => $v[0], 'codigo' => $v[1]],
            $vinculos,
        ));

        return $conta;
    }

    private function nomes(string $busca): array
    {
        app(UniversoDoSegmento::class)->reconstruir();

        return collect(app(UniversoDoSegmento::class)->lista('101', ['busca' => $busca])['linhas']->items())
            ->pluck('nome')->all();
    }

    // ─── O caso do Oba ──────────────────────────────────────────────────────────

    public function test_buscar_a_marca_traz_a_rede_que_a_contem(): void
    {
        $this->rede('FARTURA', [['grupo', '91']]);
        app(UniversoDoSegmento::class)->reconstruir();

        $linhas = collect(app(UniversoDoSegmento::class)->lista('101', ['busca' => 'oba'])['linhas']->items());
        $fartura = $linhas->firstWhere('nome', 'FARTURA');

        $this->assertNotNull($fartura, 'A rede some da busca pela marca que está dentro dela.');
        $this->assertSame(['OBA HORTIFRUTI'], $fartura['encontradoPor']['nomes']);
        $this->assertSame('dentro', $fartura['encontradoPor']['origem']);
        // O cliente da rede continua dentro dela, não solto.
        $this->assertNull($linhas->firstWhere('codCliente', '000001'));
    }

    public function test_busca_casa_pelo_comeco_da_palavra_nao_pelo_meio(): void
    {
        $this->rede('FARTURA', [['grupo', '91']]);

        // COBASI e SOROCOBA têm "OBA" no MEIO da palavra: são ruído, não resultado.
        // O cliente aparece pela razão social (a linha é o cliente, não uma loja).
        $this->assertSame(['FARTURA', 'OBA SUPER ATACADO LTDA'], $this->nomes('OBA'));
    }

    public function test_cnpj_de_uma_filial_acha_a_rede(): void
    {
        $this->rede('FARTURA', [['grupo', '91']]);

        $this->assertSame(['FARTURA'], $this->nomes('11.111.111'));
    }

    public function test_lead_ligado_a_rede_tambem_acha_a_rede(): void
    {
        $conta = $this->rede('ALVO SEM CLIENTE');
        $lead = Lead::forceCreate([
            'origem' => 'prospeccao', 'nome' => 'MERCADO ZETA', 'razao_social' => 'ZETA LTDA',
            'segmento' => 'SUPERMERCADISTA', 'status' => 'ativo', 'etapa' => 'novo',
        ]);
        $lead->forceFill(['conta_estrategica_id' => $conta->id, 'conta_vinculo' => 'confirmado'])->save();

        $this->assertSame(['ALVO SEM CLIENTE'], $this->nomes('zeta'));
    }

    // ─── Apelido ───────────────────────────────────────────────────────────────

    public function test_apelido_acha_a_rede_sem_cliente(): void
    {
        $this->rede('GRUPO ALVO', [], 'Mercado Bom, Bom Preço');

        app(UniversoDoSegmento::class)->reconstruir();
        $linha = collect(app(UniversoDoSegmento::class)->lista('101', ['busca' => 'bom preco'])['linhas']->items())->first();

        // Sem acento na busca, com acento no apelido: casa, como no MySQL.
        $this->assertSame('GRUPO ALVO', $linha['nome']);
        $this->assertSame(['Bom Preço'], $linha['encontradoPor']['nomes']);
        $this->assertSame('apelido', $linha['encontradoPor']['origem']);
    }

    public function test_rede_casada_pelo_proprio_nome_nao_explica_nada(): void
    {
        $this->rede('FARTURA', [['grupo', '91']], 'OBA');

        app(UniversoDoSegmento::class)->reconstruir();
        $linha = collect(app(UniversoDoSegmento::class)->lista('101', ['busca' => 'fartura'])['linhas']->items())->first();

        $this->assertNull($linha['encontradoPor']);
    }

    public function test_tela_grava_os_apelidos_num_formato_so(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('visao-diretor.maiores.store'), [
            'segmento_id' => $this->mercados->id,
            'nome' => 'FARTURA',
            'apelidos' => ' OBA,,  Oba Hortifruti , ',
        ])->assertSessionHasNoErrors();

        $this->assertSame('OBA, Oba Hortifruti', ContaEstrategica::sole()->apelidos);

        $this->actingAs($admin)->patch(route('visao-diretor.maiores.update', ContaEstrategica::sole()), [
            'segmento_id' => $this->mercados->id,
            'nome' => 'FARTURA',
            'apelidos' => ' , ',
        ])->assertSessionHasNoErrors();

        $this->assertNull(ContaEstrategica::sole()->apelidos);
    }

    // ─── Uma regra só ─────────────────────────────────────────────────────────

    public function test_resumo_usa_a_mesma_busca(): void
    {
        $this->rede('FARTURA', [['grupo', '91']]);
        $this->rede('OUTRA REDE', [], null, 2);

        $dados = app(MaioresPorSegmentoResolver::class)->resolver(['busca' => 'oba']);

        $this->assertSame(1, $dados['kpis']['contas']);
        $this->assertSame(['FARTURA'], collect($dados['segmentos'])->flatMap(fn ($s) => $s['contas'])->pluck('nome')->all());
    }

    public function test_linha_expandida_poe_quem_casou_primeiro(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        // Comprou mais recente: sem a busca, ele viria primeiro.
        $this->cliente('000005', 'AAA FARTURA LOJA', 'AAA FARTURA', '91', '55555555000101', 1);
        $conta = $this->rede('FARTURA', [['grupo', '91']]);

        $clientes = $this->actingAs($admin)
            ->getJson(route('visao-diretor.maiores.clientes', ['conta' => $conta->id, 'busca' => 'oba']))
            ->assertOk()
            ->json('clientes');

        $this->assertSame(['000001', '000005'], array_column($clientes, 'codCliente'));
        $this->assertSame([true, false], array_column($clientes, 'casaBusca'));
    }

    public function test_linha_expandida_e_avulsa_mostram_a_razao_social_nao_a_loja(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        $conta = $this->rede('FARTURA', [['grupo', '91']]);

        $clientes = $this->actingAs($admin)
            ->getJson(route('visao-diretor.maiores.clientes', $conta))
            ->json('clientes');

        // Status e última compra são do cliente inteiro: o nome também tem que ser.
        $this->assertSame('GRUPO FARTURA DE HORTIFRUT S A', $clientes[0]['nome']);

        app(UniversoDoSegmento::class)->reconstruir();
        $linha = collect(app(UniversoDoSegmento::class)->lista('101')['linhas']->items())->firstWhere('codCliente', '000002');
        $this->assertSame('COBASI COMERCIO DE PRODUTOS', $linha['nome']);
        // ...e o nome fantasia continua valendo na busca.
        $this->assertSame(['COBASI COMERCIO DE PRODUTOS'], $this->nomes('cobasi'));
    }

    public function test_termo_escapa_curinga_do_like(): void
    {
        // "%" e "_" digitados são texto, não curinga: "1_" não pode casar com "13".
        $this->assertFalse(TermoDeBusca::casa('LOJA 13', '1_'));
        [$sql, $bindings] = TermoDeBusca::sql(['nome'], '1_');
        $this->assertStringContainsString('1\\_', $bindings[0]);
        $this->assertTrue(TermoDeBusca::casa('SUPER-OBA LTDA', 'oba'));
        $this->assertFalse(TermoDeBusca::casa('COBASI', 'oba'));
    }
}
