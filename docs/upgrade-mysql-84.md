# Upgrade do RDS: MySQL 8.0 → 8.4

> Plano escrito em 2026-11-04. **Nada foi executado ainda.**

## 1. Por que fazer

O `crm-v2-prod` roda **MySQL 8.0.46**. O suporte padrão do 8.0 acabou, e a AWS passou a
cobrar o **RDS Extended Support** por vCPU-hora.

| Outubro/2026 | US$ |
|---|---:|
| `SAE1-ExtendedSupport:Yr1-Yr2:MySQL8.0` | **604,96** |
| Instância db.t4g.medium Multi-AZ | 204,60 |
| Conta AWS inteira (com imposto) | ~1.319 |

O Extended Support custa **três vezes o preço da própria instância** e não compra nada
(nem CPU, nem RAM, nem disco). No 8.4 ele vai a zero, e a conta cai para algo perto de
US$ 650-700/mês.

**Cada dia de espera custa ~US$ 20.** E o preço do Extended Support **sobe** no 3º ano
(`Yr3`).

## 2. O que já foi conferido (2026-11-04)

| Item | Estado | Risco no 8.4 |
|---|---|---|
| Alvos de upgrade a partir do 8.0.46 | 8.4.3 → **8.4.11** | usar o 8.4.11 (o mais novo) |
| Usuários `palma` e `bi_leitura` | `mysql_native_password` | **nenhum**: no RDS 8.4, `mysql_native_password = ON` e o parâmetro nem é modificável |
| Plugin padrão de usuário NOVO | `caching_sha2_password` | ver §6 (só se o `bi_leitura` for recriado) |
| `sql_mode` | `NO_ENGINE_SUBSTITUTION` | default do 8.4 é igual |
| Engines | 80 tabelas InnoDB + 13 views (`bi`) | nenhum |
| Procedures, functions, triggers | nenhum | nenhum |
| Tamanho | 3,2 GB | Blue/Green cria o verde em minutos |
| Parâmetros customizados (`crm-v2-mysql80`) | `slow_query_log=1`, `long_query_time=0.5`, `log_output=FILE`, `max_allowed_packet=64M` | precisam ser recriados num grupo `mysql8.4` |
| Option group | `default:mysql-8-0` | trocar por `default:mysql-8-4` |
| Binlog | ligado (retenção de backup 7 dias), `binlog_format=MIXED` | pré-requisito do Blue/Green, ok |
| Senha gerenciada pelo Secrets Manager | **desligada** (`MasterUserSecret = null`) | sem conflito com Blue/Green |
| Deletion protection | ligada | ok, só desligar no `-old1` na hora de apagar |
| Docker de dev | `mysql:8.0` | atualizar depois (§5, Fase 3) |

**O maior risco não é compatibilidade, é PLANO DE EXECUÇÃO.** O otimizador muda entre
versões maiores, e este projeto depende de planos específicos (covering index nos
faturamentos, `joinSub` em vez de `whereIn` na Carteira, ver `docs/performance.md`). Uma
consulta que hoje leva 200 ms pode virar 4 s no 8.4 sem dar erro nenhum. Por isso a
validação no verde (Fase 1) mede tempo, não só "funciona".

## 3. Estratégia: Blue/Green, não upgrade in-place

| | In-place (`modify --engine-version`) | **Blue/Green** |
|---|---|---|
| Indisponibilidade | 10-20 min (Multi-AZ não ajuda: os dois lados atualizam juntos) | **~1 min** no switchover |
| Testar antes de virar | não | **sim**: o verde fica de pé, já no 8.4, com dado real e atualizado |
| Endpoint | o mesmo | o mesmo (o switchover troca os nomes) |
| Custo extra | zero | o verde enquanto existir: ~US$ 0,28/h (algumas horas = poucos dólares) |

⚠️ **Não existe "voltar" depois do switchover sem perder dado.** O Blue/Green não replica
do verde para o azul depois da virada. O azul antigo (`crm-v2-prod-old1`) fica parado no
estado do minuto da virada; voltar para ele perde tudo o que foi escrito depois. **A rede
de segurança é a validação ANTES da virada**, não o rollback.

## 4. Janela

- **Fora** da janela de backup (05:00-06:00 UTC = 02:00-03:00 BRT) e da de manutenção
  (dom 06:30-07:30 UTC).
- **Logo depois** de uma rodada do `totvs:atualizar` (cron horário na app-2). Ex.: sábado,
  **xx:20**. Se uma rodada cair no minuto da virada, ela falha e a seguinte refaz (o import
  é idempotente), mas é melhor não pagar para ver.
