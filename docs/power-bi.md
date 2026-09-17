# Power BI lendo o RDS do CRM-V2

O relatório da diretoria (`BI_RADES CORRETO.pbix`) lia views `vw_bi_*` do MariaDB
`autopel01`, na KingHost, que sai do ar até **31/10/2026**. Ele passa a ler as mesmas
views, com os mesmos nomes e colunas, num schema `bi` do RDS `crm-v2-prod`, e a atualizar
sozinho depois de cada importação do TOTVS.

Plano completo (fases 0 a 5): `~/.claude/plans/vamos-planejar-essa-migra-o-jazzy-clover.md`.
**A migração foi concluída em 2026-09-17**: o relatório em uso é o `BI_RADES RDS`
(`POWER BI\BI_RADES RDS.pbip`), lendo o `bi` do RDS pelo gateway. Este documento cobre o
código (§1-§7), o gateway (§8), as armadilhas do dia da virada (§9) e a rotina (§10).

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
2. **Criar o schema e o usuário**, de dentro de um nó do app. O master do RDS é o próprio
   `palma`, e o script lê a senha vigente do `.env` do nó:
   ```bash
   cd /var/www/crm && bash infra/bi/criar-schema-e-usuario.sh
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

## 5. Refresh

### 5.1 O que está valendo: refresh AGENDADO no Serviço (decisão de 2026-09-17)

O gateway fica numa EC2 que **só liga nas janelas** (§8), então o refresh é o agendado do
próprio Power BI, **dentro delas**:

| Refresh agendado no Serviço | EC2 liga | EC2 desliga |
|---|---|---|
| 10:30 | 10:10 | 11:20 |
| 13:30 | 13:10 | 14:20 |

Segunda a sexta, horário de São Paulo. **O disparo pela API fica desligado**
(`POWERBI_REFRESH_HABILITADO=false`): com o gateway desligado na maior parte do dia, ele
falharia a cada importação.

⚠️ **Mudou o horário no Serviço? Mude as janelas em `infra/bi/agendar-gateway.sh`** e rode de
novo. As duas coisas são o mesmo horário escrito em dois sistemas que não se conhecem;
descasadas, o refresh roda com o gateway desligado e falha.

⚠️ **Motivo**: a `t3.large` Windows ligada 24 h custaria ~R$ 600/mês para ficar ociosa quase o
dia todo. Agendada, são ~2h20 por dia útil (~R$ 90–110/mês com o disco).

### 5.2 Disparo pela API depois da importação (pronto, desligado)

Se o gateway voltar a ficar ligado 24 h, basta ligar este caminho: depois de cada
`totvs:atualizar` que termina em **sucesso**, o `AtualizarPowerBiJob` pede o refresh ao
Power BI. O resultado aparece em `/atualizacoes`, no passo `powerbi:refresh` da rodada.

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

## 8. O gateway (EC2 Windows)

| | |
|---|---|
| Instância | `crm-v2-bi-gateway` (`i-00f370d0e45f41531`), `t3.large`, Windows Server 2022, créditos `unlimited` (em `standard` o saldo zerava no refresh; ver `infra/bi/criar-gateway.sh`) |
| Rede | subnet `crm-v2-publica-1a`; SG `crm-v2-bi-gateway` sem entrada exceto RDP dos IPs do Tony; `crm-v2-db` aceita 3306 desse SG |
| Chave | `~/.ssh/crm-v2-bi-gateway` (RSA, gerada localmente; só serve para ler a senha inicial do Administrator) |
| Liga/desliga | EventBridge Scheduler, 4 schedules `crm-v2-bi-gateway-*`, role `crm-v2-bi-gateway-agenda` (só Start/Stop desta instância) |
| Scripts | `infra/bi/criar-gateway.sh` (perfil `crm-v2`), `infra/bi/agendar-gateway.sh` (perfil `default`, admin) |

⚠️ **O IP público muda a cada vez que a máquina liga** (sem Elastic IP, de propósito: o
gateway só faz conexões de saída). Para o RDP, consultar o IP da vez:

```bash
aws ec2 describe-instances --region sa-east-1 --instance-ids i-00f370d0e45f41531 --query "Reservations[0].Instances[0].PublicIpAddress" --output text
```

⚠️ **Fora das janelas a máquina está desligada.** Para mexer nela fora de hora:
`aws ec2 start-instances --region sa-east-1 --instance-ids i-00f370d0e45f41531` — e o
schedule seguinte a desliga sozinho.

### 8.1 Instalação (feita em 2026-09-17, por SSM + RDP)

A instalação NÃO precisou de RDP: a EC2 ganhou a role `crm-v2-bi-gateway-ec2`
(`infra/bi/habilitar-ssm-gateway.sh`, perfil admin — só `AmazonSSMManagedInstanceCore`) e
o `infra/bi/instalar-gateway.ps1` rodou por `AWS-RunPowerShellScript`. Ele baixa e instala
em silêncio o **MySQL Connector/NET 26.7.0** (conferindo o MD5 publicado pela Oracle) e o
**On-premises data gateway** (conferindo a assinatura digital da Microsoft), e no fim
imprime o estado do serviço `PBIEgwService` e o provedor MySQL registrado no .NET.

⚠️ **Depois de anexar a role, a instância precisou de um reboot** para o agente do SSM
pegar a credencial. Antes disso ela não aparecia em `describe-instance-information`.

O que exigiu pessoa (não é automatizável sem service principal):

1. **RDP** no IP da vez, usuário `Administrator` (senha inicial via `get-password-data`,
   trocada no primeiro acesso).
2. Abrir o app **"On-premises data gateway"**, entrar com a conta Microsoft do Tony e
   **registrar** o gateway como `crm-v2-bi-gateway`, guardando a chave de recuperação no
   cofre.
3. No Serviço (app.powerbi.com → ⚙️ → *Gerenciar conexões e gateways* → **Nova** →
   **Local**): conexão `CRM V2 - RDS (bi)`, tipo **MySQL**, servidor
   `crm-v2-prod.c3mguim6agp4.sa-east-1.rds.amazonaws.com`, banco `bi`, autenticação
   **Básica** com `bi_leitura`, *Usar conexão criptografada* marcado.
4. Ligar o modelo semântico a essa conexão (⋯ → Configurações → **Conexão de gateway**).

## 9. O dia da virada (2026-09-17) — o que quebrou, e por quê

Ordem real dos fatos e das armadilhas. Vale mais que o plano: cada item abaixo custou uma
rodada.

### 9.1 O relatório não usava o conector MySQL — usava ODBC

O `.pbix` lia `Odbc.DataSource("dsn=mysql06-farm88.kinghost.net", …)` (9 tabelas) e
`Odbc.Query(…)` com SELECT escrito à mão (3 tabelas). **"Alterar Fonte" não resolve**: o
gateway tem o Connector/NET, não um DSN ODBC. As 12 partições passaram a
`MySQL.Database("<rds>", "bi", [ReturnSingleDatabase = true]){[Schema="bi", Item="<view>"]}`.

As três de `Odbc.Query` apontavam para tabelas do legado e viraram views do `bi` com as
MESMAS colunas — por isso o resto do M (tipagem, renomes) seguiu valendo sem tocar:

| SELECT antigo | view nova |
|---|---|
| `autopel01.METAS_MENSAIS` | `vw_bi_fato_metas` |
| `autopel01.META_VENDA` | `vw_bi_fato_pedidos_emitidos` |
| `autopel01.potencial_mercado_estado` | `vw_bi_potencial_estado` |

⚠️ **A edição foi feita nos arquivos de TEXTO do modelo**, não pela interface: *Arquivo →
Salvar como → Projeto do Power BI (.pbip)* gera `BI_RADES RDS.SemanticModel/…/tables/*.tmdl`,
onde cada partição é um bloco `source = let … in …`. Com o Desktop FECHADO, editar ali e
reabrir é mais seguro (e muito mais rápido) que repetir 12 vezes o Editor Avançado.

### 9.2 `max_user_connections 5` derrubou o primeiro refresh

Erro do Serviço: `User 'bi_leitura' has exceeded the 'max_user_connections' resource`
(MySQL 1226). **O refresh abre uma conexão por tabela, em paralelo** — 12 tabelas contra um
teto de 5. Subiu para **20** (`MAX_CONEXOES_BI` em `infra/bi/criar-schema-e-usuario.sh`, que
agora aplica o `ALTER USER` SEMPRE, não só quando cria o usuário).

### 9.3 Créditos de CPU `standard` não sobrevivem a uma máquina que desliga

Durante o primeiro refresh completo o saldo caiu de 19 para 11 em 15 min. Como a EC2 fica
ligada ~2 h por dia útil, o saldo nunca acumula: zerado, a CPU cai para 30% e o refresh
arrasta. Trocado para **`unlimited`** (excedente < US$ 3/mês nessa janela).

### 9.4 🚨 O Desktop NÃO atualiza dados — e publicar sobe o modelo VAZIO

O RDS não é público (`PubliclyAccessible=false`): quem alcança o banco é a EC2 do gateway.
No Desktop, *Atualizar* dá erro de conexão — e **publicar sobe as tabelas como estão na
memória dele**, que é vazio quando o arquivo foi aberto sem refresh.

Aconteceu: depois de publicar o visual novo, o relatório no Serviço ficou **sem dado
nenhum** até a atualização seguinte. Não é perda de dado — o banco está intacto.

**Decisão do Tony (17/09): fica assim, o banco NÃO é exposto.** Foram avaliadas as duas
alternativas para o Desktop enxergar o banco, e as duas custam mais do que resolvem:

- **Abrir o RDS para os IPs do Tony** não é um botão: as sub-redes do banco são privadas
  (sem rota para internet), então `PubliclyAccessible=true` sozinho não faz nada — seria
  preciso MOVER o banco de produção (Multi-AZ) para sub-redes públicas, com indisponibilidade
  e perda permanente do isolamento.
- **Túnel SSH pelo app-1** exige linha no `hosts` do Windows (para manter o mesmo nome de
  servidor do modelo) — testado: **precisa de administrador, que esta máquina não dá** — e a
  porta 3306 local está ocupada pelo MySQL do Docker.

**A regra: toda publicação exige um refresh no Serviço logo depois**, e refresh só funciona
com o gateway ligado. Portanto: publicar DENTRO das janelas (10:10-11:20 / 13:10-14:20) ou
ligar a máquina na mão antes (§8), e conferir também a **Conexão de gateway** do modelo, que
pode se desfazer na substituição.

### 9.5 O horário do refresh mora em DOIS sistemas

Refresh agendado no Serviço às **10:30 e 13:30**; EC2 ligando 10:10/13:10 e desligando
11:20/14:20 (`infra/bi/agendar-gateway.sh`). A primeira tentativa foi 11:00/14:00 e bateu de
frente com o `totvs:atualizar`, que roda na hora cheia e leva ~2 min — o refresh podia ler o
faturamento no meio da importação. Meia hora depois da hora cheia resolve os dois problemas
(coincidência e janela).

⚠️ Mudar um sem o outro faz o refresh rodar com o gateway desligado, e a falha só aparece no
histórico de atualizações. O script **remove** schedules que saíram da lista, então mudar
horário é editar `JANELAS` e rodar de novo.

### 9.6 RLS: já existia no modelo, e continua valendo

Os perfis `Administradores` (sem filtro) e `Gestores` (filtra `Vendedores` e `Clientes` pelo
`USERPRINCIPALNAME()`) vieram do modelo antigo e foram junto. A tabela `Acesso RLS` agora sai
de `bi.vw_bi_seg_acesso`, que deriva o mapa e-mail → códigos do próprio CRM: diretor e admin
veem tudo; supervisor/gerente veem a equipe; vendedor vê o próprio código.

- **Só entra quem tem e-mail `@autopel.com` e está ativo** — hoje 27 pessoas. Representante
  com e-mail de fora não aparece no mapa e, dentro de `Gestores`, não veria nada.
- ⚠️ **Quem é Administrador/Membro/Colaborador do workspace ignora o RLS.** Para o filtro
  valer, a pessoa precisa entrar como **Visualizador**, por compartilhamento ou pelo App.
- Testar sem outra conta: ⋯ do modelo → **Segurança** → ⋯ em `Gestores` → **Testar como
  função** → *Outro usuário* com o e-mail. Conferido em 17/09 contra o banco (agosto/2026):
  `inaya.silva@` ≈ R$ 192.772 (1 vendedor) e `cleber@` ≈ R$ 5.362.525 (41 vendedores).
- ⚠️ **`tainara.bela@autopel.com` enxerga vazio**: o `cod_vendedor` dela está com 5 dígitos
  (`00006`) e o TOTVS emite `000006`. É a pendência do CLAUDE.md, não é defeito do RLS.

### 9.7 O visual do relatório foi refeito nos arquivos (2026-09-17)

Sintomas: cabeçalhos com serifa, tabelas minúsculas, títulos repetidos.

- **Serifa** = as tabelas pediam a fonte **Montserrat**, que o navegador não tem, e caíam para
  Times. Todo `fontFamily`/`fontSize` fixado no visual foi removido para o TEMA decidir.
- **Tabelas minúsculas** = página de 2.160 px com `displayOption: FitToPage`. Todas passaram a
  **`FitToWidth`**.
- **Tema `AutopelTheme` estava registrado no `report.json` mas o ARQUIVO não existia.** Criado
  `StaticResources/RegisteredResources/AutopelTema2026.json` (cores oficiais, Segoe UI,
  cabeçalho navy, faixa alternada, total destacado, card branco com título em faixa preta —
  a mesma linguagem do `DarkCard` do CRM) e apontado em `themeCollection.customTheme`.
- Abas renomeadas: **PEDIDOS DIÁRIOS**, **VENDA NO ANO**, **VENDA x META DO MÊS** (era
  "Duplicata de…", e não é duplicata: é o recorte de um mês), **PEDIDOS x META**.

⚠️ **Editar o relatório por arquivo só é possível no formato PBIR** (`definition/pages/<id>/
visuals/<id>/visual.json`), que é o que o `.pbip` salva. Regras que valeram aqui: formatação
fixada no visual VENCE o tema — para o tema valer, apague a propriedade do visual; e o
Desktop precisa estar FECHADO durante a edição.

## 10. Rotina depois da virada

| Quando | O que fazer |
|---|---|
| Dia a dia | Nada. A EC2 liga, o Serviço atualiza às 10:30 e 13:30 e a EC2 desliga. |
| Mudou view/modelo | Deploy do CRM (se mexeu em `ViewsBi`) → abrir o `.pbip` → publicar → **atualizar no Serviço**, dentro da janela. |
| Mudou só o visual | Publicar → **atualizar no Serviço** (senão fica sem dado, §9.4). |
| Mudar horário do refresh | Trocar nos DOIS lugares: agendamento do Serviço e `JANELAS` do `agendar-gateway.sh`. |
| Publicar fora da janela | `aws ec2 start-instances …` antes, e desligar depois (`stop-instances`). |
| Falhou o refresh | Histórico de atualizações do modelo → mensagem. Erros já vistos: conexões (§9.2), gateway desligado (§9.5), modelo publicado vazio (§9.4). |

