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
    | ✅ RESPONDIDO pelo time do Portal em 14/09/2026: **"o preço deve vir já com
    | IPI"**. Ou seja, o `unitPrice` leva o `valor_unitario` do orçamento como
    | está — o CRM já o guarda com os 3,25% embutidos.
    |
    | ⚠️ E a resposta foi o OPOSTO do que a evidência de schema sugeria. Havia um
    | palpite razoável em contrário (`autopel_sic.products` tem `ipi`, `ipi_rate`,
    | `iva_st`, `icms_rate` e `ncm`, então o Portal teria como calcular sozinho) —
    | e ele estava errado. É o melhor argumento possível para isto ter nascido
    | como interruptor de uma linha em vez de regra espalhada pelo código: a
    | correção custou trocar um default, não caçar `/1.0325` em cinco arquivos.
    |
    | 🚨 Continua valendo conferir no PRIMEIRO pedido real: comparar o total que o
    | Portal calcula com o total do orçamento. Se vier 3,25% acima, é sinal de que
    | eles aplicam o `ipi_rate` do produto POR CIMA do que mandamos — e aí a
    | resposta muda de novo.
    */
    'preco_com_ipi' => (bool) env('PORTAL_PEDIDOS_PRECO_COM_IPI', true),

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
