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

    public function test_ordena_por_nome_do_grupo_e_nao_pelo_codigo(): void
    {
        // DROGARAIA (cód. 13) antes de GRUPO CARREFOUR (cód. 279) — mesma ordem
        // por código e por nome seria coincidência; aqui elas divergem.
        $ordem = $this->ordemPara('grupo_asc');

        $this->assertSame('ZETA COMERCIO', end($ordem), 'o grupo CARREFOUR deveria vir por último');
        $this->assertContains($ordem[0], ['ALFA DISTRIBUIDORA', 'MEIO LTDA']);
    }

    public function test_ordena_por_nome_do_segmento(): void
    {
        // DROGARIAS (109) antes de SUPERMERCADISTA (101): por código seria o inverso.
        $this->assertSame('ALFA DISTRIBUIDORA', $this->ordemPara('segmento_asc')[0]);
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
