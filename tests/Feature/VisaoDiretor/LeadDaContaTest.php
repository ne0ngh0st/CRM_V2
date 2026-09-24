<?php

namespace Tests\Feature\VisaoDiretor;

use App\Models\Cliente;
use App\Models\ContaEstrategica;
use App\Models\GrupoCliente;
use App\Models\Lead;
use App\Models\Notificacao;
use App\Models\Observacao;
use App\Models\Segmento;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\VisaoDiretor\ClientesDaConta;
use App\Services\VisaoDiretor\LeadDaConta;
use App\Services\VisaoDiretor\MaioresPorSegmentoResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Visão Diretor → "Gerar lead": a conta-alvo sem loja nossa vira lead no funil, já na
 * carteira de um responsável.
 *
 * O que estes testes protegem, em ordem de importância:
 *
 * 1. O lead nasce VISÍVEL para o responsável — com o `cod_vendedor` dele. Um lead com
 *    código nulo é o defeito que esta feature existe para evitar: entra e ninguém vê.
 * 2. Um lead por conta. O segundo clique não duplica.
 * 3. Conta que já tem cliente na carteira não vira lead.
 * 4. Só admin/diretor (gate da seção).
 */
class LeadDaContaTest extends TestCase
{
    use RefreshDatabase;

    private Segmento $drogarias;

    private User $admin;