- **Sábado**, não domingo: domingo 04:00 roda a carga da Receita (`receita:importar-situacoes`,
  ~20 min, pesada em escrita).
- Fora dos horários do refresh do Power BI (seg-sex 10:30 e 13:30).

## 5. Passo a passo

### Fase 0: suíte de testes contra MySQL 8.4 (local, ~1 h, sem custo)

Pega erro de SQL e de migration antes de gastar com o verde.

1. Subir um MySQL **8.4 em container separado**, numa porta livre (ex. `3308`), com um
   banco `palma_v2_test`. **Não trocar a imagem do serviço `mysql` do compose ainda**: o
   8.4 atualiza o volume `mysql_data` no lugar e não volta para o 8.0.
2. Rodar `php artisan test` apontando para ele (Regra nº 7: conferir o banco na mira
   antes; o nome tem "test", então a trava do `TestCase` vale).
3. Combinar com qualquer outra sessão aberta: `palma_v2_test` e o Docker são recursos
   únicos (Regra nº 10).

Suíte verde → seguir. Vermelha → corrigir antes.

**✅ Feita em 2026-11-04: aprovada.** Container `mysql:8.4.11` isolado (com
`--mysql-native-password=ON` e o `sql_mode` de produção, igual ao RDS) e bancos
`palma_v2_test`/`bi_test` próprios; o `palma_v2_test` compartilhado não foi tocado.
**1.389 testes, 1.386 passaram.** As 3 falhas se repetem idênticas num `mysql:8.0` também
isolado, ou seja, são testes desatualizados e não incompatibilidade:
- `CarteiraExportContatoTest`: o teste não conhece as colunas Capital social e Porte;
- `PwaTest`: procura o ícone com `?v=autopel` no nome do arquivo;
- `EspecialistaSegmentoTest`: a chave `dados` sumiu do resumo da Visão Diretor.

⚠️ **Achado colateral: o `php artisan test` local estava rodando só 444 dos 1.389
testes.** O `RecursiveDirectoryIterator` do PHP perde entradas no bind-mount Windows →
container (o `ls` e o `find` enxergam os 125 arquivos de `tests/Feature`; o iterador que o
PHPUnit usa devolve 20). Não tem relação com o MySQL. **Corrigido no mesmo dia**: a suíte
inteira roda com `composer test` (lista explícita de arquivos), e uma extensão do PHPUnit
aborta a rodada completa quando falta arquivo. Os 3 testes desatualizados também foram
corrigidos. Para repetir esta fase contra outro MySQL:

```bash
docker compose run --rm --no-deps -e DB_HOST=<host> app composer test
```

### Fase 1: criar o verde e validar (dia útil, ~2 h, ~US$ 1-2)

1. **Snapshot manual** do `crm-v2-prod` (`pre-upgrade-84-AAAAMMDD`). É o ponto de volta
   absoluto, independente do Blue/Green.
2. **Grupo de parâmetros `crm-v2-mysql84`** (família `mysql8.4`) com os 4 parâmetros
   customizados do §2, **mais `innodb_buffer_pool_size` e `innodb_adaptive_hash_index`**
   (ver o resultado abaixo).
3. **Criar o Blue/Green Deployment** com `--target-engine-version 8.4.11`,
   `--target-db-parameter-group-name crm-v2-mysql84` e o option group
   `default:mysql-8-4`. O RDS roda o pre-check de upgrade sozinho; se recusar, o motivo
   fica no log `PrePatchCompatibility.log` da instância.
4. **Esperar a réplica alcançar** (status `AVAILABLE`, `ReplicaLag` ≈ 0).
5. **Validar no verde** (ele aceita só leitura; conectar da app-2 pelo endpoint do verde):
   - login dos dois usuários (`palma` e `bi_leitura`);
   - `SELECT` em cada uma das 13 views `bi.vw_bi_*`;
   - `EXPLAIN` + tempo das consultas críticas, lado a lado com o azul, **intercalando as
     medições** (lição de 2026-09-04):
     - comparação de faturamento do ano (deve continuar `Using index`, ~660 ms);
     - Carteira agrupada, escopo empresa, com `?status=inativo` (o `joinSub`);
     - total da paginação da Carteira;
     - `ultimaLigacao()` da Visão do Gestor;
     - funil de leads, contagem das colunas;
     - `metaGauge`, escopo empresa.
   - Algum plano piorou (sumiu o `Using index`, apareceu `type: ALL` onde não tinha)?
     **Parar.** Ajustar índice ou consulta antes de virar.

