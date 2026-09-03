<?php

namespace Tests\Feature;

use App\Jobs\PromoverCapturasWpPendentesJob;
use App\Models\Lead;
use App\Models\MarketingWpFormulario;
use App\Models\MarketingWpLeadRaw;
use App\Models\Notificacao;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Marketing\WpLeadIngestor;
use App\Services\Notificacao\NotificacaoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notificação de lead vindo do formulário do site.
 *
 * Antes disto, um lead do site entrava em silêncio: quem cuida da fila só descobria
 * abrindo a /leads por conta própria. E uma captura que falhava 5 vezes não avisava
 * ninguém — o próprio CLAUDE.md mandava consultar a coluna `erro` no banco à mão.
 */
class NotificacaoLeadWordpressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // O broadcast do sino não é o objeto deste teste e exigiria o Reverb de pé.
        Event::fake();
    }

    private function vendedorCom(string $codVendedor): User
    {
        $user = User::factory()->create();
        $user->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor]);

        return $user;
    }

    /**
     * A linha `*` de marketing_wp_formularios JÁ EXISTE (a migration a insere e o
     * MarketingWpFormularioSeeder é a rede). Criar de novo estoura a unique — por isso
     * aqui é update, não create.
     */
    private function donoPadrao(string $codVendedor): void
    {
        MarketingWpFormulario::updateOrCreate(
            ['identificador' => '*'],
            ['nome' => 'Padrao', 'cod_vendedor' => $codVendedor],
        );
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** Captura crua pronta para promover, como o webhook a grava. */
    private function capturaDe(array $campos, string $fonte = WpLeadIngestor::FONTE_WEBHOOK): MarketingWpLeadRaw
    {
        return MarketingWpLeadRaw::create([
            'recebido_em' => now(),
            'payload_json' => json_encode(['fonte' => $fonte, 'parsed' => $campos]),
            'payload_hash' => hash('sha256', json_encode($campos).microtime()),
            'fonte' => $fonte,
            'tentativas' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function camposValidos(array $extra = []): array
    {
        return array_merge([
            'name' => 'Joana Prado',
            'empresa' => 'Mercado Bom Preco LTDA',
            'email' => 'joana@bompreco.com.br',
            'mc4wp-PHONE' => '(11) 98888-7777',
            'cnpj' => '12.345.678/0001-90',
            'mensagem' => 'Quero orcamento de bobina termica.',
        ], $extra);
    }

    #[Test]
    public function test_dono_do_lead_e_notificado_quando_a_captura_vira_lead(): void
    {
        $dono = $this->vendedorCom('010617');
        $this->donoPadrao('010617');

        $lead = app(WpLeadIngestor::class)->promover($this->capturaDe($this->camposValidos()));

        $this->assertNotNull($lead);
        $notificacao = Notificacao::where('user_id', $dono->id)->where('tipo', 'lead_wordpress')->first();
        $this->assertNotNull($notificacao, 'o dono da fila tem que ser avisado');
        $this->assertStringContainsString('Mercado Bom Preco', (string) $notificacao->mensagem);
    }

    /**
     * `cod_vendedor` é documentadamente NÃO único neste projeto (há contas compartilhando
     * código). Quem é avisado tem que ser quem ENXERGA o lead na listagem — e a /leads
     * filtra por cod_vendedor, não por user_id.
     */
    #[Test]
    public function test_todos_os_usuarios_do_mesmo_codigo_sao_notificados(): void
    {
        $a = $this->vendedorCom('010617');
        $b = $this->vendedorCom('010617');
        $this->donoPadrao('010617');

        app(WpLeadIngestor::class)->promover($this->capturaDe($this->camposValidos()));

        $this->assertDatabaseHas('notificacoes', ['user_id' => $a->id, 'tipo' => 'lead_wordpress']);
        $this->assertDatabaseHas('notificacoes', ['user_id' => $b->id, 'tipo' => 'lead_wordpress']);
    }

    /**
     * A falha mais silenciosa desta integração: sem `cod_vendedor` NINGUÉM vê o lead na
     * listagem (o escopo filtra por código), então o contato do cliente entra e some.
     * Mandar para o admin transforma o silêncio em sinal.
     */
    #[Test]
    public function test_lead_sem_dono_notifica_o_admin(): void
    {
        $admin = $this->admin();
        // O modo de falha real é a linha `*` AUSENTE (a coluna cod_vendedor é NOT NULL,
        // então "dono nulo" não existe no schema). Sem ela, o lead nasce sem código.
        MarketingWpFormulario::query()->delete();

        $lead = app(WpLeadIngestor::class)->promover($this->capturaDe($this->camposValidos()));

        $this->assertNull($lead->cod_vendedor);
        $this->assertDatabaseHas('notificacoes', ['user_id' => $admin->id, 'tipo' => 'lead_wordpress']);
    }

    /**
     * ⚠️ O teste mais importante deste arquivo, e ele precisa de um cuidado para valer:
     * chamar `promover()` três vezes seguidas NÃO exercita nada, porque o método retorna
     * cedo assim que `lead_id` está preenchido. O retry só acontece de verdade quando o
     * `lead_id` NÃO chegou a ser gravado — banco em failover entre o `create` do lead e o
     * `forceFill` da captura, que é justamente o motivo de os dois passos não
     * compartilharem transação.
     *
     * Aqui isso é simulado zerando o `lead_id`. Sem a chave de idempotência apontando
     * para a CAPTURA, o vendedor passaria a receber o mesmo aviso de minuto em minuto,
     * porque o job roda a cada minuto. Verificado por mutação: tirando a referência, este
     * teste falha.
     */
    #[Test]
    public function test_retry_da_promocao_nao_duplica_a_notificacao(): void
    {
        $dono = $this->vendedorCom('010617');
        $this->donoPadrao('010617');
        $captura = $this->capturaDe($this->camposValidos());

        $ingestor = app(WpLeadIngestor::class);
        $ingestor->promover($captura);

        // O `forceFill` não chegou a persistir: é o estado em que o job encontra a linha.
        $captura->forceFill(['lead_id' => null])->save();
        $ingestor->promover($captura->fresh());
        $captura->forceFill(['lead_id' => null])->save();
        $ingestor->promover($captura->fresh());

        $this->assertSame(1, Notificacao::where('user_id', $dono->id)->where('tipo', 'lead_wordpress')->count());
        // E o lead também não foi duplicado — o e-mail reaproveita o existente.
        $this->assertSame(1, Lead::where('origem', Lead::ORIGEM_WORDPRESS)->count());
    }

    /**
     * O botão "Enviar lead de teste" existe para provar webhook e promoção sem sujar a
     * carteira de ninguém — avisar o dono real da fila derrotaria o propósito.
     *
     * ⚠️ Consequência assumida: o botão de teste NÃO prova mais o caminho da notificação.
     * Para verificar isso ponta a ponta é preciso uma captura real ou um `promover()`
     * disparado via tinker.
     */
    #[Test]
    public function test_lead_de_teste_nao_notifica_ninguem(): void
    {
        $this->vendedorCom('010617');
        $this->admin();
        $this->donoPadrao('010617');

        app(WpLeadIngestor::class)->promover(
            $this->capturaDe($this->camposValidos(), WpLeadIngestor::FONTE_TESTE),
        );

        $this->assertSame(0, Notificacao::where('tipo', 'lead_wordpress')->count());
    }

    /**
     * Perder um aviso é aceitável; perder o lead do cliente não. O WordPress dispara uma
     * vez e nunca reenvia.
     */
    #[Test]
    public function test_falha_ao_notificar_nao_impede_o_lead_de_nascer(): void
    {
        // Nenhum usuário com esse código E nenhum admin: `destinatariosDoLead` devolve
        // coleção vazia e o laço não roda. O lead precisa nascer do mesmo jeito.
        $this->donoPadrao('010617');

        $lead = app(WpLeadIngestor::class)->promover($this->capturaDe($this->camposValidos()));

        $this->assertNotNull($lead);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'origem' => Lead::ORIGEM_WORDPRESS]);
    }

    /**
     * ⚠️ O que garante "uma vez só" aqui NÃO é a chave de idempotência: é o escopo
     * `pendentes()` (lead_id nulo E tentativas < MAX), que deixa de devolver a captura
     * assim que ela trava. A chave existe para o caso que este teste não alcança — dois
     * workers processando o mesmo lote em paralelo, ambos vendo tentativas = MAX-1.
     * Não confundir os dois mecanismos ao mexer aqui.
     */
    #[Test]
    public function test_captura_travada_avisa_o_admin_uma_vez_so(): void
    {
        $admin = $this->admin();
        // Payload sem nenhum campo comercial: falha DEFINITIVA, vai direto ao teto.
        $captura = $this->capturaDe(['campo_desconhecido' => 'xyz']);

        (new PromoverCapturasWpPendentesJob)->handle(app(WpLeadIngestor::class), app(NotificacaoService::class));
        $captura->refresh();

        $this->assertNull($captura->lead_id);
        $this->assertGreaterThanOrEqual(MarketingWpLeadRaw::MAX_TENTATIVAS, $captura->tentativas);
        $this->assertSame(1, Notificacao::where('user_id', $admin->id)->where('tipo', 'captura_wp_travada')->count());

        // O job roda de minuto a minuto e a captura continua travada: não pode virar
        // um aviso por minuto.
        (new PromoverCapturasWpPendentesJob)->handle(app(WpLeadIngestor::class), app(NotificacaoService::class));

        $this->assertSame(1, Notificacao::where('user_id', $admin->id)->where('tipo', 'captura_wp_travada')->count());
    }
}
