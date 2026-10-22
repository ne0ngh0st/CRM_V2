<?php

/*
|---------------------------------------------------------------------------
| Cotação — lista do cliente × estoque de revenda
|---------------------------------------------------------------------------
|
| O motor é Python (motor/cotacao/cli.py, o mesmo do antigo Cotacao.exe) e roda
| num venv fora do repositório, criado pelo docker/dev/Dockerfile e pelo
| infra/provisionar-servidor.sh no MESMO caminho.
|
| ⚠️ Sempre via config(), nunca env(): com o config cacheado em produção env()
| devolve null.
|
*/

return [

    'python' => env('COTACAO_PYTHON', '/opt/cotacao-venv/bin/python'),

    'cli' => base_path('motor/cotacao/cli.py'),

    // A lista do Almox (589 itens × 4 mil SKUs) leva ~7 s num Python glibc e ~43 s no
    // Alpine do Docker de dev (o rapidfuzz para musl é bem mais lento). O teto cobre o
    // pior caso sem deixar um processo preso para sempre.
    'timeout' => (int) env('COTACAO_TIMEOUT', 300),

    'max_upload_kb' => 10240,

    // Planilha da conferência parada em "processando" além disto é tratada como
    // interrompida (worker morto, deploy no meio). Mesmo desenho das exportações.
    'minutos_travada' => 60,

];
