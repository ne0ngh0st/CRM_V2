<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Ligacao;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Carteira\UltimoContatoSincronizador;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contato por canal: WhatsApp e e-mail ao lado da ligação, e a métrica que separa um
 * do outro.
 *
 * O que estes testes protegem, em ordem de importância:
 *
 * 1. O canal chega pela requisição e vira valor de um ENUM do MySQL. Sem a validação
 *    contra `Ligacao::TIPOS_CONTATO`, um valor arbitrário grava string vazia em
 *    silêncio fora do modo estrito — e a métrica por canal passa a mentir sem erro
 *    nenhum aparecer. É o mesmo tipo de risco da whitelist de ordenação da Carteira.
 * 2. O escopo por vendedor continua valendo para os canais novos: WhatsApp e e-mail
 *    não podem virar porta lateral para registrar atividade em cliente alheio.
 * 3. O desempate do "último contato" por `id`. Sem ele, dois contatos no mesmo
 *    segundo devolvem canal arbitrário e a célula muda de valor entre dois F5.
 */
class ContatoPorCanalTest extends TestCase
{
    use RefreshDatabase;

    private User $vendedor;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->vendedor = User::factory()->create(['is_active' => true]);
        $this->vendedor->assignRole('vendedor');
        VendedorPerfil::create(['user_id' => $this->vendedor->id, 'cod_vendedor' => '001']);

