<?php

namespace Tests\Feature\VisaoDiretor;

use App\Exports\ClientesLeadsSegmentoExport;
use App\Models\Cliente;
use App\Models\Exportacao;
use App\Models\Lead;
use App\Models\Segmento;
use App\Models\User;
use App\Services\VisaoDiretor\UniversoDoSegmento;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Maiores por Segmento → a tabela de cada segmento (`UniversoDoSegmento`), redesenhada em
 * 2026-10-14: clientes e leads, uma linha por EMPRESA (raiz do CNPJ), sem redes.
 *
 * O que estes testes protegem, em ordem de importância:
 *
 * 1. Uma empresa, uma linha: os códigos do TOTVS com a mesma raiz viram uma linha só (era o
 *    "Carrefour em duas páginas"), e as filiais somadas batem com a Carteira que o clique abre.
 * 2. Cada tile de status == a lista filtrada por ele, e o Resumo == os tiles de cada aba.
 * 3. Lead de empresa que já é cliente (mesma raiz) não aparece.
 * 4. O nome fantasia é a marca, não o de uma loja nem um marcador do cadastro.
 */
class UniversoDoSegmentoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
        Segmento::create(['codigo' => '101', 'nome' => 'SUPERMERCADISTA']);

        $hoje = now();
        // Empresa 11111111: DOIS códigos no TOTVS. Duas filiais em drogaria (velhas) pelo
        // 000001, uma em drogaria pelo 000006 e uma em supermercado (recente).
        $this->cliente('000001', '01', '109', '11111111000101', $hoje->copy()->subDays(400), 'RAIA - CENTRO');
        $this->cliente('000001', '02', '109', '11111111000202', $hoje->copy()->subDays(500), 'RAIA - SUL');
        $this->cliente('000006', '01', '109', '11111111000404', $hoje->copy()->subDays(450), 'ENTREGA');
        $this->cliente('000001', '03', '101', '11111111000303', $hoje->copy()->subDays(10), 'RAIA MERCADO');
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

    private function cliente(string $codigo, string $loja, string $segmento, string $cnpj, $ultimaCompra, ?string $fantasia = null): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $codigo,
            'loja' => $loja,
            'cnpj' => $this->mascara($cnpj),
            'razao_social' => "CLIENTE {$codigo}",
            'nome_fantasia' => $fantasia,
            'cod_vendedor' => '000010',
            'cod_segmento' => $segmento,
            'estado' => 'SP',
            'data_ultima_compra' => $ultimaCompra,
        ]);
    }

    private function lead(string $nome, ?string $cnpj, array $extra = []): Lead
    {
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

    // ─── Uma empresa, uma linha ─────────────────────────────────────────────────

    public function test_codigos_da_mesma_raiz_viram_uma_linha(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        $empresa = collect($this->lista()['dados'])->where('buscaCarteira', '11.111.111');

        $this->assertCount(1, $empresa);
        $linha = $empresa->first();
        // As TRÊS filiais de drogaria, dos dois códigos.
        $this->assertSame(3, $linha['filiais']);
        // Âncora = matriz (/0001): é dela a razão social e o CNPJ.
        $this->assertSame('CLIENTE 000001', $linha['nome']);
        $this->assertSame('11.111.111/0001-01', $linha['documento']);
        // A última compra é a de TODAS as filiais da empresa (10 dias, pelo supermercado).
        $this->assertSame('ativo', $linha['status']);
        // Em supermercado a mesma empresa aparece, com a filial de lá.
        $this->assertSame(1, collect($this->lista([], '101')['dados'])->firstWhere('buscaCarteira', '11.111.111')['filiais']);
    }

    public function test_filiais_batem_com_a_carteira_que_o_clique_abre(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        foreach ($this->lista()['dados'] as $linha) {
            if ($linha['tipo'] !== 'cliente') {
                continue;
            }
            $this->assertSame(
                $linha['filiais'],
                $this->totalDaCarteira(['busca' => $linha['buscaCarteira'], 'segmento' => '109', 'agrupar' => 0]),
                "Filiais de {$linha['nome']} divergem da Carteira",
            );
        }

        // E a soma das filiais da aba é a Carteira do segmento, por filial.
        $this->assertSame(
            $this->totalDaCarteira(['segmento' => '109', 'agrupar' => 0]),
            collect($this->lista()['dados'])->sum('filiais'),
        );
    }

    public function test_nome_fantasia_e_a_marca_e_ignora_marcador_do_cadastro(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        // "RAIA - CENTRO" e "RAIA - SUL" → "RAIA"; "ENTREGA" é marcador, não conta.
        $this->assertSame('RAIA', collect($this->lista()['dados'])->firstWhere('buscaCarteira', '11.111.111')['nomeFantasia']);
        // Cliente sem fantasia: só a razão social.
        $this->assertNull(collect($this->lista()['dados'])->firstWhere('buscaCarteira', '22.222.222')['nomeFantasia']);
    }

    public function test_fantasia_que_nao_se_repete_entre_filiais_nao_vira_marca(): void
    {
        // Cada loja com o seu nome (como as do Atacadão): não há marca para mostrar.
        $this->cliente('000007', '01', '109', '77777777000101', now(), 'AV EDUARDO FROES');
        $this->cliente('000007', '02', '109', '77777777000202', now(), 'RUA DAS FLORES');
        app(UniversoDoSegmento::class)->reconstruir();

        $this->assertNull(collect($this->lista()['dados'])->firstWhere('buscaCarteira', '77.777.777')['nomeFantasia']);
    }

    public function test_grupo_e_o_da_matriz_e_clientes_diversos_sai_vazio(): void
    {
        \App\Models\GrupoCliente::create(['codigo' => '100', 'nome' => 'RAIA DROGASIL']);
        \App\Models\GrupoCliente::create(['codigo' => '200', 'nome' => 'OUTRO GRUPO']);
        \App\Models\GrupoCliente::create(['codigo' => '9998', 'nome' => 'CLIENTES DIVERSOS']);
        // A matriz (/0001) é do 100; a outra filial, do 200: vale o da matriz.
        Cliente::where('cnpj', '11.111.111/0001-01')->update(['cod_grupo' => '100']);
        Cliente::where('cnpj', '11.111.111/0002-02')->update(['cod_grupo' => '200']);
        Cliente::where('cod_cliente', '000002')->update(['cod_grupo' => '9998']);
        $this->lead('UM LEAD', '55.555.555/0001-01');
        app(UniversoDoSegmento::class)->reconstruir();

        $dados = collect($this->lista()['dados']);

        $this->assertSame('RAIA DROGASIL', $dados->firstWhere('buscaCarteira', '11.111.111')['grupo']);
        $this->assertNull($dados->firstWhere('buscaCarteira', '22.222.222')['grupo']);
        $this->assertNull($dados->firstWhere('tipo', 'lead')['grupo']);
    }

    // ─── Leads ──────────────────────────────────────────────────────────────────

    public function test_lead_de_empresa_que_ja_e_cliente_nao_aparece(): void
    {
        // Mesmo CNPJ de uma filial, com máscara diferente e sem o zero à esquerda.
        $this->lead('DUPLICADO MASCARADO', '22.222.222/0001-01');
        $this->lead('DUPLICADO DIGITOS', '22222222000101');
        // OUTRA filial de uma empresa que já é cliente: é a mesma empresa, já está na linha dela.
        $this->lead('FILIAL DE CLIENTE', '11.111.111/0099-01');
        $this->lead('NOVO', '55.555.555/0001-01');
        $this->lead('SEM CNPJ', null);
        // Fora da lista: excluído, "Outros" (não é venda) e sem segmento.
        $this->lead('EXCLUIDO', '66666666000101', ['status' => 'excluido']);
        $this->lead('OUTROS', '77777777000101', ['etapa' => 'outros']);
        $this->lead('SEM SEGMENTO', '88888888000101', ['segmento' => null]);

        app(UniversoDoSegmento::class)->reconstruir();

        $leads = collect($this->lista(['status' => 'lead'])['dados']);

        $this->assertSame(['NOVO', 'SEM CNPJ'], $leads->pluck('nome')->sort()->values()->all());
        // Lead não tem filial nossa: a tela mostra "—".
        $this->assertNull($leads->first()['filiais']);
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

    // ─── Os números batem ───────────────────────────────────────────────────────

    public function test_tiles_ignoram_o_filtro_de_status_e_a_lista_bate_com_o_tile(): void
    {
        // Um inativo COM data (400 dias): sem ele, "a trabalhar" sem limite inferior passaria.
        $this->cliente('000005', '01', '109', '55555555000202', now()->subDays(400));
        $this->lead('UM LEAD', null);
        app(UniversoDoSegmento::class)->reconstruir();

        $semFiltro = $this->lista();
        $c = $semFiltro['contagens'];

        // Cada faixa tem ao menos um: sem isso, trocar uma pela outra passaria verde.
        foreach (UniversoDoSegmento::STATUS as $status) {
            $this->assertGreaterThan(0, $c[$status], "Fixture sem ninguém em {$status}");
        }

        foreach (UniversoDoSegmento::STATUS as $status) {
            $filtrada = $this->lista(['status' => $status]);

            $this->assertSame($c, $filtrada['contagens'], "Tiles mudaram com o filtro {$status}");
            $this->assertSame($c[$status], $filtrada['linhas']->total(), "Lista {$status} diverge do tile");
            $this->assertSame([$status], collect($filtrada['dados'])->pluck('status')->unique()->values()->all());
        }

        // As quatro faixas somam o total: ninguém fica sem faixa.
        $this->assertSame($c['total'], $c['ativo'] + $c['inativando'] + $c['inativo'] + $c['lead']);
    }

    public function test_resumo_bate_com_os_tiles_de_cada_aba(): void
    {
        $this->lead('UM LEAD', null);
        app(UniversoDoSegmento::class)->reconstruir();

        $resumo = collect(app(UniversoDoSegmento::class)->resumo())->keyBy('codigo');

        foreach (['109', '101'] as $segmento) {
            $tiles = $this->lista([], $segmento)['contagens'];
            foreach (['total', 'clientes', 'leads', 'ativo', 'inativando', 'inativo', 'lead'] as $chave) {
                $this->assertSame($tiles[$chave], $resumo[$segmento][$chave], "Resumo {$segmento}.{$chave}");
            }
        }

        // Do maior segmento para o menor (é também a ordem das abas).
        $this->assertSame(['109', '101'], $resumo->pluck('codigo')->take(2)->all());
    }

    public function test_busca_e_uf(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        // Busca pelo fantasia de uma loja acha a empresa.
        $this->assertSame(['CLIENTE 000001'], collect($this->lista(['busca' => 'raia'])['dados'])->pluck('nome')->all());
        $this->assertSame(4, $this->lista(['uf' => 'SP'])['linhas']->total());
        $this->assertSame(0, $this->lista(['uf' => 'RJ'])['linhas']->total());
    }

    public function test_paginacao_nao_pula_ninguem(): void
    {
        foreach (range(10, 44) as $i) {
            $this->cliente('0001'.$i, '01', '109', str_pad((string) $i, 8, '9', STR_PAD_LEFT).'000101', now()->subDays($i));
        }
        app(UniversoDoSegmento::class)->reconstruir();

        $p1 = $this->lista(['pagina' => 1]);
        $p2 = $this->lista(['pagina' => 2]);

        $this->assertSame(35 + 4, $p1['linhas']->total());
        $this->assertCount(30, $p1['dados']);
        $this->assertCount(9, $p2['dados']);
        $this->assertSame([], array_intersect(
            collect($p1['dados'])->pluck('chave')->all(),
            collect($p2['dados'])->pluck('chave')->all(),
        ));
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
                ->where('resumo.0.codigo', '109')
                ->where('resumo.0.total', 4));

        $this->actingAs($this->admin)
            ->get(route('visao-diretor.maiores.index', ['segmento' => '109', 'status' => 'inativo', 'pagina' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('universo.segmento', '109')
                ->where('universo.filtros.status', 'inativo')
                ->where('universo.contagens.total', 4)
                ->where('universo.linhas.total', 1));
    }

    public function test_status_desconhecido_vira_sem_filtro(): void
    {
        app(UniversoDoSegmento::class)->reconstruir();

        $this->actingAs($this->admin)
            ->get(route('visao-diretor.maiores.index', ['segmento' => '109', 'status' => 'a_trabalhar']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filtros.status', '')->where('universo.linhas.total', 4));
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
                $this->cliente('0001'.$i, '01', '109', str_pad((string) $i, 8, '8', STR_PAD_LEFT).'000101', now()->subDays($i));
            }
        };

        $criar(10, 49);
        $com40 = $medir();
        // Cada um com a própria raiz: 40 empresas novas (+ as 4 do setUp).
        $this->assertSame(44, $this->lista()['contagens']['clientes']);
        $criar(50, 89);

        // O dobro de clientes não pode virar mais consultas (nada de uma por linha).
        $this->assertSame($com40, $medir());
        $this->assertSame(84, $this->lista()['contagens']['clientes']);
        $this->assertLessThanOrEqual(10, $com40);
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

        // Cabeçalho e linha do mesmo tamanho; a primeira é a empresa de dois códigos.
        $export = new ClientesLeadsSegmentoExport('109', []);
        $linhas = $export->array();
        $this->assertSame(['CLIENTE 000001', 'RAIA', 'Cliente'], array_slice($linhas[0], 0, 3));
        $this->assertSame(3, $linhas[0][6]);
        $this->assertCount(count($export->headings()), $linhas[0]);
    }

    public function test_excel_do_resumo_tem_uma_linha_por_segmento(): void
    {
        Storage::fake('local');
        app(UniversoDoSegmento::class)->reconstruir();

        $this->actingAs($this->admin)
            ->post(route('visao-diretor.maiores.exportar'))
            ->assertRedirect();

        $exportacao = Exportacao::sole();
        $this->assertSame(Exportacao::STATUS_PRONTO, $exportacao->status, "Erro: {$exportacao->erro}");
        $this->assertSame(Segmento::count(), $exportacao->linhas);
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
