# Power BI lendo o RDS do CRM-V2

O relatório da diretoria (`BI_RADES CORRETO.pbix`) lia views `vw_bi_*` do MariaDB
`autopel01`, na KingHost, que sai do ar até **31/10/2026**. Ele passa a ler as mesmas
views, com os mesmos nomes e colunas, num schema `bi` do RDS `crm-v2-prod`, e a atualizar
sozinho depois de cada importação do TOTVS.

Plano completo (fases 0 a 5): `~/.claude/plans/vamos-planejar-essa-migra-o-jazzy-clover.md`.
Este documento cobre o que a **Fase 1** (código do CRM, 2026-09-16) entregou e como pôr
em produção.

## 1. O caminho do dado

```
relatórios TOTVS ─► Enviar relatorios TOTVS.cmd ─► S3 ─► totvs:atualizar (de hora em hora ou botão)
   ─► palma_v2 (RDS) ─► views bi.vw_bi_* ─► gateway ─► dataset do Power BI
                          ▲                               ▲
                          └── bi_leitura (só SELECT)       └── AtualizarPowerBiJob pede o refresh
```

## 2. O que existe no código

| Peça | Onde |
|---|---|
| Nome do schema (`bi`; `bi_test` na suíte) | `config('powerbi.schema')`, `App\Services\PowerBi\SchemaBi` |
| Tabelas de referência (IBGE, de-para, indicadores, potencial) | migration `2026_09_16_110000` |
| Carga dessas tabelas | `php artisan bi:carregar-referencias` ← `database/dados-bi/` |
| As 13 views | `App\Services\PowerBi\ViewsBi` (migration `2026_09_16_120000` e `bi:recriar-views`) |
| Cobertura de município e família por ano | `php artisan bi:cobertura` |
| Schema e usuário `bi_leitura` no RDS | `infra/bi/criar-schema-e-usuario.sh` |
| Refresh depois da importação | `AtualizarPowerBiJob`, `PowerBiRefresher`, `TravaDeRefreshPowerBi` |

### 2.1 As views

| View | Fonte no CRM | Substitui |
|---|---|---|
| `vw_bi_dim_cliente` | `clientes` + `segmentos` + `grupos_cliente` + de-para | view do legado |
| `vw_bi_dim_produto` | `produtos` + códigos vendidos fora do cadastro | view do legado |
| `vw_bi_dim_vendedor` | `vendedores_totvs` ∪ `vendedor_perfis` + `users` + papéis | view do legado |
| `vw_bi_dim_geografia` | `bi.ibge_municipios` | view do legado |
| `vw_bi_fato_faturamento` | `faturamentos` + de-para | view do legado |
| `vw_bi_fato_pedidos_abertos` | `pedidos` (sem faturamento) + itens + cliente | view do legado |
| `vw_bi_fato_pedidos_emitidos` | `pedidos` + itens + cliente + vendedor | SELECT em `META_VENDA` |
| `vw_bi_fato_orcamentos` | `orcamentos` + cliente + perfil do criador | view do legado |
| `vw_bi_fato_leads` | `leads` + cliente e faturamento pelo CNPJ | view do legado |
| `vw_bi_fato_indicadores_municipio` | `bi.indicadores_municipio` | view do legado |
| `vw_bi_potencial_estado` | `bi.potencial_mercado_estado` | SELECT direto |
| `vw_bi_fato_metas` | `metas_mensais` (tela `/metas`) | SELECT em `METAS_MENSAIS` |
| `vw_bi_seg_acesso` | `users` + perfis + `vw_bi_dim_vendedor` | view do legado (RLS) |

O SQL original do legado está em `database/dados-bi/views-legado.sql`.

### 2.2 Diferenças esperadas contra o BI antigo (documentar, não corrigir)

- **CNPJ só com dígitos** em todas as views. Isso também conserta o relacionamento
  Leads ↔ Clientes, que antes casava zero linha.
- **Histórico maior**: o faturamento vai de 2018 a hoje, numa tabela só (`origem_tabela`
  é sempre `FATURAMENTO`).
- **Vendedor**: uma linha por código, com o usuário ativo vencendo. Os filtros fixos por
  nome no M (PAULO DE TARSO, ROBERTO, MARCOS AURELIO) deixam de ser necessários. Código
  que só existe no TOTVS sai com o nome do TOTVS e perfil `nao_cadastrado`.
