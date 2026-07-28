<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento #{{ $orcamento->id }}</title>
    <style>
        @page { size: A4; margin: 28px 32px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 10.5px; color: #1a1a1a; }
        table { border-collapse: collapse; width: 100%; }
        .topbar { height: 4px; background: #0F3A69; margin-bottom: 12px; }
        .header-table td { vertical-align: top; }
        .empresa-nome { font-size: 13px; font-weight: bold; color: #0F3A69; margin: 0 0 2px; }
        .empresa-info { font-size: 9px; color: #555; line-height: 1.4; }
        .doc-box { border: 1px solid #00A9CE; background: #f0fbfd; padding: 8px 12px; text-align: right; }
        .doc-box .titulo { font-size: 13px; font-weight: bold; color: #0F3A69; text-transform: uppercase; margin: 0; }
        .doc-box .meta { font-size: 9px; color: #666; margin: 2px 0 0; }
        .secao { margin-top: 14px; }
        .secao-titulo { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; font-weight: bold; margin: 0 0 4px; border-bottom: 1px solid #e5e5e5; padding-bottom: 3px; }
        table.dados td { padding: 2px 0; vertical-align: top; font-size: 10px; }
        table.dados td.label { width: 110px; color: #666; }
        table.info-grid > tr > td { width: 50%; vertical-align: top; padding-right: 16px; }
        table.itens { margin-top: 4px; }
        table.itens th { background: #1a1a1a; color: #fff; text-align: left; padding: 5px 6px; font-size: 8.5px; text-transform: uppercase; }
        table.itens th.num, table.itens td.num { text-align: right; }
        table.itens td { padding: 5px 6px; border-bottom: 1px solid #e5e5e5; font-size: 9.5px; }
        table.itens tr:nth-child(even) td { background: #fafbfc; }
        .totais-table td { padding: 3px 8px; font-size: 10px; }
        .totais-table .total-final td { border-top: 2px solid #1a1a1a; font-weight: bold; font-size: 12px; padding-top: 6px; color: #0F3A69; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge-aprovado { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; }
        .badge-pendente { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
        .badge-rejeitado { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
        .importante-box { border: 1px solid #ff8f00; background: #fff8ec; padding: 8px; margin-top: 8px; font-size: 9.5px; }
        .importante-box .rotulo { color: #ff8f00; font-weight: bold; text-transform: uppercase; font-size: 8.5px; }
        .footer-table td { width: 33.33%; vertical-align: top; font-size: 9px; color: #666; padding-top: 4px; }
        .footer-table .rotulo { text-transform: uppercase; color: #999; font-weight: bold; font-size: 8px; }
        .rodape-geracao { margin-top: 10px; font-size: 8.5px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <div class="topbar"></div>

    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                @php($logoPath = public_path('images/autopel-logo.png'))
                @if (file_exists($logoPath))
                    <img src="{{ $logoPath }}" alt="Autopel" style="height: 42px;" />
                @endif
                <p class="empresa-nome">Autopel Soluções</p>
                <p class="empresa-info">
                    CNPJ 06.698.091/0005-90<br>
                    {{ $orcamento->user->display_name ?: $orcamento->user->name }}
                </p>
            </td>
            <td style="width: 40%;">
                <div class="doc-box">
                    <p class="titulo">Orçamento</p>
                    <p class="meta">Nº {{ $orcamento->id }}</p>
                    <p class="meta">Emitido em {{ $orcamento->created_at->format('d/m/Y') }}</p>
                </div>
            </td>
        </tr>
    </table>

    <table class="info-grid secao">
        <tr>
            <td>
                <p class="secao-titulo">Dados do Cliente</p>
                <table class="dados">
                    <tr><td class="label">Razão Social</td><td>{{ $orcamento->cliente_nome }}</td></tr>
                    <tr><td class="label">CNPJ</td><td>{{ $orcamento->cliente_cnpj ?? '—' }}</td></tr>
                    <tr><td class="label">Contato</td><td>{{ $orcamento->cliente_contato ?? '—' }}</td></tr>
                </table>
            </td>
            <td>
                <p class="secao-titulo">Informações do Orçamento</p>
                <table class="dados">
                    <tr><td class="label">Status</td><td>
                        <span class="badge badge-{{ $orcamento->status_gestor }}">
                            {{ ['pendente' => 'Pendente', 'aprovado' => 'Aprovado', 'rejeitado' => 'Rejeitado'][$orcamento->status_gestor] }}
                        </span>
                    </td></tr>
                    <tr><td class="label">Forma de Pagamento</td><td>{{ $orcamento->forma_pagamento ?? '—' }}</td></tr>
                    <tr><td class="label">Faturamento</td><td>{{ $orcamento->tipo_produto_servico === 'produto' ? 'Produto (IPI 3,25%)' : 'Serviço (sem IPI)' }}</td></tr>
                    <tr><td class="label">Validade</td><td>{{ optional($orcamento->data_validade)->format('d/m/Y') ?? '—' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="secao">
        <p class="secao-titulo">Produto/Serviço</p>
        <table class="itens">
            <thead>
                @if ($orcamento->tipo_produto_servico === 'produto')
                    <tr>
                        <th>Item</th>
                        <th class="num">Qtd.</th>
                        <th class="num">Vlr Unit. s/IPI</th>
                        <th class="num">Vlr Unit. c/IPI</th>
                        <th class="num">Vlr Total s/IPI</th>
                        <th class="num">Vlr Total c/IPI</th>
                    </tr>
                @else
                    <tr>
                        <th>Item</th>
                        <th class="num">Qtd.</th>
                        <th class="num">Vlr Unitário</th>
                        <th class="num">Vlr Total</th>
                    </tr>
                @endif
            </thead>
            <tbody>
                @foreach ($itensCalculados as $item)
                    <tr>
                        <td>@if($item['codProduto']){{ $item['codProduto'] }} · @endif{{ $item['descricao'] }}</td>
                        <td class="num">{{ number_format($item['quantidade'], 2, ',', '.') }}</td>
                        @if ($orcamento->tipo_produto_servico === 'produto')
                            <td class="num">R$ {{ number_format($item['valorUnitarioSemIpi'], 2, ',', '.') }}</td>
                            <td class="num">R$ {{ number_format($item['valorUnitarioComIpi'], 2, ',', '.') }}</td>
                            <td class="num">R$ {{ number_format($item['valorTotalSemIpi'], 2, ',', '.') }}</td>
                            <td class="num">R$ {{ number_format($item['valorTotalComIpi'], 2, ',', '.') }}</td>
                        @else
                            <td class="num">R$ {{ number_format($item['valorUnitarioComIpi'], 2, ',', '.') }}</td>
                            <td class="num">R$ {{ number_format($item['valorTotalComIpi'], 2, ',', '.') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totais-table" style="width: 40%; margin-left: 60%;">
            <tr>
                <td>Subtotal s/IPI</td>
                <td class="num">R$ {{ number_format($resumo['subtotalProdutosSemIpi'], 2, ',', '.') }}</td>
            </tr>
            @if ($orcamento->tipo_produto_servico === 'produto')
                <tr>
                    <td>Subtotal c/IPI</td>
                    <td class="num">R$ {{ number_format($resumo['subtotalProdutosComIpi'], 2, ',', '.') }}</td>
                </tr>
            @endif
            @if ($resumo['subtotalEtiquetas'] > 0)
                <tr>
                    <td>Subtotal etiquetas</td>
                    <td class="num">R$ {{ number_format($resumo['subtotalEtiquetas'], 2, ',', '.') }}</td>
                </tr>
            @endif
            <tr class="total-final">
                <td>Valor Total</td>
                <td class="num">R$ {{ number_format($resumo['totalGeral'], 2, ',', '.') }}</td>
            </tr>
        </table>
    </div>

    @if ($orcamento->observacoes)
        <div class="secao">
            <p class="secao-titulo">Observações</p>
            <p style="font-size: 9.5px;">{{ $orcamento->observacoes }}</p>
        </div>
    @endif

    <div class="secao">
        <p class="secao-titulo">Outras Informações</p>
        <table class="dados">
            <tr><td class="label">Variação produção personalizada</td><td>{{ $orcamento->variacao_producao_personalizado ?? '—' }}</td></tr>
            <tr><td class="label">Prazo de produção</td><td>{{ $orcamento->prazo_producao ?? '—' }}</td></tr>
            <tr><td class="label">Garantia de imagem</td><td>{{ $orcamento->garantia_imagem ?? '—' }}</td></tr>
        </table>
        @if ($orcamento->texto_importante)
            <div class="importante-box">
                <p class="rotulo">Importante</p>
                <p>{{ $orcamento->texto_importante }}</p>
            </div>
        @endif
    </div>

    <div class="secao">
        <p class="secao-titulo">Aprovação</p>
        <table class="dados">
            <tr><td class="label">Nível exigido</td><td>{{ ucfirst($orcamento->nivel_aprovacao) }}</td></tr>
            @if ($orcamento->aprovadoPor)
                <tr><td class="label">Decidido por</td><td>{{ $orcamento->aprovadoPor->display_name ?: $orcamento->aprovadoPor->name }} em {{ optional($orcamento->aprovado_em)->format('d/m/Y H:i') }}</td></tr>
            @endif
            @if ($orcamento->motivo_rejeicao)
                <tr><td class="label">Motivo da rejeição</td><td>{{ $orcamento->motivo_rejeicao }}</td></tr>
            @endif
        </table>
    </div>

    <table class="footer-table secao">
        <tr>
            <td>
                <p class="rotulo">Vendedor Responsável</p>
                <p>{{ $orcamento->user->display_name ?: $orcamento->user->name }}</p>
            </td>
            <td>
                <p class="rotulo">Condições</p>
                <p>Validade: {{ optional($orcamento->data_validade)->format('d/m/Y') ?? '—' }}<br>
                Pagamento: {{ $orcamento->forma_pagamento ?? '—' }}<br>
                Prazo de entrega: a combinar</p>
            </td>
            <td>
                <p class="rotulo">Autopel</p>
                <p>Autopel Soluções<br>CNPJ 06.698.091/0005-90</p>
            </td>
        </tr>
    </table>

    <p class="rodape-geracao">
        Documento gerado automaticamente pelo sistema PALMA em {{ now()->format('d/m/Y \à\s H:i') }} — sem valor fiscal.
    </p>
</body>
</html>
