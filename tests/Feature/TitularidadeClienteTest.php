<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\ClienteParaCadastro;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Quem cuida do cliente?"
 *
 * Gap conhecido do legado que estava em aberto: a Carteira é escopada por vendedor, então
 * não conseguia responder "esse CNPJ já é de alguém?". Sem isso, o vendedor descobre que
 * pisou no cliente do colega depois de ligar — ou pede cadastro de um cliente que existe.
 */
class TitularidadeClienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function vendedor(string $cod, string $nome = 'FULANO'): User
    {
        $user = User::factory()->create(['display_name' => $nome]);
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod, 'cod_super' => '000006']);

        return $user;
    }

    private function cliente(array $extra = []): Cliente
    {
        return Cliente::create(array_merge([
            'cod_cliente' => '000123',
            'loja' => '01',
            'cnpj' => '16.729.628/0001-62',
            'razao_social' => 'KNTT COMERCIO E SUPERMERCADO LTDA',
            'cod_vendedor' => '010617',
        ], $extra));
    }

    /**
     * ⚠️ O ponto inteiro da feature: quem busca NÃO tem o cliente na carteira e mesmo
     * assim descobre de quem ele é. Se alguém "corrigir" isto adicionando escopo por
     * vendedor, este teste cai — e é para cair.
     */
    #[Test]
    public function test_vendedor_descobre_cliente_de_outra_carteira(): void
    {
        $this->vendedor('010617', 'FERNANDA ROSSI');
        $supervisor = User::factory()->create(['display_name' => 'CLEBER S CATELA']);
        $supervisor->assignRole('supervisor');
        VendedorPerfil::create(['user_id' => $supervisor->id, 'cod_vendedor' => '000006']);
        $this->cliente();

        $this->actingAs($this->vendedor('999999'))
            ->getJson(route('cadastros.titularidade', ['termo' => 'KNTT']))
            ->assertOk()
            ->assertJsonPath('resultados.0.razaoSocial', 'KNTT COMERCIO E SUPERMERCADO LTDA')
            ->assertJsonPath('resultados.0.responsaveis.0', 'FERNANDA ROSSI')
            ->assertJsonPath('resultados.0.supervisor', 'CLEBER S CATELA');
    }

    /**
     * ⚠️ O que torna a ausência de escopo defensável é o CONTEÚDO ser pobre. A resposta
     * diz de quem é, e nada mais: sem status, sem data de última compra, sem telefone,
     * sem e-mail, sem valores. Cada campo novo aqui é uma decisão consciente, não um
     * "aproveita que já está aqui".
     */
    #[Test]
    public function test_resposta_nao_vaza_dados_operacionais_do_cliente(): void
    {
        $this->vendedor('010617', 'FERNANDA ROSSI');
        $this->cliente([
            'telefone' => '(15) 3278-9011',
            'email' => 'compras@kntt.com.br',
            'data_ultima_compra' => now()->subDays(30),
        ]);

        $resposta = $this->actingAs($this->vendedor('999999'))
            ->getJson(route('cadastros.titularidade', ['termo' => 'KNTT']))
            ->assertOk()
            ->json('resultados.0');

        foreach (['telefone', 'email', 'dataUltimaCompra', 'status', 'valorEstimado'] as $proibido) {
            $this->assertArrayNotHasKey($proibido, $resposta, "a titularidade não pode expor `{$proibido}`");
        }
    }

    /**
     * A busca por documento tem que achar com e sem máscara, e com pedaço.
     * ⚠️ Conferido no banco: 91.451 clientes têm CNPJ mascarado, 746 têm CPF mascarado e
     * ZERO estão gravados só com dígitos — por isso a remontagem da máscara em vez de um
     * REPLACE() na coluna, que jogaria o índice fora.
     */
    #[Test]
    #[DataProvider('formasDeDigitarODocumento')]
    public function test_encontra_pelo_documento_em_qualquer_formato(string $termo): void
    {
        $this->vendedor('010617');
        $this->cliente();

        $this->actingAs($this->vendedor('999999'))
            ->getJson(route('cadastros.titularidade', ['termo' => $termo]))
            ->assertOk()
            ->assertJsonPath('resultados.0.cnpj', '16.729.628/0001-62');
    }

    /** @return array<string, array{0: string}> */
    public static function formasDeDigitarODocumento(): array
    {
        return [
            'cnpj completo com mascara' => ['16.729.628/0001-62'],
            'cnpj completo so digitos' => ['16729628000162'],
            'raiz do cnpj' => ['16729628'],
            'inicio do cnpj' => ['16729'],
        ];
    }

    /** CPF é pessoa física e existe na base — a máscara é outra. */
    #[Test]
    public function test_encontra_pessoa_fisica_pelo_cpf(): void
    {
        $this->vendedor('010617');
        $this->cliente(['cnpj' => '127.934.676-00', 'razao_social' => 'JOAO DA SILVA ME']);

        $this->actingAs($this->vendedor('999999'))
            ->getJson(route('cadastros.titularidade', ['termo' => '12793467600']))
            ->assertOk()
            ->assertJsonPath('resultados.0.razaoSocial', 'JOAO DA SILVA ME');
    }

    /**
     * A metade que impede a SEGUNDA solicitação duplicada: o cliente ainda não existe no
     * TOTVS, mas alguém já pediu. Sem isto a busca diria "não é de ninguém".
     */
    #[Test]
    public function test_solicitacao_pendente_aparece_marcada(): void
    {
        $pedinte = $this->vendedor('010617', 'MURILO');
        // A tabela tem 13 colunas obrigatórias sem default — é uma FICHA de solicitação
        // de cadastro, não um espelho de `clientes`; o formulário exige tudo isso do
        // vendedor porque é o que o time de Cadastro precisa para abrir o item no TOTVS.
        ClienteParaCadastro::create([
            'user_id' => $pedinte->id,
            'nome_solicitante' => 'MURILO',
            'vendedor_autopel' => 'MURILO',
            'razao_social' => 'PADARIA NOVA LTDA',
            'cnpj_faturamento' => '11.222.333/0001-44',
            'endereco' => 'Rua das Flores, 10',
            'bairro' => 'Centro',
            'cep' => '18000-000',
            'municipio' => 'Sorocaba',
            'estado' => 'SP',
            'telefone' => '(15) 3000-0000',
            'email' => 'compras@padarianova.com.br',
            'inscricao_estadual' => 'ISENTO',
            'segmento_atuacao' => 'Padaria',
            'status' => 'pendente',
        ]);

        $this->actingAs($this->vendedor('999999'))
            ->getJson(route('cadastros.titularidade', ['termo' => 'PADARIA']))
            ->assertOk()
            ->assertJsonPath('resultados.0.tipo', 'pendente')
            ->assertJsonPath('resultados.0.responsaveis.0', 'MURILO');
    }

    /** Abaixo do mínimo a busca traria meia base — e não é isso que a pergunta pede. */
    #[Test]
    public function test_termo_curto_nao_busca(): void
    {
        $this->vendedor('010617');
        $this->cliente();

        $this->actingAs($this->vendedor('999999'))
            ->getJson(route('cadastros.titularidade', ['termo' => 'KN']))
            ->assertOk()
            ->assertJsonPath('resultados', []);
    }

    /**
     * ⚠️ `cod_vendedor` NÃO é único neste projeto — há contas compartilhando código.
     * Mostrar só o primeiro esconderia metade da resposta justamente nos casos em que a
     * pergunta "quem cuida?" é mais difícil de responder.
     */
    #[Test]
    public function test_codigo_compartilhado_lista_todos_os_responsaveis(): void
    {
        $this->vendedor('010617', 'FERNANDA');
        $this->vendedor('010617', 'MURILO');
        $this->cliente();

        $responsaveis = $this->actingAs($this->vendedor('999999'))
            ->getJson(route('cadastros.titularidade', ['termo' => 'KNTT']))
            ->assertOk()
            ->json('resultados.0.responsaveis');

        $this->assertEqualsCanonicalizing(['FERNANDA', 'MURILO'], $responsaveis);
    }
}
