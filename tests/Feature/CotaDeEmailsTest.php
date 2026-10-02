<?php

namespace Tests\Feature;

use App\Models\EmailEnviado;
use App\Models\Notificacao;
use App\Models\User;
use App\Services\Email\CotaDeEmails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CotaDeEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        config(['mail.cota_mensal' => 10]);
    }

    private function registrar(int $n, string $status = 'enviado', ?string $quando = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $e = EmailEnviado::create(['status' => $status, 'assunto' => 'x']);
            $e->forceFill(['created_at' => $quando ?? now()])->save();
        }
    }

    public function test_conta_so_enviados_do_mes_corrente(): void
    {
        $this->registrar(3, quando: '2026-10-01 00:00:00');
        $this->registrar(2, quando: '2026-10-15 11:00:00');
        $this->registrar(4, 'falhou', '2026-10-10 10:00:00');
        $this->registrar(5, quando: '2026-09-30 23:59:59');
        $this->registrar(1, quando: '2026-11-01 00:00:00');

        $c = CotaDeEmails::resumo(CarbonImmutable::parse('2026-10-15 12:00'));

        $this->assertSame(5, $c['enviados']);
        $this->assertSame(5, $c['restantes']);
        $this->assertSame(50.0, $c['percentual']);
        $this->assertSame(10, $c['projecao']); // 5 em 15 dias -> 10,3 em 31 dias
        $this->assertSame('ok', $c['nivel']);
    }

    public function test_niveis_de_aviso(): void
    {
        $agora = CarbonImmutable::parse('2026-10-28 12:00');

        $this->registrar(8, quando: '2026-10-02 10:00:00');
        $this->assertSame('atencao', CotaDeEmails::resumo($agora)['nivel']);

        $this->registrar(2, quando: '2026-10-03 10:00:00');
        $c = CotaDeEmails::resumo($agora);
        $this->assertSame('esgotada', $c['nivel']);
        $this->assertSame(0, $c['restantes']);
    }

    public function test_projecao_acima_da_cota_ja_e_atencao(): void
    {
        // 5 em 6 dias = 50% da cota, mas projeta ~26 no mês
        $this->registrar(5, quando: '2026-10-02 10:00:00');
        $c = CotaDeEmails::resumo(CarbonImmutable::parse('2026-10-06 12:00'));

        $this->assertSame('atencao', $c['nivel']);
        $this->assertSame(26, $c['projecao']);
    }

    public function test_sem_projecao_nos_primeiros_dias(): void
    {
        $this->registrar(5, quando: '2026-10-02 10:00:00');
        $c = CotaDeEmails::resumo(CarbonImmutable::parse('2026-10-04 12:00'));

        $this->assertNull($c['projecao']);
        $this->assertSame('ok', $c['nivel']);
    }

    public function test_avisa_admins_ativos_uma_vez_por_marco(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        User::factory()->create(['is_active' => false])->assignRole('admin');

        $this->registrar(7);
        CotaDeEmails::avisarSeCruzouMarco();
        $this->assertSame(0, Notificacao::count());

        $this->registrar(1); // 8 = 80%
        CotaDeEmails::avisarSeCruzouMarco();
        CotaDeEmails::avisarSeCruzouMarco();
        $this->assertSame(1, Notificacao::where('user_id', $admin->id)->count());
        $this->assertSame(1, Notificacao::count());
        $this->assertStringContainsString('80%', Notificacao::sole()->titulo);

        $this->registrar(2); // 10 = 100%
        CotaDeEmails::avisarSeCruzouMarco();
        $this->assertSame(2, Notificacao::count());
        $this->assertStringContainsString('esgotada', Notificacao::latest('id')->first()->titulo);
    }

    public function test_envio_de_verdade_dispara_o_aviso(): void
    {
        User::factory()->create()->assignRole('admin');
        $this->registrar(7);

        Mail::html('<p>oi</p>', fn ($m) => $m->to('pcp@exemplo.test')->subject('Oitavo'));

        $this->assertSame(8, EmailEnviado::count());
        $this->assertSame(1, Notificacao::where('tipo', 'cota_email')->count());
    }

    public function test_tela_recebe_a_cota(): void
    {
        $this->registrar(3);

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('emails.index'))
            ->assertInertia(fn ($p) => $p->where('cota.enviados', 3)->where('cota.limite', 10));
    }
}
