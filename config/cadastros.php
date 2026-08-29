<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirecionamento dos e-mails de solicitação
    |--------------------------------------------------------------------------
    |
    | Quando preenchido, TODA solicitação de cadastro (bobina, etiqueta e cliente)
    | vai para este endereço em vez de ir para o PCP e o Cadastro. O assunto ganha
    | um prefixo dizendo para quem teria ido de verdade, então dá para conferir o
    | roteamento sem incomodar ninguém.
    |
    | Vazio (o padrão) = os e-mails vão para os setores reais.
    |
    | ⚠️ Isto NÃO afeta a recuperação de senha nem qualquer outro e-mail do
    | sistema — só as solicitações de cadastro. Reset de senha sempre vai para o
    | usuário que pediu, que é o comportamento correto inclusive em teste.
    |
    | ⚠️ Existe porque mandar teste para inbox de time real já aconteceu aqui uma
    | vez, em 2026-08-28, e não deve acontecer de novo. Trocar o valor no .env e
    | rodar `php artisan config:cache` é mais seguro que editar constante em
    | código e lembrar de reverter depois.
    |
    */

    'redirecionar_emails_para' => env('CADASTROS_REDIRECIONAR_PARA'),

];
