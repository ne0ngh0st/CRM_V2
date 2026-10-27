<?php

namespace Tests\Feature\Ia;

use App\Jobs\ResponderMensagemIaJob;
use App\Models\MetaMensal;
use Illuminate\Support\Facades\Queue;
use App\Models\User;
use App\Services\Escopo\ModoVisao;
use App\Services\Metas\MetaRankingResolver;
use Tests\Support\ClienteClaudeRoteirizado as R;

/**
 * As ferramentas do assistente respeitam o escopo de quem pergunta.
 *
 * É o teste que justifica o desenho inteiro: o modelo não lê o banco, as ferramentas
 * rodam como o usuário — então o chat não pode virar porta lateral para a carteira de
 * outro vendedor.
 */
class FerramentasEscopoTest extends IaTestCase
{
    private function consultarPedidoComo(User $user, string $numero = '992086'): array
    {
        $this->roteiro([R::ferramenta('consultar_pedido', ['numero' => $numero]), R::texto('ok')]);
        $this->perguntar($user, "status do pedido {$numero}")->assertOk();

        return $this->resultadoDaFerramenta();
    }

    public function test_vendedor_ve_o_proprio_pedido_com_etapa_e_atraso(): void
    {
        $cliente = $this->cliente();
        $this->pedido(['cliente_id' => $cliente->id]);

        $r = $this->consultarPedidoComo($this->usuario('vendedor', '000010'));

        $this->assertTrue($r['encontrado']);
        $pedido = $r['pedidos'][0];
        $this->assertSame('992086', $pedido['numero']);
        $this->assertSame('em aberto', $pedido['situacao']);
        $this->assertTrue($pedido['atrasado']);
        $this->assertSame('MERCADO BOM PRECO LTDA', $pedido['cliente']['razao_social']);
        $this->assertSame('000010', $pedido['vendedor']['codigo']);
        $this->assertSame('R$ 1.500,50', $pedido['valor_total']);
    }

    public function test_pedido_diz_o_nome_de_quem_vendeu(): void
    {
        $vendedora = $this->usuario('vendedor', '000010');
        $vendedora->forceFill(['display_name' => 'ADRIANA AGUERA'])->save();
        $this->pedido();

        $pedido = $this->consultarPedidoComo($vendedora)['pedidos'][0];

        $this->assertSame('000010', $pedido['vendedor']['codigo']);
        $this->assertSame('ADRIANA AGUERA', $pedido['vendedor']['nome']);
    }

