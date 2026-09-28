{{--
    Resumo diário da equipe — e-mail das 18:00 para os gestores.

    ⚠️ Regras de HTML de e-mail (as mesmas de redefinir-senha.blade.php) — NÃO "modernizar":
    - Layout por <table>; flex/grid não existem no Outlook (motor do Word).
    - Estilo inline; o <style> do <head> abaixo é só progressivo (empilhar no celular) e o
      e-mail tem que continuar legível se o cliente o descartar.
    - Logo por $message->embed() (cid:). NUNCA data: URI no envio real — Gmail e Outlook
      descartam. O data: URI abaixo só existe na PRÉVIA do comando, onde não há $message.

    Cores oficiais: navy #0F3A69, teal #005A6F, cyan #00A9CE, âmbar #ff8f00.
    Status de carteira: mesmos verde/âmbar/vermelho do card do Painel.
--}}
@php
    $t = $r['totais'];
    $geradoEm = \Illuminate\Support\Carbon::parse($r['periodo']['geradoEm']);
    $vendaAte = \Illuminate\Support\Carbon::parse($r['periodo']['vendaAte']);
    $consolidado = $r['tipo'] === 'consolidado';
    $canais = fn ($c) => '<span style="white-space:nowrap;">☎ '.$c['porCanal']['telefonica'].'</span> &nbsp;·&nbsp; <span style="white-space:nowrap;">WhatsApp '.$c['porCanal']['whatsapp'].'</span> &nbsp;·&nbsp; <span style="white-space:nowrap;">✉ '.$c['porCanal']['email'].'</span>';
    $barraMeta = function ($pct) use ($fmt) {
        [, , $cor] = $fmt->tom($pct);
        $w = $pct === null ? 0 : max(2, min(100, (int) round($pct)));

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;"><tr>'
            .($w > 0 ? '<td width="'.$w.'%" style="background-color:'.$cor.'; height:5px; line-height:5px; font-size:0;">&nbsp;</td>' : '')
            .($w < 100 ? '<td style="background-color:#eaecf0; height:5px; line-height:5px; font-size:0;">&nbsp;</td>' : '')
            .'</tr></table>';
    };
    $secaoTitulo = 'margin:0 0 4px 0; font-size:17px; line-height:22px; color:#0F3A69; font-weight:bold;';
    $secaoSub = 'margin:0 0 16px 0; font-size:12px; line-height:18px; color:#667085;';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Resumo do dia · {{ $r['titulo'] }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .kpi-col { display:block !important; width:100% !important; padding:0 0 12px 0 !important; }
            .pad { padding-left:16px !important; padding-right:16px !important; }
            .leg-col { display:block !important; width:100% !important; padding:0 0 10px 0 !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#eef1f5; font-family:'Segoe UI', Roboto, Arial, Helvetica, sans-serif; -webkit-font-smoothing:antialiased;">

    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $fmt->int($t['contatosHoje']['total']) }} contatos hoje · {{ $fmt->int($t['pedidos']) }} pedidos no mês · venda {{ $fmt->pct($t['vendaPct']) }} da meta · {{ $fmt->pct($t['carteira']['pctAtivos']) }} da carteira ativa.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#eef1f5;">
        <tr>
            <td align="center" style="padding:28px 12px;">

                <table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:680px; background-color:#ffffff; border-radius:8px; overflow:hidden; border:1px solid #dde2ea;">

                    {{-- ── Cabeçalho ─────────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="background-color:#0F3A69; padding:22px 32px 26px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        @if ($logo && isset($message))
                                            <img src="{{ $message->embed($logo) }}" alt="Autopel" width="120" style="display:block; width:120px; height:auto; border:0;">
                                        @elseif ($logo)
                                            <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logo)) }}" alt="Autopel" width="120" style="display:block; width:120px; height:auto; border:0;">
                                        @else
                                            <span style="color:#ffffff; font-size:18px; font-weight:bold; letter-spacing:1px;">AUTOPEL</span>
                                        @endif
                                    </td>
                                    <td align="right" valign="middle" style="font-size:11px; line-height:15px; letter-spacing:1.5px; text-transform:uppercase; color:#9ec5e0; font-weight:bold;">
                                        Resumo do dia<br>
                                        <span style="color:#ffffff; letter-spacing:0.5px; text-transform:none; font-size:13px;">{{ ucfirst($geradoEm->translatedFormat('l, d \d\e F')) }}</span>
                                    </td>
                                </tr>
                            </table>
                            <h1 style="margin:22px 0 4px 0; font-size:26px; line-height:32px; color:#ffffff; font-weight:bold;">{{ $r['titulo'] }}</h1>
                            <p style="margin:0; font-size:14px; line-height:20px; color:#c9dcec;">
                                Olá, {{ $nome }}. Aqui está o retrato {{ $consolidado ? 'das equipes' : 'da sua equipe' }} —
                                {{ $fmt->int($r['vendedores']) }} {{ $r['vendedores'] === 1 ? 'pessoa' : 'pessoas' }}{{ $consolidado ? ' em '.count($r['secoes']).' equipes' : '' }}.
                            </p>
                        </td>
                    </tr>
                    <tr><td style="background-color:#00A9CE; height:4px; line-height:4px; font-size:0;">&nbsp;</td></tr>

                    {{-- ── Janela do dado ────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="background-color:#f5f8fb; padding:10px 32px; border-bottom:1px solid #e4e7ec; font-size:12px; line-height:18px; color:#475467;">
                            <strong style="color:#344054;">Contatos</strong> até {{ $geradoEm->format('H:i') }} de hoje
                            &nbsp;·&nbsp;
                            <strong style="color:#344054;">Venda e faturamento</strong> até {{ $vendaAte->format('d/m') }} (último dia útil, igual ao Painel)
                        </td>
                    </tr>

                    {{-- ── Destaques ─────────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="padding:26px 26px 10px 26px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td class="kpi-col" width="50%" valign="top" style="padding:0 6px 12px 6px;">
                                        @include('emails.resumo._kpi', [
                                            'rotulo' => 'Contatos hoje',
                                            'valor' => $fmt->int($t['contatosHoje']['total']),
                                            'linha1' => $canais($t['contatosHoje']),
                                            'linha2' => 'No mês: <strong style="color:#101828;">'.$fmt->int($t['contatosMes']['total']).'</strong>',
                                            'acento' => '#00A9CE',
                                        ])
                                    </td>
                                    <td class="kpi-col" width="50%" valign="top" style="padding:0 6px 12px 6px;">
                                        @include('emails.resumo._kpi', [
                                            'rotulo' => 'Pedidos emitidos no mês',
                                            'valor' => $fmt->int($t['pedidos']),
                                            'linha1' => 'Somando <strong style="color:#101828;">'.$fmt->reais($t['vendaRealizado']).'</strong> em venda',
                                            'linha2' => 'Até '.$vendaAte->format('d/m'),
                                            'acento' => '#0F3A69',
                                        ])
                                    </td>
                                </tr>
                                <tr>
                                    <td class="kpi-col" width="50%" valign="top" style="padding:0 6px 12px 6px;">
                                        @include('emails.resumo._kpi', [
                                            'rotulo' => 'Venda no mês',
                                            'valor' => $fmt->reais($t['vendaRealizado']),
                                            'linha1' => view('emails.resumo._pilula', ['pct' => $t['vendaPct'], 'fmt' => $fmt])->render().' &nbsp;meta '.$fmt->reais($t['vendaMeta']),
                                            'linha2' => $barraMeta($t['vendaPct']),
                                            'acento' => '#005A6F',
                                        ])
                                    </td>
                                    <td class="kpi-col" width="50%" valign="top" style="padding:0 6px 12px 6px;">
                                        @include('emails.resumo._kpi', [
                                            'rotulo' => 'Faturamento no mês',
                                            'valor' => $fmt->reais($t['fatRealizado']),
                                            'linha1' => view('emails.resumo._pilula', ['pct' => $t['fatPct'], 'fmt' => $fmt])->render().' &nbsp;meta '.$fmt->reais($t['fatMeta']),
                                            'linha2' => $barraMeta($t['fatPct']),
                                            'acento' => '#ff8f00',
                                        ])
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── Saúde da carteira ─────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="padding:6px 32px 26px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb; border:1px solid #e4e7ec; border-radius:6px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 2px 0; font-size:11px; line-height:14px; letter-spacing:1.2px; text-transform:uppercase; color:#667085; font-weight:bold;">Saúde da carteira</p>
                                        <p style="margin:0 0 12px 0; font-size:13px; line-height:18px; color:#475467;">{{ $fmt->int($t['carteira']['total']) }} clientes</p>
                                        @include('emails.resumo._barra-carteira', ['c' => $t['carteira'], 'altura' => 12, 'fmt' => $fmt])
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
                                            <tr>
                                                @foreach ([['Ativos', '#047857', '#10b981', 'pctAtivos', 'ativos'], ['Perdendo', '#b45309', '#f59e0b', 'pctInativando', 'inativando'], ['A trabalhar', '#b91c1c', '#ef4444', 'pctInativos', 'inativos']] as [$rot, $corTxt, $corPonto, $campoPct, $campoN])
                                                    <td class="leg-col" width="33%" valign="top">
                                                        <p style="margin:0; font-size:22px; line-height:26px; font-weight:bold; color:{{ $corTxt }};">{{ $fmt->pct($t['carteira'][$campoPct]) }}</p>
                                                        <p style="margin:2px 0 0 0; font-size:12px; line-height:16px; color:#475467;">
                                                            <span style="display:inline-block; width:8px; height:8px; border-radius:4px; background-color:{{ $corPonto }};"></span>
                                                            {{ $rot }} · {{ $fmt->int($t['carteira'][$campoN]) }}
                                                        </p>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── Ranking das equipes (só consolidado) ─────────────────── --}}
                    @if ($consolidado && count($r['secoes']) > 0)
                        <tr>
                            <td class="pad" style="padding:4px 32px 26px 32px;">
                                <p style="{{ $secaoTitulo }}">Ranking das equipes</p>
                                <p style="{{ $secaoSub }}">Ordenado pelo % da meta de venda no mês.</p>
                                @php
                                    $th = 'padding:9px 8px; font-size:10px; line-height:13px; letter-spacing:0.8px; text-transform:uppercase; color:#667085; font-weight:bold; border-bottom:2px solid #e4e7ec;';
                                    $td = 'padding:11px 8px; font-size:12px; line-height:16px; color:#344054; border-bottom:1px solid #eef0f3; vertical-align:middle;';
                                @endphp
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <th align="left" style="{{ $th }}">Equipe</th>
                                        <th align="center" style="{{ $th }}">Contatos<br>hoje · mês</th>
                                        <th align="center" style="{{ $th }}">Pedidos</th>
                                        <th align="right" style="{{ $th }}">Venda</th>
                                        <th align="right" style="{{ $th }}">Faturamento</th>
                                        <th align="left" style="{{ $th }} width:104px;">Carteira</th>
                                    </tr>
                                    @foreach ($r['secoes'] as $i => $s)
                                        @php $st = $s['totais']; $zebra = $i % 2 === 1 ? 'background-color:#f9fafb;' : ''; @endphp
                                        <tr>
                                            <td align="left" style="{{ $td }} {{ $zebra }}">
                                                <span style="display:inline-block; width:20px; height:20px; border-radius:10px; background-color:{{ $i === 0 ? '#0F3A69' : '#eaecf0' }}; color:{{ $i === 0 ? '#ffffff' : '#475467' }}; font-size:11px; line-height:20px; text-align:center; font-weight:bold;">{{ $i + 1 }}</span>
                                                &nbsp;<strong style="color:#101828;">{{ $s['nome'] }}</strong>
                                                <span style="display:block; padding-left:26px; font-size:10px; line-height:13px; color:#98a2b3;">{{ $st['vendedores'] }} pessoas</span>
                                            </td>
                                            <td align="center" style="{{ $td }} {{ $zebra }} white-space:nowrap;"><strong style="color:#101828;">{{ $fmt->int($st['contatosHoje']['total']) }}</strong> <span style="color:#98a2b3;">·</span> {{ $fmt->int($st['contatosMes']['total']) }}</td>
                                            <td align="center" style="{{ $td }} {{ $zebra }}"><strong style="color:#101828;">{{ $fmt->int($st['pedidos']) }}</strong></td>
                                            <td align="right" style="{{ $td }} {{ $zebra }} white-space:nowrap;">{{ $fmt->reais($st['vendaRealizado']) }}<br>@include('emails.resumo._pilula', ['pct' => $st['vendaPct'], 'fmt' => $fmt])</td>
                                            <td align="right" style="{{ $td }} {{ $zebra }} white-space:nowrap;">{{ $fmt->reais($st['fatRealizado']) }}<br>@include('emails.resumo._pilula', ['pct' => $st['fatPct'], 'fmt' => $fmt])</td>
                                            <td align="left" style="{{ $td }} {{ $zebra }}">
                                                @include('emails.resumo._barra-carteira', ['c' => $st['carteira'], 'altura' => 6, 'fmt' => $fmt])
                                                <span style="display:block; margin-top:4px; font-size:10px; line-height:13px; color:#98a2b3;">{{ $fmt->pct($st['carteira']['pctAtivos']) }} ativos</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ── Por vendedor ──────────────────────────────────────────── --}}
                    @foreach ($r['secoes'] as $s)
                        <tr>
                            <td class="pad" style="padding:4px 32px 26px 32px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:12px;">
                                    <tr>
                                        <td style="border-left:4px solid #00A9CE; padding:2px 0 2px 12px;">
                                            <p style="margin:0; font-size:17px; line-height:22px; color:#0F3A69; font-weight:bold;">{{ $consolidado ? 'Equipe '.$s['nome'] : 'Por vendedor' }}</p>
                                            <p style="margin:2px 0 0 0; font-size:12px; line-height:16px; color:#667085;">{{ $s['totais']['vendedores'] }} pessoas · ordenado pelo % da meta de venda · contatos em <strong style="color:#d92d20;">vermelho</strong> = nenhum hoje</p>
                                        </td>
                                    </tr>
                                </table>
                                @include('emails.resumo._tabela-vendedores', ['linhas' => $s['linhas'], 'fmt' => $fmt])
                            </td>
                        </tr>
                    @endforeach

                    {{-- ── Chamada ───────────────────────────────────────────────── --}}
                    <tr>
                        <td align="center" class="pad" style="padding:8px 32px 32px 32px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="background-color:#005A6F; border-radius:6px;">
                                        <a href="{{ $urlCrm }}" target="_blank" style="display:inline-block; padding:13px 30px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">Abrir no CRM &rarr;</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                {{-- ── Rodapé ────────────────────────────────────────────────────── --}}
                <table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:680px;">
                    <tr>
                        <td align="center" style="padding:18px 24px;">
                            <p style="margin:0 0 4px 0; font-size:12px; line-height:18px; color:#667085;">
                                <strong style="color:#475467;">PALMA CRM · Autopel Soluções</strong>
                            </p>
                            <p style="margin:0; font-size:11px; line-height:17px; color:#98a2b3;">
                                Enviado automaticamente em {{ $geradoEm->format('d/m/Y \à\s H:i') }}. Os números são os mesmos do Painel e de Metas.<br>
                                Carteira conta clientes pela compra mais recente: ativo até 290 dias, perdendo até 365, a trabalhar acima disso.
                            </p>
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
