<?php

namespace Tests\Feature;

use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Copiar (ou editar) um orçamento importado do legado.
 *
 * Reportado pelo Tony em 18/09/2026: "ao copiar o orçamento 2039, quando clico em criar
 * orçamento nada acontece". Os 2.127 orçamentos históricos têm `tipo_item` NULO em todos
 * os itens e `tipo_frete` NULO. O formulário repassava o null, o servidor recusava
 * `itens.*.tipo_item`, e esse campo não tem mensagem na folha — o erro existia, só não
 * aparecia em lugar nenhum.
 *
 * Os testes percorrem o caminho real: o que `novo` entrega ao formulário volta, do jeito
 * que o formulário monta, para o `store`. Testar só um dos lados passaria verde com o bug.
 */
class OrcamentoCopiaLegadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '010617', 'cod_super' => '000006']);

        return $user;
    }

    /** O formato exato do orçamento 2039 de produção. */
    private function orcamentoLegado(User $dono): Orcamento
    {
        $orcamento = Orcamento::create([
            'user_id' => $dono->id,
            'cliente_nome' => 'DROGARIA DROGACENTER EXPRESS LTDA',
            'cliente_cnpj' => '18824134000192',
            'forma_pagamento' => '30/60/90',
            'tipo_frete' => null,
            'tipo_produto_servico' => 'servico',
            'valor_total' => 22698.00,
            'desconto_pct_max' => 0,
            'nivel_aprovacao' => 'nenhum',
            'status_gestor' => 'aprovado',
        ]);

        OrcamentoItem::create([
            'orcamento_id' => $orcamento->id,
            'tipo_item' => null,
            'cod_produto' => 'VXXX',
            'descricao' => 'ETIQUETA PERSONALIZADA 40X40 COUCHE 4 CORES',
            'quantidade' => 11640,
            'valor_unitario' => 1.95,
            'valor_total' => 22698.00,
            'preco_tabela' => null,
            'calcula_ipi' => true,
        ]);

        return $orcamento;
    }

    /**
     * Reproduz o `useForm` do Form.vue a partir do que o servidor entregou.
     *
     * @param  array<string, mixed>  $fonte
     * @return array<string, mixed>
     */
    private function payloadDoFormulario(array $fonte): array
    {
        return [
            'cliente_nome' => $fonte['clienteNome'],
            'cliente_cnpj' => $fonte['clienteCnpj'] ?? '',
            'forma_pagamento' => $fonte['formaPagamento'] ?? '',
            'tipo_frete' => $fonte['tipoFrete'] ?? 'CIF',
            'tipo_produto_servico' => $fonte['tipoProdutoServico'] ?? 'produto',
            'data_validade' => $fonte['dataValidade'],
            'itens' => array_map(fn (array $i) => [
                'tipo_item' => $i['tipoItem'],
                'cod_produto' => $i['codProduto'] ?? '',
                'descricao' => $i['descricao'],
                'quantidade' => (string) $i['quantidade'],
                'valor_unitario' => (string) $i['valorUnitario'],
                'preco_tabela' => $i['precoTabela'] !== null ? (string) $i['precoTabela'] : '',
                'calcula_ipi' => $i['calculaIpi'],
            ], $fonte['itens']),
        ];
    }

    #[Test]
    public function copiar_orcamento_legado_entrega_item_com_tipo_valido(): void
    {
        $user = $this->vendedor();
        $origem = $this->orcamentoLegado($user);

        $this->actingAs($user)
            ->get(route('orcamentos.novo', ['copiar_de' => $origem->id]))
            ->assertInertia(fn ($page) => $page->where('copiaDe.itens.0.tipoItem', 'outro'));
    }

    #[Test]
    public function a_copia_de_orcamento_legado_salva(): void
    {
        $user = $this->vendedor();
        $origem = $this->orcamentoLegado($user);

        $fonte = null;
        $this->actingAs($user)
            ->get(route('orcamentos.novo', ['copiar_de' => $origem->id]))
            ->assertInertia(function ($page) use (&$fonte) {
                $fonte = $page->toArray()['props']['copiaDe'];
            });

        $this->actingAs($user)
            ->post(route('orcamentos.store'), $this->payloadDoFormulario($fonte))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('orcamentos.index'));

        $copia = Orcamento::query()->whereKeyNot($origem->id)->with('itens')->firstOrFail();
        $this->assertSame('outro', $copia->itens->first()->tipo_item);
        $this->assertEquals(11640, (float) $copia->itens->first()->quantidade);

        // O histórico não é reescrito: só o documento novo ganha o tipo.
        $this->assertNull($origem->itens()->first()->tipo_item);
    }

    #[Test]
    public function editar_orcamento_legado_tambem_entrega_tipo_valido(): void
    {
        $user = $this->vendedor();
        $origem = $this->orcamentoLegado($user);

        $this->actingAs($user)
            ->get(route('orcamentos.editar', $origem))
            ->assertInertia(fn ($page) => $page->where('orcamento.itens.0.tipoItem', 'outro'));
    }
}
