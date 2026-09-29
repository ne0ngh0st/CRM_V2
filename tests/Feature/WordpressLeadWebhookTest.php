<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarketingWpFormulario;
use App\Models\MarketingWpLeadRaw;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordpressLeadWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function comSegredo(string $secret = 'token-de-teste-wp'): void
    {
        config(['marketing.wp_webhook_secret' => $secret]);
    }

    public function test_options_devolve_204_com_cors_sem_origin(): void
    {
        $this->call('OPTIONS', '/webhooks/wordpress-leads')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Webhook-Token')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_get_devolve_405_method_not_allowed(): void
    {
        $this->getJson('/webhooks/wordpress-leads')
            ->assertStatus(405)
            ->assertJson(['ok' => false, 'error' => 'method_not_allowed']);
    }

    public function test_sem_segredo_configurado_devolve_503(): void
    {
        config(['marketing.wp_webhook_secret' => '']);

        $this->postJson('/webhooks/wordpress-leads', ['nome' => 'Ana'])
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'error' => 'webhook_not_configured']);

        $this->assertSame(0, MarketingWpLeadRaw::query()->count());
        $this->assertSame(0, Lead::query()->count());
    }

    public function test_token_ausente_ou_errado_devolve_401(): void
    {
        $this->comSegredo();

        $this->postJson('/webhooks/wordpress-leads', ['nome' => 'Ana'])
            ->assertUnauthorized()
            ->assertJson(['ok' => false, 'error' => 'unauthorized']);

        $this->postJson('/webhooks/wordpress-leads', ['nome' => 'Ana'], [
            'X-Webhook-Token' => 'outro',
        ])->assertUnauthorized();

        $this->assertSame(0, MarketingWpLeadRaw::query()->count());
    }

    public function test_bearer_grava_staging_e_lead_wordpress(): void
    {
        $this->comSegredo();

        $resposta = $this->withHeaders([
            'Authorization' => 'Bearer token-de-teste-wp',
        ])->postJson('/webhooks/wordpress-leads', [
            'nome' => 'Ana Silva',
            'email' => 'ana@empresa.com',
            'telefone' => '11988887777',
            'empresa' => 'Empresa Silva',
        ]);

        $resposta->assertCreated()
            ->assertJson(['ok' => true])
            ->assertJsonStructure(['ok', 'staging_id']);

        $staging = MarketingWpLeadRaw::query()->find($resposta->json('staging_id'));
        $this->assertNotNull($staging);
        $this->assertSame('wordpress_webhook', $staging->fonte);
        $this->assertNotNull($staging->lead_id);

        $envelope = json_decode($staging->payload_json, true);
        $this->assertSame('wordpress_webhook', $envelope['fonte']);
        $this->assertSame('Ana Silva', $envelope['parsed']['nome']);
        $this->assertArrayHasKey('raw_input', $envelope);

        $lead = Lead::query()->find($staging->lead_id);
        $this->assertSame(Lead::ORIGEM_WORDPRESS, $lead->origem);
        $this->assertSame('010617', $lead->cod_vendedor);
        $this->assertSame('Ana Silva', $lead->nome);
        $this->assertSame('Empresa Silva', $lead->razao_social);
        $this->assertSame('ana@empresa.com', $lead->email);
        $this->assertSame('11988887777', $lead->telefone);
        $this->assertSame('ativo', $lead->status);
    }

    public function test_x_webhook_token_tambem_autentica(): void
    {
        $this->comSegredo();

        $this->postJson('/webhooks/wordpress-leads', ['nome' => 'Bruno'], [
            'X-Webhook-Token' => 'token-de-teste-wp',
        ])->assertCreated();
    }

    public function test_fields_do_cf7_viram_lead(): void
    {
        $this->comSegredo();

        $this->withHeaders(['Authorization' => 'Bearer token-de-teste-wp'])
            ->postJson('/webhooks/wordpress-leads', [
                'form_id' => 12,
                'fields' => [
                    'your-name' => 'Carla',
                    'your-email' => 'carla@x.com',
                ],
            ])
            ->assertCreated();

        $lead = Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->first();
        $this->assertSame('Carla', $lead->nome);
        $this->assertSame('carla@x.com', $lead->email);
    }

    public function test_post_sem_csrf_nao_devolve_419(): void
    {
        $this->comSegredo();

        $this->post('/webhooks/wordpress-leads', ['nome' => 'Dora'], [
            'X-Webhook-Token' => 'token-de-teste-wp',
            'Accept' => 'application/json',
        ])->assertCreated();
    }

    public function test_form_especifico_usa_o_vendedor_da_tabela_nao_o_padrao(): void
    {
        $this->comSegredo();

        MarketingWpFormulario::query()->create([
            'identificador' => '42',
            'nome' => 'Orçamento',
            'cod_vendedor' => '999888',
            'ativo' => true,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer token-de-teste-wp'])
            ->postJson('/webhooks/wordpress-leads', [
                '_wpcf7' => 42,
                'nome' => 'Carla',
            ])
            ->assertCreated();

        $this->assertSame('999888', Lead::query()->first()->cod_vendedor);
        $this->assertSame(
            'Orçamento',
            MarketingWpLeadRaw::query()->first()->formulario->nome,
        );
    }

    public function test_sem_formulario_padrao_o_lead_nasce_sem_dono(): void
    {
        $this->comSegredo();
        MarketingWpFormulario::query()->delete();

        $this->postJson('/webhooks/wordpress-leads', ['nome' => 'Eva'], [
            'X-Webhook-Token' => 'token-de-teste-wp',
        ])->assertCreated();

        $this->assertNull(Lead::query()->first()->cod_vendedor);
    }

    public function test_lead_wordpress_aparece_na_caixa_da_listagem(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        Lead::query()->create([
            'origem' => Lead::ORIGEM_WORDPRESS,
            'nome' => 'Lead do site',
            'razao_social' => 'Lead do site',
            'email' => 'site@x.com',
            'cod_vendedor' => 'WP01',
            'status' => 'ativo',
        ]);
        Lead::query()->create([
            'origem' => Lead::ORIGEM_MANUAL,
            'nome' => 'Lead manual',
            'razao_social' => 'Lead manual',
            'email' => 'manual@x.com',
            'status' => 'ativo',
        ]);

        $this->actingAs($admin)
            ->get(route('leads.index', ['origem' => 'wordpress']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Leads/Index')
                ->where('kpis.wordpress', 1)
                ->where('kpis.manual', 0)
                ->has('leads.data', 1)
                ->where('leads.data.0.origem', 'wordpress')
                ->where('wordpressCaptura.ligado', false)
                ->where('wordpressCaptura.ultimoRecebidoEm', null)
                ->where('wordpressCaptura.donoCod', '010617')
                ->where('wordpressCaptura.podeTestar', true)
            );
    }

    public function test_admin_envia_lead_de_teste_e_aparece_na_caixa(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('leads.wordpress.teste'))
            ->assertRedirect(route('leads.index', ['origem' => 'wordpress']));

        $lead = Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->first();
        $this->assertNotNull($lead);
        $this->assertSame('010617', $lead->cod_vendedor);
        $this->assertSame('teste_interno', $lead->stagingWordpress->fonte);

        $this->actingAs($admin)
            ->get(route('leads.captura', $lead))
            ->assertOk()
            ->assertJsonPath('fonte', 'teste_interno');
    }

    public function test_vendedor_nao_dispara_teste(): void
    {
        $this->seed(RoleSeeder::class);
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $this->actingAs($vendedor)
            ->post(route('leads.wordpress.teste'))
            ->assertForbidden();
    }
}
