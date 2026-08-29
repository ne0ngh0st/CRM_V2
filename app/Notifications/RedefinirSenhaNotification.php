<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * E-mail de "esqueci minha senha".
 *
 * Existe para substituir a notificação padrão do Laravel, que sai em inglês e
 * assinada como "Laravel" — num sistema em pt_BR, com 200 usuários comerciais que
 * não são técnicos, isso parece phishing e o e-mail acaba ignorado ou reportado.
 *
 * ⚠️ NÃO implementa ShouldQueue de propósito. O usuário está parado na tela
 * esperando o e-mail chegar; enfileirar adiciona latência sem ganho — o envio é
 * um POST ao relay SMTP e acontece em milissegundos. (O e-mail de Cadastros vai
 * pra fila porque carrega um PDF anexo e ninguém fica esperando.)
 */
class RedefinirSenhaNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        // O tempo de validade vem da configuração, não de um número escrito à mão:
        // se alguém mudar 'expire' no config/auth.php, o texto acompanha em vez de
        // passar a mentir para o usuário.
        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Redefinição de senha — PALMA CRM')
            ->greeting('Olá, '.($notifiable->display_name ?: $notifiable->name).'!')
            ->line('Recebemos um pedido para redefinir a senha da sua conta no PALMA CRM.')
            ->action('Redefinir minha senha', $url)
            ->line("Por segurança, este link vale por {$minutos} minutos e só pode ser usado uma vez.")
            ->line('Se você não pediu isso, ignore este e-mail — sua senha continua a mesma.')
            ->salutation('Autopel Soluções');
    }
}
