{{--
    Réplica do PDF de solicitação de etiqueta do legado
    (includes/pdf/solicitacao_etiqueta_pdf.php + solicitacao_pdf_layout.php).
    Mesmo template visual do PDF de bobina (bobina-pdf.blade.php) — Regra de ouro
    nº 8, um estilo só pros dois. Só a seção "Saída de rolo" (com imagem F1-F4) é
    exclusiva daqui.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Solicitação de Cadastro de Etiqueta #{{ $etiqueta->id }}</title>
    <style>
        @page { size: A4; margin: 41mm 12mm 18mm 12mm; }

        /* Higiene: o dompdf não tem regra padrão pra elementos HTML5. */
        header, footer, main, section, article { display: block; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            color: #1E293B;
            margin: 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        /* --- Header (FPDF Header(): banda inset, faixa verde ABAIXO, não à esquerda) --- */
        header {
            position: fixed;
            top: -31mm;
            left: 0;
            right: 0;
        }
        .header-banda {
            background: #101521;
            height: 24mm;
        }
        .header-banda td { vertical-align: middle; padding: 0; }
        .header-logo { width: 33mm; padding: 0 0 0 5mm; }
        .header-logo img { width: 28mm; height: auto; }
        .header-texto { padding: 2.5mm 3mm 0 0; }
        .header-selo-td { width: 38mm; text-align: right; padding: 0 5mm 0 0; }
        .empresa { font-size: 14pt; font-weight: bold; color: #fff; line-height: 1.15; }
        .subtitulo { font-size: 9.5pt; color: #CBD5E1; margin-top: 0.4mm; }
        .protocolo { font-size: 8pt; color: #94A3B8; margin-top: 0.4mm; }
        .selo {
            display: inline-block;
            padding: 1.4mm 3mm;
            font-size: 7.5pt;
            font-weight: bold;
            color: #fff;
            background: {{ $statusCor }};
            text-transform: uppercase;
        }
        .faixa-verde { height: 1.4mm; background: #2E7D32; }

        footer {
            position: fixed;
            bottom: -14mm;
            left: 0;
            right: 0;
            height: 11mm;
            font-size: 7.5pt;
            color: #64748B;
            border-top: 0.2mm solid #E2E8F0;
            padding-top: 1.5mm;
        }
        /* ⚠️ NÃO usar float aqui. `float` dentro de `position: fixed` faz o
           dompdf perder a conta da altura da página (11 páginas em branco). */
        footer td { vertical-align: middle; }
        footer td.dir { text-align: right; }
        /* Numeração real via CSS paged media — diferente da bobina, a etiqueta
           tem uma seção a mais (saída de rolo) e pode legitimamente virar 2
           páginas quando também tem observações longas. Nunca hardcodear.
           ⚠️ `counter(pages)` (total) NÃO é confiável neste dompdf (voltou "de 0"
           num teste real) — só `counter(page)` (página atual). Por isso só isso. */
        .pagenum::after { content: counter(page); }

        /* Título em destaque: caixa verde-clara com borda e barra à esquerda. */
        .titulo-destaque {
            background: #F0F9F1;
            border: 0.2mm solid #BBDEBF;
            border-left: 1.8mm solid #2E7D32;
            color: #16521A;
            font-size: 12pt;
            font-weight: bold;
            padding: 2.6mm 5mm;
            margin-bottom: 5mm;
        }

        .secao-barra {
            background: #F1F5F9;
            border: 0.2mm solid #E2E8F0;
            border-left: 1.6mm solid #1E293B;
            color: #1E293B;
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            padding: 1.6mm 4mm;
            margin-bottom: 1.5mm;
        }

        .colunas td.esquerda { width: 50%; padding-right: 2.5mm; }
        .colunas td.direita { width: 50%; padding-left: 2.5mm; }

        .cartao {
            border: 0.2mm solid #E2E8F0;
            padding: 1.8mm 3mm 2mm 3mm;
            min-height: 8.5mm;
        }
        .cartao .rotulo {
            display: block;
            font-size: 7pt;
            color: #64748B;
            line-height: 1.2;
        }
        .cartao .valor {
            display: block;
            font-size: 9pt;
            font-weight: bold;
            color: #1E293B;
            line-height: 1.3;
            margin-top: 0.4mm;
        }

        .grade { margin-top: 0; }
        .grade td { width: 33.33%; padding: 0 2mm 2mm 0; }
        .grade td:last-child { padding-right: 0; }

        .envio-grade td { width: 50%; padding: 0 2mm 0 0; }
        .envio-grade td:last-child { padding-right: 0; }

        .saida-rolo-cartao { text-align: center; padding: 1.5mm 3mm; }
        .saida-rolo-cartao img { max-height: 13mm; margin-bottom: 1mm; }
        .saida-rolo-cartao .valor { display: block; }

        .bloco-texto {
            background: #F9FAFB;
            border: 0.2mm solid #E2E8F0;
            padding: 2.5mm 4mm;
            font-size: 9pt;
            color: #374151;
            white-space: pre-wrap;
        }

        .secao { margin-bottom: 5mm; }
    </style>
</head>
<body>
    <header>
        <table class="header-banda">
            <tr>
                <td class="header-logo">
                    @if ($logoPath)
                        <img src="{{ $logoPath }}" alt="Autopel" />
                    @endif
                </td>
                <td class="header-texto">
                    <div class="empresa">Autopel Soluções</div>
                    <div class="subtitulo">Solicitação de Cadastro de Etiqueta</div>
                    <div class="protocolo">Protocolo #{{ $etiqueta->id }}&nbsp;&nbsp;|&nbsp;&nbsp;Emitido em {{ $emitidoEm }}</div>
                </td>
                <td class="header-selo-td">
                    <span class="selo">{{ $statusRotulo }}</span>
                </td>
            </tr>
        </table>
        <div class="faixa-verde"></div>
    </header>

    <footer>
        <table>
            <tr>
                <td>Autopel Soluções&nbsp;&nbsp;|&nbsp;&nbsp;Solicitação de Cadastro de Etiqueta</td>
                <td class="dir">Página <span class="pagenum"></span></td>
            </tr>
        </table>
    </footer>

    <main>
        <div class="titulo-destaque">{{ $tituloDestaque }}</div>

        <table class="colunas secao">
            <tr>
                <td class="esquerda"><div class="secao-barra">Resumo da solicitação</div></td>
                <td class="direita"><div class="secao-barra">Informações comerciais</div></td>
            </tr>
            @foreach (array_map(null, array_keys($resumo), array_values($resumo), array_keys($comerciais), array_values($comerciais)) as [$rotEsq, $valEsq, $rotDir, $valDir])
                <tr>
                    <td class="esquerda">
                        @if ($rotEsq)
                            <div class="cartao">
                                <span class="rotulo">{{ $rotEsq }}</span>
                                <span class="valor">{{ $valEsq }}</span>
                            </div>
                        @endif
                    </td>
                    <td class="direita">
                        @if ($rotDir)
                            <div class="cartao">
                                <span class="rotulo">{{ $rotDir }}</span>
                                <span class="valor">{{ $valDir }}</span>
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>

        <div class="secao">
            <div class="secao-barra">Características técnicas</div>
            <table class="grade">
                @foreach (collect($tecnicas)->chunk(3) as $linha)
                    <tr>
                        @foreach ($linha as $rotulo => $valor)
                            <td>
                                <div class="cartao">
                                    <span class="rotulo">{{ $rotulo }}</span>
                                    <span class="valor">{{ $valor }}</span>
                                </div>
                            </td>
                        @endforeach
                        @for ($i = $linha->count(); $i < 3; $i++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        </div>

        @if ($saidaRolo)
            <div class="secao">
                <div class="secao-barra">Saída de rolo</div>
                <table class="envio-grade">
                    <tr>
                        <td style="width: 40mm;">
                            <div class="cartao saida-rolo-cartao">
                                <img src="{{ $saidaRolo['imagemPath'] }}" alt="{{ $saidaRolo['rotulo'] }}" />
                                <span class="valor">{{ $saidaRolo['rotulo'] }}</span>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        @endif

        <div class="secao">
            <div class="secao-barra">Envio</div>
            <table class="envio-grade">
                <tr>
                    @foreach ($envio as $rotulo => $valor)
                        <td>
                            <div class="cartao">
                                <span class="rotulo">{{ $rotulo }}</span>
                                <span class="valor">{{ $valor }}</span>
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        </div>

        @if ($observacoes !== '')
            <div class="secao">
                <div class="secao-barra">Observações</div>
                <div class="bloco-texto">{{ $observacoes }}</div>
            </div>
        @endif
    </main>
</body>
</html>
