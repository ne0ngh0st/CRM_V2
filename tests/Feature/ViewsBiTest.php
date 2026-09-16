<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PowerBi\SchemaBi;
use App\Services\PowerBi\ViewsBi;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * As views `vw_bi_*` que substituem as do `autopel01` para o Power BI.
 *
 * O contrato mais importante é o de colunas: o modelo (`BI_RADES`) casa medidas e
 * visuais pelo NOME, então uma coluna renomeada ou faltando quebra o relatório só no
 * primeiro refresh. As listas abaixo foram copiadas do export TMDL do modelo
 * (`sourceColumn` de cada tabela) em 2026-09-16 — são o contrato, e é de propósito que
 * não sejam lidas de `ViewsBi`.
 */
class ViewsBiTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRATO = [
        'vw_bi_seg_acesso' => ['email', 'cod_vendedor'],
        'vw_bi_dim_cliente' => ['cod_cliente', 'loja', 'cnpj', 'raiz_cnpj', 'razao_social', 'nome_fantasia', 'cod_vendedor', 'cod_segmento', 'estado', 'grupo_vendas', 'grupo_descricao', 'segmento_descricao', 'data_ultima_compra', 'status', 'municipio_origem', 'cod_municipio'],
        'vw_bi_fato_faturamento' => ['origem_tabela', 'filial', 'cod_cliente', 'loja', 'cnpj', 'raiz_cnpj', 'cod_produto', 'cod_vendedor', 'segmento', 'data_emissao', 'numero_pedido', 'quantidade', 'valor_unitario', 'valor_total', 'eh_devolucao', 'desc_familia', 'municipio_origem', 'cod_municipio'],
        'vw_bi_dim_geografia' => ['cod_municipio', 'cidade', 'cod_micro', 'microrregiao', 'cod_meso', 'mesorregiao', 'cod_uf', 'uf', 'cod_regiao', 'regiao'],
        'vw_bi_fato_indicadores_municipio' => ['cod_municipio', 'pea', 'ano_base_pea', 'populacao', 'ano_base_pop', 'pib_total', 'ano_base_pib', 'num_supermercados', 'supermercados_pessoal_ocupado', 'supermercados_massa_salarial', 'ano_base_supermercados', 'pib_per_capita', 'pea_percentual_populacao', 'atualizado_em'],
        'vw_bi_fato_leads' => ['origem', 'cnpj', 'raiz_cnpj', 'razao_social', 'nome_fantasia', 'uf', 'cidade', 'faturamento_bruto_2024', 'cod_vendedor', 'status', 'data_ultima_venda', 'valor_ultima_venda'],
        'vw_bi_fato_metas' => ['id', 'COD_VENDEDOR', 'ano', 'mes', 'tipo', 'valor_meta', 'data_criacao', 'data_atualizacao'],
        'vw_bi_fato_orcamentos' => ['id_orcamento', 'cod_cliente', 'cnpj', 'cliente_nome', 'cliente_razao_social', 'cod_vendedor', 'tipo_produto_servico', 'valor_total', 'status', 'status_cliente', 'status_gestor', 'motivo_recusa', 'forma_pagamento', 'tipo_faturamento', 'origem_cliente', 'data_criacao', 'data_validade', 'data_aprovacao_cliente', 'data_aprovacao_gestor'],
        'vw_bi_fato_pedidos_emitidos' => ['PEDIDO', 'dt_emissao_date', 'CNPJ', 'ATIVIDADE', 'COD_VENDEDOR', 'REPRES', 'COD_PROD', 'DESC_PROD', 'UND', 'PESO_LIQ', 'PRC_VENDA', 'QTDA_VENDA', 'VLR_TOTAL', 'DT_FATURAMENTO', 'NUM_DOCTO', 'FILIAL', 'SUPERVISOR', 'CLIENTE', 'PREV_FAT'],
        'vw_bi_fato_pedidos_abertos' => ['filial', 'cod_cliente', 'loja', 'cnpj', 'cod_vendedor', 'cod_produto', 'numero_pedido', 'data_pedido', 'data_entrega', 'data_previsao_faturamento', 'data_pcp', 'atraso', 'condicao_pagamento', 'quantidade_venda', 'quantidade_liberada', 'valor_total', 'municipio_origem', 'cod_municipio'],
        'vw_bi_potencial_estado' => ['uf', 'estado', 'regiao', 'populacao', 'pib_milhoes', 'pib_per_capita', 'pct_pib_nacional', 'pct_populacao_nacional', 'num_pdvs', 'pct_pdvs_nacional', 'ipm', 'ranking_potencial', 'ano_base_pop', 'ano_base_pib', 'ano_base_pdvs', 'atualizado_em'],
        'vw_bi_dim_produto' => ['cod_produto', 'descricao', 'unidade', 'preco_venda', 'categoria'],
        'vw_bi_dim_vendedor' => ['cod_vendedor', 'nome', 'nome_exibicao', 'perfil', 'cod_supervisor', 'cod_gerente', 'equipe', 'ativo'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return list<object> */
    private function ler(string $view, string $ordem = '1'): array
    {
        return DB::select('SELECT * FROM '.SchemaBi::tabela($view)." ORDER BY {$ordem}");
    }

    private function usuario(string $papel, string $email, ?string $cod, array $perfil = [], bool $ativo = true, string $nome = 'FULANO'): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => $ativo, 'name' => $nome]);
        $user->assignRole($papel);

        if ($cod !== null) {
            DB::table('vendedor_perfis')->insert($perfil + ['user_id' => $user->id, 'cod_vendedor' => $cod]);
        }

        return $user;
    }

    private function cliente(array $dados): int
    {
        return DB::table('clientes')->insertGetId($dados + [
            'cod_cliente' => '206065',
            'loja' => '0001',
            'razao_social' => 'EMPORIO DOM LUIZ LTDA',
        ]);
    }

    // ─── Contrato ────────────────────────────────────────────────────────────────

    public function test_toda_view_do_contrato_existe_com_as_colunas_do_modelo(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::CONTRATO), ViewsBi::VIEWS);

        foreach (self::CONTRATO as $view => $colunas) {
            $reais = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', SchemaBi::nome())
                ->where('TABLE_NAME', $view)
                ->pluck('COLUMN_NAME')
                ->all();

            $this->assertEqualsCanonicalizing($colunas, $reais, "colunas de {$view}");
        }
    }

    /** INVOKER é o que tira a view da dependência do DEFINER — o defeito do legado. */
    public function test_todas_as_views_rodam_com_a_permissao_de_quem_consulta(): void
    {
        $tipos = DB::table('information_schema.VIEWS')
            ->where('TABLE_SCHEMA', SchemaBi::nome())
            ->pluck('SECURITY_TYPE', 'TABLE_NAME')
            ->all();

        $this->assertCount(count(ViewsBi::VIEWS), $tipos);
        $this->assertSame(['INVOKER'], array_values(array_unique($tipos)));
    }

    /** As views são do banco de teste — a suíte nunca pode ler o palma_v2. */
    public function test_as_views_da_suite_leem_o_banco_de_teste(): void
    {
        $definicao = DB::table('information_schema.VIEWS')
            ->where('TABLE_SCHEMA', SchemaBi::nome())
            ->where('TABLE_NAME', 'vw_bi_dim_cliente')
            ->value('VIEW_DEFINITION');

        $this->assertStringContainsString('`palma_v2_test`.', $definicao);
    }

    // ─── Dimensões ───────────────────────────────────────────────────────────────

    public function test_cliente_sai_com_cnpj_em_digitos_grupo_segmento_e_municipio(): void
    {
        DB::table('segmentos')->insert(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        DB::table('grupos_cliente')->insert(['codigo' => '9998', 'nome' => 'CLIENTES DIVERSOS']);
        DB::table(SchemaBi::nome().'.de_para_municipio')->insert([
            'uf' => 'SP', 'nome_norm' => 'SANTA BARBARA D OESTE', 'cod_municipio' => 3545803, 'origem' => 'IBGE',
        ]);

        $this->cliente([
            'cnpj' => '46.371.190/0001-54',
            'cod_segmento' => '101',
            'cod_grupo' => '9998',
            'estado' => 'SP',
            // a normalização do legado: apóstrofo e hífen viram espaço, espaço duplo colapsa
            'municipio' => "  santa barbara d'oeste ",
        ]);

        $c = $this->ler('vw_bi_dim_cliente')[0];

        $this->assertSame('46371190000154', $c->cnpj);
        $this->assertSame('46371190', $c->raiz_cnpj);
        $this->assertSame(9998, $c->grupo_vendas);
        $this->assertSame('CLIENTES DIVERSOS', $c->grupo_descricao);
        $this->assertSame('SUPERMERCADISTA', $c->segmento_descricao);
        $this->assertSame(3545803, $c->cod_municipio);
    }

    /** Os cortes são os do BI legado (295/365), testados exatamente na fronteira. */
    public function test_status_do_cliente_segue_os_cortes_do_bi(): void
    {
        $dias = ['A' => 295, 'B' => 296, 'C' => 365, 'D' => 366];

        foreach ($dias as $loja => $n) {
            $this->cliente(['loja' => $loja, 'data_ultima_compra' => now()->subDays($n)->toDateString()]);
        }
        $this->cliente(['loja' => 'E', 'data_ultima_compra' => null]);

        $this->assertSame(
            ['A' => 'ativo', 'B' => 'inativando', 'C' => 'inativando', 'D' => 'inativo', 'E' => 'nunca comprou'],
            collect($this->ler('vw_bi_dim_cliente', 'loja'))->pluck('status', 'loja')->all()
        );
    }

    /**
     * ⚠️ O código repetido é o caso real (134 perfis para 132 códigos). O usuário inativo
     * é o MAIS ANTIGO de propósito: "o mais antigo vence" sozinho daria a resposta errada.
     */
    public function test_vendedor_tem_uma_linha_por_codigo_e_prefere_o_usuario_ativo(): void
    {
        $this->usuario('vendedor', 'antigo@autopel.com', '010395', ativo: false, nome: 'PAULO ANTIGO');
        $this->usuario('vendedor', 'atual@autopel.com', '010395', ['cod_super' => '000006', 'cod_gerente' => '010002', 'equipe_rep' => 'SP'], nome: 'PAULO DE TARSO BORIN');
        DB::table('vendedores_totvs')->insert([
            ['codigo' => '010395', 'nome' => 'NOME DO TOTVS', 'nome_reduzido' => null],
            ['codigo' => '000777', 'nome' => 'SO NO TOTVS', 'nome_reduzido' => 'SO TOTVS'],
        ]);

        $linhas = collect($this->ler('vw_bi_dim_vendedor'))->keyBy('cod_vendedor');

        $this->assertCount(2, $linhas);

        $paulo = $linhas['010395'];
        $this->assertSame('PAULO DE TARSO BORIN', $paulo->nome);
        $this->assertSame('vendedor', $paulo->perfil);
        $this->assertSame('000006', $paulo->cod_supervisor);
        $this->assertSame('010002', $paulo->cod_gerente, 'texto, com o zero à esquerda');
        $this->assertSame(1, (int) $paulo->ativo);

        $soTotvs = $linhas['000777'];
        $this->assertSame('SO NO TOTVS', $soTotvs->nome);
        $this->assertSame('SO TOTVS', $soTotvs->nome_exibicao);
        $this->assertSame('nao_cadastrado', $soTotvs->perfil);
        $this->assertSame(0, (int) $soTotvs->ativo);
    }

    public function test_produto_inclui_codigos_vendidos_fora_do_cadastro_uma_vez_so(): void
    {
        DB::table('produtos')->insert(['cod_produto' => 'V1', 'descricao' => 'CADASTRADO', 'unidade' => 'CX', 'preco_tabela' => 12.3456]);
        $base = ['data_emissao' => '2026-01-10', 'cod_vendedor' => '1', 'valor_total' => 1];
        DB::table('faturamentos')->insert([
            $base + ['cod_produto' => 'V1', 'produto_desc' => 'NAO DEVE APARECER'],
            $base + ['cod_produto' => 'ORFAO', 'produto_desc' => 'VENDIDO SEM CADASTRO'],
            $base + ['cod_produto' => 'ORFAO', 'produto_desc' => 'VENDIDO SEM CADASTRO'],
        ]);
        $pedido = DB::table('pedidos')->insertGetId(['numero_pedido' => '1', 'cod_vendedor' => '1', 'data_pedido' => '2026-01-01', 'valor_total' => 1]);
        DB::table('pedido_itens')->insert([
            ['pedido_id' => $pedido, 'cod_produto' => 'ORFAO', 'descricao' => 'VENDIDO SEM CADASTRO', 'quantidade' => 1, 'valor_unitario' => 1, 'valor_total' => 1],
            ['pedido_id' => $pedido, 'cod_produto' => 'SO_NO_PEDIDO', 'descricao' => 'SO NO PEDIDO', 'quantidade' => 1, 'valor_unitario' => 1, 'valor_total' => 1],
        ]);

        $linhas = collect($this->ler('vw_bi_dim_produto'))->keyBy('cod_produto');

        $this->assertEqualsCanonicalizing(['V1', 'ORFAO', 'SO_NO_PEDIDO'], $linhas->keys()->all());
        $this->assertSame('CADASTRADO', $linhas['V1']->descricao);
        $this->assertSame('12.3456', $linhas['V1']->preco_venda);
        $this->assertSame('VENDIDO SEM CADASTRO', $linhas['ORFAO']->descricao);
        $this->assertNull($linhas['ORFAO']->preco_venda);
    }

    // ─── Fatos ───────────────────────────────────────────────────────────────────

    public function test_faturamento_marca_devolucao_e_resolve_municipio(): void
    {
        DB::table(SchemaBi::nome().'.de_para_municipio')->insert([
            'uf' => 'SP', 'nome_norm' => 'MOJI DAS CRUZES', 'cod_municipio' => 3530607, 'origem' => 'GRAFIA_ANTIGA',
        ]);
        $base = ['data_emissao' => '2026-01-10', 'cod_vendedor' => '000359', 'cnpj' => '46.371.190/0001-54', 'estado' => 'SP', 'municipio' => 'MOJI DAS CRUZES'];
        DB::table('faturamentos')->insert([
            $base + ['pedido' => '1', 'valor_total' => 100],
            $base + ['pedido' => '2', 'valor_total' => -40],
        ]);

        $linhas = collect($this->ler('vw_bi_fato_faturamento', 'numero_pedido'))->keyBy('numero_pedido');

        $this->assertSame(0, (int) $linhas['1']->eh_devolucao);
        $this->assertSame(1, (int) $linhas['2']->eh_devolucao);
        $this->assertSame('46371190000154', $linhas['1']->cnpj);
        $this->assertSame(3530607, $linhas['1']->cod_municipio);
        $this->assertSame('FATURAMENTO', $linhas['1']->origem_tabela);
    }

    public function test_pedidos_abertos_trazem_so_os_em_aberto_com_atraso_calculado(): void
    {
        $cliente = $this->cliente(['cnpj' => '01.157.555/0066-50', 'loja' => '0066']);
        $aberto = DB::table('pedidos')->insertGetId([
            'numero_pedido' => 'A00051', 'cliente_id' => $cliente, 'filial' => 5, 'cod_vendedor' => '010585',
            'data_pedido' => now()->subDays(20)->toDateString(),
            'data_previsao_faturamento' => now()->subDays(3)->toDateString(),
            'condicao_pagamento' => '060', 'valor_total' => 10,
        ]);
        $noPrazo = DB::table('pedidos')->insertGetId([
            'numero_pedido' => '992086', 'cod_vendedor' => '010585', 'data_pedido' => now()->toDateString(),
            'data_previsao_faturamento' => now()->addDays(5)->toDateString(), 'valor_total' => 10,
        ]);
        $faturado = DB::table('pedidos')->insertGetId([
            'numero_pedido' => '900000', 'cod_vendedor' => '010585', 'data_pedido' => '2026-01-01',
            'data_faturamento' => '2026-01-05', 'valor_total' => 10,
        ]);
        foreach ([$aberto, $noPrazo, $faturado] as $id) {
            DB::table('pedido_itens')->insert(['pedido_id' => $id, 'cod_produto' => 'V1', 'descricao' => 'X', 'quantidade' => 2, 'quantidade_liberada' => 1, 'valor_unitario' => 5, 'valor_total' => 10]);
        }

        $linhas = collect($this->ler('vw_bi_fato_pedidos_abertos', 'numero_pedido'))->keyBy('numero_pedido');

        $this->assertEqualsCanonicalizing(['A00051', '992086'], $linhas->keys()->all());
        $this->assertSame(3, (int) $linhas['A00051']->atraso);
        $this->assertSame(0, (int) $linhas['992086']->atraso, 'no prazo não fica negativo');
        $this->assertSame(60, (int) $linhas['A00051']->condicao_pagamento);
        $this->assertSame(5, (int) $linhas['A00051']->filial);
        $this->assertSame('0066', $linhas['A00051']->loja);
        $this->assertSame('01157555006650', $linhas['A00051']->cnpj);
    }

    public function test_pedidos_emitidos_trazem_abertos_e_faturados_com_vendedor_e_supervisor(): void
    {
        $this->usuario('supervisor', 'sup@autopel.com', '000006', nome: 'CLEBER S CATELA');
        $this->usuario('vendedor', 'vend@autopel.com', '000359', ['cod_super' => '000006'], nome: 'AM COMERCIO');
        DB::table('segmentos')->insert(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        DB::table('produtos')->insert(['cod_produto' => 'V28042', 'descricao' => 'BOBINA', 'unidade' => 'CX']);
        $cliente = $this->cliente(['cnpj' => '46.371.190/0001-54', 'cod_segmento' => '101']);

        foreach ([['981464', null], ['981465', '2026-09-02']] as [$numero, $faturamento]) {
            $id = DB::table('pedidos')->insertGetId([
                'numero_pedido' => $numero, 'cliente_id' => $cliente, 'cod_vendedor' => '000359',
                'data_pedido' => '2026-09-01', 'data_faturamento' => $faturamento, 'valor_total' => 1,
            ]);
            DB::table('pedido_itens')->insert(['pedido_id' => $id, 'cod_produto' => 'V28042', 'descricao' => 'BOBINA TS', 'nota_fiscal' => '001123204', 'quantidade' => 750, 'valor_unitario' => 2.91, 'valor_total' => 2182.5]);
        }

        $linhas = collect($this->ler('vw_bi_fato_pedidos_emitidos', 'PEDIDO'))->keyBy('PEDIDO');

        $this->assertCount(2, $linhas);
        $this->assertNull($linhas['981464']->DT_FATURAMENTO, 'o em aberto também é pedido emitido');
        $this->assertSame('2026-09-02', $linhas['981465']->DT_FATURAMENTO);

        $l = $linhas['981465'];
        $this->assertSame('AM COMERCIO', $l->REPRES);
        $this->assertSame('CLEBER S CATELA', $l->SUPERVISOR);
        $this->assertSame('SUPERMERCADISTA', $l->ATIVIDADE);
        $this->assertSame('CX', $l->UND);
        $this->assertSame('46371190000154', $l->CNPJ);
        $this->assertSame('EMPORIO DOM LUIZ LTDA', $l->CLIENTE);
        $this->assertSame('001123204', $l->NUM_DOCTO);
    }

    /** Decisão do Tony (2026-09-16): sem aprovação do cliente no CRM, `status` fica vazio. */
    public function test_orcamento_sai_sem_status_de_conversao(): void
    {
        $vendedor = $this->usuario('vendedor', 'v@autopel.com', '000359');
        $cliente = $this->cliente(['cnpj' => '46.371.190/0001-54']);
        $lead = DB::table('leads')->insertGetId(['nome' => 'L', 'razao_social' => 'L']);

        foreach ([['cliente_id' => $cliente], ['lead_id' => $lead], []] as $origem) {
            DB::table('orcamentos')->insert($origem + [
                'user_id' => $vendedor->id, 'cliente_nome' => 'EMPORIO', 'cliente_cnpj' => '46371190000154',
                'valor_total' => 10, 'status_gestor' => 'aprovado', 'aprovado_em' => '2026-09-01 10:00:00',
            ]);
        }

        $linhas = $this->ler('vw_bi_fato_orcamentos', 'id_orcamento');

        $this->assertSame(['cliente', 'lead', 'avulso'], array_column($linhas, 'origem_cliente'));
        $this->assertSame([null], array_values(array_unique(array_column($linhas, 'status'))));
        $this->assertSame('aprovado', $linhas[0]->status_gestor);
        $this->assertSame('000359', $linhas[0]->cod_vendedor);
        $this->assertSame('206065', $linhas[0]->cod_cliente);
        $this->assertSame('2026-09-01 10:00:00', $linhas[0]->data_aprovacao_gestor);
    }

    /**
     * ⚠️ O status do lead é a situação do CLIENTE, não a etapa do funil: a medida
     * "Resumo Clientes Multilinha" conta ativo/inativo/inativando/prospect.
     */
    public function test_lead_tem_status_de_cliente_e_ultima_venda(): void
    {
        $this->cliente(['cnpj' => '46.371.190/0001-54', 'data_ultima_compra' => now()->subDays(10)->toDateString()]);
        DB::table('faturamentos')->insert([
            ['data_emissao' => now()->subDays(10)->toDateString(), 'cod_vendedor' => '1', 'cnpj' => '46.371.190/0001-54', 'valor_total' => 70],
            ['data_emissao' => now()->subDays(10)->toDateString(), 'cod_vendedor' => '1', 'cnpj' => '46.371.190/0001-54', 'valor_total' => 30],
            ['data_emissao' => now()->subDays(40)->toDateString(), 'cod_vendedor' => '1', 'cnpj' => '46.371.190/0001-54', 'valor_total' => 999],
        ]);
        DB::table('leads')->insert([
            ['origem' => 'sistema', 'nome' => 'C', 'razao_social' => 'JA E CLIENTE', 'cnpj' => '46.371.190/0001-54', 'valor_estimado' => 5, 'status' => 'ativo'],
            ['origem' => 'manual', 'nome' => 'P', 'razao_social' => 'PROSPECT', 'cnpj' => '11.111.111/0001-11', 'valor_estimado' => 5000, 'status' => 'ativo'],
            ['origem' => 'wordpress', 'nome' => 'X', 'razao_social' => 'EXCLUIDO', 'cnpj' => '22.222.222/0001-22', 'valor_estimado' => null, 'status' => 'excluido'],
        ]);

        $linhas = collect($this->ler('vw_bi_fato_leads'))->keyBy('razao_social');

        $this->assertCount(2, $linhas, 'lead excluído fica de fora');

        $cliente = $linhas['JA E CLIENTE'];
        $this->assertSame('BASE', $cliente->origem);
        $this->assertSame('ativo', $cliente->status);
        $this->assertSame(now()->subDays(10)->toDateString(), $cliente->data_ultima_venda);
        $this->assertEquals(100, $cliente->valor_ultima_venda, 'soma do dia da última compra, não do histórico');
        $this->assertNull($cliente->faturamento_bruto_2024, 'a base importada não traz faturamento 2024');

        $prospect = $linhas['PROSPECT'];
        $this->assertSame('MANUAL', $prospect->origem);
        $this->assertSame('prospect', $prospect->status);
        $this->assertEquals(5000, $prospect->faturamento_bruto_2024);
        $this->assertSame('11111111000111', $prospect->cnpj);
    }

    public function test_metas_vem_da_tela_de_metas(): void
    {
        DB::table('metas_mensais')->insert(['cod_vendedor' => '000359', 'ano' => 2026, 'mes' => 9, 'tipo' => 'venda', 'valor_meta' => 1500, 'created_at' => '2026-09-01 08:00:00']);

        $m = $this->ler('vw_bi_fato_metas')[0];

        $this->assertSame('000359', $m->COD_VENDEDOR);
        $this->assertSame('venda', $m->tipo);
        $this->assertSame('1500.00', $m->valor_meta);
        $this->assertSame('2026-09-01 08:00:00', $m->data_criacao);
    }

    public function test_indicadores_calculam_per_capita_sem_dividir_por_zero(): void
    {
        $base = ['ano_base_pea' => 2010, 'ano_base_pop' => 2022, 'ano_base_pib' => 2022, 'atualizado_em' => '2026-07-21 15:42:52'];
        DB::table(SchemaBi::nome().'.indicadores_municipio')->insert([
            $base + ['cod_municipio' => 1, 'pea_total' => 50, 'populacao' => 200, 'pib_total_reais' => 1000],
            $base + ['cod_municipio' => 2, 'pea_total' => 0, 'populacao' => 0, 'pib_total_reais' => 1000],
        ]);

        $linhas = collect($this->ler('vw_bi_fato_indicadores_municipio'))->keyBy('cod_municipio');

        $this->assertEquals(5, $linhas[1]->pib_per_capita);
        $this->assertEquals(0.25, $linhas[1]->pea_percentual_populacao);
        $this->assertNull($linhas[2]->pib_per_capita);
    }

    public function test_cobertura_mostra_municipio_resolvido_e_familia_por_ano(): void
    {
        DB::table(SchemaBi::nome().'.de_para_municipio')->insert([
            'uf' => 'SP', 'nome_norm' => 'SUMARE', 'cod_municipio' => 3552403, 'origem' => 'IBGE',
        ]);
        $base = ['data_emissao' => '2026-01-10', 'cod_vendedor' => '1', 'valor_total' => 1, 'estado' => 'SP'];
        DB::table('faturamentos')->insert([
            $base + ['municipio' => 'SUMARE', 'desc_familia' => 'BOBINA'],
            $base + ['municipio' => 'SUMAREE', 'desc_familia' => null],
            ['data_emissao' => '2019-05-01', 'municipio' => null, 'desc_familia' => null] + $base,
        ]);

        $this->artisan('bi:cobertura')
            ->expectsTable(
                ['Ano', 'Linhas', 'Com município', 'Código IBGE resolvido', 'Com família'],
                [[2019, '1', '0,00%', '-', '0,00%'], [2026, '2', '100,00%', '50,00%', '50,00%']]
            )
            ->expectsOutputToContain('SUMAREE')
            ->assertSuccessful();
    }

    // ─── RLS ─────────────────────────────────────────────────────────────────────

    /**
     * A regra do legado: diretor e admin veem todos; supervisor e gerente veem quem
     * aponta para eles (um nível só); os demais, o próprio código; só ativos @autopel.com.
     */
    public function test_seg_acesso_aplica_a_regra_do_legado(): void
    {
        $this->usuario('diretor', 'Diretor@Autopel.com', '010002', nome: 'DIRETOR');
        $this->usuario('admin', 'admin@autopel.com', null);
        $this->usuario('supervisor', 'sup@autopel.com', '000006', ['cod_super' => '010002', 'cod_gerente' => '010002']);
        $this->usuario('supervisor', 'outro.sup@autopel.com', '000054', ['cod_super' => '010002']);
        $this->usuario('vendedor', 'vend@autopel.com', '000359', ['cod_super' => '000006', 'cod_gerente' => '010002']);
        $this->usuario('vendedor', 'vend2@autopel.com', '000777', ['cod_super' => '000054', 'cod_gerente' => '010002']);
        // Neto do supervisor 000006 (aponta para o vendedor 000359): fica FORA — é um nível só.
        $this->usuario('representante', 'rep@autopel.com', '000900', ['cod_super' => '000359']);
        $this->usuario('vendedor', 'inativo@autopel.com', '000111', ativo: false);
        $this->usuario('vendedor', 'fora@gmail.com', '000222');

        $acesso = collect($this->ler('vw_bi_seg_acesso', 'email, cod_vendedor'))
            ->groupBy('email')
            ->map(fn ($linhas) => $linhas->pluck('cod_vendedor')->sort()->values()->all())
            ->all();

        $todos = ['000006', '000054', '000111', '000222', '000359', '000777', '000900', '010002'];

        $this->assertSame($todos, $acesso['diretor@autopel.com'], 'e-mail sai em minúsculas');
        $this->assertSame($todos, $acesso['admin@autopel.com'], 'admin vê tudo mesmo sem código');
        $this->assertSame(['000006', '000359'], $acesso['sup@autopel.com']);
        $this->assertSame(['000054', '000777'], $acesso['outro.sup@autopel.com']);
        $this->assertSame(['000359', '000900'], $acesso['vend@autopel.com'], 'quem aponta para ele em cod_super');
        $this->assertSame(['000777'], $acesso['vend2@autopel.com']);
        $this->assertArrayNotHasKey('inativo@autopel.com', $acesso);
        $this->assertArrayNotHasKey('fora@gmail.com', $acesso);
    }

    /** O gerente enxerga pelo `cod_gerente`, mesmo quando ninguém aponta para ele em `cod_super`. */
    public function test_gerente_ve_quem_aponta_para_ele_em_cod_gerente(): void
    {
        $this->usuario('vendedor', 'gerente@autopel.com', '000054');
        $this->usuario('vendedor', 'a@autopel.com', '000359', ['cod_super' => '000006', 'cod_gerente' => '000054']);
        $this->usuario('vendedor', 'b@autopel.com', '000777', ['cod_super' => '000006', 'cod_gerente' => '010002']);

        $codigos = collect($this->ler('vw_bi_seg_acesso'))
            ->where('email', 'gerente@autopel.com')
            ->pluck('cod_vendedor')->sort()->values()->all();

        $this->assertSame(['000054', '000359'], $codigos);
    }
}
