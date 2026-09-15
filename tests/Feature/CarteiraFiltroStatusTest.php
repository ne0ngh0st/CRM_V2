<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Carteira\CarteiraAderenciaResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtro de status da Carteira (`?status=ativo|inativando|inativo`) no grão CERTO.
 *
 * 🚨 O bug que estes testes travam era visível em produção (2026-09-15). A lista é
 * agrupada por cliente e a pill de cada linha vem da data CONSOLIDADA entre as filiais,
 * mas o filtro comparava filial por filial. Cliente com uma loja parada há dois anos e
 * outra que comprou ontem entrava na lista de "Inativos" — e era exibido lá com a pill
 * verde "Ativo". Caso real: VILA POKE LTDA, carteira da Inaya, comprou 11/09/2026.
 *
 * O KPI do topo sai da mesma query, então o card da Carteira dizia 1.009 inativos onde o
 * card do Painel dizia 955. Na base inteira: 110 vendedores, 1.357 clientes.
 *
 * ⚠️ O caminho para o erro era o próprio produto — o tile "Inativos" do Painel linka
 * para `/carteira?status=inativo`. Clicava-se em 955 e chegava-se a 1.009.
 *
 * ⚠️ Fixture deliberadamente assimétrico: cada cliente combina datas diferentes entre as
 * filiais, e nenhuma faixa tem o mesmo tamanho que outra. Com contagens parecidas, trocar
 * `MAX` por `MIN` ou voltar a filtrar por filial passaria verde — cinco testes deste
 * projeto já passaram com o código quebrado por fixture que não distinguia nada.
 *
 * ⚠️ VERIFICADO POR MUTAÇÃO, três aplicadas de propósito, cada uma mordida por 4 testes:
 *
 *   1. voltar a filtrar por filial no modo agrupado (o bug original)
 *   2. consolidar sem o escopo do vendedor (vazamento travestido de classificação)
 *   3. `MAX` -> `MIN` na consolidação
 *
 * ⚠️ Na primeira rodada um destes testes sobreviveu às três mutações; ele foi reescrito
 * quando o card deixou de se filtrar a si mesmo (ver `CarteiraCardFacetasTest`) e hoje
 * morde. A anotação ficou no próprio teste — um teste que não morde tem que dizer isso de
 * si mesmo, senão a próxima pessoa confia nele.
 */
class CarteiraFiltroStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => '001']);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $hoje = now();
        $ativa = $hoje->copy()->subDays(5)->toDateString();       // < 290: ativo
        $ativaOutra = $hoje->copy()->subDays(40)->toDateString();
        $meio = $hoje->copy()->subDays(300)->toDateString();      // 291-365: inativando
        $velha = $hoje->copy()->subDays(400)->toDateString();     // > 365: inativo
        $maisVelha = $hoje->copy()->subDays(900)->toDateString();

        /*
         * MISTO-ATIVO — o caso VILA POKE, e o motivo deste arquivo existir. Consolidado
         * é ATIVO (a loja 0002 comprou há 5 dias), mas tem duas filiais que, sozinhas,
         * casariam o filtro de inativo.
         */
        $this->cliente('100', '0001', 'MISTO ATIVO', '001', $velha);
        $this->cliente('100', '0002', 'MISTO ATIVO FILIAL', '001', $ativa);
        $this->cliente('100', '0003', 'MISTO ATIVO ENTREGA', '001', null);

        /*
         * MISTO-INATIVANDO — consolidado cai na FAIXA DO MEIO. Sem ele, um conserto que
         * tratasse só o extremo "inativo" (o caso reportado) passaria despercebido.
         */
        $this->cliente('200', '0001', 'MISTO INATIVANDO', '001', $meio);
        $this->cliente('200', '0002', 'MISTO INATIVANDO FILIAL', '001', $maisVelha);

        // Puros, um de cada faixa — é com eles que as contagens fecham.
        $this->cliente('300', '0001', 'PURO ATIVO', '001', $ativaOutra);
        $this->cliente('400', '0001', 'PURO INATIVANDO', '001', $meio);
        $this->cliente('500', '0001', 'PURO INATIVO', '001', $velha);
        $this->cliente('600', '0001', 'PURO INATIVO DOIS', '001', $maisVelha);
        $this->cliente('700', '0001', 'NUNCA COMPROU', '001', null);

        /*
         * DIVIDIDO entre vendedores — 3.964 casos reais na base. Para o vendedor 001 este
         * cliente é INATIVO; a loja que comprou ontem é de OUTRA pessoa e não pode entrar
         * no `MAX` dele. Sem este caso, consolidar sem escopo passaria no teste e vazaria
         * carteira alheia na classificação.
         */
        $this->cliente('800', '0001', 'DIVIDIDO', '001', $velha);
        $this->cliente('800', '0002', 'DIVIDIDO OUTRO VENDEDOR', '002', $ativa);
    }

    private function cliente(string $cod, string $loja, string $razao, string $vendedor, ?string $compra): void
    {
        Cliente::create([
            'cod_cliente' => $cod,
            'loja' => $loja,
            'razao_social' => $razao,
            'cod_vendedor' => $vendedor,
            'estado' => 'SP',
            'data_ultima_compra' => $compra,
        ]);
    }

    private function props(User $como, array $params = []): array
    {
        $resposta = $this->actingAs($como)->get(route('carteira.index', $params));
        $resposta->assertOk();

        return $resposta->viewData('page')['props'];
    }

    /** @return array<string, array<string, mixed>> linhas indexadas por cod_cliente */
    private function linhas(User $como, array $params = []): array
    {
        return collect($this->props($como, $params)['clientes']['data'])->keyBy('codCliente')->all();
    }

    /** Quantos clientes o Painel conta em cada faixa, para o MESMO escopo. */
    private function contagemDoPainel(string $codVendedor): array
    {
        $kpis = app(CarteiraAderenciaResolver::class)
            ->resolver(Cliente::query()->where('clientes.cod_vendedor', $codVendedor));

        $somar = fn (string $faixa) => $kpis['dentroSegmento'][$faixa]
            + $kpis['foraSegmento'][$faixa]
            + $kpis['semSegmentoDefinido'][$faixa];

        return [
            'ativo' => $somar('ativos'),
            'inativando' => $somar('inativando'),
            'inativo' => $somar('inativos'),
            'total' => $kpis['total'],
        ];
    }

    public function test_cliente_com_filial_recente_nao_aparece_entre_os_inativos(): void
    {
        $linhas = $this->linhas($this->vendedor, ['status' => 'inativo']);

        $this->assertArrayNotHasKey('100', $linhas, 'o caso VILA POKE: comprou há 5 dias e voltou a ser listado como inativo');
        $this->assertArrayNotHasKey('200', $linhas, 'consolidado inativando não é inativo');

        // Os que realmente são inativos continuam lá — o conserto não pode esvaziar a tela.
        $this->assertSame(['500', '600', '700', '800'], collect($linhas)->pluck('codCliente')->sort()->values()->all());
    }

    public function test_a_pill_de_toda_linha_listada_bate_com_o_filtro(): void
    {
        /*
         * 🚨 A invariante geral, e a que mais morde: a tela não pode exibir um status
         * diferente do que foi pedido. Cobre as três faixas de uma vez, então um conserto
         * que acertasse só "inativo" falharia aqui.
         */
        foreach (['ativo', 'inativando', 'inativo'] as $faixa) {
            $linhas = $this->linhas($this->vendedor, ['status' => $faixa]);

            $this->assertNotEmpty($linhas, "faixa {$faixa} sem nenhuma linha: fixture degenerado");

            foreach ($linhas as $codigo => $linha) {
                $this->assertSame($faixa, $linha['status'], "cliente {$codigo} listado em {$faixa} com a pill '{$linha['status']}'");
            }
        }
    }

    public function test_o_tile_do_status_filtrado_bate_com_a_lista(): void
    {
        /*
         * ⚠️ ESTE TESTE JÁ AFIRMOU O CONTRÁRIO, horas antes, e a história importa: ele
         * comparava `kpis.total` com `clientes.total` e exigia que fossem iguais sob
         * filtro. Isso valia enquanto o card se filtrava a si mesmo — e era justamente o
         * que o Tony pediu para mudar ("quando clico num KPI somem os outros"). Hoje
         * `kpis.total` é a carteira INTEIRA de propósito; quem tem que bater com a lista é
         * o TILE daquela faixa.
         *
         * ⚠️ Na versão antiga ele era o teste fraco do arquivo: sobreviveu às três
         * mutações, porque KPI e lista saíam da mesma query e inflavam JUNTOS. Nesta
         * versão ele morde — a mutação do grão muda o tile sem mudar a lista.
         *
         * A cobertura completa dos 11 números clicáveis do card está em
         * `CarteiraCardFacetasTest::test_todo_numero_do_card_bate_com_a_lista_que_ele_abre`.
         */
        $campos = ['ativo' => 'ativos', 'inativando' => 'inativando', 'inativo' => 'inativos'];

        foreach ($campos as $faixa => $campo) {
            $props = $this->props($this->vendedor, ['status' => $faixa]);
            $kpis = $props['kpis'];

            $tile = $kpis['dentroSegmento'][$campo]
                + $kpis['foraSegmento'][$campo]
                + $kpis['semSegmentoDefinido'][$campo];

            $this->assertSame(
                $props['clientes']['total'],
                $tile,
                "o tile {$faixa} não bate com a lista que ele abre",
            );
        }
    }

    public function test_numero_da_carteira_filtrada_bate_com_o_do_painel(): void
    {
        /*
         * 🚨 A reprodução literal do que a Inaya viu: clicar no tile do Painel e a
         * Carteira mostrar outro número. Compara contra o MESMO resolver que alimenta o
         * card do Painel, nunca contra um número escrito à mão — se a definição de
         * "inativo" mudar algum dia, os dois lados mudam juntos.
         */
        $painel = $this->contagemDoPainel('001');

        // O fixture só distingue se as faixas tiverem tamanhos diferentes entre si.
        $this->assertNotSame($painel['ativo'], $painel['inativo'], 'fixture degenerado');

        foreach (['ativo', 'inativando', 'inativo'] as $faixa) {
            $this->assertSame(
                $painel[$faixa],
                $this->props($this->vendedor, ['status' => $faixa])['clientes']['total'],
                "a Carteira com status={$faixa} tem que mostrar o mesmo número do tile do Painel",
            );
        }

        // E as três faixas ainda somam a carteira inteira.
        $this->assertSame(
            $painel['total'],
            $painel['ativo'] + $painel['inativando'] + $painel['inativo'],
        );
    }

    public function test_consolidacao_respeita_o_escopo_do_vendedor(): void
    {
        /*
         * O cliente 800 tem uma loja de outro vendedor que comprou há 5 dias. Para o
         * vendedor 001 ele é inativo; considerar a loja alheia o tornaria ativo — e seria
         * vazamento de escopo disfarçado de classificação.
         */
        $this->assertArrayHasKey('800', $this->linhas($this->vendedor, ['status' => 'inativo']));
        $this->assertArrayNotHasKey('800', $this->linhas($this->vendedor, ['status' => 'ativo']));
    }

    public function test_modo_por_filial_continua_filtrando_por_filial(): void
    {
        /*
         * `?agrupar=0` mostra uma linha por loja, e ali a pill É a da loja. Cada modo
         * filtra no grão que exibe — por isso a loja parada do cliente 100 TEM que
         * aparecer aqui, ao contrário do modo agrupado.
         */
        $props = $this->props($this->vendedor, ['status' => 'inativo', 'agrupar' => 0]);
        $lojas = collect($props['clientes']['data']);

        $this->assertTrue($lojas->contains(fn ($l) => $l['codCliente'] === '100'));

        foreach ($lojas as $linha) {
            $this->assertSame('inativo', $linha['status']);
        }
    }

    public function test_status_desconhecido_nao_filtra_nada(): void
    {
        // Link velho ou query string editada à mão não pode esvaziar nem derrubar a tela.
        $this->assertSame(
            $this->props($this->vendedor)['clientes']['total'],
            $this->props($this->vendedor, ['status' => 'arquivado'])['clientes']['total'],
        );
    }

    public function test_admin_ve_todos_os_clientes_na_faixa(): void
    {
        // O escopo empresa inclui a loja do vendedor 002, que é ativa: o cliente 800
        // passa a ser ATIVO para quem enxerga os dois lados. Mesma regra, outro escopo.
        $this->assertArrayHasKey('800', $this->linhas($this->admin, ['status' => 'ativo']));
        $this->assertArrayNotHasKey('800', $this->linhas($this->admin, ['status' => 'inativo']));
    }
}
