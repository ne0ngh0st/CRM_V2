# Importação de dados reais do TOTVS pro CRM-V2

> Documento de planejamento (2026-07-28). Ainda **não implementado** — nenhum comando de import
> além de `legado:import-usuarios` existe hoje. Isto é o registro das decisões e do mapeamento
> de dados levantado numa sessão de exploração, pra não perder o raciocínio antes de codar.

## 1. Contexto

Hoje o CRM-V2 roda 100% com dado mockado (seeders: `ClienteSeeder`, `OrcamentoSeeder`,
`PedidoSeeder`, `LeadSeeder`, `SegmentoVendedorSeeder`). Só `users`/`vendedor_perfis` já
puxam dado real, via `php artisan legado:import-usuarios` (lê `USUARIOS` direto de produção
KingHost, ver [reference `producao_autopel01_acesso_leitura`]).

Faltam: `clientes`, `pedidos`+`pedido_itens`, `leads`, `produtos` (tabela de preços/orçamento).
`orcamentos` fica de fora da rotina recorrente — ver seção 6.

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

### 4.3 Leads

**Fonte real é bem diferente do que o nome `BASE_LEADS` sugere.** No espelho a tabela
`base_leads` (22.570 linhas) vem do arquivo **`base_marco - SQL.xlsx`** (confirmado em
`ROTINAS_CONFIG` do `sql.py`: rotina `"FAT + BASE_MARCO"` importa `FATURAMENTO` +
`BASE_LEADS`). Não é uma lista simples de prospect — é uma **base de enriquecimento externa**
de dados firmográficos (CNAE, faturamento estimado, capital social, lat/long, IBGE,
classificação ABRAS de supermercados) cruzada com sinal de vendedor/status interno. Só uma
fração das ~70 colunas mapeia pro `leads` do v2 (`cnpj`, `RAZAOSOCIAL`, `NOMEFANTASIA`,
`TelefonePrincipalFINAL`, `Email`, `UF`/`CIDADE`, `status`, `CodigoVendedor`/
`vendedornovahierarquia`); o resto (CNAE, capital social, projeções, IBGE, lat/long) é sinal
de prospecção que o `leads` do v2 não tem campo pra guardar hoje.

**Ainda não explorei o CSV/Excel bruto de `base_marco` diretamente** (só vi a config do
mapeamento via `sql.py` e o schema já carregado no espelho) — antes de mapear de verdade,
vale abrir o Excel original e decidir com o Tony quanto desse enriquecimento entra no v2
(campo por campo) vs. fica de fora por escopo.

### 4.4 Produtos (tabela de preços / orçamento)

Tabela `codigo_produtos` no espelho (73.664 linhas), rotina `"PRODUTOS (COD + Produtos SQL)"`
no importador. **Ainda não explorei o mapeamento de colunas nem o CSV/Excel de origem**
(`PRODUTOS - SQL.xlsx`) — pendente pra quando chegar a vez desse domínio.

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
- Decidir com o Tony quanto do enriquecimento de `base_marco`/`BASE_LEADS` entra no `leads`
  do v2 (seção 4.3) — ainda não abri o Excel bruto dessa base.
- Mapear `PRODUTOS - SQL.xlsx` → `codigo_produtos` → `produtos` do v2 (seção 4.4) — não
  investigado ainda.
- Resolver o `de-para` de `COD_SEG` (código numérico do TOTVS) pro nome de segmento
  legível, se o v2 precisar exibir nome em vez de código.
- Semântica de sync ainda em aberto por domínio: `updateOrCreate` nunca remove — decidir se
  cliente que sumiu do legado devia ser marcado inativo/removido, e como tratar pedido que
  muda de status entre uma rodada de import e outra.
- Fechar a ordem de construção dos comandos (seção 3 sugere Clientes → Pedidos → Leads →
  Produtos, mas ainda não confirmado com o Tony).