- **`cod_gerente` é texto** com zero à esquerda (`010002`), não número.
- **Orçamentos**: `status`, `status_cliente`, `tipo_faturamento` e
  `data_aprovacao_cliente` saem **vazios** (decisão do Tony, 16/09). O CRM não tem
  aprovação do cliente, então a medida "Taxa de Conversao de Orcamentos" fica em branco.
  `status_gestor` é a aprovação interna.
- **Leads**: `status` é a situação do cliente (`ativo`, `inativando`, `inativo`, `nunca
  comprou`, ou `prospect` quando o CNPJ não está na carteira), não a etapa do funil.
  `origem` ganhou `SITE` (formulário do site). `faturamento_bruto_2024` só existe para
  lead manual (valor estimado).
- **Pedidos emitidos**: `ATIVIDADE` é o nome do segmento do cliente; `REPRES` e
  `SUPERVISOR` são os nomes da dimensão de vendedor.
- **Pedidos em aberto**: `atraso` é calculado (dias desde a previsão de faturamento,
  nunca negativo). Loja, CNPJ e município vêm do cadastro do cliente.
- **Filial dos pedidos** só aparece nos pedidos importados depois deste deploy.
- **Datas** saem como DATE/DATETIME. Os passos do M que convertiam texto em data podem
  sair na Fase 3.
- **Status do cliente** continua com os cortes do BI (295/365 dias). A Carteira do CRM
  usa 290; unificar é decisão de negócio.

## 3. Pôr em produção (ordem obrigatória)

1. **RDS para `db.t4g.medium`** (Fase 2.1). A reimportação do histórico e o primeiro
   refresh completo pesam na memória, e a `small` já está sem folga.
2. **Criar o schema e o usuário**, de dentro de um nó do app, com a credencial master:
   ```bash
   MASTER_USER=<usuario master> bash infra/bi/criar-schema-e-usuario.sh
   ```
   Guardar a senha do `bi_leitura` que ele mostra (uma vez só).
   ⚠️ Tem que rodar **antes** do deploy: a migration `2026_09_16_110000` para se o schema
   não existir.
3. **Deploy da branch** (`infra/deploy.sh`). As migrations criam as colunas novas, as
   tabelas de referência e as views.
4. **Carregar as referências**: `php artisan bi:carregar-referencias --force`.
5. **Reimportar o histórico do faturamento** (seção 4).
6. **Conferir**: `php artisan bi:cobertura` (meta: ≥ 99% de município resolvido).
7. **Gateway e dataset** (Fases 2 e 3), usando o `bi_leitura`.
8. **Ligar o refresh** (seção 5).

### 3.1 Local (Docker)

O usuário `palma` do Docker não cria schema. Uma vez só, como root:

```sql
CREATE DATABASE bi DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE bi_test DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON bi.* TO 'palma'@'%';
GRANT ALL PRIVILEGES ON bi_test.* TO 'palma'@'%';
```

⚠️ A suíte usa `bi_test` (forçado no `phpunit.xml`), e a trava do `tests/TestCase.php`
aborta se o schema do BI não tiver "test" no nome: o `migrate:fresh` não apaga o schema
`bi`, e as migrations o recriam vazio.

## 4. Histórico do faturamento com loja, município e família

O 198 atual já traz tudo. Para 2018–2025, os xlsx de `RELATORIOS TOTVS\Legacy`:

| Ano | Loja/Estado/Município | Família |
|---|---|---|
| 2018–2023 (`AAAA.1.xlsx`) | sim | **não** |
| 2024, 2025 (`arrumado negativos\… final (com negativos).xlsx`) | sim | sim |

A família dos anos antigos vem de um de-para produto → família montado com o que já tem
família. **A ordem importa:**

1. Converter e reimportar **2024 e 2025** (e deixar o 198 rodar):
   ```bash
   python scripts/faturamento_xlsx_para_csv.py "…/2024 final (com negativos).xlsx" storage/app/fat-2024.csv
   php artisan legado:import-faturamento-arquivo storage/app/fat-2024.csv --ano=2024
   ```
2. Gerar o de-para: `php artisan faturamento:de-para-familias` (grava em
   `storage/app/bi/de_para_familias.csv` e lista os produtos com mais de uma família).
