<?php

namespace Tests\Feature\Performance;

use App\Models\Lead;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O quadro do funil NUNCA carrega a coluna inteira.
 *
 * Em produção são ~17 mil leads, e a esmagadora maioria fica em "Novo" — a base de
 * prospecção importada nunca foi trabalhada. Um quadro que trouxesse a coluna inteira
 * seria um payload de megabytes e uma tela travada, e o defeito passaria despercebido em
 * dev, onde o seed tem poucas dezenas de linhas. É exatamente a Regra de ouro nº 6: o
 * volume é que expõe.
 *
 * ⚠️ Este teste trava o CUSTO, não o resultado. Ele falha se alguém trocar o `limit()`
 * por um `get()` "para simplificar" — que é a mudança mais fácil de fazer sem perceber.
 */
class FunilDeQueriesTest extends TestCase
{
    use RefreshDatabase;

    /** Igual a LeadController::LIMITE_COLUNA. */
    private const LIMITE_COLUNA = 20;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedorCom(int $quantosLeads): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '010617']);
        $this->criarLeads($quantosLeads);

        return $user;
    }

    private function criarLeads(int $quantosLeads): void
    {
        $agora = now();
        $linhas = [];
        for ($i = 0; $i < $quantosLeads; $i++) {
            $linhas[] = [
                'origem' => Lead::ORIGEM_SISTEMA,
                'cod_vendedor' => '010617',
                'nome' => "Contato {$i}",
                'razao_social' => "Mercado {$i} LTDA",
                'status' => 'ativo',
                'etapa' => Lead::ETAPA_NOVO,
                'etapa_alterada_em' => $agora->copy()->subDays($i % 60),
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }
        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('leads')->insert($lote);
        }
    }

    /**
     * Mede SÓ o recarregamento parcial que monta o quadro.
     *
     * ⚠️ Query log, não `DB::listen`: `listen` acumula um listener por chamada e a segunda
     * medição sai inflada — foi o que fez a primeira versão deste teste comparar 78 com
     * 87 e acusar um problema que não existia no código, só no medidor.
     *
     * @return array{queries: int, quadro: array}
     */
    private function abrirQuadro(User $user): array
    {
        $completa = $this->actingAs($user)->get(route('leads.index', ['aba' => 'funil']));
        $versao = $completa->viewData('page')['version'];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resposta = $this->actingAs($user)->get(route('leads.index', ['aba' => 'funil']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $versao,
            'X-Inertia-Partial-Component' => 'Leads/Index',
            'X-Inertia-Partial-Data' => 'funil',
        ])->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['queries' => $queries, 'quadro' => $resposta->json('props.funil')];
    }

    /**
     * ⚠️ O teste central: o custo do quadro tem que ser o MESMO com 30 leads e com 3.000.
     * Se ele crescer, alguém tirou o limite por coluna.
     */
    #[Test]
    public function test_custo_do_quadro_nao_cresce_com_o_volume(): void
    {
        $user = $this->vendedorCom(30);
        $poucos = $this->abrirQuadro($user);

        // Mesmo banco, mesmo usuário: só o volume muda.
        $this->criarLeads(2970);
        $muitos = $this->abrirQuadro($user);

        $this->assertSame(
            $poucos['queries'],
            $muitos['queries'],
            'o número de queries do quadro não pode depender de quantos leads existem',
        );

        foreach ($muitos['quadro']['colunas'] as $coluna) {
            $this->assertLessThanOrEqual(
                self::LIMITE_COLUNA,
                count($coluna['cards']),
                "a coluna {$coluna['etapa']} trouxe mais cards que o limite — o quadro voltou a carregar a coluna inteira",
            );
        }
    }

    /**
     * O total é a contagem REAL da coluna, não o número de cards entregues. Confundir os
     * dois faria o cabeçalho dizer "20" numa coluna de 3 mil, e o "carregar mais"
     * desapareceria — o vendedor nunca alcançaria o resto da carteira.
     */
    #[Test]
    public function test_total_da_coluna_e_a_contagem_real_e_nao_o_tamanho_da_pagina(): void
    {
        $quadro = $this->abrirQuadro($this->vendedorCom(3000))['quadro'];
        $novo = collect($quadro['colunas'])->firstWhere('etapa', Lead::ETAPA_NOVO);

        $this->assertSame(3000, $novo['total']);
        $this->assertCount(self::LIMITE_COLUNA, $novo['cards']);
    }

    /**
     * "Carregar mais" pagina por CURSOR (o id do último card), não por offset: com offset
     * alto o MySQL troca de plano e passa a varrer a tabela — o penhasco medido na
     * Carteira em 2026-08-29 (página 30 = 96 ms, página 40 = 1.084 ms).
     */
    #[Test]
    public function test_carregar_mais_continua_de_onde_parou_sem_repetir_card(): void
    {
        $user = $this->vendedorCom(60);
        $primeira = $this->abrirQuadro($user)['quadro'];
        $novo = collect($primeira['colunas'])->firstWhere('etapa', Lead::ETAPA_NOVO);
        $ultimo = end($novo['cards']);

        $segunda = $this->actingAs($user)
            ->getJson(route('leads.funil.mais', ['etapa' => Lead::ETAPA_NOVO, 'depois' => $ultimo['id']]))
            ->assertOk()
            ->json('cards');

        $idsPrimeira = collect($novo['cards'])->pluck('id');
        $idsSegunda = collect($segunda)->pluck('id');

        $this->assertCount(self::LIMITE_COLUNA, $segunda);
        $this->assertEmpty($idsPrimeira->intersect($idsSegunda), 'a segunda página não pode repetir card da primeira');
    }

    #[Test]
    public function test_etapa_invalida_no_carregar_mais_e_recusada(): void
    {
        $user = $this->vendedorCom(5);

        // Desfecho não é coluna do quadro — pedir "ganho" aqui é chamada malformada.
        $this->actingAs($user)
            ->getJson(route('leads.funil.mais', ['etapa' => Lead::ETAPA_GANHO]))
            ->assertStatus(422);
    }
}
