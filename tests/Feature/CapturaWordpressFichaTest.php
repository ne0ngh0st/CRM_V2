<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarketingWpLeadRaw;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Marketing\WpLeadIngestor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A ficha de "dados recebidos do site".
 *
 * Até 2026-09-03 esta tela era `JSON.stringify(payload, null, 2)` num <pre>: quem
 * precisava do telefone do cliente tinha que garimpar chave crua de plugin do WordPress.
 * Agora os campos comerciais vêm prontos, e o JSON fica recolhido e só para admin.
 */
class CapturaWordpressFichaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $papel, ?string $codVendedor = null): User
    {
        $user = User::factory()->create();
        $user->assignRole($papel);

        if ($codVendedor !== null) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codVendedor]);
        }

        return $user;
    }

    /** Um lead do site com a captura anexada, no formato que o webhook grava. */
    private function leadComCaptura(array|string $envelope, string $fonte = WpLeadIngestor::FONTE_WEBHOOK): Lead
    {
        $lead = Lead::create([
            'origem' => Lead::ORIGEM_WORDPRESS,
            'cod_vendedor' => '010617',
            'nome' => 'Maria Souza',
            'razao_social' => 'KNTT Comercio LTDA',
        ]);

        MarketingWpLeadRaw::create([
            'recebido_em' => now(),
            'payload_json' => is_string($envelope) ? $envelope : json_encode($envelope),
            'payload_hash' => hash('sha256', microtime()),
            'fonte' => $fonte,
            'remote_addr' => '203.0.113.7',
            'user_agent' => 'WordPress/6.5',
            'lead_id' => $lead->id,
            'tentativas' => 0,
        ]);

        return $lead;
    }

    private function envelopeDoSite(): array
    {
        return [
            'fonte' => WpLeadIngestor::FONTE_WEBHOOK,
            'raw_input' => 'name=Maria+Souza',
            'parsed' => [
                'name' => 'Maria Souza',
                'empresa' => 'KNTT Comercio LTDA',
                // Prefixo do Mailchimp for WordPress: sem o tratamento no parser, o lead
                // nasceria SEM telefone — o único dado que o vendedor usa para agir.
                'mc4wp-PHONE' => '(15) 3278-9011',
                'estado' => 'São Paulo',
                'mensagem' => 'Preciso de etiqueta de balanca 40x40.',
                'itens' => ['Bobinas', 'Etiquetas'],
            ],
        ];
    }

    #[Test]
    public function test_ficha_traz_os_campos_comerciais_prontos(): void
    {
        $lead = $this->leadComCaptura($this->envelopeDoSite());

        $this->actingAs($this->usuario('admin'))
            ->getJson(route('leads.captura', $lead))
            ->assertOk()
            ->assertJsonPath('campos.nome', 'Maria Souza')
            ->assertJsonPath('campos.telefone', '(15) 3278-9011')
            // Texto livre do site vira sigla; nunca truncado (leads.estado é varchar(2)).
            ->assertJsonPath('campos.estado', 'SP')
            // Array de checkbox vira texto legível, não "Array".
            ->assertJsonPath('campos.itens', 'Bobinas, Etiquetas');
    }

    /**
     * ⚠️ O bloco técnico é decidido no SERVIDOR, não escondido no front. Um `v-if` no Vue
     * não impediria um vendedor de ler o payload cru na resposta da rota.
     */
    #[Test]
    public function test_bloco_tecnico_so_existe_para_admin(): void
    {
        $lead = $this->leadComCaptura($this->envelopeDoSite());

        $this->actingAs($this->usuario('vendedor', '010617'))
            ->getJson(route('leads.captura', $lead))
            ->assertOk()
            ->assertJsonPath('campos.nome', 'Maria Souza')
            ->assertJsonPath('tecnico', null);

        $this->actingAs($this->usuario('admin'))
            ->getJson(route('leads.captura', $lead))
            ->assertOk()
            ->assertJsonPath('tecnico.remoteAddr', '203.0.113.7')
            ->assertJsonPath('tecnico.tentativas', 0);
    }

    /**
     * Import de planilha guarda os campos em `colunas`, não em `parsed`. A ficha tem que
     * aguentar as duas formas — e a de payload ilegível, abaixo.
     */
    #[Test]
    public function test_envelope_de_csv_tambem_vira_ficha(): void
    {
        $lead = $this->leadComCaptura([
            'fonte' => WpLeadIngestor::FONTE_CSV,
            'arquivo' => 'leads-2025.csv',
            'colunas' => ['nome' => 'Joana Prado', 'e-mail' => 'joana@x.com'],
        ], WpLeadIngestor::FONTE_CSV);

        $this->actingAs($this->usuario('admin'))
            ->getJson(route('leads.captura', $lead))
            ->assertOk()
            ->assertJsonPath('campos.nome', 'Joana Prado')
            ->assertJsonPath('campos.email', 'joana@x.com');
    }

    /**
     * `json_decode` devolve null quando o gravado não é JSON válido. A ficha fica vazia,
     * não estoura — e o admin ainda vê a string crua para diagnosticar.
     */
    #[Test]
    public function test_envelope_ilegivel_nao_quebra_a_ficha(): void
    {
        $lead = $this->leadComCaptura('isto nao e json {{{');

        $this->actingAs($this->usuario('admin'))
            ->getJson(route('leads.captura', $lead))
            ->assertOk()
            ->assertJsonPath('campos.nome', null)
            ->assertJsonPath('tecnico.payload', 'isto nao e json {{{');
    }

    /** O escopo por vendedor continua valendo — a ficha não é porta lateral. */
    #[Test]
    public function test_vendedor_de_outra_carteira_leva_403(): void
    {
        $lead = $this->leadComCaptura($this->envelopeDoSite());

        $this->actingAs($this->usuario('vendedor', '999999'))
            ->getJson(route('leads.captura', $lead))
            ->assertForbidden();
    }
}
