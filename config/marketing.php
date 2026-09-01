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

    /*
    |--------------------------------------------------------------------------
    | Assuntos do formulário que viram lead comercial
    |--------------------------------------------------------------------------
    |
    | O "Fale Conosco" do site (CF7 id 83) é geral: o select `assunto` oferece
    | Orçamentos, SAC, Compras, Licitação, Ouvidoria e Outros. O CRM-V2 é SÓ
    | comercial (Regra de ouro nº 2) — SAC e Licitação têm sistema próprio, e
    | jogá-los no funil do vendedor é poluir a carteira com chamado de suporte.
    |
    | O que fica de fora NÃO se perde: a captura continua guardada inteira em
    | marketing_wp_leads_raw com o motivo na coluna `erro`, e o e-mail do site
    | para o marketing sai para todos os assuntos de qualquer jeito — ele nem
    | passa pelo CRM.
    |
    | Comparação normalizada (minúsculas, sem acento), então 'orcamentos' casa
    | com "Orçamentos". Lista vazia = não filtra nada, tudo vira lead.
    |
    | ⚠️ O filtro só age quando o formulário TEM campo de assunto. Form sem
    | esse campo (a newsletter do rodapé, por exemplo) passa direto — senão
    | qualquer formulário novo nasceria bloqueado sem ninguém entender por quê.
    |
    */

    'assuntos_comerciais' => ['orcamentos', 'compras'],

    /*
    |--------------------------------------------------------------------------
    | Assuntos que NUNCA viram lead
    |--------------------------------------------------------------------------
    |
    | ⚠️ Existe uma segunda lista, e não é redundância: ela decide o que fazer
    | com um assunto que não está em NENHUMA das duas.
    |
    | Enquanto a regra era só a lista de permitidos, qualquer valor
    | irreconhecível caía fora — inclusive um "Orçamentos" que chegasse com o
    | `ç` corrompido, ou uma opção nova que o marketing adicionasse ao form. Um
    | lead comercial de verdade sumia sem ninguém saber, que é o erro caro.
    |
    | Com as duas listas: o que está aqui é bloqueado, o que está em
    | `assuntos_comerciais` passa, e o DESCONHECIDO passa (com aviso no log).
    | Perder um orçamento custa venda; deixar entrar um assunto estranho custa
    | um lead a mais para o vendedor ignorar.
    |
    */

    'assuntos_nao_comerciais' => ['sac', 'licitacao', 'ouvidoria', 'outros'],

];
