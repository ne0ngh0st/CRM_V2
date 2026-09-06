<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Segmento;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Carteira\SegmentosInativosResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quadro "Segmentos Atendidos": clientes inativos por segmento.
 *
 * Substituiu o Potencial por família de produto em 2026-09-06 a pedido do diretor
 * ("MENOS É MAIS"). As decisões estão no docblock do {@see SegmentosInativosResolver}.
 */
class SegmentosInativosTest extends TestCase
{
    use RefreshDatabase;

    private const COD = '010617';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        Segmento::create(['codigo' => '103', 'nome' => 'ORGAO PUBLICO']);
        Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
    }

    private function cliente(string $cod, string $segmento, ?string $ultimaCompra, string $vendedor = self::COD): void
    {
        Cliente::create([
            'cod_cliente' => $cod,
            'loja' => '01',
            'razao_social' => "Cliente {$cod}",
            'cod_vendedor' => $vendedor,
            'cod_segmento' => $segmento,
            'data_ultima_compra' => $ultimaCompra,
        ]);
    }

    private function inativo(): string
    {
        return now()->subDays(ClienteStatusResolver::DIAS_INATIVANDO + 30)->toDateString();
    }

    private function ativo(): string
    {
        return now()->subDays(10)->toDateString();
    }

    private function resolver(?array $cods = [self::COD]): array
    {
        return app(SegmentosInativosResolver::class)->resolver($cods);
    }

    /** @return array<string,int> nome => inativos */
    private function porNome(array $r): array
    {
        return collect($r['linhas'])->pluck('inativos', 'nome')->all();
    }

    #[Test]
    public function test_conta_inativos_por_segmento_do_maior_para_o_menor(): void
    {
        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('A2', '101', $this->inativo());
        $this->cliente('B1', '103', $this->inativo());
        $this->cliente('C1', '109', $this->ativo()); // ativo: fora da conta

        $r = $this->resolver();

        $this->assertSame(3, $r['total']);
        $this->assertSame(['SUPERMERCADISTA', 'ORGAO PUBLICO'], array_column($r['linhas'], 'nome'));
        $this->assertSame([2, 1], array_column($r['linhas'], 'inativos'));
    }

    /**
     * ⚠️ Cliente que nunca comprou tem `data_ultima_compra` nula e É inativo — mesma regra
     * do ClienteStatusResolver, que a Carteira usa. Sem isso o quadro esconderia
     * justamente quem nunca foi vendido.
     */
    #[Test]
    public function test_cliente_que_nunca_comprou_conta_como_inativo(): void
    {
        $this->cliente('A1', '101', null);

        $this->assertSame(1, $this->resolver()['total']);
    }

    /** Cliente na fronteira do corte (365 dias) ainda não é inativo. */
    #[Test]
    public function test_respeita_o_corte_de_365_dias(): void
    {
        $this->cliente('A1', '101', now()->subDays(ClienteStatusResolver::DIAS_INATIVANDO - 5)->toDateString());
        $this->cliente('A2', '101', now()->subDays(ClienteStatusResolver::DIAS_INATIVANDO + 5)->toDateString());

        $this->assertSame(1, $this->resolver()['total']);
    }

    /**
     * ⚠️ A INVARIANTE do quadro: a soma das linhas fecha com o total, SEMPRE.
     *
     * Desde 2026-09-06 o resolver devolve todos os segmentos e quem corta é a tela (mostra
     * 3, "ver mais" abre o resto). É o que garante que o TOTAL do rodapé continue sendo a
     * carteira inteira mesmo com a lista fechada — se o corte fosse no servidor, o total
     * mentiria ou a lista esconderia cliente.
     */
    #[Test]
    public function test_soma_das_linhas_sempre_fecha_com_o_total(): void
    {
        foreach (range(1, 8) as $i) {
            Segmento::firstOrCreate(['codigo' => "20{$i}"], ['nome' => "SEGMENTO {$i}"]);

            foreach (range(1, 9 - $i) as $j) {
                $this->cliente("S{$i}C{$j}", "20{$i}", $this->inativo());
            }
        }

        $r = $this->resolver();

        $this->assertCount(8, $r['linhas'], 'nenhum segmento fica de fora do payload');
        $this->assertSame(
            $r['total'],
            array_sum(array_column($r['linhas'], 'inativos')),
            'a soma das linhas tem que fechar com o total',
        );
    }

    #[Test]
    public function test_escopo_do_vendedor_e_respeitado(): void
    {
        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('Z9', '101', $this->inativo(), '999999');

        $this->assertSame(1, $this->resolver()['total']);
        $this->assertSame(2, $this->resolver(null)['total'], 'escopo null é a empresa inteira');
        $this->assertSame(2, $this->resolver([self::COD, '999999'])['total'], 'escopo de equipe soma os dois');
    }

    /**
     * ⚠️ ~331 clientes da base têm `cod_segmento` fora dos 23 conhecidos, sem descrição em
     * tabela nenhuma do TOTVS. Eles aparecem pelo código bruto em vez de sumir — mesmo
     * tratamento que a Carteira já dá.
     */
    #[Test]
    public function test_segmento_sem_cadastro_aparece_pelo_codigo(): void
    {
        $this->cliente('A1', '999', $this->inativo());

        $this->assertSame(['999' => 1], $this->porNome($this->resolver()));
    }

    /**
     * ⚠️ A linha é um LINK para a Carteira, e o filtro de lá compara `cod_segmento` — o
     * CÓDIGO, não o nome. Mandar o nome faria o link abrir uma lista vazia. Foi o
     * mismatch nome×código que quebrou a aderência em silêncio em julho de 2026.
     */
    #[Test]
    public function test_linha_carrega_o_codigo_do_segmento_para_o_link(): void
    {
        $this->cliente('A1', '101', $this->inativo());

        $linha = $this->resolver()['linhas'][0];

        $this->assertSame('101', $linha['codigo']);
        $this->assertSame('SUPERMERCADISTA', $linha['nome']);
    }

    /**
     * ⚠️ Cliente sem segmento no cadastro entra com código vazio, e é assim que o front
     * sabe que aquela linha não pode virar link: o filtro da Carteira compara o código, e
     * um link vazio abriria a carteira inteira — pior que linha estática.
     */
    #[Test]
    public function test_cliente_sem_segmento_entra_com_codigo_vazio(): void
    {
        $this->cliente('A1', '', $this->inativo());

        $linha = $this->resolver()['linhas'][0];

        $this->assertSame('', $linha['codigo']);
        $this->assertSame(1, $linha['inativos']);
    }

    /**
     * ⚠️ A INVARIANTE ENTRE OS DOIS CARDS, e a razão de ela existir: até 2026-09-06 o
     * "Carteira por Segmento" somava só DENTRO + FORA nos quadrinhos, deixando de fora os
     * clientes cujo vendedor não tem segmento cadastrado. Resultado: 73.935 aqui contra
     * 21.619 lá, dois "Inativos" na mesma tela — foi o que o Tony reportou como confuso.
     *
     * Este teste trava a reconciliação no lado do servidor: o total deste quadro tem que
     * ser a soma dos TRÊS baldes de aderência, que é o que o outro card passou a exibir.
     */
    #[Test]
    public function test_total_bate_com_a_soma_dos_tres_baldes_da_aderencia(): void
    {
        // Com segmento cadastrado para o vendedor (entra em dentro/fora).
        \App\Models\SegmentoVendedor::create([
            'cod_vendedor' => self::COD,
            'segmento_id' => Segmento::where('codigo', '101')->value('id'),
        ]);
        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('A2', '103', $this->inativo());

        // Vendedor SEM segmento cadastrado: cai em "sem segmento definido".
        $this->cliente('B1', '101', $this->inativo(), '888888');

        $aderencia = app(\App\Services\Carteira\CarteiraAderenciaResolver::class)
            ->resolver(Cliente::query());

        $somaDosTres = $aderencia['dentroSegmento']['inativos']
            + $aderencia['foraSegmento']['inativos']
            + $aderencia['semSegmentoDefinido']['inativos'];

        $this->assertSame(3, $this->resolver(null)['total']);
        $this->assertSame(3, $somaDosTres, 'os dois cards têm que contar o mesmo universo');
    }

    /**
     * ⚠️ Regressão do pedido do diretor: "na tela inicial dos ADM devem ter essas
     * informações também". Até 2026-09-06 o quadro carregava `&& ! $eGestor`.
     */
    #[Test]
    public function test_gestor_recebe_o_bloco_e_o_recorte_muda_ao_filtrar_a_equipe(): void
    {
        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('Z9', '103', $this->inativo(), '000006');

        $vendedor = User::factory()->create();
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => '000006', 'cod_super' => '000099']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $semFiltro = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertNotNull($semFiltro['segmentosInativos'], 'admin sem filtro tem que receber o quadro');
        $this->assertSame(2, $semFiltro['segmentosInativos']['total'], 'empresa inteira');

        $comEquipe = $this->actingAs($admin)
            ->get(route('dashboard', ['visao_supervisor' => '000099']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $comEquipe['segmentosInativos']['total'], 'ao filtrar a equipe, só o cliente dela');
    }
}
