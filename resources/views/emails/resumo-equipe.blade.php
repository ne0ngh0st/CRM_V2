{{--
    Resumo diário da equipe — e-mail das 18:00 para os gestores.

    Leitura em três camadas, nesta ordem (validado com o Leandro em 2026-09-28):
      1. o dia em UMA linha: contatos · pedidos · venda · faturamento
      2. a mesma linha por vendedor (no consolidado, agrupada por equipe)
      3. o "resumão" no rodapé: o mês contra a meta — só total
         (ano e carteira saíram em 2026-09-28: não mudam de um dia para o outro)
    Menos é mais: sem cor que não informe nada. Zero sai como "0" em VERMELHO (pedido do
    Tony, 2026-10-01: o traço parecia dado faltando, e zero é o que o gestor tem que cobrar).
    A tabela por vendedor tem grade (borda em toda célula): sem ela, com valores alinhados à
    direita, o olho não sabia a que coluna cada número pertencia.

    ⚠️ Regras de HTML de e-mail (as mesmas de redefinir-senha.blade.php) — NÃO "modernizar":
    - Layout por <table>; flex/grid não existem no Outlook (motor do Word).
    - Estilo inline; o <style> do <head> é só progressivo (empilhar no celular) e o
      e-mail tem que continuar legível se o cliente o descartar.
    - Logo por $message->embed() (cid:). NUNCA data: URI no envio real — Gmail e Outlook
      descartam. O data: URI abaixo só existe na PRÉVIA do comando, onde não há $message.
--}}
@php
    $t = $r['totais'];
    $rs = $r['resumao'];
    $geradoEm = \Illuminate\Support\Carbon::parse($r['periodo']['geradoEm']);
    $dia = \Illuminate\Support\Carbon::parse($r['periodo']['dia']);
    $vendaAte = \Illuminate\Support\Carbon::parse($r['periodo']['vendaAte']);
    $consolidado = $r['tipo'] === 'consolidado';

    // Dia da semana SEMPRE escrito: "ontem" sozinho não diz nada numa segunda (é sexta).
    $rotuloDia = $fmt->diaCurto($dia);
    $rotuloHoje = 'hoje, '.$fmt->diaCurto($geradoEm);
    $nomeBonito = fn (string $n) => mb_convert_case(mb_strtolower($n), MB_CASE_TITLE);
    // Zero vira "0" vermelho: é o que o gestor tem que cobrar.
    // $corZero muda na faixa navy da equipe, onde o vermelho escuro some no fundo.
    $vermelho = '#d92d20';
    $num = fn ($v, string $tipo, string $corZero = '#d92d20') => (float) $v == 0.0
        ? '<span style="color:'.$corZero.';">0</span>'
        : ($tipo === 'reais' ? $fmt->reaisCheio($v) : $fmt->int($v));

    $cinza = '#667085';
    $tinta = '#101828';
    // Grade: toda célula tem borda própria (border-collapse no <table>). Outlook respeita
    // borda em <td>; não trocar por box-shadow/outline, que ele ignora.
    $grade = '#d0d5dd';
    $th = "padding:8px 10px; font-size:11px; line-height:14px; color:#344054; font-weight:bold; background-color:#eef2f6; border:1px solid {$grade};";
    $td = "padding:8px 10px; font-size:13px; line-height:18px; color:#344054; border:1px solid {$grade};";
    $dataTh = 'display:block; margin-top:2px; font-size:10px; line-height:12px; color:#005A6F; font-weight:bold; white-space:nowrap;';
    $metaTxt = function ($pct) use ($fmt) {
        [, $cor] = $fmt->tom($pct);

        return $pct === null
            ? '<span style="color:#98a2b3;">sem meta</span>'
            : '<span style="color:'.$cor.'; font-weight:bold;">'.$fmt->pct($pct).'</span> <span style="color:#98a2b3;">da meta</span>';
    };
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
            .pad { padding-left:20px !important; padding-right:20px !important; }
            .kpi { display:inline-block !important; width:50% !important; box-sizing:border-box; border-left:0 !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f2f4f7; font-family:'Segoe UI', Roboto, Arial, Helvetica, sans-serif; -webkit-font-smoothing:antialiased;">

    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ $fmt->int($t['contatos']) }} contatos hoje · {{ $fmt->int($t['pedidos']) }} pedidos e {{ $fmt->reais($t['venda']) }} de venda em {{ $rotuloDia }}.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f2f4f7;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:640px; background-color:#ffffff; border:1px solid #e4e7ec; border-radius:8px; overflow:hidden;">

                    {{-- ── Cabeçalho ─────────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="background-color:#0F3A69; padding:18px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        @if ($logo && isset($message))
                                            <img src="{{ $message->embed($logo) }}" alt="PALMA" width="110" style="display:block; width:110px; height:auto; border:0;">
                                        @elseif ($logo)
                                            <img src="data:image/png;base64,{{ base64_encode(file_get_contents($logo)) }}" alt="PALMA" width="110" style="display:block; width:110px; height:auto; border:0;">
                                        @else
                                            <span style="color:#ffffff; font-size:16px; font-weight:bold; letter-spacing:1px;">PALMA</span>
                                        @endif
                                    </td>
                                    <td align="right" valign="middle" style="font-size:13px; line-height:18px; color:#c9dcec;">
                                        {{ ucfirst($geradoEm->translatedFormat('l, d/m')) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── Título ────────────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="padding:28px 32px 4px 32px;">
                            <p style="margin:0; font-size:12px; line-height:16px; color:{{ $cinza }};">Resumo do dia</p>
                            <h1 style="margin:2px 0 0 0; font-size:22px; line-height:28px; color:{{ $tinta }}; font-weight:bold;">{{ $r['titulo'] }}</h1>
                            <p style="margin:4px 0 0 0; font-size:13px; line-height:18px; color:{{ $cinza }};">
                                @if ($consolidado)
                                    Empresa inteira — os mesmos totais do Power BI, abertos por equipe.
                                @else
                                    {{ $fmt->int($r['vendedores']) }} {{ $r['vendedores'] === 1 ? 'pessoa' : 'pessoas' }}
                                @endif
                            </p>
                        </td>
                    </tr>

                    {{-- ── O dia, em uma linha ───────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="padding:24px 32px 8px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ec; border-radius:6px;">
                                <tr>
                                    @foreach ([
                                        ['Contatos', $fmt->int($t['contatos']), $rotuloHoje, $t['contatos']],
                                        ['Pedidos', $fmt->int($t['pedidos']), $rotuloDia, $t['pedidos']],
                                        ['Venda', $fmt->reais($t['venda']), $rotuloDia, $t['venda']],
                                        ['Faturamento', $fmt->reais($t['faturamento']), $rotuloDia, $t['faturamento']],
                                    ] as $i => [$rotulo, $valor, $quando, $bruto])
                                        @php $zerado = (float) $bruto == 0.0; @endphp
                                        <td class="kpi" width="25%" valign="top" style="padding:14px 16px; {{ $i === 0 ? '' : 'border-left:1px solid #e4e7ec;' }}">
                                            <p style="margin:0; font-size:12px; line-height:16px; color:{{ $cinza }};">{{ $rotulo }}</p>
                                            <p style="margin:4px 0 0 0; font-size:24px; line-height:30px; color:{{ $zerado ? $vermelho : $tinta }}; font-weight:bold; white-space:nowrap;">{{ $valor }}</p>
                                            <p style="margin:4px 0 0 0; font-size:11px; line-height:14px; color:#005A6F; white-space:nowrap;">{{ ucfirst($quando) }}</p>
                                        </td>
                                    @endforeach
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ── Por vendedor ──────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="padding:28px 32px 8px 32px;">
                            <p style="margin:0 0 12px 0; font-size:15px; line-height:20px; color:{{ $tinta }}; font-weight:bold;">Por vendedor</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; border:1px solid {{ $grade }};">
                                {{-- De que dia é cada coluna — contatos são de hoje, o resto do último dia fechado. --}}
                                <tr>
                                    <th align="left" valign="bottom" style="{{ $th }}">Vendedor</th>
                                    <th align="right" valign="bottom" style="{{ $th }} width:72px;">Contatos<span style="{{ $dataTh }}">{{ ucfirst($fmt->diaCurto($geradoEm)) }}</span></th>
                                    <th align="right" valign="bottom" style="{{ $th }} width:68px;">Pedidos<span style="{{ $dataTh }}">{{ ucfirst($rotuloDia) }}</span></th>
                                    <th align="right" valign="bottom" style="{{ $th }} width:100px;">Venda<span style="{{ $dataTh }}">{{ ucfirst($rotuloDia) }}</span></th>
                                    <th align="right" valign="bottom" style="{{ $th }} width:108px;">Faturamento<span style="{{ $dataTh }}">{{ ucfirst($rotuloDia) }}</span></th>
                                </tr>
                                @foreach ($r['secoes'] as $s)
                                    @if ($consolidado)
                                        @php
                                            $st = $s['totais'];
                                            // Faixa navy cheia: é o divisor entre equipes. Em cima/embaixo a borda some
                                            // no fundo; nas laterais um azul mais claro mantém as colunas separadas.
                                            $g = 'padding:10px 10px; font-size:14px; line-height:18px; color:#ffffff; font-weight:bold; background-color:#0F3A69; border-top:1px solid #0F3A69; border-bottom:1px solid #0F3A69; border-left:1px solid #5a7fa8; border-right:1px solid #5a7fa8; white-space:nowrap;';
                                        @endphp
                                        <tr>
                                            <td align="left" style="{{ $g }}">
                                                {{ $s['nome'] }}
                                                <span style="display:block; margin-top:1px; font-size:11px; line-height:14px; font-weight:normal; color:#a9c4de;">{{ $st['vendedores'] }} {{ $st['vendedores'] === 1 ? 'pessoa' : 'pessoas' }} · total da equipe</span>
                                            </td>
                                            <td align="right" style="{{ $g }}">{!! $num($st['contatos'], 'int', '#ff9c94') !!}</td>
                                            <td align="right" style="{{ $g }}">{!! $num($st['pedidos'], 'int', '#ff9c94') !!}</td>
                                            <td align="right" style="{{ $g }}">{!! $num($st['venda'], 'reais', '#ff9c94') !!}</td>
                                            <td align="right" style="{{ $g }}">{!! $num($st['faturamento'], 'reais', '#ff9c94') !!}</td>
                                        </tr>
                                    @endif
                                    @foreach ($s['linhas'] as $l)
                                        {{-- Zebra: ajuda a seguir a linha da esquerda até o valor da direita. --}}
                                        <tr style="{{ $loop->even ? 'background-color:#f8f9fb;' : '' }}">
                                            <td align="left" style="{{ $td }} {{ $consolidado ? 'padding-left:20px;' : '' }}">{{ $nomeBonito($l['nome']) }}</td>
                                            <td align="right" style="{{ $td }}">{!! $num($l['contatos'], 'int') !!}</td>
                                            <td align="right" style="{{ $td }}">{!! $num($l['pedidos'], 'int') !!}</td>
                                            <td align="right" style="{{ $td }} white-space:nowrap;">{!! $num($l['venda'], 'reais') !!}</td>
                                            <td align="right" style="{{ $td }} white-space:nowrap;">{!! $num($l['faturamento'], 'reais') !!}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </table>
                            <p style="margin:10px 0 0 0; font-size:11px; line-height:16px; color:#98a2b3;">
                                Contatos de hoje ({{ $fmt->diaCurto($geradoEm) }}) até {{ $geradoEm->format('H:i') }}. Pedidos, venda e faturamento de {{ $rotuloDia }}, o último dia já fechado no TOTVS — o de hoje ainda não entrou.
                            </p>
                        </td>
                    </tr>

                    {{-- ── Resumão ───────────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="padding:24px 32px 28px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f9fafb; border:1px solid #eaecf0; border-radius:6px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0; font-size:15px; line-height:20px; color:{{ $tinta }}; font-weight:bold;">Resumão do mês</p>
                                        <p style="margin:2px 0 14px 0; font-size:12px; line-height:16px; color:{{ $cinza }};">
                                            {{ ucfirst($vendaAte->translatedFormat('F')) }}, de 01/{{ $vendaAte->format('m') }} até {{ $fmt->diaCurto($vendaAte) }}
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                @foreach (['venda' => 'Venda', 'faturamento' => 'Faturamento'] as $tipo => $rotulo)
                                                    @php $p = $rs['mes'][$tipo]; @endphp
                                                    <td width="50%" valign="top" style="{{ $tipo === 'faturamento' ? 'padding-left:16px; border-left:1px solid #eaecf0;' : '' }}">
                                                        <p style="margin:0; font-size:12px; line-height:16px; color:{{ $cinza }};">{{ $rotulo }}</p>
                                                        <p style="margin:4px 0 0 0; font-size:20px; line-height:26px; color:{{ $tinta }}; font-weight:bold; white-space:nowrap;">{{ $fmt->reais($p['realizado']) }}</p>
                                                        <p style="margin:2px 0 0 0; font-size:12px; line-height:16px;">
                                                            {!! $metaTxt($p['pct']) !!}@if ($p['meta'] > 0) <span style="color:#98a2b3;">· meta {{ $fmt->reais($p['meta']) }}</span>@endif
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

                    {{-- ── Link ──────────────────────────────────────────────────── --}}
                    <tr>
                        <td class="pad" style="padding:0 32px 28px 32px;">
                            <a href="{{ $urlCrm }}" target="_blank" style="font-size:13px; font-weight:bold; color:#005A6F; text-decoration:none;">Abrir no CRM &rarr;</a>
                        </td>
                    </tr>
                </table>

                {{-- ── Rodapé ────────────────────────────────────────────────────── --}}
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:640px;">
                    <tr>
                        <td align="center" style="padding:16px 24px; font-size:11px; line-height:17px; color:#98a2b3;">
                            PALMA CRM · Autopel · enviado em {{ $geradoEm->format('d/m/Y \à\s H:i') }}. Os números são os mesmos do Painel e de Metas.
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
