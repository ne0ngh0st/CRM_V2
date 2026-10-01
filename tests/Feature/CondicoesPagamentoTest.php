<?php

namespace Tests\Feature;

use App\Models\CondicaoPagamento;
use App\Models\Orcamento;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Orcamento\CondicoesPagamento;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Condição de pagamento do Protheus no orçamento (2026-10-01).
 *
 * O Portal recebe `paymentConditionCode` e só aceita condição ativa no Protheus. O
 * orçamento guardava texto livre — ~30% fora da lista. Estes testes travam: a carga
 * pela migration, o reconhecimento do texto antigo, e que o formulário não aceita mais
 * nada fora da lista.
 */
class CondicoesPagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** Sem isto, produção nasceria com a tabela vazia e ninguém criaria orçamento. */
    public function test_a_migration_carrega_a_lista_do_protheus(): void
    {
        $this->assertSame(391, CondicaoPagamento::where('ativo', true)->count());
        $this->assertSame('28 / 35 / 42 DDL', CondicaoPagamento::find('067')->descricao);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function textosAntigos(): array
    {
        return [
            'as 8 opções fixas do formulário antigo' => ['28/35/42DDL', '067'],
            'sem espaço e com espaço' => ['28 DDL', '028'],
            'zero à esquerda' => ['7DDL', '007'],
            'à vista' => ['A VISTA', '001'],
            '"Dias" no lugar de DDL' => ['30/60 Dias', '081'],
            'número puro (texto livre comum)' => ['30/60/90', '079'],
            'descrição repetida no Protheus fica com o menor código' => ['60 DDL', '060'],
            'texto que não é condição' => ['COMBINAR', null],
            'vazio' => [null, null],
        ];
    }

    #[DataProvider('textosAntigos')]
    public function test_reconhece_o_texto_antigo_do_orcamento(?string $texto, ?string $esperado): void
    {
        $this->assertSame($esperado, CondicoesPagamento::sugerirCodigo($texto));
    }

    /** Condição que sai do Protheus é desativada, nunca apagada: orçamento antigo aponta para ela. */
    public function test_sincronizar_desativa_o_que_saiu_da_lista_sem_apagar(): void
    {
        $r = CondicoesPagamento::sincronizar([
            ['codigo' => '028', 'descricao' => '28 DDL', 'tipo' => '1'],
            ['codigo' => '999', 'descricao' => 'NOVA', 'tipo' => '1'],
        ]);

        $this->assertSame(2, $r['gravadas']);
        $this->assertSame(390, $r['desativadas']);
        $this->assertFalse(CondicaoPagamento::find('067')->ativo);
        $this->assertTrue(CondicaoPagamento::find('999')->ativo);
        $this->assertSame(392, CondicaoPagamento::count());
    }

    /** As mais usadas nos pedidos do TOTVS vêm primeiro — são 391 opções. */
    public function test_opcoes_vem_ordenadas_pelo_uso_nos_pedidos(): void
    {
        Cache::flush();
        foreach ([['069', 3], ['028', 1]] as [$codigo, $vezes]) {
            for ($i = 0; $i < $vezes; $i++) {
                Pedido::create([
                    'numero_pedido' => "P{$codigo}{$i}",
                    'cod_vendedor' => '010150',
                    'data_pedido' => now()->subDays(10)->toDateString(),
                    'condicao_pagamento' => $codigo,
                    'status' => 'pendente_totvs',
                    'valor_total' => 10,
                ]);
            }
        }

        $opcoes = CondicoesPagamento::opcoes();

        $this->assertSame(['069', '028'], array_column(array_slice($opcoes, 0, 2), 'codigo'));
        $this->assertSame(391, count($opcoes));
    }

    private function vendedor(): User
    {
        $u = User::factory()->create(['is_active' => true]);
        $u->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $u->id, 'cod_vendedor' => '010150']);

        return $u;
    }

    /** @param  array<string, mixed>  $extra */
    private function salvar(array $extra)
    {
        return $this->actingAs($this->vendedor())->post(route('orcamentos.store'), array_merge([
            'cliente_nome' => 'CENTRAL SUPERMERCADOS',
            'tipo_frete' => 'CIF',
            'tipo_venda' => 'consumo',
            'tipo_produto_servico' => 'produto',
            'itens' => [['tipo_item' => 'bobina', 'descricao' => 'BOBINA', 'quantidade' => 10, 'valor_unitario' => 3]],
        ], $extra));
    }

    public function test_formulario_grava_o_codigo_e_a_descricao_oficial(): void
    {
        $this->salvar(['condicao_pagamento_codigo' => '067'])->assertSessionHasNoErrors();

        $o = Orcamento::first();
        $this->assertSame('067', $o->condicao_pagamento_codigo);
        // PDF, tela, Excel e BI leem este texto — agora sempre a descrição do Protheus.
        $this->assertSame('28 / 35 / 42 DDL', $o->forma_pagamento);
    }

    /** O texto livre ("Outros") morreu: nem código ausente, nem inventado, nem inativo. */
    public function test_formulario_recusa_condicao_fora_da_lista(): void
    {
        $this->salvar([])->assertSessionHasErrors('condicao_pagamento_codigo');
        $this->salvar(['condicao_pagamento_codigo' => 'XYZ'])->assertSessionHasErrors('condicao_pagamento_codigo');

        CondicaoPagamento::whereKey('028')->update(['ativo' => false]);
        $this->salvar(['condicao_pagamento_codigo' => '028'])->assertSessionHasErrors('condicao_pagamento_codigo');

        $this->assertSame(0, Orcamento::count());
    }

    /** O relatório repete o cabeçalho a cada página; o importador mapeia por nome de coluna. */
    public function test_comando_importa_a_planilha_da_se4(): void
    {
        $planilha = new Spreadsheet();
        $planilha->getActiveSheet()->fromArray([
            ['Dt.Ref: 01/10/2026', null, null],
            [null, null, null],
            ['Tipo', 'Descricao', 'Codigo'],
            ['1', 'A VISTA', '001'],
            ['Tipo', 'Descricao', 'Codigo'],
            ['1', '28   DDL', '028'],
        ]);
        $arquivo = tempnam(sys_get_temp_dir(), 'se4').'.xlsx';
        (new Xlsx($planilha))->save($arquivo);

        $this->artisan('condicoes-pagamento:importar', ['arquivo' => $arquivo])
            ->expectsOutputToContain('2 condições no arquivo')
            ->assertSuccessful();

        $this->assertSame('28 DDL', CondicaoPagamento::find('028')->descricao);
        $this->assertSame(2, CondicaoPagamento::where('ativo', true)->count());

        unlink($arquivo);
    }
}