    public function test_admin_acha_vendedor_pelo_nome_e_devolve_o_codigo(): void
    {
        $vendedora = $this->usuario('vendedor', '010244');
        $vendedora->forceFill(['display_name' => 'ADRIANA AGUERA'])->save();

        $this->roteiro([R::ferramenta('buscar_vendedor', ['nome' => 'adriana aguera']), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'qual código a Adriana tem')->assertOk();

        $pessoa = $this->resultadoDaFerramenta()['pessoas'][0];
        $this->assertSame('ADRIANA AGUERA', $pessoa['nome']);
        $this->assertSame('010244', $pessoa['codigo']);
        $this->assertSame('vendedor', $pessoa['perfil']);
    }

    public function test_vendedor_nao_descobre_o_codigo_de_colega(): void
    {
        $colega = $this->usuario('vendedor', '010244');
        $colega->forceFill(['display_name' => 'ADRIANA AGUERA'])->save();

        $this->roteiro([R::ferramenta('buscar_vendedor', ['nome' => 'Adriana']), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'qual o código da Adriana')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame([], $r['pessoas']);
        $this->assertStringNotContainsString('010244', json_encode($r));
    }

    public function test_supervisor_ve_a_equipe_e_nao_quem_esta_fora(): void
    {
        $daEquipe = $this->usuario('vendedor', '010244', '000100');
        $daEquipe->forceFill(['display_name' => 'ADRIANA AGUERA'])->save();
        $deFora = $this->usuario('vendedor', '010999', '000200');
        $deFora->forceFill(['display_name' => 'ADRIANA FORA'])->save();

        $this->roteiro([R::ferramenta('buscar_vendedor', ['nome' => 'Adriana']), R::texto('ok')]);
        $this->perguntar($this->usuario('supervisor', '000100'), 'quem é a Adriana')->assertOk();

        $codigos = array_column($this->resultadoDaFerramenta()['pessoas'], 'codigo');
        $this->assertSame(['010244'], $codigos);
    }

    public function test_pedido_de_outra_carteira_responde_igual_a_inexistente(): void
    {
        $this->pedido(['cod_vendedor' => '000020']);

        $alheio = $this->consultarPedidoComo($this->usuario('vendedor', '000010'));
        $inexistente = $this->consultarPedidoComo($this->usuario('vendedor', '000010'), '555555');

        $this->assertFalse($alheio['encontrado']);
        $this->assertFalse($inexistente['encontrado']);
        // Mesma frase (só o número muda): dizer "existe, mas não é seu" já vazaria.
        $this->assertSame(
            str_replace('992086', 'X', $alheio['mensagem']),
            str_replace('555555', 'X', $inexistente['mensagem']),
        );
    }

    public function test_admin_ve_pedido_de_qualquer_carteira(): void
    {
        $this->pedido(['cod_vendedor' => '000020']);

        $this->assertTrue($this->consultarPedidoComo($this->usuario('admin'))['encontrado']);
    }

    public function test_supervisor_em_minha_carteira_nao_ve_pedido_da_equipe_dentro_do_job(): void
    {
        $supervisor = $this->usuario('supervisor', '000100');
        $this->usuario('vendedor', '000010', '000100');
        $this->pedido(['cod_vendedor' => '000010']);

        // Modo Equipe: vê o pedido do vendedor da equipe.
        $this->assertTrue($this->consultarPedidoComo($supervisor)['encontrado']);

        // Modo "Minha carteira": o job roda sem sessão e tem que restaurar o modo de
        // quem perguntou — senão o supervisor receberia a equipe inteira.
        //
        // ⚠️ O job roda À MÃO, depois de esvaziar a sessão. Com a fila `sync` ele rodaria
        // dentro da própria requisição, enxergando a sessão dela, e o teste passaria
        // mesmo sem a restauração — foi exatamente o que a verificação por mutação
        // mostrou (o mesmo caso da exportação, 2026-09-09).
        Queue::fake();
        $this->actingAs($supervisor)
            ->withSession([ModoVisao::CHAVE_SESSAO => ModoVisao::PESSOAL])
            ->postJson(route('ia.enviar'), ['texto' => 'pedido 992086'])
            ->assertOk();

        $job = null;
        Queue::assertPushed(ResponderMensagemIaJob::class, function ($j) use (&$job) {
            $job = $j;

            return $j->modoVisao === ModoVisao::PESSOAL;
        });

        session()->flush();
        $this->app->forgetInstance(ModoVisao::class);
        $this->roteiro([R::ferramenta('consultar_pedido', ['numero' => '992086']), R::texto('ok')]);
        $job->handle();

        $this->assertFalse($this->resultadoDaFerramenta()['encontrado']);
    }

    public function test_buscar_cliente_so_devolve_a_carteira(): void
    {
        $this->cliente(['razao_social' => 'MERCADO DO JOAO', 'cod_cliente' => '000001', 'cod_vendedor' => '000010']);
        $this->cliente(['razao_social' => 'MERCADO DO PEDRO', 'cod_cliente' => '000002', 'cod_vendedor' => '000020', 'cnpj' => '22.333.444/0001-81']);

        $this->roteiro([R::ferramenta('buscar_cliente', ['termo' => 'MERCADO']), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'acha o mercado')->assertOk();

        $nomes = array_column($this->resultadoDaFerramenta()['clientes'], 'razao_social');
        $this->assertSame(['MERCADO DO JOAO'], $nomes);
    }

    public function test_resumo_cliente_nega_cliente_de_outra_carteira(): void
    {
        $alheio = $this->cliente(['cod_vendedor' => '000020']);

        $this->roteiro([R::ferramenta('resumo_cliente', ['cliente_id' => $alheio->id]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'como está esse cliente')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertFalse($r['encontrado']);
        $this->assertArrayNotHasKey('razao_social', $r);
    }

    public function test_resumo_cliente_usa_a_compra_mais_recente_entre_as_filiais(): void
    {
        // Mesmo critério da Carteira: a filial parada não deixa o cliente inativo.
        $velha = $this->cliente(['loja' => '01', 'data_ultima_compra' => today()->subYears(2)]);
        $this->cliente(['loja' => '02', 'cnpj' => '11.222.333/0002-62', 'data_ultima_compra' => today()->subDays(10)]);

        $this->roteiro([R::ferramenta('resumo_cliente', ['cliente_id' => $velha->id]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'por que está inativo')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame('ativo', $r['status_carteira']);
        $this->assertSame(10, $r['dias_sem_comprar']);
    }

    public function test_quem_cuida_nao_devolve_status_nem_valores(): void
    {
        $this->cliente(['cod_vendedor' => '000020', 'data_ultima_compra' => today(), 'telefone' => '1133334444']);

        $this->roteiro([R::ferramenta('quem_cuida_do_cliente', ['termo' => 'MERCADO']), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'de quem é o mercado bom preco')->assertOk();

        $resultado = $this->resultadoDaFerramenta()['resultados'][0];
        $this->assertSame('MERCADO BOM PRECO LTDA', $resultado['razaoSocial']);
        foreach (['status', 'data_ultima_compra', 'telefone', 'email', 'valor'] as $proibido) {
            $this->assertArrayNotHasKey($proibido, $resultado);
        }
    }

    public function test_minha_meta_bate_com_o_resolver_das_telas(): void
    {
        $user = $this->usuario('vendedor', '000010');
        MetaMensal::create(['cod_vendedor' => '000010', 'ano' => now()->year, 'mes' => now()->month, 'tipo' => 'venda', 'valor_meta' => 4000]);
        $this->pedido(['data_pedido' => now()->startOfMonth(), 'valor_total' => 1000]);

        $this->roteiro([R::ferramenta('minha_meta', ['ano' => null, 'mes' => null]), R::texto('ok')]);
        $this->perguntar($user, 'quanto falta pra minha meta')->assertOk();

        $esperado = app(MetaRankingResolver::class)->metaVsRealizado(['000010'], now()->year, now()->month, now()->month, 'venda');
        $r = $this->resultadoDaFerramenta();

        $this->assertSame('R$ 4.000,00', $r['venda']['meta']);
        $this->assertSame('R$ '.number_format($esperado['realizado'], 2, ',', '.'), $r['venda']['realizado']);
        $this->assertSame('carteira do código 000010', $r['escopo']);
    }

    public function test_ferramenta_desconhecida_vira_erro_e_nao_executa_nada(): void
    {
        $this->roteiro([R::ferramenta('apagar_cliente', ['id' => 1]), R::texto('não consegui')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'apaga o cliente')->assertOk();

        $ultima = end($this->claude->requisicoes[1]['messages']);
        $this->assertTrue($ultima['content'][0]['is_error']);
    }
}
