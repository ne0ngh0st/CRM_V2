# Deploy AWS — CRM-V2 (PALMA v2)

> Guia completo de provisionamento, configuração e verificação do primeiro deploy.
> Escrito em 2026-08-27, ao fim das fases de performance. Ver também `docs/performance.md`
> (o que é caro e o que é barato) e a **Regra de ouro nº 9** no `CLAUDE.md`.
>
> **Este documento é para ser seguido de cima para baixo, num dia dedicado.** A ordem
> importa: cada seção assume que a anterior foi concluída.

---

## 0.0 Estado atual — atualizado em 2026-08-28

**A aplicação está no ar em https://crm.autopel.online**, provisionada por SSH.

| Seção | Estado |
|---|---|
| 1-3 Arquitetura, inventário, custos | ✅ decidido |
| 4.1 Rede (VPC, subnets, SGs) | ✅ `vpc-0c3d9a668fc84f036` |
| 4.2 RDS | ✅ `crm-v2-prod`, MySQL 8.0.46, Multi-AZ, senha no Secrets Manager |
| 4.3 ElastiCache | ✅ `crm-v2-redis`, 7.1.0, `volatile-lru` confirmado |
| 4.4 S3 | ✅ bucket `crm-v2-arquivos-890615325644`, acesso por IAM role — ver 5.4.1 |
| 4.5 ACM + Route 53 | ✅ certificado ISSUED, `crm.autopel.online` → ALB |
| 4.6 ALB | ✅ listeners 80→443, `idle_timeout` 300, ambos os TGs `healthy` |
| 4.7 EC2 | ✅ 2× `m7g.large`, Ubuntu 24.04, provisionadas por `infra/provisionar-servidor.sh` |
| 4.8 Daemons | ✅ Reverb no app-1; worker + scheduler no app-2 |
| 5 Configuração | ✅ `.env` nos dois nós, caches de produção ativos |
| 6 Carga de dados | ✅ 91.293 clientes, 1.032.099 faturamentos, 201 usuários — ver 6.1.1 |
| 7 Testes | ✅ 7.1, 7.2, 7.3, 7.4, 7.6, 7.8 · ⏸ 7.5, 7.7, 7.9 |
| 8 Monitoramento | ❌ nenhum alarme criado |

### ⚠️ Não usamos o Laravel Forge — por ora

Decisão de 2026-08-28: o Forge depende de cartão corporativo, que ainda não saiu.
Provisionamento e deploy são feitos por três scripts versionados em `infra/`:

| Script | Roda como | O que faz |
|---|---|---|
| `provisionar-servidor.sh` | root, uma vez por máquina | PHP 8.3, nginx, Composer, Node 22, pool do FPM |
| `deploy.sh` | ubuntu, a cada release | git, composer, build, migrations, caches, reload |
| `configurar-daemons.sh` | root, ao definir o papel | supervisor (Reverb ou worker) + cron do scheduler |

**O Forge não foi descartado.** Se a assinatura sair, ele substitui os scripts sem
mudar nada da arquitetura — os três fazem exatamente o que ele faria.

### Endereços reais (substituem os `xxxxx` da seção 5.1)

```
RDS    crm-v2-prod.c3mguim6agp4.sa-east-1.rds.amazonaws.com
Redis  crm-v2-redis.jgy4wl.0001.sae1.cache.amazonaws.com
app-1  15.229.96.223  (privado 10.0.1.59)  — Reverb
app-2  54.94.163.16   (privado 10.0.2.74)  — worker + scheduler
ALB    crm-v2-alb-40682473.sa-east-1.elb.amazonaws.com
```

Acesso: `ssh -i ~/.ssh/crm-v2 ubuntu@<ip>`. A porta 22 é liberada por IP no SG
`crm-v2-app` (escritório e casa do Tony) — **se o SSH der timeout, o primeiro suspeito é
IP novo**, não máquina fora do ar.

---

## 0. Como usar este documento

| Parte | O quê | Quando |
|---|---|---|
| 1-2 | Arquitetura e inventário | Ler antes de tocar em qualquer coisa |
| 3 | Custos | Aprovar orçamento antes de provisionar |
| 4 | Provisionamento | Bloco de ~3h no console AWS + Forge |
| 5 | Configuração (`.env`, deploy script) | Depois do servidor de pé |
| 6 | Carga inicial de dados | Depois do RDS acessível |
| 7 | Testes do primeiro deploy | **Não pule.** É o que separa "subiu" de "funciona" |
| 8 | Monitoramento | Antes de liberar para os beta testers |
| 9 | Armadilhas conhecidas | Consultar quando algo não funcionar |

**Decisões já tomadas** (não reabrir sem motivo novo): região **sa-east-1**, topologia
completa desde o beta, orçamento **R$ 2.000/mês**, beta só depois das fases de performance
(já concluídas).

---

## 1. Arquitetura

```
                         Internet
                            │
                    ┌───────▼────────┐
                    │  Route 53      │  crm.autopel.online
                    └───────┬────────┘
                            │
                  ┌─────────▼──────────┐
                  │  ALB (2 AZs)       │  TLS via ACM
                  │  idle_timeout 300s │
                  └────┬──────────┬────┘
           /app/*, /apps/*        │  /*
                  │               │
        ┌─────────▼────┐   ┌──────▼───────────────────────┐
        │ TG Reverb    │   │ TG Web (:80)                 │
        │ (:8080)      │   │  ┌────────────┬────────────┐ │
        └──────┬───────┘   │  │ EC2 app-1  │ EC2 app-2  │ │
               │           │  │ nginx      │ nginx      │ │
               └───────────┼──┤ PHP-FPM    │ PHP-FPM    │ │
                           │  │ reverb ⬅   │ worker     │ │
                           │  │            │ scheduler  │ │
                           │  └────────────┴────────────┘ │
                           └──────┬───────────────┬───────┘
                                  │               │
                      ┌───────────▼──┐   ┌────────▼─────────┐
                      │ RDS MySQL 8  │   │ ElastiCache Redis│
                      │ Multi-AZ     │   │ (cache/sessão/   │
                      │ 337 MB       │   │  fila/locks)     │
                      └──────────────┘   └──────────────────┘
                                  │
                          ┌───────▼────────┐
                          │ S3             │
                          │ uploads +      │
                          │ exports +      │
                          │ logs do ALB    │
                          └────────────────┘
```

### Distribuição de papéis entre os dois nós

Os dois nós são **idênticos em código** — a diferença é quais processos de segundo plano
rodam em cada um:

| Processo | app-1 | app-2 | Por quê |
|---|:---:|:---:|---|
| nginx + PHP-FPM | ✅ | ✅ | Ambos atrás do ALB |
| **Reverb** (WebSocket) | ✅ | ❌ | Um só: o ALB roteia `/app/*` e `/apps/*` para ele |
| **Queue worker** | ❌ | ✅ | Um só basta para o volume; concentra a memória dos exports |
| **Scheduler** (cron) | ❌ | ✅ | Pode ficar nos dois — `onOneServer()` protege —, mas um só é mais simples |

⚠️ **Se ligar o scheduler nos dois nós**, o `onOneServer()` que já está em todas as tarefas
impede execução duplicada, usando lock no Redis. Isso é rede de segurança, não permissão
para ligar sem pensar.

---

## 2. Inventário — o que é cada peça e por que existe

### 2.1 EC2 (2× `m7g.large`) — a aplicação

Roda nginx + PHP-FPM. Família **m7g** (Graviton, ARM) escolhida por dois motivos:

- **Sem burst.** Instâncias `t3`/`t4g` acumulam créditos de CPU e são *throttled* quando
  acabam — a latência fica errática, que é o oposto do requisito da Regra nº 9. Isso não é
  economia, é risco.
- **8 GB de RAM.** Necessário por causa do PHP-FPM com múltiplos workers: cada export
  síncrono ainda pode usar até 1 GB (`ini_set` do trait `ExportaPlanilha`), e são 8 telas
  que exportam de forma síncrona.

⚠️ **Graviton é ARM.** Todas as extensões PHP que usamos têm build ARM no Ubuntu, e o
Forge provisiona `arm64` normalmente. Se algo falhar no provisionamento, o plano B é
`m7i.large` (x86, ~10% mais caro).

### 2.2 RDS MySQL 8.0 (`db.t4g.small`, Multi-AZ) — o banco

**Volumes reais medidos (2026-08-27):**

| Tabela | Linhas | Observação |
|---|---:|---|
| `faturamentos` | 931.000 | 239 MB — a maior de longe |
| `clientes` | 91.293 | Espelho do TOTVS |
| `pedido_itens` | 186.497 | |
| `produtos` | 26.989 | |
| `leads` | 17.173 | |
| `pedidos` | 15.523 | 3.517 em aberto |
| `orcamentos` | 2.103 | |
| `users` | 201 | |
| **Total do banco** | | **337 MB** |

