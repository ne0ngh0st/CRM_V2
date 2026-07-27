<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Orçamento #{{ $orcamento->id }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #0F3A69; }
        .subtitulo { color: #666; margin: 0 0 16px; }
        .secao { margin-bottom: 14px; }
        .secao-titulo { font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 4px; font-weight: bold; }
        table.dados { width: 100%; border-collapse: collapse; }
        table.dados td { padding: 2px 0; vertical-align: top; }
        table.dados td.label { width: 120px; color: #666; }
        table.itens { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.itens th { background: #1a1a1a; color: #fff; text-align: left; padding: 5px 6px; font-size: 9px; text-transform: uppercase; }
        table.itens th.num, table.itens td.num { text-align: right; }
        table.itens td { padding: 5px 6px; border-bottom: 1px solid #e5e5e5; }
        .total-row td { border-top: 2px solid #1a1a1a; font-weight: bold; padding-top: 6px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        .badge-aprovado { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; }
        .badge-pendente { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
        .badge-rejeitado { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
        .rodape { margin-top: 20px; font-size: 9px; color: #999; }
    </style>
</head>
<body>
    <h1>Orçamento #{{ $orcamento->id }}</h1>
    <p class="subtitulo">Gerado em {{ now()->format('d/m/Y H:i') }} por {{ $orcamento->user->display_name ?: $orcamento->user->name }}</p>

    <div class="secao">
        <div class="secao-titulo">Cliente</div>
        <table class="dados">
            <tr><td class="label">Razão Social</td><td>{{ $orcamento->cliente_nome }}</td></tr>
            <tr><td class="label">CNPJ</td><td>{{ $orcamento->cliente_cnpj ?? '—' }}</td></tr>
            <tr><td class="label">Contato</td><td>{{ $orcamento->cliente_contato ?? '—' }}</td></tr>
        </table>
    </div>

    <div class="secao">
        <div class="secao-titulo">Itens</div>
        <table class="itens">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th class="num">Qtd.</th>
                    <th class="num">Vlr. Unit.</th>
                    <th class="num">Vlr. Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orcamento->itens as $item)
                    <tr>
                        <td>@if($item->cod_produto){{ $item->cod_produto }} · @endif{{ $item->descricao }}</td>
                        <td class="num">{{ number_format($item->quantidade, 2, ',', '.') }}</td>
                        <td class="num">R$ {{ number_format($item->valor_unitario, 2, ',', '.') }}</td>
                        <td class="num">R$ {{ number_format($item->valor_total, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3">Valor Total</td>
                    <td class="num">R$ {{ number_format($orcamento->valor_total, 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="secao">
        <div class="secao-titulo">Condições</div>
        <table class="dados">
            <tr><td class="label">Forma de Pagamento</td><td>{{ $orcamento->forma_pagamento ?? '—' }}</td></tr>
            <tr><td class="label">Validade</td><td>{{ optional($orcamento->data_validade)->format('d/m/Y') ?? '—' }}</td></tr>
        </table>
    </div>

    <div class="secao">
        <div class="secao-titulo">Aprovação</div>
        <table class="dados">
            <tr>
                <td class="label">Status</td>
                <td>
                    <span class="badge badge-{{ $orcamento->status_gestor }}">
                        {{ ['pendente' => 'Pendente', 'aprovado' => 'Aprovado', 'rejeitado' => 'Rejeitado'][$orcamento->status_gestor] }}
                    </span>
                </td>
            </tr>
            <tr><td class="label">Nível exigido</td><td>{{ ucfirst($orcamento->nivel_aprovacao) }}</td></tr>
            @if ($orcamento->aprovadoPor)
                <tr><td class="label">Decidido por</td><td>{{ $orcamento->aprovadoPor->display_name ?: $orcamento->aprovadoPor->name }} em {{ optional($orcamento->aprovado_em)->format('d/m/Y H:i') }}</td></tr>
            @endif
            @if ($orcamento->motivo_rejeicao)
                <tr><td class="label">Motivo da rejeição</td><td>{{ $orcamento->motivo_rejeicao }}</td></tr>
            @endif
        </table>
    </div>

    <p class="rodape">Autopel Soluções — documento gerado pelo PALMA CRM, sem valor fiscal.</p>
</body>
</html>
