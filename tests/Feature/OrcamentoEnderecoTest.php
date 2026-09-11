<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Orcamento;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Orcamento\OrcamentoCalculoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endereço do CNPJ orçado no documento.
 *
 * Sugestão do Vagner Sabelli (10/09/2026), aprovada: "Constar no orçamento o endereço
 * completo correspondente ao cnpj orçado".
 *
 * O que estes testes travam, e por quê:
 *
 *  - o SNAPSHOT, porque orçamento é documento: o endereço impresso não pode mudar
 *    quando a empresa muda de endereço depois;
 *  - o FALLBACK por CNPJ, porque os 2.127 orçamentos que já existiam não têm cópia
 *    nenhuma nem `cliente_id` — sem ele a feature nasceria sem efeito no acervo;
 *  - a PRECEDÊNCIA entre os dois, que é onde um erro seria invisível: se o fallback
 *    ganhasse do snapshot, todo documento antigo passaria a se reescrever sozinho.
 */
class OrcamentoEnderecoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(): User
    {
        $user = User::factory()->create(['display_name' => 'FULANO']);
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => '010617', 'cod_super' => '000006']);

        return $user;
    }

    private function cliente(array $extra = []): Cliente
    {
        return Cliente::create(array_merge([
            'cod_cliente' => '000123',
            'loja' => '0001',
            // Mascarado, como o import grava (92.197 de 92.200 em produção).
            'cnpj' => '16.729.628/0001-62',
            'razao_social' => 'KNTT COMERCIO E SUPERMERCADO LTDA',
            'cod_vendedor' => '010617',
            'endereco' => 'RUA NOVE 420',
            'municipio' => 'CONTAGEM',
            'estado' => 'MG',
            'cep' => '32183020',
        ], $extra));
    }

    /** @param  array<string, mixed>  $extra */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'cliente_nome' => 'KNTT COMERCIO E SUPERMERCADO LTDA',
            'cliente_cnpj' => '16729628000162',
            'tipo_frete' => 'CIF',
            'tipo_produto_servico' => 'produto',
            'itens' => [[
                'tipo_item' => 'outro',
                'descricao' => 'BOBINA 80X40',
                'quantidade' => 10,
                'valor_unitario' => 12.50,
            ]],
        ], $extra);
    }

    #[Test]
    public function a_busca_de_cliente_devolve_o_endereco_para_a_folha(): void
    {
        $this->cliente();

        $resposta = $this->actingAs($this->vendedor())
            ->getJson(route('orcamentos.buscaClientes', ['q' => 'KNTT']));

        $resposta->assertOk()->assertJsonFragment([
            'endereco' => 'RUA NOVE 420',
            'municipio' => 'CONTAGEM',
            'estado' => 'MG',
            'cep' => '32183020',
        ]);
    }

    #[Test]
    public function orcamento_novo_congela_o_endereco_enviado(): void
    {
        $this->actingAs($this->vendedor())
            ->post(route('orcamentos.store'), $this->payload([
                'cliente_endereco' => 'AVENIDA PAULISTA, 1000',
                'cliente_municipio' => 'SAO PAULO',
                'cliente_estado' => 'SP',
                'cliente_cep' => '01310100',
            ]))
            ->assertRedirect();

        $orcamento = Orcamento::firstOrFail();

        $this->assertSame('AVENIDA PAULISTA, 1000', $orcamento->cliente_endereco);
        $this->assertSame('SAO PAULO', $orcamento->cliente_municipio);
        $this->assertSame('SP', $orcamento->cliente_estado);
        $this->assertSame('01310100', $orcamento->cliente_cep);
    }

    #[Test]
    public function o_snapshot_vence_o_cadastro_atual_do_cliente(): void
    {
        // O cliente MUDOU de endereço depois que o orçamento foi emitido.
        $cliente = $this->cliente();

        $orcamento = Orcamento::create($this->dadosBase() + [
            'cliente_id' => $cliente->id,
            'cliente_endereco' => 'RUA ANTIGA 1',
            'cliente_municipio' => 'BELO HORIZONTE',
            'cliente_estado' => 'MG',
            'cliente_cep' => '30000000',
        ]);

        $endereco = $orcamento->enderecoDoDocumento();

        // Se isto voltar 'RUA NOVE 420', o documento se reescreveu sozinho.
        $this->assertSame('RUA ANTIGA 1', $endereco['logradouro']);
        $this->assertSame('BELO HORIZONTE', $endereco['municipio']);
        $this->assertSame('30000000', $endereco['cep']);
    }

    #[Test]
    public function orcamento_antigo_sem_copia_cai_para_o_endereco_atual_do_cliente(): void
    {
        $this->cliente();

        /*
         * Retrato fiel dos 2.127 orçamentos que já existiam em 11/09: SEM `cliente_id`
         * (a coluna nasceu em 10/09, com o Portal) e com o CNPJ gravado só em dígitos,
         * enquanto `clientes.cnpj` está mascarado. É o casamento entre esses dois
         * formatos que o fallback precisa acertar.
         */
        $orcamento = Orcamento::create($this->dadosBase() + ['cliente_cnpj' => '16729628000162']);

        $endereco = $orcamento->enderecoDoDocumento();

        $this->assertSame('RUA NOVE 420', $endereco['logradouro']);
        $this->assertSame('CONTAGEM', $endereco['municipio']);
        $this->assertSame('MG', $endereco['estado']);
        $this->assertSame('32183020', $endereco['cep']);
    }

    #[Test]
    public function orcamento_sem_cliente_correspondente_nao_inventa_endereco(): void
    {
        // Nenhum cliente cadastrado com este CNPJ — é o caso do orçamento digitado à
        // mão e do orçamento para lead.
        $orcamento = Orcamento::create($this->dadosBase() + ['cliente_cnpj' => '99999999000199']);

        $this->assertSame(
            ['logradouro' => null, 'municipio' => null, 'estado' => null, 'cep' => null],
            $orcamento->enderecoDoDocumento()
        );
        $this->assertNull($orcamento->enderecoDoDocumentoEmLinha());
    }

    #[Test]
    public function a_linha_unica_junta_as_partes_e_omite_o_que_falta(): void
    {
        $completo = Orcamento::create($this->dadosBase() + [
            'cliente_endereco' => 'RUA NOVE 420',
            'cliente_municipio' => 'CONTAGEM',
            'cliente_estado' => 'MG',
            'cliente_cep' => '32183020',
        ]);

        $this->assertSame(
            'RUA NOVE 420 — CONTAGEM/MG — CEP 32183020',
            $completo->enderecoDoDocumentoEmLinha()
        );

        // Sem CEP e sem UF a linha não pode sair com separador solto ("— /" ou "— CEP").
        $parcial = Orcamento::create($this->dadosBase() + [
            'cliente_endereco' => 'RUA NOVE 420',
            'cliente_municipio' => 'CONTAGEM',
        ]);

        $this->assertSame('RUA NOVE 420 — CONTAGEM', $parcial->enderecoDoDocumentoEmLinha());
    }

    #[Test]
    public function o_pdf_mostra_o_endereco_do_cnpj_orcado(): void
    {
        $cliente = $this->cliente();
        $user = $this->vendedor();

        $orcamento = Orcamento::create($this->dadosBase() + [
            'user_id' => $user->id,
            'cliente_id' => $cliente->id,
            'cliente_endereco' => 'RUA NOVE 420',
            'cliente_municipio' => 'CONTAGEM',
            'cliente_estado' => 'MG',
            'cliente_cep' => '32183020',
        ]);

        $resposta = $this->actingAs($user)->get(route('orcamentos.pdf', $orcamento));

        $resposta->assertOk();

        /*
         * ⚠️ Conteúdo, não só "é um PDF válido". A lição de 2026-08-10 (o PDF de bobina
         * que saiu com 11 páginas em branco e mesmo assim passou na asserção de
         * assinatura) vale aqui: o dompdf comprime o stream, então a verificação é
         * renderizar o Blade com o mesmo model e conferir que os rótulos e os valores
         * saem — é o que quebraria se alguém ler `$orcamento->cliente->endereco`
         * direto no lugar de `enderecoDoDocumento()`.
         */
        $this->assertSame('application/pdf', $resposta->headers->get('content-type'));

        $html = $this->renderizarFolha($orcamento->fresh());

        $this->assertStringContainsString('Endereço', $html);
        $this->assertStringContainsString('RUA NOVE 420', $html);
        $this->assertStringContainsString('CONTAGEM/MG', $html);
        $this->assertStringContainsString('32183020', $html);
    }

    #[Test]
    public function o_pdf_de_orcamento_antigo_tambem_mostra_endereco(): void
    {
        $this->cliente();
        $user = $this->vendedor();

        // Sem cópia e sem cliente_id: só o CNPJ, como o acervo importado do legado.
        $orcamento = Orcamento::create($this->dadosBase() + [
            'user_id' => $user->id,
            'cliente_cnpj' => '16729628000162',
        ]);

        $html = $this->renderizarFolha($orcamento->fresh());

        $this->assertStringContainsString('RUA NOVE 420', $html);
        $this->assertStringContainsString('CONTAGEM/MG', $html);
    }

    /**
     * Renderiza o MESMO Blade do PDF, sem passar pelo dompdf.
     *
     * ⚠️ O binário sai com os streams comprimidos, então procurar texto dentro dele não
     * funciona — e "é um PDF válido" não prova layout nenhum (lição de 2026-08-10, o PDF
     * de bobina que saiu com 11 páginas em branco e passou na asserção de assinatura).
     * A rota é exercida à parte, e aqui se confere o conteúdo.
     *
     * A lista de itens vai vazia de propósito: o que este arquivo cobre é o painel de
     * dados do cliente. O `resumo` vem do MESMO serviço que o controller usa, para o
     * teste não carregar uma segunda versão da matemática de IPI.
     */
    private function renderizarFolha(Orcamento $orcamento): string
    {
        return view('orcamentos.pdf', [
            'orcamento' => $orcamento,
            'itensCalculados' => collect(),
            'resumo' => app(OrcamentoCalculoService::class)->resumo(collect()),
        ])->render();
    }

    #[Test]
    public function o_formulario_de_edicao_recebe_o_endereco_ja_resolvido(): void
    {
        $this->cliente();
        $user = $this->vendedor();

        $orcamento = Orcamento::create($this->dadosBase() + [
            'user_id' => $user->id,
            'cliente_cnpj' => '16729628000162',
        ]);

        $this->actingAs($user)
            ->get(route('orcamentos.editar', $orcamento))
            ->assertInertia(fn ($page) => $page
                ->where('orcamento.clienteEndereco', 'RUA NOVE 420')
                ->where('orcamento.clienteMunicipio', 'CONTAGEM')
                ->where('orcamento.clienteEstado', 'MG')
                ->where('orcamento.clienteCep', '32183020')
            );
    }

    #[Test]
    public function cnpj_em_mais_de_uma_filial_com_enderecos_diferentes_nao_exibe_nada(): void
    {
        /*
         * 🚨 O caso que quase passou batido, e o mais perigoso da feature.
         *
         * CNPJ não identifica cliente nesta base — o grão é a filial. Em produção
         * (11/09/2026) são 8.142 CNPJs repetidos, 7.518 com endereços diferentes e
         * 2.334 em municípios diferentes. Um `first()` imprimiria a filial errada,
         * às vezes de outra cidade, num documento que vai para o cliente.
         */
        $this->cliente(['cod_cliente' => '000123', 'loja' => '0001', 'endereco' => 'RUA NOVE 420', 'municipio' => 'CONTAGEM']);
        $this->cliente(['cod_cliente' => '000123', 'loja' => '0002', 'endereco' => 'AVENIDA OUTRA 99', 'municipio' => 'SOROCABA']);

        $orcamento = Orcamento::create($this->dadosBase() + ['cliente_cnpj' => '16729628000162']);

        $this->assertNull($orcamento->enderecoDoDocumentoEmLinha(), 'Ambíguo tem que sair vazio, nunca com a filial errada.');
    }

    #[Test]
    public function filiais_repetidas_com_o_mesmo_endereco_continuam_resolvendo(): void
    {
        // O contraveneno: cautela demais deixaria sem endereço o caso mais comum —
        // a mesma empresa cadastrada duas vezes, no mesmo lugar.
        $this->cliente(['cod_cliente' => '000123', 'loja' => '0001']);
        $this->cliente(['cod_cliente' => '000123', 'loja' => '0002']);

        $orcamento = Orcamento::create($this->dadosBase() + ['cliente_cnpj' => '16729628000162']);

        $this->assertSame('RUA NOVE 420', $orcamento->enderecoDoDocumento()['logradouro']);
    }

    #[Test]
    public function o_vinculo_direto_vence_a_ambiguidade_do_cnpj(): void
    {
        // Orçamento NOVO guarda `cliente_id`: aponta para a filial exata que o vendedor
        // escolheu, então CNPJ repetido não é ambiguidade nenhuma para ele.
        $matriz = $this->cliente(['cod_cliente' => '000123', 'loja' => '0001', 'endereco' => 'RUA NOVE 420', 'municipio' => 'CONTAGEM']);
        $this->cliente(['cod_cliente' => '000123', 'loja' => '0002', 'endereco' => 'AVENIDA OUTRA 99', 'municipio' => 'SOROCABA']);

        $orcamento = Orcamento::create($this->dadosBase() + [
            'cliente_id' => $matriz->id,
            'cliente_cnpj' => '16729628000162',
        ]);

        $this->assertSame('CONTAGEM', $orcamento->enderecoDoDocumento()['municipio']);
    }

    /** @return array<string, mixed> */
    private function dadosBase(): array
    {
        return [
            'user_id' => User::query()->value('id') ?? $this->vendedor()->id,
            'cliente_nome' => 'KNTT COMERCIO E SUPERMERCADO LTDA',
            'valor_total' => 125.00,
            'desconto_pct_max' => 0,
            'nivel_aprovacao' => 'nenhum',
            'status_gestor' => 'pendente',
            'tipo_frete' => 'CIF',
            'tipo_produto_servico' => 'produto',
        ];
    }
}
