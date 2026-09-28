{{--
    Cartão de destaque. Parâmetros: $rotulo, $valor, $linha1 (html), $linha2 (html|null), $acento (cor).
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff; border:1px solid #e4e7ec; border-top:3px solid {{ $acento }}; border-radius:6px;">
    <tr>
        <td style="padding:16px 18px 14px 18px;">
            <p style="margin:0 0 6px 0; font-size:11px; line-height:14px; letter-spacing:1.2px; text-transform:uppercase; color:#667085; font-weight:bold;">{{ $rotulo }}</p>
            <p style="margin:0 0 8px 0; font-size:28px; line-height:32px; color:#101828; font-weight:bold;">{{ $valor }}</p>
            <p style="margin:0; font-size:12px; line-height:18px; color:#475467;">{!! $linha1 !!}</p>
            @if (! empty($linha2))
                <p style="margin:6px 0 0 0; font-size:12px; line-height:18px; color:#475467;">{!! $linha2 !!}</p>
            @endif
        </td>
    </tr>
</table>
