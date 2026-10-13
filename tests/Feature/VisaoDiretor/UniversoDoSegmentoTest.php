<?php

namespace Tests\Feature\VisaoDiretor;

use App\Exports\ClientesLeadsSegmentoExport;
use App\Models\Cliente;
use App\Models\Exportacao;
use App\Models\ContaEstrategica;
use App\Models\GrupoCliente;
use App\Models\Lead;
use App\Models\Segmento;
use App\Models\User;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Maiores por Segmento → a tabela de cada segmento (`UniversoDoSegmento`, 2026-10-13):
 * as redes primeiro, depois todos os outros clientes e leads, do maior capital ao menor.
 *
 * O que estes testes protegem, em ordem de importância:
 *
 * 1. Ninguém duas vezes, ninguém de fora: todo cliente do segmento aparece UMA vez — solto
 *    ou dentro de uma rede — e o total bate com `/carteira?segmento=X`.
 * 2. Cada tile de status == a lista filtrada por ele (e, sem redes, == a Carteira).
 * 3. Lead cujo CNPJ já é de cliente não aparece.
 * 4. A ordem: redes no ranking, depois capital; sem capital vai para o fim.
 */
class UniversoDoSegmentoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Segmento $drogarias;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->drogarias = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);
        GrupoCliente::create(['codigo' => '100', 'nome' => 'RAIA SP']);

        $hoje = now();
        // 000001: duas filiais em drogaria (uma recente, uma velha) + uma em supermercado.
        $this->cliente('000001', '01', '109', '11111111000101', $hoje->copy()->subDays(400), '100');
        $this->cliente('000001', '02', '109', '11111111000202', $hoje->copy()->subDays(500), '100');
        $this->cliente('000001', '03', '101', '11111111000303', $hoje->copy()->subDays(10));
        // 000002: perdendo; 000003: nunca comprou; 000004: ativo, sem capital na Receita.
        $this->cliente('000002', '01', '109', '22222222000101', $hoje->copy()->subDays(300));
        $this->cliente('000003', '01', '109', '33333333000101', null);
        $this->cliente('000004', '01', '109', '44444444000101', $hoje->copy()->subDays(20));

        $this->receita('11111111000101', 5_000_000, '05');
        $this->receita('11111111000202', 5_000_000, '05');
        $this->receita('22222222000101', 900_000, '03');
        $this->receita('33333333000101', 50_000, '01');
        $this->receita('55555555000101', 2_000_000, '03');
    }

    private function cliente(string $codigo, string $loja, string $segmento, string $cnpj, $ultimaCompra, ?string $grupo = null): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $codigo,
            'loja' => $loja,
            'cnpj' => $this->mascara($cnpj),
            'razao_social' => "CLIENTE {$codigo}",
            'cod_vendedor' => '000010',
            'cod_grupo' => $grupo,
            'cod_segmento' => $segmento,
            'estado' => 'SP',
            'data_ultima_compra' => $ultimaCompra,
        ]);
    }

    private function lead(string $nome, ?string $cnpj, array $extra = []): Lead
    {
        // forceCreate: `conta_estrategica_id` e `conta_vinculo` não são fillable (só o import os grava).
        return Lead::forceCreate([
            'origem' => 'prospeccao',
            'nome' => $nome,
            'razao_social' => $nome,
            'cnpj' => $cnpj,
            'segmento' => 'DROGARIAS',
            'status' => 'ativo',
            'etapa' => 'novo',
            ...$extra,
        ]);
    }

    private function receita(string $cnpj, float $capital, string $porte): void
    {
        DB::table('cnpj_situacoes')->insert([
            'cnpj' => $cnpj,
            'situacao' => 'ATIVA',
            'capital_social' => $capital,
            'porte' => ['01' => 'ME', '03' => 'EPP', '05' => 'DEMAIS'][$porte],
            'fonte' => 'base',
            'atualizado_em' => now(),
        ]);
    }

    private function mascara(string $d): string
    {
        return substr($d, 0, 2).'.'.substr($d, 2, 3).'.'.substr($d, 5, 3).'/'.substr($d, 8, 4).'-'.substr($d, 12, 2);
    }

    private function lista(array $filtros = [], string $segmento = '109'): array
    {
        $r = app(UniversoDoSegmento::class)->lista($segmento, $filtros);
        $r['dados'] = $r['linhas']->items();

        return $r;
    }

    /** @param  list<array{0: string, 1: string}>  $vinculos  [tipo, codigo] */
    private function rede(string $nome, array $vinculos = [], int $ordem = 1): ContaEstrategica
    {
        $conta = ContaEstrategica::create(['segmento_id' => $this->drogarias->id, 'nome' => $nome, 'ordem' => $ordem]);
        app(ClientesDaConta::class)->sincronizarVinculos($conta, array_map(
            fn (array $v) => ['tipo' => $v[0], 'codigo' => $v[1]],
            $vinculos,
        ));

        return $conta;
    }

    private function totalDaCarteira(array $query): int
    {
        $total = null;

        $this->actingAs($this->admin)
            ->get(route('carteira.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$total) {
                $total = $page->toArray()['props']['clientes']['total'];
            });

        return $total;
    }

    // ─── Quem entra ─────────────────────────────────────────────────────────────

    public function test_cliente_com_filiais_em_dois_segmentos_aparece_em_cada_um(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        $drogaria = collect($this->lista()['dados'])->firstWhere('codCliente', '000001');
        $mercado = collect($this->lista([], '101')['dados'])->firstWhere('codCliente', '000001');

        // Lojas contam só as filiais daquele segmento...
        $this->assertSame(2, $drogaria['lojas']);
        $this->assertSame(1, $mercado['lojas']);
        // ...mas a última compra é a de TODAS as filiais, como a pill da Carteira: o
        // cliente comprou há 10 dias pela loja do supermercado, então está ativo.
        $this->assertSame('ativo', $drogaria['status']);
    }

    public function test_lead_que_ja_e_cliente_nao_aparece(): void
    {
        // Mesmo CNPJ de uma filial, com máscara diferente e sem o zero à esquerda.
        $this->lead('DUPLICADO MASCARADO', '22.222.222/0001-01');
        $this->lead('DUPLICADO DIGITOS', '22222222000101');
        $this->lead('NOVO', '55.555.555/0001-01');
        $this->lead('SEM CNPJ', null);
        // Fora da lista: excluído, "Outros" (não é venda) e sem segmento.
        $this->lead('EXCLUIDO', '66666666000101', ['status' => 'excluido']);
        $this->lead('OUTROS', '77777777000101', ['etapa' => 'outros']);
        $this->lead('SEM SEGMENTO', '88888888000101', ['segmento' => null]);

        app(UniversoDoSegmento::class)->reconstruir();

        $leads = collect($this->lista(['status' => 'lead'])['dados'])->pluck('nome')->sort()->values()->all();

        $this->assertSame(['NOVO', 'SEM CNPJ'], $leads);
    }

    public function test_cnpj_sem_o_zero_do_excel_tambem_e_reconhecido_como_cliente(): void
    {
        $this->cliente('000009', '01', '109', '01234567000189', now()->subDays(5));
        // 13 dígitos: o Excel comeu o zero.
        $this->lead('ZERO COMIDO', '1234567000189');

        app(UniversoDoSegmento::class)->reconstruir();

        $this->assertSame(0, $this->lista()['contagens']['lead']);
    }

    // ─── Ordem ──────────────────────────────────────────────────────────────────

    public function test_ordem_e_o_capital_e_sem_capital_vai_para_o_fim(): void
    {
        $this->lead('LEAD DE 2 MI', '55.555.555/0001-01');

        app(UniversoDoSegmento::class)->reconstruir();

        $nomes = collect($this->lista()['dados'])->pluck('nome')->all();

        $this->assertSame(['CLIENTE 000001', 'LEAD DE 2 MI', 'CLIENTE 000002', 'CLIENTE 000003', 'CLIENTE 000004'], $nomes);
    }

    public function test_capital_e_porte_vao_no_formato_do_componente_da_receita(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        $linha = collect($this->lista()['dados'])->firstWhere('codCliente', '000002');

        $this->assertEqualsWithDelta(900_000, $linha['receita']['capitalSocial'], 0.01);
        $this->assertSame('Pequeno porte', $linha['receita']['porte']);
        $this->assertNull(collect($this->lista()['dados'])->firstWhere('codCliente', '000004')['receita']['capitalSocial']);
    }

    // ─── Os números batem com a Carteira ────────────────────────────────────────

    public function test_total_e_cada_status_batem_com_a_carteira_do_segmento(): void
    {
        $this->lead('UM LEAD', '55.555.555/0001-01');
        app(UniversoDoSegmento::class)->reconstruir();

        $contagens = $this->lista()['contagens'];

        $this->assertSame($this->totalDaCarteira(['segmento' => '109']), $contagens['total'] - $contagens['lead']);

        foreach (['ativo', 'inativando', 'inativo'] as $status) {
            $this->assertSame(
                $this->totalDaCarteira(['segmento' => '109', 'status' => $status]),
                $contagens[$status],
                "Tile {$status} diverge da Carteira",
            );
        }

        $this->assertSame(1, $contagens['lead']);
        // Os quatro status somam o total: nenhum cliente fica sem faixa.
        $this->assertSame($contagens['total'], $contagens['ativo'] + $contagens['inativando'] + $contagens['inativo'] + $contagens['lead']);
    }

    public function test_tiles_ignoram_o_filtro_de_status_e_a_lista_bate_com_o_tile(): void
    {
        // Um inativo COM data (400 dias): sem ele, "Perdendo" sem limite inferior passaria.
        $this->cliente('000005', '01', '109', '55555555000202', now()->subDays(400));
        $this->lead('UM LEAD', null);
        $this->rede('REDE PARADA', [], 2);
        // Uma rede ATIVA: sem ela, tirar as redes da contagem dos tiles passaria verde.
        $this->rede('REDE ATIVA', [['cliente', '000004']], 3);
        app(UniversoDoSegmento::class)->reconstruir();

        $semFiltro = $this->lista();

        foreach (MaioresPorSegmentoResolver::FILTROS_STATUS as $status => $incluidos) {
            $filtrada = $this->lista(['status' => $status]);
            $esperado = array_sum(array_map(fn ($s) => $semFiltro['contagens'][$s], $incluidos));

            $this->assertSame($semFiltro['contagens'], $filtrada['contagens'], "Tiles mudaram com o filtro {$status}");
            $this->assertSame($esperado, $filtrada['linhas']->total(), "Lista {$status} diverge do tile");
            $this->assertEmpty(array_diff(collect($filtrada['dados'])->pluck('status')->unique()->all(), $incluidos));
        }
    }

    // ─── Redes ─────────────────────────────────────────────────────────────────

    public function test_redes_vem_primeiro_e_quem_e_da_rede_nao_se_repete_solto(): void
    {
        $raia = $this->rede('RAIA', [['grupo', '100']], 2);
        $vazia = $this->rede('VAZIA', [], 1);

        $ligado = $this->lead('LEAD DA RAIA', '55.555.555/0001-01');
        $ligado->forceFill(['conta_estrategica_id' => $raia->id, 'conta_vinculo' => 'confirmado'])->save();
        $sugerido = $this->lead('LEAD SUGERIDO', '99999999000101');
        $sugerido->forceFill(['conta_estrategica_id' => $raia->id, 'conta_vinculo' => 'sugerido'])->save();

        app(UniversoDoSegmento::class)->reconstruir();

        $dados = collect($this->lista()['dados']);

        // As redes na ordem do ranking (`ordem`), antes de qualquer cliente.
        $this->assertSame(['VAZIA', 'RAIA'], $dados->where('tipo', 'rede')->pluck('nome')->values()->all());
        $this->assertSame(['rede', 'rede'], $dados->take(2)->pluck('tipo')->all());
        $this->assertSame('lead', $dados->firstWhere('nome', 'VAZIA')['status']);

        // 000001 é da RAIA (grupo 100) e o lead confirmado também: estão dentro da linha dela.
        $this->assertNull($dados->firstWhere('codCliente', '000001'));
        $this->assertNull($dados->firstWhere('nome', 'LEAD DA RAIA'));
        // Sugerido ainda não é da rede.
        $this->assertNotNull($dados->firstWhere('nome', 'LEAD SUGERIDO'));
    }

    public function test_todo_cliente_do_segmento_aparece_uma_vez(): void
    {
        $raia = $this->rede('RAIA', [['grupo', '100']]);
        app(UniversoDoSegmento::class)->reconstruir();

        $contagens = $this->lista()['contagens'];
        $naRede = DB::query()
            ->fromSub(app(ClientesDaConta::class)->paresDeTodasAsContas(), 'p')
            ->join('clientes as c', 'c.id', '=', 'p.cliente_id')
            ->where('p.conta_id', $raia->id)
            ->where('c.cod_segmento', '109')
            ->distinct()
            ->count('p.cod_cliente');

        $this->assertSame(1, $naRede);
        $this->assertSame($this->totalDaCarteira(['segmento' => '109']), $contagens['clientes'] + $naRede);
        $this->assertSame(1, $contagens['redes']);
    }

    public function test_filiais_do_cliente_batem_com_a_carteira_que_o_clique_abre(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        // O link da tela: código do cliente + segmento, por filial.
        $linha = collect($this->lista()['dados'])->firstWhere('codCliente', '000001');

        $this->assertSame(2, $linha['lojas']);
        $this->assertSame($linha['lojas'], $this->totalDaCarteira(['busca' => '000001', 'segmento' => '109', 'agrupar' => 0]));
    }

    public function test_busca_e_uf_valem_para_redes_e_clientes(): void
    {
        $this->rede('DROGA RAIA', [['grupo', '100']]);
        $this->rede('PACHECO', [], 2);
        app(UniversoDoSegmento::class)->reconstruir();

        $this->assertSame(['DROGA RAIA'], collect($this->lista(['busca' => 'raia'])['dados'])->pluck('nome')->all());
        // O cliente da rede (000001, "CLIENTE 000001") casa com a busca: quem aparece é a
        // REDE, com o motivo, e ele não reaparece solto (`BuscaNasRedes`).
        $porCliente = collect($this->lista(['busca' => 'CLIENTE'])['dados']);
        $this->assertSame(['DROGA RAIA', 'CLIENTE 000002', 'CLIENTE 000003', 'CLIENTE 000004'], $porCliente->pluck('nome')->all());
        $this->assertSame(['CLIENTE 000001'], $porCliente->first()['encontradoPor']['nomes']);

        // O filtro de UF esconde a DROGA RAIA (sem UF cadastrada), mas o cliente dela (SP)
        // continua sendo dela: não pode reaparecer solto só porque a rede saiu do filtro.
        $soSp = collect($this->lista(['uf' => 'SP'])['dados']);
        $this->assertNull($soSp->firstWhere('nome', 'DROGA RAIA'));
        $this->assertNull($soSp->firstWhere('codCliente', '000001'));
        $this->assertSame(0, $this->lista(['uf' => 'RJ'])['linhas']->total());
    }

    public function test_paginacao_passa_das_redes_para_os_clientes_sem_pular_ninguem(): void
    {
        foreach (range(1, 35) as $i) {
            $this->rede("REDE {$i}", [], $i);
        }
        app(UniversoDoSegmento::class)->reconstruir();

        $p1 = $this->lista(['pagina' => 1]);
        $p2 = $this->lista(['pagina' => 2]);

        $this->assertSame(35 + 4, $p1['linhas']->total());
        $this->assertCount(30, $p1['dados']);
        $this->assertSame('REDE 30', $p1['dados'][29]['nome']);
        // Página 2: as 5 redes que sobraram, depois os 4 clientes por capital.
        $this->assertSame(
            ['REDE 31', 'REDE 32', 'REDE 33', 'REDE 34', 'REDE 35', 'CLIENTE 000001', 'CLIENTE 000002', 'CLIENTE 000003', 'CLIENTE 000004'],
            collect($p2['dados'])->pluck('nome')->all(),
        );
    }

    // ─── Página ─────────────────────────────────────────────────────────────────

    public function test_pagina_manda_a_lista_so_do_segmento_aberto(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        $this->actingAs($this->admin)
            ->get(route('visao-diretor.maiores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('universo', null)
                ->has('abas', 2));

        $this->actingAs($this->admin)
            ->get(route('visao-diretor.maiores.index', ['segmento' => '109', 'status' => 'inativo', 'pagina' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('universo.segmento', '109')
                ->where('universo.filtros.status', 'inativo')
                ->where('universo.contagens.total', 4)
                ->where('universo.linhas.total', 1)
                // Os segmentos com redes na ordem da planilha: Supermercadista é a primeira aba.
                ->where('abas.0.codigo', '101')
                ->where('abas.1.codigo', '109'));
    }

    public function test_segmento_inexistente_cai_no_resumo(): void
    {
        $this->actingAs($this->admin)
            ->get(route('visao-diretor.maiores.index', ['segmento' => '999']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filtros.segmento', '')->where('universo', null));
    }

    public function test_custo_da_pagina_nao_cresce_com_o_numero_de_linhas(): void
    {
        $medir = function (): int {
            app(UniversoDoSegmento::class)->reconstruir();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->lista();

            return count(DB::getQueryLog());
        };

        $criar = function (int $de, int $ate): void {
            foreach (range($de, $ate) as $i) {
                $this->cliente('0001'.$i, '01', '109', '1'.str_pad((string) $i, 13, '0', STR_PAD_LEFT), now()->subDays($i), '200');
                $this->rede("REDE {$i}", [['cliente', '0001'.$i]], $i);
            }
        };

        $criar(10, 49);
        $com40 = $medir();
        $criar(50, 89);

        // O dobro de clientes e de redes não pode virar mais consultas (nada de uma por linha).
        $this->assertSame($com40, $medir());
        $this->assertLessThanOrEqual(20, $com40);
    }

    // ─── Excel ──────────────────────────────────────────────────────────────────

    public function test_excel_leva_o_recorte_filtrado_inteiro(): void
    {
        Storage::fake('local');
        app(UniversoDoSegmento::class)->reconstruir();

        $this->actingAs($this->admin)
            ->post(route('visao-diretor.maiores.exportar-clientes'), ['segmento' => '109', 'status' => 'inativo'])
            ->assertRedirect();

        $exportacao = Exportacao::sole();
        $this->assertSame(Exportacao::STATUS_PRONTO, $exportacao->status, "Erro: {$exportacao->erro}");
        $this->assertSame($this->lista(['status' => 'inativo'])['linhas']->total(), $exportacao->linhas);
        Storage::disk('local')->assertExists($exportacao->caminho);

        // Cabeçalho e linha do mesmo tamanho, para rede e para cliente.
        $this->rede('RAIA', [['grupo', '100']]);
        $export = new ClientesLeadsSegmentoExport('109', []);
        $linhas = $export->array();
        $this->assertSame('Rede', $linhas[0][1]);
        $this->assertSame('Cliente', $linhas[1][1]);
        $this->assertCount(count($export->headings()), $linhas[0]);
        $this->assertCount(count($export->headings()), $linhas[1]);
    }

    public function test_excel_exige_o_gate(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $this->actingAs($vendedor)
            ->post(route('visao-diretor.maiores.exportar-clientes'), ['segmento' => '109'])
            ->assertForbidden();
    }

    public function test_reconstruir_de_novo_nao_duplica(): void
    {
        $servico = app(UniversoDoSegmento::class);
        $servico->reconstruir();
        $antes = DB::table('visao_diretor_universo')->count();
        $servico->reconstruir();

        $this->assertSame($antes, DB::table('visao_diretor_universo')->count());
    }
}