**✅ Feita em 2026-11-04: aprovada, com um ajuste de parâmetro OBRIGATÓRIO.**

| Peça | Nome |
|---|---|
| Snapshot manual | `pre-upgrade-84-20261104` |
| Grupo de parâmetros | `crm-v2-mysql84` |
| Blue/Green | `crm-v2-upgrade-84` (`bgd-8hjvbelzlx0msmhi`) |
| Verde | `crm-v2-prod-green-gnaxsa` (8.4.11, Multi-AZ, primário em `sa-east-1a`) |

- Logins `palma` e `bi_leitura` mantêm `mysql_native_password` (plugin ACTIVE);
  `sql_mode` e os 4 parâmetros iguais; réplica em dia.
- As 13 views do BI respondem. `vw_bi_dim_produto`, a mais pesada, ficou **mais rápida**
  no 8.4: 34 s contra 49-51 s, aquecido e intercalado. A primeira leitura no verde frio
  levou 279 s, só cache.
- Páginas comparadas pelo kernel HTTP (`infra/comparar-banco-84.php`), admin, cache
  desligado, mínimo de 3-4 rodadas intercaladas: **nenhum erro**.

🚨 **O 8.4 nasce com o buffer pool 25% MENOR, e isso precisa ficar no grupo
`crm-v2-mysql84`.** O grupo padrão do 8.0 dimensiona o `innodb_buffer_pool_size` por
`{DBInstanceClassMemory*3/4}` (2 GB na `db.t4g.medium`). O do 8.4 deixa em branco, e o
MySQL escolhe 1,5 GB. Com o banco em 3,2 GB e a RAM já escassa, a Carteira ficou 13-18%
mais lenta e o Painel 7%, uniformemente, sem nenhum plano diferente. Também mudou
`innodb_adaptive_hash_index` (ON → OFF). Os dois foram fixados no `crm-v2-mysql84`:

| Parâmetro | Valor |
|---|---|
| `innodb_buffer_pool_size` | `{DBInstanceClassMemory*3/4}` |
| `innodb_adaptive_hash_index` | `1` |

Depois do ajuste (medido da app-1):

| Página | 8.0 | 8.4 |
|---|---:|---:|
| Carteira (todos os filtros) | 1.017-1.412 ms | 1,00-1,01x |
| Painel (sem cache) | 3.746 ms | 1,05x |
| Metas, Visão Gestor, Leads, Pedidos, Equipe | | 0,42-0,65x (rede, ver abaixo) |

O que sobra no Painel é a soma de faturamento do ano (+9%, mesmo plano, `Using index`),
e o Painel é servido do cache aquecido pelo `AquecerCacheDashboardJob`.

⚠️ **Zona de disponibilidade muda na virada.** O primário de hoje está em `sa-east-1c`
(junto da app-2); o do verde, em `sa-east-1a` (junto da app-1). Cada consulta que cruza
de zona paga ~0,7 ms a mais (0,37 contra 1,11 ms por `SELECT 1`). Depois da virada a
app-2, que roda worker, scheduler e o `totvs:atualizar`, passa a ficar longe do banco e a
app-1 perto. Página leve com 40 consultas: +30 ms. Não bloqueia o upgrade; se incomodar,
um *failover* do RDS troca o primário para `sa-east-1c` (o secundário já está lá).

Outros defaults que mudaram e ficaram como o 8.4 decide: `innodb_buffer_pool_instances`
8 → 1, `innodb_change_buffering` all → none, `innodb_io_capacity` 200 → 10000,
`innodb_log_buffer_size` 8 → 64 MB.

### Fase 2: switchover (sábado, xx:20, ~15 min com a conferência)

1. `aws rds switchover-blue-green-deployment --switchover-timeout 300`. O RDS bloqueia
   escrita, espera a réplica zerar e troca os nomes. Indisponibilidade ~1 min.
2. Nos **dois nós**: `sudo systemctl reload php8.3-fpm` e `sudo supervisorctl restart all`.
   O worker, o Reverb e o scheduler seguram conexão aberta e morreriam na primeira query.
3. **Conferir pelo caminho real** (lições de 2026-08-29 e 2026-09-04):
   - `SELECT @@version` pelo endpoint de produção → `8.4.11`;
   - **POST de login com senha errada → 422** (500 = banco quebrado). `curl /login → 200`
     não prova nada: a tela não toca o banco;
   - Painel, Carteira e `/atualizacoes` abrindo logado, com latência dentro da Regra nº 9;
   - a rodada seguinte do `totvs:atualizar` terminando em **sucesso** na `/atualizacoes`;
   - fila andando (`queue` sem acúmulo nas métricas do CloudWatch);
   - na segunda seguinte, o refresh das 10:30 do Power BI concluindo.

