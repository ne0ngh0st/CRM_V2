<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O card "Carteira por Segmento" dentro da própria Carteira: ele DESENHA a quebra por
 * status e por aderência, então não pode aplicar a si esses dois filtros.
 *
 * 🚨 Pedido do Tony em 2026-09-15: "quando clico num KPI somem os outros". Com
 * `?status=inativo` o card mostrava "0 ativos · 0 inativando · 543 inativos" — respondendo
 * "quantos inativos entre os inativos?" e apagando a única informação que ele existe para
 * dar. Um filtro não se aplica à faceta que ele controla.
 *
 * 🚨 E aí apareceu o SEGUNDO caso do defeito de grão de 2026-09-15, justamente porque o
 * card passou a marcar a célula aplicada: a matriz dizia 408 e a lista trazia 420. "Fora
 * do segmento" era ALGUMA filial fora no filtro e NENHUMA filial dentro no card, então
 * cliente com filiais de segmentos diferentes entrava nos dois grupos.
 *
 * O teste que sustenta tudo isto é `test_todo_numero_do_card_bate_com_a_lista_que_ele_abre`:
 * ele percorre a matriz inteira comparando cada número com o total da lista daquele
 * recorte. É a invariante do Tony — "os dois números têm que bater em todos os casos" —
 * escrita como código em vez de conferida no olho.
 *
 * ⚠️ Fixture montado para que NENHUMA célula da matriz empate com outra: com valores
 * parecidos, trocar uma faceta pela outra passaria verde.
 */
class CarteiraCardFacetasTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private const COD = '001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => self::COD]);

        // O vendedor atende SUPERMERCADISTA (101). 103 e 109 são "fora".
        $super = Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        Segmento::create(['codigo' => '103', 'nome' => 'ORGAO PUBLICO']);
        Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        SegmentoVendedor::create(['cod_vendedor' => self::COD, 'segmento_id' => $super->id]);

        $hoje = now();
        $ativa = $hoje->copy()->subDays(10)->toDateString();
        $meio = $hoje->copy()->subDays(300)->toDateString();
        $velha = $hoje->copy()->subDays(500)->toDateString();

        // Puros DENTRO do segmento — contagens diferentes por faixa, de propósito.
        $this->cliente('100', '0001', '101', $ativa);
        $this->cliente('110', '0001', '101', $ativa);
        $this->cliente('120', '0001', '101', $ativa);
        $this->cliente('200', '0001', '101', $meio);
        $this->cliente('300', '0001', '101', $velha);
        $this->cliente('310', '0001', '101', $velha);

        // Puros FORA do segmento.
        $this->cliente('400', '0001', '103', $ativa);
        $this->cliente('500', '0001', '109', $meio);
        $this->cliente('510', '0001', '103', $meio);
        $this->cliente('600', '0001', '109', $velha);

        /*
         * 🚨 O CLIENTE QUE DENUNCIA OS DOIS DEFEITOS DE UMA VEZ. Filiais em segmentos
         * diferentes (uma dentro, uma fora) E em faixas de status diferentes (uma ativa,
         * uma inativa). Para o card ele é ATIVO (a data é o `MAX`) e DENTRO (basta uma
         * filial dentro); pelo filtro antigo ele aparecia também em "inativo" e em "fora".
         */
        $this->cliente('700', '0001', '101', $ativa);
        $this->cliente('700', '0002', '109', $velha);

        // Vendedor com segmento definido, cliente sem segmento reconhecido: nem dentro nem
        // fora no card (cai em "sem segmento" só quando o VENDEDOR não tem segmento), mas
        // aqui serve para provar que código órfão não some da contagem de status.
        $this->cliente('800', '0001', '999', $velha);
    }

    private function cliente(string $cod, string $loja, string $segmento, ?string $compra): void
    {
        Cliente::create([
            'cod_cliente' => $cod,
            'loja' => $loja,
            'razao_social' => "CLIENTE {$cod}/{$loja}",
            'cod_vendedor' => self::COD,
            'estado' => 'SP',
            'cod_segmento' => $segmento,
            'data_ultima_compra' => $compra,
        ]);
    }

    private function props(array $params = []): array
    {
        $resposta = $this->actingAs($this->vendedor)->get(route('carteira.index', $params));
        $resposta->assertOk();

        return $resposta->viewData('page')['props'];
    }

    /** Quantos clientes a LISTA traz com este recorte. */
    private function totalDaLista(array $params): int
    {
        return $this->props($params)['clientes']['total'];
    }

    public function test_o_card_mostra_a_carteira_inteira_mesmo_com_status_filtrado(): void
    {
        $semFiltro = $this->props()['kpis'];
        $filtrado = $this->props(['status' => 'inativo'])['kpis'];

        $this->assertSame($semFiltro, $filtrado, 'o card não pode aplicar a si a faceta que desenha');

        // E o que ele mostra continua sendo as três faixas cheias, não duas zeradas.
        foreach (['ativos', 'inativando', 'inativos'] as $faixa) {
            $this->assertGreaterThan(
                0,
                $filtrado['dentroSegmento'][$faixa] + $filtrado['foraSegmento'][$faixa] + $filtrado['semSegmentoDefinido'][$faixa],
                "faixa {$faixa} zerada no card com status=inativo aplicado",
            );
        }
    }

    public function test_o_card_mostra_a_matriz_inteira_mesmo_com_aderencia_filtrada(): void
    {
        $this->assertSame(
            $this->props()['kpis'],
            $this->props(['aderencia' => 'fora'])['kpis'],
        );
    }

    public function test_todo_numero_do_card_bate_com_a_lista_que_ele_abre(): void
    {
        /*
         * 🚨 A invariante, para os 11 números clicáveis do card de uma vez: três tiles de
         * status, os dois lados da barra de aderência e as seis células da matriz. Cada um
         * é um link, e o número que a pessoa clicou tem que ser o número que ela encontra.
         */
        $kpis = $this->props()['kpis'];

        $faixas = ['ativo' => 'ativos', 'inativando' => 'inativando', 'inativo' => 'inativos'];
        $lados = ['dentro' => 'dentroSegmento', 'fora' => 'foraSegmento'];

        foreach ($faixas as $status => $campo) {
            $tile = $kpis['dentroSegmento'][$campo] + $kpis['foraSegmento'][$campo] + $kpis['semSegmentoDefinido'][$campo];

            $this->assertSame($tile, $this->totalDaLista(['status' => $status]), "tile {$status}");

            foreach ($lados as $aderencia => $bloco) {
                $this->assertSame(
                    $kpis[$bloco][$campo],
                    $this->totalDaLista(['status' => $status, 'aderencia' => $aderencia]),
                    "célula {$status} × {$aderencia}",
                );
            }
        }

        foreach ($lados as $aderencia => $bloco) {
            $this->assertSame(
                $kpis[$bloco]['total'],
                $this->totalDaLista(['aderencia' => $aderencia]),
                "barra {$aderencia}",
            );
        }
    }

    public function test_cliente_com_filiais_de_segmentos_diferentes_conta_uma_vez_so(): void
    {
        /*
         * O caso 408 × 420. O cliente 700 tem uma filial dentro e outra fora: o card o
         * conta em DENTRO (basta uma filial dentro), então ele não pode aparecer na lista
         * de FORA — senão o mesmo cliente está nos dois grupos e a soma passa do total.
         */
        $dentro = collect($this->props(['aderencia' => 'dentro'])['clientes']['data'])->pluck('codCliente');
        $fora = collect($this->props(['aderencia' => 'fora'])['clientes']['data'])->pluck('codCliente');

        $this->assertTrue($dentro->contains('700'));
        $this->assertFalse($fora->contains('700'), 'cliente com filial dentro não é "fora do segmento"');
        $this->assertEmpty($dentro->intersect($fora), 'nenhum cliente pode estar nos dois lados');
    }

    public function test_os_demais_filtros_continuam_valendo_no_card(): void
    {
        /*
         * ⚠️ O contrário do teste de cima, e igualmente importante: só as facetas que o
         * card DESENHA ficam de fora. Estado não é coluna do card, então filtrar por ele
         * tem que mudar os números — senão o card deixa de ser o retrato do que se olha.
         */
        Cliente::create([
            'cod_cliente' => '900',
            'loja' => '0001',
            'razao_social' => 'CLIENTE DO RIO',
            'cod_vendedor' => self::COD,
            'estado' => 'RJ',
            'cod_segmento' => '101',
            'data_ultima_compra' => now()->subDays(10)->toDateString(),
        ]);

        $this->assertSame(1, $this->props(['estado' => 'RJ'])['kpis']['total']);
        $this->assertGreaterThan(1, $this->props()['kpis']['total']);
    }

    public function test_por_filial_a_aderencia_continua_sendo_da_filial(): void
    {
        // `agrupar=0` lista lojas, e ali a aderência exibida é a da loja: a filial 0002 do
        // cliente 700 está fora do segmento e tem que aparecer. Cada modo filtra no grão
        // que mostra — mesma regra do filtro de status.
        $props = $this->props(['aderencia' => 'fora', 'agrupar' => 0]);

        $this->assertTrue(
            collect($props['clientes']['data'])->contains(fn ($l) => $l['codCliente'] === '700'),
        );
    }
}
