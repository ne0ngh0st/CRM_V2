<?php

namespace Tests\Feature;

use App\Jobs\EnviarResumoEquipeJob;
use App\Jobs\EnviarResumosEquipeJob;
use App\Mail\ResumoEquipeMail;
use App\Models\Cliente;
use App\Models\Faturamento;
use App\Models\Ligacao;
use App\Models\MetaMensal;
use App\Models\Pedido;
use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Carteira\CarteiraAderenciaResolver;
use App\Services\Metas\MetaRankingResolver;
use App\Services\ResumoEquipe\ResumoEquipeBuilder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Resumo diário da equipe por e-mail.
 *
 * O que estes testes travam, em ordem de importância:
 *
 * 1. OS NÚMEROS BATEM COM AS TELAS — o mês igual ao /metas, e o dia sendo o último dia
 *    fechado (o D-1 do Painel). O e-mail não pode ter aritmética própria.
 * 2. QUEM É A EQUIPE — `cod_super`, não perfil (o caso do Beto, diretor com
 *    representantes), e o consolidado ser a lista de quem recebe `equipe`.
 * 3. O ENVIO — interruptor, idempotência, redirecionamento, gestor sem equipe.
 *
 * ⚠️ Valores do fixture todos diferentes entre si (pedidos 1.000 / 700 / 3.300, notas
 * 500 / 90, metas 4.000 / 2.500 / 800 / 250): venda e faturamento têm o mesmo formato, e
 * com números parecidos trocar um pelo outro passaria verde.
 *
 * Relógio congelado numa QUARTA às 18:00 (perto do horário do envio, 18:15): D-1 é terça, e "hoje"
 * tem contatos que o mês também conta.
 */
class ResumoEquipeTest extends TestCase
{
    use RefreshDatabase;

    private const SANDRA = '000100';

    private const BETO = '000200';

    private const PAULO = '000300';

    private User $sandra;

    private User $ana;

    private User $bruno;

    private User $beto;

    private User $rep;

    private User $paulo;

