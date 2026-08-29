{{--
    E-mail de redefinição de senha — identidade visual da Autopel.

    ⚠️ Regras de HTML para e-mail, que NÃO são as da web e não devem ser "modernizadas":

    - Layout por <table>, nunca flex/grid: Outlook (motor Word) ignora os dois.
    - Estilo inline, nunca classe: Gmail remove <style> do <head> em boa parte dos casos.
    - Nada de rem/vh: use px.
    - O logo entra por $message->embed(), que anexa o arquivo e referencia por cid:.
      NÃO trocar por <img src="data:image/png;base64,...">: Gmail e Outlook descartam
      data: URI em imagem, e o e-mail chega com o logo quebrado.

    Cores oficiais (Arte/Tema.json): navy #0F3A69, teal #005A6F, cyan #00A9CE.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinição de senha</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f1f4; font-family:Arial, Helvetica, sans-serif; -webkit-font-smoothing:antialiased;">

    {{-- Pré-cabeçalho: o trecho que a caixa de entrada mostra ao lado do assunto. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        Link para criar uma senha nova no PALMA CRM. Vale por {{ $minutos }} minutos.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f1f4;">
        <tr>
            <td align="center" style="padding:32px 16px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; background-color:#ffffff; border:1px solid #d8d9de;">

                    {{-- Cabeçalho navy com o logo branco --}}
                    <tr>
                        <td align="center" style="background-color:#0F3A69; padding:28px 24px;">
                            @if ($logo)
                                <img src="{{ $message->embed($logo) }}" alt="Autopel Soluções" width="150" style="display:block; width:150px; max-width:150px; height:auto; border:0;">
                            @else
                                <span style="color:#ffffff; font-size:20px; font-weight:bold; letter-spacing:1px;">AUTOPEL SOLUÇÕES</span>
                            @endif
                        </td>
                    </tr>

                    {{-- Faixa cyan de acento --}}
                    <tr>
                        <td style="background-color:#00A9CE; height:4px; line-height:4px; font-size:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td style="padding:32px 32px 8px 32px;">
                            <p style="margin:0 0 4px 0; font-size:12px; letter-spacing:1.5px; text-transform:uppercase; color:#005A6F; font-weight:bold;">
                                PALMA CRM
                            </p>
                            <h1 style="margin:0 0 20px 0; font-size:22px; line-height:28px; color:#1a1a1a; font-weight:bold;">
                                Redefinição de senha
                            </h1>

                            <p style="margin:0 0 16px 0; font-size:15px; line-height:23px; color:#3f3f46;">
                                Olá, <strong>{{ $nome }}</strong>.
                            </p>
                            <p style="margin:0 0 24px 0; font-size:15px; line-height:23px; color:#3f3f46;">
                                Recebemos um pedido para redefinir a senha da sua conta.
                                Clique no botão abaixo para criar uma nova.
                            </p>
                        </td>
                    </tr>

                    {{-- Botão "à prova de balas": tabela, não <a> estilizado, por causa do Outlook --}}
                    <tr>
                        <td align="center" style="padding:0 32px 28px 32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="background-color:#0F3A69;">
                                        <a href="{{ $url }}" target="_blank"
                                           style="display:inline-block; padding:14px 34px; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none;">
                                            Criar nova senha
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Aviso de validade --}}
                    <tr>
                        <td style="padding:0 32px 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f7f7f9; border-left:3px solid #00A9CE;">
                                <tr>
                                    <td style="padding:14px 16px; font-size:13px; line-height:20px; color:#52525b;">
                                        Por segurança, este link vale por <strong>{{ $minutos }} minutos</strong> e só pode ser usado uma vez.<br>
                                        Se você não pediu a redefinição, ignore este e-mail — sua senha continua a mesma.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Alternativa ao botão, em português (o texto padrão do Laravel saía em inglês) --}}
                    <tr>
                        <td style="padding:0 32px 28px 32px; border-top:1px solid #e6e6ea;">
                            <p style="margin:20px 0 6px 0; font-size:12px; line-height:18px; color:#71717a;">
                                Se o botão não funcionar, copie e cole este endereço no seu navegador:
                            </p>
                            <p style="margin:0; font-size:12px; line-height:18px; word-break:break-all;">
                                <a href="{{ $url }}" target="_blank" style="color:#005A6F;">{{ $url }}</a>
                            </p>
                        </td>
                    </tr>

                </table>

                {{-- Rodapé --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">
                    <tr>
                        <td align="center" style="padding:20px 24px;">
                            <p style="margin:0 0 4px 0; font-size:12px; line-height:18px; color:#71717a;">
                                <strong style="color:#52525b;">Autopel Soluções</strong> · CNPJ 06.698.091/0005-90
                            </p>
                            <p style="margin:0; font-size:11px; line-height:17px; color:#a1a1aa;">
                                Mensagem automática do PALMA CRM — não responda a este e-mail.
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</body>
</html>
