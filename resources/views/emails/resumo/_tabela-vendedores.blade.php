{{--
    Uma linha por vendedor. Parâmetros: $linhas, $fmt.
    Ordenada no builder (melhor % de venda primeiro).
--}}
@php
    $th = 'padding:9px 8px; font-size:10px; line-height:13px; letter-spacing:0.8px; text-transform:uppercase; color:#667085; font-weight:bold; border-bottom:2px solid #e4e7ec;';
    $td = 'padding:10px 8px; font-size:12px; line-height:16px; color:#344054; border-bottom:1px solid #eef0f3; vertical-align:middle;';
    $sub = 'display:block; font-size:10px; line-height:13px; color:#98a2b3; font-weight:normal;';
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <th align="left" style="{{ $th }}">Vendedor</th>
        <th align="center" style="{{ $th }}">Contatos<br>hoje · mês</th>
        <th align="center" style="{{ $th }}">Pedidos</th>
        <th align="right" style="{{ $th }}">Venda</th>
        <th align="right" style="{{ $th }}">Faturamento</th>
        <th align="left" style="{{ $th }} width:104px;">Carteira</th>
    </tr>
    @foreach ($linhas as $i => $l)
        @php $zebra = $i % 2 === 1 ? 'background-color:#f9fafb;' : ''; @endphp
        <tr>
            <td align="left" style="{{ $td }} {{ $zebra }}">
                <strong style="color:#101828;">{{ $l['nome'] }}</strong>
                <span style="{{ $sub }}">{{ $l['codVendedor'] }}</span>
            </td>
            <td align="center" style="{{ $td }} {{ $zebra }} white-space:nowrap;">
                <strong style="color:{{ $l['contatosHoje']['total'] > 0 ? '#101828' : '#d92d20' }};">{{ $fmt->int($l['contatosHoje']['total']) }}</strong>
                <span style="color:#98a2b3;">·</span> {{ $fmt->int($l['contatosMes']['total']) }}
                <span style="{{ $sub }}">☎ {{ $l['contatosMes']['porCanal']['telefonica'] }} · WA {{ $l['contatosMes']['porCanal']['whatsapp'] }} · ✉ {{ $l['contatosMes']['porCanal']['email'] }}</span>
            </td>
            <td align="center" style="{{ $td }} {{ $zebra }}"><strong style="color:#101828;">{{ $fmt->int($l['pedidos']) }}</strong></td>
            <td align="right" style="{{ $td }} {{ $zebra }} white-space:nowrap;">
                {{ $fmt->reais($l['vendaRealizado']) }}
                @php [, $cor] = $fmt->tom($l['vendaPct']); @endphp
                <span style="{{ $sub }} color:{{ $cor }}; font-weight:bold;">{{ $l['vendaPct'] === null ? 'sem meta' : $fmt->pct($l['vendaPct']) }}</span>
            </td>
            <td align="right" style="{{ $td }} {{ $zebra }} white-space:nowrap;">
                {{ $fmt->reais($l['fatRealizado']) }}
                @php [, $cor] = $fmt->tom($l['fatPct']); @endphp
                <span style="{{ $sub }} color:{{ $cor }}; font-weight:bold;">{{ $l['fatPct'] === null ? 'sem meta' : $fmt->pct($l['fatPct']) }}</span>
            </td>
            <td align="left" style="{{ $td }} {{ $zebra }}">
                @include('emails.resumo._barra-carteira', ['c' => $l['carteira'], 'altura' => 6, 'fmt' => $fmt])
                <span style="{{ $sub }} margin-top:4px;">{{ $fmt->pct($l['carteira']['pctAtivos']) }} ativos · {{ $fmt->int($l['carteira']['total']) }} cli.</span>
            </td>
        </tr>
    @endforeach
</table>
