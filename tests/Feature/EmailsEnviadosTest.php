<?php

namespace Tests\Feature;

use App\Models\EmailEnviado;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailsEnviadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'vendedor'] as $papel) {
            Role::findOrCreate($papel);
        }
    }

    public function test_email_enviado_fica_registrado_com_envelope(): void
    {
        Mail::to('pcp@exemplo.test')->cc(['cadastro@exemplo.test', 'vendedor@exemplo.test'])
            ->send(new EmailDeTesteMail('Solicitação de bobina #7'));

        $e = EmailEnviado::sole();
        $this->assertSame('enviado', $e->status);
        $this->assertSame('Solicitação de bobina #7', $e->assunto);
        $this->assertSame('pcp@exemplo.test', $e->para);
        $this->assertSame('cadastro@exemplo.test, vendedor@exemplo.test', $e->cc);
        $this->assertSame(['ficha.pdf'], $e->anexos);
        $this->assertSame('EmailDeTesteMail', $e->origem);
    }

    public function test_email_da_fila_que_falha_fica_registrado_como_falha(): void
    {
        try {
            Mail::to('pcp@exemplo.test')->queue(new EmailDeTesteMail('Vai falhar', falhar: true));
        } catch (RuntimeException) {
            // fila sync relança a exceção depois de marcar o job como falho
        }

        $e = EmailEnviado::sole();
        $this->assertSame('falhou', $e->status);
        $this->assertSame('pcp@exemplo.test', $e->para);
        $this->assertSame('Vai falhar', $e->assunto);
        $this->assertStringContainsString('SMTP recusou', $e->erro);
    }

    public function test_so_admin_ve_a_tela(): void
    {
        EmailEnviado::create(['status' => 'enviado', 'assunto' => 'Oi', 'para' => 'a@exemplo.test']);

        $this->actingAs(User::factory()->create()->assignRole('vendedor'))
            ->get(route('emails.index'))->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('emails.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Emails/Index')->where('emails.data.0.assunto', 'Oi'));
    }

    public function test_filtro_de_situacao(): void
    {
        EmailEnviado::create(['status' => 'enviado', 'assunto' => 'Ok']);
        EmailEnviado::create(['status' => 'falhou', 'assunto' => 'Ruim']);

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('emails.index', ['status' => 'falhou']))
            ->assertInertia(fn ($p) => $p->has('emails.data', 1)->where('emails.data.0.assunto', 'Ruim'));
    }
}

class EmailDeTesteMail extends Mailable implements ShouldQueue
{
    public function __construct(public string $assunto, public bool $falhar = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->assunto);
    }

    public function content(): Content
    {
        if ($this->falhar) {
            throw new RuntimeException('SMTP recusou');
        }

        return new Content(htmlString: '<p>oi</p>');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => '%PDF-1.4', 'ficha.pdf')];
    }
}
