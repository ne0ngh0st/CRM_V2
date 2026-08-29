<?php

namespace Tests\Feature;

use App\Mail\CadastroSolicitacaoMail;
use App\Models\SolicitacaoBobina;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Trava o interruptor que decide se a solicitação de cadastro vai para os setores reais
 * (PCP / Cadastro) ou para um endereço de teste.
 *
 * Existe porque em 2026-08-28 um teste disparou e-mail de verdade para o pcp.sp@ e o
 * cadastro@. A proteção anterior era uma constante editada no código e revertida na mão
 * — o tipo de coisa que se esquece. Agora é configuração, e este teste garante que ela
 * realmente redireciona.
 */
class CadastroEmailRedirecionamentoTest extends TestCase
{
    use RefreshDatabase;

    private function solicitacao(): SolicitacaoBobina
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('vendedor');

        return SolicitacaoBobina::create([
            'user_id' => $user->id,
            'solicitante_nome' => $user->display_name,
            'nomenclatura' => 'BOBINA TESTE',
            'titulo_padronizado' => 'BOBINA TS KPH BC 80X40',
            'largura' => '80',
            'metragem' => '40',
            'gramatura' => '48',
            'papel' => 'termicco',
            'status' => 'pendente',
        ]);
    }

    public function test_sem_configuracao_vai_para_os_setores_reais(): void
    {
        Mail::fake();
        config(['cadastros.redirecionar_emails_para' => null]);

        $bobina = $this->solicitacao();

        $this->actingAs($bobina->user)
            ->post(route('cadastros.bobinas.enviar', $bobina));

        Mail::assertQueued(CadastroSolicitacaoMail::class, function ($mail) {
            return $mail->hasTo('pcp.sp@autopel.com')
                && ! str_contains($mail->envelope()->subject, '[TESTE');
        });
    }

    public function test_com_configuracao_vai_só_para_o_endereco_de_teste(): void
    {
        Mail::fake();
        config(['cadastros.redirecionar_emails_para' => 'antonio.barbosa@autopel.com']);

        $bobina = $this->solicitacao();

        $this->actingAs($bobina->user)
            ->post(route('cadastros.bobinas.enviar', $bobina));

        Mail::assertQueued(CadastroSolicitacaoMail::class, function ($mail) {
            return $mail->hasTo('antonio.barbosa@autopel.com')
                && ! $mail->hasTo('pcp.sp@autopel.com')
                && ! $mail->hasCc('cadastro@autopel.com');
        });
    }

    /**
     * O prefixo existe porque o objetivo do teste é conferir o ROTEAMENTO: sem ele você
     * vê o conteúdo do e-mail e continua sem saber para qual setor teria ido.
     */
    public function test_assunto_mostra_para_quem_teria_ido(): void
    {
        Mail::fake();
        config(['cadastros.redirecionar_emails_para' => 'antonio.barbosa@autopel.com']);

        $bobina = $this->solicitacao();

        $this->actingAs($bobina->user)
            ->post(route('cadastros.bobinas.enviar', $bobina));

        Mail::assertQueued(CadastroSolicitacaoMail::class, function ($mail) {
            return str_starts_with($mail->envelope()->subject, '[TESTE →')
                && str_contains($mail->envelope()->subject, 'pcp.sp@autopel.com');
        });
    }
}
