<?php

namespace Tests\Feature;

use App\Models\AgendamentoLigacao;
use App\Models\Cliente;
use App\Models\Lead;
use App\Models\User;
use App\Models\VendedorPerfil;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Clientes e leads na mesma página, com uma busca só (2026-09-29).
 *
 * `/carteira` tem as abas Clientes · Leads · Funil · Calendário. `/leads` só redireciona,
 * porque notificação e favorito já apontam para lá.
 *
 * ⚠️ Os CNPJs do fixture são todos diferentes, e um lead guarda o documento SÓ COM
 * DÍGITOS (insert direto, fora do mutator). Com os dois já pontuados, o selo "Já é
 * cliente" passaria mesmo se a comparação voltasse a ser string crua.
 */
class CarteiraUnicaTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = $this->usuario('vendedor', '001');
        $this->admin = $this->usuario('admin', '999');
    }

    public function test_leads_redireciona_para_a_aba_preservando_a_query(): void
    {
        $resposta = $this->actingAs($this->vendedor)
            ->get('/leads?busca=kntt&visao_vendedor=001&origem=sistema');

        $resposta->assertRedirect();
        parse_str((string) parse_url($resposta->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('leads', $query['aba']);
        $this->assertSame('kntt', $query['busca']);
        $this->assertSame('001', $query['visao_vendedor']);
        $this->assertSame('sistema', $query['origem']);
    }

    public function test_aba_antiga_de_funil_e_calendario_cai_na_aba_de_mesmo_nome(): void
    {
        foreach (['funil', 'calendario'] as $aba) {
            $resposta = $this->actingAs($this->vendedor)->get('/leads?aba='.$aba.'&busca=droga');
            parse_str((string) parse_url($resposta->headers->get('Location'), PHP_URL_QUERY), $query);

            $this->assertSame($aba, $query['aba']);
            $this->assertSame('droga', $query['busca']);
        }

        $resto = $this->actingAs($this->vendedor)->get('/leads?aba=clientes');
        parse_str((string) parse_url($resto->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('leads', $query['aba']);
    }

    public function test_cada_aba_so_traz_as_proprias_props(): void
    {
        $this->cliente('100', 'ALFA UNICO', '001', '11.111.111/0001-11');
        $this->lead('BETA UNICO', '001');

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Carteira/Index')
                ->where('aba', 'clientes')
                ->has('clientes.data')
                ->has('kpis')
                ->where('leads', null)
                ->where('funil', null)
                ->where('leadsKpis', null));

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index', ['aba' => 'leads']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('aba', 'leads')
                ->has('leads.data')
                ->has('leadsKpis')
                ->where('clientes', null)
                ->where('kpis', null)
                ->where('funil', null));

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index', ['aba' => 'funil']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('aba', 'funil')
                ->has('funil.colunas')
                ->where('leads', null)
                ->where('clientes', null));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->vendedor)->get(route('carteira.index', ['aba' => 'clientes']))->assertOk();
        $sqlClientes = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('agendamentos_ligacoes', $sqlClientes);
        $this->assertDoesNotMatchRegularExpression('/\bleads\b/', $sqlClientes);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->vendedor)->get(route('carteira.index', ['aba' => 'leads']))->assertOk();
        $sqlLeads = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('agendamentos_ligacoes', $sqlLeads);
        $this->assertStringNotContainsString('segmentos_vendedor', $sqlLeads);
    }

    public function test_busca_e_visao_valem_nas_duas_abas_e_o_filtro_da_aba_nao_atravessa(): void
    {
        $this->cliente('100', 'ZULU VISION', '001');
        $this->cliente('200', 'ZULU DE OUTRO', '002');
        $this->lead('ZULU VISION', '001');
        $this->lead('ZULU DE OUTRO', '002');

        $clientes = $this->actingAs($this->admin)
            ->get(route('carteira.index', [
                'aba' => 'clientes',
                'busca' => 'ZULU',
                'visao_vendedor' => '001',
                // Etapa de lead na URL. Na aba de clientes isso não pode esvaziar a lista.
                'status' => 'novo',
            ]));

        $clientes->assertInertia(fn (Assert $page) => $page
            ->where('clientes.total', 1)
            ->where('clientes.data.0.razaoSocial', 'ZULU VISION'));

        $leads = $this->actingAs($this->admin)
            ->get(route('carteira.index', [
                'aba' => 'leads',
                'busca' => 'ZULU',
                'visao_vendedor' => '001',
                // Status de cliente na URL. Na aba de leads isso não pode esvaziar a lista.
                'status' => 'inativo',
            ]));

        $leads->assertInertia(fn (Assert $page) => $page
            ->where('leads.total', 1)
            ->where('leads.data.0.razaoSocial', 'ZULU VISION'));
    }

    public function test_calendario_traz_cliente_e_lead_do_escopo(): void
    {
        $cliente = $this->cliente('100', 'CLIENTE AGENDA', '001');
        $lead = $this->lead('LEAD AGENDA', '001');
        $alheio = $this->cliente('200', 'CLIENTE ALHEIO', '002');
        $leadAlheio = $this->lead('LEAD ALHEIO', '002');

        $this->agendar(['cliente_id' => $cliente->id], now()->addDay());
        $this->agendar(['lead_id' => $lead->id], now()->addDays(2));
        $this->agendar(['cliente_id' => $alheio->id], now()->addDay());
        $this->agendar(['lead_id' => $leadAlheio->id], now()->addDays(2));

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index', ['aba' => 'calendario']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('agendamentos', fn ($itens) => collect($itens)->count() === 2
                    && collect($itens)->pluck('tipo')->sort()->values()->all() === ['cliente', 'lead']
                    && collect($itens)->pluck('clienteNome')->sort()->values()->all() === ['CLIENTE AGENDA', 'LEAD AGENDA']));
    }

    public function test_selo_ja_e_cliente_casa_cnpj_e_so_linka_quem_esta_no_escopo(): void
    {
        $noEscopo = $this->cliente('100', 'CLIENTE MEU', '001', '11.111.111/0001-11');
        $deOutro = $this->cliente('200', 'CLIENTE DELE', '002', '22.222.222/0001-22');

        // Fora do mutator, de propósito: é o formato que o lead manual gravava antes.
        $this->leadCru('LEAD MEU CNPJ', '001', '11111111000111');
        $this->leadCru('LEAD CNPJ ALHEIO', '001', '22222222000122');
        $this->lead('LEAD SEM CNPJ', '001');

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index', ['aba' => 'leads', 'busca' => 'LEAD']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('leads.data', function ($linhas) use ($noEscopo, $deOutro) {
                $porNome = collect($linhas)->keyBy('razaoSocial');

                return ($porNome['LEAD MEU CNPJ']['jaECliente']['clienteId'] ?? null) === $noEscopo->id
                    && array_key_exists('jaECliente', $porNome['LEAD CNPJ ALHEIO'])
                    && $porNome['LEAD CNPJ ALHEIO']['jaECliente']['clienteId'] === null
                    && $porNome['LEAD SEM CNPJ']['jaECliente'] === null
                    && $deOutro->id !== ($porNome['LEAD CNPJ ALHEIO']['jaECliente']['clienteId'] ?? null);
            }));
    }

    public function test_cnpj_do_lead_sai_pontuado_qualquer_que_seja_a_origem(): void
    {
        $manual = Lead::create([
            'origem' => Lead::ORIGEM_MANUAL,
            'nome' => 'Manual',
            'razao_social' => 'MANUAL',
            'cnpj' => '12345678000190',
            'status' => 'ativo',
        ]);
        $this->assertSame('12.345.678/0001-90', $manual->fresh()->cnpj);

        $cpf = Lead::create([
            'origem' => Lead::ORIGEM_WORDPRESS,
            'nome' => 'Site',
            'razao_social' => 'SITE',
            'cnpj' => '123.456.789-01',
            'status' => 'ativo',
        ]);
        $this->assertSame('123.456.789-01', $cpf->fresh()->cnpj);

        $vazio = Lead::create([
            'origem' => Lead::ORIGEM_MANUAL,
            'nome' => 'Sem',
            'razao_social' => 'SEM DOC',
            'cnpj' => '   ',
            'status' => 'ativo',
        ]);
        $this->assertNull($vazio->fresh()->cnpj);
    }

    public function test_migration_de_cnpj_normaliza_e_e_idempotente(): void
    {
        $this->leadCru('JA PONTUADO', '001', '11.111.111/0001-11');
        $this->leadCru('SO DIGITOS', '001', '22333444000155');
        $idIncompleto = $this->leadCru('INCOMPLETO', '001', '12.345')->id;

        /** @var \Illuminate\Database\Migrations\Migration $migration */
        $migration = require base_path('database/migrations/2026_09_29_120000_normaliza_cnpj_dos_leads.php');
        $migration->up();
        $migration->up();

        $this->assertSame('11.111.111/0001-11', DB::table('leads')->where('razao_social', 'JA PONTUADO')->value('cnpj'));
        $this->assertSame('22.333.444/0001-55', DB::table('leads')->where('razao_social', 'SO DIGITOS')->value('cnpj'));
        $this->assertSame('12345', DB::table('leads')->where('id', $idIncompleto)->value('cnpj'));
    }

    private function usuario(string $perfil, string $codigo): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($perfil);
        VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $codigo]);

        return $user;
    }

    private function cliente(string $cod, string $razao, string $vendedor, ?string $cnpj = null): Cliente
    {
        return Cliente::create([
            'cod_cliente' => $cod,
            'loja' => '0001',
            'razao_social' => $razao,
            'cod_vendedor' => $vendedor,
            'cnpj' => $cnpj,
        ]);
    }

    private function lead(string $razao, string $vendedor, ?string $cnpj = null): Lead
    {
        return Lead::create([
            'origem' => Lead::ORIGEM_MANUAL,
            'nome' => 'Contato',
            'razao_social' => $razao,
            'cod_vendedor' => $vendedor,
            'cnpj' => $cnpj,
            'status' => 'ativo',
        ]);
    }

    /** Grava o CNPJ como veio, sem o mutator — é o que o import antigo fazia. */
    private function leadCru(string $razao, string $vendedor, string $cnpj): Lead
    {
        $id = DB::table('leads')->insertGetId([
            'origem' => Lead::ORIGEM_MANUAL,
            'nome' => 'Contato',
            'razao_social' => $razao,
            'cod_vendedor' => $vendedor,
            'cnpj' => $cnpj,
            'status' => 'ativo',
            'etapa' => Lead::ETAPA_NOVO,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Lead::findOrFail($id);
    }

    private function agendar(array $dono, \DateTimeInterface $quando): void
    {
        AgendamentoLigacao::create([
            ...$dono,
            'user_id' => $this->vendedor->id,
            'data_agendamento' => $quando,
            'status' => 'agendado',
        ]);
    }
}
