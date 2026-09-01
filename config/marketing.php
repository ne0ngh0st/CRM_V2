<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook de leads do WordPress
    |--------------------------------------------------------------------------
    |
    | Segredo compartilhado com o site. O plugin do WordPress tem UM campo (a
    | URL), então ele viaja como `?token=…` na própria URL. Header
    | (`Authorization: Bearer` / `X-Webhook-Token`) continua aceito para o dia
    | em que a origem mudar. Sem valor, o endpoint
    | recusa com 503 (webhook_not_configured) ANTES de gravar — é o interruptor
    | de desligar: apagar o segredo desliga a captura sem tirar o código do ar.
    |
    | Dono comercial NÃO mora aqui. Mora em marketing_wp_formularios
    | (identificador `*` = fallback, hoje 010617). Form novo = nova linha.
    |
    | ⚠️ Ler por config(), nunca env() no app: em produção o config está
    | cacheado e env() devolveria null exatamente onde protege.
    |
    */

    'wp_webhook_secret' => env('WP_LEADS_WEBHOOK_SECRET', ''),

];
