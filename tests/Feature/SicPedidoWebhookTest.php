<?php

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Models\Orcamento;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O SIC avisa o CRM quando o pedido sai da fila de aprovação (aceito ou recusado pela
 * Laís). Sem isto a recusa não aparecia em lugar nenhum: a API não tem consulta e o
 * pedido recusado nunca chega ao TOTVS.
 */
class SicPedidoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'chave-do-webhook-de-teste';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        config()->set('portal.webhook_token', self::CHAVE);
    }

    /** Orçamento que já virou o pedido 4512, enviado por um admin em nome do vendedor. */
    private function pedidoNoSic(): array
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $orcamento = Orcamento::create([
            'user_id' => $vendedor->id,
            'cliente_nome' => 'CENTRAL SUPERMERCADOS',
            'valor_total' => 2700,
            'nivel_aprovacao' => 'nenhum',
            'status_gestor' => 'aprovado',
        ]);
        $orcamento->forceFill([
            'portal_pedido_id' => 4512,
            'portal_enviado_em' => now(),
            'portal_enviado_por_id' => $admin->id,
            'portal_status' => 'AWAITING_APPROVAL',
        ])->save();

        return [$vendedor, $admin, $orcamento];
    }

    private function avisar(array $corpo, ?string $chave = self::CHAVE)
    {
        $headers = $chave !== null ? ['Authorization' => 'Bearer '.$chave] : [];

        return $this->postJson(route('webhooks.sic-pedidos'), $corpo, $headers);
    }

    public function test_recusa_grava_status_e_motivo_e_avisa_dono_e_quem_enviou(): void
    {
        [$vendedor, $admin, $orcamento] = $this->pedidoNoSic();

        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED', 'reason' => 'Cliente com crédito bloqueado'])
            ->assertOk()
            ->assertJson(['ok' => true, 'orcamento' => $orcamento->id, 'atualizado' => true]);

        $orcamento->refresh();
        $this->assertSame('REJECTED', $orcamento->portal_status);
        $this->assertSame('Cliente com crédito bloqueado', $orcamento->portal_status_motivo);
        $this->assertNotNull($orcamento->portal_status_em);
        $this->assertTrue($orcamento->pedidoRecusadoNoPortal());
        // O número continua: é a referência do pedido do outro lado.
        $this->assertSame(4512, $orcamento->portal_pedido_id);

        foreach ([$vendedor, $admin] as $destinatario) {
            $n = Notificacao::where('user_id', $destinatario->id)->sole();
            $this->assertSame('portal_pedido_recusado', $n->tipo);
            $this->assertStringContainsString('4512 recusado', $n->titulo);
            $this->assertSame('Cliente com crédito bloqueado', $n->mensagem);
        }
    }

    public function test_aprovacao_tambem_avisa(): void
    {
        [$vendedor] = $this->pedidoNoSic();

        $this->avisar(['orderId' => 4512, 'status' => 'approved'])->assertOk();

        $n = Notificacao::where('user_id', $vendedor->id)->sole();
        $this->assertSame('portal_pedido_status', $n->tipo);
        $this->assertStringContainsString('4512 aprovado', $n->titulo);
    }

    public function test_o_mesmo_aviso_repetido_nao_gera_segunda_notificacao(): void
    {
        [$vendedor] = $this->pedidoNoSic();

        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED', 'reason' => 'Sem estoque'])->assertOk();
        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED', 'reason' => 'Sem estoque'])
            ->assertOk()
            ->assertJson(['atualizado' => false]);

        $this->assertSame(1, Notificacao::where('user_id', $vendedor->id)->count());
    }

    public function test_status_novo_depois_de_outro_avisa_de_novo(): void
    {
        [$vendedor] = $this->pedidoNoSic();

        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED', 'reason' => 'Sem estoque'])->assertOk();
        $this->avisar(['orderId' => 4512, 'status' => 'APPROVED'])->assertOk();

        $this->assertSame(2, Notificacao::where('user_id', $vendedor->id)->count());
        $this->assertFalse(Orcamento::where('portal_pedido_id', 4512)->sole()->pedidoRecusadoNoPortal());
    }

    public function test_acha_o_orcamento_pela_referencia_quando_falta_o_id(): void
    {
        [, , $orcamento] = $this->pedidoNoSic();

        $this->avisar(['orderReference' => 'ORC-'.$orcamento->id, 'status' => 'REJECTED'])
            ->assertOk()
            ->assertJson(['orcamento' => $orcamento->id]);

        $this->assertSame('REJECTED', $orcamento->fresh()->portal_status);
    }

    public function test_pedido_desconhecido_responde_ok_sem_mexer_em_nada(): void
    {
        [, , $orcamento] = $this->pedidoNoSic();

        $this->avisar(['orderId' => 999, 'status' => 'REJECTED'])
            ->assertOk()
            ->assertJson(['ignorado' => 'pedido_desconhecido']);

        $this->assertSame('AWAITING_APPROVAL', $orcamento->fresh()->portal_status);
        $this->assertSame(0, Notificacao::count());
    }

    public function test_referencia_de_orcamento_que_nao_virou_pedido_e_ignorada(): void
    {
        [, , $orcamento] = $this->pedidoNoSic();
        $outro = Orcamento::create([
            'user_id' => $orcamento->user_id,
            'cliente_nome' => 'OUTRO',
            'valor_total' => 1,
            'nivel_aprovacao' => 'nenhum',
            'status_gestor' => 'aprovado',
        ]);

        $this->avisar(['orderReference' => 'ORC-'.$outro->id, 'status' => 'REJECTED'])
            ->assertJson(['ignorado' => 'pedido_desconhecido']);

        $this->assertNull($outro->fresh()->portal_status);
    }

    public function test_chave_errada_ou_ausente_e_recusada(): void
    {
        [, , $orcamento] = $this->pedidoNoSic();

        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED'], 'outra-chave')->assertUnauthorized();
        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED'], null)->assertUnauthorized();

        $this->assertSame('AWAITING_APPROVAL', $orcamento->fresh()->portal_status);
    }

    public function test_sem_chave_configurada_o_webhook_fica_fechado(): void
    {
        $this->pedidoNoSic();
        config()->set('portal.webhook_token', '');

        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED'], '')->assertStatus(503);
    }

    public function test_payload_invalido_responde_422_em_json_mesmo_sem_accept(): void
    {
        $this->pedidoNoSic();

        $this->post(route('webhooks.sic-pedidos'), ['status' => 'REJECTED'], ['Authorization' => 'Bearer '.self::CHAVE])
            ->assertStatus(422)
            ->assertJson(['erro' => 'payload_invalido']);
    }

    public function test_tela_de_orcamentos_mostra_a_recusa(): void
    {
        [$vendedor] = $this->pedidoNoSic();
        $this->avisar(['orderId' => 4512, 'status' => 'REJECTED', 'reason' => 'Sem estoque'])->assertOk();

        $this->actingAs($vendedor)->get(route('orcamentos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orcamentos.data.0.portalStatusRotulo', 'Recusado')
                ->where('orcamentos.data.0.portalStatusMotivo', 'Sem estoque')
                ->where('orcamentos.data.0.portalRecusado', true)
                ->etc());
    }
}
