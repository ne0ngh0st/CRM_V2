<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Carteira agrupada por cliente (`?agrupar=1`): uma linha por `cod_cliente`, com as
 * filiais contadas.
 *
 * O que estes testes protegem, em ordem de gravidade:
 *
 *  1. o TOTAL da paginação passa a contar clientes, não linhas — se voltar a contar
 *     linhas, a tela mostra "1.007" com 636 clientes listados;
 *  2. o ESCOPO vale também na contagem de filiais. Um `cod_cliente` dividido entre
 *     vendedores é caso real (3.964 na base), e contar as filiais do outro é vazamento
 *     de escopo travestido de número;
 *  3. a ÂNCORA é determinística e prefere loja comercial — é ela que recebe as ações da
 *     linha, e o de-para do Portal Autopel é `cod_cliente + loja`;
 *  4. o modo plano continua idêntico, porque ele ainda é o padrão.
 *
 * ⚠️ Os fixtures são deliberadamente assimétricos. Datas, contagens e códigos são todos
 * diferentes entre si para que trocar `MAX` por `MIN`, perder o `DISTINCT` ou ignorar o
 * escopo mude o número — cinco testes deste projeto já passaram com o código quebrado
 * por usarem valores que não distinguiam nada.
 */
class CarteiraAgrupadaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => '001']);

        /*
         * A: três lojas, uma delas endereço de entrega. As datas são diferentes de
         * propósito — a do grupo tem que ser a MAIS RECENTE (2026-06-20), e ela NÃO está
         * na âncora, senão trocar MAX por MIN (ou ler a data da âncora em vez do grupo)
         * passaria despercebido.
         */
        $this->cliente('100', '0001', 'ALFA INDUSTRIA', '001', 'SP', '2026-01-10');
        $this->cliente('100', '0002', 'ALFA INDUSTRIA FILIAL', '001', 'RJ', '2026-06-20');
        $this->cliente('100', 'E001', 'ALFA INDUSTRIA ENTREGA', '001', 'SP', null);

        // B: cliente comum de uma loja só — 87,7% da base real é assim.
        $this->cliente('200', '0005', 'BETA COMERCIO', '001', 'MG', '2026-03-01');

        /*
         * C: SÓ endereços de entrega. Existe na base real (dois dos três "AUTOPASS" da
         * carteira da Sthefany são assim) e é o caso que exige o fallback da âncora —
         * sem ele o cliente não teria linha nenhuma e sumiria da carteira.
         */
        $this->cliente('300', 'E001', 'GAMA LOGISTICA', '001', 'PR', null);
        $this->cliente('300', 'E002', 'GAMA LOGISTICA 2', '001', 'PR', null);

        /*
         * D: MESMO código, vendedores diferentes. É o que prova que a contagem de filiais
         * respeita o escopo: para o vendedor 001 este cliente tem UMA loja, não duas.
         */
        $this->cliente('400', '0001', 'DELTA SERVICOS', '001', 'SP', '2026-02-01');
        $this->cliente('400', '0002', 'DELTA SERVICOS OUTRO', '002', 'SP', '2026-02-02');

        /*
         * E: a loja de ENTREGA ordena ANTES da comercial ('E001' < 'Z001'). É artificial
         * — na base real toda loja comercial começa com dígito e portanto já vence o
         * desempate —, e é exatamente por isso que precisa existir: sem este caso,
         * remover a preferência por loja comercial não quebra teste nenhum, e a regra
         * viraria decoração. Verificado por mutação.
         */
        $this->cliente('500', 'E001', 'EPSILON ENTREGA', '001', 'BA', null);
        $this->cliente('500', 'Z001', 'EPSILON MATRIZ', '001', 'BA', '2026-04-04');
    }

    private function cliente(string $cod, string $loja, string $razao, string $vendedor, string $uf, ?string $compra): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $cod,
            'loja' => $loja,
            'razao_social' => $razao,
            'cod_vendedor' => $vendedor,
            'estado' => $uf,
            'data_ultima_compra' => $compra,
        ]);
    }

    /** @return array<string, array<string, mixed>> linhas da tela, indexadas por cod_cliente */
    private function linhas(User $como, array $params = []): array
    {
        $resposta = $this->actingAs($como)->get(route('carteira.index', $params + ['agrupar' => 1]));
        $resposta->assertOk();

        return collect($resposta->viewData('page')['props']['clientes']['data'])
            ->keyBy('codCliente')
            ->all();
    }

    private function props(User $como, array $params = []): array
    {
        $resposta = $this->actingAs($como)->get(route('carteira.index', $params));
        $resposta->assertOk();

        return $resposta->viewData('page')['props'];
    }

    public function test_agrupado_lista_um_cliente_por_linha(): void
    {
        $linhas = $this->linhas($this->vendedor);

        // 5 clientes (100, 200, 300, 400, 500) a partir de 8 linhas no escopo do vendedor.
        $this->assertCount(5, $linhas);

        // Via pluck, não array_keys: o PHP converte chave numérica de array para int e a
        // comparação com as strings do banco falharia por tipo, não por conteúdo.
        $this->assertSame(
            ['100', '200', '300', '400', '500'],
            collect($linhas)->pluck('codCliente')->sort()->values()->all(),
        );
    }

    public function test_total_da_paginacao_conta_clientes_e_nao_linhas(): void
    {
        $props = $this->props($this->vendedor, ['agrupar' => 1]);

        $linhasNoBanco = DB::table('clientes')->where('cod_vendedor', '001')->count();
        $clientesNoBanco = DB::table('clientes')->where('cod_vendedor', '001')->distinct()->count('cod_cliente');

        // Os dois números TÊM que ser diferentes, senão o teste não distingue nada.
        $this->assertNotSame($linhasNoBanco, $clientesNoBanco, 'fixture degenerado: linhas e clientes coincidem');

        $this->assertSame($clientesNoBanco, $props['clientes']['total']);
    }

    public function test_agrupado_e_o_padrao_da_tela(): void
    {
        // Sem parâmetro nenhum: é o que 200 vendedores vão ver.
        $props = $this->props($this->vendedor);

        $this->assertTrue($props['filtros']['agrupado']);
        $this->assertSame(
            DB::table('clientes')->where('cod_vendedor', '001')->distinct()->count('cod_cliente'),
            $props['clientes']['total'],
        );
    }

    public function test_modo_por_filial_continua_alcancavel(): void
    {
        $props = $this->props($this->vendedor, ['agrupar' => 0]);

        $this->assertFalse($props['filtros']['agrupado']);
        $this->assertSame(
            DB::table('clientes')->where('cod_vendedor', '001')->count(),
            $props['clientes']['total'],
            'o botão "ver filiais separadas" precisa devolver a lista antiga intacta',
        );
    }

    public function test_link_antigo_com_agrupar_1_continua_valendo(): void
    {
        // Links salvos e a URL que circulou durante o opt-in não podem quebrar.
        $this->assertTrue($this->props($this->vendedor, ['agrupar' => 1])['filtros']['agrupado']);

        // Valor inesperado cai no padrão em vez de derrubar a tela.
        $this->assertTrue($this->props($this->vendedor, ['agrupar' => 'sim'])['filtros']['agrupado']);
    }

    public function test_kpi_do_topo_bate_com_o_total_da_tabela(): void
    {
        /*
         * 🚨 A invariante que motivou a Etapa 4. O KPI fica cinco centímetros acima da
         * tabela; se contarem coisas diferentes, o usuário não tem como saber em qual
         * acreditar — e a dúvida contamina a tela inteira, não só o card.
         */
        foreach ([$this->vendedor, $this->admin] as $usuario) {
            $props = $this->props($usuario);

            $this->assertSame(
                $props['clientes']['total'],
                $props['kpis']['total'],
                'kpis.total e clientes.total têm que contar a mesma coisa',
            );
        }
    }

    public function test_modo_por_filial_expoe_as_duas_unidades(): void
    {
        /*
         * No modo por filial o KPI conta clientes e a paginação conta filiais — são
         * perguntas diferentes, e por isso divergem legitimamente. O que não pode é a
         * tela mostrar só um dos dois: quem vê "5 clientes" sobre uma lista de 8 linhas
         * não tem como saber qual está certo.
         *
         * Este teste trava o CONTRATO (as duas contagens chegam ao front e são
         * diferentes); a tela usa `clientes.total` para dizer "N filiais" ao lado.
         */
        $props = $this->props($this->vendedor, ['agrupar' => 0]);

        $clientes = DB::table('clientes')->where('cod_vendedor', '001')->distinct()->count('cod_cliente');
        $filiais = DB::table('clientes')->where('cod_vendedor', '001')->count();

        $this->assertNotSame($clientes, $filiais, 'fixture degenerado');
        $this->assertSame($clientes, $props['kpis']['total']);
        $this->assertSame($filiais, $props['clientes']['total']);
    }

    public function test_kpi_soma_as_partes_exatamente(): void
    {
        $kpis = $this->props($this->admin)['kpis'];

        $soma = $kpis['dentroSegmento']['total'] + $kpis['foraSegmento']['total'] + $kpis['semSegmentoDefinido']['total'];

        // Um cliente com filiais em status ou aderências diferentes não pode ser contado
        // em dois baldes — seria o caso em que as partes passam do todo.
        $this->assertSame($kpis['total'], $soma);

        foreach (['dentroSegmento', 'foraSegmento', 'semSegmentoDefinido'] as $bloco) {
            $b = $kpis[$bloco];
            $this->assertSame($b['total'], $b['ativos'] + $b['inativando'] + $b['inativos'], "bloco {$bloco}");
        }
    }

    public function test_conta_filiais_e_entregas_separadamente(): void
    {
        $linhas = $this->linhas($this->vendedor);

        $this->assertSame(3, $linhas['100']['lojas']);
        $this->assertSame(1, $linhas['100']['entregas'], 'E001 é entrega; 0001 e 0002 não');

        $this->assertSame(1, $linhas['200']['lojas']);
        $this->assertSame(0, $linhas['200']['entregas']);

        $this->assertSame(2, $linhas['300']['lojas']);
        $this->assertSame(2, $linhas['300']['entregas'], 'cliente só de endereços de entrega');
    }

    public function test_contagem_de_filiais_respeita_o_escopo_do_vendedor(): void
    {
        $doVendedor = $this->linhas($this->vendedor);
        $doAdmin = $this->linhas($this->admin);

        $this->assertSame(1, $doVendedor['400']['lojas'],
            'a filial do vendedor 002 não pode entrar na contagem de quem é do 001');
        $this->assertSame(2, $doAdmin['400']['lojas'],
            'o admin vê a empresa inteira, então para ele são duas');
    }

    public function test_data_do_grupo_e_a_compra_mais_recente_entre_as_filiais(): void
    {
        $linhas = $this->linhas($this->vendedor);

        // A âncora é a loja 0001, cuja compra é de janeiro; o grupo comprou em junho.
        $this->assertSame('20/06/2026', $linhas['100']['dataUltimaCompra']);
        $this->assertSame('ativo', $linhas['100']['status'],
            'o status sai da data do GRUPO — pela data da âncora este cliente pareceria mais frio');
    }

    public function test_ancora_prefere_loja_comercial_e_e_deterministica(): void
    {
        $linhas = $this->linhas($this->vendedor);

        $this->assertSame('0001', $linhas['100']['loja'], 'menor loja comercial');
        $this->assertSame('Z001', $linhas['500']['loja'],
            'Z001 é comercial e E001 é entrega: a preferência vence a ordem alfabética');
        $this->assertSame('E001', $linhas['300']['loja'],
            'sem nenhuma loja comercial, cai para a menor loja — senão o cliente sumiria da lista');

        // Determinismo: a mesma consulta, de novo, dá a mesma âncora.
        $this->assertSame(
            collect($linhas)->map(fn ($l) => $l['loja'])->all(),
            collect($this->linhas($this->vendedor))->map(fn ($l) => $l['loja'])->all(),
        );
    }

    public function test_linha_agrupada_leva_os_dados_da_ancora(): void
    {
        $linhas = $this->linhas($this->vendedor);

        $this->assertSame('ALFA INDUSTRIA', $linhas['100']['razaoSocial']);
        $this->assertSame('SP', $linhas['100']['estado'], 'estado da âncora, não da filial do RJ');
    }

    public function test_filtro_traz_o_cliente_quando_qualquer_filial_casa(): void
    {
        // A matriz do cliente 100 é de SP e a filial é do RJ. Filtrando por RJ, o cliente
        // tem que aparecer — com os dados da âncora (SP) na linha.
        $linhas = $this->linhas($this->vendedor, ['estado' => 'RJ']);

        $this->assertArrayHasKey('100', $linhas);
        $this->assertSame('SP', $linhas['100']['estado']);
        $this->assertArrayNotHasKey('200', $linhas, 'BETA é de MG e não pode entrar no filtro de RJ');
    }

    public function test_contagem_de_filiais_ignora_os_filtros_de_tela(): void
    {
        // Filtrando por RJ, o cliente 100 entra por causa de uma filial — mas "3 filiais"
        // continua sendo o que ele tem na carteira, não quantas casaram com o filtro.
        // É o que faz o número bater com o que a expansão vai listar.
        $linhas = $this->linhas($this->vendedor, ['estado' => 'RJ']);

        $this->assertSame(3, $linhas['100']['lojas']);
    }

    public function test_ordenacao_agrupada_usa_a_whitelist(): void
    {
        $nomes = fn (string $ordenar) => collect($this->linhas($this->vendedor, ['ordenar' => $ordenar]))
            ->map(fn ($l) => $l['razaoSocial'])->values()->all();

        $asc = $nomes('nome_asc');
        $this->assertSame('ALFA INDUSTRIA', $asc[0]);

        $desc = $nomes('nome_desc');
        $this->assertSame(array_reverse($asc), $desc);

        // Campo fora da whitelist não pode quebrar a página nem virar ORDER BY cru.
        $this->assertSame($asc, $nomes('cod_vendedor); DROP TABLE clientes; --_asc'));
    }

    public function test_ordena_por_ultima_compra_do_grupo(): void
    {
        $ordem = collect($this->linhas($this->vendedor, ['ordenar' => 'ultima_compra_desc']))
            ->map(fn ($l) => $l['codCliente'])->values()->all();

        // 100 comprou em 20/06 (pela filial), 500 em 04/04, 200 em 01/03, 400 em 01/02,
        // 300 nunca. Se a ordenação usasse a data da ÂNCORA, o 100 cairia para depois do
        // 500 — a asserção abaixo é o que separa os dois comportamentos.
        $this->assertSame(['100', '500', '200', '400', '300'], $ordem);
    }

    // ---------------------------------------------------------------- filiais (expansão)

    public function test_filiais_vem_com_as_comerciais_primeiro(): void
    {
        $r = $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', '100'));
        $r->assertOk();

        $this->assertSame(3, $r->json('total'));
        $this->assertSame(['0001', '0002', 'E001'], collect($r->json('filiais'))->pluck('loja')->all());

        $this->assertFalse($r->json('filiais.0.ehEntrega'));
        $this->assertTrue($r->json('filiais.2.ehEntrega'));

        /*
         * ⚠️ A asserção acima NÃO prova a regra "comercial primeiro": com lojas 0001,
         * 0002 e E001, ordenar só por `loja` dá o mesmo resultado. Descoberto por
         * mutação — removida a regra, o teste continuava verde.
         *
         * O cliente 500 é o que separa os dois comportamentos, porque nele a loja de
         * entrega ordena ANTES da comercial ('E001' < 'Z001'). É também o que garante
         * que a âncora seja a PRIMEIRA linha da expansão, e não uma linha qualquer do
         * meio.
         */
        $ordem = collect(
            $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', '500'))->json('filiais')
        )->pluck('loja')->all();

        $this->assertSame(['Z001', 'E001'], $ordem,
            'a loja comercial vem primeiro mesmo ordenando depois da de entrega');
    }

    public function test_filiais_respeita_o_escopo_de_quem_pergunta(): void
    {
        // O cliente 400 tem uma loja do vendedor 001 e outra do 002.
        $doVendedor = $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', '400'));
        $doVendedor->assertOk();

        $this->assertSame(1, $doVendedor->json('total'));
        $this->assertSame(['0001'], collect($doVendedor->json('filiais'))->pluck('loja')->all(),
            'a filial do outro vendedor não pode nem aparecer na resposta');

        $this->assertSame(2, $this->actingAs($this->admin)->getJson(route('carteira.filiais', '400'))->json('total'));
    }

    public function test_filiais_de_cliente_fora_do_escopo_da_404(): void
    {
        // Existe, mas é de outro vendedor: para o 001 tem que ser indistinguível de
        // "não existe", senão a resposta confirma a existência de carteira alheia.
        $this->cliente('900', '0001', 'OMEGA LTDA', '002', 'SP', '2026-01-01');

        $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', '900'))->assertNotFound();
        $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', 'NAO-EXISTE'))->assertNotFound();
    }

    public function test_filiais_tem_teto_e_anuncia_o_total(): void
    {
        // 130 lojas num código só — a CAIXA real tem 4.179. Inseridas em lote porque o
        // que está sendo testado é o teto, não o model.
        $linhas = [];
        for ($i = 1; $i <= 130; $i++) {
            $linhas[] = [
                'cod_cliente' => '700',
                'loja' => sprintf('%04d', $i),
                'razao_social' => "REDE GRANDE {$i}",
                'cod_vendedor' => '001',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('clientes')->insert($linhas);

        $r = $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', '700'));
        $r->assertOk();

        $this->assertSame(130, $r->json('total'), 'o total é o real, não o que coube');
        $this->assertSame(100, $r->json('mostrando'));
        $this->assertCount(100, $r->json('filiais'));
    }

    public function test_filiais_exige_login(): void
    {
        $this->getJson(route('carteira.filiais', '100'))->assertUnauthorized();
    }

    public function test_pagina_agrupada_nao_faz_uma_consulta_por_cliente(): void
    {
        /*
         * Comparado com o modo plano, e não contra um número absoluto: o total inclui
         * autenticação, roles e lookups que mudam quando o framework ou o layout mudam,
         * e um teto fixo viraria falso positivo sem relação com esta feature.
         *
         * O agrupamento troca UMA consulta de listagem por TRÊS (códigos, resumo,
         * âncoras) e economiza o `count` (cacheado). O que este teste tem que pegar é a
         * volta de um N+1 — uma consulta de filiais por linha —, que faria a diferença
         * crescer com o número de clientes da página.
         */
        $contar = function (array $params) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->vendedor)->get(route('carteira.index', $params))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $plano = $contar([]);
        $agrupado = $contar(['agrupar' => 1]);

        $this->assertLessThanOrEqual($plano + 3, $agrupado,
            "agrupado fez {$agrupado} consultas contra {$plano} do plano — mais de 3 a mais sugere consulta por linha");
    }
}
