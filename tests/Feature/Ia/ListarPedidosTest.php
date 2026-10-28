<?php

namespace Tests\Feature\Ia;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClienteClaudeRoteirizado as R;

/**
 * As perguntas do diretor que o assistente não respondia (2026-10-28): lista de pedidos
 * por vendedor, por cliente, por faixa de atraso e na ordem pedida; cliente achado pelas
 * palavras ("C6 Bank" → "BANCO C6"); e a frequência de compra mês a mês.
 */
class ListarPedidosTest extends IaTestCase
{
    /** @param array<string, mixed> $filtros */
    private function listar(User $user, array $filtros): array
    {
        $entrada = [
            'situacao' => 'em_aberto',
            'vendedor_codigo' => null,
            'cliente_id' => null,
            'data_pedido_de' => null,
            'data_pedido_ate' => null,
            'atraso_min_dias' => null,
            'atraso_max_dias' => null,
            'ordem' => 'mais_recentes',
            'por_mes' => false,
            ...$filtros,
        ];
        $this->roteiro([R::ferramenta('listar_pedidos', $entrada), R::texto('ok')]);
        $this->perguntar($user, 'liste os pedidos')->assertOk();

        return $this->resultadoDaFerramenta();
    }

    /** @return list<string> */
    private function numeros(array $r): array
    {
        return array_column($r['pedidos'], 'numero');
    }

    public function test_atrasados_ate_30_dias_do_menor_para_o_maior_atraso(): void
    {
        $this->pedido(['numero_pedido' => 'P5', 'data_previsao_faturamento' => today()->subDays(5)]);
        $this->pedido(['numero_pedido' => 'P2', 'data_previsao_faturamento' => today()->subDays(2)]);
        $this->pedido(['numero_pedido' => 'P90', 'data_previsao_faturamento' => today()->subDays(90)]);
        $this->pedido(['numero_pedido' => 'FUT', 'data_previsao_faturamento' => today()->addDays(3)]);

        $r = $this->listar($this->usuario('admin'), [
            'situacao' => 'atrasados', 'atraso_max_dias' => 30, 'ordem' => 'menor_atraso',
        ]);

        $this->assertSame(['P2', 'P5'], $this->numeros($r));
        $this->assertSame(2, $r['total']['quantidade']);
        $this->assertSame(2, $r['pedidos'][0]['dias_de_atraso']);

        $r = $this->listar($this->usuario('admin'), ['situacao' => 'atrasados', 'ordem' => 'maior_atraso']);
        $this->assertSame(['P90', 'P5', 'P2'], $this->numeros($r));
    }

    public function test_pedidos_do_mes_de_um_vendedor_e_o_escopo_vale(): void
    {
        $this->pedido(['numero_pedido' => 'DOMES', 'cod_vendedor' => '010395', 'data_pedido' => today()->startOfMonth(), 'data_faturamento' => today()]);
        $this->pedido(['numero_pedido' => 'ANTIGO', 'cod_vendedor' => '010395', 'data_pedido' => today()->subMonths(2)]);
        $this->pedido(['numero_pedido' => 'OUTRO', 'cod_vendedor' => '000020', 'data_pedido' => today()]);

        $r = $this->listar($this->usuario('diretor'), [
            'situacao' => 'todos', 'vendedor_codigo' => '10395',
            'data_pedido_de' => today()->startOfMonth()->toDateString(),
        ]);
        $this->assertSame(['DOMES'], $this->numeros($r));

        // O vendedor 000020 não alcança o 010395: nem a lista, nem o total.
        $r = $this->listar($this->usuario('vendedor', '000020'), ['situacao' => 'todos', 'vendedor_codigo' => '010395']);
        $this->assertFalse($r['encontrado']);
        $this->assertArrayNotHasKey('pedidos', $r);
    }

