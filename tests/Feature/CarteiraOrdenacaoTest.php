<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\GrupoCliente;
use App\Models\Segmento;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ordenação por clique no header da Carteira.
 *
 * O foco aqui é a whitelist: o campo de ordenação chega pela query string e vira
 * parte de um ORDER BY. Se um dia alguém trocar o `match` por interpolação direta,
 * estes testes é que vão pegar.
 */
class CarteiraOrdenacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        GrupoCliente::create(['codigo' => '279', 'nome' => 'GRUPO CARREFOUR']);
        GrupoCliente::create(['codigo' => '13', 'nome' => 'DROGARAIA']);

        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $vendedor->id, 'cod_vendedor' => '001']);

        // Zebrado de propósito: código e nome ordenam em sentidos diferentes
        // (279/CARREFOUR vs 13/DROGARAIA), então um teste de ordem por nome falha
        // se a implementação ordenar pelo código.
        Cliente::create([
            'cod_cliente' => '1', 'loja' => '01', 'razao_social' => 'ZETA COMERCIO',
            'cod_vendedor' => '001', 'cod_segmento' => '101', 'cod_grupo' => '279',
            'estado' => 'SP', 'data_ultima_compra' => '2026-01-10',
        ]);
        Cliente::create([
            'cod_cliente' => '2', 'loja' => '01', 'razao_social' => 'ALFA DISTRIBUIDORA',
            'cod_vendedor' => '001', 'cod_segmento' => '109', 'cod_grupo' => '13',
            'estado' => 'RJ', 'data_ultima_compra' => '2026-06-20',
        ]);
        Cliente::create([
            'cod_cliente' => '3', 'loja' => '01', 'razao_social' => 'MEIO LTDA',
            'cod_vendedor' => '001', 'cod_segmento' => '101', 'cod_grupo' => '13',
            'estado' => 'MG', 'data_ultima_compra' => null,
        ]);
    }

    /** @return list<string> razões sociais na ordem em que a página devolveu */
    private function ordemPara(string $ordenar): array
    {
        $resposta = $this->actingAs($this->admin)->get(route('carteira.index', ['ordenar' => $ordenar]));
        $resposta->assertOk();

        return collect($resposta->viewData('page')['props']['clientes']['data'])
            ->pluck('razaoSocial')
            ->all();
    }

    public function test_ordena_por_nome_nos_dois_sentidos(): void
    {
        $this->assertSame(
            ['ALFA DISTRIBUIDORA', 'MEIO LTDA', 'ZETA COMERCIO'],
            $this->ordemPara('nome_asc')
        );

        $this->assertSame(
            ['ZETA COMERCIO', 'MEIO LTDA', 'ALFA DISTRIBUIDORA'],
            $this->ordemPara('nome_desc')
        );
    }

    /**
     * Ordenar por grupo e por segmento foi REMOVIDO em 2026-08-29 (decisão do Tony).
     *
     * O nome dos dois mora em outra tabela, então ordenar exigia LEFT JOIN, e o join
     * forçava filesort: 596 ms e 603 ms medidos em produção, contra 106 ms da ordenação
     * padrão — fora do orçamento de 400 ms da Regra de ouro nº 9.
     *
     * Estes testes ficam para proteger a decisão: se alguém devolver os campos à
     * whitelist de ORDENACOES, a suíte acusa.
     *
     * ⚠️ Os testes anteriores, que afirmavam ordenar por grupo/segmento, passavam por
     * COINCIDÊNCIA: com três clientes chamados ALFA, MEIO e ZETA, a ordem por nome do
     * grupo era a mesma que por razão social, então as asserções eram verdadeiras nos
     * dois casos. Eles continuaram verdes depois da remoção da feature — não protegiam
     * nada. Por isso agora a asserção é sobre a ordem COMPLETA, não sobre as pontas.
     */
    public function test_grupo_nao_e_campo_ordenavel_e_cai_no_nome(): void
    {
        $this->assertSame(
            $this->ordemPara('nome_asc'),
            $this->ordemPara('grupo_asc'),
            'grupo saiu da whitelist: deve cair no padrão (nome), não ordenar por grupo'
        );
    }

    public function test_segmento_nao_e_campo_ordenavel_e_cai_no_nome(): void
    {
        $this->assertSame(
            $this->ordemPara('nome_asc'),
            $this->ordemPara('segmento_asc'),
            'segmento saiu da whitelist: deve cair no padrão (nome)'
        );
    }

    /**
     * O OFFSET do MySQL fica caro com a distância: 93 ms na página 1 e 2.462 ms na 3000,
     * medidos em produção com 91.293 clientes. Acima de 2 s a Regra nº 9 manda tornar
     * assíncrono — e era alcançável num clique, porque a paginação linka a última página.
     */
    public function test_pagina_muito_profunda_e_limitada_ao_teto(): void
    {
        config(['perf.max_paginas' => 2]);

        $resposta = $this->actingAs($this->admin)->get(route('carteira.index', ['page' => 9999]));
        $resposta->assertOk();

        $this->assertSame(
            2,
            $resposta->viewData('page')['props']['clientes']['current_page'],
            'a página pedida deveria ter sido limitada ao teto de perf.max_paginas'
        );
    }

    public function test_ultima_compra_desc_joga_quem_nunca_comprou_pro_fim(): void
    {
        $ordem = $this->ordemPara('ultima_compra_desc');

        $this->assertSame('ALFA DISTRIBUIDORA', $ordem[0]);
        $this->assertSame('MEIO LTDA', end($ordem), 'sem data de compra deveria terminar a lista');
    }

    public function test_status_asc_lista_do_mais_ativo_pro_mais_frio(): void
    {
        // Status é derivado da data, e "asc" no status significa compra mais recente
        // primeiro — o inverso da direção da coluna crua.
        $this->assertSame('ALFA DISTRIBUIDORA', $this->ordemPara('status_asc')[0]);
    }

    public function test_campo_desconhecido_cai_no_padrao_sem_quebrar(): void
    {
        $this->assertSame($this->ordemPara('nome_asc'), $this->ordemPara('coluna_que_nao_existe_asc'));
        $this->assertSame($this->ordemPara('nome_asc'), $this->ordemPara('sem_direcao'));
    }

    public function test_ordenacao_nao_aceita_sql_da_query_string(): void
    {
        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        // Se o campo fosse interpolado no ORDER BY, isto apareceria no SQL gerado.
        $this->ordemPara('razao_social; DROP TABLE clientes--_asc');
        $this->ordemPara('(SELECT 1)_desc');

        $this->assertNotEmpty($consultas);
        foreach ($consultas as $sql) {
            $this->assertStringNotContainsString('DROP TABLE', $sql);
            $this->assertStringNotContainsString('(SELECT 1)', $sql);
        }

        // E a tabela continua de pé.
        $this->assertSame(3, Cliente::count());
    }

    public function test_total_da_paginacao_nao_muda_com_o_join_de_ordenacao(): void
    {
        // O total é contado sem o join de ordenação (otimização em `index()`).
        // Este teste trava a premissa que a torna válida: o join é 1:1.
        $resposta = $this->actingAs($this->admin)->get(route('carteira.index', ['ordenar' => 'grupo_asc']));

        $this->assertSame(3, $resposta->viewData('page')['props']['clientes']['total']);
    }
}