**✅ Feita em 2026-11-04, 16:37:59 → 16:38:37 (38 s), no meio da tarde, a pedido do
Tony.** Sem import rodando e com lag 0. Depois do `reload` do FPM e do
`supervisorctl restart all` nos dois nós, foi conferido:
- endpoint de produção em 8.4.11, gravável, buffer pool 2 GB, adaptive hash index ON;
- POST de login com senha errada pelo ALB → **422**;
- 13 páginas pelo kernel → 200;
- nenhum `ERROR` no log, scheduler rodando, `failed_jobs` = 0, usuários gravando
  presença.

Ainda a conferir: a rodada das 17:00 do `totvs:atualizar` e o refresh das 10:30 do Power
BI no dia seguinte.

O banco antigo virou `crm-v2-prod-old1` (8.0.46, `sa-east-1c`, deletion protection
ligada).

**Conferência antes de apagar o azul (mesmo dia, ~17:05):**
- as 80 tabelas de `palma_v2` + `bi` têm a mesma contagem nos dois (9.239.450 linhas) e
  o mesmo `CHECKSUM TABLE`, exceto `users`. Ali a única diferença é a presença
  (`last_activity_at`/`last_login_at` de 7 usuários que usaram o sistema depois da
  virada); todas as outras colunas batem nos 140 usuários;
- 13 views e as permissões do `bi_leitura` (20 conexões) intactas;
- ALB: 0 erros 5xx da aplicação em 196 requisições desde a virada (1 do próprio ALB,
  durante os 38 s); nenhum alarme; zero `ERROR` no log dos dois nós;
- a rodada das 17:00 do `totvs:atualizar` saiu `sem_mudanca` (não havia relatório
  novo). Uma rodada `--forcar` foi barrada pelo modo automático e não foi feita; o import
  já tinha passado nos testes contra o 8.4 na Fase 0.

**Feita em 2026-11-04, ~17:10:** apagados `crm-v2-prod-old1` (com
`--delete-automated-backups`, sem snapshot final), o Blue/Green (sem `--delete-target`,
que depois da virada é a produção) e o grupo `crm-v2-mysql80`. Ficou o snapshot
`pre-upgrade-84-20261104` (8.0.46), a única volta para o 8.0. Em 2026-11-05 foi apagado
o snapshot manual `crm-v2-prod-antes-historico-20260831-0916` (de antes da carga do
histórico de faturamento, sem uso).

### Fase 3: limpeza (até 24 h depois)

⚠️ **O `crm-v2-prod-old1` continua no 8.0 e continua pagando Extended Support enquanto
existir** (~US$ 20/dia + instância). Validou, apaga.

1. Apagar o Blue/Green Deployment e o `crm-v2-prod-old1` (desligar a deletion protection
   dele antes). O snapshot manual da Fase 1 fica guardado por mais algumas semanas.
2. Apagar o grupo `crm-v2-mysql80`.
3. `docker-compose.yml`: `mysql:8.0` → `mysql:8.4`, com dump do `palma_v2` antes (o volume
   não volta).
4. Atualizar `docs/deploy-aws.md` (§4.2) e o `CLAUDE.md`.
5. Uns 3 dias depois, conferir no Cost Explorer que a linha `ExtendedSupport` zerou.

## 6. Riscos e o que fazer com cada um

| Risco | Probabilidade | Mitigação |
|---|---|---|
| Consulta crítica muda de plano e fica lenta | média | Fase 1, passo 5: medir antes de virar |
| Erro de SQL ou migration no 8.4 | baixa | Fase 0 |
| Worker/Reverb presos na conexão velha | alta se esquecer | Fase 2, passo 2 |
| `bi_leitura` recriado vira `caching_sha2_password` | só se alguém rodar o `CREATE USER` do `infra/bi/criar-schema-e-usuario.sh` | o conector MySQL do gateway aceita `caching_sha2` com SSL (e o usuário já é `REQUIRE SSL`); o `ALTER USER ... IDENTIFIED BY` mantém o plugin atual |
| Import do TOTVS no minuto da virada | baixa | janela em xx:20; o import é idempotente |
| Precisar voltar depois de virar | baixa | perde as escritas desde a virada; por isso a validação vem antes |
| Esquecer o `-old1` de pé | média | Fase 3, passo 1, com prazo |
