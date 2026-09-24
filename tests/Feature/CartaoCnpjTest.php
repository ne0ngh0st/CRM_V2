<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CnpjConsulta;
use App\Models\Lead;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Verificar cartão CNPJ" na Carteira. Nenhum teste aqui fala com a internet
 * (`preventStrayRequests`): as fontes são simuladas com o formato REAL de cada uma,
 * copiado de respostas de 2026-09-24 para o CNPJ público do Banco do Brasil.
 */
class CartaoCnpjTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '00000000000191';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Http::preventStrayRequests();
    }

    private function usuario(string $role = 'admin', ?string $codVendedor = null): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        if ($codVendedor) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor]);
        }

        return $user;
    }

    private function cliente(array $atributos = []): Cliente
    {
        return Cliente::create([
            'cod_cliente' => '000123',
            'loja' => '01',
            'cnpj' => '00.000.000/0001-91',
            'razao_social' => 'BANCO DO BRASIL S/A',
            'municipio' => 'BRASILIA',
            'estado' => 'DF',
            'cep' => '70040-912',
            'cod_vendedor' => '000010',
            ...$atributos,
        ]);
    }

    /** Formato BrasilAPI / minhareceita. */
    private function respostaBrasilApi(array $sobrescrever = []): array
    {
        return [
            'cnpj' => self::CNPJ,
            'razao_social' => 'BANCO DO BRASIL SA',
            'nome_fantasia' => 'DIRECAO GERAL',
            'descricao_situacao_cadastral' => 'ATIVA',
            'data_situacao_cadastral' => '2005-11-03',
            'descricao_motivo_situacao_cadastral' => 'SEM MOTIVO',
            'data_inicio_atividade' => '1966-08-01',
            'identificador_matriz_filial' => 1,
            'natureza_juridica' => 'Sociedade de Economia Mista',
            'porte' => 'DEMAIS',
            'opcao_pelo_simples' => false,
            'opcao_pelo_mei' => false,
            'capital_social' => 120000000000,
            'cnae_fiscal' => 6422100,
            'cnae_fiscal_descricao' => 'Bancos múltiplos, com carteira comercial',
            'cnaes_secundarios' => [['codigo' => 6499999, 'descricao' => 'Outras atividades de serviços financeiros']],
            'descricao_tipo_de_logradouro' => 'QUADRA',
            'logradouro' => 'SAUN QUADRA 5 BLOCO B',
            'numero' => 'SN',
            'complemento' => '',
            'bairro' => 'ASA NORTE',
            'municipio' => 'BRASILIA',
            'uf' => 'DF',
            'cep' => '70040912',
            'ddd_telefone_1' => '6134939002',
            'ddd_telefone_2' => '',
            'email' => null,
            'qsa' => [['nome_socio' => 'FULANO DE TAL', 'cnpj_cpf_do_socio' => '***550179**']],
            ...$sobrescrever,
        ];
    }

    /** Formato CNPJá (open). */
    private function respostaCnpja(): array
    {
        return [
            'taxId' => self::CNPJ,
            'alias' => 'Direcao Geral',
            'founded' => '1966-08-01',
            'head' => true,
            'status' => ['id' => 2, 'text' => 'Ativa'],
            'statusDate' => '2005-11-03',
            'company' => [
                'name' => 'BANCO DO BRASIL SA',
                'equity' => 120000000000,
                'nature' => ['id' => 2038, 'text' => 'Sociedade de Economia Mista'],
                'size' => ['id' => 5, 'acronym' => 'DEMAIS', 'text' => 'Demais'],
                'simples' => ['optant' => false],
                'simei' => ['optant' => false],
                'members' => [['person' => ['name' => 'FULANO DE TAL']]],
            ],
            'address' => [
                'street' => 'Quadra Saun Quadra 5 Bloco B', 'number' => 'SN', 'district' => 'Asa Norte',
                'city' => 'Brasília', 'state' => 'DF', 'zip' => '70040912',
            ],
            'phones' => [['area' => '61', 'number' => '34939002']],
            'emails' => [['address' => 'secex@bb.com.br']],
            'mainActivity' => ['id' => 6422100, 'text' => 'Bancos múltiplos, com carteira comercial'],
            'sideActivities' => [],
        ];
    }

    private function consultar(Cliente $cliente, ?User $user = null, bool $atualizar = false)
    {
        return $this->actingAs($user ?? $this->usuario())
            ->getJson(route('carteira.cartaoCnpj', $cliente).($atualizar ? '?atualizar=1' : ''));
    }

    public function test_traz_o_cartao_normalizado_e_grava_a_consulta(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        $resposta = $this->consultar($this->cliente())->assertOk();

        $resposta->assertJsonPath('cartao.razaoSocial', 'BANCO DO BRASIL SA')
            ->assertJsonPath('cartao.situacao', 'ATIVA')
            ->assertJsonPath('cartao.dataSituacao', '03/11/2005')
            ->assertJsonPath('cartao.motivoSituacao', null)
            ->assertJsonPath('cartao.matriz', true)
            ->assertJsonPath('cartao.cnaePrincipal.codigo', '6422-1/00')
            ->assertJsonPath('cartao.endereco.cep', '70040-912')
            ->assertJsonPath('cartao.telefones.0', '(61) 3493-9002')
            ->assertJsonPath('cartao.cnpjFormatado', '00.000.000/0001-91')
            ->assertJsonPath('fonte', 'brasilapi')
            ->assertJsonPath('desatualizado', false);

        $gravada = CnpjConsulta::query()->where('cnpj', self::CNPJ)->sole();
        $this->assertSame('ATIVA', $gravada->situacao);
    }

    public function test_quadro_de_socios_nao_sai_nem_e_gravado(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        $resposta = $this->consultar($this->cliente())->assertOk();

        $this->assertStringNotContainsString('FULANO', $resposta->getContent());
        $this->assertStringNotContainsString('FULANO', json_encode(CnpjConsulta::sole()->dados));
    }

    public function test_consulta_recente_nao_vai_de_novo_a_receita(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);
        $cliente = $this->cliente();
        $user = $this->usuario();

        $this->consultar($cliente, $user)->assertOk();
        $this->consultar($cliente, $user)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_atualizar_forca_nova_consulta(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::sequence()
            ->push($this->respostaBrasilApi())
            ->push($this->respostaBrasilApi(['descricao_situacao_cadastral' => 'BAIXADA', 'descricao_motivo_situacao_cadastral' => 'EXTINCAO POR ENCERRAMENTO LIQUIDACAO VOLUNTARIA']))]);
        $cliente = $this->cliente();
        $user = $this->usuario();

        $this->consultar($cliente, $user)->assertJsonPath('cartao.situacao', 'ATIVA');
        $this->consultar($cliente, $user, atualizar: true)
            ->assertJsonPath('cartao.situacao', 'BAIXADA')
            ->assertJsonPath('cartao.motivoSituacao', 'EXTINCAO POR ENCERRAMENTO LIQUIDACAO VOLUNTARIA');

        Http::assertSentCount(2);
        $this->assertSame('BAIXADA', CnpjConsulta::sole()->situacao);
    }

    public function test_consulta_vencida_vai_de_novo_a_receita(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);
        $cliente = $this->cliente();
        $user = $this->usuario();

        $this->consultar($cliente, $user)->assertOk();
        $this->travel(config('receita.validade_dias') + 1)->days();
        $this->consultar($cliente, $user)->assertOk();

        Http::assertSentCount(2);
    }

    public function test_cai_para_a_proxima_fonte_quando_uma_falha(): void
    {
        Http::fake([
            'brasilapi.com.br/*' => Http::response('erro', 502),
            'minhareceita.org/*' => fn () => throw new ConnectionException('timeout'),
            'open.cnpja.com/*' => Http::response($this->respostaCnpja()),
        ]);

        $this->consultar($this->cliente())->assertOk()
            ->assertJsonPath('fonte', 'cnpja')
            ->assertJsonPath('cartao.situacao', 'ATIVA')
            ->assertJsonPath('cartao.porte', 'DEMAIS')
            ->assertJsonPath('cartao.endereco.municipio', 'BRASÍLIA')
            ->assertJsonPath('cartao.email', 'secex@bb.com.br')
            ->assertJsonPath('cartao.telefones.0', '(61) 3493-9002');
    }

    public function test_formato_inesperado_conta_como_falha_da_fonte(): void
    {
        // 200 sem razão social = o provedor mudou o formato. Não pode virar cartão vazio.
        Http::fake([
            'brasilapi.com.br/*' => Http::response(['cnpj' => self::CNPJ, 'nome' => 'BANCO DO BRASIL SA']),
            'minhareceita.org/*' => Http::response($this->respostaBrasilApi()),
        ]);

        $this->consultar($this->cliente())->assertOk()->assertJsonPath('fonte', 'minhareceita');
    }

    public function test_cnpj_inexistente_em_todas_as_fontes_da_404(): void
    {
        Http::fake(['*' => Http::response(['message' => 'not found'], 404)]);

        $this->consultar($this->cliente())->assertNotFound()->assertJsonStructure(['erro']);
        $this->assertSame(0, CnpjConsulta::count());
    }

    public function test_todas_fora_do_ar_sem_consulta_anterior_da_503(): void
    {
        Http::fake(['*' => Http::response('fora', 503)]);

        $this->consultar($this->cliente())->assertStatus(503)->assertJsonStructure(['erro']);
    }

    public function test_todas_fora_do_ar_devolve_o_ultimo_cartao_marcado_como_desatualizado(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::sequence()
            ->push($this->respostaBrasilApi())
            ->push('fora', 503),
            'minhareceita.org/*' => Http::response('fora', 503),
            'open.cnpja.com/*' => Http::response('fora', 429),
        ]);
        $cliente = $this->cliente();
        $user = $this->usuario();

        $this->consultar($cliente, $user)->assertOk();
        $this->consultar($cliente, $user, atualizar: true)
            ->assertOk()
            ->assertJsonPath('desatualizado', true)
            ->assertJsonPath('cartao.razaoSocial', 'BANCO DO BRASIL SA');
    }

    public function test_cliente_com_cpf_nao_consulta(): void
    {
        Http::fake();

        $this->consultar($this->cliente(['cnpj' => '123.456.789-09']))->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_vendedor_nao_consulta_cliente_de_outra_carteira(): void
    {
        Http::fake();
        $vendedor = $this->usuario('vendedor', '000999');

        $this->consultar($this->cliente(['cod_vendedor' => '000010']), $vendedor)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_vendedor_consulta_cliente_da_propria_carteira(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);
        $vendedor = $this->usuario('vendedor', '000010');

        $this->consultar($this->cliente(['cod_vendedor' => '000010']), $vendedor)->assertOk();
    }

    public function test_diferencas_de_grafia_nao_sao_divergencia(): void
    {
        // TOTVS: "S/A", CEP com traço, razão social truncada. Nada disso é divergência.
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi([
            'razao_social' => 'BANCO DO BRASIL SA DIRECAO GERAL DE OPERACOES',
        ]))]);

        $this->consultar($this->cliente(['razao_social' => 'BANCO DO BRASIL S/A DIRECAO']))
            ->assertOk()
            ->assertJsonPath('divergencias', []);
    }

    public function test_sufixo_societario_invertido_nao_e_divergencia(): void
    {
        // Caso real do dev (2026-09-24): TOTVS "... AS", Receita "... SA".
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi([
            'razao_social' => 'IMIFARMA PRODUTOS FARMACEUTICOS E COSMETICOS SA',
        ]))]);

        $this->consultar($this->cliente(['razao_social' => 'IMIFARMA PRODUTOS FARMACEUTICOS E COSMETICOS AS']))
            ->assertOk()
            ->assertJsonPath('divergencias', []);
    }

    public function test_nome_abreviado_no_totvs_nao_e_divergencia(): void
    {
        // Caso real de produção (2026-09-24): o TOTVS abrevia palavra para caber no campo.
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi([
            'razao_social' => 'IMIFARMA PRODUTOS FARMACEUTICOS E COSMETICOS SA',
        ]))]);

        $this->consultar($this->cliente(['razao_social' => 'IMIFARMA PROD FARMA E COSMETICOS SA']))
            ->assertOk()
            ->assertJsonPath('divergencias', []);
    }

    public function test_nome_diferente_com_mesmo_comeco_continua_divergindo(): void
    {
        // Abreviação vale palavra a palavra, na mesma posição — não "qualquer prefixo".
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi([
            'razao_social' => 'IMIFARMA PRODUTOS FARMACEUTICOS E COSMETICOS SA',
        ]))]);

        $this->consultar($this->cliente(['razao_social' => 'IMIFARMA COMERCIO DE ALIMENTOS SA']))
            ->assertOk()
            ->assertJsonPath('divergencias.0.campo', 'Razão social');
    }

    public function test_aponta_onde_o_totvs_diverge_da_receita(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        $resposta = $this->consultar($this->cliente([
            'razao_social' => 'OUTRA EMPRESA LTDA',
            'cep' => '01310-100',
            'municipio' => 'BRASÍLIA', // acento não conta
        ]))->assertOk()->assertJsonPath('origemCadastro', 'TOTVS');
        $divergencias = $resposta->json('divergencias');

        $this->assertSame(['Razão social', 'CEP'], array_column($divergencias, 'campo'));
        $this->assertSame('01310-100', $divergencias[1]['cadastro']);
        $this->assertSame('70040-912', $divergencias[1]['receita']);
    }

    public function test_campo_vazio_no_totvs_nao_e_divergencia(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        $this->consultar($this->cliente(['cep' => null, 'municipio' => null]))
            ->assertOk()
            ->assertJsonPath('divergencias', []);
    }

    // ------------------------------------------------------------------ leads

    private function lead(array $atributos = []): Lead
    {
        return Lead::create([
            'origem' => Lead::ORIGEM_MANUAL,
            'cod_vendedor' => '000010',
            'nome' => 'Contato',
            'razao_social' => 'BANCO DO BRASIL SA',
            'cnpj' => '00000000000191',
            'cidade' => 'Brasília',
            'estado' => 'DF',
            ...$atributos,
        ]);
    }

    private function consultarLead(Lead $lead, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->usuario())->getJson(route('leads.cartaoCnpj', $lead));
    }

    public function test_lead_traz_o_cartao_e_compara_com_o_que_foi_digitado(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);

        $resposta = $this->consultarLead($this->lead(['cidade' => 'Goiânia']))->assertOk();

        $resposta->assertJsonPath('cartao.situacao', 'ATIVA')
            ->assertJsonPath('origemCadastro', 'Lead')
            ->assertJsonPath('divergencias.0.campo', 'Município')
            ->assertJsonPath('divergencias.0.cadastro', 'Goiânia');
        // Lead não tem CEP: nunca pode aparecer como divergência.
        $this->assertNotContains('CEP', array_column($resposta->json('divergencias'), 'campo'));
    }

    public function test_lead_e_cliente_com_o_mesmo_cnpj_reaproveitam_a_consulta(): void
    {
        Http::fake(['brasilapi.com.br/*' => Http::response($this->respostaBrasilApi())]);
        $user = $this->usuario();

        $this->consultar($this->cliente(), $user)->assertOk();
        $this->consultarLead($this->lead(), $user)->assertOk();

        Http::assertSentCount(1);
    }

    public function test_vendedor_nao_consulta_lead_de_outro(): void
    {
        Http::fake();
        $vendedor = $this->usuario('vendedor', '000999');

        $this->consultarLead($this->lead(['cod_vendedor' => '000010']), $vendedor)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_lead_sem_cnpj_nao_consulta(): void
    {
        Http::fake();

        $this->consultarLead($this->lead(['cnpj' => null]))->assertStatus(422);

        Http::assertNothingSent();
    }
}
