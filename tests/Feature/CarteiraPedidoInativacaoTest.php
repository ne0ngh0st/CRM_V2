<?php

namespace Tests\Feature;

use App\Mail\CadastroSolicitacaoMail;
use App\Models\Cliente;
use App\Models\SolicitacaoInativacao;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Receita\SituacaoCadastral;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Solicitar inativação" (Tony, 2026-10-01): e-mail ao Cadastro de clientes pedindo para
 * inativar no TOTVS um cliente com CNPJ irregular na Receita, com quem pediu em cópia.
 *
 * ⚠️ O e-mail vai para um setor de verdade. O que estes testes travam é justamente o que
 * não pode escapar: pedir inativação de CNPJ ATIVO, pedir duas vezes, pedir cliente de
 * outra carteira, e o modo teste (`CADASTROS_REDIRECIONAR_PARA`) deixando de valer.
 */
class CarteiraPedidoInativacaoTest extends TestCase
{
    use RefreshDatabase;

    private const CADASTRO = 'cadastro.geral@autopel.com';

    private User $vendedor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Não herdar o modo teste do .env local: aqui se confere o destino REAL.
        config(['cadastros.redirecionar_emails_para' => null]);
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true, 'email' => 'vendedor@autopel.com']);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => '001']);
    }

    public function test_cnpj_irregular_manda_para_o_cadastro_com_o_solicitante_em_copia(): void
    {
        $cliente = $this->cliente('11111111000111', '001', 'BAIXADA');

        $this->pedir($cliente)->assertOk()->assertJsonPath('inativacao.por', $this->vendedor->display_name ?: $this->vendedor->name);

        Mail::assertQueued(CadastroSolicitacaoMail::class, function (CadastroSolicitacaoMail $mail) {
            return $mail->hasTo(self::CADASTRO)
                && $mail->hasCc('vendedor@autopel.com')
                && str_contains($mail->envelope()->subject, 'BAIXADA na Receita')
                && str_contains($mail->envelope()->subject, 'EMPRESA 11111111000111')
                && str_contains($mail->corpo, 'INATIVAÇÃO')
                && str_contains($mail->corpo, '11.111.111/0001-11');
        });
        Mail::assertQueuedCount(1);

        $this->assertDatabaseHas('solicitacoes_inativacao', [
            'cliente_id' => $cliente->id, 'situacao_receita' => 'BAIXADA', 'solicitado_por' => $this->vendedor->id,
        ]);
    }

    public function test_cnpj_ativo_ou_nao_verificado_nao_manda_nada(): void
    {
        $ativo = $this->cliente('22222222000122', '001', 'ATIVA');
        $nunca = $this->cliente('33333333000133', '001', null);

        $this->pedir($ativo)->assertStatus(422);
        $this->pedir($nunca)->assertStatus(422);

        Mail::assertNothingQueued();
        $this->assertSame(0, SolicitacaoInativacao::count());
    }

    public function test_segundo_pedido_nao_manda_outro_email(): void
    {
        $cliente = $this->cliente('11111111000111', '001', 'INAPTA');

        $this->pedir($cliente)->assertOk();
        $this->pedir($cliente)->assertStatus(409)->assertJsonPath('inativacao.em', now()->format('d/m/Y'));

        Mail::assertQueuedCount(1);
        $this->assertSame(1, SolicitacaoInativacao::count());
    }

    public function test_depois_de_trinta_dias_pode_pedir_de_novo(): void
    {
        $cliente = $this->cliente('11111111000111', '001', 'INAPTA');
        $this->pedir($cliente)->assertOk();
        SolicitacaoInativacao::query()->update(['created_at' => now()->subDays(31)]);

        $this->pedir($cliente)->assertOk();

        Mail::assertQueuedCount(2);
    }

    public function test_cliente_de_outra_carteira_nao_pode_ser_pedido(): void
    {
        $alheio = $this->cliente('44444444000144', '002', 'BAIXADA');

        $this->assertContains($this->pedir($alheio)->status(), [403, 404]);

        Mail::assertNothingQueued();
    }

    public function test_modo_teste_redireciona_e_tira_as_copias(): void
    {
        config(['cadastros.redirecionar_emails_para' => 'antonio.barbosa@autopel.com']);
        $cliente = $this->cliente('11111111000111', '001', 'BAIXADA');

        $this->pedir($cliente)->assertOk();

        Mail::assertQueued(CadastroSolicitacaoMail::class, function (CadastroSolicitacaoMail $mail) {
            return $mail->hasTo('antonio.barbosa@autopel.com')
                && ! $mail->hasTo(self::CADASTRO)
                && ! $mail->hasCc('vendedor@autopel.com')
                && str_contains($mail->envelope()->subject, '[TESTE → '.self::CADASTRO);
        });
    }

    public function test_a_tela_mostra_o_pedido_feito(): void
    {
        $cliente = $this->cliente('11111111000111', '001', 'BAIXADA');
        $this->pedir($cliente)->assertOk();

        $linha = collect($this->actingAs($this->vendedor)->get(route('carteira.index'))
            ->viewData('page')['props']['clientes']['data'])->firstWhere('id', $cliente->id);
        $this->assertSame(now()->format('d/m/Y'), $linha['inativacao']['em']);

        $filial = $this->actingAs($this->vendedor)->getJson(route('carteira.filiais', $cliente->cod_cliente))
            ->json('filiais.0');
        $this->assertSame(now()->format('d/m/Y'), $filial['inativacao']['em']);

        $ficha = $this->actingAs($this->vendedor)->get(route('carteira.detalhes', $cliente))
            ->viewData('page')['props']['cliente']['receita'];
        $this->assertSame(now()->format('d/m/Y'), $ficha['inativacao']['em']);
    }

    /**
     * O pedido mora DENTRO do Cartão CNPJ (decisão do Tony: nada de botão na Carteira).
     * Quem diz se ele aparece é a resposta do cartão — a mesma regra que o envio confere.
     */
    public function test_cartao_cnpj_da_carteira_diz_se_a_inativacao_cabe(): void
    {
        $baixado = $this->cliente('11111111000111', '001', null);
        $ativo = $this->cliente('22222222000122', '001', null);

        Http::fake([
            'brasilapi.com.br/*11111111000111' => Http::response($this->cartao('11111111000111', 'BAIXADA')),
            'brasilapi.com.br/*22222222000122' => Http::response($this->cartao('22222222000122', 'ATIVA')),
        ]);

        $this->actingAs($this->vendedor)->getJson(route('carteira.cartaoCnpj', $baixado))
            ->assertOk()
            ->assertJsonPath('inativacao.permitida', true)
            ->assertJsonPath('inativacao.situacao', 'BAIXADA')
            ->assertJsonPath('inativacao.solicitada', null);

        $this->actingAs($this->vendedor)->getJson(route('carteira.cartaoCnpj', $ativo))
            ->assertOk()
            ->assertJsonPath('inativacao.permitida', false);

        // Pedido feito: o cartão passa a trazê-lo, e o botão vira "solicitada em".
        $this->pedir($baixado)->assertOk();
        $this->actingAs($this->vendedor)->getJson(route('carteira.cartaoCnpj', $baixado))
            ->assertJsonPath('inativacao.solicitada.em', now()->format('d/m/Y'));
    }

    // ---------------------------------------------------------------------------------

    private function cartao(string $cnpj, string $situacao): array
    {
        return [
            'cnpj' => $cnpj, 'razao_social' => 'EMPRESA '.$cnpj, 'nome_fantasia' => '',
            'descricao_situacao_cadastral' => $situacao, 'data_situacao_cadastral' => '2024-01-02',
            'descricao_motivo_situacao_cadastral' => 'SEM MOTIVO', 'data_inicio_atividade' => '2010-01-01',
            'identificador_matriz_filial' => 1, 'natureza_juridica' => 'LTDA', 'porte' => 'ME',
            'opcao_pelo_simples' => false, 'opcao_pelo_mei' => false, 'capital_social' => 1000,
            'cnae_fiscal' => 4711302, 'cnae_fiscal_descricao' => 'Comércio', 'cnaes_secundarios' => [],
            'descricao_tipo_de_logradouro' => 'RUA', 'logradouro' => 'A', 'numero' => '1', 'complemento' => '',
            'bairro' => 'CENTRO', 'municipio' => 'SAO PAULO', 'uf' => 'SP', 'cep' => '01001000',
            'ddd_telefone_1' => '', 'ddd_telefone_2' => '', 'email' => null, 'qsa' => [],
        ];
    }

    private function pedir(Cliente $cliente)
    {
        return $this->actingAs($this->vendedor)->postJson(route('carteira.solicitarInativacao', $cliente));
    }

    private function cliente(string $cnpj, string $vendedor, ?string $situacao): Cliente
    {
        $cliente = Cliente::create([
            'cod_cliente' => substr($cnpj, 0, 6),
            'loja' => '0001',
            'razao_social' => 'EMPRESA '.$cnpj,
            'cnpj' => vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj)),
            'cod_vendedor' => $vendedor,
            'estado' => 'SP',
        ]);

        if ($situacao !== null) {
            DB::table('cnpj_situacoes')->insert([
                'cnpj' => $cnpj, 'situacao' => $situacao, 'data_situacao' => '2024-01-02',
                'fonte' => SituacaoCadastral::FONTE_BASE, 'referencia' => '2026-09', 'atualizado_em' => now(),
            ]);
        }

        return $cliente->fresh();
    }
}