    private User $leandro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::create(2026, 6, 17, 18, 0));

        // Sandra é supervisora e responde ao Beto (o cod_super de supervisor aponta para
        // diretor) — é o que faz o código dela aparecer em DUAS equipes.
        $this->sandra = $this->pessoa('supervisor', self::SANDRA, self::BETO, 'SANDRA LIMA', 'equipe');
        $this->ana = $this->pessoa('vendedor', '000101', self::SANDRA, 'ANA SOUZA');
        $this->bruno = $this->pessoa('vendedor', '000102', self::SANDRA, 'BRUNO DIAS');

        $this->beto = $this->pessoa('diretor', self::BETO, null, 'ROBERTO ALVES', 'equipe');
        $this->rep = $this->pessoa('representante', '000201', self::BETO, 'REP NORTE');

        $this->paulo = $this->pessoa('diretor', self::PAULO, null, 'PAULO DIRETOR', 'consolidado');
        $this->leandro = $this->pessoa('diretor', null, null, 'LEANDRO COPIA', 'consolidado');

        // De fora de tudo: não pode aparecer em equipe nenhuma.
        $this->pessoa('vendedor', '000999', '000777', 'FORA DO RESUMO');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. Os números batem com as telas ────────────────────────────────────────

    public function test_resumao_do_mes_e_o_do_ranking_de_metas(): void
    {
        $this->cenarioComercial();

        $resumo = app(ResumoEquipeBuilder::class)->equipe($this->sandra);
        $codigos = app(ResumoEquipeBuilder::class)->codigosDaEquipe($this->sandra);
        $ranking = app(MetaRankingResolver::class)->ranking($codigos, 2026, 6)['totais'];
        $mes = $resumo['resumao']['mes'];

        $this->assertEqualsWithDelta($ranking['vendaRealizado'], $mes['venda']['realizado'], 0.001);
        $this->assertEqualsWithDelta($ranking['vendaMeta'], $mes['venda']['meta'], 0.001);
        $this->assertEquals($ranking['vendaPct'], $mes['venda']['pct']);
        $this->assertEqualsWithDelta($ranking['fatRealizado'], $mes['faturamento']['realizado'], 0.001);
        $this->assertEqualsWithDelta($ranking['fatMeta'], $mes['faturamento']['meta'], 0.001);
        $this->assertEquals($ranking['fatPct'], $mes['faturamento']['pct']);

        // E os valores esperados, para o teste não passar com as duas fontes erradas juntas.
        // Pedido de hoje (D-0) não entra: a janela é D-1, igual ao Painel.
        $this->assertEqualsWithDelta(1700.0, $mes['venda']['realizado'], 0.001);
        $this->assertEqualsWithDelta(500.0, $mes['faturamento']['realizado'], 0.001);
        $this->assertEqualsWithDelta(6500.0, $mes['venda']['meta'], 0.001);
        $this->assertEqualsWithDelta(1050.0, $mes['faturamento']['meta'], 0.001);
    }

    /**
     * O "dia" de venda/faturamento/pedidos é o último dia FECHADO (terça, com o relógio
     * na quarta) — nem o mês inteiro, nem hoje, que às 18h ainda não entrou do TOTVS.
     */
    public function test_pedidos_venda_e_faturamento_sao_do_ultimo_dia_fechado(): void
    {
        $this->cenarioComercial();
        $this->nota('000101', '2026-06-16', 320.0);
        $this->nota('000102', '2026-06-16', 45.0);

        $resumo = app(ResumoEquipeBuilder::class)->equipe($this->sandra);
        $linhas = collect($resumo['secoes'][0]['linhas']);
        $ana = $linhas->firstWhere('codVendedor', '000101');
        $bruno = $linhas->firstWhere('codVendedor', '000102');

        $this->assertSame('2026-06-16', $resumo['periodo']['dia']);

        // Ana: pedido do dia 05 e nota do dia 09 ficam de fora; só a nota de terça entra.
        $this->assertSame(0, $ana['pedidos']);
        $this->assertEqualsWithDelta(0.0, $ana['venda'], 0.001);
        $this->assertEqualsWithDelta(320.0, $ana['faturamento'], 0.001);

        // Bruno: o pedido de terça entra; o de hoje (9.999) e a nota de hoje (90) não.
        $this->assertSame(1, $bruno['pedidos']);
        $this->assertEqualsWithDelta(700.0, $bruno['venda'], 0.001);
        $this->assertEqualsWithDelta(45.0, $bruno['faturamento'], 0.001);

        $this->assertSame(1, $resumo['totais']['pedidos']);
        $this->assertEqualsWithDelta(700.0, $resumo['totais']['venda'], 0.001);
        $this->assertEqualsWithDelta(365.0, $resumo['totais']['faturamento'], 0.001);

        // Maior venda do dia primeiro.
        $this->assertSame('000102', $linhas->first()['codVendedor']);
    }

    public function test_na_segunda_o_dia_fechado_e_a_sexta(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 22, 18, 15)); // segunda
        $this->pedido('000101', '2026-06-19', 1234.0);           // sexta
        $this->pedido('000101', '2026-06-21', 50.0);             // domingo: não é o dia

        $resumo = app(ResumoEquipeBuilder::class)->equipe($this->sandra);

        $this->assertSame('2026-06-19', $resumo['periodo']['dia']);
        $this->assertEqualsWithDelta(1234.0, $resumo['totais']['venda'], 0.001);
    }

    public function test_contatos_sao_so_os_de_hoje_e_ignoram_excluida(): void
    {
        $this->contato($this->ana, 'telefonica', now()->subHours(2));
        $this->contato($this->ana, 'whatsapp', now()->subHour());
        $this->contato($this->ana, 'email', now()->subDays(1));              // ontem: não é hoje
        $this->contato($this->bruno, 'telefonica', now()->subMinutes(5), 'excluida');

        $resumo = app(ResumoEquipeBuilder::class)->equipe($this->sandra);
        $linhas = collect($resumo['secoes'][0]['linhas']);

        $this->assertSame(2, $linhas->firstWhere('codVendedor', '000101')['contatos']);
        $this->assertSame(0, $linhas->firstWhere('codVendedor', '000102')['contatos']);
        $this->assertSame(2, $resumo['totais']['contatos']);
    }

    // ── 2. Quem é a equipe ──────────────────────────────────────────────────────

    public function test_equipe_e_quem_aponta_para_o_gestor_mais_ele(): void
    {
        $resumo = app(ResumoEquipeBuilder::class)->equipe($this->sandra);

        $codigos = collect($resumo['secoes'][0]['linhas'])->pluck('codVendedor')->sort()->values()->all();
        $this->assertSame(['000100', '000101', '000102'], $codigos);
        $this->assertSame('Equipe Sandra', $resumo['titulo']);
    }

    public function test_diretor_com_representantes_recebe_a_equipe_dele(): void
    {
        $resumo = app(ResumoEquipeBuilder::class)->equipe($this->beto);

        $this->assertNotNull($resumo);
        $codigos = collect($resumo['secoes'][0]['linhas'])->pluck('codVendedor')->all();
        $this->assertContains('000201', $codigos);
        $this->assertNotContains('000999', $codigos);
    }

    public function test_gestor_sem_ninguem_abaixo_nao_tem_resumo_de_equipe(): void
    {
        $this->assertNull(app(ResumoEquipeBuilder::class)->equipe($this->paulo));
    }

    public function test_consolidado_tem_uma_secao_por_gestor_marcado_e_total_sem_dupla_contagem(): void
    {
        $this->cenarioComercial();
        // Pedido da própria Sandra no dia fechado: o código dela está na equipe dela E na do Beto.
        $this->pedido('000100', '2026-06-16', 3300.0);

        $resumo = app(ResumoEquipeBuilder::class)->consolidado();

        $nomes = collect($resumo['secoes'])->pluck('nome')->sort()->values()->all();
        $this->assertSame(['ROBERTO ALVES', 'SANDRA LIMA'], $nomes);

        // Dia: Bruno 700 + Sandra 3.300 na equipe da Sandra; Sandra 3.300 na do Beto.
        $somaSecoes = collect($resumo['secoes'])->sum(fn ($s) => $s['totais']['pedidos']);
        $this->assertSame(3, $somaSecoes, 'o pedido da Sandra aparece nas duas seções');
        $this->assertSame(2, $resumo['totais']['pedidos'], 'mas o total conta a união dos códigos');
        $this->assertEqualsWithDelta(4000.0, $resumo['totais']['venda'], 0.001);

        // O resumão do mês também é sobre a união: 1.000 + 700 + 3.300.
        $this->assertEqualsWithDelta(5000.0, $resumo['resumao']['mes']['venda']['realizado'], 0.001);
    }

    public function test_consolidado_acompanha_quem_esta_marcado(): void
    {
        $this->beto->forceFill(['resumo_diario' => 'nenhum'])->save();

        $resumo = app(ResumoEquipeBuilder::class)->consolidado();

        $this->assertSame(['SANDRA LIMA'], collect($resumo['secoes'])->pluck('nome')->all());
    }

    // ── 3. O envio ──────────────────────────────────────────────────────────────

    public function test_disparo_desligado_nao_envia_nada(): void
    {
        Mail::fake();
        config(['resumo_equipe.habilitado' => false]);

        EnviarResumosEquipeJob::dispatchSync();

        Mail::assertNothingSent();
    }

    public function test_disparo_envia_para_cada_marcado_e_pula_quem_nao_tem_equipe(): void
    {
        Mail::fake();
        config(['resumo_equipe.habilitado' => true, 'resumo_equipe.redirecionar_para' => null]);

        EnviarResumosEquipeJob::dispatchSync();

        // Sandra e Beto (equipe), Paulo e Leandro (consolidado). Ana/Bruno não estão marcados.
        Mail::assertSent(ResumoEquipeMail::class, 4);
        Mail::assertSent(ResumoEquipeMail::class, fn ($m) => $m->hasTo($this->sandra->email) && $m->resumo['tipo'] === 'equipe');
        Mail::assertSent(ResumoEquipeMail::class, fn ($m) => $m->hasTo($this->leandro->email) && $m->resumo['tipo'] === 'consolidado');
        Mail::assertNotSent(ResumoEquipeMail::class, fn ($m) => $m->hasTo($this->ana->email));
    }

    public function test_gestor_marcado_sem_equipe_nao_recebe_email_vazio(): void
    {
        Mail::fake();
        $this->paulo->forceFill(['resumo_diario' => 'equipe'])->save();

        (new EnviarResumoEquipeJob($this->paulo->id))->handle(app(ResumoEquipeBuilder::class));

        Mail::assertNothingSent();
    }

    public function test_segundo_envio_no_mesmo_dia_nao_duplica(): void
    {
        Mail::fake();

        (new EnviarResumoEquipeJob($this->sandra->id))->handle(app(ResumoEquipeBuilder::class));
        (new EnviarResumoEquipeJob($this->sandra->id))->handle(app(ResumoEquipeBuilder::class));

        Mail::assertSent(ResumoEquipeMail::class, 1);

        Carbon::setTestNow(now()->addDay());
        (new EnviarResumoEquipeJob($this->sandra->id))->handle(app(ResumoEquipeBuilder::class));
        Mail::assertSent(ResumoEquipeMail::class, 2);
    }

    public function test_redirecionamento_desvia_tudo_e_avisa_no_assunto(): void
    {
        Mail::fake();
        config(['resumo_equipe.redirecionar_para' => 'teste@autopel.com']);

        (new EnviarResumoEquipeJob($this->sandra->id))->handle(app(ResumoEquipeBuilder::class));

        Mail::assertSent(ResumoEquipeMail::class, function (ResumoEquipeMail $m) {
            return $m->hasTo('teste@autopel.com')
                && ! $m->hasTo($this->sandra->email)
                && str_contains($m->envelope()->subject, '[TESTE → '.$this->sandra->email.']');
        });
    }

    public function test_quem_esta_como_nenhum_nao_recebe(): void
    {
        Mail::fake();

        (new EnviarResumoEquipeJob($this->ana->id))->handle(app(ResumoEquipeBuilder::class));

        Mail::assertNothingSent();
    }

    public function test_email_renderiza_com_os_numeros_e_nomes(): void
    {
        $this->cenarioComercial();
        $resumo = app(ResumoEquipeBuilder::class)->consolidado();

        $html = (new ResumoEquipeMail($resumo, 'Leandro'))->render();

        $this->assertStringContainsString('Equipe Sandra Lima', $html);
        $this->assertStringContainsString('Ana Souza', $html);
        $this->assertStringContainsString('R$ 700', $html);          // venda do dia (Bruno)
        $this->assertStringContainsString('R$ 1.700', $html);        // resumão do mês

        // O dia da semana escrito, dos dois lados da linha.
        $this->assertStringContainsString('Hoje, quarta, 17/06', $html);
        $this->assertStringContainsString('Terça, 16/06', $html);

        // Saíram em 2026-09-28: carteira e acumulado do ano.
        $this->assertStringNotContainsString('Carteira', $html);
        $this->assertStringNotContainsString('acumulado', $html);
    }

    public function test_comando_de_previa_grava_html_sem_enviar(): void
    {
        Mail::fake();

        $this->artisan('resumo-equipe:enviar', ['--usuario' => $this->sandra->email, '--previa' => true])
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_tela_equipe_grava_a_opcao_de_resumo(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $payload = [
            'name' => $this->ana->name, 'email' => $this->ana->email, 'perfil' => 'vendedor',
            'cod_vendedor' => '000101', 'cod_super' => self::SANDRA,
        ];

        $this->actingAs($admin)->patch(route('equipe.update', $this->ana), $payload + ['resumo_diario' => 'equipe'])
            ->assertSessionHasNoErrors();
        $this->assertSame('equipe', $this->ana->fresh()->resumo_diario);

        $this->actingAs($admin)->patch(route('equipe.update', $this->ana), $payload + ['resumo_diario' => 'todo_mundo'])
            ->assertSessionHasErrors('resumo_diario');
        $this->assertSame('equipe', $this->ana->fresh()->resumo_diario);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function pessoa(string $perfil, ?string $cod, ?string $codSuper, string $nome, string $resumo = 'nenhum'): User
    {
        $user = User::factory()->create(['name' => $nome, 'display_name' => $nome]);
        $user->forceFill(['resumo_diario' => $resumo])->save();
        $user->assignRole($perfil);

        if ($cod) {
            VendedorPerfil::create(['user_id' => $user->id, 'cod_vendedor' => $cod, 'cod_super' => $codSuper]);
        }

        return $user->fresh();
    }

    /** Pedidos, notas e metas da equipe da Sandra em junho/2026. */
    private function cenarioComercial(): void
    {
        $this->pedido('000101', '2026-06-05', 1000.0);
        $this->pedido('000102', '2026-06-16', 700.0);
        $this->pedido('000102', '2026-06-17', 9999.0);  // hoje: fora da janela D-1
        $this->pedido('000999', '2026-06-05', 55555.0); // fora da equipe

        $this->nota('000101', '2026-06-09', 500.0);
        $this->nota('000102', '2026-06-17', 90.0);      // hoje: fora

        $this->meta('000101', 'venda', 4000);
        $this->meta('000102', 'venda', 2500);
        $this->meta('000101', 'faturamento', 800);
        $this->meta('000102', 'faturamento', 250);
    }

    private function pedido(string $cod, string $data, float $valor): void
    {
        Pedido::create([
            'numero_pedido' => 'P'.fake()->unique()->numberBetween(1, 999999),
            'cod_vendedor' => $cod,
            'data_pedido' => $data,
            'valor_total' => $valor,
            'status' => 'faturado',
            'data_faturamento' => $data,
        ]);
    }

    private function nota(string $cod, string $data, float $valor): void
    {
        Faturamento::create([
            'nota_fiscal' => (string) fake()->unique()->numberBetween(1, 999999),
            'data_emissao' => $data,
            'cod_vendedor' => $cod,
            'valor_total' => $valor,
            'quantidade' => 1,
            'valor_unitario' => $valor,
        ]);
    }

    private function meta(string $cod, string $tipo, float $valor): void
    {
        MetaMensal::create(['cod_vendedor' => $cod, 'ano' => 2026, 'mes' => 6, 'tipo' => $tipo, 'valor_meta' => $valor]);
    }

    private function contato(User $u, string $canal, Carbon $quando, string $status = 'finalizada'): void
    {
        Ligacao::create([
            'usuario_id' => $u->id, 'cliente_nome' => 'X', 'tipo_contato' => $canal,
            'status' => $status, 'data_ligacao' => $quando,
        ]);
    }
}