    public function test_cliente_vale_para_todas_as_filiais_e_conta_por_mes(): void
    {
        $matriz = $this->cliente(['cod_cliente' => '212921', 'loja' => '0001', 'razao_social' => 'BANCO C6 S.A']);
        $filial = $this->cliente(['cod_cliente' => '212921', 'loja' => 'E001', 'cnpj' => '22.333.444/0001-81', 'razao_social' => 'BANCO C6 S A']);
        $alheio = $this->cliente(['cod_cliente' => '000777', 'cnpj' => '33.444.555/0001-81']);
        $this->pedido(['numero_pedido' => 'M1', 'cliente_id' => $matriz->id, 'data_pedido' => '2026-08-10']);
        $this->pedido(['numero_pedido' => 'F1', 'cliente_id' => $filial->id, 'data_pedido' => '2026-08-20']);
        $this->pedido(['numero_pedido' => 'F2', 'cliente_id' => $filial->id, 'data_pedido' => '2026-10-02']);
        $this->pedido(['numero_pedido' => 'X1', 'cliente_id' => $alheio->id, 'data_pedido' => '2026-10-02']);

        $r = $this->listar($this->usuario('admin'), [
            'situacao' => 'todos', 'cliente_id' => $matriz->id, 'por_mes' => true,
        ]);

        $this->assertSame(['F2', 'F1', 'M1'], $this->numeros($r));
        $this->assertSame([['mes' => '2026-08', 'pedidos' => 2], ['mes' => '2026-10', 'pedidos' => 1]],
            array_map(fn ($m) => ['mes' => $m['mes'], 'pedidos' => $m['pedidos']], $r['por_mes']));
    }

    public function test_data_invalida_e_recusada(): void
    {
        $r = $this->listar($this->usuario('admin'), ['data_pedido_de' => '01/10/2026']);

        $this->assertSame('Data inválida. Use AAAA-MM-DD.', $r['erro']);
    }

    public function test_c6_bank_acha_banco_c6_pelas_palavras(): void
    {
        $this->cliente(['cod_cliente' => '212921', 'loja' => '0001', 'razao_social' => 'BANCO C6 S.A', 'nome_fantasia' => 'BANCO C6']);
        $this->cliente(['cod_cliente' => '004640', 'cnpj' => '22.333.444/0001-81', 'razao_social' => 'IMIFARMA', 'nome_fantasia' => 'LJ7384-REC61']);
        $this->cliente(['cod_cliente' => '041893', 'cnpj' => '33.444.555/0001-81', 'razao_social' => '5S SERVICOS FINANCEIROS LTDA', 'nome_fantasia' => '5S BANK']);
        $this->cliente(['cod_cliente' => '010439', 'cnpj' => '44.555.666/0001-81', 'razao_social' => 'BANK LOG DO BRASIL LTDA']);

        $this->roteiro([R::ferramenta('buscar_cliente', ['termo' => 'C6 Bank']), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'pedidos da c6 bank')->assertOk();
        $r = $this->resultadoDaFerramenta();

        // O banco vem primeiro: "C6" é a palavra rara. "REC61" não casa (palavra inteira).
        $this->assertSame('BANCO C6 S.A', $r['clientes'][0]['razao_social']);
        $this->assertNotContains('IMIFARMA', array_column($r['clientes'], 'razao_social'));
        $this->assertStringContainsString('aproximado', $r['mensagem'] ?? '');
        // Cliente sem nome fantasia também entra (NULL LIKE não pode zerar a pontuação).
        $this->assertContains('BANK LOG DO BRASIL LTDA', array_column($r['clientes'], 'razao_social'));
    }

    public function test_numeros_do_cliente_traz_mes_a_mes_e_diz_que_e_o_codigo_inteiro(): void
    {
        $cliente = $this->cliente();
        $this->cliente(['loja' => '02', 'cnpj' => '22.333.444/0001-81']);
        DB::table('faturamento_cliente_mensal')->insert([
            'cod_cliente' => '000123',
            'mes' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'valor_total' => 700,
            'notas' => 3,
            'calculado_em' => now(),
        ]);

        $this->roteiro([R::ferramenta('numeros_do_cliente', ['cliente_id' => $cliente->id]), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'compra todo mês?')->assertOk();
        $r = $this->resultadoDaFerramenta();

        $meses = $r['faturamento']['ultimos_13_meses'];
        $this->assertCount(13, $meses);
        $this->assertSame(3, $meses[10]['notas']);
        $this->assertSame(0, $meses[11]['notas']);
        $this->assertTrue($meses[12]['parcial']);
        $this->assertSame(2, $r['filiais_do_codigo']);
        $this->assertStringContainsString('000123 inteiro (2 filiais)', $r['definicao']);
    }
}