3. Converter e reimportar **2018–2023** passando o de-para:
   ```bash
   python scripts/faturamento_xlsx_para_csv.py "…/2019.1.xlsx" storage/app/fat-2019.csv --familias storage/app/bi/de_para_familias.csv
   php artisan legado:import-faturamento-arquivo storage/app/fat-2019.csv --ano=2019
   ```
   O conversor informa quantas linhas ficaram sem família.
4. Jan–ago/2026: gerar o 198 dos meses no TOTVS e deixar o `totvs:atualizar` refazer.

⚠️ `--ano` apaga o ano antes de inserir (é o que torna a carga repetível). O comando
mostra o banco-alvo e pede confirmação. Em produção, rodar fora do horário de uso.

⚠️ A carga é mais lenta com índices; ver a lição de 2026-08-31 no CLAUDE.md.

## 5. Refresh automático

Depois de cada `totvs:atualizar` que termina em **sucesso**, o `AtualizarPowerBiJob` pede
o refresh ao Power BI. O resultado aparece em `/atualizacoes`, no passo
`powerbi:refresh` da rodada.

- **Nasce desligado.** Para ligar, no `.env` dos dois nós:
  ```
  POWERBI_REFRESH_HABILITADO=true
  POWERBI_TENANT_ID=455c3f1c-0a92-4f6d-8943-26ee08301ad0
  POWERBI_CLIENT_ID=<app registrado>
  POWERBI_CLIENT_SECRET=<segredo>
  POWERBI_WORKSPACE_ID=<workspace>
  POWERBI_DATASET_ID=<dataset novo>
  ```
  e depois `php artisan config:cache` e `php artisan queue:restart` (quem dispara é o
  worker, que leu a config no boot).
- **Pré-requisitos no tenant** (Fase 2.5): app registrado com segredo; "Service principals
  can use Fabric APIs" liberado; o service principal como membro do workspace; o service
  principal adicionado à fonte de dados do gateway.
- **Trava de cota:** no máximo 6 disparos em qualquer janela de 24 h e 90 min entre eles
  (`POWERBI_REFRESH_MAX_POR_DIA`, `POWERBI_REFRESH_INTERVALO_MINUTOS`). Um disparo barrado
  se reagenda para quando a janela abrir; importações seguintes não empilham outro.
- ⚠️ **Desligar o refresh agendado do dataset no Serviço.** Ele gasta a mesma cota de 8 por
  dia sem a trava saber.
- **Falha do BI não é falha da importação.** A rodada continua `sucesso`; o passo fica
  vermelho. Recusa (credencial, permissão, cota) não retenta; rede e 5xx retentam 3 vezes.

## 6. Custo das views (medido no dev, 2026-09-16)

Leitura completa, como o refresh faz:

| View | Linhas | Tempo |
|---|---:|---:|
| `vw_bi_fato_faturamento` | 5.996.448 | 29 s |
| `vw_bi_fato_pedidos_emitidos` | 602.965 | 11,6 s |
| `vw_bi_dim_produto` | 28.896 | 11 s |
| `vw_bi_fato_leads` | 17.173 | 2,9 s |
| `vw_bi_dim_cliente` | 92.488 | 1,7 s |
| demais | < 32 mil | < 0,6 s cada |

- **Pedidos emitidos** levava 39 s: o MySQL fundia a dimensão de vendedor na consulta e
  refazia o `ROW_NUMBER` por item. Entrando por uma derivada com `GROUP BY`, a dimensão é
  calculada uma vez. O hint `NO_MERGE` é ignorado dentro de view.
- **Produto** levava 28 s: o `UNION ALL` materializava as 6,6 M de linhas antes de
  filtrar os órfãos. Filtrar dentro de cada ramo e agrupar pelo código cru baixou para 11 s.
- **Faturamento com filtro de data** (refresh incremental) usa `fat_data_valor_idx`
  (`range`), e o de-para entra por chave primária.
- **Município resolvido** em 99,91% dos clientes (o legado media 99,43%).

Medir de novo no RDS no primeiro refresh completo, olhando `FreeableMemory` e `SwapUsage`.

## 7. Permissões provadas

Com um `bi_leitura` local recebendo só os grants do script: lê as 13 views; não
escreve; não enxerga tabela fora da lista; sem um dos grants, a view que depende dele
recusa (`ERROR 1356`). O `SchemaBiTest` garante que a lista do script e a de
`SchemaBi::TABELAS_DO_APP` são a mesma — view nova lendo tabela nova precisa entrar nas
duas.
