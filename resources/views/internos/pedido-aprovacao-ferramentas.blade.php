{{--
    Pedido de aprovação de despesa — Claude + Laravel Forge.
    Documento interno para a diretoria. Gerado sob demanda, não faz parte do CRM.
    Restrições do dompdf: layout em tabela, sem flex/grid/float em position:fixed.
--}}
@php
    $fontes = public_path('fonts/inter');
    $logo = public_path('images/autopel-logo.png');
    $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    $usd = fn ($v) => 'US$ ' . number_format((float) $v, 2, ',', '.');

    $cambio = 5.50;
    $iof = 0.035;
    $claudeUsd = 20.00;
    $forgeUsd = 19.00;
    $totalUsd = $claudeUsd + $forgeUsd;

    $custo = function (float $usd) use ($cambio, $iof): array {
        $brl = $usd * $cambio;
        $imposto = $brl * $iof;
        return [
            'brl' => $brl,
            'iof' => $imposto,
            'total' => $brl + $imposto,
        ];
    };

    $claude = $custo($claudeUsd);
    $forge = $custo($forgeUsd);
    $total = $custo($totalUsd);
    $anual = $total['total'] * 12;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Pedido de aprovação — Claude e Laravel Forge</title>
    <style>
        @font-face { font-family: 'Inter'; font-weight: 400; font-style: normal; src: url('{{ $fontes }}/Inter-Regular.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 500; font-style: normal; src: url('{{ $fontes }}/Inter-Medium.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 600; font-style: normal; src: url('{{ $fontes }}/Inter-SemiBold.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 700; font-style: normal; src: url('{{ $fontes }}/Inter-Bold.ttf') format('truetype'); }

        @page { size: A4; margin: 12mm 13mm 16mm 13mm; }

        header, footer, main, section { display: block; }
        body { font-family: 'Inter', DejaVu Sans, sans-serif; font-size: 9pt; color: #111827; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        p { margin: 0 0 6px 0; }

        footer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            height: 10mm;
            font-size: 7pt;
            color: #6B7280;
            border-top: 0.4pt solid #E5E7EB;
            padding-top: 2mm;
        }
        footer td { vertical-align: middle; }
        footer td.dir { text-align: right; }

        .marca-nome { font-size: 12pt; font-weight: 700; color: #0F3A69; }
        .marca-meta { font-size: 7.5pt; color: #6B7280; padding-top: 2px; }

        .barra-titulo { background: #0F3A69; }
        .barra-titulo td { padding: 8px 12px; vertical-align: middle; }
        .barra-titulo .rotulo { color: #FFFFFF; font-size: 11.5pt; font-weight: 700; letter-spacing: 1.2px; }
        .barra-titulo .numero { color: #FFFFFF; font-size: 8.5pt; font-weight: 500; text-align: right; }
        .barra-regua { height: 2.5px; background: #00A9CE; }

        .resumo {
            margin-top: 8px;
            border: 1px solid #E5E7EB;
            background: #F8FAFC;
        }
        .resumo td { padding: 8px 10px; font-size: 9pt; }
        .resumo strong { color: #0F3A69; }

        .secao { margin-top: 10px; page-break-inside: avoid; }
        .legenda {
            background: #F1F5F9;
            border-left: 3px solid #005A6F;
            padding: 4px 10px;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.9px;
            text-transform: uppercase;
            color: #0F3A69;
        }

        .itens { border: 1px solid #E5E7EB; border-top: none; }
        .itens .cabeca { background: #F1F5F9; border-bottom: 1px solid #E5E7EB; }
        .itens .cabeca td { padding: 5px 10px; font-size: 8pt; font-weight: 700; color: #0F3A69; }
        .itens .corpo td { padding: 8px 10px; border-bottom: 1px solid #F3F4F6; }
        .itens .nome { font-size: 9.5pt; font-weight: 700; color: #0F3A69; }
        .itens .plano { font-size: 8pt; color: #005A6F; font-weight: 600; padding-top: 1px; }
        .itens .desc { font-size: 8pt; color: #4B5563; padding-top: 3px; line-height: 1.35; }

        .tbl { border: 1px solid #E5E7EB; border-top: none; }
        .tbl th {
            background: #0F3A69;
            color: #fff;
            font-size: 7.5pt;
            font-weight: 600;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: center;
        }
        .tbl th.esq, .tbl td.esq { text-align: left; }
        .tbl td {
            padding: 6px 8px;
            font-size: 8.5pt;
            text-align: center;
            border-bottom: 1px solid #E5E7EB;
        }
        .tbl .total td {
            background: #0F3A69;
            color: #fff;
            font-weight: 700;
            border-bottom: none;
        }
        .tbl .alt td { background: #F8FAFC; color: #6B7280; font-size: 8pt; }

        .destaque { margin-top: 8px; background: #0F3A69; }
        .destaque td { padding: 10px 12px; color: #fff; vertical-align: middle; }
        .destaque .rotulo { font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase; color: #94A3B8; }
        .destaque .valor { font-size: 16pt; font-weight: 700; padding-top: 1px; }
        .destaque .sub { font-size: 8.5pt; color: #CBD5E1; padding-top: 2px; }
        .destaque .dir { text-align: right; }
        .faixa-ambar { height: 3px; background: #ff8f00; }

        .notas { margin-top: 8px; font-size: 7.5pt; color: #6B7280; line-height: 1.4; }
        .notas strong { color: #374151; }

        .aceite { border: 1px solid #E5E7EB; border-top: none; }
        .aceite td { padding: 10px 12px; }
        .box {
            border: 1px solid #D1D5DB;
            padding: 5px 8px;
            font-size: 8pt;
            font-weight: 600;
            text-align: center;
            color: #374151;
        }
        .linha-assinatura {
            border-bottom: 0.6pt solid #9CA3AF;
            height: 22px;
        }
        .rotulo-assinatura { font-size: 7pt; color: #6B7280; padding-top: 3px; }
    </style>
</head>
<body>
    <footer>
        <table>
            <tr>
                <td>Autopel Soluções · Uso interno · Fontes: claude.com/pricing e laravel.com/forge/pricing (consultados em 02/09/2026)</td>
                <td class="dir">Documento de 1 página</td>
            </tr>
        </table>
    </footer>

    <main>
        <table>
            <tr>
                <td style="width: 32mm; vertical-align: middle;">
                    <img src="{{ $logo }}" alt="Autopel" style="width: 30mm; height: auto;">
                </td>
                <td style="vertical-align: middle; padding-left: 8px;">
                    <div class="marca-nome">Autopel Soluções</div>
                    <div class="marca-meta">CRM PALMA v2 · Desenvolvimento comercial</div>
                    <div class="marca-meta">Uso interno · Diretoria</div>
                </td>
                <td style="width: 52mm; text-align: right; vertical-align: middle;">
                    <div class="marca-meta" style="font-weight: 600; color: #0F3A69;">2 de setembro de 2026</div>
                    <div class="marca-meta">Solicitante: Antonio Barbosa</div>
                    <div class="marca-meta">Ref.: CRM-V2 / ferramentas</div>
                </td>
            </tr>
        </table>

        <table class="barra-titulo" style="margin-top: 8px;">
            <tr>
                <td class="rotulo">PEDIDO DE APROVAÇÃO DE DESPESA</td>
                <td class="numero">Duas assinaturas mensais · cartão corporativo</td>
            </tr>
        </table>
        <div class="barra-regua"></div>

        <table class="resumo">
            <tr>
                <td>
                    Aprovar o <strong>Claude Pro</strong> (Anthropic, US$ 20/mês) e o <strong>Laravel Forge Growth</strong>
                    para o desenvolvedor único do CRM PALMA v2, já no ar em
                    <strong>crm.autopel.online</strong>. São cobranças em dólar, fora da fatura AWS.
                    Canceláveis a qualquer momento.
                </td>
            </tr>
        </table>

        <div class="secao">
            <div class="legenda">O que está sendo pedido</div>
            <table class="itens">
                <tr class="cabeca">
                    <td style="width: 50%;">1. Claude — Anthropic</td>
                    <td>2. Laravel Forge — Laravel LLC</td>
                </tr>
                <tr class="corpo">
                    <td>
                        <div class="nome">Claude Pro</div>
                        <div class="plano">{{ $usd($claudeUsd) }} / mês · 1 licença</div>
                        <div class="desc">
                            Inteligência artificial usada para escrever, revisar e manter o código
                            do CRM. Inclui o Claude Code. É o que torna viável o sistema ter um
                            desenvolvedor só, em vez de uma equipe. Plano profissional de entrada
                            (US$ 20/mês, ou US$ 17/mês se pago o ano à vista).
                        </div>
                    </td>
                    <td>
                        <div class="nome">Forge Growth</div>
                        <div class="plano">{{ $usd($forgeUsd) }} / mês · ilimitado em servidores</div>
                        <div class="desc">
                            Painel que publica o CRM nos dois servidores AWS já contratados
                            (deploy, certificado SSL, filas e agendador). Hoje isso é feito na mão,
                            por SSH, porque o cartão ainda não saiu. A fatura da AWS <strong>não muda</strong>:
                            o Forge só gerencia as máquinas que já existem. O plano Hobby (US$ 12)
                            cobre só 1 servidor; a produção tem 2.
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <div class="secao">
            <div class="legenda">Custo mensal estimado</div>
            <table class="tbl">
                <thead>
                    <tr>
                        <th class="esq">Item</th>
                        <th>Plano</th>
                        <th>US$ / mês</th>
                        <th>R$ / mês</th>
                        <th>IOF 3,5%</th>
                        <th>Total na fatura</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="esq">Claude (Anthropic)</td>
                        <td>Pro</td>
                        <td>{{ $usd($claudeUsd) }}</td>
                        <td>{{ $brl($claude['brl']) }}</td>
                        <td>{{ $brl($claude['iof']) }}</td>
                        <td><strong>{{ $brl($claude['total']) }}</strong></td>
                    </tr>
                    <tr>
                        <td class="esq">Laravel Forge</td>
                        <td>Growth</td>
                        <td>{{ $usd($forgeUsd) }}</td>
                        <td>{{ $brl($forge['brl']) }}</td>
                        <td>{{ $brl($forge['iof']) }}</td>
                        <td><strong>{{ $brl($forge['total']) }}</strong></td>
                    </tr>
                    <tr class="total">
                        <td class="esq">Total pedido</td>
                        <td>2 assinaturas</td>
                        <td>{{ $usd($totalUsd) }}</td>
                        <td>{{ $brl($total['brl']) }}</td>
                        <td>{{ $brl($total['iof']) }}</td>
                        <td>{{ $brl($total['total']) }}</td>
                    </tr>
                    <tr class="alt">
                        <td class="esq" colspan="6">
                            Se o limite do Pro passar a interromper o expediente, o próximo degrau é o Max 5×
                            (US$ 100/mês). Não faz parte deste pedido.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <table class="destaque">
            <tr>
                <td>
                    <div class="rotulo">Total mensal estimado</div>
                    <div class="valor">{{ $brl($total['total']) }}</div>
                    <div class="sub">≈ {{ $brl($anual) }} por ano · 1 pessoa · cancelável mês a mês</div>
                </td>
                <td class="dir" style="width: 48%;">
                    <div class="rotulo">Fora da fatura AWS</div>
                    <div class="sub" style="color: #E5E7EB; padding-top: 4px;">
                        A AWS (~R$ 1.900 / mês, já no ar) continua igual.
                        Isto aqui é cartão corporativo, cobrança internacional,
                        dois fornecedores (Anthropic e Laravel).
                    </div>
                </td>
            </tr>
        </table>
        <div class="faixa-ambar"></div>

        <p class="notas">
            <strong>Premissas de câmbio:</strong> US$ 1 = R$ 5,50 (mesmo usado no orçamento AWS já aprovado;
            o comercial de 31/08/2026 fechou em ≈ R$ 5,18). IOF de 3,5% sobre compra internacional no cartão.
            O spread do emissor pode alterar alguns reais. Valores oficiais sem imposto:
            Claude Pro US$ 20/mês · Forge Growth US$ 19/mês.
        </p>

        <div class="secao">
            <div class="legenda">Decisão da diretoria</div>
            <table class="aceite">
                <tr>
                    <td style="width: 33%;"><div class="box">☐  Aprovado</div></td>
                    <td style="width: 34%;"><div class="box">☐  Aprovado com ressalva</div></td>
                    <td style="width: 33%;"><div class="box">☐  Recusado</div></td>
                </tr>
                <tr>
                    <td>
                        <div class="linha-assinatura"></div>
                        <div class="rotulo-assinatura">Nome</div>
                    </td>
                    <td>
                        <div class="linha-assinatura"></div>
                        <div class="rotulo-assinatura">Cargo</div>
                    </td>
                    <td>
                        <div class="linha-assinatura"></div>
                        <div class="rotulo-assinatura">Data e assinatura</div>
                    </td>
                </tr>
            </table>
        </div>
    </main>
</body>
</html>
