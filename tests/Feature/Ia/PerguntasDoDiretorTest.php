<?php

namespace Tests\Feature\Ia;

use App\Models\Lead;
use App\Models\Orcamento;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClienteClaudeRoteirizado as R;

/**
 * As perguntas de um diretor (faturamento do cliente, orçamento, meta, pedido em
 * risco, lead) respondem com o mesmo corte das telas.
 */
class PerguntasDoDiretorTest extends IaTestCase
{
    public function test_faturamento_do_cliente_so_sai_para_quem_ve_todas_as_filiais(): void
    {
        $filial = $this->cliente();
        $this->cliente([
            'loja' => '02',
            'cnpj' => '22.333.444/0001-81',
            'cod_vendedor' => '000020',
        ]);
        DB::table('faturamento_cliente_mensal')->insert([
            'cod_cliente' => '000123',
            'mes' => now()->startOfMonth()->toDateString(),
            'valor_total' => 1000,
            'notas' => 1,
            'calculado_em' => now(),
        ]);

        $this->roteiro([R::ferramenta('numeros_do_cliente', ['cliente_id' => $filial->id]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'quanto faturou')->assertOk();

        $parcial = $this->resultadoDaFerramenta();
        $this->assertNull($parcial['faturamento']);
        $this->assertStringNotContainsString('1.000,00', json_encode($parcial));

        $this->roteiro([R::ferramenta('numeros_do_cliente', ['cliente_id' => $filial->id]), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'quanto faturou')->assertOk();

        $this->assertSame('R$ 1.000,00', $this->resultadoDaFerramenta()['faturamento']['mes']);
        $this->assertSame('R$ 1.000,00', $this->resultadoDaFerramenta()['faturamento']['historico']);
    }

    public function test_vendedor_dono_unico_ve_o_faturamento_e_nao_o_pedido_alheio(): void
    {
        $filial = $this->cliente();
        $this->pedido(['cliente_id' => $filial->id, 'valor_total' => 200, 'cod_vendedor' => '000010']);
        $alheia = $this->cliente([
            'cod_cliente' => '000999',
            'loja' => '01',
            'cnpj' => '22.333.444/0001-81',
            'cod_vendedor' => '000020',
        ]);
        $this->pedido(['cliente_id' => $alheia->id, 'numero_pedido' => '800000', 'valor_total' => 9000, 'cod_vendedor' => '000020']);
        DB::table('faturamento_cliente_mensal')->insert([
            'cod_cliente' => '000123',
            'mes' => now()->startOfMonth()->toDateString(),
            'valor_total' => 1000,
            'notas' => 1,
            'calculado_em' => now(),
        ]);

        $this->roteiro([R::ferramenta('numeros_do_cliente', ['cliente_id' => $filial->id]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'quanto faturou')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame('R$ 1.000,00', $r['faturamento']['historico']);
        $this->assertSame('R$ 200,00', $r['pedidos_em_aberto']['valor']);
        $this->assertStringNotContainsString('9.000,00', json_encode($r));
    }

    public function test_orcamento_de_outro_vendedor_nao_aparece_e_o_admin_ve_quem_fez(): void
    {
        $dona = $this->usuario('vendedor', '010244');
        $dona->forceFill(['display_name' => 'ADRIANA AGUERA'])->save();
        $orcamento = Orcamento::create([
            'user_id' => $dona->id,
            'cliente_nome' => 'CONCESSIONARIA DO AEROPORTO',
            'valor_total' => 1704.50,
            'desconto_pct_max' => 12.5,
            'tipo_produto_servico' => 'servico',
            'tipo_frete' => 'CIF',
            'tipo_venda' => 'consumo',
            'condicao_pagamento_codigo' => '028',
            'status_gestor' => 'pendente',
            'nivel_aprovacao' => 'diretor',
        ]);

        $this->roteiro([R::ferramenta('consultar_orcamento', ['numero' => $orcamento->id, 'cliente_id' => null, 'etapa' => null]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'quem fez o orçamento')->assertOk();
        $escondido = $this->resultadoDaFerramenta();
        $this->assertFalse($escondido['encontrado']);
        $this->assertStringNotContainsString('ADRIANA', json_encode($escondido));

        $this->roteiro([R::ferramenta('consultar_orcamento', ['numero' => $orcamento->id, 'cliente_id' => null, 'etapa' => null]), R::texto('ok')]);
        $this->perguntar($this->usuario('admin'), 'quem fez o orçamento')->assertOk();
        $visto = $this->resultadoDaFerramenta()['orcamentos'][0];
        $this->assertSame('ADRIANA AGUERA', $visto['vendedor']['nome']);
        $this->assertSame('010244', $visto['vendedor']['codigo']);
        $this->assertSame('12,50%', $visto['desconto_maximo']);
        $this->assertSame('Aguardando margem', $visto['etapa']);
    }

    public function test_funil_de_orcamento_nao_conta_o_do_colega(): void
    {
        $meu = $this->usuario('vendedor', '000010');
        Orcamento::create([
            'user_id' => $meu->id,
            'cliente_nome' => 'MEU CLIENTE',
            'valor_total' => 100,
            'tipo_produto_servico' => 'servico',
            'tipo_frete' => 'CIF',
            'tipo_venda' => 'consumo',
            'condicao_pagamento_codigo' => '028',
            'status_gestor' => 'pendente',
            'nivel_aprovacao' => 'supervisor',
        ]);
        $outro = $this->usuario('vendedor', '000020');
        Orcamento::create([
            'user_id' => $outro->id,
            'cliente_nome' => 'CLIENTE DELA',
            'valor_total' => 9000,
            'tipo_produto_servico' => 'servico',
            'tipo_frete' => 'CIF',
            'tipo_venda' => 'consumo',
            'condicao_pagamento_codigo' => '028',
            'status_gestor' => 'pendente',
            'nivel_aprovacao' => 'diretor',
        ]);

        $this->roteiro([R::ferramenta('consultar_orcamento', ['numero' => null, 'cliente_id' => null, 'etapa' => null]), R::texto('ok')]);
        $this->perguntar($meu, 'como estão os orçamentos')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame(1, $r['funil']['aguardando_o_supervisor']);
        $this->assertSame(0, $r['funil']['aguardando_o_diretor']);
        $this->assertStringNotContainsString('9.000,00', json_encode($r));
        $this->assertStringNotContainsString('CLIENTE DELA', json_encode($r));
    }

    public function test_desempenho_do_vendedor_nao_traz_a_meta_do_colega(): void
    {
        $eu = $this->usuario('vendedor', '000010');
        $eu->forceFill(['display_name' => 'EU MESMO'])->save();
        $colega = $this->usuario('vendedor', '000020');
        $colega->forceFill(['display_name' => 'ADRIANA AGUERA'])->save();
        \App\Models\MetaMensal::create(['cod_vendedor' => '000020', 'ano' => now()->year, 'mes' => now()->month, 'tipo' => 'faturamento', 'valor_meta' => 9000]);

        $this->roteiro([R::ferramenta('desempenho_comercial', ['nome' => 'Adriana', 'ano' => null, 'mes' => null]), R::texto('ok')]);
        $this->perguntar($eu, 'como está a Adriana')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame([], $r['pessoas']);
        $this->assertStringNotContainsString('9.000,00', json_encode($r));
        $this->assertStringNotContainsString('000020', json_encode($r));
    }

    public function test_pedidos_em_atencao_nao_somam_a_carteira_alheia(): void
    {
        $this->pedido(['valor_total' => 200, 'cod_vendedor' => '000010']);
        $this->pedido(['numero_pedido' => '800000', 'valor_total' => 9000, 'cod_vendedor' => '000020']);

        $this->roteiro([R::ferramenta('pedidos_em_atencao', ['incluir_lista' => false]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'quanto está em aberto')->assertOk();

        $r = $this->resultadoDaFerramenta();
        $this->assertSame('R$ 200,00', $r['em_aberto']['valor']);
        $this->assertSame(1, $r['em_aberto']['quantidade']);
        $this->assertStringNotContainsString('9.000,00', json_encode($r));
    }

    public function test_funil_de_leads_ignora_o_lead_de_outra_carteira(): void
    {
        Lead::create([
            'origem' => 'manual', 'status' => 'ativo', 'etapa' => 'negociacao',
            'nome' => 'LEAD MEU', 'razao_social' => 'LEAD MEU', 'cod_vendedor' => '000010',
        ]);
        Lead::create([
            'origem' => 'manual', 'status' => 'ativo', 'etapa' => 'negociacao',
            'nome' => 'LEAD DELA', 'razao_social' => 'LEAD DELA', 'cod_vendedor' => '000020',
        ]);

        $this->roteiro([R::ferramenta('consultar_leads', ['termo' => null]), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'quantos leads')->assertOk();

        $funil = $this->resultadoDaFerramenta();
        $this->assertSame(1, $funil['em_jogo']);
        $this->assertStringNotContainsString('LEAD DELA', json_encode($funil));

        $this->roteiro([R::ferramenta('consultar_leads', ['termo' => 'LEAD DELA']), R::texto('ok')]);
        $this->perguntar($this->usuario('vendedor', '000010'), 'de quem é o lead dela')->assertOk();
        $busca = $this->resultadoDaFerramenta();
        $this->assertSame([], $busca['leads']);
        $this->assertStringNotContainsString('000020', json_encode($busca));
    }
}
