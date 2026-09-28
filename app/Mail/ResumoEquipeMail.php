<?php

namespace App\Mail;

use App\Services\ResumoEquipe\FormatoResumo;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Resumo diário da equipe (e o consolidado das equipes) para os gestores.
 *
 * ⚠️ NÃO é ShouldQueue, de propósito: quem envia já é um job na fila
 * (`EnviarResumoEquipeJob`). Um Mailable ShouldQueue faria o `send()` de dentro do job
 * enfileirar de novo — e a marca de "já enviado hoje" seria gravada antes de o SMTP
 * responder, então uma falha no segundo job ficaria muda.
 *
 * Os dados chegam prontos do `ResumoEquipeBuilder`; aqui só se decide assunto e visual.
 */
class ResumoEquipeMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $resumo
     */
    public function __construct(
        public readonly array $resumo,
        public readonly string $nomeDestinatario,
        private readonly ?string $destinoReal = null,
    ) {}

    public function envelope(): Envelope
    {
        $data = Carbon::parse($this->resumo['periodo']['geradoEm'])->format('d/m');
        $assunto = "Resumo do dia · {$this->resumo['titulo']} · {$data}";

        // Redirecionado: o assunto diz para quem teria ido, igual aos e-mails de Cadastros.
        if ($this->destinoReal !== null) {
            $assunto = "[TESTE → {$this->destinoReal}] {$assunto}";
        }

        return new Envelope(subject: $assunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.resumo-equipe',
            with: self::dadosDaView($this->resumo, $this->nomeDestinatario),
        );
    }

    /**
     * Dados da view — público para o comando de prévia renderizar o MESMO HTML sem montar
     * um Mailable (lá não existe `$message` para embutir o logo).
     *
     * @param  array<string, mixed>  $resumo
     * @return array<string, mixed>
     */
    public static function dadosDaView(array $resumo, string $nomeDestinatario): array
    {
        $logo = public_path('images/autopel-logo-white.png');

        return [
            'r' => $resumo,
            'fmt' => new FormatoResumo,
            'nome' => $nomeDestinatario,
            'logo' => file_exists($logo) ? $logo : null,
            'urlCrm' => rtrim((string) config('app.url'), '/').($resumo['tipo'] === 'consolidado' ? '/metas' : '/visao-gestor'),
        ];
    }
}
