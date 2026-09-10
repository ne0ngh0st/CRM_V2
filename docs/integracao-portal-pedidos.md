# Integração CRM-V2 → Portal Autopel (orçamento vira pedido)

> **Estado em 2026-09-10: CONSTRUÍDA e no ar DESLIGADA.** O código está em produção com o
> interruptor `PORTAL_PEDIDOS_HABILITADO` ausente do `.env` de lá — a rota responde 404 e o
> botão não existe. **Ver §7** para o que foi construído e como ligar.
>
> Falta uma coisa só para homologar: **dado nas tabelas `portal_*`**, que hoje estão vazias.
> O que o Marcelo precisa mandar está na §4.5.
>
> 🚨 **E uma pergunta ainda sem resposta que é PRÉ-REQUISITO, não curiosidade**: ninguém
> confirmou se o "homolog" do Portal compartilha banco com produção. Enquanto isso não for
> respondido, o primeiro envio bem-sucedido pode criar pedido de verdade no SIC. Nenhum
> pedido foi criado até aqui — as 24 sondagens todas voltaram 4xx, e isso foi verificado
> ativamente reenviando a chave de idempotência (§4.5).
>
> As §§1-6 são a análise original (2026-09-09) da documentação recebida do time do Portal
> (`docs/API-Pedidos-Autopel.pdf`) cruzada com o schema real do CRM-V2.

O que o time do Portal entregou: o endpoint de **criação de pedidos**, em homologação, com
token. E fez uma pergunta que ainda não foi respondida:

> *"Você pode me passar exatamente o que você precisaria consultar no banco de dados?
> Porque dependendo do que você precisa, talvez o ideal seja criar outros endpoints ao invés
> de passar o acesso ao BD. Evita que sua aplicação quebre caso a gente altere o schema."*

**A pergunta deles é a parte mais importante da integração**, e a resposta está na §4. Eles
estão certos: acesso ao banco deles seria o mesmo erro que o CRM-V2 já não comete com o
TOTVS (a Regra de ouro nº 4 existe justamente porque espelho de leitura é melhor que
acoplar em schema alheio).

---

## 1. Onde isso encaixa

O caminho comercial completo, como o Tony descreveu:

```
CRM-V2 (/orcamentos)  →  Portal Autopel (SAC/SIC)  →  TOTVS
   orçamento aprovado        pedido rascunho          pedido de fato
```

O pedido criado pela API **entra no Portal como rascunho**, no mesmo estado de um pedido
digitado à mão. Ele **não segue para aprovação automaticamente** — alguém da Autopel revisa
e finaliza no Portal, e é lá que frete, transportadora, data de entrega e previsão de
faturamento são definidos. Só depois disso ele vai para o ERP.

⚠️ **Consequência de escopo, e ela é boa:** o CRM-V2 não precisa (nem consegue) responder
"esse pedido foi faturado?". Ele empurra o rascunho e guarda o `id` devolvido. Quem sabe do
resto é o Portal — e o dado volta pro CRM pelo caminho que já existe, os relatórios do TOTVS
(`totvs:import-pedidos-abertos`). **Não inventar sincronismo de status por aqui.**

## 2. O contrato, em uma tela

| | |
|---|---|
| Base (homolog) | `https://api-portal.autopel.com/` |
| Endpoint | `POST /v1/api/orders` |
| Auth | `Authorization: Bearer <token>` (64 hex; o Portal guarda só o SHA-256) |
| Idempotência | header `Idempotency-Key`, **obrigatório**, 8-200 caracteres |
| Formato | `application/json`, validação **estrita** (campo desconhecido → 400) |
| Resposta | `201` + `{ payload: { id, clientId, clientCompanyName } }` |

**Corpo:** `clientId`, `clientRepresentativeId`, `deliveryClientId`, `createdBy`, `products[]`
(obrigatórios) + `shippingType` (`CIF`/`FOB`), `orderReference`, `orderNote`, `invoiceNote`,
`logisticsNote` (opcionais).

**Item:** `productId`, `quantity` (inteiro ≥ 1), `unitPrice` (**em centavos**) obrigatórios
+ `invoiceTypeId`, `notes`, `orderReference`, `orderLine` opcionais.

**Tipos de nota:** `1` Serviço · `2` Venda (Consumo) · `3` Remessa · `4` Venda (Revenda).

## 3. As três armadilhas do contrato

Não são opinião minha — a própria documentação deles chama a atenção para as duas primeiras.

### 3.1 🚨 `unitPrice` é em CENTAVOS, e enviar reais NÃO dá erro

Mandar `12.5` para um item de R$ 12,50 grava o pedido com **doze centavos e meio**. O campo
é aceito sem erro. É o mesmo formato de falha que este projeto já conhece de cor: dado
errado que não acende luz vermelha (a badge "0 online" de 31/08, a fila parada de 29/08, o
dado velho do TOTVS de setembro). **Teste dedicado obrigatório antes de subir**, comparando
o total do orçamento no CRM com o total que o Portal calcula.

### 3.2 🚨 Retentativa com chave NOVA é o único caminho que ainda duplica pedido

