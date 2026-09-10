<?php

namespace Tests\Feature;

use App\Jobs\EnviarPedidoAoPortalJob;
use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\PortalCliente;
use App\Models\PortalProduto;
use App\Models\PortalRepresentante;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Portal\GeradorDePedidoNoPortal;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Transformar em pedido": do clique até o pedido criado no Portal.
 *
 * O caso mais importante deste arquivo é `test_cnpj_divergente_bloqueia_o_envio`.
 * Ele existe porque foi MEDIDO que há par `code`+`store` apontando para empresas
 * diferentes nos dois sistemas — e um `clientId` errado cria pedido para a empresa
 * errada devolvendo `201` normalmente.
 */
class PortalPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config()->set('portal.habilitado', true);
        config()->set('portal.token', 'token-de-teste');
        config()->set('portal.preco_com_ipi', false);
    }

    private function vendedor(): User
    {
        $user = User::factory()->create(['is_active' => true, 'email' => 'vend@autopel.com']);
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '010150']);

        return $user;
    }

    /**
     * 🚧 Durante a homologação SÓ ADMIN dispara o envio, então é ele quem clica na
     * maioria dos testes. Quando a restrição cair, o dono do orçamento volta a valer.
     */
    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        return $admin;
    }

    /** Monta o cenário inteiro já ligado: cliente, de-para, orçamento aprovado e item. */
    private function cenario(array $sobrescreve = []): array
    {
        $vendedor = $this->vendedor();

        $cliente = Cliente::create([
            'cod_cliente' => '041626',
            'loja' => '0002',
            'razao_social' => 'CENTRAL SUPERMERCADOS',
            'cnpj' => '08.019.075/0002-07',
            'cod_vendedor' => '010150',
        ]);

        PortalCliente::create([
            'portal_id' => 501,
            'code' => '041626',
            'store' => '0002',
            // Só dígitos, como o Portal guarda — a nossa vem mascarada.
            'document' => $sobrescreve['documentPortal'] ?? '08019075000207',
            'razao_social' => 'CENTRAL SUPERMERCADOS',
        ]);

        $portalUsuario = PortalUsuario::create([
            'portal_id' => 900,
            'email' => 'vend@autopel.com',
            'protheus_seller_code' => '010150',
            'ativo' => true,
        ]);

        if ($sobrescreve['comRepresentante'] ?? true) {
            PortalRepresentante::create([
                'portal_id' => 700,
                'portal_cliente_id' => 501,
                'portal_usuario_id' => $portalUsuario->portal_id,
            ]);
        }

        PortalProduto::create(['portal_id' => 811, 'code' => 'V23730', 'descricao' => 'BOBINA']);

        $orcamento = Orcamento::create([
            'user_id' => $vendedor->id,
            'cliente_id' => $sobrescreve['semCliente'] ?? false ? null : $cliente->id,
            'cliente_nome' => 'CENTRAL SUPERMERCADOS',
            'cliente_cnpj' => '08019075000207',
            'tipo_produto_servico' => 'servico',
            'valor_total' => 2700,
            'nivel_aprovacao' => 'nenhum',
            'status_gestor' => $sobrescreve['status'] ?? 'aprovado',
        ]);

        OrcamentoItem::create([
            'orcamento_id' => $orcamento->id,
            'cod_produto' => 'V23730',
            'descricao' => 'BOBINA TS KPH BC 80X40M',
            'quantidade' => 900,
            'valor_unitario' => 3.00,
            'valor_total' => 2700,
        ]);

        return [$vendedor, $orcamento->fresh()];
    }

    /**
     * 🚨 Regressão do buraco que fazia TODO orçamento falhar no primeiro passo: a coluna
     * `cliente_id` existia, mas nada no fluxo de criação a escrevia — a busca de cliente
     * nem devolvia o id. O documento nascia só com nome e CNPJ em texto e nunca virava
     * pedido, com a mensagem "não está vinculado a um cliente do TOTVS".
     */
    public function test_orcamento_novo_grava_o_vinculo_com_o_cliente(): void
    {
        $vendedor = $this->vendedor();

        $cliente = Cliente::create([
            'cod_cliente' => '041626',
            'loja' => '0002',
            'razao_social' => 'CENTRAL SUPERMERCADOS',
            'cnpj' => '08.019.075/0002-07',
            'cod_vendedor' => '010150',
        ]);

        $this->actingAs($vendedor)->post(route('orcamentos.store'), [
            'cliente_id' => $cliente->id,
            'cliente_nome' => 'CENTRAL SUPERMERCADOS',
            'tipo_frete' => 'CIF',
            'tipo_produto_servico' => 'servico',
            'itens' => [[
                'tipo_item' => 'bobina',
                'cod_produto' => 'V23730',
                'descricao' => 'BOBINA',
                'quantidade' => 900,
                'valor_unitario' => 3.00,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('orcamentos', [
            'cliente_id' => $cliente->id,
            'cliente_nome' => 'CENTRAL SUPERMERCADOS',
        ]);
    }

    /** A busca do formulário precisa devolver o id, senão o vínculo acima é impossível. */
    public function test_busca_de_cliente_devolve_o_id_para_o_formulario(): void
    {
        $vendedor = $this->vendedor();

        $cliente = Cliente::create([
            'cod_cliente' => '041626',
            'loja' => '0002',
            'razao_social' => 'CENTRAL SUPERMERCADOS',
            'cnpj' => '08.019.075/0002-07',
            'cod_vendedor' => '010150',
        ]);

        $this->actingAs($vendedor)
            ->getJson(route('orcamentos.buscaClientes', ['q' => 'CENTRAL']))
            ->assertOk()
            ->assertJsonFragment(['origem' => 'cliente', 'clienteId' => $cliente->id]);
    }

    public function test_rota_nao_existe_com_a_integracao_desligada(): void
    {
        config()->set('portal.habilitado', false);
        [$vendedor, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id))
            ->assertNotFound();
    }

    public function test_orcamento_pendente_nao_vira_pedido(): void
    {
        [$vendedor, $orcamento] = $this->cenario(['status' => 'pendente']);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id))
            ->assertForbidden();
    }

    /**
     * 🚧 A restrição de homologação é de VERDADE: guarda a rota, não só esconde o botão.
     * Sem isto o vendedor poderia disparar um POST direto e criar pedido no Portal.
     */
    public function test_durante_a_homologacao_o_dono_do_orcamento_nao_envia(): void
    {
        [$vendedor, $orcamento] = $this->cenario();

        $this->actingAs($vendedor)
            ->post(route('orcamentos.portal', $orcamento->id))
            ->assertForbidden();
    }

    public function test_vendedor_de_fora_tambem_nao_envia(): void
    {
        [, $orcamento] = $this->cenario();
        $outro = User::factory()->create(['is_active' => true]);
        $outro->assignRole('vendedor');

        $this->actingAs($outro)
            ->post(route('orcamentos.portal', $orcamento->id))
            ->assertForbidden();
    }

    /** O dono vê o botão, desabilitado, como aviso de que a função está chegando. */
    public function test_dono_ve_o_botao_como_em_breve_e_admin_ve_habilitado(): void
    {
        [$vendedor, $orcamento] = $this->cenario();

        $this->actingAs($vendedor)
            ->get(route('orcamentos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orcamentos.data.0.portalEmBreve', true)
                ->where('orcamentos.data.0.podeEnviarAoPortal', false));

        $this->actingAs($this->admin())
            ->get(route('orcamentos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orcamentos.data.0.portalEmBreve', false)
                ->where('orcamentos.data.0.podeEnviarAoPortal', true));
    }

    /**
     * 🚨 O teste que justifica a guarda existir. Sem ele, o pedido nasceria para a
     * empresa errada e o `201` voltaria bonito.
     */
    public function test_cnpj_divergente_bloqueia_o_envio(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario(['documentPortal' => '99999999000199']);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id))
            ->assertRedirect();

        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);
        $this->assertNull($orcamento->fresh()->portal_idempotency_key);
    }

    public function test_orcamento_sem_cliente_vinculado_e_recusado(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario(['semCliente' => true]);

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id));

        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);
    }

    public function test_representante_nao_vinculado_e_recusado_antes_de_chamar_a_api(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario(['comRepresentante' => false]);

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id));

        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);
    }

    public function test_caminho_feliz_congela_o_payload_e_enfileira(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id))
            ->assertRedirect();

        Bus::assertDispatched(EnviarPedidoAoPortalJob::class);

        $orcamento->refresh();
        $this->assertNotNull($orcamento->portal_idempotency_key);
        // Centavos, não reais: R$ 3,00 × 900.
        $this->assertSame(300, $orcamento->portal_payload['products'][0]['unitPrice']);
        $this->assertSame(900, $orcamento->portal_payload['products'][0]['quantity']);
        $this->assertSame(501, $orcamento->portal_payload['clientId']);
        $this->assertSame(700, $orcamento->portal_payload['clientRepresentativeId']);
        $this->assertSame(900, $orcamento->portal_payload['createdBy']);
    }

    /**
     * 🚨 Retentar com chave NOVA é o único caminho que ainda duplica pedido no Portal.
     */
    public function test_segunda_tentativa_reusa_a_mesma_chave(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id));
        $chave = $orcamento->fresh()->portal_idempotency_key;

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id));

        $this->assertSame($chave, $orcamento->fresh()->portal_idempotency_key);
    }

    public function test_job_grava_o_numero_do_pedido_e_avisa(): void
    {
        Http::fake([
            '*/v1/api/orders' => Http::response(['payload' => ['id' => 4512]], 201),
        ]);

        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento);
        $gerador->enviar($orcamento->fresh());

        $orcamento->refresh();
        $this->assertSame(4512, $orcamento->portal_pedido_id);
        $this->assertNull($orcamento->portal_erro);
        $this->assertNotNull($orcamento->portal_enviado_em);

        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $vendedor->id,
            'tipo' => 'portal_pedido_criado',
        ]);
    }

    /**
     * A mensagem do Portal é propagada LITERALMENTE — "Cliente ainda está como
     * prospect" diz mais a quem opera do que qualquer tradução nossa.
     */
    public function test_recusa_do_portal_guarda_o_motivo_e_nao_marca_como_enviado(): void
    {
        Http::fake([
            '*/v1/api/orders' => Http::response([
                'statusCode' => 409,
                'message' => 'Cliente ainda está como prospect',
            ], 409),
        ]);

        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento);
        $gerador->enviar($orcamento->fresh());

        $orcamento->refresh();
        $this->assertNull($orcamento->portal_pedido_id);
        $this->assertStringContainsString('prospect', $orcamento->portal_erro);

        $this->assertDatabaseHas('notificacoes', [
            'user_id' => $vendedor->id,
            'tipo' => 'portal_pedido_erro',
        ]);
    }

    public function test_orcamento_ja_enviado_nao_vai_de_novo(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario();
        $orcamento->forceFill(['portal_pedido_id' => 999])->save();

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id));

        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);
    }

    public function test_erro_do_portal_notifica_toda_vez_e_nao_e_deduplicado(): void
    {
        Http::fake([
            '*/v1/api/orders' => Http::response(['message' => 'Representante não encontrado'], 404),
        ]);

        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento);
        $gerador->enviar($orcamento->fresh());
        $gerador->enviar($orcamento->fresh());

        // Se a chave de idempotência do NotificacaoService fosse usada aqui, a segunda
        // falha ficaria muda e o vendedor concluiria que deu certo.
        $this->assertSame(2, Notificacao::where('user_id', $vendedor->id)
            ->where('tipo', 'portal_pedido_erro')->count());
    }
}
