<?php

/*
 * Consulta do cartão CNPJ em APIs públicas (sem chave, sem SLA).
 *
 * As fontes são tentadas NA ORDEM: a primeira que responder com um cartão válido
 * vence. BrasilAPI e minhareceita servem a mesma base (o arquivo mensal da Receita,
 * com 30-45 dias de atraso); a CNPJá é independente e às vezes mais fresca — e é a
 * mais restrita (5 consultas/min no plano aberto), por isso fica por último.
 *
 * ⚠️ Nenhuma delas serve para consulta em MASSA. Enriquecer a carteira inteira é o
 * download da base da Receita, não este caminho.
 */
return [
    'fontes' => array_filter(explode(',', env('RECEITA_CNPJ_FONTES', 'brasilapi,minhareceita,cnpja'))),

    // Por fonte. O pior caso (todas caindo por timeout) fica em ~3x isto, e só
    // acontece quando as três estão fora — o modal mostra carregando, não trava a tela.
    'timeout_segundos' => (int) env('RECEITA_CNPJ_TIMEOUT', 4),

    // A Receita publica uma vez por mês: reconsultar antes disso não traz nada novo.
    'validade_dias' => (int) env('RECEITA_CNPJ_VALIDADE_DIAS', 30),

    /*
     * "Solicitar inativação" (Cartão CNPJ da Carteira). Desligado = EM MANUTENÇÃO
     * (decisão do Tony, 2026-10-07): o botão aparece desabilitado, a rota recusa com 503
     * e a lista das 18h não sai. Pedido já registrado fica pendente e vai na primeira
     * lista depois de religar.
     */
    'inativacao_habilitada' => (bool) env('RECEITA_INATIVACAO_HABILITADA', false),

    /*
     * Base aberta de CNPJ da Receita (carga mensal, `receita:importar-situacoes`).
     * Publicada num compartilhamento público do Nextcloud do SERPRO; o "token" é o id
     * do link público, não uma credencial. Mudou de endereço em 2025 — se a carga
     * começar a dar 404, conferir em https://arquivos.receitafederal.gov.br.
     */
    'base' => [
        'webdav' => env('RECEITA_BASE_WEBDAV', 'https://arquivos.receitafederal.gov.br/public.php/webdav'),
        'token' => env('RECEITA_BASE_TOKEN', 'YggdBLfdninEJX9'),
        'arquivos' => 10, // Estabelecimentos0.zip … Estabelecimentos9.zip
        // Um zip por vez (~2,3 GB no maior), apagado depois de varrido.
        'diretorio' => env('RECEITA_BASE_DIRETORIO', storage_path('app/receita')),
    ],
];
