<?php

namespace Tests\Feature;

use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aceite do CLIENTE no orçamento (2026-10-15) — separado da aprovação de MARGEM.
 *
 * O vendedor registra a qualquer momento; recusa exige motivo; pedido só com as duas
 * aprovações. O teste que sustenta o card é `test_todo_tile_novo_bate_com_a_lista`.
 *
 * ⚠️ Fixture com contagens TODAS diferentes (aceitou 2, recusou 3, aguardando 4, pedido 1)
 * — com números iguais, trocar um filtro pelo outro passaria verde.
 */
class OrcamentoRespostaClienteTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
    }

    private function orcamento(string $margem = 'pendente', array $extra = []): Orcamento
    {
        $orcamento = Orcamento::create([
            'user_id' => $this->vendedor->id,
            'cliente_nome' => 'CLIENTE TESTE',
            'valor_total' => $extra['valor'] ?? 100,
            'tipo_produto_servico' => 'servico',
            'tipo_frete' => 'CIF',
            'tipo_venda' => 'consumo',
            'condicao_pagamento_codigo' => '028',
            'status_gestor' => $margem,
            'nivel_aprovacao' => $margem === 'pendente' ? 'supervisor' : 'nenhum',
        ]);

        OrcamentoItem::create([
            'orcamento_id' => $orcamento->id,
            'tipo_item' => 'outro',
            'descricao' => 'ITEM',
            'quantidade' => 1,
            'valor_unitario' => 100,
            'valor_total' => 100,
        ]);

        $orcamento->forceFill(array_intersect_key($extra, array_flip(['resposta_cliente', 'portal_pedido_id'])))->save();

        return $orcamento->fresh();
    }

    private function responder(Orcamento $orcamento, array $dados, ?User $quem = null)
    {
        return $this->actingAs($quem ?? $this->vendedor)
            ->patch(route('orcamentos.respostaCliente', $orcamento->id), $dados);
    }

    public function test_vendedor_registra_o_aceite_mesmo_com_a_margem_pendente(): void
    {
        $orcamento = $this->orcamento('pendente');

        $this->responder($orcamento, ['resposta' => 'aprovado'])->assertSessionHasNoErrors();

        $orcamento->refresh();
        $this->assertSame('aprovado', $orcamento->resposta_cliente);
        $this->assertSame($this->vendedor->id, $orcamento->resposta_cliente_por_id);
        $this->assertNotNull($orcamento->resposta_cliente_em);
    }

    public function test_recusa_sem_motivo_e_recusada(): void
    {
        $orcamento = $this->orcamento();

        $this->responder($orcamento, ['resposta' => 'recusado'])->assertSessionHasErrors('motivo');
        $this->assertNull($orcamento->fresh()->resposta_cliente);
    }

    public function test_motivo_fora_da_lista_e_recusado(): void
    {
        $orcamento = $this->orcamento();

        $this->responder($orcamento, ['resposta' => 'recusado', 'motivo' => 'inventado'])->assertSessionHasErrors('motivo');
        $this->assertNull($orcamento->fresh()->resposta_cliente);
    }

    public function test_outros_exige_texto_e_o_texto_vira_o_rotulo(): void
    {
        $orcamento = $this->orcamento();

        $this->responder($orcamento, ['resposta' => 'recusado', 'motivo' => 'outros'])->assertSessionHasErrors('motivo_texto');
        $this->assertNull($orcamento->fresh()->resposta_cliente);

        $this->responder($orcamento, ['resposta' => 'recusado', 'motivo' => 'outros', 'motivo_texto' => 'Comprou estoque no mês passado'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Recusou: Comprou estoque no mês passado', $orcamento->fresh()->rotuloRespostaCliente());
    }

    public function test_recusa_com_motivo_da_lista(): void
    {
        $orcamento = $this->orcamento();

        $this->responder($orcamento, ['resposta' => 'recusado', 'motivo' => 'preco'])->assertSessionHasNoErrors();

        $orcamento->refresh();
        $this->assertSame('recusado', $orcamento->resposta_cliente);
        $this->assertSame('Recusou: Preço', $orcamento->rotuloRespostaCliente());
    }

    public function test_trocar_para_aceite_limpa_o_motivo(): void
    {
        $orcamento = $this->orcamento();
        $this->responder($orcamento, ['resposta' => 'recusado', 'motivo' => 'outros', 'motivo_texto' => 'x']);

        $this->responder($orcamento, ['resposta' => 'aprovado', 'motivo' => 'preco']);

        $orcamento->refresh();
        $this->assertSame('aprovado', $orcamento->resposta_cliente);
        $this->assertNull($orcamento->motivo_recusa_cliente);
        $this->assertNull($orcamento->motivo_recusa_cliente_texto);
    }

    public function test_vendedor_de_fora_nao_registra(): void
    {
        $orcamento = $this->orcamento();
        $outro = User::factory()->create(['is_active' => true]);
        $outro->assignRole('vendedor');

        $this->responder($orcamento, ['resposta' => 'aprovado'], $outro)->assertForbidden();
        $this->assertNull($orcamento->fresh()->resposta_cliente);
    }

    public function test_depois_de_virar_pedido_trava(): void
    {
        $orcamento = $this->orcamento('aprovado', ['resposta_cliente' => 'aprovado', 'portal_pedido_id' => 1133]);

        $this->responder($orcamento, ['resposta' => 'recusado', 'motivo' => 'preco'])->assertStatus(409);
        $this->assertSame('aprovado', $orcamento->fresh()->resposta_cliente);
    }

    /** 🚨 A trava do pedido: valem as DUAS aprovações, e ela guarda a rota, não só o botão. */
    public function test_pedido_exige_margem_e_aceite(): void
    {
        config()->set('portal.habilitado', true);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $semAceite = $this->orcamento('aprovado');
        $semMargem = $this->orcamento('pendente', ['resposta_cliente' => 'aprovado']);

        $this->actingAs($admin)->post(route('orcamentos.portal', $semAceite->id))->assertForbidden();
        $this->actingAs($admin)->post(route('orcamentos.portal', $semMargem->id))->assertForbidden();

        $this->assertSame('Falta registrar o aceite do cliente.', $semAceite->bloqueioDoPedido());
        $this->assertSame('Falta a aprovação de margem.', $semMargem->bloqueioDoPedido());

        $semAceite->forceFill(['resposta_cliente' => 'aprovado'])->save();
        $this->assertNull($semAceite->fresh()->bloqueioDoPedido());
    }

    public function test_botao_desabilitado_diz_o_que_falta(): void
    {
        config()->set('portal.habilitado', true);
        $this->orcamento('aprovado');

        $this->actingAs($this->vendedor)
            ->get(route('orcamentos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orcamentos.data.0.portalBloqueio', 'Falta registrar o aceite do cliente.')
                ->where('orcamentos.data.0.podeEnviarAoPortal', false)
                ->where('orcamentos.data.0.podeRegistrarRespostaCliente', true));
    }

    /** Editar depois do aceite volta a "aguardando": o cliente aceitou outro documento. */
    public function test_editar_o_orcamento_zera_o_aceite(): void
    {
        $orcamento = $this->orcamento('aprovado', ['resposta_cliente' => 'aprovado']);

        $this->actingAs($this->vendedor)->patch(route('orcamentos.update', $orcamento->id), [
            'cliente_nome' => 'CLIENTE TESTE',
            'condicao_pagamento_codigo' => '028',
            'tipo_frete' => 'CIF',
            'tipo_venda' => 'consumo',
            'tipo_produto_servico' => 'servico',
            'itens' => [[
                'tipo_item' => 'outro',
                'descricao' => 'ITEM',
                'quantidade' => 2,
                'valor_unitario' => 100,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertNull($orcamento->fresh()->resposta_cliente);
    }

    /**
     * 🚨 O funil: cada orçamento numa etapa só, as etapas somam o total, e cada etapa abre
     * exatamente aquele tanto de linhas. Fixture com contagens todas diferentes (1/2/3/4/5),
     * e com os casos de precedência: aceite antecipado com margem pendente fica em
     * "aguardando margem"; recusa do cliente é "perdido" mesmo com a margem pendente; pedido vence tudo.
     */
    public function test_funil_soma_o_total_e_cada_etapa_bate_com_a_lista(): void
    {
        $this->orcamento('aprovado', ['resposta_cliente' => 'aprovado']);                              // pronto
        foreach (range(1, 2) as $_) {
            $this->orcamento('aprovado', ['resposta_cliente' => 'aprovado', 'portal_pedido_id' => 1200]); // pedido
        }
        foreach (range(1, 3) as $_) {
            $this->orcamento('aprovado');                                                             // aguardando cliente
        }
        $this->orcamento('pendente', ['resposta_cliente' => 'aprovado']);                              // aguardando margem
        foreach (range(1, 3) as $_) {
            $this->orcamento('pendente');                                                             // aguardando margem
        }
        foreach (range(1, 3) as $_) {
            $this->orcamento('rejeitado');                                                            // perdido
        }
        $this->orcamento('aprovado', ['resposta_cliente' => 'recusado']);                              // perdido
        $this->orcamento('pendente', ['resposta_cliente' => 'recusado']);                              // perdido: um "não" basta

        $kpis = $this->props([])['kpis'];
        $porEtapa = array_column($kpis['etapas'], 'qtd', 'chave');

        $this->assertSame([
            'aguardando_margem' => 4,
            'aguardando_cliente' => 3,
            'pronto' => 1,
            'pedido' => 2,
            'perdido' => 5,
        ], $porEtapa);
        $this->assertSame($kpis['total'], array_sum($porEtapa));

        foreach ($porEtapa as $chave => $qtd) {
            $this->assertSame($qtd, $this->total(['etapa' => $chave]), "etapa {$chave}");
        }

        // O funil não se filtra pela etapa que ele desenha.
        $this->assertSame(4, array_column($this->props(['etapa' => 'pronto'])['kpis']['etapas'], 'qtd', 'chave')['aguardando_margem']);
        // Etapa fora da lista não vira condição.
        $this->assertSame($kpis['total'], $this->total(['etapa' => 'inventada']));
    }

    /** @return array<string, mixed> */
    private function props(array $query): array
    {
        return $this->actingAs($this->vendedor)
            ->get(route('orcamentos.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    private function total(array $query): int
    {
        return $this->props($query)['orcamentos']['total'];
    }
}