    private User $inaya;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->admin = $this->usuario('admin');
        $this->inaya = $this->usuario('vendedor', '010755', 'Inaya');
        $this->drogarias = Segmento::create(['codigo' => '109', 'nome' => 'DROGARIAS']);
    }

    private function usuario(string $papel, ?string $codVendedor = null, ?string $nome = null, bool $ativo = true): User
    {
        $user = User::factory()->create(['is_active' => $ativo, 'display_name' => $nome]);
        $user->assignRole($papel);

        if ($codVendedor) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor]);
        }

        return $user;
    }

    private function conta(string $nome = 'DROGASIL', array $vinculos = []): ContaEstrategica
    {
        $conta = ContaEstrategica::create([
            'segmento_id' => $this->drogarias->id,
            'nome' => $nome,
            'uf' => 'SP',
            'filiais_mercado' => 1200,
            'site' => 'drogasil.com.br',
            'ordem' => 1,
        ]);

        app(ClientesDaConta::class)->sincronizarVinculos($conta, $vinculos);

        return $conta;
    }

    private function gerar(ContaEstrategica $conta, User $responsavel, ?User $como = null, ?string $recado = null)
    {
        return $this->actingAs($como ?? $this->admin)
            ->from(route('visao-diretor.maiores.index'))
            ->post(route('visao-diretor.maiores.gerar-lead', $conta), [
                'responsavel_id' => $responsavel->id,
                'recado' => $recado,
            ]);
    }

    public function test_lead_nasce_na_carteira_do_responsavel(): void
    {
        $conta = $this->conta();

        $this->gerar($conta, $this->inaya, recado: 'Falar com o comprador regional')
            ->assertRedirect(route('visao-diretor.maiores.index'))
            ->assertSessionHasNoErrors();

        $lead = Lead::sole();
        $this->assertSame('010755', $lead->cod_vendedor);
        $this->assertSame($this->inaya->id, $lead->user_id);
        $this->assertSame(Lead::ORIGEM_MANUAL, $lead->origem);
        $this->assertSame(Lead::ETAPA_NOVO, $lead->etapa);
        $this->assertSame('DROGASIL', $lead->razao_social);
        $this->assertSame('SP', $lead->estado);
        $this->assertSame('DROGARIAS', $lead->segmento);
        $this->assertNotNull($lead->etapa_alterada_em);

        $this->assertSame($lead->id, $conta->fresh()->lead_id);
    }

    /**
     * A prova que importa: o responsável ENXERGA o lead na tela dele. Checar só a coluna
     * não basta — a visibilidade depende do escopo do `LeadController`.
     */
    public function test_responsavel_ve_o_lead_na_tela_de_leads(): void
    {
        $this->gerar($this->conta(), $this->inaya);

        $this->actingAs($this->inaya)
            ->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leads.total', 1)
                ->where('leads.data.0.razaoSocial', 'DROGASIL'));

        $outro = $this->usuario('vendedor', '010999');
        $this->actingAs($outro)
            ->get(route('leads.index'))
            ->assertInertia(fn (Assert $page) => $page->where('leads.total', 0));
    }

    public function test_contexto_e_recado_viram_observacao_do_autor(): void
    {
        $this->gerar($this->conta(), $this->inaya, recado: 'Já pediram cotação de bobina');

        $obs = Observacao::sole();
        $this->assertSame(Lead::sole()->id, $obs->lead_id);
        $this->assertSame($this->admin->id, $obs->user_id, 'O autor é quem abriu o lead, não o responsável.');
        $this->assertStringContainsString('DROGARIAS', $obs->mensagem);
        $this->assertStringContainsString('1.200', $obs->mensagem);
        $this->assertStringContainsString('drogasil.com.br', $obs->mensagem);
        $this->assertStringContainsString('Já pediram cotação de bobina', $obs->mensagem);
    }

    public function test_responsavel_e_avisado_pelo_sino(): void
    {
        $this->gerar($this->conta(), $this->inaya);

        $notificacao = Notificacao::sole();
        $this->assertSame($this->inaya->id, $notificacao->user_id);
        $this->assertSame('lead_conta_alvo', $notificacao->tipo);
        $this->assertSame(Lead::sole()->id, $notificacao->referencia_id);
    }

    public function test_segundo_clique_nao_duplica_o_lead(): void
    {
        $conta = $this->conta();
        $outro = $this->usuario('representante', '020001');

        $this->gerar($conta, $this->inaya)->assertSessionHasNoErrors();
        $this->gerar($conta, $outro)->assertSessionHasErrors('responsavel_id');

        $this->assertSame(1, Lead::count());
        $this->assertSame('010755', Lead::sole()->cod_vendedor);
    }

    public function test_lead_excluido_libera_a_conta_para_gerar_de_novo(): void
    {
        $conta = $this->conta();
        $this->gerar($conta, $this->inaya);
        Lead::sole()->update(['status' => 'excluido']);

        $this->gerar($conta, $this->inaya)->assertSessionHasNoErrors();

        $this->assertSame(2, Lead::count());
        $this->assertSame(Lead::visivel()->sole()->id, $conta->fresh()->lead_id);
    }

    public function test_conta_com_cliente_na_carteira_nao_vira_lead(): void
    {
        GrupoCliente::create(['codigo' => '100', 'nome' => 'RAIA SP']);
        Cliente::create([
            'cod_cliente' => '000001', 'loja' => '01', 'cnpj' => '11.111.111/0001-11',
            'razao_social' => 'RAIA', 'cod_vendedor' => '010755', 'cod_grupo' => '100',
            'cod_segmento' => '109', 'estado' => 'SP',
        ]);
        $conta = $this->conta('RAIA', [['tipo' => 'grupo', 'codigo' => '100']]);

        $this->gerar($conta, $this->inaya)->assertSessionHasErrors('responsavel_id');

        $this->assertSame(0, Lead::count());
    }

    public function test_responsavel_sem_codigo_de_vendedor_e_recusado(): void
    {
        $semCodigo = $this->usuario('supervisor');

        $this->gerar($this->conta(), $semCodigo)->assertSessionHasErrors('responsavel_id');

        $this->assertSame(0, Lead::count());
    }

    public function test_responsavel_inativo_e_recusado(): void
    {
        $inativo = $this->usuario('vendedor', '010111', ativo: false);

        $this->gerar($this->conta(), $inativo)->assertSessionHasErrors('responsavel_id');

        $this->assertSame(0, Lead::count());
    }

    public function test_so_admin_e_diretor_geram(): void
    {
        $conta = $this->conta();

        $this->gerar($conta, $this->inaya, como: $this->inaya)->assertForbidden();
        $this->gerar($conta, $this->inaya, como: $this->usuario('diretor'))->assertSessionHasNoErrors();

        $this->assertSame(1, Lead::count());
    }

    public function test_linha_da_conta_mostra_o_lead_aberto(): void
    {
        $conta = $this->conta();
        $this->assertNull(app(MaioresPorSegmentoResolver::class)->linhas()->firstWhere('id', $conta->id)['leadAberto']);

        $this->gerar($conta, $this->inaya);

        $linha = app(MaioresPorSegmentoResolver::class)->linhas()->firstWhere('id', $conta->id);
        $this->assertSame('Inaya', $linha['leadAberto']['responsavel']);
        $this->assertSame(Lead::ETAPA_NOVO, $linha['leadAberto']['etapa']);
        // Lead aberto não é loja nossa: a conta continua "lead".
        $this->assertSame('lead', $linha['status']);
    }

    public function test_lista_de_responsaveis_so_tem_ativos_com_codigo(): void
    {
        $this->usuario('supervisor');
        $this->usuario('vendedor', '010111', 'Inativo', ativo: false);
        $this->usuario('representante', '020001', 'Beto');

        $nomes = array_column(app(LeadDaConta::class)->responsaveis(), 'nome');

        $this->assertSame(['Beto', 'Inaya'], $nomes);
    }
}
