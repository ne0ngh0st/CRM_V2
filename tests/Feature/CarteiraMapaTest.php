<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A aba Mapa da Carteira e o filtro `?municipio=` que o clique na bolha aplica.
 *
 * 🥇 A regra que tudo aqui trava: O MAPA NÃO DECIDE QUEM ESTÁ NA LISTA, SÓ MOSTRA ONDE
 * ELES ESTÃO. Todo número do mapa tem que bater com a lista que ele abre — é a invariante
 * do Tony ("os dois números têm que bater em todos os casos") aplicada a um grão novo:
 * o mapa conta ENDEREÇO (filial) e a lista conta CLIENTE.
 *
 * ⚠️ Fixture deliberadamente torto, porque com dados "redondos" quase toda regra errada
 * passa verde:
 *
 *   - 100 tem lojas em DUAS cidades e uma entrega: aparece em duas bolhas, uma vez no
 *     total; a loja da capital é velha e a de Campinas comprou há 5 dias — a bolha da
 *     capital tem que sair ATIVA (status do cliente), não inativa (status da filial);
 *   - 800 é dividido entre vendedores: a loja que comprou ontem é de OUTRA pessoa;
 *   - 400 não tem município resolvido: fora do mapa, dentro da conta;
 *   - 500 e 600 são cidades HOMÔNIMAS em UFs diferentes, com códigos diferentes;
 *   - nenhuma bolha tem os mesmos números de outra.
 */
class CarteiraMapaTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPINAS = 3509502;

    private const SAO_PAULO = 3550308;

    private const RIO = 3304557;

    private const BOM_JESUS_PI = 2201903;

    private const BOM_JESUS_RS = 4302303;

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
        $ativa = $hoje->copy()->subDays(5)->toDateString();
        $meio = $hoje->copy()->subDays(300)->toDateString();
        $velha = $hoje->copy()->subDays(400)->toDateString();

        $this->cliente('100', '0001', '001', $velha, 'SP', 'SAO PAULO', self::SAO_PAULO);
        $this->cliente('100', '0002', '001', $ativa, 'SP', 'CAMPINAS', self::CAMPINAS);
        $this->cliente('100', 'E001', '001', null, 'SP', 'CAMPINAS', self::CAMPINAS);

        $this->cliente('200', '0001', '001', $velha, 'SP', 'CAMPINAS', self::CAMPINAS);

        $this->cliente('300', '0001', '001', $meio, 'RJ', 'RIO DE JANEIRO', self::RIO);
        $this->cliente('300', '0002', '001', $velha, 'RJ', 'RIO DE JANEIRO', self::RIO);

        $this->cliente('400', '0001', '001', $ativa, 'SP', 'CIDADE QUE NAO EXISTE', null);

        $this->cliente('500', '0001', '001', $ativa, 'PI', 'BOM JESUS', self::BOM_JESUS_PI);
        $this->cliente('600', '0001', '001', $velha, 'RS', 'BOM JESUS', self::BOM_JESUS_RS);

        $this->cliente('800', '0001', '001', $velha, 'SP', 'CAMPINAS', self::CAMPINAS);
        $this->cliente('800', '0002', '002', $ativa, 'SP', 'CAMPINAS', self::CAMPINAS);
    }

    private function cliente(string $cod, string $loja, string $vendedor, ?string $compra, string $uf, string $municipio, ?int $codMunicipio): void
    {
        $cliente = Cliente::create([
            'cod_cliente' => $cod,
            'loja' => $loja,
            'razao_social' => "CLIENTE {$cod} LOJA {$loja}",
            'cod_vendedor' => $vendedor,
            'estado' => $uf,
            'municipio' => $municipio,
            'data_ultima_compra' => $compra,
        ]);

        // Fora do `$fillable` de propósito (dono único: MunicipioSincronizador).
        DB::table('clientes')->where('id', $cliente->id)->update(['cod_municipio' => $codMunicipio]);
    }

    private function props(User $como, array $params = []): array
    {
        $resposta = $this->actingAs($como)->get(route('carteira.index', $params));
        $resposta->assertOk();

        return $resposta->viewData('page')['props'];
    }

    /** O mapa é prop opcional: só vem em recarga parcial, como a tela pede. */
    private function mapa(User $como, array $params = []): array
    {
        /*
         * ⚠️ Sem isto dois testes daqui passavam com o código QUEBRADO (achado por
         * mutação): o mapa é cacheado por escopo + filtros, e a chave ignora `agrupar` e
         * `municipio` — de propósito, porque a consulta também ignora. Só que aí a segunda
         * chamada do mesmo teste devolvia a resposta da primeira, e "as duas são iguais"
         * era verdade por causa do cache, não da regra.
         */
        Cache::flush();

        $completa = $this->actingAs($como)->get(route('carteira.index', $params));
        $completa->assertOk();

        $mapa = $this->actingAs($como)->get(route('carteira.index', $params), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $completa->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'Carteira/Index',
            'X-Inertia-Partial-Data' => 'mapa',
        ])->assertOk()->json('props.mapa');

        $this->assertNotNull($mapa, 'a recarga parcial tem que trazer o mapa');

        return $mapa;
    }

    /** @return array<int, array<string, int>> bolhas indexadas pelo código do município */
    private function bolhas(User $como, array $params = []): array
    {
        return collect($this->mapa($como, $params)['municipios'])->keyBy('cod')->all();
    }

    public function test_visita_completa_nao_paga_pelo_mapa(): void
    {
        $this->assertArrayNotHasKey('mapa', $this->props($this->vendedor, ['aba' => 'mapa']));
    }

    public function test_cada_bolha_conta_filiais_entregas_e_clientes_do_municipio(): void
    {
        $bolhas = $this->bolhas($this->vendedor);

        $this->assertSame(
            ['cod' => self::CAMPINAS, 'comerciais' => 3, 'entregas' => 1, 'clientes' => 3, 'ativos' => 1, 'inativando' => 0, 'inativos' => 2],
            $bolhas[self::CAMPINAS],
        );
        $this->assertSame(
            ['cod' => self::RIO, 'comerciais' => 2, 'entregas' => 0, 'clientes' => 1, 'ativos' => 0, 'inativando' => 1, 'inativos' => 0],
            $bolhas[self::RIO],
        );
    }

    public function test_a_cor_da_bolha_e_o_status_do_cliente_nao_o_da_filial(): void
    {
        /*
         * A loja da capital do cliente 100 está parada há 400 dias, mas o cliente comprou
         * há 5 dias por Campinas. Na lista ele é ATIVO; pintar a capital de inativo
         * mandaria o representante prospectar quem já compra.
         */
        $capital = $this->bolhas($this->vendedor)[self::SAO_PAULO];

        $this->assertSame(1, $capital['ativos']);
        $this->assertSame(0, $capital['inativos']);
    }

    public function test_cliente_em_duas_cidades_esta_nas_duas_bolhas_e_uma_vez_no_total(): void
    {
        $mapa = $this->mapa($this->vendedor);
        $bolhas = collect($mapa['municipios'])->keyBy('cod');

        $this->assertSame(1, $bolhas[self::SAO_PAULO]['clientes']);
        $this->assertSame(3, $bolhas[self::CAMPINAS]['clientes']);

        // 100, 200, 300, 400, 500, 600 e 800 — somar "clientes por cidade" daria 8.
        $this->assertSame(7, $mapa['totais']['clientes']);
        $this->assertSame(8, $bolhas->sum('clientes') + $mapa['semLocalizacao']['clientes'], 'fixture degenerado: a soma das bolhas tem que DIFERIR do total');
    }

    public function test_comerciais_mais_entregas_fecham_com_as_filiais_do_recorte(): void
    {
        foreach ([[], ['status' => 'inativo'], ['estado' => 'SP']] as $filtros) {
            $mapa = $this->mapa($this->vendedor, $filtros);
            $bolhas = collect($mapa['municipios']);

            $somaDasBolhas = $bolhas->sum('comerciais') + $bolhas->sum('entregas')
                + $mapa['semLocalizacao']['comerciais'] + $mapa['semLocalizacao']['entregas'];

            $this->assertSame($mapa['totais']['comerciais'] + $mapa['totais']['entregas'], $somaDasBolhas);
        }

        // Sem filtro, é a carteira inteira do vendedor: 10 filiais, 1 delas entrega.
        $mapa = $this->mapa($this->vendedor);
        $this->assertSame(9, $mapa['totais']['comerciais']);
        $this->assertSame(1, $mapa['totais']['entregas']);
        $this->assertSame(10, $this->props($this->vendedor, ['agrupar' => '0'])['clientes']['total']);
    }

    public function test_municipio_sem_codigo_fica_fora_das_bolhas_e_dentro_da_conta(): void
    {
        $mapa = $this->mapa($this->vendedor);

        $this->assertSame(1, $mapa['semLocalizacao']['comerciais']);
        $this->assertSame(1, $mapa['semLocalizacao']['clientes']);
        $this->assertCount(5, $mapa['municipios']);
    }

    public function test_cidades_homonimas_em_ufs_diferentes_nao_se_fundem(): void
    {
        $bolhas = $this->bolhas($this->vendedor);

        $this->assertSame(1, $bolhas[self::BOM_JESUS_PI]['ativos']);
        $this->assertSame(1, $bolhas[self::BOM_JESUS_RS]['inativos']);
    }

    public function test_o_numero_de_toda_bolha_bate_com_a_lista_que_o_clique_abre(): void
    {
        /*
         * 🚨 A invariante. Para cada recorte e cada bolha, "N clientes" do tooltip tem
         * que ser o total da lista com `?municipio=` — e o total do cabeçalho do mapa, o
         * total da lista sem ele.
         */
        foreach ([[], ['status' => 'ativo'], ['status' => 'inativando'], ['status' => 'inativo'], ['estado' => 'SP']] as $filtros) {
            $mapa = $this->mapa($this->vendedor, $filtros);
            $rotulo = json_encode($filtros);

            $this->assertSame(
                $this->props($this->vendedor, $filtros)['clientes']['total'],
                $mapa['totais']['clientes'],
                "total do mapa ≠ total da lista em {$rotulo}",
            );

            foreach ($mapa['municipios'] as $bolha) {
                $this->assertSame(
                    $this->props($this->vendedor, $filtros + ['municipio' => $bolha['cod']])['clientes']['total'],
                    $bolha['clientes'],
                    "bolha {$bolha['cod']} ≠ lista em {$rotulo}",
                );
            }
        }
    }

    public function test_filtro_de_status_no_mapa_e_por_cliente_mesmo_pedindo_a_lista_por_filial(): void
    {
        /*
         * `?agrupar=0` muda como a LISTA é contada. O mapa continua consolidando por
         * cliente: por filial, o cliente 100 (ativo) entraria entre os inativos pela loja
         * parada da capital — o defeito de 2026-09-15 de volta, agora desenhado num mapa.
         */
        $agrupado = $this->mapa($this->vendedor, ['status' => 'inativo']);
        $porFilial = $this->mapa($this->vendedor, ['status' => 'inativo', 'agrupar' => '0']);

        $this->assertSame($agrupado, $porFilial);
        $this->assertArrayNotHasKey(self::SAO_PAULO, collect($agrupado['municipios'])->keyBy('cod')->all());
        $this->assertSame(3, $agrupado['totais']['clientes']); // 200, 600, 800
    }

    public function test_o_mapa_nao_se_filtra_pelo_municipio_que_ele_mesmo_desenha(): void
    {
        $this->assertSame(
            $this->mapa($this->vendedor),
            $this->mapa($this->vendedor, ['municipio' => self::CAMPINAS]),
        );
    }

    public function test_filial_de_outro_vendedor_nao_entra_na_bolha_nem_no_status(): void
    {
        $doVendedor = $this->bolhas($this->vendedor)[self::CAMPINAS];
        $doAdmin = $this->bolhas($this->admin)[self::CAMPINAS];

        $this->assertSame(3, $doVendedor['comerciais']);
        $this->assertSame(4, $doAdmin['comerciais']);

        // Para o vendedor 001 o cliente 800 é inativo; a loja que comprou é de outro.
        $this->assertSame(2, $doVendedor['inativos']);
        $this->assertSame(1, $doAdmin['inativos']);
        $this->assertSame(2, $doAdmin['ativos']);
    }

    public function test_filtro_de_municipio_lista_quem_tem_endereco_la_e_mostra_esse_endereco(): void
    {
        $props = $this->props($this->vendedor, ['municipio' => self::CAMPINAS]);
        $linhas = collect($props['clientes']['data'])->keyBy('codCliente');

        // `pluck`, não `keys()`: chave de array que parece número vira int no PHP.
        $this->assertSame(['100', '200', '800'], $linhas->pluck('codCliente')->sort()->values()->all());

        // A âncora do 100 é a loja 0001, na capital. A linha tem que dizer Campinas.
        $this->assertSame('CAMPINAS', $linhas['100']['municipio']);
        $this->assertSame('0001', $linhas['100']['loja'], 'as ações da linha continuam na âncora');
        $this->assertSame(1, $linhas['100']['outrasCidades']);
        $this->assertSame(0, $linhas['200']['outrasCidades']);

        $this->assertSame(['cod' => self::CAMPINAS, 'nome' => 'CAMPINAS/SP'], $props['filtros']['municipio']);
    }

    public function test_sem_filtro_de_lugar_a_linha_mostra_o_endereco_da_ancora(): void
    {
        $linhas = collect($this->props($this->vendedor)['clientes']['data'])->keyBy('codCliente');

        $this->assertSame('SAO PAULO', $linhas['100']['municipio']);
        $this->assertSame(1, $linhas['100']['outrasCidades']);
    }

    public function test_filtro_de_estado_tambem_mostra_o_endereco_que_casou(): void
    {
        // Cliente com a âncora em SP e uma loja no RJ: filtrando RJ, a linha diz RJ.
        $this->cliente('900', '0001', '001', null, 'SP', 'SAO PAULO', self::SAO_PAULO);
        $this->cliente('900', '0002', '001', null, 'RJ', 'RIO DE JANEIRO', self::RIO);

        $linhas = collect($this->props($this->vendedor, ['estado' => 'RJ'])['clientes']['data'])->keyBy('codCliente');

        $this->assertSame('RJ', $linhas['900']['estado']);
        $this->assertSame('RIO DE JANEIRO', $linhas['900']['municipio']);
    }

    public function test_municipio_invalido_na_url_nao_filtra_nada(): void
    {
        $semFiltro = $this->props($this->vendedor)['clientes']['total'];

        foreach (['abc', '35095020000', "1' OR '1'='1"] as $lixo) {
            $props = $this->props($this->vendedor, ['municipio' => $lixo]);

            $this->assertSame($semFiltro, $props['clientes']['total']);
            $this->assertNull($props['filtros']['municipio']);
        }
    }
}
