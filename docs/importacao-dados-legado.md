# Importação de dados reais do TOTVS pro CRM-V2

> Documento iniciado como planejamento (2026-07-28); em 2026-07-29 os dois primeiros domínios
> já foram implementados e testados (ver seção 8). Continua sendo o registro de referência do
> mapeamento de dados, pra não perder o raciocínio a cada domínio novo.

## 1. Contexto

Seeders mockados restantes: `OrcamentoSeeder`, `LeadSeeder`, `SegmentoVendedorSeeder`.
Já vêm de dado real (ver seção 8): `users`/`vendedor_perfis` (`legado:import-usuarios`),
`clientes` (`legado:import-clientes`), `faturamentos` (`legado:import-faturamento`),
`pedidos`+`pedido_itens` (`legado:import-pedidos`).

Faltam: `leads`, `produtos` (tabela de preços/orçamento). `orcamentos` fica de fora da
rotina recorrente — ver seção 6.

## 2. As duas fontes possíveis (e qual é a fonte de verdade)

Existem duas cópias do dado do TOTVS acessíveis localmente, e elas **não são a mesma coisa**:

1. **Banco espelho `autopel01_homolog`** (MySQL local do XAMPP, mesma instância do `palma_v2`).
   É alimentado pelo importador Python (`ROTINA SQL`) e reflete o schema de produção já
   carregado (nomes de coluna as vezes diferentes do Excel original, ex. `Segmento 1` do
   Excel virou `COD_SEG` na tabela `CLIENTES`). Populado e testado nesta sessão: `clientes`
   89.643 linhas, `faturamento` 910.447 (até `emissao_date` 2026-07-13), `meta_venda` 951.062
   (até 2026-05-31), `pedidos_em_aberto` 42.158, `usuarios` 206, `orcamentos` 2.037,
   `observacoes` 9.355, `codigo_produtos` 73.664.
