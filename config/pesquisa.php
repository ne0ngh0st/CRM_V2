<?php

/*
 * Pesquisa de mercado da planilha Supermercadista: faturamento que não está no ranking
 * da ABRAS é buscado na web. A chave fica vazia até alguém preencher — o comando
 * `pesquisa:faturamento-mercado` recusa rodar sem ela, em vez de inventar número.
 */
return [
    'firecrawl_key' => env('FIRECRAWL_API_KEY'),
    'firecrawl_url' => env('FIRECRAWL_API_URL', 'https://api.firecrawl.dev/v1/search'),
];