⚠️ **NÃO superdimensione o RDS.** O banco inteiro tem 337 MB e cabe folgado no buffer pool
de uma `t4g.small` (2 GB). RAM além disso **não é usada** — o dataset já está 100% em
memória. Toda vez que a tentação de subir a instância aparecer, reler esta linha e a
Parte 3 do `docs/performance.md`.

**Multi-AZ é obrigatório aqui**, e a razão mudou recentemente: o dado passou a **nascer**
na AWS (observações, orçamentos, agendamentos, solicitações de cadastro, exportações). Não
existe mais o espelho do legado para reimportar tudo. Perder o RDS = perder dado de
verdade. Ativar também **PITR** (point-in-time recovery) e reter 7 dias de backup.

### 2.3 ElastiCache Redis (`cache.t4g.micro`) — três papéis distintos

Esta peça faz **três** trabalhos que costumam ser confundidos:

| Papel | O que guarda | Database index | Consequência de faltar |
|---|---|:---:|---|
| **Sessão** | Quem está logado | 0 | Ninguém consegue logar |
| **Fila** | Jobs pendentes | 0 | Exportações e notificações param |
| **Cache** | Agregações do Dashboard/Carteira | 1 | Sistema volta a ~5 s por página |
| **Locks** | `onOneServer()` do scheduler | 0 | Jobs agendados executam em duplicata |

A separação por *database index* (0 e 1) não é decoração: é o que faz `php artisan
cache:clear` limpar **só o cache**, sem deslogar todo mundo e sem apagar a fila.

⚠️ **`maxmemory-policy` = `volatile-lru`, NUNCA `allkeys-lru`.** Sob pressão de memória,
`allkeys-lru` pode descartar jobs enfileirados e sessões (que não têm TTL). `volatile-lru`
só descarta chave com prazo — ou seja, só cache.

⚠️ **Corolário que virou regra do projeto: `Cache::forever()` é proibido.** Chave sem TTL
fica imune ao descarte e imortal.

**Dimensionamento:** 200 sessões de ~2 KB ≈ 400 KB, mais alguns MB de agregações, contra
os ~500 MB do `t4g.micro`. Folga de duas ordens de grandeza. O sinal a monitorar é
`Evictions` no CloudWatch: se sair de zero, subir a instância — não trocar a política.

### 2.4 Laravel Reverb — o sino em tempo real

WebSocket self-hosted (first-party do Laravel 11). Entrega notificação instantânea no sino
sem polling — o legado fazia poll a cada 60s recomputando 6-15 queries por vez, e era um
dos maiores causadores de lentidão lá.

**No ALB:** regra de path roteando `/app/*` e `/apps/*` para o target group do Reverb
(porta 8080). O ALB suporta WebSocket nativamente. Isso evita um segundo load balancer
(~US$ 28/mês economizados).

⚠️ **Health check do target group do Reverb não pode ser `/`.** O Reverb não serve uma
página ali. Configurar o matcher para aceitar `200,404`, ou apontar para um path que ele
responda — confirmar com `curl` na primeira instância.

⚠️ **`REVERB_HOST` do servidor ≠ do navegador.** O PHP (app e worker) precisa alcançar o
Reverb pelo endereço interno; o navegador usa o domínio público. São variáveis separadas
(`REVERB_*` vs `VITE_REVERB_*`). Confundir isso fez o sino nunca funcionar no Docker — ver
seção 9.

### 2.5 Queue worker — o que roda em segundo plano

| Job | Disparo | O que faz | Se falhar |
|---|---|---|---|
| `AquecerCacheDashboardJob` | a cada **10 min** | Recalcula as agregações caras dos 10 escopos de gestor | Páginas voltam a ~5 s no cache frio |
| `NotificarAgendamentosDoDiaJob` | diário **07:00** | Avisa quem tem ligação agendada hoje | Vendedor não é lembrado |
| `NotificarPedidosAtencaoJob` | diário **07:05** | Avisa sobre pedidos atrasados/vencendo | Idem |
| `ExpurgarNotificacoesLidasJob` | semanal **seg 03:00** | Apaga notificação lida há 30+ dias | Tabela cresce sem limite |
| `ExpurgarExportacoesJob` | diário **03:30** | Apaga planilhas vencidas do disco | Disco cresce sem limite |
| `GerarExportacaoCarteiraJob` | sob demanda | Gera o Excel da Carteira (~95 s, ~540 MB) | Usuário fica sem a planilha |

⚠️ **`--timeout` do worker precisa ser MAIOR que o `$timeout` do job.** O
`GerarExportacaoCarteiraJob` declara 600 s; o worker deve rodar com **700**. Sem essa
margem, o worker mata o processo sem passar pelo `failed()`, e o registro fica preso em
"processando" para sempre.

⚠️ **Os 5 jobs agendados usam `onOneServer()`**, que depende de lock atômico no Redis.
Sem Redis funcionando, o scheduler dos dois nós executaria tudo em duplicata.

### 2.6 Scheduler — o cron

Um único cron chamando `php artisan schedule:run` a cada minuto. No Forge é o toggle
**Scheduler** na aba do servidor.

⚠️ **Sem ele, o cache warming não roda e as páginas voltam a esfriar.** É o erro mais
provável de passar despercebido no primeiro deploy, porque nada quebra — só fica lento de
novo, de forma intermitente. A **pill de fogo** no Painel (visível para admin) existe
exatamente para tornar isso visível: verde = aquecido nos últimos 20 min; vermelho = o
warming parou.

### 2.7 S3 — três usos distintos

| Bucket/prefixo | Conteúdo | Por que não fica no disco da instância |
|---|---|---|
| `uploads/` | Fotos de perfil, imagens de faca | Com 2 nós, arquivo enviado no nó A não existe no nó B |
| `exports/` | Planilhas geradas pelo job | Idem — e o link da notificação pode cair em qualquer nó |
| `alb-logs/` | Access logs do ALB | Base para diagnóstico de latência por rota |

⚠️ **Isto exige mudança de código que ainda NÃO foi feita:** hoje `FacaController`,
`ProfileController::updateFoto` e o `GerarExportacaoCarteiraJob` gravam no disco local
(`storage/app/public` e `storage/app/exports`). Ver seção 5.4.

### 2.8 ALB — o balanceador

Além de distribuir carga, ele entrega:

