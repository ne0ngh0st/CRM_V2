<?php

namespace App\Services\Email;

use App\Jobs\EnviarResumoEquipeJob;
use App\Models\EmailEnviado;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Único ponto que escreve em `emails_enviados`. Ligado no `AppServiceProvider`.
 *
 * - `enviado()` escuta `MessageSent`: dispara depois do SMTP aceitar a mensagem, para
 *   QUALQUER caminho (Mailable na fila, `Mail::send`, notificação de senha).
 * - `falhou()` escuta `JobFailed` e só olha jobs de e-mail. Cobre a falha definitiva
 *   (depois das retentativas) de tudo que vai pela fila — que é todo e-mail do sistema.
 *
 * ⚠️ Nenhum dos dois pode derrubar o envio: um erro gravando o log não pode virar
 * "e-mail não saiu". Tudo dentro de try/catch, com log.
 */
class RegistroDeEmails
{
    /** Jobs cuja falha significa "um e-mail não saiu". */
    private const JOBS_DE_EMAIL = [SendQueuedMailable::class, EnviarResumoEquipeJob::class];

    public function enviado(MessageSent $evento): void
    {
        try {
            /** @var Email $m */
            $m = $evento->message;

            EmailEnviado::create([
                'status' => 'enviado',
                'assunto' => mb_substr((string) $m->getSubject(), 0, 500),
                'remetente' => self::enderecos($m->getFrom()),
                'para' => self::enderecos($m->getTo()),
                'cc' => self::enderecos($m->getCc()),
                'bcc' => self::enderecos($m->getBcc()),
                'anexos' => self::anexos($m) ?: null,
                'origem' => self::nomeCurto($evento->data['__laravel_mailable'] ?? $evento->data['__laravel_notification'] ?? null),
            ]);
        } catch (Throwable $e) {
            Log::warning('Falha ao registrar e-mail enviado', ['erro' => $e->getMessage()]);

            return;
        }

        // Separado do registro: falhar o aviso da cota não pode apagar o log do envio.
        try {
            CotaDeEmails::avisarSeCruzouMarco();
        } catch (Throwable $e) {
            Log::warning('Falha ao conferir a cota de e-mails', ['erro' => $e->getMessage()]);
        }
    }

    public function falhou(JobFailed $evento): void
    {
        try {
            // ⚠️ Pelo COMANDO, não por `resolveName()`: o `SendQueuedMailable` se apresenta
            // com o nome do Mailable (`displayName()`), então comparar o nome do job com
            // a classe dele nunca casa.
            $comando = @unserialize($evento->job->payload()['data']['command'] ?? '');
            if (! is_object($comando) || ! in_array($comando::class, self::JOBS_DE_EMAIL, true)) {
                return;
            }

            $dados = ['status' => 'falhou', 'origem' => self::nomeCurto($comando::class), 'erro' => mb_substr($evento->exception->getMessage(), 0, 2000)];

            // Para Mailable na fila dá para recuperar o envelope. Se não der, fica só a
            // origem e o erro — o que já basta.
            if ($comando instanceof SendQueuedMailable) {
                try {
                    $mailable = $comando->mailable;
                    $dados['origem'] = self::nomeCurto($mailable::class);
                    $dados['para'] = self::listar($mailable->to);
                    $dados['cc'] = self::listar($mailable->cc);
                    $dados['assunto'] = $mailable->subject ?? (method_exists($mailable, 'envelope') ? $mailable->envelope()->subject : null);
                } catch (Throwable) {
                    // envelope é bônus
                }
            }

            EmailEnviado::create($dados);
        } catch (Throwable $e) {
            Log::warning('Falha ao registrar e-mail que falhou', ['erro' => $e->getMessage()]);
        }
    }

    /** @param  Address[]  $lista */
    private static function enderecos(array $lista): ?string
    {
        return implode(', ', array_map(fn (Address $a) => $a->getAddress(), $lista)) ?: null;
    }

    /** O `to`/`cc` de um Mailable é lista de ['address' => ..., 'name' => ...]. */
    private static function listar(array $lista): ?string
    {
        return implode(', ', array_filter(array_map(fn ($a) => $a['address'] ?? null, $lista))) ?: null;
    }

    /** Nome dos anexos de verdade — imagem embutida (logo, `cid:`) não conta. */
    private static function anexos(Email $m): array
    {
        $nomes = [];
        foreach ($m->getAttachments() as $parte) {
            if ($parte->getDisposition() === 'inline') {
                continue;
            }
            $nomes[] = $parte->getFilename() ?? 'anexo';
        }

        return $nomes;
    }

    private static function nomeCurto(?string $classe): ?string
    {
        return $classe ? class_basename($classe) : null;
    }
}
