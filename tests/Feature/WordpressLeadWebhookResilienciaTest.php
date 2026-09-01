<?php

namespace Tests\Feature;

use App\Jobs\ExpurgarCapturasWpJob;
use App\Jobs\PromoverCapturasWpPendentesJob;
use App\Models\Lead;
use App\Models\MarketingWpFormulario;
use App\Models\MarketingWpLeadRaw;
use App\Services\Marketing\WpLeadIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As garantias que fazem a captura do site funcionar sem ninguém cuidar.
 *
 * O teste do caminho feliz mora em WordpressLeadWebhookTest. Aqui está o que
 * acontece quando algo dá errado — que é o caso que importa, porque o
 * WordPress dispara o webhook UMA vez e nunca reenvia.
 */
class WordpressLeadWebhookResilienciaTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'token-de-teste-wp';

    private function comSegredo(): void
    {
        config(['marketing.wp_webhook_secret' => self::SEGREDO]);
    }

    /**
     * O caminho que o plugin do site realmente consegue usar: ele só tem campo
     * de URL, não tem onde pôr header. Se este teste cair, a integração para
     * de funcionar em produção mesmo com todo o resto verde.
     */
    public function test_token_na_query_string_autentica(): void
    {
        $this->comSegredo();

        $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'nome' => 'Carla',
            'email' => 'carla@empresa.com',
        ])->assertCreated();

        $this->assertSame(1, Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->count());
    }

    /**
     * O formulário REAL do site (CF7 id 83 em /fale-conosco), com os nomes de
     * campo lidos do HTML dele — não inventados.
     *
     * ⚠️ Este é o teste que pega o defeito que quase passou: o telefone se
     * chama `mc4wp-PHONE` (o Mailchimp prefixa o que controla) e não batia com
     * nenhum alias. O lead nascia sem telefone, sem CNPJ, sem estado e sem
     * segmento — com nome e e-mail e mais nada, e o vendedor sem como ligar.
     */
    public function test_formulario_real_do_site_mapeia_todos_os_campos(): void
    {
        $this->comSegredo();

        $this->post('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'name' => 'Joana Prado',
            'empresa' => 'Mercado Bom Preço LTDA',
            'segmento' => 'Supermercados',
            'cnpj' => '12.345.678/0001-90',
            'estado' => 'São Paulo',
            'cidade' => 'Guarulhos',
            'endereco' => 'Rua das Palmeiras, 100',
            'email' => 'joana@bompreco.com.br',
            'mc4wp-PHONE' => '(11) 98888-7777',
            'assunto' => 'Orçamentos',
            'itens' => ['Bobinas', 'Etiquetas'],
            'mensagem' => 'Gostaria de um orçamento de bobinas térmicas.',
        ])->assertCreated();

        $lead = Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->firstOrFail();

        $this->assertSame('Joana Prado', $lead->nome);
        $this->assertSame('Mercado Bom Preço LTDA', $lead->razao_social);
        $this->assertSame('joana@bompreco.com.br', $lead->email);
        $this->assertSame('(11) 98888-7777', $lead->telefone, 'o mc4wp-PHONE tem que virar telefone');
        $this->assertSame('12.345.678/0001-90', $lead->cnpj);
        $this->assertSame('SP', $lead->estado, '"São Paulo" tem que virar sigla, nunca "Sã"');
        $this->assertSame('Guarulhos', $lead->cidade);
        $this->assertSame('Rua das Palmeiras, 100', $lead->endereco);
        $this->assertSame('Supermercados', $lead->segmento);
        $this->assertSame('010617', $lead->cod_vendedor);

        // A mensagem e os itens não têm coluna, mas não podem sumir.
        $envelope = MarketingWpLeadRaw::query()->firstOrFail()->payload_json;
        $this->assertStringContainsString('bobinas térmicas', $envelope);
        $this->assertStringContainsString('Etiquetas', $envelope);
    }

    /** UF já em sigla passa direto; texto que não é estado não vira sigla errada. */
    public function test_estado_invalido_fica_nulo_em_vez_de_truncado(): void
    {
        $this->comSegredo();

        $this->post('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'name' => 'Teste UF',
            'email' => 'uf@empresa.com',
            'estado' => 'nao sei',
        ])->assertCreated();

        $this->assertNull(Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->value('estado'));
    }

    /** Regra de ouro nº 2: SAC e Licitação têm sistema próprio, não são do CRM-V2. */
    public function test_assunto_nao_comercial_fica_na_staging_sem_virar_lead(): void
    {
        $this->comSegredo();

        $resposta = $this->post('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'name' => 'Reclamante',
            'email' => 'reclamacao@cliente.com',
            'mc4wp-PHONE' => '11988887777',
            'assunto' => 'SAC',
        ])->assertCreated();

        $staging = MarketingWpLeadRaw::query()->find($resposta->json('staging_id'));
        $this->assertNull($staging->lead_id);
        $this->assertStringContainsString('assunto_nao_comercial', $staging->erro);
        $this->assertSame(0, Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->count());

        // Guardado inteiro: se um dia mudar de ideia, o dado está lá.
        $this->assertStringContainsString('reclamacao@cliente.com', $staging->payload_json);
    }

    /** Formulário sem campo de assunto (a newsletter do rodapé) não pode nascer bloqueado. */
    public function test_formulario_sem_assunto_continua_virando_lead(): void
    {
        $this->comSegredo();

        $this->post('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'your-name' => 'Sem Assunto',
            'your-email' => 'sem.assunto@empresa.com',
        ])->assertCreated();

        $this->assertSame(1, Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->count());
    }

    public function test_token_errado_na_query_string_devolve_401(): void
    {
        $this->comSegredo();

        $this->postJson('/webhooks/wordpress-leads?token=chute', ['nome' => 'Carla'])
            ->assertUnauthorized();

        $this->assertSame(0, MarketingWpLeadRaw::query()->count());
    }

    /**
     * O throttle é a única barreira antes do banco depois que alguém tem o
     * token — e o token viaja na URL, onde vaza mais fácil (access log).
     *
     * ⚠️ Provado aqui e não por rajada de curl de propósito: contra o
     * `artisan serve`, que atende uma requisição por vez, 130 chamadas levam
     * mais que os 60s da janela e o limite nunca aparece. Aquilo dá verde sem
     * provar nada — o teste roda em processo e é determinístico.
     */
    public function test_rajada_acima_do_limite_recebe_429(): void
    {
        $this->comSegredo();

        $ultimo = null;
        for ($i = 0; $i < 121; $i++) {
            $ultimo = $this->postJson('/webhooks/wordpress-leads?token=chute-errado', ['x' => 1]);
            if ($ultimo->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertSame(429, $ultimo->getStatusCode(), 'o throttle do webhook não está ativo');
        $this->assertSame(0, MarketingWpLeadRaw::query()->count());
    }

    public function test_payload_gigante_e_recusado_antes_de_gravar(): void
    {
        $this->comSegredo();

        $this->call(
            'POST',
            '/webhooks/wordpress-leads?token='.self::SEGREDO,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['nome' => str_repeat('a', 300000)]),
        )->assertStatus(413);

        $this->assertSame(0, MarketingWpLeadRaw::query()->count());
    }

    /** Retry do WordPress (timeout) não pode virar dois leads na carteira. */
    public function test_mesmo_post_repetido_nao_duplica_lead(): void
    {
        $this->comSegredo();

        $payload = ['nome' => 'Diego', 'email' => 'diego@empresa.com'];

        $primeira = $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, $payload);
        $primeira->assertCreated()->assertJson(['duplicada' => false]);

        $segunda = $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, $payload);
        $segunda->assertOk()->assertJson(['duplicada' => true]);

        $this->assertSame($primeira->json('staging_id'), $segunda->json('staging_id'));
        $this->assertSame(1, MarketingWpLeadRaw::query()->count());
        $this->assertSame(1, Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->count());
    }

    /** Envio diferente, mesmo e-mail no mesmo dia: reaproveita o lead, mas guarda os dois envelopes. */
    public function test_mesmo_email_no_mesmo_dia_reaproveita_o_lead(): void
    {
        $this->comSegredo();

        $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'nome' => 'Elisa', 'email' => 'elisa@empresa.com', 'mensagem' => 'primeira',
        ])->assertCreated();

        $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'nome' => 'Elisa', 'email' => 'elisa@empresa.com', 'mensagem' => 'segunda',
        ])->assertCreated();

        $this->assertSame(2, MarketingWpLeadRaw::query()->count());
        $this->assertSame(1, Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->count());
    }

    /**
     * O coração da coisa: a staging NÃO pode desaparecer junto com uma falha
     * na criação do lead. Aqui a promoção é sabotada tirando a coluna de
     * destino do caminho — a captura tem que sobreviver mesmo assim.
     */
    public function test_falha_na_promocao_preserva_o_envelope_e_o_job_resolve_depois(): void
    {
        $this->comSegredo();

        // Sem formulário `*`, o lead nasceria sem dono. Aqui simulamos a falha
        // real: uma captura que chegou e ficou pendente.
        $staging = MarketingWpLeadRaw::query()->create([
            'recebido_em' => now(),
            'payload_json' => json_encode([
                'fonte' => WpLeadIngestor::FONTE_WEBHOOK,
                'parsed' => ['nome' => 'Fabio', 'email' => 'fabio@empresa.com'],
            ]),
            'payload_hash' => hash('sha256', 'pendente-de-teste'),
            'fonte' => WpLeadIngestor::FONTE_WEBHOOK,
            'tentativas' => 1,
            'erro' => 'falha simulada',
        ]);

        $this->assertNull($staging->lead_id);
        $this->assertSame(1, MarketingWpLeadRaw::query()->pendentes()->count());

        (new PromoverCapturasWpPendentesJob)->handle(app(WpLeadIngestor::class));

        $staging->refresh();
        $this->assertNotNull($staging->lead_id, 'o job tinha que ter promovido a captura pendente');
        $this->assertNull($staging->erro);
        $this->assertSame('Fabio', Lead::query()->find($staging->lead_id)->nome);
        $this->assertSame(0, MarketingWpLeadRaw::query()->pendentes()->count());
    }

    /**
     * O dono só é resolvido na promoção. Isso permite cadastrar o formulário
     * `*` DEPOIS de o lead já ter chegado, e o retry acerta o dono sozinho.
     */
    public function test_formulario_cadastrado_depois_ainda_acerta_o_dono(): void
    {
        $this->comSegredo();
        MarketingWpFormulario::query()->delete();

        $resposta = $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'nome' => 'Gisele', 'email' => 'gisele@empresa.com',
        ])->assertCreated();

        $lead = Lead::query()->latest('id')->first();
        $this->assertNull($lead->cod_vendedor, 'sem formulário cadastrado o lead nasce órfão');

        // Agora o `*` é cadastrado e uma nova captura já nasce com dono.
        MarketingWpFormulario::query()->create([
            'identificador' => MarketingWpFormulario::IDENTIFICADOR_PADRAO,
            'nome' => 'Padrão',
            'cod_vendedor' => '010617',
            'ativo' => true,
        ]);

        $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'nome' => 'Hugo', 'email' => 'hugo@empresa.com',
        ])->assertCreated();

        $this->assertSame('010617', Lead::query()->latest('id')->first()->cod_vendedor);
        $this->assertNotNull($resposta->json('staging_id'));
    }

    /** Bot batendo no form: fica registrado, mas não vira lead nem fica em retry eterno. */
    public function test_submissao_vazia_nao_vira_lead_mas_fica_registrada(): void
    {
        $this->comSegredo();

        $resposta = $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'campo_irrelevante' => '',
        ])->assertCreated();

        $staging = MarketingWpLeadRaw::query()->find($resposta->json('staging_id'));
        $this->assertNull($staging->lead_id);
        $this->assertSame('payload_sem_campos_comerciais', $staging->erro);
        $this->assertSame(0, Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->count());

        // E não pode ficar sendo retentado para sempre.
        $this->assertSame(0, MarketingWpLeadRaw::query()->pendentes()->count());
        $this->assertSame(1, MarketingWpLeadRaw::query()->travadas()->count());
    }

    /** E-mail inválido não descarta o lead (o telefone pode ser o contato bom). */
    public function test_email_invalido_vira_null_sem_perder_o_lead(): void
    {
        $this->comSegredo();

        $this->postJson('/webhooks/wordpress-leads?token='.self::SEGREDO, [
            'nome' => 'Ivo', 'email' => 'nao-e-email', 'telefone' => '11999998888',
        ])->assertCreated();

        $lead = Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->first();
        $this->assertNotNull($lead);
        $this->assertNull($lead->email);
        $this->assertSame('11999998888', $lead->telefone);
    }

    /** O lead de teste é descartável — senão o botão sujaria a carteira para sempre. */
    public function test_expurgo_apaga_lead_de_teste_antigo_e_preserva_o_real(): void
    {
        $this->comSegredo();

        $ingestor = app(WpLeadIngestor::class);
        $teste = $ingestor->ingerirTesteInterno(1)['staging'];
        $real = $ingestor->ingerirDoWebhook(
            \Illuminate\Http\Request::create('/webhooks/wordpress-leads', 'POST', [], [], [], [], json_encode([
                'nome' => 'Joana', 'email' => 'joana@empresa.com',
            ])),
        )['staging'];

        $leadDeTeste = $teste->fresh()->lead_id;
        $leadReal = $real->fresh()->lead_id;
        $this->assertNotNull($leadDeTeste);
        $this->assertNotNull($leadReal);

        // Envelhece só a captura de teste.
        MarketingWpLeadRaw::query()->whereKey($teste->id)->update([
            'recebido_em' => now()->subDays(2)->format('Y-m-d H:i:s'),
        ]);

        (new ExpurgarCapturasWpJob)->handle();

        $this->assertNull(MarketingWpLeadRaw::query()->find($teste->id));
        $this->assertNull(Lead::query()->find($leadDeTeste), 'o lead de teste tinha que sumir junto');

        $this->assertNotNull(MarketingWpLeadRaw::query()->find($real->id));
        $this->assertNotNull(Lead::query()->find($leadReal), 'o lead real nunca pode ser apagado');
    }
}