- **TLS gerenciado** (certificado ACM, renovação automática, sem Let's Encrypt na máquina)
- **Health checks** — tira um nó doente do rodízio sozinho
- **`TargetResponseTime`** — a métrica que finalmente prova (ou não) a meta da Regra nº 9
- **Access logs** por requisição, no S3

⚠️ **`idle_timeout` deve ir para 300 s** (padrão 60). Mesmo com a Carteira agora
assíncrona, as outras 8 exportações continuam síncronas e algumas podem passar de 60 s com
o volume crescendo.

---

### 2.9 O caminho de uma requisição — quem toca o quê

Entender isto ajuda a diagnosticar: quando algo está lento ou quebrado, o problema está em
um destes saltos.

**Navegação normal (ex.: abrir a Carteira):**

```
navegador → ALB (TLS) → nginx → PHP-FPM
                                   │
                                   ├─→ Redis db0: lê a sessão (quem é o usuário)
                                   ├─→ MySQL: resolve o escopo (vendedor_perfis)
                                   ├─→ Redis db1: KPIs de aderência (cache quente)
                                   ├─→ MySQL: página de 30 clientes
                                   └─→ resposta JSON do Inertia
```

Com cache quente são **7 queries e ~25 ms de SQL**. Sem o cache, a agregação de aderência
sozinha custa ~950 ms — é a diferença que o cache warming protege.

**Exportação da Carteira:**

```
navegador → POST /carteira/exportar → cria registro em `exportacoes` → responde na hora
                                            │
                                    enfileira no Redis db0
                                            │
                              worker (app-2) pega o job
                                            │
                          reconstrói a query pelos filtros salvos
                                            │
                              gera .xlsx → S3 (~95 s)
                                            │
                        NotificacaoService grava no MySQL
                                            │
                    evento → Reverb (app-1) → WebSocket → sino do usuário
```

⚠️ Repare que esse fluxo atravessa **quatro** componentes de infra (Redis, worker, S3,
Reverb). Se a notificação não chegar, o teste 7.5 isola qual deles falhou.

**Cache warming (a cada 10 min):**

```
cron (app-2) → schedule:run → lock no Redis (onOneServer)
                                      │
                        AquecerCacheDashboardJob na fila
                                      │
                    worker recalcula 10 escopos (~9 s)
                                      │
                          grava no Redis db1 (TTL 30 min)
```

Como o TTL é 30 min e o job roda a cada 10, a chave é sempre reescrita **antes** de
expirar — ninguém paga a agregação fria.

## 3. Custos — detalhado

Preços de **sa-east-1 (São Paulo)**, on-demand, estimados. A região custa ~60% mais que
`us-east-1`, e isso é deliberado: latência de ~5-15 ms contra ~110-130 ms, o que num app
Inertia (uma requisição XHR por navegação) o usuário sente em cada clique.

### 3.1 Configuração recomendada

| Recurso | Especificação | Cálculo | US$/mês |
|---|---|---|---:|
| EC2 app-1 | `m7g.large` (2 vCPU, 8 GB, ARM) | ~US$ 0,13/h × 730 | 95 |
| EC2 app-2 | `m7g.large` | idem | 95 |
| EBS | 2 × 30 GB gp3 | 60 GB × ~US$ 0,12 | 7 |
| IPv4 público | 2 endereços | 2 × US$ 0,005/h × 730 | 7 |
| ALB | base + ~3 LCU | US$ 0,0279/h + LCU | 28 |
| RDS | `db.t4g.small` **Multi-AZ** | ~US$ 0,102/h × 2 × 730 | 74 |
| RDS storage | 20 GB gp3 + backup | | 6 |
| ElastiCache | `cache.t4g.micro`, 1 nó | ~US$ 0,026/h × 730 | 19 |
| S3 | ~5 GB + requisições | | 2 |
| Transferência de saída | ~30 GB | US$ 0,138/GB (após 100 GB free) | 4 |
| CloudWatch | métricas + alarmes + logs | | 8 |
| Route 53 | 1 zona hospedada + queries | | 1 |
| SES | e-mail transacional (quando entrar) | US$ 0,10/1.000 | ~0 |
| **TOTAL AWS** | | | **~346** |

**≈ R$ 1.900/mês** a R$ 5,50/US$ — tudo na fatura da AWS (conta 890615325644).

### 3.1.1 ⚠️ Gerenciamento do servidor é OUTRO centro de custo

O Laravel Forge (~US$ 19/mês) **não** entra na fatura AWS: é assinatura própria da Laravel,
cartão separado, cobrança internacional com IOF. O AWS Budget não enxerga esse gasto.

São dois pontos de falha de pagamento diferentes: cartão recusado na Laravel derruba o
painel de gerenciamento e o deploy automático (as máquinas seguem rodando).

**A alternativa sem custo adicional** é provisionar por SSH — instalar PHP, nginx,
supervisor, cron e o script de deploy à mão. O trabalho de instalar é equivalente; a
diferença aparece na OPERAÇÃO: sem o painel, ver log de job, reiniciar worker e disparar
deploy passam a ser linha de comando. Para quem vai manter o sistema sozinho e está
começando, essa diferença pesa mais que os US$ 19.

### 3.2 ⚠️ Isso é exatamente o teto do orçamento

Sem folga. Dois riscos concretos:

**Câmbio.** A R$ 6,00 o total vai para ~R$ 2.190 sem nada ter mudado na infra.

**Surpresas.** Configure **AWS Budgets com alerta em 80% e 100%** no primeiro dia. É
grátis e é a diferença entre descobrir no dia 10 ou na fatura.

### 3.3 Onde cortar, se precisar

| Ação | Economia | Custo da decisão |
|---|---:|---|
| `c7g.large` (4 GB) no lugar de `m7g.large` | ~US$ 40/mês | Só depois de todas as exportações irem para fila |
| RDS Single-AZ | ~US$ 37/mês | Perde o failover automático — **não recomendado**, o dado nasce aqui |
| 1 app node em vez de 2 | ~US$ 99/mês | Perde redundância; útil só durante o desenvolvimento |
| Savings Plan 1 ano (no-upfront) | ~30% do EC2 (~US$ 57/mês) | Trava a família por 12 meses — fazer só após 1-2 meses estáveis |

### 3.4 ⚠️ Armadilhas de custo

**NAT Gateway: não use.** Colocar as instâncias em subnet privada exigiria NAT Gateway,
que custa **~US$ 33/mês** em sa-east-1 **mais** US$ 0,045/GB processado — mais que a
instância que ele protegeria. Use subnet **pública** com security group restrito (porta 80
só do SG do ALB, porta 22 só do seu IP).

**Instâncias `t3`/`t4g` em modo unlimited** cobram US$ 0,05/vCPU-hora de crédito excedente.
Já estão fora da recomendação por causa da latência errática, mas vale saber que o custo
também é imprevisível.

**Cross-AZ.** Tráfego entre zonas custa US$ 0,01/GB em cada direção. Mantenha Redis e o
RDS primário **na mesma AZ do app-1**.

---

## 4. Provisionamento — ordem de execução

> Ordem pensada para que cada passo dependa apenas dos anteriores. Tempo estimado: 3-4h.

### 4.0 Credenciais — use SEMPRE o profile `crm-v2`

A conta AWS **890615325644** hospeda três sistemas. O CRM-V2 tem um usuário IAM dedicado
(`crm-v2-deploy`) e um profile local:

```bash
aws sts get-caller-identity --profile crm-v2
# → arn:aws:iam::890615325644:user/crm-v2-deploy
```

| Propriedade | Valor | Por que importa |
|---|---|---|
| Região default do profile | `sa-east-1` | Não precisa passar `--region` — e comando esquecido não cria recurso na Virgínia |
| Acesso a `us-east-1` | **negado** (`UnauthorizedOperation`) | Não alcança a infra do Licitações nem do Autoprint, que rodam lá |
| Route 53 | só a zona `autopel.online` | Não consegue mexer em zona de outro projeto |

⚠️ **Nunca use o profile default para este projeto.** Ele aponta para `us-east-1` e tem
`AdministratorAccess` na conta inteira — inclusive sobre a produção do sistema de
licitações. O isolamento do `crm-v2` é proteção real, não formalidade.

```bash
export AWS_PROFILE=crm-v2   # ou --profile crm-v2 em cada comando
```

**O que já existe na conta (levantado em 2026-08-27):**

| Sistema | Região | Infra |
|---|---|---|
| Licitações | `us-east-1` | VPC própria via CDK, bastion + app EC2, MySQL local |
| Autoprint | `us-east-1` | Bucket de deploy + secret de ingestão |
| **CRM-V2** | **`sa-east-1`** | **Vazio — só a VPC default** |

### 4.0.1 Pre-requisitos descobertos no provisionamento real (2026-08-27)

Tres coisas que so aparecem na hora e travam o dia se pegarem de surpresa:

**1. Service-linked roles.** Servico nunca usado na conta exige uma SLR, criada uma unica
vez e apenas por quem tem `iam:CreateServiceLinkedRole` (nao o `crm-v2-deploy`).
Nesta conta ja existiam as de RDS e ElasticLoadBalancing; a de ElastiCache faltava:

```bash
# com o profile ADMIN, nao o crm-v2
aws iam create-service-linked-role --aws-service-name elasticache.amazonaws.com
```

O erro que denuncia isso e `ServiceLinkedRoleNotFoundFault`, e ele aparece em TODOS os
comandos do servico — inclusive nos de criar subnet group e parameter group.

**2. KMS.** Storage criptografado no RDS e senha gerenciada no Secrets Manager exigem
permissao de KMS, que o `crm-v2-deploy` nao tinha. Ver `policy-kms.json` na raiz do
projeto. ⚠️ **Criptografia de storage nao pode ser ligada depois** — so recriando a
instancia. Resolver ANTES de criar o RDS.

⚠️ Depois de aplicar essa policy, `aws kms describe-key` continua sendo negado, e isso e
o esperado: a condicao `kms:ViaService` so libera KMS quando a chamada vem ATRAVES de
RDS/S3/SecretsManager/ElastiCache. O teste valido e criar o recurso, nao consultar a chave.

**3. Git Bash no Windows corrompe argumentos com path.** O MSYS converte `/up` em
`C:/Users/.../up`, e o erro fala de "path invalido" sem dizer que foi o shell:

```bash
export MSYS_NO_PATHCONV=1   # antes de qualquer comando aws com path
```

O mesmo vale para `--matcher HttpCode=200,404`, que o CLI le como lista — use JSON:
`--matcher '{"HttpCode":"200,404"}'`.

### 4.1 Rede (30 min)

1. **VPC** dedicada (não use a default) — CIDR `10.0.0.0/16`
2. **Subnets públicas em 2 AZs** (`sa-east-1a`, `sa-east-1c`) — o ALB exige duas
3. **Subnets de banco em 2 AZs** — para o subnet group do RDS
4. **Internet Gateway** + rota `0.0.0.0/0` nas subnets públicas
5. **Security groups:**

| SG | Entrada | Origem |
|---|---|---|
| `crm-alb` | 80, 443 | `0.0.0.0/0` |
| `crm-app` | 80 | SG `crm-alb` |
| `crm-app` | 8080 | SG `crm-alb` (Reverb) |
| `crm-app` | 22 | **seu IP fixo**, não `0.0.0.0/0` |
| `crm-db` | 3306 | SG `crm-app` |
| `crm-redis` | 6379 | SG `crm-app` |

### 4.2 RDS (20 min + ~15 min de criação)

- MySQL **8.0** (mesma major do dev — o projeto usa SQL específico de MySQL 8)
- `db.t4g.small`, **Multi-AZ**, 20 GB gp3
- Subnet group nas subnets de banco, SG `crm-db`, **sem acesso público**
- Backup 7 dias, **PITR ligado**, janela de manutenção fora do horário comercial
- ⚠️ **Performance Insights NAO funciona em `db.t4g.small`** (nem em qualquer burstable
  `micro`/`small`) — o erro e `InvalidParameterCombination`. Subir para `medium` so por
  isso dobraria o custo do RDS. Em vez disso, slow query log exportado pro CloudWatch,
  que atende a mesma necessidade de achar query lenta:
  `--enable-cloudwatch-logs-exports '["slowquery","error"]'` mais os parametros
  `slow_query_log=1` e `long_query_time=0.5` no parameter group
- Parameter group: `max_allowed_packet` ≥ 64 MB (os imports fazem insert em lote)

### 4.3 ElastiCache (15 min)

- Redis 7, `cache.t4g.micro`, **cluster mode desabilitado**, 1 nó
- **Mesma AZ do app-1**
- SG `crm-redis`, subnet group na VPC
- Parameter group: **`maxmemory-policy` = `volatile-lru`** ⚠️
- Encryption in-transit: se ligar, a string de conexão precisa de `tls://` no `.env`

### 4.4 S3 (10 min)

- Bucket `crm-autopel-prod` (nome global — ajuste se ocupado)
- **Bloquear acesso público** (os arquivos são servidos pela aplicação, não diretamente)
- Lifecycle: `exports/` expira em 7 dias, `alb-logs/` em 30
- Usuário IAM dedicado com política restrita a este bucket (chaves vão para o `.env`)

### 4.5 ACM + Route 53 (20 min, depende de propagação DNS)

- Certificado para `crm.autopel.online` em **sa-east-1** (ALB exige certificado na mesma região)
- Validação por DNS (registro CNAME na zona)
- ✅ **A zona `autopel.online` já está no Route 53** (`Z01098153VPQ0G1VCPV09`) e o usuário
  `crm-v2-deploy` tem permissão de escrita nela. Validação e registro final saem por CLI,
  sem passar por provedor externo.

### 4.6 ALB (30 min)

1. ALB **internet-facing**, nas 2 subnets públicas, SG `crm-alb`
2. **Target group `tg-web`**: porta 80, health check em **`/up`**, matcher `200`
3. **Target group `tg-reverb`**: porta 8080, health check com matcher `200,404` ⚠️
4. **Listener 443** (certificado ACM):
   - Regra 1 (prioridade 10): path `/app/*` ou `/apps/*` → `tg-reverb`
   - Regra padrão: → `tg-web`
5. **Listener 80** → redirect 301 para 443
6. **Atributos:** `idle_timeout.timeout_seconds` = **300** ⚠️
7. **Access logs** → bucket S3, prefixo `alb-logs/`

### 4.7 EC2 via SSH (40 min)

1. Lançar **2 instâncias** `m7g.large`, Ubuntu 24.04 **ARM**, nas subnets públicas, SG
   `crm-v2-app`, key pair `crm-v2`
2. Registrar as instâncias nos target groups (`tg-web` as duas, `tg-reverb` só o app-1)
3. Provisionar cada uma:

```bash
scp -i ~/.ssh/crm-v2 infra/provisionar-servidor.sh ubuntu@<ip>:/tmp/
ssh -i ~/.ssh/crm-v2 ubuntu@<ip> 'sudo bash /tmp/provisionar-servidor.sh'
```

O script confere as extensões obrigatórias e **aborta** se faltar alguma — não é decoração,
foi ele que pegou o `opcache` ausente no provisionamento real (ver 9.11).

### 4.8 Deploy e daemons

```bash
# .env primeiro — o deploy aborta sem ele
scp -i ~/.ssh/crm-v2 env-producao ubuntu@<ip>:/tmp/
ssh -i ~/.ssh/crm-v2 ubuntu@<ip> 'mkdir -p /var/www/crm && mv /tmp/env-producao /var/www/crm/.env && chmod 600 /var/www/crm/.env'

# deploy — --migrar em UM nó só
ssh -i ~/.ssh/crm-v2 ubuntu@<app-1> 'bash /tmp/deploy.sh --migrar'
ssh -i ~/.ssh/crm-v2 ubuntu@<app-2> 'bash /tmp/deploy.sh'

# daemons — o papel decide o que roda
ssh -i ~/.ssh/crm-v2 ubuntu@<app-1> 'sudo bash /tmp/configurar-daemons.sh app-1'
ssh -i ~/.ssh/crm-v2 ubuntu@<app-2> 'sudo bash /tmp/configurar-daemons.sh app-2'
```

| Servidor | Processo | Observação |
|---|---|---|
| app-2 | `queue:work redis --tries=1 --timeout=700` | ⚠️ timeout > 600 do job |
| app-1 | `reverb:start --host=0.0.0.0 --port=8080` | Só neste nó |
| app-2 | cron `schedule:run` a cada minuto | Sem ele o cache esfria |

⚠️ **O target group do Reverb leva ~1 min para virar `healthy`** (intervalo 30 s × 2
sucessos). `unhealthy` logo após subir o daemon não é defeito — é o health check ainda
contando. Só investigue se persistir depois de 2 minutos.

---

## 5. Configuração

### 5.1 `.env` de produção — anotado

```dotenv
APP_NAME="PALMA CRM"
APP_ENV=production
APP_KEY=                      # php artisan key:generate --show
APP_DEBUG=false               # ⚠️ CRÍTICO — ver 5.2
APP_URL=https://crm.autopel.online
APP_LOCALE=pt_BR
APP_TIMEZONE=America/Sao_Paulo

# Banco — endpoint do RDS, nunca IP
DB_CONNECTION=mysql
DB_HOST=crm-prod.xxxxx.sa-east-1.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=palma_v2
DB_USERNAME=palma
DB_PASSWORD=                  # gerar forte; guardar no gerenciador de senhas

# Redis — os TRÊS drivers apontam pra cá (ver 2.3)
REDIS_CLIENT=phpredis
REDIS_HOST=crm-prod.xxxxx.cache.amazonaws.com
REDIS_PORT=6379
REDIS_PASSWORD=null
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_LIFETIME=120

# Reverb — LADO SERVIDOR (PHP → Reverb). Como o Reverb roda no app-1, o app-2 precisa
# alcançá-lo pelo IP privado. ⚠️ Não confundir com as VITE_* abaixo.
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=10.0.x.x          # IP privado do app-1
REVERB_PORT=8080
REVERB_SCHEME=http            # interno, dentro da VPC
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Reverb — LADO NAVEGADOR. Passa pelo ALB, então é o domínio público em 443/wss.
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=crm.autopel.online
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

# S3
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=sa-east-1
AWS_BUCKET=crm-autopel-prod
AWS_USE_PATH_STYLE_ENDPOINT=false

# Legado — só quando for rodar import. Ver seção 6.
LEGADO_DB_HOST=
LEGADO_DB_PORT=3306
LEGADO_DB_DATABASE=
LEGADO_DB_USERNAME=
LEGADO_DB_PASSWORD=

# E-mail — SMTP relay dedicado (smtplw.com.br), NÃO o SES. O plano original previa SES,
# mas em 2026-08-28 o Tony recebeu credenciais deste relay e é o que o projeto usa.
#
# ⚠️ MAIL_MAILER=log no primeiro deploy, de propósito: os destinos de Cadastros são os
# setores REAIS (pcp.sp@, cadastro.geral@). Uma solicitação de teste dispararia e-mail de
# verdade para o time — já aconteceu uma vez. Trocar para `smtp` só ao abrir o beta.
MAIL_MAILER=log
MAIL_HOST=smtplw.com.br
MAIL_PORT=587
MAIL_USERNAME=autopel
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="no-reply.crm@solucoes.autopel.com"

# ⚠️ Sem isto o título de toda aba do navegador vira "... - Laravel": o Inertia lê o nome
# do app do VITE_APP_NAME embutido no BUILD, não do config do servidor. Mudar o valor
# exige `npm run build` de novo — config:cache não alcança asset já compilado.
VITE_APP_NAME="${APP_NAME}"
```

### 5.2 ⚠️ `APP_DEBUG=false` é requisito de segurança, não de performance

O projeto está travado no Laravel 11.55.0 e o `composer` precisou de
`--no-security-blocking` por advisories abertas na branch 11.x. Uma delas é **XSS refletido
que só é explorável com `APP_DEBUG=true`**. Com debug ligado em produção, você tem uma
vulnerabilidade conhecida exposta na internet.

### 5.3 Script de deploy (Forge)

```bash
cd /home/forge/crm.autopel.online

git pull origin main

# --no-dev tira o dev-dependencies; --classmap-authoritative evita stat() por classe
composer install --no-interaction --prefer-dist --optimize-autoloader \
    --no-dev --classmap-authoritative

npm ci
npm run build

php artisan migrate --force

# ⚠️ ORDEM IMPORTA: config:cache antes de qualquer comando que leia config
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

php artisan storage:link

# Reinicia os daemons para carregarem o código novo
( flock -w 10 9 || exit 1; echo 'Restarting FPM...'; sudo -S service php8.3-fpm reload ) 9>/tmp/fpmlock
php artisan queue:restart

# ⚠️ Por último: aquece o cache ANTES do tráfego chegar. Sem isto, o primeiro
# usuário depois de cada deploy paga ~5 s de agregação fria.
php artisan cache:aquecer
```

### 5.4 ✅ Uploads e exports no S3 — feito em 2026-08-27

Não existe mais `Storage::disk('public')` nem `disk('local')` espalhado pelo código. Cada
tipo de arquivo aponta para um **disco lógico**, resolvido por `App\Support\Uploads\Disco`:

| Disco lógico | Conteúdo | Dev | Produção |
|---|---|---|---|
| `uploads` | Foto de perfil, imagem de faca | `public` | **`s3`** |
| `exports` | Planilhas geradas pelo job | `local` | **`s3`** |

**No `.env` de produção, basta:**

```dotenv
UPLOADS_DISK=s3
EXPORTS_DISK=s3
```

⚠️ **`exports` NUNCA pode ser público.** O download passa pelo `ExportacaoController`, que
confere se o arquivo pertence a quem pediu — um `.xlsx` da Carteira contém a base inteira
de clientes de alguém. O bucket deve manter "bloquear acesso público" ligado.

⚠️ **As imagens em `public/images/facas/` não mudam** — são versionadas no git e vêm do
legado. Só o que o usuário envia vai para o S3.

### 5.4.1 ✅ S3 ativo em produção (2026-08-28) — e os quatro defeitos do caminho

`UPLOADS_DISK=s3` e `EXPORTS_DISK=s3` nos dois nós, com credencial vinda de **IAM role**
(`crm-v2-app`), não de chave no `.env`. Scripts: `infra/iam/criar-role-s3.sh` (precisa de
admin, roda uma vez) e `infra/ativar-s3.sh`. Ver `infra/iam/LEIA-ME.md`.

Marcar isto como pronto custou quatro correções, e **três delas eram do mesmo tipo: teste
que não percorre o caminho real**. Vale ler antes de dar qualquer outra coisa por concluída.

| # | O que quebrou | Por que passou despercebido |
|---|---|---|
| 1 | `league/flysystem-aws-s3-v3` **nunca foi instalado** | A migração de 27/08 escreveu o `Disco`, o config e a documentação, e foi marcada ✅ por inspeção de código. Nunca falou com o S3 uma vez. |
| 2 | Política IAM liberava `uploads/*`, prefixo que **o código não usa** | O teste de ativação escrevia justamente em `uploads/`. Passava com folga enquanto `facas/` e `perfis/` davam AccessDenied. |
| 3 | `Storage::url()` em bucket privado responde **403** | Não há erro no log: para o PHP, gerar a URL funcionou. Só aparece como imagem quebrada. |
| 4 | `php -r class_exists(...)` sem `require vendor/autoload.php` | Falso negativo: dizia "adapter ausente" com o pacote instalado. A guarda teria bloqueado a ativação para sempre. |

⚠️ **O modo de falha do nº 1 é o mais perigoso da lista:** o Laravel aceita
`FILESYSTEM_DISK=s3` sem reclamar e só quebra na primeira gravação. Como o accessor
`User::foto_url` roda no layout, isso derrubaria **toda** página, não só o upload.

⚠️ **Prefixos que a aplicação realmente usa** — a política precisa cobrir os três:

| Recurso | Prefixo | Onde no código |
|---|---|---|
| Imagem de faca | `facas/` | `CatalogoFacaController::186` |
| Foto de perfil | `perfis/` | `ProfileController::65` |
| Planilha | `exports/{id}/` | `GerarExportacaoCarteiraJob::64` |

**URL de imagem é assinada** (`Disco::urlUpload()`, TTL 1 h) porque o bucket é privado e
tem que continuar sendo — as planilhas de `exports/` contêm a carteira inteira de um
vendedor. Efeito colateral aceito: a URL muda a cada renderização, então o navegador não
reaproveita a imagem entre páginas. Pesa pouco hoje (as 166 imagens do catálogo são assets
versionados em `public/images/` e nem passam por ali). Se pesar, a saída é **CloudFront com
Origin Access Control** — URL estável e cacheável sem abrir o bucket.

**Fotos de perfil herdadas do legado:** 16 usuários têm `foto_perfil` no formato
`assets/img/perfis/...`, que é caminho do sistema **antigo** — o arquivo nunca existiu no
CRM-V2. O accessor devolve `null` para esse formato, então esses usuários caem no avatar
padrão em vez de imagem quebrada. Decisão pendente: migrar as fotos do legado ou limpar a
coluna.

### 5.4.2 Histórico: como era antes de 28/08

Em produção o `.env` está com `UPLOADS_DISK=public` e `EXPORTS_DISK=local` — **disco
local**, não S3. Com dois nós isso é um bug de verdade: a foto enviada no app-1 dá 404
em ~50% dos carregamentos, porque o ALB pode mandar o próximo request para o app-2.

**Por que não foi resolvido junto com o resto:** criar identidade IAM exige permissão de
IAM, e o usuário `crm-v2-deploy` **não tem** — de propósito, é o isolamento descrito em
4.0. Só o profile admin da conta consegue.

⚠️ **Não resolver reaproveitando as chaves do `crm-v2-deploy` no servidor.** Elas criam
EC2, RDS e ALB; guardá-las no `.env` de uma máquina exposta à internet significa que um
comprometimento da aplicação vira comprometimento da infraestrutura inteira. A conveniência
não paga esse risco.

**As duas saídas, em ordem de preferência:**

| | Como | Vantagem |
|---|---|---|
| **A — IAM role na instância** *(recomendada)* | Criar role com política restrita ao bucket e anexar às duas EC2 (`ec2:AssociateIamInstanceProfile`) | **Nenhuma chave em disco.** Credencial rotaciona sozinha; o SDK da AWS a encontra pelo metadata service sem nenhuma variável no `.env` |
| B — Usuário IAM dedicado | Criar `crm-v2-s3` com política só deste bucket; chaves no `.env` | É o que a seção 4.4 previu; funciona, mas cria segredo de vida longa em disco |

Com a opção A, o `.env` precisa apenas de:

```dotenv
UPLOADS_DISK=s3
EXPORTS_DISK=s3
AWS_BUCKET=crm-v2-arquivos-890615325644
AWS_DEFAULT_REGION=sa-east-1
```

(sem `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` — a ausência delas é o que faz o SDK
buscar a credencial da role.)

**Três formatos convivem na coluna `faca_recursos.imagem`**, e `urlDaImagem()` resolve os
três sem migration:

| Formato | Origem | URL gerada |
|---|---|---|
| `images/facas/...` | Asset versionado (legado) | `/images/facas/...` |
| `storage/facas/...` | Upload anterior a 27/08 | `/storage/facas/...` |
| `facas/...` | Upload novo (caminho no disco) | `Storage::url()` — S3 em produção |

⚠️ Se você **migrar os arquivos antigos** de `storage/app/public/facas` para o S3, os
registros do formato 2 continuarão apontando para `/storage/...` e vão quebrar. Ou copie
mantendo o caminho servido pelo `storage:link`, ou rode um `UPDATE` trocando o prefixo
`storage/facas/` por `facas/`.

**Uma otimização junto:** o accessor `User::foto_url` deixou de chamar `exists()` antes de
gerar a URL. Com disco local isso era barato; com S3 seria uma chamada de rede **por
usuário renderizado**, e o accessor roda no layout de toda página. Foto apagada por fora
vira um 404 na tag `<img>`, que custa infinitamente menos.

### 5.5 Configuração do PHP e do nginx

**`php.ini` (via Forge):**

```ini
opcache.enable=1
opcache.validate_timestamps=0   ; sem stat() por arquivo em cada request
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.interned_strings_buffer=16
realpath_cache_size=4096k
memory_limit=512M               ; ⚠️ php_value, NÃO php_admin_value — ver 7.6
```

**PHP-FPM pool:**

```ini
pm = static
pm.max_children = 12    ; 8 GB / ~500 MB de folga por worker
```

**nginx:** gzip/brotli para `application/json` (o payload do Inertia comprime muito) e
`Cache-Control: public, max-age=31536000, immutable` em `/build/`.

---

## 6. Carga inicial de dados

### 6.1 ⚠️ A sincronização automática ainda não existe

Os comandos `legado:import-*` leem o espelho `autopel01_homolog`, que hoje roda na máquina
do Tony. **Na AWS esse espelho não existe.** A automação depende da refatoração das bases
do Adriano, que ainda não veio.

**Para o beta, use carga pontual:**

```bash
# Na máquina local — só as tabelas espelho
mysqldump -h 127.0.0.1 -P 3306 -u root -p palma_v2 \
  clientes faturamentos pedidos pedido_itens produtos leads segmentos grupos_cliente \
  > espelho.sql

# Enviar e importar (via bastion ou do próprio app server)
mysql -h <endpoint-rds> -u palma -p palma_v2 < espelho.sql
```

### 6.1.1 ✅ Como a carga foi feita de verdade (2026-08-28)

O `mysqldump` da 6.1 lista **8 tabelas**, e isso não basta: sem `users`, `vendedor_perfis`
e `segmentos_vendedor`, ninguém além do admin consegue entrar e a aderência fica zerada.
Foram carregadas **19 tabelas**:

```
clientes faturamentos pedidos pedido_itens produtos leads
segmentos grupos_cliente segmentos_vendedor
users vendedor_perfis roles model_has_roles
orcamentos orcamento_itens facas faca_recursos
etiquetas_materia_prima data_sync_status
```

⚠️ **Quatro tabelas ficaram DE FORA de propósito, porque contêm dado de seed, não dado
real:** `observacoes` e `sugestoes` são **Lorem Ipsum** gerado pelo Faker; `ligacoes` (6.743)
e `metas_mensais` (4.320) vêm de seeders de demonstração. Meta de venda inventada e ligação
que ninguém fez são **piores que vazio** num sistema que o vendedor usa para se avaliar.

⚠️ Também de fora: `migrations` (o RDS tem o seu próprio estado), `sessions`/`cache`/`jobs`
(agora no Redis) e `notificacoes`/`exportacoes`/`simulacoes_usuario` (artefatos de dev).

**Antes de repetir esta carga, conferir se alguma tabela nova entrou numa dessas duas
categorias** — a lista acima é um retrato de 28/08, não uma regra permanente.

O caminho foi dump local → gzip (29 MB) → `scp` para o app-1 → `mysql` para o RDS.
**54 segundos** de import. Rodar de dentro da VPC importa: da máquina do Tony levaria muito
mais, pela latência por statement.

### 6.1.2 ⚠️ O cast `hashed` re-hasheia hash que você mesmo gerou

`$u->password = Hash::make($senha)` gravado via Eloquent pode virar **bcrypt do bcrypt** —
o valor no banco é um hash bcrypt válido de 60 caracteres, `password_get_info()` confirma
"bcrypt", e mesmo assim o login falha com "E-mail ou senha inválidos". Não há sintoma que
aponte para a causa.

Para definir senha em massa, contornar o cast:

```php
DB::table('users')->where('id', $id)->update(['password' => Hash::make($senha)]);
```

E **verificar antes de entregar a senha para alguém**:

```php
Hash::check($senha, DB::table('users')->where('id',$id)->value('password'));  // tem que ser true
```

### 6.2 Ordem de restauração completa (se precisar do zero)

```
migrate
→ db:seed --class=RoleSeeder        # os imports atribuem roles e falham sem elas
→ legado:import-usuarios
→ legado:import-clientes
→ legado:import-faturamento
→ legado:import-pedidos
→ legado:import-leads
→ legado:import-produtos
→ legado:import-orcamentos-historico
→ db:seed
→ cache:aquecer
```

### 6.3 ⚠️ O que cada import faz com dado que já existe

| Comando | Comportamento | Seguro para dado do CRM? |
|---|---|---|
| `import-usuarios` | `firstOrNew` + bloco de primeira carga | ✅ **desde 2026-08-27** — preserva senha, foto, display_name |
| `import-clientes` | `upsert` | ✅ |
| `import-produtos` | `upsert` | ✅ |
| `import-leads` | Apaga **só** `origem = 'sistema'` | ✅ preserva lead manual |
| `import-faturamento` | `truncate` ou delete por data | ✅ espelho puro |
| `import-pedidos` | `truncate` | ✅ espelho puro |

⚠️ **Risco conhecido e não resolvido:** a chave do `import-usuarios` é o **e-mail**, e o
usuário pode trocar o próprio e-mail em `/profile`. Se um beta tester fizer isso, a próxima
importação não o reconhece e **cria um usuário duplicado**. Resolver quando incomodar,
provavelmente chaveando por `username` (que o CRM não deixa editar).

### 6.4 Senhas dos beta testers

O `import-usuarios` gera senha aleatória de propósito (nunca migra hash do legado). Depois
da carga, defina as senhas manualmente:

```bash
php artisan tinker
>>> $u = App\Models\User::where('email','...')->first();
>>> $u->password = bcrypt('...'); $u->save();
```

⚠️ Agora que o import preserva senha, isso só precisa ser feito **uma vez**.

---

## 7. Testes do primeiro deploy

> **Esta é a seção que separa "subiu" de "funciona".** Execute na ordem; cada item tem um
> critério objetivo de aprovação.

### 7.1 Fumaça — a aplicação responde

| # | Teste | Comando / ação | Esperado |
|---|---|---|---|
| 1 | Health check | `curl -I https://crm.autopel.online/up` | `200` |
| 2 | Raiz redireciona | `curl -I https://crm.autopel.online/` | `302` → `/login` |
| 3 | Login renderiza | Abrir no navegador | Tela com o mosaico de triângulos |
| 4 | HTTPS forçado | `curl -I http://crm.autopel.online` | `301` para `https` |
| 5 | Assets carregam | DevTools → Network | Sem 404 em `/build/` |

### 7.2 ⚠️ Os caches de produção realmente funcionaram

O `route:cache` e o `config:cache` já quebraram neste projeto — os dois problemas foram
corrigidos, mas confirme que continuam corrigidos:

```bash
php artisan route:cache      # deve completar sem erro (a rota / não pode ser Closure)
php artisan config:cache
php artisan tinker --execute="echo config('legado.homolog.host');"   # NÃO pode ser vazio
```

⚠️ Se `config('legado.*')` vier vazio com o config cacheado, algum `env()` voltou para fora
de `config/` e os imports vão quebrar na primeira sincronização.

### 7.3 Sessão e login (o modo de falha mais grave)

| # | Teste | Esperado |
|---|---|---|
| 1 | Login com usuário real | Entra e vai para o Dashboard |
| 2 | Navegar 3-4 páginas | Continua logado |
| 3 | `redis-cli -n 0 DBSIZE` | Cresce a cada login |
| 4 | `SELECT COUNT(*) FROM sessions` | **0** — se crescer, o driver não é Redis |
| 5 | Logout | Volta ao login e não reentra com o botão "voltar" |

### 7.4 Fila e jobs

| # | Teste | Comando | Esperado |
|---|---|---|---|
| 1 | Worker vivo | Forge → Daemons | `running` |
| 2 | Fila aceita job | `php artisan cache:aquecer` | "Aquecendo 10 escopo(s)... Concluído" |
| 3 | Scheduler ativo | `php artisan schedule:list` | 5 tarefas listadas |
| 4 | Warming automático | Esperar 10 min → `php artisan cache:aquecer --status` | Aquecimento recente |
| 5 | **Pill de fogo** | Abrir o Painel como admin | Verde: "Cache aquecido" |

⚠️ **Se a pill ficar vermelha depois de 40 min, o Scheduler não está ligado.** É o erro
silencioso mais provável deste deploy.

### 7.5 Exportação assíncrona

| # | Teste | Esperado |
|---|---|---|
| 1 | Clicar "Gerar Excel" na Carteira (admin, sem filtro) | Modal "Preparando sua planilha" |
| 2 | Aguardar ~2 min | Notificação no sino, **em tempo real** |
| 3 | Clicar no link da notificação | Download do `.xlsx` com ~90k linhas |
| 4 | Abrir o arquivo | Colunas corretas, sem linha vazia |
| 5 | Tentar baixar exportação de outro usuário | **403** |

⚠️ O item 2 testa **duas** coisas: a fila e o WebSocket. Se a notificação aparecer só ao
recarregar a página, o Reverb não está entregando — ver 7.7.

### 7.6 ⚠️ Exportações síncronas e o `memory_limit`

**Este é o teste que valida um risco que nunca foi verificado em produção real.** O trait
`ExportaPlanilha` chama `ini_set('memory_limit', '1024M')`, e isso **não tem efeito** se o
pool do PHP-FPM travar o valor via `php_admin_value`.

```bash
# Na instância:
php -i | grep memory_limit
# E via web, criando um phpinfo() temporário — o valor do FPM pode diferir do CLI
```

Depois: exportar **Tabela de Preços** (27 mil produtos) e **Leads** (17 mil) pela interface.
Se der 500 silencioso, o valor está travado e é preciso mudar para `php_value` no pool.

### 7.7 Reverb / sino em tempo real

| # | Teste | Esperado |
|---|---|---|
| 1 | DevTools → Network → WS | Conexão `wss://crm.autopel.online/app/...` com status `101` |
| 2 | Gerar notificação (aprovar orçamento em outra aba) | Sino atualiza **sem F5** |
| 3 | Target group `tg-reverb` | `healthy` |

⚠️ Se o WebSocket não conectar, o suspeito nº 1 é a regra de path do ALB; o nº 2 são as
variáveis `VITE_REVERB_*` (que precisam ser o domínio público em 443, não o host interno).

### 7.8 Performance — o teste que declara a meta cumprida

**Baseline de queries** (deve bater com o dev, porque é determinístico):

```bash
php artisan perf:baseline --perfis=admin,supervisor,vendedor --cache=quente --json=prod-inicial
```

| Rota | Queries esperadas | Se vier muito acima |
|---|---:|---|
| `/dashboard` | 5 | Cache não está sendo lido — checar Redis |
| `/carteira` | 7 | idem |
| `/leads` | 5-6 | |
| `/equipe` | 16-18 | Se vier ~218, o eager loading de roles se perdeu |

**Latência real — o número que vale:** CloudWatch → ALB → `TargetResponseTime`, percentil
p95, por target group. Navegue por todas as páginas core e depois compare:

| Métrica | Meta (Regra de ouro nº 9) |
|---|---:|
| TTFB p95 | ≤ 300 ms |
| Navegação Inertia p95 | ≤ 400 ms |
| Primeira pintura p95 | ≤ 1 s |

**Teste de carga** (opcional, mas recomendado antes de liberar):

```bash
LOADTEST_BASE_URL=https://crm.autopel.online node docker/loadtest.mjs 40 45
```

Referência do ambiente local (Docker/WSL2, que é **pior** que EC2): 27,9 req/s, todas as
páginas subsecond no p50. Em produção deve ser melhor.

⚠️ **Avise antes de rodar contra produção** — 40 usuários virtuais geram carga real e
poluem as métricas do CloudWatch.

### 7.9 Escopo por perfil (segurança de dados)

Com 200 usuários e 6 perfis, um erro de escopo expõe carteira alheia:

| # | Teste | Esperado |
|---|---|---|
| 1 | Logar como vendedor | Vê só os próprios clientes |
| 2 | Vendedor abre `/equipe` | Redirecionado |
| 3 | Vendedor acessa `/carteira/{id}/detalhes` de cliente alheio | **403** |
| 4 | Supervisor | Vê a própria equipe, não a empresa |
| 5 | Admin simula vendedor | Vê o que o vendedor vê; banner âmbar aparece |
| 6 | Encerrar simulação | Volta a ser admin |

---

## 8. Monitoramento

### 8.1 Alarmes mínimos (CloudWatch → SNS → seu e-mail)

| Alarme | Condição | Por quê |
|---|---|---|
| 5XX no ALB | `HTTPCode_Target_5XX_Count` > 0 em 5 min | Erro de aplicação |
| Latência | `TargetResponseTime` p99 > 2 s por 10 min | Degradação |
| Nó fora | `UnHealthyHostCount` ≥ 1 | Instância doente |
| CPU | > 80% por 10 min | Precisa escalar |
| RDS memória | `FreeableMemory` < 200 MB | Buffer pool apertado |
| **Redis evictions** | `Evictions` > 0 | ⚠️ Está descartando cache — subir memória |
| Fila crescendo | `queues:default` > 50 | Worker morreu |

### 8.2 Dentro da aplicação

- **Pill de fogo no Painel** — o indicador mais direto de que o warming está vivo
- **`php artisan cache:aquecer --status`** — quando foi o último aquecimento
- **RDS Performance Insights** — ranqueia a query por DB load real
- **Laravel Pulse** (recomendado, ainda não instalado) — slow queries e jobs falhando
- **Sentry ou equivalente** — ⚠️ com `APP_DEBUG=false` você fica cego a exceções sem isso

---

## 9. Armadilhas conhecidas

> Cada uma destas custou tempo real de investigação nesta sessão. Todas já estão
> corrigidas no código — a lista existe para o caso de reaparecerem em outra forma.

### 9.1 Rota Closure quebra o `route:cache`
`Route::get('/', function(){...})` faz `php artisan route:cache` abortar o arquivo inteiro.
Virou `InicioController`. **Sintoma:** deploy falha no cache de rotas.

### 9.2 `env()` fora de `config/` devolve null com `config:cache`
Com config cacheado o `.env` nem é lido. O `LegadoConexao` montava o DSN com `env()` e sairia
vazio. **Sintoma:** import quebra na primeira sincronização, não antes.

### 9.3 Sem `trustProxies`, o ALB envenena URLs e IPs
`url()` gera `http://` (mixed content) e `$request->ip()` vira o IP do balanceador —
poluindo a auditoria de `simulacoes_usuario`, que grava IP.

### 9.4 `REVERB_HOST` do servidor ≠ do navegador
Confundir os dois faz o broadcast falhar **em silêncio**: a notificação vai para o banco, o
push não sai. **Sintoma:** sino só atualiza com F5.

### 9.5 Worker sem a extensão certa morre calado
No Docker, `docker compose build app` não reconstrói `queue`/`reverb`, e o worker ficava sem
phpredis. Ele anunciava "Processing jobs from the [default] queue" e não consumia nada — o
erro (`Class "Redis" not found`) só aparecia em `docker compose logs queue`.
**Equivalente em produção:** worker rodando sem `php8.3-redis`. **Sempre confira `php -m`.**

### 9.6 `--timeout` do worker menor que o do job
O worker mata o processo sem passar pelo `failed()`, e o registro fica preso em
"processando" para sempre. Worker **700** > job **600**.

### 9.7 Scheduler desligado = cache esfria em silêncio
Nada quebra, só fica lento de novo, de forma intermitente. É o motivo de a pill de fogo
existir.

### 9.8 `allkeys-lru` no Redis descarta jobs e sessões
Use `volatile-lru`. E **nunca** `Cache::forever()`.

### 9.9 `ORDER BY <coluna> IS NULL, <coluna>` ignora o índice
Medido: 6,6 ms contra 0,73 ms. Se alguém "melhorar" uma ordenação adicionando isso de
volta, a página fica 9x mais lenta sem erro nenhum.

### 9.10 Índice de ordenação esquecido numa tabela irmã
`clientes` recebeu índice em `razao_social` em agosto; `leads` não, e a mesma tela ficou 48 ms
mais lenta. Ao criar tela nova com listagem ordenável, verificar o índice.

---

> As quatro abaixo saíram do provisionamento real de 2026-08-28, feito por SSH.

### 9.11 `php8.3-opcache` é pacote separado e não vem por dependência
Instalar `php8.3-fpm`/`php8.3-cli` **não** traz o OPcache no Ubuntu. Sem ele nada quebra:
o site sobe, tudo funciona — e cada requisição recompila o PHP inteiro. É o pior tipo de
falha para a Regra nº 9, porque é invisível. O `provisionar-servidor.sh` instala explícito
e confere depois.

### 9.12 O OPcache não se chama `opcache` no `php -m`
Ele sai como **`Zend OPcache`**, na seção `[Zend Modules]`. Qualquer verificação escrita
como `php -m | grep "^opcache$"` dá falso negativo mesmo com a extensão ativa. O script
normaliza (minúsculas + remove o prefixo `zend `) antes de comparar.

### 9.13 `crontab -l` com `set -e` instala um crontab VAZIO
`( crontab -l | grep -v X; echo "$NOVO" ) | crontab -` parece idempotente, mas numa máquina
**sem** crontab o `crontab -l` sai com erro e — sob `set -e` + `pipefail` — mata o subshell
antes do `echo`. Resultado: crontab vazio instalado em silêncio, ou seja, **scheduler
desligado sem ninguém perceber** (que é exatamente a 9.7). Montar a lista numa variável com
`|| true` antes de canalizar.

### 9.14 `VITE_APP_NAME` ausente deixa "Laravel" no título de toda aba
O Inertia lê o nome do app de `import.meta.env.VITE_APP_NAME`, embutido no **build**.
`APP_NAME` no `.env` corrige o lado servidor mas não o título do navegador, e `config:cache`
não alcança asset compilado — é preciso `npm run build` de novo.

### 9.15 Medir latência sem `X-Inertia-Version` mede resposta 409, não página
O Inertia responde **409** quando a versão de assets do cliente não bate — é o sinal para o
cliente recarregar. Um `fetch` feito à mão com `X-Inertia: true` mas **sem**
`X-Inertia-Version` recebe 409 sempre. A resposta é rápida e o teste parece ótimo: foi assim
que um benchmark desta sessão reportou "83-134 ms, tudo dentro do orçamento" medindo
respostas vazias. **Confira o status e o tamanho do payload**, nunca só o tempo. A versão
correta sai de `JSON.parse(document.getElementById('app').dataset.page).version`.

### 9.16 Build do Vite é determinístico — assets divergentes NÃO são a causa
Ao investigar os 409 acima, a hipótese natural foi "cada nó rodou seu próprio `npm run
build`, logo os hashes divergem". **Medido: não divergem** — os dois nós produziram
`manifest.json` com md5 idêntico e o mesmo `app-12X8Sh2q.js`. Vale saber para não perder
tempo com essa hipótese de novo; se um dia divergirem de verdade, aí sim o sintoma seria
409 em produção e 404 nos chunks.

### 9.17 `artisan tinker` sai com código 0 mesmo lançando exceção
`set -e` não pega. Um script que roda uma verificação por `tinker` e confia no código de
saída **anuncia sucesso com o sistema quebrado** — foi o que o `ativar-s3.sh` fez na
primeira execução, imprimindo "S3 ATIVO NOS DOIS NÓS" com a prova falhando. Conferir o
TEXTO da saída, e exigir a contagem esperada de "OK".

---

## 10. Rollback

| Situação | Ação |
|---|---|
| Deploy quebrou a aplicação | Forge → Deployments → redeploy do commit anterior |
| Migration quebrou o schema | `php artisan migrate:rollback --step=1` ⚠️ ver abaixo |
| Dado corrompido | RDS → Restore to point in time |
| Instância doente | O ALB tira do rodízio sozinho; recriar pelo Forge |

⚠️ **`migrate:rollback` em produção exige cuidado extra.** O `AppServiceProvider` tem
`DB::prohibitDestructiveCommands($this->app->isProduction())`, que bloqueia
`migrate:fresh`/`refresh`/`reset`/`db:wipe` — mas **não** bloqueia `rollback`. Antes de
rodar, confirme o que o `down()` daquela migration faz.

---

## 11. Checklist final — antes de liberar os beta testers

**Infra**
- [ ] `APP_ENV=production` e `APP_DEBUG=false`
- [ ] Os 3 drivers em `redis`; tabela `sessions` do MySQL vazia
- [ ] `config:cache`, `route:cache`, `view:cache`, `event:cache` no script de deploy
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] OPcache com `validate_timestamps=0`
- [ ] `trustProxies` ativo (URLs saindo em `https`)
- [ ] ALB `idle_timeout` = 300 s
- [ ] Health check `/up` respondendo e **sem tocar o banco**
- [ ] Redis com `volatile-lru`
- [ ] RDS Multi-AZ + PITR
- [ ] AWS Budgets com alerta em 80% e 100%

**Aplicação**
- [ ] `UPLOADS_DISK=s3` e `EXPORTS_DISK=s3` no .env (seção 5.4)
- [ ] Worker `queue:work` com `--timeout=700`
- [ ] **Scheduler ligado** no Forge
- [ ] Reverb rodando e o WebSocket conectando pelo domínio público
- [ ] `cache:aquecer` no fim do deploy
- [ ] Pill de fogo verde no Painel

**Dados**
- [ ] Carga inicial conferida (91k clientes, 931k faturamentos)
- [ ] Senhas dos beta testers definidas
- [ ] Um teste de cada perfil validando escopo

**Observabilidade**
- [ ] Alarmes do CloudWatch criados
- [ ] Access logs do ALB no S3
- [ ] Sentry (ou equivalente) capturando exceções
- [ ] Snapshot `perf:baseline --json=prod-inicial` guardado como referência

---

## 12. Diagnóstico rápido — "não está funcionando"

Tabela de sintoma → causa mais provável. Use na ordem: o primeiro suspeito acerta na
maioria das vezes.

### Ninguém consegue logar
1. `SESSION_DRIVER` não é `redis`, ou o Redis está inacessível → `redis-cli -h <endpoint> ping`
2. Security group `crm-redis` não libera o SG `crm-app`
3. `APP_KEY` mudou entre deploys (invalida todos os cookies)

### Sistema lento de novo, sem erro
1. **Scheduler desligado** → pill de fogo vermelha → `php artisan cache:aquecer --status`
2. Worker morto → Forge → Daemons
3. Redis com `Evictions` > 0 → está descartando cache → subir memória
4. `opcache.validate_timestamps` ficou em `1` → `php -i | grep validate_timestamps`

### Sino não atualiza sozinho (só com F5)
1. `VITE_REVERB_*` apontando para host interno em vez do domínio público
2. Regra de path `/app/*` do ALB ausente ou com prioridade errada
3. Target group `tg-reverb` unhealthy (matcher precisa aceitar `200,404`)
4. `REVERB_HOST` do servidor não alcança o app-1 (checar SG e IP privado)

### Exportação nunca chega
1. Worker morto ou sem `php8.3-redis` → `php -m | grep redis` **na instância do worker**
2. `--timeout` do worker < 600 → registro preso em "processando"
3. Credenciais do S3 erradas → ver `storage/logs/laravel.log`
4. `Exportacao::where('status','erro')` → a coluna `erro` tem a mensagem

### Imagens de faca / foto de perfil quebradas
1. Uploads ainda no disco local com 2 nós (seção 5.4) — quebra em ~50% dos loads
2. `php artisan storage:link` não rodou
3. `FILESYSTEM_DISK` não é `s3`

### Import do legado falha
1. `config('legado.homolog.host')` vazio → algum `env()` voltou para fora de `config/`
2. Sem rota de rede até o banco de origem (SG, VPN, ou IP não liberado no KingHost)
3. `max_allowed_packet` do RDS pequeno para os inserts em lote

### Deploy falha
1. `route:cache` abortando → alguma rota virou Closure de novo
2. `npm ci` sem memória → a instância precisa de swap, ou buildar os assets no CI
3. Migration com `down()` destrutivo

### 12.1 Comandos de emergência

```bash
# Estado do warming
php artisan cache:aquecer --status

# Forçar aquecimento agora
php artisan cache:aquecer

# Ver o que seria aquecido, sem executar
php artisan cache:aquecer --listar

# Baseline de performance (somente leitura)
php artisan perf:baseline --perfis=admin --cache=quente

# Diagnóstico de N+1 numa rota específica
php artisan perf:baseline --rotas=carteira --perfis=admin --sql

# Tamanho da fila
redis-cli -h <endpoint> -n 0 LLEN <prefixo>queues:default

# Chaves de agregação vivas
redis-cli -h <endpoint> -n 1 --scan --pattern '*agg:v1*'

# Limpar SÓ o cache (não derruba sessão nem fila — dbs diferentes)
php artisan cache:clear

# Reiniciar workers após deploy
php artisan queue:restart

# Jobs que falharam
php artisan queue:failed
```

⚠️ **Nunca rode `redis-cli FLUSHALL` em produção** — apagaria sessões (todos deslogados) e
a fila (jobs perdidos) junto com o cache.

## 13. O que fica pendente

| Item | Impacto | Quando |
|---|---|---|
| ~~Credencial de S3 para a aplicação~~ | Feito em 2026-08-28 via IAM role — ver 5.4.1 | ✅ |
| CloudFront na frente do bucket | URL de imagem assinada muda a cada render, sem cache de navegador | Quando o volume de upload crescer |
| 16 fotos de perfil com caminho do legado | Usuários caem no avatar padrão | Migrar ou limpar a coluna |
| ~~Carga inicial de dados~~ | Feita em 2026-08-28 — ver 6.1.1 | ✅ |
| Senhas dos beta testers | 200 usuários com senha inutilizável de propósito | Liberar sob demanda (6.4) |
| Trocar `MAIL_MAILER` de `log` para `smtp` | Nenhum e-mail de Cadastros sai | Ao abrir o beta, não antes |
| Alarmes do CloudWatch (seção 8) | Sem aviso de 5XX, latência ou fila parada | Semana 1 |
| Access logs do ALB no S3 | Sem diagnóstico de latência por rota | Semana 1 |
| ~~Uploads para o S3~~ | Feito em 2026-08-27 | ✅ |
| Sincronização automática com o TOTVS | Dado envelhece entre cargas manuais | Depende do Adriano |
| SES para e-mail transacional | Sem "esqueci minha senha" | Antes de abrir para todos |
| Laravel Pulse | Menos visibilidade de slow queries | Semana 1 |
| Deferred props no Dashboard (Fase 4) | Só importa se o p99 mostrar picos | Quando houver evidência |
| Recalibrar o prefetch | Política decidida sem métrica de produção | Após 1 semana de dados |
| 7 exportações ainda síncronas | Risco se o volume crescer | Quando alguma passar de 60 s |
