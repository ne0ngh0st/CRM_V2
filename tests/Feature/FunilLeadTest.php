<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Ligacao;
use App\Models\Orcamento;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O funil de leads.
 *
 * Antes disto, `leads.status` misturava o estado do REGISTRO (ativo/excluído) com o
 * estágio da NEGOCIAÇÃO — por isso "convertido" convivia com "inativo" como se fossem
 * alternativas. Não dava para trabalhar um lead.
 */
class FunilLeadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(string $cod = '010617'): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod]);

        return $user;
    }

    private function lead(string $etapa = Lead::ETAPA_NOVO, string $cod = '010617'): Lead
    {
        return Lead::create([
            'origem' => Lead::ORIGEM_MANUAL,
            'cod_vendedor' => $cod,
            'nome' => 'Contato',
            'razao_social' => 'Mercado Teste LTDA',
            'etapa' => $etapa,
            'etapa_alterada_em' => now()->subDays(10),
        ]);
    }

    // ---------------------------------------------------------------- movimento manual

    #[Test]
    public function test_avancar_pelo_endpoint_move_e_carimba_a_data(): void
    {
        $lead = $this->lead();
        $antes = $lead->etapa_alterada_em;

        $this->actingAs($this->vendedor())
            ->patchJson(route('leads.etapa', $lead), ['etapa' => Lead::ETAPA_EM_CONTATO])
            ->assertOk()
            ->assertJsonPath('etapa', Lead::ETAPA_EM_CONTATO)
            ->assertJsonPath('proximaEtapa', Lead::ETAPA_ORCAMENTO);

        $lead->refresh();
        $this->assertSame(Lead::ETAPA_EM_CONTATO, $lead->etapa);
        $this->assertTrue($lead->etapa_alterada_em->gt($antes), 'a data de mudança tem que ser recarimbada');
    }

    /**
     * ⚠️ Isto é SEGURANÇA, não organização: a etapa chega da requisição e vira valor de um
     * ENUM do MySQL. Sem o Rule::in, um valor arbitrário grava string VAZIA em silêncio
     * fora do modo estrito, e a contagem por coluna do quadro passa a mentir sem nenhum
     * erro aparecer. Mesmo risco da whitelist de ordenação da Carteira.
     */
    #[Test]
    public function test_etapa_invalida_e_recusada_sem_gravar_nada(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->vendedor())
            ->patchJson(route('leads.etapa', $lead), ['etapa' => 'qualquer_coisa'])
            ->assertStatus(422);

        $this->assertSame(Lead::ETAPA_NOVO, $lead->fresh()->etapa);
    }

    /** Perder sem dizer por quê torna o funil inútil como diagnóstico. */
    #[Test]
    public function test_marcar_perdido_exige_motivo(): void
    {
        $lead = $this->lead();
        $vendedor = $this->vendedor();

        $this->actingAs($vendedor)
            ->patchJson(route('leads.etapa', $lead), ['etapa' => Lead::ETAPA_PERDIDO])
            ->assertStatus(422);

        $this->actingAs($vendedor)
            ->patchJson(route('leads.etapa', $lead), ['etapa' => Lead::ETAPA_PERDIDO, 'motivo_perda' => 'Fechou com concorrente'])
            ->assertOk();

        $this->assertSame('Fechou com concorrente', $lead->fresh()->motivo_perda);
    }

    /** Sair de "perdido" tem que limpar o motivo, senão o lead carrega para sempre a
     *  explicação de uma derrota que foi revertida. */
    #[Test]
    public function test_sair_de_perdido_limpa_o_motivo(): void
    {
        $lead = $this->lead(Lead::ETAPA_PERDIDO);
        $lead->forceFill(['motivo_perda' => 'Preço'])->save();

        $this->actingAs($this->vendedor())
            ->patchJson(route('leads.etapa', $lead), ['etapa' => Lead::ETAPA_NEGOCIACAO])
            ->assertOk();

        $this->assertNull($lead->fresh()->motivo_perda);
    }

    /** O escopo por vendedor vale para a escrita — o funil não é porta lateral. */
    #[Test]
    public function test_vendedor_nao_move_lead_de_outra_carteira(): void
    {
        $lead = $this->lead(cod: '999999');

        $this->actingAs($this->vendedor('010617'))
            ->patchJson(route('leads.etapa', $lead), ['etapa' => Lead::ETAPA_EM_CONTATO])
            ->assertForbidden();

        $this->assertSame(Lead::ETAPA_NOVO, $lead->fresh()->etapa);
    }

    /** Mover para a etapa onde já está não é atividade — recarimbar zeraria o
     *  "parado há X dias", que é justamente o indicador de esquecimento. */
    #[Test]
    public function test_reaplicar_a_mesma_etapa_nao_recarimba_a_data(): void
    {
        $lead = $this->lead(Lead::ETAPA_NEGOCIACAO);
        $antes = $lead->etapa_alterada_em;

        $this->actingAs($this->vendedor())
            ->patchJson(route('leads.etapa', $lead), ['etapa' => Lead::ETAPA_NEGOCIACAO])
            ->assertOk();

        $this->assertTrue($lead->fresh()->etapa_alterada_em->eq($antes));
    }

    // ---------------------------------------------------------------- auto-avanço

    /**
     * ⚠️ A REGRA MAIS IMPORTANTE DO FUNIL. Registrar um contato num lead que já está em
     * Negociação NÃO pode puxá-lo de volta para "Em contato": o quadro passaria a brigar
     * com o vendedor, e ele deixaria de usar. Verificado por mutação.
     */
    #[Test]
    #[DataProvider('etapasAFrente')]
    public function test_auto_avanco_nunca_retrocede(string $etapaAtual): void
    {
        $lead = $this->lead($etapaAtual);

        $lead->avancarAutomaticamentePara(Lead::ETAPA_EM_CONTATO);

        $this->assertSame($etapaAtual, $lead->fresh()->etapa);
    }

    /** @return array<string, array{0: string}> */
    public static function etapasAFrente(): array
    {
        return [
            'ja em contato' => [Lead::ETAPA_EM_CONTATO],
            'ja em orcamento' => [Lead::ETAPA_ORCAMENTO],
            'ja em negociacao' => [Lead::ETAPA_NEGOCIACAO],
        ];
    }

    /** Ligar para um cliente ganho não reabre a negociação. */
    #[Test]
    public function test_auto_avanco_nao_toca_lead_com_desfecho(): void
    {
        foreach ([Lead::ETAPA_GANHO, Lead::ETAPA_PERDIDO] as $fechada) {
            $lead = $this->lead($fechada);

            $lead->avancarAutomaticamentePara(Lead::ETAPA_ORCAMENTO);

            $this->assertSame($fechada, $lead->fresh()->etapa);
        }
    }

    /**
     * O gancho real: os botões de telefone/WhatsApp/e-mail já gravam `Ligacao` com
     * `lead_id`, então o funil anda sozinho a partir do que o vendedor já fazia.
     */
    #[Test]
    public function test_registrar_contato_move_o_lead_para_em_contato(): void
    {
        $vendedor = $this->vendedor();
        $lead = $this->lead();

        $this->actingAs($vendedor)
            ->post(route('leads.ligacao', $lead), ['tipo_contato' => 'whatsapp'])
            ->assertRedirect();

        $this->assertSame(Lead::ETAPA_EM_CONTATO, $lead->fresh()->etapa);
    }

    #[Test]
    public function test_contato_em_lead_de_negociacao_nao_o_puxa_de_volta(): void
    {
        $vendedor = $this->vendedor();
        $lead = $this->lead(Lead::ETAPA_NEGOCIACAO);

        $this->actingAs($vendedor)
            ->post(route('leads.ligacao', $lead), ['tipo_contato' => 'telefonica'])
            ->assertRedirect();

        $this->assertSame(Lead::ETAPA_NEGOCIACAO, $lead->fresh()->etapa);
        // E o contato foi registrado do mesmo jeito — o funil não pode engolir atividade.
        $this->assertSame(1, Ligacao::where('lead_id', $lead->id)->count());
    }

    #[Test]
    public function test_orcamento_criado_a_partir_do_lead_avanca_o_funil(): void
    {
        $vendedor = $this->vendedor();
        $lead = $this->lead(Lead::ETAPA_EM_CONTATO);

        $this->actingAs($vendedor)->post(route('orcamentos.store'), [
            'cliente_nome' => 'Mercado Teste LTDA',
            'lead_id' => $lead->id,
            'tipo_frete' => 'CIF',
            'tipo_produto_servico' => 'servico',
            'itens' => [[
                'tipo_item' => 'etiqueta',
                'descricao' => 'ETIQUETA 40X40',
                'quantidade' => 100,
                'valor_unitario' => 1.50,
            ]],
        ])->assertRedirect();

        $this->assertSame(Lead::ETAPA_ORCAMENTO, $lead->fresh()->etapa);
        $this->assertSame($lead->id, Orcamento::latest('id')->first()->lead_id);
    }

    // ---------------------------------------------------------------- o quadro

    /**
     * ⚠️ Trava a armadilha documentada do `Inertia::optional()`: prop opcional NÃO vem em
     * visita completa. Entrar por /leads?aba=funil ou dar F5 traz a página SEM o quadro —
     * é por isso que Leads/Index.vue tem um `onMounted` que pede `only: ['funil']`. Sem
     * essa segunda metade, o funil abriria vazio e pareceria quebrado.
     */
    #[Test]
    public function test_quadro_nao_vem_em_visita_completa_e_vem_no_recarregamento_parcial(): void
    {
        $vendedor = $this->vendedor();
        $this->lead(Lead::ETAPA_NOVO);
        $this->lead(Lead::ETAPA_NOVO);
        $this->lead(Lead::ETAPA_NEGOCIACAO);
        $this->lead(Lead::ETAPA_GANHO);

        $completa = $this->actingAs($vendedor)->get(route('leads.index', ['aba' => 'funil']))->assertOk();
        $this->assertArrayNotHasKey('funil', $completa->viewData('page')['props']);

        $parcial = $this->actingAs($vendedor)->get(route('leads.index', ['aba' => 'funil']), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $completa->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'Leads/Index',
            'X-Inertia-Partial-Data' => 'funil',
        ])->assertOk();

        $funil = $parcial->json('props.funil');
        $this->assertNotNull($funil, 'o recarregamento parcial tem que trazer o quadro');

        $totais = collect($funil['colunas'])->pluck('total', 'etapa');
        $this->assertSame(2, $totais[Lead::ETAPA_NOVO]);
        $this->assertSame(1, $totais[Lead::ETAPA_NEGOCIACAO]);

        // Ganho e perdido NÃO são coluna — viram contador.
        $this->assertCount(count(Lead::ETAPAS_ABERTAS), $funil['colunas']);
        $this->assertSame(1, $funil['fechados']['ganho']);
    }
}