A proteção depende de o CRM **repetir exatamente a mesma `Idempotency-Key`**. Ou seja: a
chave tem que ser **gerada uma vez e persistida no orçamento**, nunca gerada no momento do
envio. Se o job tentar de novo com `Str::uuid()` fresco, nasce um segundo pedido no Portal —
e ninguém no CRM fica sabendo.

Regras que valem a pena ter em mente:
- mesma chave + mesmo conteúdo → `201` com o mesmo `id` e header `Idempotent-Replay: true`;
- mesma chave + conteúdo diferente → **`409`** (inclusive trocar só o `createdBy`);
- a comparação é do **conteúdo**, não do texto — reordenar itens continua sendo o mesmo pedido;
- a chave não expira, e vale por credencial;
- `409 "…um pedido que não existe mais"` = alguém apagou o pedido no Portal; aí sim, chave nova.

### 3.3 Erro significa nada gravado

Qualquer resposta de erro garante que nem pedido nem itens foram criados. Não existe pedido
pela metade para limpar — o que simplifica bastante o tratamento no job. Timeout/500 são
seguros para retentar **com a mesma chave**.

## 4. 🔴 O buraco central: o CRM não tem NENHUM id do Portal

A API identifica tudo por **id interno do Portal** — cliente, representante, produto,
usuário. E a documentação é explícita:

> *"Não há endpoints de listagem disponíveis para integrações — nem de clientes, nem de
> produtos. Os ids necessários precisam ser combinados com a Autopel antes de integrar."*

O CRM-V2 identifica tudo por **código do TOTVS**:

| API do Portal | O que o CRM-V2 tem hoje | Tem como resolver hoje? |
|---|---|---|
| `clientId` | `clientes.cod_cliente` + `clientes.loja`, `clientes.cnpj` | ❌ não |
| `deliveryClientId` | idem (o CRM não modela endereço de entrega alternativo) | ❌ não |
| `clientRepresentativeId` | — (o CRM não tem contatos do cliente) | ❌ não, e nem sei o que é (§6.1) |
| `createdBy` | `users.id` (do CRM) e `vendedor_perfis.cod_vendedor` (TOTVS) | ❌ não |
| `productId` | `produtos.cod_produto` (26.989 produtos) | ❌ não |
| `invoiceTypeId` | — | ✅ são 4 constantes, dá pra chumbar |
| `shippingType` | `orcamentos.tipo_frete` (`CIF`/`FOB`) | ✅ já existe, campo obrigatório na tela |

**Sem de-para, a integração não sai do lugar.** E de-para mantido à mão em planilha não
sobrevive: são 26.989 produtos e 92 mil clientes, e ambos mudam sozinhos pelo TOTVS.

### 4.1 Resposta recomendada ao time do Portal

Não é acesso ao banco. É um punhado de endpoints de **resolução por código do TOTVS** — que
é exatamente o que eles propuseram ("talvez o ideal seja criar outros endpoints"), e o que
protege os dois lados de mudança de schema:

1. **`GET /v1/api/clients?code=<COD_CLIENTE>&store=<LOJA>`** — devolve `id` do Portal, se o
   cadastro está concluído (não-prospect), se tem código e loja preenchidos, e os
   representantes vinculados (`id` + nome). O par código+loja é a chave real do cliente no
   TOTVS e é o que o CRM-V2 usa como grão da tabela `clientes` (por CNPJ não serve: CNPJ se
   repete entre filiais — é bug documentado do legado). Aceitar busca por CNPJ como
   alternativa é bom, mas o par é o que precisa funcionar.
2. **`GET /v1/api/products?code=<COD_PROD>`** — devolve `id`, unidade, **fator e tipo de
   conversão** e o **grupo** (o grupo 3 é o que aceita nota de serviço). O fator é
   indispensável: sem ele, o CRM só descobre que a quantidade é inválida quando o pedido é
   recusado com 400, na cara do vendedor.
3. **`GET /v1/api/users?email=<e-mail corporativo>`** — devolve o `id` do vendedor no
   Portal e se está ativo. O e-mail `@autopel.com` é a única chave que existe dos dois
   lados hoje.
4. *(desejável)* **algum jeito de baixar o catálogo em lote** — um `GET` paginado de
   produtos/clientes atualizados desde uma data, para o CRM manter espelho local e não
   fazer três chamadas HTTP no meio de cada envio de pedido. Sem isso, dá para viver com
   cache; mas aí um pedido depende de o Portal estar de pé no momento exato do clique.

Vale mandar junto o contexto do porquê: o CRM-V2 já mantém espelho de leitura do TOTVS e
**não escreve em banco de terceiro** — a preferência por endpoint é a mesma dos dois lados.

### 4.2 🟢 Existe um caminho que já está no ar: a Integrador API

Descoberto em 2026-09-09, depois da análise acima, a partir do zip
`autopel-integrador-api-v1.8-p0-filtros-avancados.zip` que o Tony recebeu. **É outra API,
não a de pedidos** — foi construída para o projeto do Lovable (portal de acompanhamento de
pedidos) e é **somente leitura** sobre os bancos da Autopel.

**Verificado por chamada real ao endpoint público `/health` em 2026-09-09** (não por leitura
de código — a lição de 29/08 vale aqui):

```
https://api-integrador.autopel.com/health  →  200
{"status":"ok","version":"1.9.0","uptime_seconds":1321399,
 "connections":[{"id":"sac"},{"id":"b2b"},{"id":"easy"},{"id":"sic"}]}   // todos reachable
```