2. **Exports brutos do TOTVS** (`OneDrive - autopel.com\RELATORIOS TOTVS\CSV\` e a raiz de
   `RELATORIOS TOTVS\`, `.xlsx`). É o que o Tony extrai do TOTVS periodicamente e o que o
   importador Python lê pra popular o banco espelho. **Esta é a fonte de verdade real** — o
   banco espelho é só uma cópia derivada, com nomes de coluna já traduzidos pelo mapeamento
   do `sql.py`, e pode ter colunas artificiais que não existem na origem (ex. `raiz_cnpj`
   **não existe** no CSV de Clientes — é uma coluna calculada em algum ponto do pipeline;
   reforça a Regra de Ouro nº 3 do `CLAUDE.md`: nunca usar essa coluna, e agora sabemos que
   nem a origem a fornece).

**Decisão**: desenhar o mapeamento pra CRM-V2 olhando o CSV bruto como contrato, não o
schema já carregado no espelho. O espelho é útil como *destino intermediário já populado*
que o comando de import pode ler (mais rápido que reprocessar Excel/CSV toda vez), mas os
nomes de campo, formatos (CNPJ com/sem pontuação) e regras de negócio (ex. status de pedido)
só ficam claros olhando o export bruto.

## 3. Decisões já tomadas (confirmadas com o Tony)

- **Cadência por domínio**: `clientes`, `pedidos`, `leads`, `produtos` — rotina recorrente
  (comando artisan `legado:import-*`, roda quando o Tony quiser dado fresco, depois de rodar
  o ROTINA SQL com CSV novo). `orcamentos` — **pontual**, migração histórica única (schema do
  legado é achatado/JSON-em-TEXT, incompatível com o schema normalizado novo do v2; orçamento
  novo já nasce direto na tela do v2).
- **Fonte configurável**: cada comando aceita apontar pra `autopel01_homolog` (padrão, local,
  sem credencial de produção) ou produção (flag/env, só quando o mirror estiver
  desatualizado numa tabela específica e precisar do dado mais recente na hora).
- **Ordem de construção sugerida**: Clientes → Pedidos (aberto+emitidos) → Leads → Produtos
  (do mais direto pro mais confuso). Ainda não fechada — ver seção 8.
- **Volume exige upsert em lote**, não o padrão `foreach`+`updateOrCreate` do
  `ImportUsuariosLegado` (ok pra 206 linhas, inviável pra `faturamento`/`meta_venda` com
  900k+). Usar `DB::table()->upsert()` em chunks, cursor/`chunkById`, e filtrar por janela de
  tempo quando fizer sentido (ex. `emissao_date`/`dt_emissao_date`, já são colunas geradas e
  indexadas no espelho — sargable, criadas justamente pra isso).

## 4. Mapeamento por domínio

### 4.1 Clientes

**Arquivo**: `Clientes - SQL.csv` (relatório TOTVS `210 - CADASTRO DE CLIENTES.RLT`).
Delimitador `;`, primeira linha é título do relatório (descartar), segunda linha é o
cabeçalho real, encoding UTF-8 com BOM.

Colunas brutas → coluna do espelho (mapeamento confirmado em `SCRIPTS/sql.py`, config da
tabela `CLIENTES`):

| Coluna no CSV   | Coluna no espelho | Observação |
|---|---|---|
| `Codigo`        | `COD_CLIENT` | |
| `Loja`          | `LOJA` | |
| `Nome`          | `CLIENTE` | razão social |
| `N Fantasia`    | `NOME_FANTASIA` | |
| `CNPJ/CPF`      | `CNPJ` | **sem pontuação** no CSV (`10970887006569`), formatado no espelho e em outros relatórios (`03.667.884/0015-26`) — normalizar |
| `Vendedor`      | `COD_VENDEDOR` | |
| `Endereco`      | `Endereco` | |
| `Estado`        | `Estado` | sigla UF |
| `E-Mail NF-e`   | `EMailNFe` | |
| `DDD` / `Telefone` | `DDD` / `Telefone` | |
| `CEP`           | `CEP` | |
| `Grp.Vendas`    | `GrpVendas` | não confundir com segmento — ver `CLAUDE.md` nota sobre `GrpVendas` em aberto |
| `Segmento 1`    | `COD_SEG` | **código numérico** (ex. `000106`), não texto — precisa de/para pra virar o `cod_segmento` legível do v2, se o v2 quiser nome |

(**Não existe** `raiz_cnpj` no CSV — confirma que é derivado depois, nunca usar como
apontado na Regra nº 3.)

**Mapeamento pro schema v2** (`database/migrations/2026_07_27_100401_create_clientes_table.php`):
`cod_cliente`+`loja` (chave), `cnpj`, `razao_social`←`Nome`, `nome_fantasia`, `cod_vendedor`,
`cod_segmento`←`COD_SEG` (revisar se precisa resolver nome), `estado`, `cep`, `telefone`←`DDD`+`Telefone`,
`email`←`E-Mail NF-e`. Falta no CSV: `data_ultima_compra` — **não vem daqui**, ver próximo item.

**`data_ultima_compra`**: usar `Ultimo faturamento - SQL.csv` (relatório separado, já é o
rollup de última compra por cliente pré-calculado pelo TOTVS/pipeline — **não** agregar os
900k+ registros de `FATURAMENTO` na mão). Colunas: `COD_CLIENT;LOJA;CNPJ;Grp.Vendas;Descricao;
Nome;N Fantasia;E-Mail NF-e;DDD;Telefone;Telefone 2;Segmento 1;Descricao;Codigo;Nome;
Nome Reduzid;COD_SUPER;RAZ_SUPER;FANT_SUPER;COD_DIR;RAZ_DIR;FANT_DIR;NFISCAL;DT_FAT;VLR_TOTAL;
CEP;Endereco;Estado` — o campo relevante aqui é `DT_FAT` (data do último faturamento).

### 4.2 Pedidos (aberto + emitidos → tabela única `pedidos`+`pedido_itens`)

Dois relatórios diferentes alimentam isso, e cada um tem uma chave de cliente diferente:

**`Pedidos abertos - SQL.csv`** (relatório `200 - PEDIDOS EM ABERTO COM STATUS.RLT`,
grão = linha de item, 60.355 linhas na carga de 2026-07-27). Colunas:
`FILIAL;COD_CLI;LOJA_CLI;GRP_CLIENT;CNPJ;CLIENTE;FANTASIA;MUNICIPIO;ESTADO;COD_REPRES;REPRES;
DATA_PED;N_PEDIDO;DIGITACAO;CARGA;DT_ENTREGA;DT_PREVFAT;DT_PCP;ATRASO;TMP_VIAGEM;COND_PAGTO;
COD_PROD;DESC_PROD;QTD_VENDA;QTD_LIBER;VLR_PEDIDO;EMAIL;CONTATO;DDD;TELEFONE;DATA_HIST;
HORA_HIST;USUARIO;HISTORICO`. Tem `COD_CLI`+`LOJA_CLI` — liga direto em `clientes` sem
precisar de CNPJ.

**`META VENDA - SQL.csv`** (relatório `232 - CONSULTA DE PEDIDOS EMITIDOS META DE VENDAS.RLT`,
grão = linha de item, 951k linhas no espelho). Colunas: `FILIAL;COD_USER;USUARIO;PEDIDO;
DT_EMISSAO;PREV_FAT;CNPJ;CLIENTE;ATIVIDADE;COD_VENDEDOR;REPRES;SUPERVISOR;COD_PROD;DESC_PROD;
UND;PESO_LIQ;PRC_VENDA;QTDA_VENDA;VLR_TOTAL;DT_FATURAMENTO;Num. Docto.`. **Não tem**
`COD_CLI`/`LOJA` — só `CNPJ` (completo, com pontuação). Ligar em `clientes` por CNPJ
(seguro — CNPJ completo é 1:1 por filial; diferente de usar `raiz_cnpj`, que é só os 8
primeiros dígitos e causa cross-product, isso continua proibido) ou por `PEDIDO`/número do
pedido cruzando com `Pedidos abertos`, se um pedido aparecer nos dois relatórios em algum
momento da vida dele (aberto → depois faturado).

`data_faturamento IS NULL` continua sendo o discriminador aberto/faturado no v2 (`CLAUDE.md`
já documenta isso pra `PEDIDOS_EM_ABERTO`+`META_VENDA` → `pedidos`).

**Descoberta desta sessão — de onde vem o `status` enum** (`separacao`/`bloqueio`/`wms`/
`liberado`/`faturado`, hoje só existe como rótulo em `resources/js/constants/pedidos.js`,
sem lógica de derivação em lugar nenhum): o campo `DIGITACAO` **não discrimina nada** (sempre
`"DIGITACAO CONCLUIDA"` nas 60.355 linhas testadas). O sinal real está no texto livre de
`HISTORICO`:

| Padrão de texto em `HISTORICO` | Status provável |
|---|---|
| `INCLUIDO NA CARGA NNNNNN` | pedido já alocado numa carga (fase avançada, perto de faturado) |
| `ENVIO DO PEDIDO PARA O WMS - ...` | `wms` |
| `COM BLOQUEIO DE ESTOQUE` | `bloqueio` |
| `LIBERADO PARA MONTAGEM DE CARGA` | `liberado` (antes de entrar numa carga) |
| (nenhum dos acima) | provavelmente `separacao` (estado inicial/default) |

`DT_PCP` só vem preenchido em 442 das 60.355 linhas testadas — não é um bom discriminador
sozinho, é mais um carimbo de quando entrou em planejamento de produção do que um estado.
**Isto ainda não foi validado com uma amostra maior nem contra o PHP do legado** — antes de
codar o parser, vale conferir contra `PLANO-DE-ESCAPE` (`pages/COMERCIAL` ou
`includes/reports`, ver como a tela de pedidos-abertos do legado deriva a cor/rótulo de
status) pra não inventar regra por conta própria.

Investiguei também o arquivo **`pedidos com status - SQL.xlsx`** (alimenta a tabela
`Pedidos_status`/`pedidos_status` no espelho, 407k linhas) — apesar do nome sugerir que teria
uma coluna de status explícita, **não tem**: colunas reais são `FILIAL, COD_CLI, LOJA_CLI,
CNPJ, INS_EST, CLIENTE, FANTASIA, MUNICIPIO, ESTADO, COD_REPRES, REPRES, DATA_PED, DT_NFISCAL,
N_PEDIDO, RESIDUO, DT_ENTREGA, DT_PREVFAT, COND_PAGTO, COD_PROD, DESC_PROD, QTD_VENDA,
QTD_LIBER, VLR_UNIT, ALIQ_IPI, VLR_IPI, VLR_PEDIDO, COD_ATIV, ATIVIDADE, NT_FISCAL`. Parece
ser mais um relatório histórico/consolidado (tem `DT_NFISCAL`, `ALIQ_IPI`, `VLR_IPI` — dados
de nota fiscal já emitida) do que uma fonte do enum de status. `RESIDUO` guarda um código
tipo `LIMP_RESID` (razão de saldo pendente?) — não explorado a fundo, não parece ser o que
precisamos pro status do pedido em aberto.

**Mapeamento pro schema v2** (`create_pedidos_table`/`create_pedido_itens_table`):
`numero_pedido`←`N_PEDIDO`/`PEDIDO`, `cliente_id`←(join por `COD_CLI+LOJA_CLI` ou CNPJ),
`cod_vendedor`←`COD_REPRES`/`COD_VENDEDOR`, `data_pedido`←`DATA_PED`, `data_previsao_faturamento`
←`DT_PREVFAT`/`PREV_FAT`, `data_faturamento`←`DT_FATURAMENTO` (`META_VENDA`, null = aberto),
`data_entrega_prevista`←`DT_ENTREGA`, `data_pcp`←`DT_PCP`, `carga`←`CARGA`,
`condicao_pagamento`←`COND_PAGTO`, `status`← derivar de `HISTORICO` (ver acima) quando aberto,
`faturado` quando `data_faturamento` não é nulo. Itens: `cod_produto`/`descricao`/`quantidade`
/`quantidade_liberada`/`valor_unitario`/`valor_total` ← `COD_PROD`/`DESC_PROD`/`QTD_VENDA`
(ou `QTDA_VENDA`)/`QTD_LIBER`/`VLR_UNIT` (ou `PRC_VENDA`)/`VLR_PEDIDO` (ou `VLR_TOTAL`),
dependendo do relatório de origem.

### 4.3 Leads (`base_marco - SQL.xlsx` → `BASE_LEADS` → `leads`)

**Isto NÃO é export TOTVS.** É planilha de enriquecimento externo (aba `base final`,
22.570 linhas, 71 colunas, header na linha 0). O importador (`sql.py`) carrega como
`BASE_LEADS` (rotinas `"FAT + BASE_MARCO"` / `"… + BASE LEADS"`). Confirmação no Excel
bruto de `RELATORIOS TOTVS\base_marco - SQL.xlsx` (2026-07-28).

**Filtro que o legado usa pra listar leads de sistema** (`pages/COMERCIAL/leads.php`):

```sql
WHERE bl.MARCAOPROSPECT = 'SAI PROSPECT'
```

No Excel bruto isso é a coluna `MARCAÇÃO PROSPECT`:
- `sai prospect` → **18.467** linhas (~82% da base) — é o universo "lead de sistema"
- `ok` → 4.103 — **fora** da listagem de leads do legado (cliente já "ok"/não-prospect)

Dentro do subconjunto `sai prospect`, a coluna `status` é quase sempre `prospect`
(18.442); só ~25 linhas escapam pra `ativo`/`inativo`. Ou seja: o discriminador operacional
de "aparece na tela de Leads" é **`MARCAÇÃO PROSPECT`**, não a coluna `status`.

Distribuição completa de `status` na base (22.570): `prospect` 18.442 · `inativo` 2.395 ·
`ativo` 1.622 · `ativo...` 111 (sujeira). `FONTE` (origem da linha): null 14.090 ·
`z__AUTOPEL (ultimo fat. SUPERMERCADISTAS)` 3.229 · `Fulfillment` 3.187 · `Driva` 1.045 ·
`NEOWAY MASTER` 692 · `ABRAS` 327.

**Gotcha — `raiz CNPJ` existe NESTE Excel** (coluna `[2]`). Diferente de Clientes (onde a
raiz é inventada depois no pipeline). Continua valendo a Regra nº 3: **nunca usar pra join/
agrupar** — CNPJ completo (`cnpj`, coluna `[1]`, formatado `00.000.993/0001-00`) é a chave.
Preenchimento: CNPJ 100%; telefone final ~99,7%; e-mail ~91%; `Codigo Vendedor` vazio em
~17,8%.

Cabeçalhos brutos relevantes (amostra; a planilha tem 71 cols — lista completa no Excel):

| Coluna no Excel | Uso no legado / candidata v2 |
|---|---|
| `cnpj` | chave do lead |
| `RAZAO SOCIAL` / `NOME FANTASIA` / `nome final` | identificação |
| `E-mail` | contato |
| `Telefone Principal (FINAL)` | telefone canônico (há várias colunas intermediárias de telefone — ignorar) |
| `UF` / `CIDADE` / `CIDADE (arrumada)` | localização (`arrumada` preferível quando preenchida) |
| `CEP` / `endereçoCNPJJA` | endereço |
| `Codigo Vendedor` | dono da carteira de prospecção (`cod_vendedor`) |
| `vendedor (nova hierarquia)` / `supervisor (nova hierarquia)` | sinal interno; legado usa mais o código |
| `status` | `prospect`/`ativo`/`inativo` — **não** é o enum do v2 |
| `MARCAÇÃO PROSPECT` | filtro de inclusão (`sai prospect`) |
| `projeção R$ (mês)` | candidato a `valor_estimado` — só ~896 preenchidos entre os SAI PROSPECT |
| `cnae` / `cnae e desc.` | firmográfico; legado ainda referencia `Descricao1` como "segmento", mas **essa coluna NÃO existe** no Excel atual nem no espelho homolog — filtro de segmento do legado provavelmente quebrado/legado de versão antiga |
| `raiz CNPJ`, ABRAS, capital social, lat/long, IBGE, projeções anuais, última venda AUTOPEL, etc. | enriquecimento — **fora do schema `leads` do v2 hoje** |

**Mapeamento sugerido pro schema v2** (`create_leads_table`):

| Coluna v2 | Fonte | Nota |
|---|---|---|
| `origem` | literal `'sistema'` | leads manuais continuam vindo da tela `/cadastros` / `/leads` |
| `cod_vendedor` | `Codigo Vendedor` | ~18% vazio — lead órfão de carteira |
| `nome` | `nome final` (fallback `RAZAO SOCIAL`) | |
| `razao_social` | `RAZAO SOCIAL` | |
| `nome_fantasia` | `NOME FANTASIA` (fallback `Nome Fantasia CNPJ JÁ…`) | |
| `cnpj` | `cnpj` | normalizar pontuação |
| `email` | `E-mail` | |
| `telefone` | `Telefone Principal (FINAL)` | |
| `endereco` | `endereçoCNPJJA` | |
| `cidade` | `CIDADE (arrumada)` fallback `CIDADE` | |
| `estado` | `UF` | |
| `segmento` | **em aberto** | sem `Descricao1` na fonte atual; opções: null / `cnae e desc.` / inventar de-para depois |
| `valor_estimado` | `projeção R$ (mês)` | maioria null |
| `status` | **decisão pendente** | proposta: importar só `MARCAÇÃO PROSPECT = 'sai prospect'` e mapear `prospect`→`ativo`, `inativo`→`inativo`; `convertido`/`excluido` ficam só pro fluxo do v2 |

**Decisão de escopo ainda aberta com o Tony**: enriquecer o schema `leads` com CNAE/ABRAS/
lat-long/etc., ou manter o schema enxuto atual e descartar o resto no import (recomendação
inicial: manter enxuto — a tela `/leads` do v2 não tem UI pra isso).

### 4.4 Produtos — três fontes diferentes (não uma)

Aqui o nome da rotina do importador mente. `"PRODUTOS (COD + Produtos SQL)"` **só carrega
`CODIGO_PRODUTOS`**. O arquivo `PRODUTOS - SQL.xlsx` (cadastro TOTVS) **não está wired** no
`PLANILHAS_CONFIG` do `sql.py`. E o preço (`PRCVENDA`) **não vem de nenhum dos dois** — vem
de um fluxo separado do legado. Detalhe:

#### Fonte A — de-para manual de categoria
**Arquivo**: `RELATORIOS TOTVS\Arquivos aversos\CODIGO PRODUTOS - SQL.xlsx`
(cópia em `ROTINA SQL\.XLSX\deparas\`; `subpasta: "deparas"` no `sql.py`).
Aba `Produtos AUTOPEL`, header linha 0: `desc_prod; cat_prod; cod_prod`.
~54.929 linhas. Categorias (case misto no Excel): `bobina`/`BOBINA`, `suply`/`SUPLY`,
`etiqueta`/`ETIQUETA`, `tag`/`TAG`, `volante`/`VOLANTE`.
**Sem preço, sem unidade.** É só o mapa código→descrição→categoria comercial Autopel.

Há um irmão quase idêntico: `Produtos AUTOPEL.xlsx` (header `cod_prod (FINAL)` em vez de
`cod_prod`) — mesma ideia, não é o que a rotina lê hoje.

#### Fonte B — cadastro TOTVS (NÃO entra no importador hoje)
**Arquivo**: `RELATORIOS TOTVS\PRODUTOS - SQL.xlsx`, relatório
`005 - CADASTRO DE PRODUTO COM NCM.RLT`. Header na linha 2 (linha 1 = título).
Colunas: `Codigo; Descricao; Grupo; Desc Grupo; SubGrupo; Desc SubGrup; Família;
Desc.Família; Pos.IPI/NCM; Grupo Trib.; Origem; ATIVO`.
94.774 linhas (`ATIVO=SIM` 67.504 / `NAO` 27.270). Top `Desc Grupo`: ETIQUETAS, PRODUTO
INTERMEDIÁRIO, BOBINAS, MATERIA PRIMA BOBINAS, PAPELARIA/OFFICE…
**Também sem preço.** Mais completo que o de-para (NCM, ativo/inativo, hierarquia TOTVS),
mas o legado de orçamento/catálogo **não usa essa tabela** — usa `CODIGO_PRODUTOS`.

#### Fonte C — tabela de preços (fluxo à parte, UI admin do legado)
Página `pages/GESTAO/importar_tabela_preco.php` + `includes/produtos/tabela_preco_import.php`.
Admin/diretor sobe um `.xlsx`/`.csv` com colunas detectadas por nome
(`CODIGO`/`COD_PROD` + `PRCVENDA`/`PRECO` obrigatórios; `DESCRICAO`/`UNIDADE` opcionais).
Efeito em `CODIGO_PRODUTOS`:
- UPDATE `PRCVENDA` + `UN_PROD` nos códigos que já existem;
- INSERT dos que não existem, com `CAT_PROD = 'TABELA PADRAO'` (default).

No espelho homolog hoje: 73.664 linhas, **68.737 com `PRCVENDA > 0`**, 18.735 na categoria
`TABELA PADRAO` (vieram só da tabela de preço). `buscar_produto.php` do legado expõe
`PRCVENDA` como `preco_venda`/`preco_tabela`.

#### Mapeamento pro schema v2 (`produtos`)

| Coluna v2 | Fonte recomendada | Nota |
|---|---|---|
| `cod_produto` | `COD_PROD` (espelho já mergeado) ou `cod_prod` do de-para | unique |
| `descricao` | `DESC_PROD` / `desc_prod` | |
| `categoria` | `CAT_PROD` / `cat_prod` | normalizar case (`bobina` vs `BOBINA`); `TABELA PADRAO` = veio só do preço |
| `unidade` | `UN_PROD` (só existe depois do import de preço) | null no de-para puro |
| `preco_tabela` | `PRCVENDA` (só existe depois do import de preço) | **não tem** em `PRODUTOS - SQL.xlsx` nem no de-para |

**Implicação pro comando `legado:import-produtos`**: ler o espelho `CODIGO_PRODUTOS` (já
com preço mergeado pela rotina+UI) é o caminho curto e fiel ao que o legado usa hoje.
Reconstruir a partir dos Excels brutos exigiria **dois arquivos** (de-para + planilha de
preço), e a planilha de preço **não mora** em `RELATORIOS TOTVS\` de forma estável — é
upload pontual do admin. `PRODUTOS - SQL.xlsx` só entra se a gente quiser expandir o
catálogo além do de-para Autopel (NCM/ATIVO) — decisão de escopo, não bloqueante.

## 5. Índices/colunas geradas já existentes no espelho (aproveitar, não recriar)

O `ROTINA SQL` já cuidou de performance no schema do espelho — os comandos de import devem
**ler através** desses índices, não escanear a tabela inteira:

- `FATURAMENTO.emissao_date` — `DATE GENERATED STORED` a partir de `EMISSAO` (texto),
  indexado (`idx_fat_emissao_date`). `META_VENDA.dt_emissao_date` — mesma ideia, a partir de
  `DT_EMISSAO`, indexado (`idx_meta_dt_emissao` no SQL de migration é sobre a coluna texto,
  mas a config em `planilhas.yaml` já indica preferir a gerada).
- `CLIENTES` indexado por `(COD_CLIENT, LOJA)` e por `COD_VENDEDOR` — bate com a chave do v2.

## 6. Orçamentos — fora da rotina recorrente

Confirmado com o Tony: migração pontual/histórica, não rotina. Motivo: schema do legado é
achatado (itens em JSON dentro de um TEXT), incompatível com o normalizado do v2
(`orcamentos`+`orcamento_itens`, IPI, nível de aprovação). Se algum dia for feita, é um
comando `legado:import-orcamentos-historico` rodado uma vez, não um `legado:import-*` de
rotina.

## 7. Pendências / perguntas em aberto

- Validar a regra de derivação de `status` do pedido (seção 4.2) contra o PHP do legado
  (`PLANO-DE-ESCAPE`) antes de codar — a amostra de `HISTORICO` até agora é só de uma carga.
- **Leads — decisões de negócio (seção 4.3, Excel já explorado):**
  1. Confirmar filtro `MARCAÇÃO PROSPECT = 'sai prospect'` como universo do import
     `origem=sistema`.
  2. Mapear `prospect`→`ativo` (e o que fazer com linhas `status=inativo` fora do filtro).
  3. Schema enxuto (recomendado) vs. trazer CNAE/ABRAS/lat-long/etc. pro v2.
  4. `segmento`: null, `cnae e desc.`, ou outro de-para (a coluna `Descricao1` que o legado
     filtra **não existe** na fonte atual).
- **Produtos — decisões de negócio (seção 4.4, Excels já explorados):**
  1. Importar do espelho `CODIGO_PRODUTOS` (já com `PRCVENDA` mergeado) — caminho curto —
     ou reconstruir de-para + planilha de preço separados?
  2. Incluir ou não o cadastro TOTVS (`PRODUTOS - SQL.xlsx`, NCM/ATIVO) além do de-para
     Autopel?
  3. Onde mora a planilha de preço "oficial" pra rotina recorrente do v2? (hoje é upload
     pontual na UI admin do legado, não um arquivo fixo em `RELATORIOS TOTVS\`).
- Resolver o `de-para` de `COD_SEG` (código numérico do TOTVS) pro nome de segmento
  legível, se o v2 precisar exibir nome em vez de código.
- Semântica de sync ainda em aberto por domínio: `updateOrCreate` nunca remove — decidir se
  cliente que sumiu do legado devia ser marcado inativo/removido, e como tratar pedido que
  muda de status entre uma rodada de import e outra. Em leads: linha que sai de
  `sai prospect` → `ok` deve virar `convertido`/`excluido` ou sumir da listagem?
- Fechar a ordem de construção dos comandos (seção 3 sugere Clientes → Pedidos → Leads →
  Produtos, mas ainda não confirmado com o Tony).

## 8. Progresso real (2026-07-29)

### 8.1 Clientes — feito
`php artisan legado:import-clientes` (`App\Console\Commands\ImportClientesLegado`,
`App\Services\Legado\LegadoConexao`). Lê `CLIENTES`+`ultimo_faturamento` do espelho
(`--fonte=homolog`, padrão) e faz `upsert` em `clientes` por `(cod_cliente, loja)`.
89.643 clientes reais importados. `ClienteSeeder` removido do `DatabaseSeeder` e as
~3.983 linhas mock deletadas (identificadas por `LENGTH(cod_cliente) = 4` — o seeder gera
código sequencial a partir de 1000, sempre 4 dígitos; TOTVS real é sempre 6, com raras
exceções de 5).

Tratamentos de qualidade de dado aplicados (achados comparando o espelho contra o Excel
bruto, não são "gambiarra do legado" — são artefato da própria carga do espelho):
`DDD` vem zero-padded de forma inconsistente (`000031` em vez de `31`) — corrigido com
`ltrim`. CNPJ vem sem pontuação no Excel de Clientes — reformatado pro padrão com pontuação
usado no resto do sistema.

**Pendência que ficou visível na tela depois do import**: `cod_segmento` agora é o código
numérico cru do TOTVS (`000103`), mas `segmentos_vendedor` usa nome por extenso
(`SUPERMERCADISTA`) — a Aderência por Segmento (Carteira/Home) zerou (0% no segmento) até
resolver esse de/para (mesmo item já listado acima nas pendências).

### 8.2 Faturamento — feito
`php artisan legado:import-faturamento` (`App\Console\Commands\ImportFaturamentoLegado`).
Lê `FATURAMENTO` do espelho via PDO **não-bufferizado**
(`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false` — obrigatório pra 900k+ linhas, senão o driver
tenta carregar o resultado inteiro na memória do cliente antes da primeira linha) e faz
`INSERT` em lote (sem upsert — a tabela não tem chave natural única; idempotência vem de
truncar antes de uma carga completa, ou `--desde=Y-m-d` pra um `period_merge` só numa janela,
igual ao próprio `ROTINA SQL` faz). **910.447 linhas importadas em ~92s.** `FaturamentoSeeder`
removido do `DatabaseSeeder`.

**Achado de performance real** (motivo de existir a Regra de ouro nº 6 no `CLAUDE.md` —
resumo aqui, detalhe lá): a query "Comparação de Faturamento" do Home, sem filtro de
vendedor (visão admin/diretor/supervisor), foi de instantânea (seed mockado) pra 1,3s com
os 910k reais — `whereYear()` não é sargable. Troquei por `whereBetween()` + índice em
`data_emissao`, mas **não resolveu sozinho**: 100% do faturamento importado é de um único ano
(o espelho só tem esse ano hoje), então o `BETWEEN` não corta nada e o MySQL prefere table
scan mesmo com o índice disponível (~860ms, sem melhora real). Fix efetivo:
`Cache::remember(..., now()->addMinutes(15), ...)` em
`DashboardController::faturamentoComparacao` — primeira carga continua ~880ms, mas fica
em cache por 15 min por (ano, escopo de vendedor). Lição pra próximos domínios de alto
volume (`pedidos`, especialmente): **medir com `EXPLAIN` + tempo real antes de considerar
pronto**, e não assumir que criar um índice resolve — se a condição não corta uma fração
real da tabela, o índice não ajuda, e a resposta é reduzir quantas vezes a query roda
(cache/rollup), não insistir em indexar.

### 8.3 Pedidos — feito, com uma decisão de escopo importante

`php artisan legado:import-pedidos` (`App\Console\Commands\ImportPedidosLegado`). Junta
duas fontes, como já estava mapeado na seção 4.2:
- **`PEDIDOS_EM_ABERTO`** (grão de item, tem `COD_CLIENT`+`LOJA` direto) → todo pedido aqui
  vira `status = 'pendente_totvs'` (ver abaixo).
- **`META_VENDA` filtrado por `DT_FATURAMENTO IS NOT NULL`** → `status = 'faturado'`. Achado
  importante durante a implementação: **92% das linhas de `META_VENDA` (878.768 de
  951.062) não têm `DT_FATURAMENTO`** — são o mesmo pedido ainda em aberto, já coberto por
  `PEDIDOS_EM_ABERTO`. Importar a tabela inteira duplicaria quase tudo; só a fatia com
  `DT_FATURAMENTO` preenchido (72.294 linhas, 5.084 pedidos) é dado novo de verdade.
- Cliente linkado por `(cod_cliente, loja)` em `PEDIDOS_EM_ABERTO`, por `cnpj` completo em
  `META_VENDA` (não tem cod_cliente/loja). 10 pedidos de 8.853 ficaram sem `cliente_id`
  (cliente não encontrado — aceitável, `cliente_id` é nullable).
- **583 pedidos apareciam nas duas fontes ao mesmo tempo** (aberto numa carga, já com linha
  faturada na outra) — bug real encontrado e corrigido durante o teste: o cabeçalho
  (`pedidos`) já fazia upsert por `numero_pedido` corretamente, mas os **itens**
  (`pedido_itens`) eram só inseridos, então esses 583 pedidos ficavam com itens duplicados/
  misturados das duas rodadas. Fix: antes de inserir os itens de uma rodada, apaga
  `pedido_itens` dos `pedido_id` que essa rodada vai (re)preencher. **8.853 pedidos,
  98.006 itens** depois do fix (era 114.452 itens antes, ~16k duplicados).
- **⚠️ Segundo bug, encontrado em 2026-08-10: os itens dos pedidos FATURADOS não entravam.**
  Sintoma na tela: "Itens = 0" em todo pedido faturado, na ficha do cliente e em
  `/pedidos-emitidos` (linha expansível abrindo vazia) — 73% dos clientes com pedido.
  Causa: `Allowed memory size exhausted` (512M do CLI) dentro de `processarGrupos`, que
  montava a lista completa de itens antes de fatiar com `array_chunk()` — os 161.114 itens
  de `META_VENDA` ficavam em memória **três vezes** (`fetchAll` + `$itensPorNumero` + a
  lista final). A rodada de abertos (25 mil itens) sempre coube, por isso parecia problema
  específico de "faturado". O comando **erra e sai com código 255**; passou batido por estar
  no meio da sequência de restauração, deixando cabeçalhos corretos e só os itens faltando.
  Fix: inserir em lotes de 1.000 durante o percurso, `unset` no `fetchAll` após o
  agrupamento e liberar cada grupo depois de gravado (iterando `array_keys`, nunca o array
  sendo modificado). **Estado atual: 15.523 pedidos, 186.497 itens, zero pedido sem item.**

**Decisão de escopo confirmada com o Tony sobre o status do pedido em aberto**: o legado
**não tem** um enum de status de verdade — confirmado lendo o PHP (`pedidos_abertos_ajax_v2.php`):
o parâmetro `filtro_status` é lido do POST e **nunca usado** em nenhum `WHERE` (código morto).
Quem "inventa" status é o JS do front (`assets/js/pedidos-abertos.js`): pega o texto livre de
`HISTORICO`, tira uns prefixos, e pinta a cor por 3 regras de regex genéricas
(`REJEIC|CANCEL|BLOQ|RECUS|NEGAD|ATRAS|VENC` → vermelho, `AGUARD|PEND|ANALIS|COMPRV|APROV`
→ âmbar, `LIBER|FATUR|CONFIRM|ENTREG|CONCLU|FINALIZ` → verde, resto → cinza). O enum
`separacao/bloqueio/wms/liberado` que já existia no schema do v2 foi um chute de quem
desenhou o mock, sem corresponder a nada real.

**Solução acordada**: em vez de inventar uma tradução arbitrária do texto pro enum antigo,
adicionamos um 6º valor **`pendente_totvs`** ("Aguardando classificação do TOTVS") —
todo pedido em aberto recebe esse status até existir um código estruturado de verdade na
origem. **Ação pendente, fora do CRM-V2**: pedir pro Adriano incluir uma coluna de código
de status estruturado no relatório "Pedidos em Aberto com Status" do TOTVS (ex.: separação,
aguardando arte, bloqueio de estoque, WMS, liberado — os nomes reais do processo interno,
que o Tony conhece mas que hoje só existem como texto livre não padronizado no `HISTORICO`).
Quando isso existir, o `legado:import-pedidos` passa a ler esse código em vez de forçar
`pendente_totvs` pra tudo. Migration `2026_07_29_090549_add_pendente_totvs_status_to_pedidos_table`
documenta isso no comentário.

**Teste de performance real (Regra de ouro nº 6) — feito de verdade, não só superficial**:
testei os 5 caminhos de query que `PedidoController` realmente executa pra visão "todos os
vendedores" (pior caso, admin/diretor/supervisor sem filtro): contagem de abertos (25ms),
atrasados (9ms), valor em risco (7ms), listagem paginada com eager load (25ms), contagem de
emitidos no mês (7,5ms). Todos rápidos hoje porque `pedidos` só tem 8.853 linhas — bem menor
que os 910k de `faturamentos`. Mas achei a mesma lacuna estrutural **antes** dela doer:
`(cod_vendedor, data_pedido)` só ajuda quem filtra por vendedor; a página "Pedidos Emitidos"
(histórico completo, sem filtro de vendedor pra gestor) fazia `type: index` (varre o índice
inteiro, 8.707 linhas) por falta de índice próprio em `data_pedido`. O legado tem 407.604
linhas na tabela equivalente (`pedidos_status`) — esse histórico vai crescer nessa direção.
Adicionei o índice (`add_data_pedido_index_to_pedidos_table`) proativamente, antes de virar
um caso de "1,3 segundo" como o do Faturamento.

### 8.4 Leads — feito

`php artisan legado:import-leads` (`App\Console\Commands\ImportLeadsLegado`). Fonte:
`base_leads` no espelho (populada a partir de `base_marco - SQL.xlsx`), filtrado por
`UPPER(TRIM(MARCAOPROSPECT)) = 'SAI PROSPECT'` — decisão confirmada com o Tony. Números
reais na carga de 2026-07-29 (diferem um pouco do que a seção 4.3 estimou, porque a base
já tinha sido atualizada entre uma sessão e outra): 17.173 "SAI PROSPECT" (não 18.467) e
5.397 "ok" (não 4.103) — total 22.570 bate igual. `status` na fatia importada veio 100%
`prospect` (não os ~25 casos de exceção que a exploração anterior tinha visto) → mapeado
todo pra `ativo`. `segmento` ficou `null` em todo mundo, por decisão explícita do Tony
("não inventa") — não existe fonte confiável pra isso hoje.

**Nunca mexe em `origem=manual`** — o comando só apaga e recria `origem=sistema` antes de
reinserir (138 mock removidos, 10 leads manuais preservados). Isso é comportamento
permanente do comando, não só desta carga: lead cadastrado pela tela nunca é tocado pelo
import.

`LeadSeeder` removido do `DatabaseSeeder`. Performance testada (índice composto
`(origem, status)` já cobre a query mais comum, ~32-37ms pros 17k reais) — sem achado
digno de nota desta vez.

### 8.5 Produtos — feito

`php artisan legado:import-produtos` (`App\Console\Commands\ImportProdutosLegado`). Fonte:
`CODIGO_PRODUTOS` no espelho, exatamente como a seção 4.4 recomendava (caminho curto, já
com `PRCVENDA` mergeado pela rotina de preço do legado).

**Achado não documentado antes**: a tabela tem 73.664 linhas mas só **26.989 `COD_PROD`
distintos** — média de 2,7 linhas idênticas por produto (reimport da planilha de preço sem
dedup, no próprio legado). Resolvido com `GROUP BY COD_PROD` + `MAX()` em cada coluna
(colapsa em 1 linha por código; `MAX(PRCVENDA)` garante que se qualquer uma das duplicatas
tiver preço preenchido, ele "vence"). Categoria normalizada pra maiúsculo (`bobina`→`BOBINA`)
pra não duplicar filtro por causa de caixa. **26.989 produtos importados**, 24.432 com
preço, 6 categorias (`BOBINA`, `ETIQUETA`, `SUPLY`, `TABELA PADRAO`, `TAG`, `VOLANTE`).
`ProdutoSeeder` removido do `DatabaseSeeder` e as 29 linhas mock apagadas antes do import
(códigos tipo `BOB001` não colidem com os reais `V29904`/`21822`, mas removidas por limpeza).

## 9. Estado final desta rodada (2026-07-29)

Só `orcamentos` continua mockado por decisão (migração pontual futura, fora de escopo desta
rotina). Todos os outros domínios do CRM-V2 (`users`, `clientes`, `faturamentos`, `pedidos`,
`leads`, `produtos`) já vêm de dado real do TOTVS via `legado:import-*`. Pendência real que
ficou em aberto e depende de terceiro: pedir pro Adriano um código de status estruturado no
relatório de Pedidos em Aberto (seção 8.3).

### 8.6 Orçamentos — migração pontual feita (2026-07-29)

`php artisan legado:import-orcamentos-historico` (`App\Console\Commands\ImportOrcamentosHistoricoLegado`,
namespace deliberadamente diferente de `legado:import-*` recorrente — é rodado uma vez).

**Correção de leitura importante**: quando propus essa migração, tinha lido a coluna errada
e reportado que 98% dos 2.037 orçamentos ficavam parados em "pendente" pra sempre — dado
preocupante o bastante pra questionar se valia migrar tudo. Na verdade eu tinha olhado
`status` (aprovação do **cliente**, campo que nem existe no schema do v2 — ver
`CLAUDE.md`/"Aprovação do cliente... fora de escopo") em vez de `status_gestor` (aprovação
**interna**, o que o v2 realmente rastreia). Com a coluna certa: **1.912 aprovados (94%), 83
pendentes, 42 rejeitados** — dado real e saudável, não lixo de teste. Bom lembrete de
sempre conferir contra o schema de destino antes de tirar conclusão sobre "qualidade" do
dado de origem.

`itens_orcamento` era mesmo um JSON válido dentro do TEXT, como o mapeamento antigo previa —
`json_decode` direto, sem drama. **1.885 orçamentos + 2.918 itens importados** (152
ignorados: `codigo_vendedor` deles — majoritariamente um único vendedor, "SIMONE ALVES"/
`010492`, 152 orçamentos — não bate com nenhum usuário real importado; provavelmente fora
do escopo comercial do `legado:import-usuarios`, ver Regra de ouro nº 2). `aprovado_por_id`
ficou `null` em todo mundo — o legado só guarda o *perfil* de quem aprovou (texto tipo
"supervisor"), não o usuário específico; não dava pra apontar pra um usuário real sem
inventar. Datas (`created_at`/`aprovado_em`) preservadas do histórico real, não `now()`.

`nivel_aprovacao_necessario` e `maior_desconto_pct` vieram **100% `nenhum`/`0.00`** em toda
a base — a lógica de nível de aprovação por desconto não deixou rastro nenhum nesse
histórico (não investigado o motivo; irrelevante pra uma migração pontual read-only).

`OrcamentoSeeder` removido do `DatabaseSeeder`; 648 orçamentos mock apagados antes do
import. Performance testada (1.885 linhas — listagem com itens 21ms, soma de aprovados
1,4ms), sem achado digno de nota.

**Com isso, `orcamentos` deixa de ser mock também** — hoje só a criação de orçamento novo
continua 100% no fluxo normal da tela do v2 (não recebe mais import depois desta rodada).

---

## 10. O fluxo em produção: S3 → EC2 → RDS (fechado em 2026-09-04)

Até esta data a sincronização **não existia em produção**. Os importadores `totvs:import-*`
e a ponte `infra/enviar-relatorios-totvs.sh` → S3 → `totvs:sincronizar-s3` já estavam
escritos e deployados nos dois nós, e os CSVs estavam no bucket desde 03/09 — mas
`TOTVS_RELATORIOS_DIR` nunca tinha sido posto no `.env`, `storage/app/totvs` não existia,
e nenhum agendamento chamava nada. Resultado: **produção passou um mês** com faturamento
parado em 04/08 e pedidos em 05/08.

> ⚠️ **A lição não é "faltou um passo", é que dado velho não acende luz vermelha.** Os 12
> alarmes do CloudWatch ficaram verdes o mês inteiro — CPU, memória, ALB e 5xx não têm
> como saber que a última nota fiscal é de trinta dias atrás. É o mesmo formato do
> incidente de 29/08 (fila parada seis horas com tudo verde) e da badge "0 online agora"
> de 31/08: **o defeito silencioso é a regra neste sistema, não a exceção.** Se um número
> precisa estar fresco, alguém tem que medir a idade dele.

### 10.1 Como ficou

```
TOTVS ──(Tony exporta)──> RELATORIOS TOTVS\      (OneDrive, máquina do Tony)
                              │
                              │  bash infra/enviar-relatorios-totvs.sh
                              ▼
                          s3://crm-v2-arquivos-.../totvs/
                              │
                              │  php artisan totvs:atualizar   (cron horário, app-2)
                              ▼
                    storage/app/totvs/ ──> os 4 importadores ──> RDS
```

`TOTVS_RELATORIOS_DIR=/var/www/crm/storage/app/totvs` está no `.env` dos **dois** nós
(para não haver surpresa se o papel de scheduler mudar de máquina), mas hoje só a **app-2**
tem o cron do `schedule:run` — é lá que os relatórios são baixados e os imports rodam.

### 10.2 `totvs:atualizar` — o comando que o cron chama

Agendado **de hora em hora** em `routes/console.php`. Ele sincroniza do S3 e só importa se
a impressão digital do diretório (caminho + tamanho + mtime de cada relatório) mudou desde
a **última importação bem-sucedida** — marcador em `storage/app/totvs/.ultima-importacao`.

⚠️ **A ordem dos quatro imports é regra de negócio, não lista.** Está num lugar só, dentro
do comando, exatamente porque valeria também no SSH manual e em qualquer runbook (Regra de
ouro nº 8):

| # | Import | Por que nessa posição |
|---|---|---|
| 1 | `totvs:import-clientes` | alimenta o `ClientesLookup` dos dois imports de pedido. Medido em 04/09: **109 pedidos órfãos** quando o 232 rodou antes; **zero** depois de importar os 916 clientes novos primeiro |
| 2 | `totvs:import-faturamento` | independente; posição livre |
| 3 | `totvs:import-pedidos-emitidos` | o 232 marca `data_faturamento` |
| 4 | `totvs:import-pedidos-abertos` | **por último.** O 200 é o retrato de "aberto AGORA" e prevalece no empate (faturamento parcial). Invertido, o 232 marcaria faturado por cima e o pedido sumiria da tela de pendentes |

⚠️ **Falha no meio aborta a corrente e NÃO grava o marcador** — a rodada seguinte tenta de
novo. Gravar o marcador cedo esconderia a falha e congelaria o dado em silêncio, que é
exatamente o defeito que este comando existe para não repetir.

⚠️ **Comparar contra a última importação bem-sucedida, não contra "antes/depois do
download"**: com a comparação ingênua, um import que falha faz a rodada seguinte ver
"nada mudou no S3" e pular para sempre.

### 10.3 A carga de recuperação (04/09) e o buraco de agosto

Ligada a ponta de produção, apareceu um vão: o `FAT - SQL.csv` cobria só **01-02/09** e o
banco parava em **04/08**. Importar só ele deixaria quase um mês vazio no meio da série —
pior que o atraso uniforme, porque todo KPI de agosto passaria a mostrar número parcial
com cara de real.

Decisão do Tony: buscar agosto no **MySQL de produção do PALMA legado (KingHost)**, que é
a mesma fonte de onde os 2026 já gravados vieram — não introduz série mista. Dali para
frente, o fluxo de CSV mensal assume.

**149.751 linhas** (agosto 139.540 + setembro 10.211) importadas com
`legado:import-faturamento-arquivo` **sem `--ano`** (nesse modo ele só acrescenta; a faixa
estava comprovadamente com zero linhas). Resultado: `faturamentos` foi de 5.853.279 para
**6.003.030**, série contínua de 2018-01-15 a 2026-09-02.

**Duas confirmações cruzadas que valeram mais que qualquer teste:**
- setembro deu **10.211 linhas no KingHost e 10.211 no `FAT - SQL.csv`** — fontes
  independentes batendo;
- rodar o `totvs:import-faturamento` depois deixou o total **idêntico** (6.003.030),
  provando que o merge por recorte é idempotente e que o fluxo contínuo funciona ponta a
  ponta. Foi de propósito: melhor descobrir isso agora do que no mês que vem.

Pedidos na mesma rodada: 15.523 → **46.237**, itens 186.497 → **606.043**, zero pedidos
sem item, cobertura contínua de maio a setembro.

### 10.4 ⚠️ Exportar texto do KingHost: sempre `HEX()`

As `varchar` de `FATURAMENTO` no KingHost são **`latin1_swedish_ci` guardando bytes
UTF-8** (o legado sempre conectou com `charset=utf8` e nunca converteu nada). Isso põe
qualquer cliente num palpite, e ele erra por dois caminhos diferentes:

- `CAST(col AS CHAR)` faz o **próprio MariaDB** converter latin1→utf8 e **dobrar** cada
  byte: `Nº` (`C2 BA`) vira `NÂº` (`C3 82 C2 BA`);
- sem `CAST`, o **driver** decodifica como Latin-1 — mesmo estrago.

A saída é `HEX(col)` para todo texto: o servidor devolve ASCII descrevendo os bytes
gravados, sem passar por charset nenhum, e a conversão acontece no cliente, uma vez e de
forma explícita. `CAST(... AS CHAR)` continua **necessário nos numéricos**, por outro
motivo: sem ele o cliente formata o decimal no locale pt-BR (vírgula) e o `(float)` do PHP
leria `"218,8"` como `218`.

⚠️ **Testar a coluna isolada engana** — num `SELECT` de uma coluna só o driver acertou nos
três charsets testados, e só errou dentro do export real. E **a renderização do terminal
mente nos dois sentidos**: a verificação que presta é contar o par de bytes `C3 82` no
arquivo gerado, que tem que dar zero.

Acesso remoto do KingHost é liberado **por IP**: o do Tony passa, os das EC2 não. A
diferença entre os erros é o diagnóstico — **1045** ("access denied ... using password")
é grant inexistente para aquele IP; **1044** ("access denied ... to database X") é
credencial boa e nome de banco errado. O banco é `autopel01`.

⚠️ A credencial do KingHost entra no `.env` **só durante a carga** e sai depois (é o que
`config/legado.php` já mandava). Em 04/09 nem chegou a ser usada em produção: o import foi
por arquivo, e a exportação rodou da máquina do Tony.

### 10.5 O que continua fora do fluxo automático

- **`produtos`** — o `PRODUTOS - SQL.xlsx` está vazio e o Tony mescla dois CSVs à mão;
  enquanto a query de origem não virar uma só, continua vindo do `legado:import-produtos`
  (espelho do v1). Ver seção 4.4.
- **`leads`** (`base_marco - SQL.csv`) — o arquivo é sincronizado do S3 mas o import não
  entrou na corrente do `totvs:atualizar`: o `legado:import-leads` apaga e reinsere as
  linhas `origem='sistema'`, então os ids mudam a cada rodada e observação/agendamento
  religariam no lead errado. Precisa de chave estável antes de automatizar — é a mesma
  razão pela qual `leads` está fora do `infra/sincronizar-dados.sh`.
- **`CSV/META VENDA - SQL.csv`** — convenção antiga do relatório 232, hoje duplicata de
  agosto+setembro. A cópia que está no S3 tem o formato velho (23 colunas) e é **pulada
  sozinha** pelo `exigirColunas`; uma regerada com as 32 colunas atuais entraria
  redundante. Apagar da pasta.

### 10.6 A tela `/atualizacoes` (2026-09-04)

Admin-only, no menu do usuário. Responde sem SSH as três perguntas que só o terminal
respondia: **o dado está velho?**, **o que eu subi chegou no S3?** e **a última rodada
funcionou?** — mais um botão para não esperar a hora cheia.

| Bloco | Fonte |
|---|---|
| Idade do dado (data mais recente + atraso + linhas) | `MAX()` em `faturamentos`/`pedidos` |
| Relatórios enviados (arquivo, tamanho, quando subiu) | `listContents` do S3 |
| Rodadas (status, origem, quem, duração, saída de cada import) | `totvs_importacoes` |

⚠️ **A TELA NÃO LÊ O DISCO.** Os relatórios vivem em `storage/app/totvs` da **app-2**, e a
página é servida pelo ALB, caindo em qualquer um dos dois nós. Tudo que ela mostra vem do
banco ou do S3 — as duas fontes que os dois nós enxergam igual. Ler o diretório local
daria "nenhum relatório" de forma **intermitente**, conforme o nó sorteado, que é o tipo de
defeito que ninguém consegue reproduzir.

⚠️ **O botão enfileira; quem trabalha é o worker.** Não é só latência (a corrente leva ~2
min contra o orçamento de 500 ms da Regra nº 9): é correção. O worker roda na app-2, que é
onde os arquivos estão; no request cairia no nó que o ALB escolhesse.

⚠️ **A linha `executando` nasce no CONTROLLER, não no worker.** Defeito real encontrado só
no navegador: com a linha nascendo no job, o redirect voltava com `emAndamento = false`, o
acompanhamento automático nunca começava e a tela dizia "nenhuma rodada registrada" logo
depois do clique — como se o botão não tivesse feito nada. Nenhum teste de servidor pegaria
isso sozinho. De quebra, o guarda de "já existe uma em andamento" passou a valer antes de o
worker pegar o job.

⚠️ **Rodada `executando` há mais de 30 min é mostrada como "Interrompida" e libera o
botão.** Sem isso, um worker morto no meio (aconteceu em 28/08 com
`ProcessTimedOutException`) deixaria a linha eterna e o botão travado para sempre — o
usuário ficaria sem saída pela interface, o oposto do que a tela existe para fazer.

⚠️ **`sem_mudanca` é resultado NORMAL, não falha.** É o que a rodada de hora em hora
devolve quase sempre. Pintar de vermelho treinaria qualquer um a ignorar a tela.

⚠️ **A prop de aviso chama-se `aviso`, não `flash`** — `flash` já é compartilhada pelo
`HandleInertiaRequests` e no Inertia a prop de página sobrescreve a compartilhada. Travado
por teste.

Falha ao listar o S3 **não derruba a página**: o inventário mostra o erro e os outros dois
blocos continuam respondendo, que é o que mais importa quando algo está errado.

**Testes:** `tests/Feature/AtualizacaoDadosTest.php` (20 casos). Os dois mais importantes —
"falha no meio interrompe a corrente e não grava o marcador" e "depois de falhar a rodada
seguinte tenta de novo" — foram **verificados por mutação**: gravar o marcador antes da
corrente, ou remover a interrupção, faz os dois falharem.

⚠️ **Armadilha de teste que custou uma rodada:** um segundo `Artisan::shouldReceive('call')`
no mesmo teste **acumula** expectativas em vez de substituir a anterior. Os testes de fase
única passavam e exatamente os quatro de duas fases falhavam. O helper agora é chamado uma
vez por teste, com `reiniciar()` entre as fases.

### 10.7 Enviar os relatórios sem terminal (2026-09-05)

⚠️ **O upload NÃO pode ser um botão no CRM, e isso é arquitetura, não preguiça.** Os
relatórios vivem no OneDrive da máquina do Tony e o CRM roda na AWS — nenhum servidor
alcança aquela pasta. É justamente por isso que a ponte é o S3. O botão "Atualizar agora"
da tela `/atualizacoes` cuida da outra metade (S3 → RDS), que essa mora no servidor.

Três formas de disparar a metade local, da mais manual para a mais automática:

| Como | Arquivo |
|---|---|
| Terminal | `infra/enviar-relatorios-totvs.sh` (Git Bash) |
| Duplo clique | `infra/Enviar relatorios TOTVS.cmd` |
| **Sozinho, a cada N minutos** | **`infra/instalar-inicializacao.ps1`** ← é este que funciona |
| ~~Tarefa agendada~~ | `infra/instalar-tarefa-upload.ps1` — **não funciona nesta máquina**, ver abaixo |

#### 🔴 O Agendador de Tarefas do Windows reporta sucesso sem executar nada

Descoberto em 2026-09-05, tentando automatizar o upload. Na máquina do Tony (domínio
AUTOPEL, conta sem administrador) o Agendador **aceita registrar a tarefa, dispara no
horário, cria o processo, grava no log de eventos "concluída com sucesso, código de
retorno 0" — e a ação não executa.**

Reduzido ao caso mínimo antes de concluir: uma tarefa rodando
`C:\Windows\System32\cmd.exe /c echo ok > arquivo.txt` no próprio perfil do usuário
**não cria o arquivo**, e mesmo assim reporta 0. Não é o script, não é permissão de
registro (registrar funciona), não é caminho: é política da máquina.

⚠️ **`LastTaskResult = 0` NÃO é prova de que algo aconteceu.** Se a tarefa tivesse sido
dada como pronta por causa do código 0 — que foi exatamente o que quase aconteceu —, o
upload teria parado em silêncio e o sintoma apareceria semanas depois como "o CRM está
com dado velho". É o mesmo formato do mês de sincronização parada (§10) e da fila de
29/08: **a prova é o efeito colateral, nunca o código de retorno.**

Por isso `instalar-tarefa-upload.ps1` **se auto-verifica**: depois de registrar, ele
dispara a tarefa e espera o log CRESCER. Se não crescer, remove a tarefa e manda usar a
inicialização — uma tarefa que mente é pior que nenhuma.

Dois defeitos reais foram corrigidos no caminho, e valem para qualquer tarefa agendada
futura:
- **`[TimeSpan]::MaxValue` como `RepetitionDuration` é recusado** (`P99999999DT23H59M59S`,
  HRESULT 0x80041318). Omitir a duração é o certo: vazia significa "indefinidamente".
- **`New-ScheduledTaskTrigger -AtLogOn` sem `-User` é system-wide** e dá "Acesso negado"
  numa conta sem administrador — o erro parece falta de permissão para criar tarefa,
  quando criar tarefa funciona. Escopado ao próprio usuário, registra normalmente.

#### Como o automático funciona hoje

`infra/instalar-inicializacao.ps1` põe um `.vbs` na pasta de Inicialização que sobe
`infra/vigiar-relatorios-totvs.ps1` — um laço que chama o envio de N em N minutos.

- **`.vbs` e não `.lnk`/`.cmd`**: é a única forma de subir sem piscar um console preto a
  cada logon.
- **Mutex de instância única**: sem ele, cada logon deixaria mais um vigia rodando, todos
  disparando `aws s3 sync` sobre os mesmos arquivos.
- **O envio roda como processo FILHO**: uma falha (rede caída, credencial expirada) não
  derruba o vigia; ele tenta de novo no ciclo seguinte.
- **O instalador sobe o vigia na hora**, senão a pessoa instalaria e ficaria sem nada
  rodando até reiniciar — a mesma armadilha do gatilho `-AtLogOn`.
- ⚠️ **O caminho do repositório fica escrito dentro do `.vbs`**: se a pasta do projeto
  mudar de lugar, rodar o instalador de novo.

Verificado em 2026-09-05 pelo efeito, não por código de retorno: dois ciclos consecutivos
com intervalo de 1 min no teste, e o ciclo automático de 5 min da instalação real
(00:49:45 → 00:54:47), com o log crescendo nas duas vezes.

As duas últimas são cascas finas sobre **`infra/enviar-relatorios-totvs.ps1`** — a lógica
(filtros, espera, log) mora num lugar só. A versão `.sh` continua existindo e é a
referência dos filtros; se um mudar, mudar o outro.

⚠️ **`$MinutosParaAssentar` (3 min) existe por causa da automação, e não deve ir a zero.**
Um relatório do TOTVS leva minutos sendo escrito no disco. Se o upload pegar o arquivo
pela metade, o S3 fica com um CSV truncado — e o importador do outro lado **não tem como
saber**: lê as linhas que existem e grava um mês incompleto, sem erro nenhum. Pior, a
impressão digital já terá mudado, então a rodada seguinte considera o trabalho feito e nem
tenta de novo. O risco não existe quando alguém roda na mão (só roda depois de gerar);
ele **nasce** da automação. Arquivo tocado há menos que isso entra como `--exclude` e sobe
no ciclo seguinte.

⚠️ **A tarefa agendada roda como o USUÁRIO, não como SYSTEM** (por isso não pede
administrador). Como SYSTEM ela não teria o perfil do AWS CLI (`crm-v2`) nem enxergaria a
pasta do OneDrive — falharia toda vez, em silêncio.

⚠️ **`MultipleInstances IgnoreNew`**: se a rodada anterior ainda está subindo 200 MB, a
seguinte é descartada em vez de empilhar dois syncs sobre os mesmos arquivos.

Log em `%LOCALAPPDATA%\CRM_V2\upload-totvs.log`, rotacionado em 2 MB — numa tarefa
agendada a saída não vai para lugar nenhum, e sem teto o arquivo cresceria para sempre
(mesmo motivo dos expurgos do resto do sistema).

**Com a tarefa instalada, o fluxo inteiro vira um passo:** gerar o relatório no TOTVS e
salvar na pasta. A tarefa sobe para o S3 em até N minutos e o cron da app-2 importa na
virada da hora.
