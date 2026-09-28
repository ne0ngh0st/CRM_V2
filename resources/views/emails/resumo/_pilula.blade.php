{{-- Pílula de % da meta. Parâmetros: $pct (float|null), $fmt. --}}
@php [$fundo, $texto, $borda] = $fmt->tom($pct); @endphp
<span style="display:inline-block; padding:2px 8px; border-radius:10px; background-color:{{ $fundo }}; border:1px solid {{ $borda }}; color:{{ $texto }}; font-size:11px; line-height:14px; font-weight:bold; white-space:nowrap;">{{ $pct === null ? 'sem meta' : $fmt->pct($pct).' da meta' }}</span>
