<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Relatório embutido na Home
    |--------------------------------------------------------------------------
    |
    | Substitui o gráfico Chart.js de comparação de faturamento para supervisor,
    | admin e diretor. Vendedor e representante continuam com o gráfico local.
    |
    | Não é segredo — é o snippet "Incorporar" do Power BI. Quem autentica é a
    | conta Microsoft do browser (licença Pro), não o login do CRM. O Laravel
    | NÃO injeta RLS nem o seletor de visão da Home: o filtro é o que o
    | dataset do Power BI aplicar àquela conta.
    |
    | ⚠️ Ler por config(), nunca env() no app: em produção o config está
    | cacheado e env() devolveria null exatamente onde o embed deveria aparecer.
    |
    */

    'embed_url' => env(
        'POWERBI_EMBED_URL',
        'https://app.powerbi.com/reportEmbed?reportId=2274b4af-037f-460a-85da-dbe978ee550d&autoAuth=true&ctid=455c3f1c-0a92-4f6d-8943-26ee08301ad0',
    ),

];
