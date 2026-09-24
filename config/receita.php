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
];
