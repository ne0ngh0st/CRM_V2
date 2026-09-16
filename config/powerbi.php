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
        'https://app.powerbi.com/reportEmbed?reportId=583751e0-a0ab-46ee-9722-f9c1fce34a4d&autoAuth=true&ctid=455c3f1c-0a92-4f6d-8943-26ee08301ad0',
    ),

    /*
    |--------------------------------------------------------------------------
    | Schema do BI no MySQL
    |--------------------------------------------------------------------------
    |
    | Onde moram as tabelas de referência (IBGE, de-para de município, potencial)
    | e as views `vw_bi_*` que o Power BI lê. Fica no MESMO servidor do app, num
    | schema à parte, para o usuário `bi_leitura` enxergar só o que precisa.
    |
    | ⚠️ A suíte usa `bi_test` (forçado no phpunit.xml). O schema não é apagado pelo
    | `migrate:fresh` — só o banco padrão é —, então dev e teste precisam de schemas
    | DIFERENTES, senão cada `php artisan test` recriaria as tabelas do BI do dev
    | vazias. A trava do tests/TestCase.php exige "test" neste nome também.
    |
    */

    'schema' => env('BI_DB_SCHEMA', 'bi'),

];
