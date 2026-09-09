<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Pedidos\StatusPedidoResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O status do pedido, do arquivo do TOTVS até a tela.
 *
 * O teste unitário do resolver trava a TRADUÇÃO; aqui se trava a LIGAÇÃO — que é onde os
 * defeitos deste projeto costumam morar. O caso da bobina em 2026-08-11 é o precedente:
 * o resolver estava certo para o que recebia, e era o controller que passava o argumento
 * errado.
 *
 * ⚠️ O teste do IMPORT escreve um CSV de verdade e roda o comando de verdade. Sem isso,
 * "o resolver classifica" e "o import grava o que o resolver classificou" seriam duas
 * afirmações, e só a primeira estaria provada — exatamente o buraco por onde a coluna
 * `HISTORICO` passou meses sendo lida por ninguém.
 */
class PedidoStatusTotvsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $cliente;

    private string $diretorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => '010585']);

        $this->cliente = Cliente::create([
            'cod_cliente' => '042932',
            'loja' => 'E004',
            'razao_social' => 'TRIBUNAL DE JUSTICA DO ESTADO DE RONDONIA',
            'cnpj' => '10.466.386/0001-85',
            'cod_vendedor' => '010585',
        ]);

        $this->diretorio = sys_get_temp_dir().'/totvs-teste-'.uniqid();
        mkdir($this->diretorio.'/CSV', 0777, true);
        config(['totvs.diretorio' => $this->diretorio]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diretorio.'/CSV/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->diretorio.'/CSV');
        @rmdir($this->diretorio);

        parent::tearDown();
    }

    /**
     * Monta o relatório 200 com o cabeçalho real (34 colunas, na ordem do arquivo).
     *
     * @param  list<array{0: string, 1: string}>  $pedidos  [numero, historico]
     */
    private function escreverRelatorio(array $pedidos): void
    {
        $colunas = [
            'FILIAL', 'COD_CLI', 'LOJA_CLI', 'GRP_CLIENT', 'CNPJ', 'CLIENTE', 'FANTASIA',
            'MUNICIPIO', 'ESTADO', 'COD_REPRES', 'REPRES', 'DATA_PED', 'N_PEDIDO', 'DIGITACAO',
            'CARGA', 'DT_ENTREGA', 'DT_PREVFAT', 'DT_PCP', 'ATRASO', 'TMP_VIAGEM', 'COND_PAGTO',
            'COD_PROD', 'DESC_PROD', 'QTD_VENDA', 'QTD_LIBER', 'VLR_PEDIDO', 'EMAIL', 'CONTATO',
            'DDD', 'TELEFONE', 'DATA_HIST', 'HORA_HIST', 'USUARIO', 'HISTORICO',
        ];

        // A primeira linha é o título do relatório, sozinho — o leitor detecta por forma.
        $linhas = ['200 - PEDIDOS EM ABERTO COM STATUS.RLT'.str_repeat(';', 33)];
        $linhas[] = implode(';', $colunas);

        foreach ($pedidos as [$numero, $historico]) {
            $valores = array_fill_keys($colunas, '');
            $valores['FILIAL'] = '05';
            $valores['COD_CLI'] = '042932';
            $valores['LOJA_CLI'] = 'E004';
            $valores['COD_REPRES'] = '010585';
            $valores['DATA_PED'] = '14/08/2026';
            $valores['N_PEDIDO'] = $numero;
            $valores['DT_PREVFAT'] = '15/09/2026';
            $valores['COD_PROD'] = 'V6045';
            $valores['DESC_PROD'] = 'PAPEL A4 75G';
            $valores['QTD_VENDA'] = '10';
            $valores['QTD_LIBER'] = '10';
            $valores['VLR_PEDIDO'] = '1.000,00';
            $valores['DATA_HIST'] = '08/09/2026';
            $valores['HORA_HIST'] = '07:10:21';
            // Aspas porque o histórico do TOTVS às vezes tem `;` interno — é o caso que
            // um split ingênuo por `;` erra, e o `fgetcsv` do leitor acerta.
            $valores['HISTORICO'] = '"'.$historico.'"';

            $linhas[] = implode(';', $valores);
        }

        file_put_contents($this->diretorio.'/CSV/Pedidos abertos - SQL.csv', implode("\n", $linhas)."\n");
    }

    public function test_import_grava_a_etapa_e_o_texto_cru_do_totvs(): void
    {
        $this->escreverRelatorio([
            ['990001', 'PEDIDO 990001 COM BLOQUEIO DE ESTOQUE'],
            ['990002', 'PEDIDO 990002 INCLUIDO NA CARGA 190050'],
            ['990003', 'ENVIO DO PEDIDO PARA O WMS - ORDEM DE SEPARACAO 876758'],
        ]);

        $this->artisan('totvs:import-pedidos-abertos')->assertSuccessful();

        $porNumero = Pedido::query()->pluck('status', 'numero_pedido');

        $this->assertSame(StatusPedidoResolver::BLOQUEIO_ESTOQUE, $porNumero['990001']);
        $this->assertSame(StatusPedidoResolver::EM_CARGA, $porNumero['990002']);
        $this->assertSame(StatusPedidoResolver::SEPARACAO, $porNumero['990003']);

        $pedido = Pedido::where('numero_pedido', '990001')->firstOrFail();

        // O texto cru sobrevive à classificação, com a data do movimento.
        $this->assertSame('PEDIDO 990001 COM BLOQUEIO DE ESTOQUE', $pedido->historico_totvs);
        $this->assertSame('2026-09-08 07:10:21', $pedido->historico_em->format('Y-m-d H:i:s'));
    }

    /**
     * ⚠️ Movimento desconhecido NÃO pode impedir a importação nem virar etapa chutada: o
     * pedido entra, sem classificação, com o texto guardado. É o comportamento que torna
     * seguro classificar dado que vem de fora.
     */
    public function test_movimento_desconhecido_importa_sem_classificacao_e_avisa(): void
    {
        $this->escreverRelatorio([
            ['990004', 'NOTA FISCAL CANCELADA - NF 1  /001123689'],
            ['990005', 'PEDIDO 990005 COM BLOQUEIO DE ESTOQUE'],
        ]);

        $this->artisan('totvs:import-pedidos-abertos')
            ->expectsOutputToContain('Movimentos que o CRM não reconheceu: 1')
            ->assertSuccessful();

        $pedido = Pedido::where('numero_pedido', '990004')->firstOrFail();

        $this->assertSame(StatusPedidoResolver::DESCONHECIDO, $pedido->status);
        $this->assertSame('NOTA FISCAL CANCELADA - NF 1  /001123689', $pedido->historico_totvs);
        $this->assertSame(2, Pedido::count());
    }

    public function test_a_tela_recebe_o_rotulo_pronto_e_o_movimento(): void
    {
        $this->pedido('990006', StatusPedidoResolver::BLOQUEIO_ESTOQUE, 'PEDIDO 990006 COM BLOQUEIO DE ESTOQUE');

        $this->actingAs($this->admin)
            ->get('/pedidos-abertos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pedidos.data.0.status', StatusPedidoResolver::BLOQUEIO_ESTOQUE)
                ->where('pedidos.data.0.statusRotulo', 'Bloqueio de estoque')
                ->where('pedidos.data.0.movimento', 'PEDIDO 990006 COM BLOQUEIO DE ESTOQUE')
                ->where('pedidos.data.0.movimentoEm', '08/09/2026 07:10')
            );
    }

    /**
     * ⚠️ O pedido do Tony em uma frase: "se não tivermos nada a mostrar talvez seja
     * melhor não mostrar nada". `statusRotulo` nulo é o que faz a pill sumir da tela em
     * vez de exibir um rótulo genérico em 100% das linhas.
     */
    public function test_sem_etapa_reconhecida_a_tela_nao_recebe_rotulo(): void
    {
        $this->pedido('990007', StatusPedidoResolver::DESCONHECIDO, 'ELIMINACAO DE RESIDUOS - PEDIDO 990007 ITEM 01');

        $this->actingAs($this->admin)
            ->get('/pedidos-abertos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pedidos.data.0.statusRotulo', null)
                // Mas o texto do TOTVS continua chegando: nada se perde.
                ->where('pedidos.data.0.movimento', 'ELIMINACAO DE RESIDUOS - PEDIDO 990007 ITEM 01')
            );
    }

    /**
     * ⚠️ O defeito que originou esta rodada: o filtro oferecia 6 status e 5 devolviam
     * tela vazia. `faturado` não pode aparecer porque a query da tela filtra por
     * `data_faturamento IS NULL`.
     */
    public function test_filtro_so_oferece_status_que_existem_em_pedido_aberto(): void
    {
        $this->pedido('990008', StatusPedidoResolver::EM_CARGA, 'PEDIDO 990008 INCLUIDO NA CARGA 1');

        $this->actingAs($this->admin)
            ->get('/pedidos-abertos')
            ->assertOk()
            ->assertInertia(function ($page) {
                $opcoes = collect($page->toArray()['props']['opcoes']['status']);

                $this->assertNotContains(StatusPedidoResolver::FATURADO, $opcoes->pluck('valor')->all());
                $this->assertContains(StatusPedidoResolver::EM_CARGA, $opcoes->pluck('valor')->all());
                // Cada opção chega com o rótulo pronto do servidor.
                $this->assertContains('Bloqueio de estoque', $opcoes->pluck('rotulo')->all());
            });
    }

    public function test_filtro_por_etapa_recorta_a_lista(): void
    {
        $this->pedido('990009', StatusPedidoResolver::BLOQUEIO_ESTOQUE, 'a');
        $this->pedido('990010', StatusPedidoResolver::EM_CARGA, 'b');

        $this->actingAs($this->admin)
            ->get('/pedidos-abertos?status='.StatusPedidoResolver::BLOQUEIO_ESTOQUE)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pedidos.total', 1)
                ->where('pedidos.data.0.numeroPedido', '990009')
            );
    }

    /**
     * ⚠️ Um link salvo com um status que não existe mais (`?status=wms`, por exemplo)
     * não pode esvaziar a tela como se a carteira não tivesse pedido nenhum — parece
     * defeito da busca, e ninguém desconfia da URL.
     */
    public function test_status_desconhecido_na_url_e_ignorado_em_vez_de_zerar_a_tela(): void
    {
        $this->pedido('990011', StatusPedidoResolver::EM_CARGA, 'a');
        $this->pedido('990012', StatusPedidoResolver::BLOQUEIO_ESTOQUE, 'b');

        $this->actingAs($this->admin)
            ->get('/pedidos-abertos?status=wms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pedidos.total', 2));
    }

    /**
     * A ficha do cliente mostra os mesmos campos, pelo mesmo caminho.
     *
     * ⚠️ É a tela que já ficou para trás uma vez, exibindo a string crua do enum porque
     * tinha cópia própria do mapa de rótulos.
     */
    public function test_ficha_do_cliente_usa_o_mesmo_rotulo(): void
    {
        $this->pedido('990013', StatusPedidoResolver::REJEICAO_CREDITO, 'PEDIDO 990013 COM REJEICAO DE CREDITO - NF 1, VENCIDA');

        $this->actingAs($this->admin)
            ->get("/carteira/{$this->cliente->id}/detalhes")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pedidos.data.0.statusRotulo', 'Rejeição de crédito')
                ->where('pedidos.data.0.movimento', 'PEDIDO 990013 COM REJEICAO DE CREDITO - NF 1, VENCIDA')
            );
    }

    private function pedido(string $numero, string $status, string $historico): Pedido
    {
        return Pedido::create([
            'numero_pedido' => $numero,
            'cliente_id' => $this->cliente->id,
            'cod_vendedor' => '010585',
            'data_pedido' => '2026-08-14',
            'data_previsao_faturamento' => '2026-09-15',
            'status' => $status,
            'historico_totvs' => $historico,
            'historico_em' => '2026-09-08 07:10:21',
            'valor_total' => 1000,
        ]);
    }
}