Quatro bancos MySQL de pé, ~15 dias de uptime. ⚠️ **O zip é a v1.8.0 e o que está no ar é a
v1.9.0** — o código em mãos está uma versão atrás, então conferir o `/docs` (Swagger) antes
de confiar em qualquer detalhe daqui.

**O que essa API dá** (tudo `Authorization: Bearer <API_TOKEN>`, tudo `SELECT`, sem escrita):

| Endpoint | Serve para |
|---|---|
| `GET /metadata/connections` | quais bancos existem, com host/database/usuário |
| `GET /metadata/tables` | **todas as tabelas e views dos 4 bancos** |
| `GET /metadata/tables/:id/columns` | colunas, tipos, PK, FK |
| `GET /metadata/relationships` | as FKs — o mapa de relacionamento pronto |
| `GET /sample/:conn/:schema/:table` | 10 linhas de amostra (teto rígido de 10) |
| `GET·POST /operacional/consulta` | **SELECT parametrizado em qualquer tabela dos 4 bancos** |

O `/operacional/consulta` é o que interessa: escolhe conexão/schema/tabela, lista de campos,
filtros (`eq`, `in` até 1000 valores, `in_tuple` para chave composta, `between`, `like`,
`is_null`…), ordenação e paginação — até **1000 linhas por página**. Não aceita SQL livre:
tabela, campos e filtros são validados contra os metadados antes de montar a query.

**Isso é, na prática, "ver o banco deles" — só que read-only, paginado e sem credencial de
banco na nossa mão.** Se o banco do Portal for uma dessas quatro conexões (o `sic` é o
candidato óbvio: o Tony descreve o caminho como *"passa pelo SAC/SIC depois pro TOTVS"*),
o de-para da §4 deixa de depender de eles construírem endpoint novo — dá para ler
`id` × código do TOTVS direto e montar espelho local, no mesmo molde dos `totvs:import-*`.

#### Quem é o dono, e o que está a caminho

**Marcelo Lorenzi** é o dono do projeto do integrador. Em 2026-09-09 ele combinou com o Tony
passar **o `API_TOKEN`, o acesso ao Lovable e a versão nova** (a v1.9.0 que já está no ar).

⚠️ **Não é preciso conta no Lovable para usar a API.** O Lovable é o front que consome o
integrador; o integrador é HTTP com `Authorization: Bearer`. Assim que o token chegar dá para
trabalhar por `curl` e depois pelo Laravel — o acesso ao Lovable só serve para ver o projeto
dele por dentro, não é dependência.

#### O que falta para confirmar (nesta ordem)

1. **Conseguir o `API_TOKEN` do integrador** com o Marcelo. Sem ele só o `/health` responde.
2. **`GET /metadata/connections` + `GET /metadata/tables`** → descobrir qual das quatro
   conexões é o banco por trás do `api-portal.autopel.com`, e se as tabelas de cliente,
   produto, usuário e representante estão lá com os ids que o `POST /v1/api/orders` espera.
3. **Casar um caso real**: pegar um cliente conhecido pelo `cod_cliente`+`loja` e conferir
   que o `id` encontrado é o mesmo que o endpoint de pedidos aceitaria como `clientId`.
   ⚠️ Sem esse passo, "achei uma tabela com id e código" é suposição, não de-para.

#### ⚠️ Três ressalvas que não podem ser esquecidas

