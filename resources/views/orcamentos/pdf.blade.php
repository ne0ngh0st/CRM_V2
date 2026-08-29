{{--
    Documento de orçamento — versão do CLIENTE.

    ⚠️ Este arquivo tem um par: resources/js/Components/Orcamentos/OrcamentoSheet.vue.
    A folha na tela é o mesmo documento em modo editável, então QUALQUER mudança de
    estrutura, ordem de seção, rótulo ou cor tem que ser feita nos dois. Se divergirem,
    o vendedor preenche uma coisa e o cliente recebe outra.

    ⚠️ Só entra aqui o que o cliente pode ver. O fluxo interno de aprovação
    (nível exigido, quem aprovou, motivo da rejeição, status do gestor) NÃO é
    impresso — é informação de bastidor.

    ⚠️ Restrições do dompdf que ditam o desenho: sem flexbox, sem grid, sem sombra.
    Layout é tabela; separação entre caixas é coluna espaçadora, não `gap`.
    E nunca usar `float` dentro de `position: fixed` — quebra a contagem de páginas.
--}}
@php
    $fontes = public_path('fonts/inter');
    $logo = public_path('images/autopel-logo.png');
    $ehProduto = $orcamento->tipo_produto_servico === 'produto';
    $vendedor = $orcamento->user->display_name ?: $orcamento->user->name;
    $contatoVendedor = collect([$orcamento->user->telefone, $orcamento->user->email])->filter()->implode(' · ');
    $moeda = fn ($v) => number_format((float) $v, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento nº {{ $orcamento->id }} — Autopel Soluções</title>
    <style>
        /* Inter embarcado do próprio repositório (public/fonts/inter). Nada de CDN:
           `enable_remote` é false no dompdf, então fonte remota simplesmente não carrega. */
        @font-face { font-family: 'Inter'; font-weight: 400; font-style: normal; src: url('{{ $fontes }}/Inter-Regular.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 500; font-style: normal; src: url('{{ $fontes }}/Inter-Medium.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 600; font-style: normal; src: url('{{ $fontes }}/Inter-SemiBold.ttf') format('truetype'); }
        @font-face { font-family: 'Inter'; font-weight: 700; font-style: normal; src: url('{{ $fontes }}/Inter-Bold.ttf') format('truetype'); }

        @page { size: A4; margin: 14mm 13mm 20mm 13mm; }

        /* O dompdf não tem regra padrão pra elemento HTML5. */
        header, footer, main, section { display: block; }

        body { font-family: 'Inter', sans-serif; font-size: 9.5pt; color: #111827; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        p { margin: 0; }

        /* ---------- Cabeçalho ---------- */
        .marca-nome { font-size: 12.5pt; font-weight: 700; color: #0F3A69; letter-spacing: -0.2px; }
        .marca-doc { font-size: 8pt; color: #6B7280; padding-top: 2px; }

        /* ---------- Barra de título ---------- */
        .barra-titulo { background: #0F3A69; }
        .barra-titulo td { padding: 9px 12px; vertical-align: middle; }
        .barra-titulo .rotulo { color: #FFFFFF; font-size: 13pt; font-weight: 700; letter-spacing: 1.6px; }
        .barra-titulo .numero { color: #FFFFFF; font-size: 11pt; font-weight: 600; text-align: right; }
        .barra-regua { height: 2.5px; background: #00A9CE; }

        /* ---------- Legenda de seção ---------- */
        /* `page-break-inside: avoid` é o que impede a legenda ficar no pé de uma
           página e a caixa dela começar na seguinte (aconteceu com o aceite). */
        .secao { margin-top: 12px; page-break-inside: avoid; }
        .legenda {
            background: #F1F5F9;
            border-left: 3px solid #005A6F;
            padding: 5px 10px;
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #0F3A69;
        }

        /* ---------- Painéis lado a lado ---------- */
        .painel { border: 1px solid #E5E7EB; }
        .painel .cabeca {
            background: #F1F5F9;
            border-bottom: 1px solid #E5E7EB;
            padding: 5px 10px;
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #0F3A69;
        }
        .painel .rotulo { width: 40%; padding: 3.5px 10px; font-size: 8.5pt; color: #6B7280; }
        .painel .valor { padding: 3.5px 10px 3.5px 0; font-size: 9.5pt; font-weight: 500; }
        .painel .destaque { color: #0F3A69; font-weight: 600; }

        /* ---------- Itens ---------- */
        table.itens { margin-top: 6px; border: 1px solid #E5E7EB; }
        table.itens th {
            background: #0F3A69;
            color: #FFFFFF;
            font-size: 7.5pt;
            font-weight: 600;
            letter-spacing: 0.2px;
            text-transform: uppercase;
            text-align: left;
            padding: 7px 6px;
        }
        table.itens td { padding: 5px 8px; font-size: 9pt; border-bottom: 1px solid #EEF2F6; }
        table.itens tr:nth-child(even) td { background: #FAFBFC; }
        table.itens .num { text-align: right; }
        table.itens .meio { text-align: center; color: #6B7280; }
        table.itens .cod { color: #6B7280; font-size: 8.5pt; }

        /* ---------- Resumo financeiro ---------- */
        .resumo { border: 1px solid #E5E7EB; }
        .resumo td { padding: 5px 12px; font-size: 9.5pt; }
        .resumo td.num { text-align: right; font-weight: 500; }
        .resumo .rot { color: #6B7280; }
        .resumo .total td { background: #0F3A69; color: #FFFFFF; font-size: 11.5pt; font-weight: 700; padding: 9px 12px; }

        /* ---------- Blocos de texto ---------- */
        .caixa { border: 1px solid #E5E7EB; border-top: 0; padding: 8px 11px; font-size: 9pt; line-height: 1.5; }
        .info-linha td { padding: 3px 0; font-size: 9pt; }
        .info-linha td.rot { width: 34%; color: #6B7280; }
        .importante { border: 1px solid #ff8f00; background: #FFF8EC; padding: 9px 11px; margin-top: 8px; font-size: 8.8pt; line-height: 1.5; }
        .importante .tag { color: #B36400; font-weight: 700; text-transform: uppercase; font-size: 7.5pt; letter-spacing: 0.8px; }

        /* ---------- Aceite ---------- */
        .aceite td { padding: 0 14px 0 0; }
        .aceite .espaco { height: 26px; }
        .aceite .linha { border-top: 1px solid #9CA3AF; padding-top: 4px; font-size: 8pt; color: #6B7280; }

        /* ---------- Rodapé ---------- */
        footer {
            position: fixed;
            bottom: -15mm;
            left: 0;
            right: 0;
            border-top: 1px solid #E5E7EB;
            padding-top: 6px;
            font-size: 7.5pt;
            color: #9CA3AF;
        }
        /* ⚠️ Nada de `float` aqui dentro: `float` em `position: fixed` faz o dompdf
           perder a conta da altura da página (o PDF sai com N páginas em branco). */
        footer td { vertical-align: middle; }
        footer td.dir { text-align: right; }
        /* Numeração real. ⚠️ `counter(pages)` (total) não é confiável neste dompdf —
           voltou "de 0" em teste real —, então só a página atual. */
        .pagenum::after { content: counter(page); }
    </style>
</head>
<body>

<table>
    <tr>
        <td style="width: 52%;">
            @if (file_exists($logo))
                <img src="{{ $logo }}" alt="Autopel Soluções" style="height: 42px;">
            @else
                <p class="marca-nome">Autopel Soluções</p>
            @endif
        </td>
        <td style="width: 48%; text-align: right;">
            <p class="marca-nome">Autopel Soluções</p>
            <p class="marca-doc">CNPJ 06.698.091/0005-90</p>
        </td>
    </tr>
</table>

<table class="barra-titulo" style="margin-top: 12px;">
    <tr>
        <td class="rotulo">ORÇAMENTO</td>
        <td class="numero">Nº {{ str_pad((string) $orcamento->id, 4, '0', STR_PAD_LEFT) }}</td>
    </tr>
</table>
<div class="barra-regua"></div>

<table style="margin-top: 14px;">
    <tr>
        <td style="width: 49%;">
            <table class="painel">
                <tr><td class="cabeca" colspan="2">Dados do cliente</td></tr>
                <tr>
                    <td class="rotulo">Razão social</td>
                    <td class="valor destaque">{{ $orcamento->cliente_nome }}</td>
                </tr>
                <tr>
                    <td class="rotulo">CNPJ</td>
                    <td class="valor">{{ $orcamento->cliente_cnpj ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Contato</td>
                    <td class="valor">{{ $orcamento->cliente_contato ?: '—' }}</td>
                </tr>
            </table>
        </td>
        <td style="width: 2%;"></td>
        <td style="width: 49%;">
            <table class="painel">
                <tr><td class="cabeca" colspan="2">Dados da proposta</td></tr>
                <tr>
                    <td class="rotulo">Emissão</td>
                    <td class="valor">{{ $orcamento->created_at->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Validade</td>
                    <td class="valor destaque">{{ optional($orcamento->data_validade)->format('d/m/Y') ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Forma de pagamento</td>
                    <td class="valor">{{ $orcamento->forma_pagamento ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Frete</td>
                    <td class="valor">{{ $orcamento->tipo_frete ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Faturamento</td>
                    <td class="valor">{{ $ehProduto ? 'Produto (IPI 3,25% embutido)' : 'Serviço (sem IPI)' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">Vendedor</td>
                    <td class="valor">
                        {{ $vendedor }}
                        @if ($contatoVendedor)
                            <br><span style="font-weight: 400; color: #6B7280; font-size: 8.5pt;">{{ $contatoVendedor }}</span>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="secao">
    <div class="legenda">Itens do orçamento</div>
    <table class="itens">
        <thead>
            <tr>
                <th style="width: 3.5%;">Nº</th>
                <th style="width: 8.5%;">Código</th>
                <th style="width: {{ $ehProduto ? '29.5%' : '46%' }};">Descrição</th>
                <th class="num" style="width: 7%;">Qtd.</th>
                @if ($ehProduto)
                    <th class="num" style="width: 12.5%;">Unit. s/IPI</th>
                    <th class="num" style="width: 12.5%;">Unit. c/IPI</th>
                    <th class="num" style="width: 12.5%;">Total s/IPI</th>
                    <th class="num" style="width: 14%;">Total c/IPI</th>
                @else
                    <th class="num" style="width: 17%;">Vlr. unitário</th>
                    <th class="num" style="width: 18%;">Vlr. total</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($itensCalculados as $i => $item)
                <tr>
                    <td class="meio">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="cod">{{ $item['codProduto'] ?: '—' }}</td>
                    <td>{{ $item['descricao'] }}</td>
                    <td class="num">{{ $moeda($item['quantidade']) }}</td>
                    @if ($ehProduto)
                        <td class="num">{{ $moeda($item['valorUnitarioSemIpi']) }}</td>
                        <td class="num">{{ $moeda($item['valorUnitarioComIpi']) }}</td>
                        <td class="num">{{ $moeda($item['valorTotalSemIpi']) }}</td>
                        <td class="num">{{ $moeda($item['valorTotalComIpi']) }}</td>
                    @else
                        <td class="num">{{ $moeda($item['valorUnitarioComIpi']) }}</td>
                        <td class="num">{{ $moeda($item['valorTotalComIpi']) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
    <p style="margin-top: 5px; font-size: 7.5pt; color: #9CA3AF;">Valores em reais (R$).</p>
</div>

<table style="margin-top: 10px;">
    <tr>
        <td style="width: 54%;"></td>
        <td style="width: 46%;">
            <table class="resumo">
                <tr>
                    <td class="rot">Subtotal s/ IPI</td>
                    <td class="num">R$ {{ $moeda($resumo['subtotalProdutosSemIpi']) }}</td>
                </tr>
                @if ($ehProduto)
                    <tr>
                        <td class="rot">Subtotal c/ IPI</td>
                        <td class="num">R$ {{ $moeda($resumo['subtotalProdutosComIpi']) }}</td>
                    </tr>
                @endif
                @if ($resumo['subtotalEtiquetas'] > 0)
                    <tr>
                        <td class="rot">Subtotal etiquetas</td>
                        <td class="num">R$ {{ $moeda($resumo['subtotalEtiquetas']) }}</td>
                    </tr>
                @endif
                <tr class="total">
                    <td>VALOR TOTAL</td>
                    <td class="num" style="color: #FFFFFF;">R$ {{ $moeda($resumo['totalGeral']) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if ($orcamento->observacoes)
    <div class="secao">
        <div class="legenda">Observações</div>
        <div class="caixa">{{ $orcamento->observacoes }}</div>
    </div>
@endif

<div class="secao">
    <div class="legenda">Condições de fornecimento</div>
    <div class="caixa">
        <table class="info-linha">
            <tr>
                <td class="rot">Variação em produtos personalizados</td>
                <td>{{ $orcamento->variacao_producao_personalizado ?: '—' }}</td>
            </tr>
            <tr>
                <td class="rot">Prazo de produção</td>
                <td>{{ $orcamento->prazo_producao ?: '—' }}</td>
            </tr>
            <tr>
                <td class="rot">Garantia de imagem</td>
                <td>{{ $orcamento->garantia_imagem ?: '—' }}</td>
            </tr>
        </table>
        @if ($orcamento->texto_importante)
            <div class="importante">
                <p class="tag">Importante</p>
                <p style="margin-top: 3px;">{{ $orcamento->texto_importante }}</p>
            </div>
        @endif
    </div>
</div>

<div class="secao">
    <div class="legenda">Aceite do cliente</div>
    <div class="caixa">
        <table class="aceite">
            <tr>
                <td style="width: 46%;"><div class="espaco"></div><div class="linha">Nome do responsável</div></td>
                <td style="width: 20%;"><div class="espaco"></div><div class="linha">Data</div></td>
                <td style="width: 34%; padding-right: 0;"><div class="espaco"></div><div class="linha">Assinatura e carimbo</div></td>
            </tr>
        </table>
    </div>
</div>

<footer>
    <table>
        <tr>
            <td>Autopel Soluções · CNPJ 06.698.091/0005-90 · Orçamento nº {{ str_pad((string) $orcamento->id, 4, '0', STR_PAD_LEFT) }}</td>
            <td class="dir">Página <span class="pagenum"></span></td>
        </tr>
    </table>
    <p style="margin-top: 3px;">Documento gerado pelo sistema PALMA em {{ now()->format('d/m/Y \à\s H:i') }} — sem valor fiscal.</p>
</footer>

</body>
</html>
