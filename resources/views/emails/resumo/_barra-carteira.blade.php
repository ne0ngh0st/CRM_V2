{{--
    Barra empilhada Ativos / Perdendo / A trabalhar.
    Parâmetros: $c (contagem com pct*), $altura (px), $fmt.

    ⚠️ Células de <table> com width em %, e não <div> com flex: é a única barra que o
    Outlook (motor do Word) desenha. Fatia de 0% não vira célula — uma célula de largura
    zero ainda ocupa 1px e deixa um fiapo de cor onde não há nada.
--}}
@php
    $fatias = array_filter([
        ['#10b981', $c['pctAtivos']],
        ['#f59e0b', $c['pctInativando']],
        ['#ef4444', $c['pctInativos']],
    ], fn ($f) => $f[1] > 0);
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:3px; overflow:hidden;">
    <tr>
        @forelse ($fatias as [$cor, $pct])
            <td width="{{ $pct }}%" style="background-color:{{ $cor }}; height:{{ $altura }}px; line-height:{{ $altura }}px; font-size:0;">&nbsp;</td>
        @empty
            <td style="background-color:#e4e4e7; height:{{ $altura }}px; line-height:{{ $altura }}px; font-size:0;">&nbsp;</td>
        @endforelse
    </tr>
</table>
