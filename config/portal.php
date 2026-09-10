<?php

/**
 * Integração "orçamento vira pedido" — Portal Autopel (SIC).
 *
 * ⚠️ TUDO AQUI É LIDO POR config(), NUNCA POR env() DIRETO no código da aplicação.
 * Com o config cacheado em produção (`config:cache`), env() devolve null — e a
 * consequência aqui não é erro visível, é o token sumir e todo envio virar 401.
 * Mesma armadilha já documentada em config/cadastros.php.
 *
 * Análise completa da integração, de-para e armadilhas: docs/integracao-portal-pedidos.md
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Interruptor mestre
    |---------------------------------------------------------------------------
    | Desligado, o botão "Transformar em pedido" não aparece e o endpoint recusa.
    | Nasce DESLIGADO de propósito: a feature só liga depois de homologada, e
    | ligar por engano cria pedido de verdade no Portal.
    */
    'habilitado' => (bool) env('PORTAL_PEDIDOS_HABILITADO', false),

    /*
    |---------------------------------------------------------------------------
    | Endereço e credencial
    |---------------------------------------------------------------------------
    | 🚨 O DEFAULT É HOMOLOGAÇÃO, e isso não é descuido — é a proteção.
    | Se um dia existir uma URL de produção, ela entra no .env do servidor de
    | produção e em lugar nenhum mais. Nunca versionar token.
    */
    'base_url' => rtrim((string) env('PORTAL_PEDIDOS_URL', 'https://api-portal.autopel.com'), '/'),
    'token' => (string) env('PORTAL_PEDIDOS_TOKEN', ''),
    'timeout' => (int) env('PORTAL_PEDIDOS_TIMEOUT', 20),

    /*
    |---------------------------------------------------------------------------
    | O IPI vai embutido no unitPrice?
    |---------------------------------------------------------------------------
    | 🚨 PERGUNTA EM ABERTO COM O MARCELO (10/09/2026). Errar aqui custa 3,25%
    | em TODO pedido, e o `201` volta bonito do mesmo jeito — ninguém percebe.
    |
    | O default é `false` (manda SEM IPI) por causa da evidência de schema:
    | `autopel_sic.products` tem `ipi`, `ipi_rate`, `iva_st`, `icms_rate` e `ncm`,
    | ou seja, o Portal guarda a tributação por produto e tem tudo para calcular
    | o imposto sozinho. Evidência é hipótese, não confirmação — por isso isto é
    | um interruptor de uma linha, e não uma decisão espalhada pelo código.
    |
    | Como confirmar em homologação: enviar um orçamento com item que participa
    | de IPI e comparar o total que o Portal calcula com o total do orçamento.
    */
    'preco_com_ipi' => (bool) env('PORTAL_PEDIDOS_PRECO_COM_IPI', false),

    /*
    |---------------------------------------------------------------------------
    | Tipos de nota (invoiceTypeId) — constantes da API, não vêm de tabela nossa
    |---------------------------------------------------------------------------
    | ⚠️ Regra da API: um pedido NÃO aceita itens de Venda (Consumo) e Venda
    | (Revenda) ao mesmo tempo — responde 409. E Serviço só é aceito para
    | produto do grupo 3 no Portal.
    */
    'tipos_nota' => [
        'servico' => 1,
        'venda_consumo' => 2,
        'remessa' => 3,
        'venda_revenda' => 4,
    ],

    /*
    | Tipo de nota usado quando o item não diz outra coisa. O CRM-V2 não tem
    | campo de tipo de nota por item hoje; quando tiver, ele manda aqui.
    */
    'tipo_nota_padrao' => (int) env('PORTAL_PEDIDOS_TIPO_NOTA_PADRAO', 2),

];
