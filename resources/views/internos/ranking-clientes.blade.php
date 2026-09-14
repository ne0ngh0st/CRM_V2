{{--
    Ranking pontual de clientes por faturamento — documento interno.
    Gerado sob demanda (scripts/gerar-ranking-clientes-pdf.php), não faz parte do CRM.
    Restrições do dompdf: layout em tabela, sem flex/grid/float em position:fixed.
--}}
@php
    $fontes = public_path('fonts/inter');
    $logo = public_path('images/autopel-logo.png');
    $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    $data = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Ranking de clientes por faturamento — Autopel Soluções</title>
    <style>
        @font-face { font-family: 'Inter'; font-weight: 400; font-style: normal; src: url('{{ $fontes }}/Inter-Regular.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 500; font-style: normal; src: url('{{ $fontes }}/Inter-Medium.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 600; font-style: normal; src: url('{{ $fontes }}/Inter-SemiBold.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 700; font-style: normal; src: url('{{ $fontes }}/Inter-Bold.ttf') format('truetype'); }

        @page { size: A4 landscape; margin: 12mm 12mm 16mm 12mm; }

        header, footer, main, section { display: block; }

        body { font-family: 'Inter', sans-serif; font-size: 8.5pt; color: #111827; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: middle; }
        p { margin: 0; }

        .marca-nome { font-size: 12pt; font-weight: 700; color: #0F3A69; }
        .marca-doc { font-size: 8pt; color: #6B7280; padding-top: 2px; }

        .barra-titulo { background: #0F3A69; }
        .barra-titulo td { padding: 8px 12px; }
        .barra-titulo .rotulo { color: #FFFFFF; font-size: 12pt; font-weight: 700; letter-spacing: 1.2px; }
        .barra-titulo .numero { color: #FFFFFF; font-size: 9pt; font-weight: 500; text-align: right; }
        .barra-regua { height: 2.5px; background: #00A9CE; }

        .criterio {
            margin-top: 8px;
            border: 1px solid #E5E7EB;
            background: #F8FAFC;
        }
        .criterio td { padding: 7px 10px; font-size: 8pt; color: #374151; line-height: 1.35; }
        .criterio strong { color: #0F3A69; }

        .secao { margin-top: 12px; }
        .secao-nova-pagina { page-break-before: always; }
        .legenda {
            background: #F1F5F9;
            border-left: 3px solid #005A6F;
            padding: 5px 10px;
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #0F3A69;
        }
        .legenda.inativo { border-left-color: #B45309; }

        .tbl { margin-top: 0; }
        .tbl th {
            background: #F1F5F9;
            border: 1px solid #D1D5DB;
            padding: 4px 6px;
            font-size: 7pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #4B5563;
            text-align: center;
        }
        .tbl td {
            border: 1px solid #E5E7EB;
            padding: 4px 6px;
            font-size: 8pt;
            text-align: center;
        }
        .tbl .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .tbl .nome { text-align: left; }
        .tbl .main { font-weight: 600; color: #111827; }
        .tbl .sub { font-size: 7pt; color: #6B7280; }
        .tbl tr:nth-child(even) td { background: #FAFAFA; }
        .pos { font-weight: 700; color: #0F3A69; }

        .tbl tfoot td {
            border: 1px solid #E5E7EB;
            padding: 5px 10px;
            font-size: 8pt;
            color: #374151;
            background: #F8FAFC;
            text-align: left;
        }

        .nota { margin-top: 10px; font-size: 7.5pt; color: #6B7280; line-height: 1.4; }

        footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #6B7280;
        }
        footer td { padding: 0; vertical-align: middle; }
        footer td.dir { text-align: right; }
        .pagenum::after { content: counter(page); }
    </style>
</head>
<body>
    <header>
        <table>
            <tr>
                <td style="width: 42px; padding-right: 10px;">
                    @if (is_file($logo))
                        <img src="{{ $logo }}" width="38" alt="Autopel">
                    @endif
                </td>
                <td>
                    <p class="marca-nome">Autopel Soluções</p>
                    <p class="marca-doc">Documento interno · PALMA CRM · gerado em {{ $geradoEm }} · {{ $escopoRotulo }}</p>
                </td>
                <td style="text-align: right; color: #6B7280; font-size: 8pt;">
                    <p>Faturamento de {{ $periodoDe }} a {{ $periodoAte }}</p>
                    <p>{{ $empresas }} empresas · {{ $filiais }} filiais</p>
                </td>
            </tr>
        </table>
        <table class="barra-titulo" style="margin-top: 8px;">
            <tr>
                <td class="rotulo">RANKING DE CLIENTES POR FATURAMENTO</td>
                <td class="numero">{{ $escopoRotulo }}</td>
            </tr>
        </table>
        <div class="barra-regua"></div>
    </header>

    <table class="criterio">
        <tr>
            <td>
                <strong>Critério.</strong>
                {{ $criterioExtra }}
                Ordenado pelo faturamento líquido do período.
                <strong>Ativo:</strong> última nota até {{ $diasAtivo }} dias (a partir de {{ $limiteAtivo }}).
                <strong>Inativo:</strong> mais de {{ $diasInativo }} dias sem nota (antes de {{ $limiteInativo }}).
                Cada linha é uma empresa (código de cliente) da carteira.
            </td>
        </tr>
    </table>

    <section class="secao">
        <div class="legenda">Top 10 clientes ativos — do maior para o menor</div>
        <table class="tbl">
            <thead>
                <tr>
                    <th style="width: 28px;">#</th>
                    <th style="width: 52px;">Código</th>
                    <th>Cliente</th>
                    <th style="width: 118px;">CNPJ</th>
                    <th style="width: 28px;">UF</th>
                    <th style="width: 110px;">Segmento</th>
                    <th style="width: 48px;">Filiais</th>
                    <th style="width: 72px;">Última NF</th>
                    <th style="width: 92px;">Faturamento 2026</th>
                    <th style="width: 100px;">Faturamento histórico</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ativos as $c)
                    <tr>
                        <td class="pos">{{ $c->pos }}</td>
                        <td>{{ $c->cod_cliente }}</td>
                        <td class="nome">
                            <span class="main">{{ $c->razao_social }}</span>
                            @if ((int) $c->filiais <= 1 && $c->nome_fantasia && $c->nome_fantasia !== $c->razao_social && ! str_contains(mb_strtoupper($c->nome_fantasia), 'INATIVO'))
                                <br><span class="sub">{{ trim($c->nome_fantasia) }}</span>
                            @endif
                        </td>
                        <td>{{ $c->cnpj ?: '—' }}</td>
                        <td>{{ $c->estado ?: '—' }}</td>
                        <td>{{ $c->segmento ?: '—' }}</td>
                        <td>{{ number_format((int) $c->filiais, 0, ',', '.') }}</td>
                        <td>{{ $data($c->ultima_nf) }}</td>
                        <td class="num">{{ $brl($c->volume_2026) }}</td>
                        <td class="num"><strong>{{ $brl($c->volume) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="10">
                        Soma dos 10: <strong>{{ $brl($somaAtivos) }}</strong>
                        · {{ number_format($pctAtivos, 1, ',', '.') }}% {{ $pctBaseRotulo }}
                        ({{ $brl($volumeTotal) }}).
                    </td>
                </tr>
            </tfoot>
        </table>
    </section>

    <section class="secao secao-nova-pagina">
        <div class="legenda inativo">Top 10 clientes inativos — do maior para o menor</div>
        <table class="tbl">
            <thead>
                <tr>
                    <th style="width: 28px;">#</th>
                    <th style="width: 52px;">Código</th>
                    <th>Cliente</th>
                    <th style="width: 118px;">CNPJ</th>
                    <th style="width: 28px;">UF</th>
                    <th style="width: 110px;">Segmento</th>
                    <th style="width: 48px;">Filiais</th>
                    <th style="width: 72px;">Última NF</th>
                    <th style="width: 92px;">Faturamento 2026</th>
                    <th style="width: 100px;">Faturamento histórico</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($inativos as $c)
                    <tr>
                        <td class="pos">{{ $c->pos }}</td>
                        <td>{{ $c->cod_cliente }}</td>
                        <td class="nome">
                            <span class="main">{{ $c->razao_social }}</span>
                            @if ((int) $c->filiais <= 1 && $c->nome_fantasia && $c->nome_fantasia !== $c->razao_social && ! str_contains(mb_strtoupper($c->nome_fantasia), 'INATIVO'))
                                <br><span class="sub">{{ trim($c->nome_fantasia) }}</span>
                            @endif
                        </td>
                        <td>{{ $c->cnpj ?: '—' }}</td>
                        <td>{{ $c->estado ?: '—' }}</td>
                        <td>{{ $c->segmento ?: '—' }}</td>
                        <td>{{ number_format((int) $c->filiais, 0, ',', '.') }}</td>
                        <td>{{ $data($c->ultima_nf) }}</td>
                        <td class="num">{{ $brl($c->volume_2026) }}</td>
                        <td class="num"><strong>{{ $brl($c->volume) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="10">
                        Soma dos 10: <strong>{{ $brl($somaInativos) }}</strong>
                        · {{ number_format($pctInativos, 1, ',', '.') }}% {{ $pctBaseRotulo }}.
                        São os maiores volumes parados — prioridade de reativação.
                    </td>
                </tr>
            </tfoot>
        </table>
    </section>

    <p class="nota">
        Fonte: notas fiscais de {{ $periodoDe }} a {{ $periodoAte }} ({{ number_format($notas, 0, ',', '.') }} linhas).
        A faixa “inativando” ({{ $diasAtivo + 1 }} a {{ $diasInativo }} dias sem compra) ficou de fora das duas listas, de propósito.
        Código de cliente com várias filiais entra uma vez só, com o volume somado.
        @if ($observacoes)
            {{ $observacoes }}
        @endif
    </p>

    <footer>
        <table>
            <tr>
                <td>Autopel Soluções · uso interno · não enviar ao cliente</td>
                <td class="dir">Página <span class="pagenum"></span></td>
            </tr>
        </table>
    </footer>
</body>
</html>