        $this->cliente = Cliente::create([
            'cod_cliente' => '1', 'loja' => '01', 'razao_social' => 'ALFA DISTRIBUIDORA',
            'cnpj' => '11.111.111/0001-11', 'telefone' => '(11) 98888-7777',
            'email' => 'compras@alfa.com.br', 'cod_vendedor' => '001', 'estado' => 'SP',
            'data_ultima_compra' => '2026-08-01',
        ]);
    }

    public function test_registra_contato_de_whatsapp_com_o_canal_certo(): void
    {
        $this->actingAs($this->vendedor)
            ->post(route('carteira.ligacao', $this->cliente), ['tipo' => 'whatsapp'])
            ->assertRedirect();

        $this->assertDatabaseHas('ligacoes', [
            'cliente_id' => $this->cliente->id,
            'usuario_id' => $this->vendedor->id,
            'tipo_contato' => 'whatsapp',
            'status' => 'finalizada',
        ]);
    }

    public function test_registra_contato_de_email_com_o_canal_certo(): void
    {
        $this->actingAs($this->vendedor)
            ->post(route('carteira.ligacao', $this->cliente), ['tipo' => 'email'])
            ->assertRedirect();

        $this->assertDatabaseHas('ligacoes', ['cliente_id' => $this->cliente->id, 'tipo_contato' => 'email']);
    }

    /** Sem `tipo` continua sendo ligação — é o contrato que a rota tinha antes. */
    public function test_sem_tipo_o_contato_e_telefonico(): void
    {
        $this->actingAs($this->vendedor)
            ->post(route('carteira.ligacao', $this->cliente))
            ->assertRedirect();

        $this->assertDatabaseHas('ligacoes', ['cliente_id' => $this->cliente->id, 'tipo_contato' => 'telefonica']);
    }

    /**
     * O teste que mais importa: canal fora da whitelist é recusado ANTES do banco.
     * Se alguém trocar o `Rule::in` por um `$request->input('tipo')` cru, isto quebra.
     */
    public function test_canal_invalido_e_recusado_e_nada_e_gravado(): void
    {
        $this->actingAs($this->vendedor)
            ->post(route('carteira.ligacao', $this->cliente), ['tipo' => 'pombo-correio'])
            ->assertSessionHasErrors('tipo');

        $this->assertDatabaseCount('ligacoes', 0);
    }

    public function test_vendedor_nao_registra_contato_em_cliente_de_outra_carteira(): void
    {
        $alheio = Cliente::create([
            'cod_cliente' => '9', 'loja' => '01', 'razao_social' => 'DE OUTRO VENDEDOR',
            'cod_vendedor' => '999', 'estado' => 'SP',
        ]);

        $this->actingAs($this->vendedor)
            ->post(route('carteira.ligacao', $alheio), ['tipo' => 'whatsapp'])
            ->assertForbidden();

        $this->assertDatabaseCount('ligacoes', 0);
    }

    public function test_lead_tambem_registra_o_canal(): void
    {
        $lead = Lead::create([
            'origem' => 'manual', 'user_id' => $this->vendedor->id, 'cod_vendedor' => '001',
            'nome' => 'Contato', 'razao_social' => 'LEAD LTDA',
            'telefone' => '(11) 97777-6666', 'email' => 'lead@exemplo.com', 'status' => 'ativo',
        ]);

        $this->actingAs($this->vendedor)
            ->post(route('leads.ligacao', $lead), ['tipo' => 'whatsapp'])
            ->assertRedirect();

        $this->assertDatabaseHas('ligacoes', ['lead_id' => $lead->id, 'tipo_contato' => 'whatsapp']);

        $this->actingAs($this->vendedor)
            ->post(route('leads.ligacao', $lead), ['tipo' => 'fax'])
            ->assertSessionHasErrors('tipo');
    }

    public function test_carteira_expoe_data_e_canal_do_ultimo_contato(): void
    {
        $this->contato('telefonica', '2026-08-20 10:00:00');
        $this->contato('whatsapp', '2026-08-28 15:30:00');

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index'))
            ->assertInertia(fn ($page) => $page
                ->where('clientes.data.0.ultimoContato.data', '28/08/2026')
                ->where('clientes.data.0.ultimoContato.canal', 'whatsapp'));
    }

    public function test_cliente_sem_contato_vem_nulo(): void
    {
        $this->actingAs($this->vendedor)
            ->get(route('carteira.index'))
            ->assertInertia(fn ($page) => $page->where('clientes.data.0.ultimoContato', null));
    }

    public function test_contato_excluido_nao_conta_como_ultimo(): void
    {
        $this->contato('telefonica', '2026-08-20 10:00:00');
        $this->contato('email', '2026-08-29 09:00:00', 'excluida');

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index'))
            ->assertInertia(fn ($page) => $page
                ->where('clientes.data.0.ultimoContato.canal', 'telefonica'));
    }

    /**
     * Empate de `data_ligacao` — acontece de verdade quando o vendedor dispara
     * WhatsApp e e-mail no mesmo segundo. Em empate vence o contato registrado por
     * último; sem isso a célula muda de valor entre dois carregamentos.
     */
    public function test_empate_de_data_desempata_pelo_contato_mais_recente(): void
    {
        $this->contato('whatsapp', '2026-08-28 15:30:00');
        $this->contato('email', '2026-08-28 15:30:00');

        $this->assertSame('email', $this->cliente->fresh()->canal_ultimo_contato);
    }

    /**
     * O hook `Ligacao::created()` é o ÚNICO ponto de escrita da coluna desnormalizada.
     */
    public function test_registrar_contato_atualiza_a_coluna_do_cliente(): void
    {
        $this->assertNull($this->cliente->data_ultimo_contato);

        $this->actingAs($this->vendedor)
            ->post(route('carteira.ligacao', $this->cliente), ['tipo' => 'whatsapp']);

        $cliente = $this->cliente->fresh();
        $this->assertNotNull($cliente->data_ultimo_contato);
        $this->assertSame('whatsapp', $cliente->canal_ultimo_contato);
    }

    /**
     * Contato retroativo NÃO sobrescreve um mais recente — senão um registro antigo
     * lançado depois faria o cliente "voltar no tempo" na ordenação.
     */
    public function test_contato_mais_antigo_nao_sobrescreve_o_ultimo(): void
    {
        $this->contato('whatsapp', '2026-08-28 15:30:00');
        $this->contato('telefonica', '2026-01-05 09:00:00');

        $this->assertSame('whatsapp', $this->cliente->fresh()->canal_ultimo_contato);
    }

    /**
     * ⚠️ Protege contra a pior falha desta desnormalização: se a regra de desempate do
     * HOOK divergir da regra da RECONSTRUÇÃO, a coluna muda de valor toda vez que
     * alguém rodar a manutenção — e ninguém liga uma coisa na outra.
     */
    public function test_reconstrucao_chega_no_mesmo_valor_que_o_hook(): void
    {
        $this->contato('telefonica', '2026-08-20 10:00:00');
        $this->contato('whatsapp', '2026-08-28 15:30:00');
        $this->contato('email', '2026-08-28 15:30:00');

        $campos = ['data_ultimo_contato', 'canal_ultimo_contato'];
        $peloHook = $this->cliente->fresh()->only($campos);

        app(UltimoContatoSincronizador::class)->reconstruirTudo();

        $this->assertEquals($peloHook, $this->cliente->fresh()->only($campos));
    }

    /** Cliente que ficou sem nenhum contato válido volta a NULL na reconstrução. */
    public function test_reconstrucao_zera_cliente_sem_contato_valido(): void
    {
        $this->contato('whatsapp', '2026-08-28 15:30:00');
        Ligacao::query()->update(['status' => 'excluida']);

        app(UltimoContatoSincronizador::class)->reconstruirTudo();

        $this->assertNull($this->cliente->fresh()->data_ultimo_contato);
    }

    /**
     * A coluna é ordenável, e só é viável porque virou coluna indexada de `clientes`
     * (0,9 ms). Agregando `ligacoes` na hora seriam 987 ms no escopo admin.
     */
    public function test_ordena_por_ultimo_contato_nos_dois_sentidos(): void
    {
        $outro = Cliente::create([
            'cod_cliente' => '2', 'loja' => '01', 'razao_social' => 'BETA COMERCIO',
            'cod_vendedor' => '001', 'estado' => 'SP',
        ]);

        $this->contato('whatsapp', '2026-08-28 15:30:00');
        Ligacao::create([
            'usuario_id' => $this->vendedor->id, 'cliente_id' => $outro->id,
            'cliente_nome' => $outro->razao_social, 'tipo_contato' => 'email',
            'status' => 'finalizada', 'data_ligacao' => '2026-01-05 09:00:00',
        ]);

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index', ['ordenar' => 'ultimo_contato_desc']))
            ->assertInertia(fn ($page) => $page->where('clientes.data.0.razaoSocial', 'ALFA DISTRIBUIDORA'));

        $this->actingAs($this->vendedor)
            ->get(route('carteira.index', ['ordenar' => 'ultimo_contato_asc']))
            ->assertInertia(fn ($page) => $page->where('clientes.data.0.razaoSocial', 'BETA COMERCIO'));
    }

    public function test_painel_quebra_os_contatos_do_mes_por_canal(): void
    {
        $this->contato('telefonica', now()->toDateTimeString());
        $this->contato('whatsapp', now()->toDateTimeString());
        $this->contato('whatsapp', now()->toDateTimeString());
        $this->contato('email', now()->toDateTimeString());
        // Fora do mês corrente: não pode entrar na conta.
        $this->contato('presencial', now()->subMonths(2)->toDateTimeString());

        $this->actingAs($this->vendedor)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('ligacoesStats.total', 4)
                ->where('ligacoesStats.porCanal.telefonica', 1)
                ->where('ligacoesStats.porCanal.whatsapp', 2)
                ->where('ligacoesStats.porCanal.email', 1)
                ->where('ligacoesStats.porCanal.presencial', 0));
    }

    public function test_visao_do_gestor_quebra_por_canal_na_linha_e_no_kpi(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->contato('whatsapp', now()->toDateTimeString());
        $this->contato('whatsapp', now()->toDateTimeString());
        $this->contato('email', now()->toDateTimeString());

        $this->actingAs($admin)
            ->get(route('visao-gestor.index'))
            ->assertInertia(fn ($page) => $page
                ->where('kpis.ligacoesPorCanal.whatsapp', 2)
                ->where('kpis.ligacoesPorCanal.email', 1)
                ->where('kpis.ligacoesPorCanal.telefonica', 0)
                ->where('linhas.0.ligacoesPorCanal.whatsapp', 2));
    }

    /**
     * Canal sem nenhum registro vem 0, e não ausente — o front itera a lista de canais
     * e mostraria célula vazia em vez de zero se a chave sumisse.
     */
    public function test_todos_os_canais_aparecem_mesmo_zerados(): void
    {
        $this->actingAs($this->vendedor)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->has('ligacoesStats.porCanal', 4)
                ->where('ligacoesStats.porCanal.whatsapp', 0));
    }

    private function contato(string $canal, string $quando, string $status = 'finalizada'): void
    {
        Ligacao::create([
            'usuario_id' => $this->vendedor->id,
            'cliente_id' => $this->cliente->id,
            'cliente_nome' => $this->cliente->razao_social,
            'tipo_contato' => $canal,
            'status' => $status,
            'data_ligacao' => $quando,
        ]);
    }
}
