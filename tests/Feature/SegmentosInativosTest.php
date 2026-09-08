<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Segmento;
use App\Models\SegmentoVendedor;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Carteira\ClienteStatusResolver;
use App\Services\Carteira\SegmentosInativosResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quadro "Segmentos Atendidos": clientes inativos nos segmentos que a pessoa ATENDE.
 *
 * ⚠️ A INVARIANTE, exigida pelo Tony em 2026-09-08 ("os dois números têm que bater em todos
 * os casos"): o total daqui é igual aos inativos da carteira do escopo — o mesmo número que
 * o card "Carteira por Segmento" mostra ao lado. Houve uma versão, no mesmo dia, que
 * listava só os segmentos atendidos e por isso divergia (66.753 contra 73.940); explicar a
 * diferença no subtítulo não bastou, porque número que precisa de legenda para não parecer
 * errado já custou a confiança. Os segmentos atendidos agora são MARCADOS, não filtrados.
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

    private function peso(string $codigo, float $peso): void
    {
        Segmento::where('codigo', $codigo)->update(['peso_potencial' => $peso]);
    }

    private function atende(string $codigo, string $cod = self::COD): void
    {
        SegmentoVendedor::create([
            'cod_vendedor' => $cod,
            'segmento_id' => Segmento::where('codigo', $codigo)->value('id'),
        ]);
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

    #[Test]
    public function test_conta_inativos_por_segmento_do_maior_para_o_menor(): void
    {
        $this->atende('101');
        $this->atende('103');

        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('A2', '101', $this->inativo());
        $this->cliente('B1', '103', $this->inativo());
        $this->cliente('C1', '101', $this->ativo()); // ativo: fora da conta

        $r = $this->resolver();

        $this->assertSame(3, $r['total']);
        $this->assertSame(['SUPERMERCADISTA', 'ORGAO PUBLICO'], array_column($r['linhas'], 'nome'));
        $this->assertSame([2, 1], array_column($r['linhas'], 'inativos'));
    }

    /**
     * ⚠️ A REGRA DO CARD, e a razão de ele ser assim: segmento NÃO atendido continua na
     * lista e no total — só não recebe a marca. Filtrar aqui faria o total discordar do card
     * "Carteira por Segmento" ao lado, que foi exatamente a reclamação do Tony.
     */
    #[Test]
    public function test_segmento_nao_atendido_entra_na_lista_sem_a_marca(): void
    {
        $this->atende('101');

        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('B1', '103', $this->inativo());
        $this->cliente('B2', '109', $this->inativo());

        $r = $this->resolver();

        $this->assertCount(3, $r['linhas'], 'os três segmentos aparecem');
        $this->assertSame(3, $r['total'], 'e os três contam no total');
        $this->assertSame(1, $r['atendidos']);

        $marca = array_combine(array_column($r['linhas'], 'nome'), array_column($r['linhas'], 'atendido'));
        $this->assertTrue($marca['SUPERMERCADISTA']);
        $this->assertFalse($marca['ORGAO PUBLICO']);
        $this->assertFalse($marca['DROGARIAS']);
    }

    /**
     * ⚠️ A INVARIANTE do bloco, conferida contra o MESMO corte que o card "Carteira por
     * Segmento" usa (`ClienteStatusResolver`), e não contra um número escrito à mão: é o que
     * pega uma futura mudança que volte a filtrar linha aqui.
     */
    #[Test]
    public function test_total_bate_com_os_inativos_da_carteira(): void
    {
        $this->atende('101');

        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('B1', '103', $this->inativo());
        $this->cliente('B2', '109', null);
        $this->cliente('C1', '101', $this->ativo());

        $limite = app(ClienteStatusResolver::class)->limiteInativando()->toDateString();

        $inativosDaCarteira = Cliente::query()
            ->where('cod_vendedor', self::COD)
            ->where(fn ($q) => $q->whereNull('data_ultima_compra')->orWhere('data_ultima_compra', '<', $limite))
            ->count();

        $this->assertSame($inativosDaCarteira, $this->resolver()['total']);
    }

    /**
     * ⚠️ Segmento atendido SEM nenhum inativo aparece com ZERO, não some — pedido do diretor
     * em 08/09 ("colocar todos os segmentos que essa pessoa atende, e destacar na listagem").
     * Somar zero não mexe na invariante do total.
     */
    #[Test]
    public function test_segmento_atendido_sem_inativo_aparece_com_zero(): void
    {
        $this->atende('109');

        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('A2', '101', $this->inativo());

        $r = $this->resolver();

        $linha = collect($r['linhas'])->firstWhere('nome', 'DROGARIAS');

        $this->assertNotNull($linha, 'segmento atendido sem inativo tem que aparecer');
        $this->assertSame(0, $linha['inativos']);
        $this->assertTrue($linha['atendido']);
        $this->assertSame(2, $r['total'], 'e não altera o total da carteira');
    }

    /**
     * ⚠️ Sem NENHUM segmento cadastrado a lista continua cheia: são os inativos da carteira,
     * nenhum deles marcado. A versão que filtrava por segmento atendido deixava essas
     * pessoas com o card inteiro vazio e a carteira cheia de inativo.
     */
    #[Test]
    public function test_sem_segmento_cadastrado_a_lista_ainda_traz_os_inativos(): void
    {
        $this->cliente('A1', '101', $this->inativo());

        $r = $this->resolver();

        $this->assertSame(['SUPERMERCADISTA'], array_column($r['linhas'], 'nome'));
        $this->assertSame(1, $r['total']);
        $this->assertSame(0, $r['atendidos']);
        $this->assertFalse($r['linhas'][0]['atendido']);
    }

    /**
     * ⚠️ Cliente que nunca comprou tem `data_ultima_compra` nula e É inativo — mesma regra
     * do ClienteStatusResolver, que a Carteira usa. Sem isso o quadro esconderia justamente
     * quem nunca foi vendido.
     */
    #[Test]
    public function test_cliente_que_nunca_comprou_conta_como_inativo(): void
    {
        $this->atende('101');
        $this->cliente('A1', '101', null);

        $this->assertSame(1, $this->resolver()['total']);
    }

    /** Cliente na fronteira do corte (365 dias) ainda não é inativo. */
    #[Test]
    public function test_respeita_o_corte_de_365_dias(): void
    {
        $this->atende('101');
        $this->cliente('A1', '101', now()->subDays(ClienteStatusResolver::DIAS_INATIVANDO - 5)->toDateString());
        $this->cliente('A2', '101', now()->subDays(ClienteStatusResolver::DIAS_INATIVANDO + 5)->toDateString());

        $this->assertSame(1, $this->resolver()['total']);
    }

    /**
     * ⚠️ A INVARIANTE do quadro: a soma das linhas fecha com o total, SEMPRE. É o que
     * impede o rodapé de anunciar um número que a tabela não explica.
     */
    #[Test]
    public function test_soma_das_linhas_sempre_fecha_com_o_total(): void
    {
        foreach (['101', '103', '109'] as $i => $codigo) {
            $this->atende($codigo);

            foreach (range(0, $i) as $j) {
                $this->cliente("S{$codigo}C{$j}", $codigo, $this->inativo());
            }
        }

        $r = $this->resolver();

        $this->assertCount(3, $r['linhas']);
        $this->assertSame($r['total'], array_sum(array_column($r['linhas'], 'inativos')));
    }

    /**
     * ⚠️ A linha é um LINK para a Carteira, e o filtro de lá compara `cod_segmento` — o
     * CÓDIGO, não o nome. Mandar o nome faria o link abrir uma lista vazia; foi o mismatch
     * nome×código que quebrou a aderência em silêncio em julho de 2026.
     *
     * ⚠️ E o código tem que chegar como STRING: `pluck('nome', 'codigo')` devolve array
     * PHP, que converte chave numérica em INTEIRO, e as comparações aqui são estritas —
     * foi assim que o segmento atendido sumiu da lista na primeira execução desta regra.
     */
    #[Test]
    public function test_linha_carrega_o_codigo_do_segmento_como_string(): void
    {
        $this->atende('101');
        $this->cliente('A1', '101', $this->inativo());

        $linha = $this->resolver()['linhas'][0];

        $this->assertSame('101', $linha['codigo']);
        $this->assertSame('SUPERMERCADISTA', $linha['nome']);
    }

    /**
     * ⚠️ A CONTA DO POTENCIAL: inativos × peso do segmento. Os pesos (0-20) vieram da
     * diretoria em 08/09/2026 e moram em `segmentos.peso_potencial`.
     *
     * Os números do fixture são escolhidos para não empatarem por acaso: 3×8=24 e 2×20=40
     * invertem a ordem em relação à contagem bruta, então trocar `potencial` por `inativos`
     * na ordenação — ou multiplicar pelo peso errado — faz este teste falhar.
     */
    #[Test]
    public function test_potencial_e_inativos_vezes_o_peso_do_segmento(): void
    {
        $this->atende('109');
        $this->atende('101');
        $this->peso('109', 8);
        $this->peso('101', 20);

        foreach (range(1, 3) as $i) {
            $this->cliente("D{$i}", '109', $this->inativo());
        }
        foreach (range(1, 2) as $i) {
            $this->cliente("S{$i}", '101', $this->inativo());
        }

        $r = $this->resolver();

        // SUPERMERCADISTA tem MENOS inativos e vem primeiro: 2×20 > 3×8.
        $this->assertSame(['SUPERMERCADISTA', 'DROGARIAS'], array_column($r['linhas'], 'nome'));
        $this->assertSame([40, 24], array_column($r['linhas'], 'potencial'));
        $this->assertSame([20.0, 8.0], array_column($r['linhas'], 'peso'));
        $this->assertSame(64, $r['totalPotencial']);
        $this->assertSame(5, $r['total'], 'a contagem bruta não muda com o peso');
    }

    /**
     * ⚠️ Peso 0 é a diretoria dizendo "não é alvo de reativação", não dado faltando: o
     * segmento continua listado, com os inativos visíveis, mas potencial zero e no fim da
     * fila. Esconder a linha seria decidir por eles.
     */
    #[Test]
    public function test_peso_zero_zera_o_potencial_mas_nao_esconde_o_segmento(): void
    {
        $this->atende('103');
        $this->atende('109');
        $this->peso('103', 0);
        $this->peso('109', 8);

        foreach (range(1, 9) as $i) {
            $this->cliente("O{$i}", '103', $this->inativo());
        }
        $this->cliente('D1', '109', $this->inativo());

        $r = $this->resolver();

        $this->assertSame(['DROGARIAS', 'ORGAO PUBLICO'], array_column($r['linhas'], 'nome'));
        $this->assertSame([8, 0], array_column($r['linhas'], 'potencial'));
        $this->assertSame(10, $r['total'], 'os 9 inativos continuam contados');
        $this->assertSame(8, $r['totalPotencial']);
    }

    #[Test]
    public function test_escopo_do_vendedor_e_respeitado(): void
    {
        $this->atende('101');
        $this->atende('101', '999999');

        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('Z9', '101', $this->inativo(), '999999');

        $this->assertSame(1, $this->resolver()['total']);
        $this->assertSame(2, $this->resolver(null)['total'], 'escopo null é a empresa inteira');
        $this->assertSame(2, $this->resolver([self::COD, '999999'])['total'], 'escopo de equipe soma os dois');
    }

    /**
     * ⚠️ Regressão do pedido do diretor: "na tela inicial dos ADM devem ter essas
     * informações também, e ao filtrar a equipe vemos o resumo da equipe".
     */
    #[Test]
    public function test_gestor_recebe_o_bloco_e_o_recorte_muda_ao_filtrar_a_equipe(): void
    {
        $this->atende('101');
        $this->atende('103', '000006');

        $this->cliente('A1', '101', $this->inativo());
        $this->cliente('Z9', '103', $this->inativo(), '000006');

        $vendedor = User::factory()->create();
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => '000006', 'cod_super' => '000099']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $semFiltro = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('page')['props'];
        $this->assertNotNull($semFiltro['segmentosInativos']);
        $this->assertSame(2, $semFiltro['segmentosInativos']['total'], 'empresa inteira');

        $comEquipe = $this->actingAs($admin)
            ->get(route('dashboard', ['visao_supervisor' => '000099']))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $comEquipe['segmentosInativos']['total'], 'só o segmento da equipe');
    }
}
