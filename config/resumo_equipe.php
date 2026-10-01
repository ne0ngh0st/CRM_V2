<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Resumo diário da equipe por e-mail
    |--------------------------------------------------------------------------
    |
    | Interruptor do ENVIO AGENDADO (dias úteis às 18:15). Nasce desligado: quem
    | recebe se marca na tela Equipe, e o agendamento só passa a mandar quando
    | isto virar true. O comando `resumo-equipe:enviar --para=...` funciona com
    | ele desligado — é o caminho para o primeiro envio de validação.
    |
    */

    'habilitado' => (bool) env('RESUMO_EQUIPE_HABILITADO', false),

    /*
    |
    | Preenchido, TODO resumo agendado vai só para este endereço, com o
    | destinatário real no assunto — mesmo desenho de CADASTROS_REDIRECIONAR_PARA.
    | Vazio (o padrão) = cada gestor recebe o seu.
    |
    | ⚠️ Lido sempre por config(), nunca por env(): com o config cacheado em
    | produção, env() devolve null e a proteção sumiria justamente onde protege.
    |
    */

    'redirecionar_para' => env('RESUMO_EQUIPE_REDIRECIONAR_PARA'),

    /*
    |
    | Ordem das equipes no consolidado, pelo cod_vendedor do gestor (o e-mail o
    | usuário pode trocar no perfil; o código não). Pedido do Tony, 2026-10-01:
    | Américo, Sandra, Cleber, Wellington — as demais vêm depois, pela venda do dia.
    |
    */

    'ordem_equipes' => [
        '010389', // Américo
        '000115', // Sandra
        '000006', // Cleber
        '010650', // Wellington
    ],

];
