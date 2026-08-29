<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail de notificação de solicitação de cadastro (bobina/etiqueta/cliente) pra
 * PCP/Cadastro — substitui o `mailto:` que só abria o cliente de e-mail do usuário.
 * Enfileirado (ShouldQueue): SMTP externo não pode segurar a resposta do POST
 * (Regra de ouro nº 9 — escrita tem orçamento de 500ms).
 */
class CadastroSolicitacaoMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var ?string base64 — payload da fila é JSON, e PDF binário cru quebra o encode ("Malformed UTF-8 characters"). */
    private readonly ?string $anexoConteudoBase64;

    public function __construct(
        private readonly string $assunto,
        public readonly string $corpo,
        ?string $anexoConteudo = null,
        private readonly ?string $anexoNome = null,
    ) {
        $this->anexoConteudoBase64 = $anexoConteudo !== null ? base64_encode($anexoConteudo) : null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->assunto);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.cadastro-solicitacao');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if ($this->anexoConteudoBase64 === null) {
            return [];
        }

        return [
            Attachment::fromData(fn () => base64_decode($this->anexoConteudoBase64), $this->anexoNome ?? 'anexo.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
