{{--
    Réplica do PDF de solicitação de bobina do legado
    (includes/pdf/solicitacao_bobina_pdf.php + solicitacao_pdf_layout.php).

    Decisão do Tony (2026-08-10): "o PDF deve ficar igualzinho o legado". Por isso as
    cores aqui são as do legado (navy #101521 + verde #2E7D32), NÃO a paleta oficial
    Autopel usada no PDF de orçamento — os dois documentos ficam propositalmente
    diferentes entre si. Se um dia unificar, é decisão de marca, não de código.

    Estrutura, na ordem do legado: header + selo de status → título em destaque →
    duas colunas → grade de características → grade de envio → bloco de observações.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Solicitação de Cadastro de Bobina #{{ $bobina->id }}</title>
    <style>
        @page { margin: 96px 32px 56px 32px; }

        /* Higiene: o dompdf não tem regra padrão pra elementos HTML5. Não foi isto que
           causou o bug das 11 páginas (ver rodapé abaixo), mas custa nada ser explícito. */
        header, footer, main, section, article { display: block; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            color: #374151;
            margin: 0;
        }

        /* --- Header fixo (o legado desenha em toda página no Header()) --- */
        header {
            position: fixed;
            top: -80px; left: 0; right: 0;
            height: 62px;
            background: #101521;
            color: #fff;
            padding: 10px 14px;
        }
        header .faixa {
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 5px;
            background: #2E7D32;
        }
        header .empresa { font-size: 14pt; font-weight: bold; line-height: 1.2; }
        header .subtitulo { font-size: 9.5pt; color: #CBD5E1; margin-top: 2px; }
        header .protocolo { font-size: 8pt; color: #94A3B8; margin-top: 2px; }
        header .selo {
            position: absolute;
            top: 14px; right: 14px;
            padding: 4px 10px;
            border-radius: 3px;
            font-size: 7.5pt;
            font-weight: bold;
            color: #fff;
            background: {{ $statusCor }};
            text-transform: uppercase;
        }

        footer {
            position: fixed;
            bottom: -40px; left: 0; right: 0;
            height: 20px;
            font-size: 7.5pt;
            color: #64748B;
            border-top: 1px solid #E5E7EB;
            padding-top: 5px;
        }
        /* ⚠️ NÃO usar float aqui. `float` dentro de um elemento `position: fixed` faz o
           dompdf perder a conta da altura da página: a primeira versão deste template
           tinha dois <span> flutuantes no rodapé e o PDF saía com 11 páginas, todas em
           branco menos o header — o conteúdo inteiro sumia. Confirmado por bisecção
           (reintroduzir o float reproduz o bug). Alinhamento aqui é por tabela. */
        footer table { width: 100%; }
        footer td.dir { text-align: right; }

        /* --- Título em destaque: caixa verde-clara com barra à esquerda --- */
        .titulo-destaque {
            background: #F0F9F1;
            border-left: 4px solid #2E7D32;
            color: #16521A;
            font-size: 12pt;
            font-weight: bold;
            padding: 9px 12px;
            margin-bottom: 14px;
            text-align: left;
        }

        .secao-titulo {
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #101521;
            border-bottom: 1px solid #2E7D32;
            padding-bottom: 3px;
            margin: 0 0 7px 0;
        }

        table { width: 100%; border-collapse: collapse; }
        .colunas td { width: 50%; vertical-align: top; padding: 0; }
        .colunas td.esquerda { padding-right: 10px; }

        .campo { padding: 3px 0; border-bottom: 1px solid #F1F5F9; }
        .campo .rotulo {
            display: block;
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748B;
        }
        .campo .valor { display: block; font-size: 9pt; color: #1F2937; }

        .grade td { vertical-align: top; padding: 0 6px 6px 0; }

        .bloco-texto {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            padding: 8px 10px;
            font-size: 9pt;
            color: #374151;
            white-space: pre-wrap;
        }

        .secao { margin-bottom: 14px; }
    </style>
</head>
<body>
    <header>
        <div class="faixa"></div>
        <div class="empresa">Autopel Soluções</div>
        <div class="subtitulo">Solicitação de Cadastro de Bobina</div>
        <div class="protocolo">Protocolo nº {{ $bobina->id }}</div>
        <div class="selo">{{ $statusRotulo }}</div>
    </header>

    <footer>
        <table>
            <tr>
                <td>Autopel Soluções&nbsp; |&nbsp; Solicitação de Cadastro de Bobina</td>
                <td class="dir">Emitido em {{ now()->format('d/m/Y H:i') }}</td>
            </tr>
        </table>
    </footer>

    <main>
        <div class="titulo-destaque">{{ $tituloDestaque }}</div>

        <table class="colunas secao">
            <tr>
                <td class="esquerda">
                    <p class="secao-titulo">Resumo da solicitação</p>
                    @foreach ($resumo as $rotulo => $valor)
                        <div class="campo">
                            <span class="rotulo">{{ $rotulo }}</span>
                            <span class="valor">{{ $valor }}</span>
                        </div>
                    @endforeach
                </td>
                <td>
                    <p class="secao-titulo">Informações comerciais</p>
                    @foreach ($comerciais as $rotulo => $valor)
                        <div class="campo">
                            <span class="rotulo">{{ $rotulo }}</span>
                            <span class="valor">{{ $valor }}</span>
                        </div>
                    @endforeach
                </td>
            </tr>
        </table>

        <div class="secao">
            <p class="secao-titulo">Características técnicas</p>
            <table class="grade">
                @foreach (collect($tecnicas)->chunk(3) as $linha)
                    <tr>
                        @foreach ($linha as $rotulo => $valor)
                            <td style="width: 33.33%">
                                <div class="campo">
                                    <span class="rotulo">{{ $rotulo }}</span>
                                    <span class="valor">{{ $valor }}</span>
                                </div>
                            </td>
                        @endforeach
                        {{-- completa a linha pra grade não desalinhar --}}
                        @for ($i = $linha->count(); $i < 3; $i++)
                            <td style="width: 33.33%"></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        </div>

        <div class="secao">
            <p class="secao-titulo">Envio</p>
            <table class="grade">
                <tr>
                    @foreach ($envio as $rotulo => $valor)
                        <td style="width: 50%">
                            <div class="campo">
                                <span class="rotulo">{{ $rotulo }}</span>
                                <span class="valor">{{ $valor }}</span>
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        </div>

        <div class="secao">
            <p class="secao-titulo">Observações</p>
            <div class="bloco-texto">{{ trim((string) $bobina->observacoes) ?: '-' }}</div>
        </div>
    </main>
</body>
</html>