- **Isto NÃO substitui o pedido da §4.1, e é importante entender por quê.** A preocupação
  que eles levantaram — *"evita que sua aplicação quebre caso a gente altere o schema"* —
  continua valendo inteira: ler as tabelas deles por HTTP é o mesmo acoplamento, só que por
  outro transporte. O uso certo aqui é **destravar agora e descobrir o schema**, para que o
  pedido de endpoint que a gente mandar seja preciso ("exponham a resolução destas 3
  chaves") em vez de genérico. E, se ficar em produção, o acoplamento tem que morar num
  serviço só do lado do CRM (Regra de ouro nº 8), nunca espalhado.
- **O token é único e global.** O `middleware/auth.ts` compara uma string só: quem tem o
  token lê **os quatro bancos inteiros** (SAC, B2B, EASY, SIC). Não há escopo por integração
  nem por conexão. Se o CRM-V2 passar a usar isso, ele passa a carregar acesso de leitura a
  todos os sistemas da empresa. **Vale pedir um token dedicado** — e saber que hoje isso
  exige mudança no código deles ou uma segunda instância, porque a API só conhece um.
- **Não é contrato estável.** Foi feita para outro projeto, mudou 8 vezes em 8 versões
  (v1.3 a v1.9), várias delas corrigindo timeout. Não construir nada crítico em cima sem
  combinar com eles que o CRM-V2 também é cliente dessa API.

#### 🔎 Achado colateral que pode valer mais que a integração

A view `sac.sacautopel.ConsultaBasePedidos` tem **status estruturado** e datas de verdade:
`status`, `data_bip`, `data_embarque`, `previsao_entrega`, `previsao_faturamento`,
`dt_emissao`, `nota_fiscal`, `transportadora`. Existe até `/operacional/pedidos/{n}/timeline`
pronto.

Em 2026-09-09 o CRM passou a derivar a etapa do pedido **lendo frases** do campo `HISTORICO`
do relatório do TOTVS (`StatusPedidoResolver`, 11 moldes cobrindo 99,86%), e está registrado
lá que a defesa contra o TOTVS mudar a redação é frágil — um aviso no import. **Se essa view
tiver o status estruturado do mesmo pedido, ela é a fonte que o `pedido-dados-adriano.md`
está pedindo há meses.** Também é candidata a fonte do histórico de pedidos emitidos que
está pendente (2025 inteiro).

⚠️ **Não é decisão minha e nem é óbvia**: mexe com a Regra de ouro nº 2 (SAC tem sistema
próprio). Ler *dado de pedido comercial* de uma view não é portar feature de SAC, mas a
chamada é do Tony. Fica registrado como pergunta.

### 4.3 🟢 O DE-PARA FOI ENCONTRADO — 2026-09-10

**O Portal é o `sic`.** Não por dedução: por estrutura lida do próprio banco.

**Como**: o projeto compartilhado do Lovable ("Order Insight Portal", app *"Pedidos ·
Logística"*) tem uma página **`/descoberta` — "Descoberta de Bancos"** que é, ela mesma, um
explorador de metadados sobre a Integrador API. O Tony autenticou no app e os metadados
foram lidos por ali. **Não precisou do `API_TOKEN` do nosso lado** — o app o guarda no
servidor (`INTEGRADORA_API_TOKEN`, secret).

Inventário: **4 conexões, 549 tabelas, 23 views** — `SAC` (`sacautopel`), `B2B`
(`autopel_b2b`), `EASY` (`easyb2b_autopel`) e **`MySQL SIC` (`autopel_sic`)**.

`autopel_sic` tem `orders`, `order_products`, `quotations`, `quotation_products`,
`clients`, `clients_representatives`, `products`, `invoice_types`, `users` — exatamente as
entidades do `POST /v1/api/orders`. É o Portal.

#### O mapa

| Campo da API | Tabela | id | Chave que casa com o CRM-V2 |
|---|---|---|---|
| `clientId` | `autopel_sic.clients` | `id` | **`code` + `store`** ↔ `clientes.cod_cliente` + `loja` |
| `deliveryClientId` | idem | `id` | idem |
| `clientRepresentativeId` | `autopel_sic.clients_representatives` | `id` | par (`client_id`, `user_id`) |
| `createdBy` | `autopel_sic.users` | `id` | **`email`** ou **`protheus_seller_code`** ↔ `vendedor_perfis.cod_vendedor` |
| `productId` | `autopel_sic.products` | `id` | **`code`** ↔ `produtos.cod_produto` |
| `invoiceTypeId` | `autopel_sic.invoice_types` | `id` | as 4 constantes documentadas |

Amostra real de `autopel_sic.clients` que sustenta a linha do cliente:

```
id | code   | store | autopel_code | seller_one | document
 5 | 000002 | 0001  | 0594788      | 010150     | 07103681000162
 6 | 000002 | 0002  | 0613726      | 000044     | 07103681000243
 8 | 000004 | 0001  | 0587812      | 010241     | 92702067000196
 9 | 000004 | 0002  | 0613814      | 010241     | 92702067000277
10 | 000004 | 0003  | 0613816      | 010241     | 92702067000358
```

Mesmo `code`, três `store`, três CNPJ com sufixo diferente = matriz + filiais. É
`A1_COD`/`A1_LOJA` do Protheus, o mesmo grão da nossa `clientes`. E `seller_one` traz
`010150`/`000044`/`010241` — o formato exato de `vendedor_perfis.cod_vendedor`.

#### ⚠️ Quatro armadilhas deste mapa

1. **NÃO usar `autopel_code` nem `external_id` como chave.** `clients` tem TRÊS colunas
   parecidas com código (`code`, `autopel_code`, `external_id`) e DUAS parecidas com loja
   (`store`, `branch`). `autopel_code` tem 7 dígitos e é **nulo em parte das linhas**;
   `external_id` é sequencial interno. Quem casa com o TOTVS é **`code` + `store`**.
   `products` repete o mesmo par `code`/`autopel_code` — mesma escolha lá.
2. **`document` vem sem máscara** (`07103681000162`) e a nossa `clientes.cnpj` é
   **mascarada**. Casar por CNPJ exigiria normalizar dos dois lados; por `code`+`store`
   não precisa. Mais um motivo para não usar CNPJ como chave (além da Regra nº 3).
3. **`clients`, `products` e `users` têm `deleted` (tinyint)** — é a origem dos 404
   "inexistente ou excluído" do catálogo de erros. O de-para tem que filtrar isso.
4. ✅ **VERIFICADO contra o `palma_v2` em 2026-09-10 — e a verificação achou um problema.**
   Ver §4.4.

### 4.4 A verificação, e a regra que ela obrigou a criar

Os 9 pares `code`+`store` da amostra do SIC foram procurados na nossa `clientes`.
**Todos os 9 existem.** Em **8 deles** o CNPJ bate dígito a dígito e a razão social é a
mesma:

| `code`+`store` | SIC | `palma_v2` |
|---|---|---|
| 000002 / 0001 | `07103681000162` | `07.103.681/0001-62` RODOPEL TRANSPORTES ✅ |
| 000002 / 0002 | `07103681000243` | `07.103.681/0002-43` RODOPEL TRANSPORTES ✅ |
| 000003 / 0001 | `07667662000169` | `07.667.662/0001-69` SUPERMERCADOS VIGOR ✅ |
| 000004 / 0001·0002·0003 | `92702067000196/277/358` | idem, BANCO DO ESTADO DO RS ✅ |
| 000001 / E001 · X001 | `51244101000149` | idem, ADC BRADESCO ✅ |
| **000001 / 0001** | **WM AUTO PECAS** `10577002000100` | **SANIO MATOS SANTANA**, CPF `917.131.535-72` ❌ |

**O par existe nos dois lados e aponta para empresas DIFERENTES.**

Hipótese (não confirmada): é resíduo de carga inicial do SIC. O `id` 1 é literalmente
`company_name = "ORCAMENTO"`, `legal_name = "CLIENTE PADRAO P/ ORCAMENTO"`; o `id` 2 (o
divergente) é o único da amostra com **`autopel_code` vazio**, enquanto os `id` 3+ têm.
⚠️ **Hipótese não é medição** — a taxa real de divergência na tabela inteira não foi
medida, porque `Ver Amostra` devolve sempre as 10 primeiras linhas (teto do endpoint
`/sample`). Medir isso é trabalho do próprio importador do de-para, contra a tabela
completa.

#### 🚨 A regra que sai daí: o de-para NUNCA confia só em `code`+`store`

Um `clientId` errado **cria pedido para a empresa errada** — e nada no CRM acusaria: o
`201` volta bonito, com um `id` válido. É a pior falha possível desta integração, e é
silenciosa.

**Portanto, obrigatório**: ao resolver o cliente, **conferir também o CNPJ**
(`clients.document` sem máscara × os dígitos de `clientes.cnpj`). Divergiu → **recusa o
envio e sinaliza**, nunca envia "no melhor esforço". Vale o mesmo raciocínio para produto
(`products.code` × `produtos.cod_produto`, conferindo a descrição).

Isso não é zelo: é a única razão pela qual a divergência acima deixa de ser um risco.

#### ⚠️ Correção: `seller_one` NÃO espelha o nosso `cod_vendedor`

Na §4.3 eu apontei `seller_one` como evidência por estar no formato certo
(`010150`, `010241`). O formato está — **os valores não batem**: `000002/0002` traz
`000044` no SIC contra `010723` aqui; `000001/E001` traz `000000` contra `010789`. Um dos
lados está desatualizado.

**Não usar `seller_one` para nada.** O de-para do vendedor é
`users.email` / `users.protheus_seller_code`, que é outra tabela e outro campo. E fica o
aviso: **quem "cuida do cliente" no SIC pode não ser quem cuida no CRM** — se algum dia
alguém quiser cruzar carteira entre os dois sistemas, isso precisa ser medido primeiro.

#### O que mais saiu de graça

- **Fator de conversão**: `products` tem `conversion_factor`, `conversion_type`,
  `unit` e `secondary_unit`. A regra da API (*"tipo `D` e unidade secundária `CX` →
  quantidade múltiplo exato do fator"*) fica **verificável ANTES de enviar**, em vez de o
  vendedor descobrir por um 400 na cara dele.
- **Nota de serviço só no grupo 3**: `products.group` responde isso.
- **`users.active` / `deleted` / `immediate_supervisor_id`** — dá para saber de antemão se
  o `createdBy` vai ser recusado (409 "usuário está inativo").
- **`clients.price_table`, `client_group`, `market_segment`, `payment_condition_code`,
  `carrier`, `freight_type`** — há bem mais coisa ali do que a integração precisa. Não
  ampliar escopo por estar disponível.

---

⚠️ **O de-para está mapeado, mas não verificado.** A §4.1 (pedir os endpoints de resolução)
continua valendo, e agora **fica muito mais fácil de pedir**: em vez de "exponham algo",
é "exponham a resolução de `clients` por `code`+`store`, `products` por `code` e `users`
por `email`, devolvendo o `id`". Ler as tabelas por HTTP destrava agora; endpoint nomeado é
o que sobrevive a um `ALTER TABLE` do lado deles.

## 4.5 Primeira conversa real com o homolog — 2026-09-10

Token de homologação em mãos, o endpoint foi sondado **sem criar nada**. A documentação
garante que *"qualquer resposta de erro garante que nenhum pedido e nenhum item foram
criados"*, e todas as sondagens abaixo pararam em erro de propósito.

**A ordem de validação, descoberta e não suposta:**

```
formato do corpo  →  createdBy  →  clientId  →  clientRepresentativeId  →  produto …
```

Isso é útil porque transforma o endpoint num **oráculo**: a mensagem que volta diz até
onde o payload passou.

| Sondagem | Resposta |
|---|---|
| corpo `{}` | `400` com a lista de campos obrigatórios — **token válido**, ~650 ms |
| ids impossíveis (`999999999`) | `404 Usuário não encontrado` |
| `createdBy` = 1 | avança → **existe** |
| `createdBy` = 2, 7 | `404 Usuário não encontrado` |
| `clientId` = 1, 2, 3, 5, 8, 10, 12 | todos avançam → **existem** |
| `clientRepresentativeId` 1-8 no cliente 1 | `404 Representante não encontrado` |

⚠️ **O homolog NÃO está vazio** — tem cliente de sobra. O que falta é o vínculo
representante↔cliente, exatamente o campo cuja semântica só foi entendida em §6.1.

⚠️ **A pergunta "homolog é o mesmo banco que a Integrador API enxerga?" continua sem
resposta.** Os ids baixos existirem nos dois lados é compatível com as duas hipóteses.
Só o Marcelo responde, e a resposta decide se o de-para lido do `autopel_sic` de produção
vale para testar aqui.

### O payload de um orçamento REAL, ponta a ponta

Orçamento **2093** do banco de desenvolvimento (importado do legado, portanto dado real):
CENTRAL SUPERMERCADOS EIRELI, aprovado, 3 itens com código, modo Serviço.

```json
{ "clientId": 1, "clientRepresentativeId": 1, "deliveryClientId": 1, "createdBy": 1,
  "products": [
    { "productId": 1, "quantity": 900, "unitPrice": 300, "invoiceTypeId": 2, "orderLine": "3227" },
    { "productId": 2, "quantity": 120, "unitPrice": 880, "invoiceTypeId": 2, "orderLine": "3228" },
    { "productId": 3, "quantity": 240, "unitPrice": 920, "invoiceTypeId": 2, "orderLine": "3229" } ],
  "orderReference": "ORC-2093" }
```

**A conferência que importa**: `900×300 + 120×880 + 240×920 = 596.400` centavos =
**R$ 5.964,00**, idêntico ao `valor_total` do orçamento. É assim que se prova que a
conversão para centavos está certa — comparando o total, não olhando um item.

`shippingType` e `orderNote` saíram **omitidos** (o orçamento não tem frete nem
observação), e não como `null`: o corpo é validado de forma estrita.

Resposta do homolog: **`404 Representante não encontrado`** — passou por formato,
`createdBy`, `clientId` e parou no vínculo que falta. É o resultado esperado e é a prova
de que o resto do payload está aceitável para a API.

⚠️ **Gotcha desta máquina, não da API**: JSON inline no `curl` do Git Bash é corrompido e
produz um erro de validação FALSO (`products: Expected number, received nan`). Mandar
sempre por arquivo (`-d @arquivo.json`).

## 5. Lacunas de schema — medidas, não estimadas

Números tirados do `palma_v2` de desenvolvimento em 2026-09-09 (1.864 orçamentos,
2.936 itens):

| Lacuna | Medido | Por que trava |
|---|---|---|
| **`orcamentos` não tem `cliente_id`** — só `cliente_nome` e `cliente_cnpj` texto | 1.858 com CNPJ, mas **440 mascarados e 1.418 só dígitos** na mesma coluna; **348 (18,7%) não casam com nenhum cliente** nem normalizando os dígitos | sem vínculo com `clientes` não há `cod_cliente`+`loja`, logo não há `clientId` |
| **Orçamento pode ser de LEAD** (`orcamentos.lead_id`, desde 03/09) | — | lead não é cliente no Portal, e cliente prospect é **recusado com 409**. Orçamento de lead simplesmente não vira pedido enquanto o cadastro não for concluído no TOTVS |
| **`orcamento_itens.cod_produto` é nullable** | **68 itens sem código** | sem código não há `productId`. E item de etiqueta **nasce sem código de propósito** (é precificado pela calculadora, com `materia_prima_id`/`etiqueta_calc`) — não é dado faltando, é produto que não existe no catálogo |
| **`quantidade` é `decimal(12,2)` e a validação aceita `min:0.01`** | 2 itens fracionados hoje | a API exige **inteiro ≥ 1** e recusa fracionado com 400 |
| **Múltiplo do fator de conversão** (tipo `D` + unidade `CX`) | não verificável daqui | o CRM não conhece o fator; hoje só descobriria pelo 400 |
| **Não há tipo de nota por item** | — | `invoiceTypeId` é opcional, mas a regra "um pedido não aceita Venda (Consumo) e Venda (Revenda) juntos" precisa ser respeitada **antes** de enviar, senão é 409 |
| **`unitPrice` em centavos** | 0 itens com centavo inexato — a conversão `round(valor*100)` é segura hoje | ver §3.1 |

### 5.1 ⚠️ O IPI é a pergunta que ninguém fez ainda

O CRM-V2 guarda `valor_unitario` **com o IPI de 3,25% embutido** quando o item participa de
IPI (`OrcamentoItem::participaIpi()`), e a base sem IPI é sempre derivada, nunca armazenada
(`OrcamentoCalculoService`). A API do Portal **não tem campo de IPI** e calcula os totais
sozinha a partir do `unitPrice`.

**Então qual dos dois valores vai no `unitPrice`?** Se for o com IPI e o Portal também
somar IPI depois, o pedido sai 3,25% mais caro do que o orçamento aprovado — e ninguém
percebe olhando a tela do CRM. Isso precisa ser confirmado com o time do Portal **antes do
primeiro envio real**, e travado por teste comparando total do orçamento × total do pedido
criado. É o mesmo tipo de divergência silenciosa da §3.1, só que com uma causa a mais.

> ⚠️ **Atualização de 2026-09-10 — a pergunta ficou muito mais precisa.** Lendo o schema
> (§4.3), `autopel_sic.products` tem **`ipi`, `ipi_rate`, `iva_st`, `icms_rate` e `ncm`**.
> Ou seja: **o Portal guarda a tributação por produto** e tem tudo para calcular o IPI
> sozinho. Isso torna muito provável que o `unitPrice` deva ir **SEM IPI** — mas
> **provável não é confirmado**, e errar aqui custa 3,25% em cada pedido. A pergunta ao
> Marcelo agora é fechada e verificável: *"o `ipi_rate` do produto é aplicado por cima do
> `unitPrice` que eu mando, ou o `unitPrice` já tem que vir com o imposto embutido?"*

## 6. Decisões em aberto (para o Tony)

1. ~~**O que é `clientRepresentativeId`?**~~ **RESPONDIDO em 2026-09-10, pelo schema.**
   `autopel_sic.clients_representatives` é `id`, `user_id` → `users.id`, `client_id` →
   `clients.id`, mais `department` e `phone`. Ou seja: é a opção **(b)** — a **pessoa da
   Autopel** vinculada àquele cliente, não um contato do lado do cliente.
   - O valor a enviar é o `id` da linha em que `client_id` = o cliente resolvido e
     `user_id` = o usuário do vendedor.
   - ⚠️ **Consequência operacional que vale saber antes do primeiro envio**: se o vendedor
     que montou o orçamento **não estiver cadastrado como representante daquele cliente no
     Portal**, a API responde `404 "Representante não encontrado"` e o pedido não entra.
     Isso é cadastro do lado deles, não código do nosso — e vai aparecer como "o sistema
     não deixa enviar" para quem usa. Precisa de mensagem clara na tela.
2. **Quem dispara o envio?** Candidatos: automático quando o orçamento é aprovado
   (`status_gestor = aprovado`), ou botão explícito "Enviar para o Portal" na tela de
   orçamentos. Dado que o pedido entra como rascunho e alguém revisa do outro lado,
   automático é defensável; botão dá controle e evita mandar orçamento aprovado que o
   cliente ainda não fechou. **Inclino para botão** — aprovar internamente ≠ o cliente
   comprou.
3. **Orçamento com item de etiqueta** (sem produto no catálogo): não envia? envia só os
   itens com código? exige um `cod_produto` genérico de etiqueta acordado com o Portal?
4. **Reenvio depois de mudar o orçamento**: o Portal responde 409 para a mesma chave com
   conteúdo diferente. Ou o orçamento vira imutável depois de enviado, ou editar exige
   chave nova e vira **um segundo pedido** no Portal. Precisa de uma regra explícita na
   tela, senão o vendedor cria pedido duplicado achando que "corrigiu".
5. **Homologação x produção**: a base é `https://<host>/v1` — falta o host de produção, e
   o token de produção não existe ainda.

## 7. O que foi CONSTRUÍDO — 2026-09-10

Feature completa e testada, **desligada por padrão**. Suíte inteira verde: 541 testes.

### O fluxo

Botão **"Transformar em pedido"** na tabela de `/orcamentos` → `POST /orcamentos/{id}/portal`
→ `GeradorDePedidoNoPortal::preparar()` (síncrono) → `EnviarPedidoAoPortalJob` (fila) →
`PortalPedidoClient` → o número do pedido volta pelo sino.

### 🥇 A divisão em dois tempos é a decisão central, não detalhe

| Etapa | Onde roda | Por quê |
|---|---|---|
| Resolver de-para + montar payload | **na requisição** | é tudo local, custa milissegundos — e "falta o representante" ou "o CNPJ não bate" precisa aparecer NO CLIQUE. Devolver isso pelo sino seria cruel |
| Chamada HTTP ao Portal | **na fila** | o homolog responde em ~500 ms; sozinha ela estoura o orçamento de escrita da Regra de ouro nº 9 |

Consequência: **erro nosso é síncrono, erro deles é assíncrono.** Quem mexer aqui precisa
manter essa fronteira, senão ou a tela trava, ou a mensagem útil vira notificação.

### Onde cada decisão mora (Regra de ouro nº 8)

| O que se repete | Onde mora |
|---|---|
| URL, token e formato de erro do Portal | `PortalPedidoClient` |
| Código do TOTVS → id do Portal (+ guarda de CNPJ) | `PortalDeParaResolver` |
| Formato do corpo, centavos, IPI, quantidade | `PortalPedidoPayload` |
| Escrita das colunas `orcamentos.portal_*` | `GeradorDePedidoNoPortal` — e ninguém mais |
| Interruptor, URL, IPI, tipos de nota | `config/portal.php` |
| Quem pode enviar | `OrcamentoController::podeEnviarAoPortal()` — guarda a rota E o botão |

### ⚠️ Cinco coisas que vão surpreender quem mexer depois

1. **O payload é congelado em `orcamentos.portal_payload`.** O contrato de idempotência
   deles é *"reenvie a requisição IDÊNTICA com a mesma chave"*. Se a retentativa remontasse
   o corpo a partir do orçamento, qualquer edição no meio do caminho mudaria o conteúdo e a
   mesma chave passaria a responder **409** — o pedido ficaria preso sem motivo aparente.
2. **A chave de idempotência nasce persistida, ANTES do envio, e é reusada.** Gerar chave
   nova na retentativa é o único caminho que ainda duplica pedido no Portal.
3. **Erro do Portal notifica TODA vez; sucesso só uma.** O `NotificacaoService` deduplica
   por `referenciaTipo`+`referenciaId`; usar isso no erro deixaria a segunda falha **muda**
   e o vendedor concluiria que deu certo.
4. **As colunas `portal_*` estão FORA do `$fillable`** — dono único, mesmo raciocínio de
   `clientes.data_ultimo_contato`.
5. **Recusa do Portal (4xx) não retenta; falha de rede (5xx/timeout) retenta.** São duas
   exceções diferentes de propósito: retentar uma recusa daria exatamente a mesma resposta.

### 🚧 Restrito a admin durante a homologação

Decisão do Tony (10/09): só **admin** dispara. Dono do orçamento e diretor veem o mesmo
botão **desabilitado**, com "em breve" no tooltip — mostrar desabilitado em vez de esconder
avisa que a função existe e evita a tela mudar do nada quando liberar.

⚠️ **A restrição guarda a ROTA, não só o botão** (mutação confirmou: removê-la derruba três
testes). **Para liberar**: em `podeEnviarAoPortal()`, trocar o `=== 'admin'` pela linha
comentada logo acima; o rótulo "em breve" se apaga sozinho, porque é calculado como
"quem veria o botão menos quem já pode enviar".

### 🔴 O buraco que só apareceu quando o Tony perguntou "o que acontece quando eu clico?"

A coluna `cliente_id` foi criada e o resolver escrito contando com ela — mas **nada no fluxo
de criação a escrevia**, e a busca de cliente do formulário nem devolvia o `id`. Resultado:
**todo** orçamento parava na primeira checagem com *"não está vinculado a um cliente do
TOTVS"*. Mesmo formato da `last_activity_at` da badge "0 online" (31/08): coluna que parece
popular e não popula.

Corrigido em cinco pontos — `buscarClientes()` devolve `clienteId`, o formulário guarda,
`store()`/`update()` persistem, `mapOrcamentoParaForm()` preserva ao editar/copiar, e a
Carteira manda `cliente_id` no link "Criar orçamento". Dois testes de regressão.

⚠️ **Os 1.899 orçamentos históricos continuam sem vínculo e NÃO viram pedido.** É correto:
foram importados antes da coluna existir. Backfill por CNPJ é possível mas **não é seguro às
cegas** — 348 deles casam com o cliente errado ou com nenhum (§4.4). Se for feito, que
vincule só onde o CNPJ bate exatamente e reporte o resto.

### Testes: 16 de feature + 14 unit, cinco mutações verificadas

| Mutação aplicada de propósito | Testes que morderam |
|---|---:|
| centavos → reais | 5 |
| interruptor de IPI invertido | 2 |
| guarda de CNPJ desligada | 1 |
| chave de idempotência sempre nova | 1 |
| restrição de admin removida | 3 |

## 8. Segurança do token

⚠️ **O token de homologação veio por WhatsApp, em texto puro, num grupo.** Tratar como
exposto: serve para homologar, mas **pedir um novo para produção**, entregue por canal
adequado. O Portal permite revogação a qualquer momento.

- Mora em `.env` (`PORTAL_PEDIDOS_TOKEN`) e é lido por `config()`, **nunca por `env()`** —
  com o config cacheado em produção, `env()` devolve null e a integração cai em 401 sem
  explicação. Mesma armadilha do `CADASTROS_REDIRECIONAR_PARA`.
- **Nunca versionar o valor** — este documento é versionado e por isso não traz o token.
  O de homologação está no WhatsApp e deve ir para o `.env` local e para o gerenciador de
  senhas.
- Trocar o token exige `config:cache` + `reload php8.3-fpm` + **`queue:restart`** (quem
  chama a API é o worker, processo de vida longa que leu a config no boot).

---

**Documentação original:** `docs/API-Pedidos-Autopel.pdf` (recebida em 2026-09-09).
**Interlocutor: Marcelo Lorenzi — e é uma pessoa só para tudo.** Confirmado pelo Tony em
2026-09-09: ele é dono do SAC, do SIC, do Portal, do endpoint de pedidos e do integrador.

⚠️ **Isso muda o formato da conversa, não só o nome do contato.** Não há duas negociações
(uma pelo de-para, outra pelo endpoint de pedidos) nem time intermediário: as perguntas da
§4.1 (endpoints de resolução), da §5.1 (o IPI vai ou não no `unitPrice`), da §6.1
(`clientRepresentativeId`), o `API_TOKEN` do integrador e o achado da
`ConsultaBasePedidos` são **uma conversa só, com a mesma pessoa**. Vale mandar de uma vez —
inclusive porque a resposta de uma muda a outra: se a `ConsultaBasePedidos` já resolve
status de pedido, o pedido antigo ao Adriano por um código estruturado no relatório do TOTVS
(`docs/pedido-dados-adriano.md`) pode ter ficado sem objeto.

Todos os horários das respostas da API de pedidos vêm em UTC.
