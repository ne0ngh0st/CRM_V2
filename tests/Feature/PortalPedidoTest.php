<?php

namespace Tests\Feature;

use App\Jobs\EnviarPedidoAoPortalJob;
use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
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
 * Desde 2026-09-25 o payload é 100% CHAVE DE NEGÓCIO do TOTVS — `sellerCode`,
 * `clientCode`+`clientStore`, `productCode` — e o Portal resolve os ids internos dele.
 * Não há mais de-para local (`portal_*`), nem guarda de CNPJ nossa: a resolução é deles
 * (decisão do Tony, confiar). O caso mais importante aqui é `test_caminho_feliz_*`, que
 * trava o FORMATO do corpo — o resto do fluxo (fila, idempotência, notificação) já era.
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

    /** O que o modal manda: a data de entrega desejada (e a transportadora, no FOB). */
    private function envio(array $extra = []): array
    {
        return array_merge(['data_entrega' => now()->addDays(10)->format('Y-m-d')], $extra);
    }

    /** Cenário ligado: cliente do TOTVS, orçamento aprovado e um item de catálogo. */
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

        $orcamento = Orcamento::create([
            'user_id' => $vendedor->id,
            'cliente_id' => ($sobrescreve['semCliente'] ?? false) ? null : $cliente->id,
            'cliente_nome' => 'CENTRAL SUPERMERCADOS',
            'cliente_cnpj' => '08019075000207',
            'tipo_produto_servico' => 'servico',
            'tipo_frete' => $sobrescreve['frete'] ?? 'CIF',
            'tipo_venda' => array_key_exists('tipoVenda', $sobrescreve) ? $sobrescreve['tipoVenda'] : 'consumo',
            'condicao_pagamento_codigo' => array_key_exists('condicao', $sobrescreve) ? $sobrescreve['condicao'] : '028',
            'forma_pagamento' => $sobrescreve['formaPagamento'] ?? '28 DDL',
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
            'tipo_venda' => 'consumo',
            'condicao_pagamento_codigo' => '028',
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

    public function test_orcamento_sem_cliente_vinculado_e_recusado(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario(['semCliente' => true]);

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id), $this->envio());

        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);
        $this->assertNull($orcamento->fresh()->portal_idempotency_key);
    }

    /**
     * 🚨 O teste do FORMATO do corpo. Chaves de negócio do TOTVS, e o Portal resolve o
     * resto. Se algum campo voltar a ser um id interno, isto quebra.
     */
    public function test_caminho_feliz_congela_o_payload_e_enfileira(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio())
            ->assertRedirect();

        Bus::assertDispatched(EnviarPedidoAoPortalJob::class);

        $orcamento->refresh();
        $corpo = $orcamento->portal_payload;

        $this->assertNotNull($orcamento->portal_idempotency_key);
        $this->assertSame('010150', $corpo['sellerCode']);
        $this->assertSame('041626', $corpo['clientCode']);
        $this->assertSame('0002', $corpo['clientStore']);
        // Centavos, não reais: R$ 3,00 × 900.
        $this->assertSame(300, $corpo['products'][0]['unitPrice']);
        $this->assertSame(900, $corpo['products'][0]['quantity']);
        $this->assertSame('V23730', $corpo['products'][0]['productCode']);
        $this->assertSame('SALE', $corpo['products'][0]['invoiceType']);
        $this->assertSame('CIF', $corpo['shippingType']);
        $this->assertSame(now()->addDays(10)->format('Y-m-d'), $corpo['deliveryTime']);
    }

    /**
     * 🚨 Retentar com chave NOVA é o único caminho que ainda duplica pedido no Portal.
     */
    public function test_segunda_tentativa_reusa_a_mesma_chave(): void
    {
        Bus::fake();
        [$vendedor, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id), $this->envio());
        $chave = $orcamento->fresh()->portal_idempotency_key;

        $this->actingAs($this->admin())->post(route('orcamentos.portal', $orcamento->id), $this->envio());

        $this->assertSame($chave, $orcamento->fresh()->portal_idempotency_key);
    }

    public function test_job_grava_o_numero_do_pedido_e_avisa(): void
    {
        Http::fake([
            '*/v1/api/orders' => Http::response(['payload' => ['id' => 4512]], 201),
        ]);

        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
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

        $gerador->preparar($orcamento, '2026-10-15');
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
            '*/v1/api/orders' => Http::response(['message' => 'Cliente inexistente'], 404),
        ]);

        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        // Recusa apaga a chave (nada foi gravado lá), então cada tentativa é um preparo novo.
        foreach ([1, 2] as $tentativa) {
            $gerador->preparar($orcamento->fresh(), '2026-10-15');
            $gerador->enviar($orcamento->fresh());
        }

        // Se a chave de idempotência do NotificacaoService fosse usada aqui, a segunda
        // falha ficaria muda e o vendedor concluiria que deu certo.
        $this->assertSame(2, Notificacao::where('user_id', $vendedor->id)
            ->where('tipo', 'portal_pedido_erro')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Versão da API de 2026-09-30: data de entrega, transportadora e resposta do ERP
    |--------------------------------------------------------------------------
    */

    public function test_sem_data_de_entrega_nada_e_preparado(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), [])
            ->assertSessionHasErrors('data_entrega');

        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);
        $this->assertNull($orcamento->fresh()->portal_idempotency_key);
    }

    public function test_data_de_hoje_ou_passada_e_recusada(): void
    {
        [, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio(['data_entrega' => now()->format('Y-m-d')]))
            ->assertSessionHasErrors('data_entrega');
    }

    public function test_fob_leva_a_transportadora_ate_o_corpo(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario(['frete' => 'FOB']);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio(['transportadora' => 'T00042']));

        $corpo = $orcamento->fresh()->portal_payload;
        $this->assertSame('FOB', $corpo['shippingType']);
        $this->assertSame('T00042', $corpo['carrierCode']);
    }

    /**
     * 🚨 Não existe endpoint de consulta: a resposta é a única forma de saber o que o
     * ERP gravou. E a data que vale é a DELE — foi pedida 15/10 e voltou 17/10, com 201
     * e sem aviso. O vendedor precisa ler a data ajustada, não a que digitou.
     */
    public function test_resposta_do_erp_e_guardada_e_a_data_ajustada_vai_no_aviso(): void
    {
        Http::fake([
            '*/v1/api/orders' => Http::response(['payload' => [
                'id' => 4512,
                'status' => 'AWAITING_APPROVAL',
                'shippingType' => 'CIF',
                'carrierCode' => 'T00042',
                'shippingCost' => 18500,
                'deliveryTime' => '2026-10-17',
                'expectedBillingDate' => '2026-10-13',
            ]], 201),
        ]);

        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
        $gerador->enviar($orcamento->fresh());

        $orcamento->refresh();
        $this->assertSame('2026-10-17', $orcamento->portal_resposta['deliveryTime']);
        $this->assertSame(18500, $orcamento->portal_resposta['shippingCost']);

        $aviso = Notificacao::where('user_id', $vendedor->id)->where('tipo', 'portal_pedido_criado')->value('mensagem');
        $this->assertStringContainsString('17/10/2026', $aviso);
        $this->assertStringContainsString('15/10/2026', $aviso);
        $this->assertStringContainsString('13/10/2026', $aviso);
    }

    /**
     * O ERP recusou a data (400, nada gravado lá). A próxima tentativa vai com OUTRA
     * data — logo, outro corpo — e por isso precisa de chave nova: a antiga com corpo
     * diferente seria 409 de chave reutilizada.
     */
    public function test_recusa_libera_nova_data_com_chave_nova(): void
    {
        Http::fake([
            '*/v1/api/orders' => Http::response([
                'message' => 'A data de entrega desejada no pedido é inválida. A próxima data válida seria 2026-10-20',
            ], 400),
        ]);

        [, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
        $chaveAntiga = $orcamento->fresh()->portal_idempotency_key;
        $gerador->enviar($orcamento->fresh());

        $orcamento->refresh();
        $this->assertNull($orcamento->portal_idempotency_key);
        $this->assertStringContainsString('2026-10-20', $orcamento->portal_erro);

        $this->assertFalse($gerador->preparar($orcamento, '2026-10-20'));

        $orcamento->refresh();
        $this->assertNotSame($chaveAntiga, $orcamento->portal_idempotency_key);
        $this->assertSame('2026-10-20', $orcamento->portal_payload['deliveryTime']);
    }

    /**
     * 🚨 Resultado INCERTO (fila, ou o Portal não respondeu): o pedido pode existir.
     * O reenvio é a requisição idêntica com a mesma chave — uma data nova digitada no
     * modal NÃO pode entrar, senão vira 409 ou, pior, um segundo pedido.
     */
    public function test_reenvio_de_resultado_incerto_mantem_corpo_e_chave(): void
    {
        [, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
        $chave = $orcamento->fresh()->portal_idempotency_key;

        $this->assertTrue($gerador->preparar($orcamento->fresh(), '2026-12-01'));

        $orcamento->refresh();
        $this->assertSame($chave, $orcamento->portal_idempotency_key);
        $this->assertSame('2026-10-15', $orcamento->portal_payload['deliveryTime']);
    }

    public function test_reenvio_nao_exige_data_no_modal(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario();
        app(GeradorDePedidoNoPortal::class)->preparar($orcamento, '2026-10-15');

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), [])
            ->assertSessionHasNoErrors();

        Bus::assertDispatched(EnviarPedidoAoPortalJob::class);
    }

    /**
     * Sem isto o orçamento ficava calado para sempre quando o Portal não respondia:
     * nem pedido, nem erro. A chave fica — o pedido pode ter sido criado.
     */
    public function test_job_esgotado_avisa_e_mantem_a_chave(): void
    {
        [$vendedor, $orcamento] = $this->cenario();
        app(GeradorDePedidoNoPortal::class)->preparar($orcamento, '2026-10-15');
        $chave = $orcamento->fresh()->portal_idempotency_key;

        (new EnviarPedidoAoPortalJob($orcamento->id))->failed(new \RuntimeException('timeout'));

        $orcamento->refresh();
        $this->assertSame($chave, $orcamento->portal_idempotency_key);
        $this->assertStringContainsString('não duplica', $orcamento->portal_erro);
        $this->assertTrue($orcamento->temEnvioIncertoAoPortal());
        $this->assertDatabaseHas('notificacoes', ['user_id' => $vendedor->id, 'tipo' => 'portal_pedido_erro']);
    }

    /**
     * Regressão de 2026-10-01, no homolog: o Portal devolveu um SOAP-ERROR do Protheus
     * com 400+ caracteres, o INSERT da notificação estourou o varchar(255), o vendedor
     * ficou sem aviso e o job retentou até falhar por outro motivo.
     */
    public function test_erro_longo_do_portal_ainda_avisa_e_guarda_o_texto_inteiro(): void
    {
        $longo = 'Erro na comunicação com o protheus: SOAP-ERROR: Parsing WSDL: '.str_repeat('x', 400);

        Http::fake([
            '*/v1/api/orders' => Http::response(['message' => $longo], 400),
        ]);

        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
        $gerador->enviar($orcamento->fresh());

        $this->assertSame($longo, $orcamento->fresh()->portal_erro);

        $aviso = Notificacao::where('user_id', $vendedor->id)->where('tipo', 'portal_pedido_erro')->value('mensagem');
        $this->assertNotNull($aviso);
        $this->assertLessThanOrEqual(255, mb_strlen($aviso));
        $this->assertStringStartsWith('Erro na comunicação com o protheus', $aviso);
    }

    /*
    |--------------------------------------------------------------------------
    | Tipo de venda (consumo / revenda / serviço) — 2026-10-01
    |--------------------------------------------------------------------------
    */

    public function test_formulario_exige_o_tipo_de_venda(): void
    {
        $vendedor = $this->vendedor();

        $this->actingAs($vendedor)->post(route('orcamentos.store'), [
            'cliente_nome' => 'CENTRAL SUPERMERCADOS',
            'tipo_frete' => 'CIF',
            'condicao_pagamento_codigo' => '028',
            'tipo_produto_servico' => 'produto',
            'itens' => [['tipo_item' => 'bobina', 'descricao' => 'BOBINA', 'quantidade' => 10, 'valor_unitario' => 3]],
        ])->assertSessionHasErrors('tipo_venda');

        $this->assertSame(0, Orcamento::count());
    }

    /** Orçamento anterior ao campo: o modal pede o tipo, e ele fica gravado no orçamento. */
    public function test_orcamento_antigo_escolhe_o_tipo_no_envio_e_ele_fica_gravado(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario(['tipoVenda' => null]);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio())
            ->assertSessionHasErrors('tipo_venda');
        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio(['tipo_venda' => 'revenda']))
            ->assertSessionHasNoErrors();

        $orcamento->refresh();
        $this->assertSame('revenda', $orcamento->tipo_venda);
        $this->assertSame('RESALE', $orcamento->portal_payload['products'][0]['invoiceType']);
    }

    /** O tipo escolhido no formulário não é trocado por um campo que o modal nem mostra. */
    public function test_envio_nao_sobrescreve_o_tipo_que_veio_do_formulario(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario(['tipoVenda' => 'consumo']);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio(['tipo_venda' => 'revenda']));

        $orcamento->refresh();
        $this->assertSame('consumo', $orcamento->tipo_venda);
        $this->assertSame('SALE', $orcamento->portal_payload['products'][0]['invoiceType']);
    }

    /*
    |--------------------------------------------------------------------------
    | Condição de pagamento do Protheus — 2026-10-01
    |--------------------------------------------------------------------------
    */

    /** Orçamento antigo (só texto): o modal pede a condição, e ela fica gravada com a descrição oficial. */
    public function test_orcamento_antigo_escolhe_a_condicao_no_envio_e_ela_fica_gravada(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario(['condicao' => null, 'formaPagamento' => 'COMBINAR']);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio())
            ->assertSessionHasErrors('condicao_pagamento_codigo');
        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio(['condicao_pagamento_codigo' => '069']))
            ->assertSessionHasNoErrors();

        $orcamento->refresh();
        $this->assertSame('069', $orcamento->condicao_pagamento_codigo);
        $this->assertSame('28 / 42 / 56 DDL', $orcamento->forma_pagamento);
        $this->assertSame('069', $orcamento->portal_payload['paymentConditionCode']);
    }

    /** A tela leva a sugestão pronta: texto antigo reconhecido vira a condição pré-escolhida no modal. */
    public function test_listagem_sugere_a_condicao_a_partir_do_texto_antigo(): void
    {
        [, $orcamento] = $this->cenario(['condicao' => null, 'formaPagamento' => '28/35/42DDL']);

        $this->actingAs($this->admin())
            ->get(route('orcamentos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orcamentos.data.0.condicaoPagamentoCodigo', null)
                ->where('orcamentos.data.0.condicaoPagamentoSugerida', '067')
                ->has('condicoesPagamento', 391));
    }

    /** O envio não troca a condição que veio do formulário. */
    public function test_envio_nao_sobrescreve_a_condicao_do_formulario(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio(['condicao_pagamento_codigo' => '001']));

        $this->assertSame('028', $orcamento->fresh()->portal_payload['paymentConditionCode']);
    }

    /*
    |--------------------------------------------------------------------------
    | Quem recebe o resultado — 2026-10-07, primeiro envio real em produção
    |--------------------------------------------------------------------------
    | O admin clicou, o Portal recusou, e o aviso de erro foi para o sino da
    | VENDEDORA, enquanto o admin ficou sem saber de nada.
    */

    public function test_o_clique_leva_quem_clicou_para_a_fila(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('orcamentos.portal', $orcamento->id), $this->envio());

        Bus::assertDispatched(EnviarPedidoAoPortalJob::class, fn ($job) => $job->solicitanteId === $admin->id);
    }

    public function test_recusa_avisa_quem_clicou_e_nao_o_dono(): void
    {
        Http::fake(['*/v1/api/orders' => Http::response(['message' => 'Vendedor não encontrado'], 404)]);
        [$vendedor, $orcamento] = $this->cenario();
        $admin = $this->admin();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
        $gerador->enviar($orcamento->fresh(), $admin);

        $aviso = Notificacao::where('user_id', $admin->id)->where('tipo', 'portal_pedido_erro')->first();
        $this->assertNotNull($aviso);
        $this->assertSame('Vendedor não encontrado', $aviso->mensagem);
        // Orçamento de outra pessoa: o título diz de quem é.
        $this->assertStringContainsString($vendedor->display_name ?: $vendedor->name, $aviso->titulo);

        $this->assertSame(0, Notificacao::where('user_id', $vendedor->id)->count());
    }

    public function test_pedido_criado_avisa_o_dono_e_quem_clicou(): void
    {
        Http::fake(['*/v1/api/orders' => Http::response(['payload' => ['id' => 4512]], 201)]);
        [$vendedor, $orcamento] = $this->cenario();
        $admin = $this->admin();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
        $gerador->enviar($orcamento->fresh(), $admin);

        $this->assertSame(1, Notificacao::where('user_id', $vendedor->id)->where('tipo', 'portal_pedido_criado')->count());
        $this->assertSame(1, Notificacao::where('user_id', $admin->id)->where('tipo', 'portal_pedido_criado')->count());
        // Para o dono, o título não repete o próprio nome.
        $this->assertStringNotContainsString('(', Notificacao::where('user_id', $vendedor->id)->value('titulo'));
    }

    public function test_dono_que_clica_recebe_um_aviso_so(): void
    {
        Http::fake(['*/v1/api/orders' => Http::response(['payload' => ['id' => 4512]], 201)]);
        [$vendedor, $orcamento] = $this->cenario();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-15');
        $gerador->enviar($orcamento->fresh(), $vendedor);

        $this->assertSame(1, Notificacao::where('user_id', $vendedor->id)->count());
    }

    public function test_sem_resposta_avisa_quem_clicou(): void
    {
        [$vendedor, $orcamento] = $this->cenario();
        $admin = $this->admin();
        app(GeradorDePedidoNoPortal::class)->preparar($orcamento, '2026-10-15');

        (new EnviarPedidoAoPortalJob($orcamento->id, $admin->id))->failed(new \RuntimeException('timeout'));

        $this->assertSame(1, Notificacao::where('user_id', $admin->id)->where('tipo', 'portal_pedido_erro')->count());
        $this->assertSame(0, Notificacao::where('user_id', $vendedor->id)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Data de entrega — 2026-10-07, primeiro pedido real em produção
    |--------------------------------------------------------------------------
    | O Protheus recusou 17/10 e sugeriu 26/10. Quem enviou precisa saber que a data
    | digitada não vale, e entrega mais de 3 meses à frente é barrada no CRM.
    */

    public function test_entrega_alem_de_tres_meses_e_bloqueada(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio([
                'data_entrega' => now()->addMonthsNoOverflow(3)->addDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('data_entrega');

        Bus::assertNotDispatched(EnviarPedidoAoPortalJob::class);
    }

    public function test_entrega_no_limite_de_tres_meses_passa(): void
    {
        Bus::fake();
        [, $orcamento] = $this->cenario();

        $this->actingAs($this->admin())
            ->post(route('orcamentos.portal', $orcamento->id), $this->envio([
                'data_entrega' => now()->addMonthsNoOverflow(3)->toDateString(),
            ]))
            ->assertSessionHasNoErrors();

        Bus::assertDispatched(EnviarPedidoAoPortalJob::class);
    }

    public function test_recusa_de_data_explica_a_data_pedida_e_a_sugerida(): void
    {
        $doErp = 'A data de entrega desejada no pedido é inválida. A próxima data válida seria 2026-10-26.';
        Http::fake(['*/v1/api/orders' => Http::response(['message' => $doErp], 400)]);
        [, $orcamento] = $this->cenario();
        $admin = $this->admin();
        $gerador = app(GeradorDePedidoNoPortal::class);

        $gerador->preparar($orcamento, '2026-10-17');
        $gerador->enviar($orcamento->fresh(), $admin);

        $aviso = Notificacao::where('user_id', $admin->id)->value('mensagem');
        $this->assertStringContainsString('17/10/2026', $aviso);
        $this->assertStringContainsString('26/10/2026', $aviso);
        // A mensagem do ERP fica literal no orçamento: é dela que a tela lê a sugestão.
        $this->assertSame($doErp, $orcamento->fresh()->portal_erro);
    }

    public function test_listagem_leva_a_data_sugerida_para_o_modal(): void
    {
        [, $orcamento] = $this->cenario();
        $orcamento->forceFill([
            'portal_erro' => 'A data de entrega desejada no pedido é inválida. A próxima data válida seria 2026-10-26.',
        ])->save();

        $this->actingAs($this->admin())
            ->get(route('orcamentos.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orcamentos.data.0.portalDataSugerida', '2026-10-26')
                ->where('portalEntregaMaxima', now()->addMonthsNoOverflow(3)->toDateString()));
    }

    public function test_recusa_que_nao_e_de_data_nao_inventa_sugestao(): void
    {
        $this->assertNull(GeradorDePedidoNoPortal::dataSugeridaNaRecusa('Vendedor não encontrado'));
        $this->assertNull(GeradorDePedidoNoPortal::dataSugeridaNaRecusa(null));
        $this->assertSame('2026-10-26', GeradorDePedidoNoPortal::dataSugeridaNaRecusa(
            'A data de entrega desejada no pedido é inválida. A próxima data válida seria 2026-10-26.'
        ));
    }
}
