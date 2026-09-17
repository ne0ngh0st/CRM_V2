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
        'https://app.powerbi.com/reportEmbed?reportId=0d7a10a1-58cc-489c-af50-51fb51bc5fe1&autoAuth=true&ctid=455c3f1c-0a92-4f6d-8943-26ee08301ad0',
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

    /*
    |--------------------------------------------------------------------------
    | Refresh do dataset depois de cada importação do TOTVS
    |--------------------------------------------------------------------------
    |
    | Quando o `totvs:atualizar` termina em `sucesso`, o CRM pede ao Power BI que
    | atualize o dataset (API REST, service principal do Entra ID). Detalhe em
    | docs/power-bi.md.
    |
    | ⚠️ Nasce DESLIGADO. Só ligar depois que o dataset novo (lendo o RDS) estiver
    | publicado e o service principal for membro do workspace — ligado antes disso,
    | cada importação gera um passo vermelho de 401/404 em /atualizacoes.
    |
    | ⚠️ `client_secret` é segredo: só no .env do servidor, nunca versionado. Tudo lido
    | por config(), nunca env() no código (config cacheado em produção).
    |
    */

    'refresh' => [
        'habilitado' => (bool) env('POWERBI_REFRESH_HABILITADO', false),

        'tenant_id' => (string) env('POWERBI_TENANT_ID', ''),
        'client_id' => (string) env('POWERBI_CLIENT_ID', ''),
        'client_secret' => (string) env('POWERBI_CLIENT_SECRET', ''),
        'workspace_id' => (string) env('POWERBI_WORKSPACE_ID', ''),
        'dataset_id' => (string) env('POWERBI_DATASET_ID', ''),

        'timeout' => (int) env('POWERBI_TIMEOUT', 20),

        /*
        | Trava de cota. A licença Pro aceita 8 refreshes por dataset por dia,
        | contando os agendados no próprio Serviço. 6 deixa margem para um refresh
        | manual de emergência pelo portal. Contado numa janela MÓVEL de 24 h, que
        | respeita o limite qualquer que seja o horário de virada da Microsoft.
        |
        | ⚠️ Desligar o refresh agendado do dataset no Serviço: senão ele consome a
        | mesma cota sem esta trava saber.
        */
        'max_por_dia' => (int) env('POWERBI_REFRESH_MAX_POR_DIA', 6),
        'intervalo_minutos' => (int) env('POWERBI_REFRESH_INTERVALO_MINUTOS', 90),
    ],

];
