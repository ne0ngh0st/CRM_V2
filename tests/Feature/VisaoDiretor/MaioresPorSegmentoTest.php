<?php

namespace Tests\Feature\VisaoDiretor;

use App\Exports\MaioresPorSegmentoExport;
use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\ContaEstrategicaVinculo;
use App\Models\GrupoCliente;
use App\Models\Segmento;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\FaturamentoMensalRollup;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Visão Diretor → Maiores por Segmento.
 *
 * O que estes testes protegem, em ordem de importância:
 *
 * 1. O número clicado bate com a lista aberta. "Nossas lojas" da conta tem que ser o total
 *    da Carteira filtrada por `?conta_alvo=`, e isso só é verdade enquanto as duas telas
 *    usarem a MESMA definição (`ClientesDaConta`).
 * 2. O gate: a seção é só admin + diretor, e o filtro `conta_alvo` não vale para os outros.
 * 3. Os derivados (status, atendimento, penetração) seguem as regras da Carteira.
 * 4. O rollup de faturamento soma exatamente o que `faturamentos` soma.
 */
class MaioresPorSegmentoTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $drogarias;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = $this->usuario('admin');
        $this->drogarias = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);

        GrupoCliente::create(['codigo' => '100', 'nome' => 'RAIA SP']);
        GrupoCliente::create(['codigo' => '101', 'nome' => 'RAIA RJ']);
        GrupoCliente::create(['codigo' => '555', 'nome' => 'OUTRA REDE']);
        GrupoCliente::create(['codigo' => '9998', 'nome' => 'CLIENTES DIVERSOS']);

        $hoje = now();
        $this->cliente('000001', '01', '100', '001', $hoje->copy()->subDays(10));
        $this->cliente('000001', '02', '100', '002', $hoje->copy()->subDays(400));
        $this->cliente('000002', '01', '101', '001', null);
        $this->cliente('000003', '01', '555', '003', $hoje->copy()->subDays(300));
        $this->cliente('000004', '01', '777', '001', $hoje->copy()->subDays(5));
    }

    private function usuario(string $papel, ?string $codVendedor = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($papel);

        if ($codVendedor) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor]);
        }

        return $user;
    }

    private function cliente(string $codigo, string $loja, string $grupo, string $vendedor, $ultimaCompra): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $codigo,
            'loja' => $loja,
            'cnpj' => '11.111.'.substr($codigo, -3).'/00'.$loja.'-11',
            'razao_social' => "CLIENTE {$codigo}",
            'cod_vendedor' => $vendedor,
            'cod_grupo' => $grupo,
            'cod_segmento' => '109',
            'estado' => 'SP',
            'data_ultima_compra' => $ultimaCompra,
        ]);
    }

    /** @param  list<array{0: string, 1: string}>  $vinculos  [tipo, codigo] */
    private function conta(string $nome, array $vinculos = [], ?int $filiais = null): ContaEstrategica
    {
        $conta = ContaEstrategica::create([
            'segmento_id' => $this->drogarias->id,
            'nome' => $nome,
            'filiais_mercado' => $filiais,
            'ordem' => ContaEstrategica::count() + 1,
        ]);

        app(ClientesDaConta::class)->sincronizarVinculos($conta, array_map(
            fn (array $v) => ['tipo' => $v[0], 'codigo' => $v[1]],
            $vinculos,
        ));

        return $conta;
    }

    private function linha(ContaEstrategica $conta): array
    {
        return app(MaioresPorSegmentoResolver::class)->linhas()->firstWhere('id', $conta->id);
    }

    private function totalDaCarteira(User $user, array $query): int
    {
        $total = null;

        $this->actingAs($user)
            ->get(route('carteira.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$total) {
                $total = $page->toArray()['props']['clientes']['total'];
            });

        return $total;
    }

    // ─── Acesso ─────────────────────────────────────────────────────────────────

    public function test_admin_e_diretor_entram(): void
    {
        foreach (['admin', 'diretor'] as $papel) {
            $this->actingAs($this->usuario($papel))
                ->get(route('visao-diretor.maiores.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('VisaoDiretor/MaioresPorSegmento'));
        }
    }

    public function test_demais_perfis_recebem_403_em_toda_rota_da_secao(): void
    {
        $conta = $this->conta('RAIA', [['grupo', '100']]);

        foreach (['supervisor', 'vendedor', 'representante', 'assistente'] as $papel) {
            $user = $this->usuario($papel);

            $this->actingAs($user)->get(route('visao-diretor.maiores.index'))->assertForbidden();
            $this->actingAs($user)->get(route('visao-diretor.maiores.clientes', $conta))->assertForbidden();
            $this->actingAs($user)->patch(route('visao-diretor.maiores.update', $conta), ['nome' => 'X'])->assertForbidden();
            $this->actingAs($user)->delete(route('visao-diretor.maiores.destroy', $conta))->assertForbidden();
        }

        $this->assertDatabaseHas('contas_estrategicas', ['id' => $conta->id, 'nome' => 'RAIA']);
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get(route('visao-diretor.maiores.index'))->assertRedirect(route('login'));
    }

    // ─── Derivados ──────────────────────────────────────────────────────────────

    public function test_deriva_lojas_clientes_status_atendimento_e_penetracao(): void
    {
        $conta = $this->conta('RAIA', [['grupo', '100'], ['grupo', '101'], ['cliente', '000003']], 100);

        $l = $this->linha($conta);

        $this->assertSame(4, $l['lojas']);
        $this->assertSame(3, $l['clientes']);
        $this->assertSame(0.04, $l['penetracao']);
        // A loja que comprou há 10 dias basta: a rede está ativa.
        $this->assertSame('ativo', $l['status']);
        $this->assertSame(['001', '002', '003'], array_column($l['atendimento'], 'codVendedor'));
        $this->assertSame(2, $l['atendimento'][0]['lojas']);
    }

    public function test_status_segue_o_corte_da_carteira_e_sem_loja_e_lead(): void
    {
        $perdendo = $this->conta('PERDENDO', [['cliente', '000003']]); // 300 dias
        $trabalhar = $this->conta('A TRABALHAR', [['cliente', '000002']]); // nunca comprou
        $lead = $this->conta('SEM VINCULO');

        $this->assertSame('inativando', $this->linha($perdendo)['status']);
        $this->assertSame('inativo', $this->linha($trabalhar)['status']);
        $this->assertSame('lead', $this->linha($lead)['status']);
    }

    /**
     * ⚠️ Uma filial casada pelo grupo E pelo código conta UMA vez. Com `UNION ALL` nos
     * pares ela contaria duas — e deixaria de bater com a Carteira, que filtra com OR.
     */
    public function test_filial_casada_por_grupo_e_por_codigo_conta_uma_vez(): void
    {
        $conta = $this->conta('DUPLA', [['grupo', '555'], ['cliente', '000003']]);

        $this->assertSame(1, $this->linha($conta)['lojas']);
        $this->assertSame(1, $this->totalDaCarteira($this->admin, ['conta_alvo' => $conta->id, 'agrupar' => '0']));
    }

    // ─── A invariante: número clicado == lista aberta ───────────────────────────

    public function test_nossas_lojas_e_clientes_batem_com_a_carteira_filtrada(): void
    {
        $conta = $this->conta('RAIA', [['grupo', '100'], ['grupo', '101'], ['cliente', '000003']], 100);
        $l = $this->linha($conta);

        $this->assertSame($l['lojas'], $this->totalDaCarteira($this->admin, ['conta_alvo' => $conta->id, 'agrupar' => '0']));
        $this->assertSame($l['clientes'], $this->totalDaCarteira($this->admin, ['conta_alvo' => $conta->id]));

        // O chip de cada vendedor em "Atendimento" abre a carteira dele dentro da conta.
        foreach ($l['atendimento'] as $v) {
            $this->assertSame($v['lojas'], $this->totalDaCarteira($this->admin, [
                'conta_alvo' => $conta->id, 'visao_vendedor' => $v['codVendedor'], 'agrupar' => '0',
            ]));
        }
    }

    /**
     * ⚠️ O total da Carteira é cacheado por 10 min com a assinatura dos filtros. Sem a
     * VERSÃO dos vínculos na assinatura, editar a conta deixaria a Carteira mostrando o
     * total antigo — o número clicado não bateria com a lista aberta.
     */
    public function test_editar_vinculo_reflete_na_carteira_sem_esperar_o_cache(): void
    {
        $conta = $this->conta('RAIA', [['grupo', '100'], ['grupo', '101']]);

        $this->assertSame(2, $this->totalDaCarteira($this->admin, ['conta_alvo' => $conta->id]));

        $this->actingAs($this->admin)
            ->patch(route('visao-diretor.maiores.update', $conta), [
                'segmento_id' => $this->drogarias->id,
                'nome' => 'RAIA',
                'vinculos' => [['tipo' => 'grupo', 'codigo' => '100']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->totalDaCarteira($this->admin, ['conta_alvo' => $conta->id]));
    }

    public function test_conta_alvo_e_ignorado_fora_do_gate(): void
    {
        $conta = $this->conta('RAIA', [['grupo', '100']]);
        $vendedor = $this->usuario('vendedor', '001');

        $semFiltro = $this->totalDaCarteira($vendedor, ['agrupar' => '0']);

        $this->assertSame($semFiltro, $this->totalDaCarteira($vendedor, ['conta_alvo' => $conta->id, 'agrupar' => '0']));
    }

    public function test_conta_sem_vinculo_abre_carteira_vazia_e_nao_a_carteira_inteira(): void
    {
        $conta = $this->conta('SEM VINCULO');

        $this->assertSame(0, $this->totalDaCarteira($this->admin, ['conta_alvo' => $conta->id]));
    }

    // ─── Escrita ────────────────────────────────────────────────────────────────

    public function test_grupo_clientes_diversos_e_recusado(): void
    {
        $conta = $this->conta('RAIA', [['grupo', '100']]);

        $this->actingAs($this->admin)
            ->patch(route('visao-diretor.maiores.update', $conta), [
                'segmento_id' => $this->drogarias->id,
                'nome' => 'RAIA',
                'vinculos' => [['tipo' => 'grupo', 'codigo' => '9998']],
            ])
            ->assertSessionHasErrors('vinculos.0.codigo');

        $this->assertSame(['100'], $conta->vinculos()->pluck('codigo')->all());
    }

    public function test_vinculo_para_codigo_inexistente_e_recusado(): void
    {
        $this->actingAs($this->admin)
            ->post(route('visao-diretor.maiores.store'), [
                'segmento_id' => $this->drogarias->id,
                'nome' => 'NOVA',
                'vinculos' => [['tipo' => 'grupo', 'codigo' => '424242']],
            ])
            ->assertSessionHasErrors('vinculos');

        $this->assertDatabaseMissing('contas_estrategicas', ['nome' => 'NOVA']);
    }

    public function test_salvar_pela_tela_confirma_a_sugestao(): void
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->drogarias->id, 'nome' => 'RAIA']);
        app(ClientesDaConta::class)->sincronizarVinculos($conta, [
            ['tipo' => 'grupo', 'codigo' => '100', 'origem' => ContaEstrategicaVinculo::ORIGEM_SUGESTAO],
        ]);

        $this->assertTrue($this->linha($conta)['temSugestao']);

        $this->actingAs($this->admin)
            ->patch(route('visao-diretor.maiores.update', $conta), [
                'segmento_id' => $this->drogarias->id,
                'nome' => 'RAIA',
                'vinculos' => [['tipo' => 'grupo', 'codigo' => '100', 'origem' => 'manual']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->linha($conta)['temSugestao']);
    }

    public function test_nome_repetido_no_mesmo_segmento_e_recusado(): void
    {
        $this->conta('RAIA');

        $this->actingAs($this->admin)
            ->post(route('visao-diretor.maiores.store'), ['segmento_id' => $this->drogarias->id, 'nome' => 'RAIA'])
            ->assertSessionHasErrors('nome');
    }

    // ─── Tela ───────────────────────────────────────────────────────────────────

    /**
     * O quadro-resumo desenha a quebra por segmento, então ignora o filtro de segmento —
     * senão viraria uma linha só. A lista `segmentos` (o Excel) recorta; o resumo não.
     */
    public function test_resumo_por_segmento_ignora_o_filtro_de_segmento(): void
    {
        $postos = Segmento::create(['codigo' => '114', 'nome' => 'POSTOS']);
        $this->conta('RAIA', [['grupo', '100']]);
        ContaEstrategica::create(['segmento_id' => $postos->id, 'nome' => 'SHELL']);

        $dados = app(MaioresPorSegmentoResolver::class)->resolver(['segmento' => '109']);

        $this->assertCount(1, $dados['segmentos']);
        $this->assertSame(1, $dados['kpis']['contas']);
        $this->assertCount(2, $dados['resumoPorSegmento']);
    }

    /**
     * Sem conta nenhuma a tela ainda tem as abas da planilha — senão o diretor abre e
     * não tem o que clicar, e parece que a feature não existe.
     */
    public function test_abas_da_planilha_existem_mesmo_sem_conta(): void
    {
        foreach (['108' => 'REDE DE LOJAS', '112' => 'ALIMENTACAO', '113' => 'ESTACIONAMENTOS', '114' => 'POSTOS', '120' => 'CONSTRUCAO'] as $codigo => $nome) {
            Segmento::create(['codigo' => $codigo, 'nome' => $nome]);
        }

        $dados = app(MaioresPorSegmentoResolver::class)->resolver();

        $this->assertSame(
            ['109', '108', '112', '114', '120', '113'],
            array_column($dados['segmentos'], 'codigo'),
        );
        $this->assertSame(0, $dados['segmentos'][0]['resumo']['contas']);
        $this->assertSame([], $dados['segmentos'][0]['contas']);
    }

    /**
     * A página manda as seis tabelas juntas (troca de aba local). O `?segmento=` da URL
     * não pode recortar o payload, senão o clique na aba iria ao servidor.
     */
    public function test_a_pagina_nao_recorta_as_abas_pelo_segmento_da_url(): void
    {
        $postos = Segmento::create(['codigo' => '114', 'nome' => 'POSTOS']);
        $this->conta('RAIA', [['grupo', '100']]);
        ContaEstrategica::create(['segmento_id' => $postos->id, 'nome' => 'SHELL']);

        $this->actingAs($this->admin)
            ->get(route('visao-diretor.maiores.index', ['segmento' => '109']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filtros.segmento', '109')
                ->has('dados.segmentos', 2)
                ->where('dados.segmentos.0.codigo', '109')
                ->where('dados.segmentos.1.codigo', '114')
            );
    }

    public function test_linha_expandida_lista_os_mesmos_clientes_da_contagem(): void
    {
        $conta = $this->conta('RAIA', [['grupo', '100'], ['grupo', '101'], ['cliente', '000003']]);

        $resposta = $this->actingAs($this->admin)
            ->getJson(route('visao-diretor.maiores.clientes', $conta))
            ->assertOk()
            ->json();

        $this->assertSame($this->linha($conta)['clientes'], $resposta['total']);
        $this->assertEqualsCanonicalizing(['000001', '000002', '000003'], array_column($resposta['clientes'], 'codCliente'));
        // A mais recente primeiro.
        $this->assertSame('000001', $resposta['clientes'][0]['codCliente']);
    }

    /**
     * O custo não pode crescer com o número de contas: a agregação é por conjunto, não
     * uma consulta por conta.
     */
    public function test_numero_de_queries_nao_cresce_com_as_contas(): void
    {
        $this->conta('A', [['grupo', '100']]);
        $poucas = $this->contarQueries();

        foreach (range(1, 20) as $i) {
            $this->conta("CONTA {$i}", [['grupo', '101'], ['cliente', '000003']]);
        }

        $this->assertSame($poucas, $this->contarQueries());
    }

    private function contarQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MaioresPorSegmentoResolver::class)->resolver();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_planilha_tem_cabecalho_e_linha_do_mesmo_tamanho(): void
    {
        $this->conta('RAIA', [['grupo', '100']], 100);
        $dados = app(MaioresPorSegmentoResolver::class)->resolver();
        $export = new MaioresPorSegmentoExport($dados['segmentos']);

        $this->assertCount(count($export->headings()), $export->array()[0]);
    }

    // ─── Rollup de faturamento ──────────────────────────────────────────────────

    public function test_rollup_soma_o_mesmo_que_faturamentos_em_cada_mes(): void
    {
        $mesPassado = CarbonImmutable::today()->startOfMonth()->subMonth();

        $this->nota('000001', $mesPassado->addDays(2), 100.10, 'NF1');
        $this->nota('000001', $mesPassado->addDays(2), 50.05, 'NF1'); // outro item da mesma nota
        $this->nota('000001', $mesPassado->addDays(20), 30.00, 'NF2');
        $this->nota('000002', $mesPassado->endOfMonth(), 7.00, 'NF3');

        app(FaturamentoMensalRollup::class)->recalcular($mesPassado, $mesPassado);

        $this->assertEqualsWithDelta(
            (float) DB::table('faturamentos')->whereBetween('data_emissao', [$mesPassado->toDateString(), $mesPassado->endOfMonth()->toDateString()])->sum('valor_total'),
            (float) DB::table('faturamento_cliente_mensal')->where('mes', $mesPassado->toDateString())->sum('valor_total'),
            0.001,
        );
        $this->assertSame(2, (int) DB::table('faturamento_cliente_mensal')->where('cod_cliente', '000001')->value('notas'));

        // Recalcular de novo não duplica.
        app(FaturamentoMensalRollup::class)->recalcular($mesPassado, $mesPassado);
        $this->assertSame(2, DB::table('faturamento_cliente_mensal')->count());
    }

    public function test_faturamento_da_conta_usa_12_meses_fechados_e_os_12_anteriores(): void
    {
        $inicioMes = CarbonImmutable::today()->startOfMonth();

        $this->nota('000001', $inicioMes->subMonth(), 1000, 'A');          // últimos 12 m
        $this->nota('000003', $inicioMes->subMonths(12), 200, 'B');        // primeiro mês da janela
        $this->nota('000001', $inicioMes->subMonths(13), 500, 'C');        // 12 m anteriores
        $this->nota('000001', $inicioMes, 777, 'D');                        // mês corrente: fora
        $this->nota('000004', $inicioMes->subMonth(), 9999, 'E');          // cliente de fora da conta

        app(FaturamentoMensalRollup::class)->recalcular($inicioMes->subMonths(24), $inicioMes);

        $conta = $this->conta('RAIA', [['grupo', '100'], ['cliente', '000003']]);
        $l = $this->linha($conta);

        $this->assertEqualsWithDelta(1200.0, $l['fat12m'], 0.001);
        $this->assertEqualsWithDelta(500.0, $l['fat12mAnterior'], 0.001);
    }

    private function nota(string $codCliente, CarbonImmutable $data, float $valor, string $nf): void
    {
        DB::table('faturamentos')->insert([
            'cod_cliente' => $codCliente,
            'cod_vendedor' => '001',
            'data_emissao' => $data->toDateString(),
            'valor_total' => $valor,
            'nota_fiscal' => $nf,
        ]);
    }
}
