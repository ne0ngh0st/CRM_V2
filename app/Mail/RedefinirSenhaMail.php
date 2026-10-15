<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail de "esqueci minha senha", com a identidade visual da Autopel.
 *
 * É um Mailable e não um MailMessage porque o template padrão de notificação do Laravel
 * não é customizável o bastante: ele injeta o rodapé "If you're having trouble clicking
 * the button..." e "All rights reserved" em inglês, e não há gancho para trocar isso sem
 * publicar as views do framework — que passariam a exigir manutenção a cada upgrade.
 *
 * ⚠️ O logo entra por `$message->embed()` dentro da view (anexo referenciado por `cid:`).
 * Não trocar por `data:` URI: Gmail e Outlook descartam imagem em base64, e o e-mail
 * chega com o logo quebrado — justamente o oposto do objetivo.
 */
class RedefinirSenhaMail extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly string $nome,
        public readonly int $minutos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Redefinição de senha — PALMA CRM',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.redefinir-senha',
            with: [
                'url' => $this->url,
                'nome' => $this->nome,
                'minutos' => $this->minutos,
                'logo' => $this->logoBranco(),
            ],
        );
    }

    /**
     * ⚠️ Versão BRANCA do logo, não a colorida.
     *
     * De propósito não reusa `SolicitacaoFormatador::logoPath()`: aquele resolve o logo
     * dos PDFs, que são impressos em fundo branco e por isso preferem a arte colorida.
     * Aqui o cabeçalho é navy (#0F3A69) e a arte colorida some no fundo escuro. É a
     * mesma marca, mas a decisão é outra — centralizar as duas num helper só faria uma
     * delas ficar errada.
     *
     * Devolve null se o arquivo não existir; a view cai para o nome em texto.
     */
    private function logoBranco(): ?string
    {
        $branco = public_path('images/autopel-logo-white.png');

        return file_exists($branco) ? $branco : null;
    }
}
