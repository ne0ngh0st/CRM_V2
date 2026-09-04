# Performance — o requisito nº 1 do CRM-V2

> Documento de referência da **Regra de ouro nº 9** (ver `CLAUDE.md`).
> Última revisão: 2026-08-26, na preparação do deploy AWS / beta.

## Por que este documento existe

Os usuários vêm do PALMA legado e estão **traumatizados com sistema lento**. Isso não é uma observação de UX, é o dado central do projeto: a lentidão do legado é a razão de o CRM-V2 existir. Um recurso correto que demora 3 segundos não é "quase pronto" — para quem usa, ele é pior que o legado, porque o legado pelo menos já é conhecido.

**Latência é requisito funcional, no mesmo nível de "a query traz o dado certo".** Uma feature não está pronta enquanto não estiver dentro do orçamento abaixo.

## Orçamento de latência

Metas por classe de interação. `p95` = 95% das requisições devem estar abaixo disso.

| Interação | Meta p95 | Nota |
|---|---:|---|
| TTFB do servidor (tempo de resposta do PHP) | **300 ms** | é o que o ALB mede como `TargetResponseTime` |
| Navegação entre páginas (XHR do Inertia) | **400 ms** | percebido como instantâneo |
| Primeira pintura útil da página | **1 s** | o "subsecond" combinado com o Tony |
| Ação de escrita (salvar observação, agendar, aprovar) | **500 ms** | usuário está esperando parado |
| Qualquer coisa acima de **2 s** | — | **não pode ser síncrona.** Vai pra fila com retorno assíncrono |

Não existe "página que é lenta porque é pesada". Existe página que ainda não foi transformada em assíncrona.

## Resultado das Fases 0-5 sob carga (2026-08-27)

Teste de carga com 40 usuários virtuais por 45 s, contra nginx + PHP-FPM, containers de dev parados. Mesmo ambiente e mesmo método nas duas medições — a única variável é o código.

| | Antes (fases 0-1) | Depois (fases 0-5) | |
|---|---:|---:|---|
| **Throughput** | 14,3 req/s | **27,9 req/s** | +95% |
| Requisições em 45 s | 702 | **1.289** | +84% |
| `/carteira` p50 | 4.894 ms | **647 ms** | −87% |
| `/dashboard` p50 | 2.556 ms | **670 ms** | −74% |
| `/leads` p50 | 1.769 ms | **890 ms** | −50% |
| `/equipe` p50 | 1.566 ms | **851 ms** | −46% |
| `/cadastros` p50 | 995 ms | **328 ms** | −67% |
| Erros | 0 | 0 | |

**Todas as 10 páginas ficaram subsecond no p50.** No p95, 6 das 10 — as quatro acima (carteira 1.022 ms, equipe 1.097 ms, leads 1.159 ms, metas 1.122 ms) passam de 1 s por pouco.

⚠️ **Isso não é a meta cumprida, e sim um forte indício.** O orçamento da Regra de ouro nº 9 fala em p95, e esses números vêm de um ambiente pior que produção (WSL2, máquina de trabalho) sob um cenário mais agressivo que o real — 40 usuários batendo sem pausa não é o mesmo que 200 pessoas/dia lendo a tela entre cliques. A meta só se declara cumprida no `TargetResponseTime` do ALB.

## Ordem de trabalho: medir → mudar → medir

Isto é a Regra de ouro nº 6 aplicada: **nunca otimizar por intuição.** Duas vezes neste projeto o palpite errou — o índice em `data_emissao` que não rendeu nada (baixa seletividade) e a simulação de memória do import que "provou" que cabia, quando não cabia.

Ferramentas, em ordem de uso:

1. **`DB::listen`** ou **Laravel Pulse** — quantas queries a página faz e quais são lentas.
2. **`EXPLAIN`** na query suspeita — procurar `type: ALL`, `key: NULL`, `Using filesort`, `Using temporary`.
3. **Cronometrar o caminho real do controller**, não a query isolada — o join de ordenação da Carteira só apareceu quando medido pelo controller inteiro.
4. **RDS Performance Insights** (produção) — ranqueia a query por DB load real.
5. **`TargetResponseTime` do ALB** (produção) — a verdade sobre o que o usuário sente.

## A ferramenta: `perf:baseline`

```bash
php artisan perf:baseline --perfis=admin,supervisor,vendedor --cache=ambos --json=baseline
php artisan perf:baseline --rotas=equipe --perfis=admin --sql        # diagnóstico de N+1
php artisan perf:baseline --comparar=baseline                        # antes/depois
```

Percorre as rotas de `config/perf.php` autenticado como cada perfil e mede queries, ms de SQL, KB de payload e pico de memória. **Somente leitura** — só rotas GET, e a primeira linha da saída diz contra qual banco vai rodar (Regra de ouro nº 7).

Mede cada rota de três jeitos, porque medem coisas diferentes: **html** (primeira pintura), **inertia** (navegação entre páginas) e **partial** (recarga parcial). O partial é a régua do progresso das Fases 4 e 5 — enquanto as props forem valores materializados, ele economiza payload mas não economiza query nenhuma.

`--sql` mostra as queries mais repetidas da pior rota, **normalizadas**: o eager loading do Laravel usa `whereIntegerInRaw`, que inlina os ids no SQL, então sem normalizar o mesmo N+1 aparece como centenas de consultas distintas e fica invisível justo na ferramenta feita pra achá-lo.

⚠️ O modo `frio` troca o store de cache por `array` em tempo de execução. **Nunca use `cache:clear` pra simular cache frio** — com o Redis compartilhado, isso derruba sessão e fila de todo mundo.

## Baseline medido — 2026-08-27

Escopo admin (o mais caro), banco `palma_v2` com dado real, cache quente:

| Rota | Queries | SQL ms | Payload KB |
|---|---:|---:|---:|
| `/dashboard` | 40 (53 frio) | ~200 | 35,6 |
| `/carteira` | 22 | **~900** | 53,8 |
| `/orcamentos` | 45 | ~40 | — |
| `/equipe` | ~~218~~ → **18** | ~~201~~ → 30 | 169,8 |

Três leituras que orientam o resto do trabalho:

1. **Carteira tem menos queries que o Dashboard e gasta 4x mais tempo de SQL.** Contagem de query e custo de query são problemas diferentes: a Carteira faz poucas consultas, mas uma delas é a agregação de aderência sem cache.
2. **O partial do Dashboard faz as mesmas 40 queries do html.** Prova de que as props são materializadas — `only:`/`defer()` não economizam nada até a Fase 4. O payload cai de 35,6 KB pra 2,3 KB, e só.
3. **Cache quente não ajuda `/orcamentos` nem `/equipe`** (mesmo número frio e quente): o custo delas não é agregação cacheável, é trabalho repetido por request.

### O caso `/equipe`: 218 → 18 queries com uma linha

Esta página não estava em nenhuma análise anterior — nem no `CLAUDE.md`, nem nas rodadas de otimização de julho. Ela apareceu no primeiro run da instrumentação.

`EquipeController::index()` monta o organograma com `VendedorPerfil::with('user:id,name,display_name')` e depois chama `$vp->user->getRoleNames()` no `map()`. O eager loading trazia o `user`, **mas não as `roles`** — então o Spatie consultava o banco uma vez por usuário: 201 queries extras. Só disparava para quem tem `podeGerenciar`, o que explica por que apenas o admin sofria.

A correção foi `->with(['user:id,name,display_name', 'user.roles'])`.

Isso explica retroativamente o teste de carga de julho, em que `/equipe` era a página **mais lenta do sistema** (p50 10,0 s, pior que Dashboard e Carteira) sem que ninguém soubesse por quê.

**A lição não é sobre eager loading — é sobre medir.** Duas rodadas de otimização passaram por cima dessa página sem vê-la, porque ninguém contava as queries. E ela era barata de achar: 30 segundos de instrumentação depois, estava na tela.

### Memoização do `DashboardScopeResolver`: três páginas de uma vez

O diagnóstico de `/orcamentos` mostrou 19 lookups de role por nome + 8 execuções de `User::role(...)->pluck('id')` no mesmo request. A causa está em `OrcamentoController::index()`:

```php
$baseQuery = fn () => $this->baseQuery($request);
$kpis = ['total' => (clone $baseQuery())->count(), /* … mais 6 … */];
```

Cada `$baseQuery()` reconstrói a query do zero, e `baseQuery()` chama `resolve()` + `usuarioIds()` internamente — oito vezes por requisição.

A correção foi memoizar no resolver, o que consertou três páginas de uma vez:

| Rota | Antes | Depois |
|---|---:|---:|
| `/orcamentos` | 45 | **13** (-71%) |
| `/carteira` | 22 | **14** (-36%) |
| `/dashboard` | 40 | **29** (-28%) |

⚠️ **A memória fica na instância, não no container.** O resolver é injetado no construtor dos controllers, então dentro de uma requisição já existe uma instância só — memoizar no objeto basta e o cache morre com ele. Registrar como `scoped()` faria a instância sobreviver entre as várias requisições de um mesmo teste, e um teste que alterasse `vendedor_perfis` entre dois `$this->get()` passaria a ler escopo velho. Ganho idêntico, risco menor.

⚠️ **Não confunda o número do teste com o do baseline.** O `phpunit.xml` força `CACHE_STORE=array`, então a suíte mede sempre o caminho FRIO. O Dashboard faz 42 queries frio e 29 quente — usar um para calibrar o teto do outro leva a conclusão errada (aconteceu, na primeira tentativa de remover a exceção do teto).

---

# Parte 1 — O que é CARO

Fontes de lentidão **neste projeto especificamente**, da mais grave pra menos.

## 1.1 🔴 Cache, sessão e fila gravados no próprio MySQL

Estado atual do `.env.example`:

```
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

Isto é o problema mais caro e menos visível do sistema hoje, porque **as três coisas que deveriam aliviar o banco estão dentro do banco**:

- Todo `Cache::remember` do Dashboard — o mesmo que derrubou o p50 de 8,6 s pra 1,8 s no loadtest — grava e lê da tabela `cache` no MySQL. O cache funciona, mas cada acerto ainda é uma ida ao banco que está sob pressão.
- **Sessão em `database` custa SELECT + UPDATE em toda requisição autenticada.** Com 200 usuários, é o piso de duas queries por página, antes de qualquer lógica.
- A fila em `database` faz o worker dar polling no MySQL continuamente, gerando carga de fundo constante.

**Correção:** Redis para os três. É a mudança de melhor relação ganho/esforço do projeto inteiro — três linhas de `.env` mais uma instância de ElastiCache.

### ✅ Feito em 2026-08-27 — e o ganho foi exatamente o previsto

**Toda rota autenticada do sistema perdeu exatamente 2 queries** (o SELECT e o UPDATE da sessão). Visível limpo nas páginas simples: `catalogo-facas` 5→3, `cadastros` 6→4, `tabela-precos` 9→7. As páginas maiores ganharam mais, porque somam o cache saindo do MySQL: `/orcamentos` 45→11, `/dashboard` 40→23, `/carteira` 22→12.

Essa previsão numérica exata é a melhor verificação que existe aqui: **se a queda não tivesse sido de exatamente 2 nas rotas simples, algo não estaria realmente usando o Redis** — e o `.env` dizendo `redis` não prova nada sozinho.

Peças: extensão `phpredis` nos dois Dockerfiles (`docker/dev` e `docker/php`), serviço `redis:7-alpine` no compose, `REDIS_HOST: redis` inline nos serviços PHP (o `.env` sozinho não resolve dentro do container), e os três drivers trocados.

**`tests/Feature/SessaoRedisTest.php`** cobre o que a suíte não cobriria: o `phpunit.xml` força `SESSION_DRIVER=array` para isolar os testes, então nenhum outro teste exercita o driver que roda em produção. Como trocar o driver de sessão tem o pior modo de falha do projeto — ninguém consegue logar —, esse teste força `redis` e prova login, persistência entre requisições, logout, e que a tabela `sessions` do MySQL fica vazia.

### ⚠️ `volatile-lru` protege a fila, não a sessão

Vale entender o limite da escolha. Com `volatile-lru`, sob pressão de memória o Redis descarta chaves **que têm TTL**. Jobs da fila não têm TTL, então estão protegidos — era esse o objetivo. Mas **sessões têm** (`SESSION_LIFETIME`), então em teoria um usuário poderia ser deslogado por pressão de memória.

Na prática isso não acontece aqui, e a conta mostra por quê: 200 sessões de ~2 KB dão ~400 KB, mais alguns MB de agregações cacheadas, contra 256 MB configurados — folga de duas ordens de grandeza. A política é global à instância (não dá para separar por database index), então o que resolve é dimensionar com folga, não configurar melhor.

**O sinal a monitorar em produção é `evicted_keys`** (via `redis-cli INFO stats` ou a métrica `Evictions` do CloudWatch no ElastiCache). Se ele sair de zero, o Redis começou a descartar coisa — e a resposta é subir a memória, não trocar a política.

⚠️ Com mais de um app node, isso deixa de ser otimização e vira **correção de bug**: a sessão precisa ser central senão a simulação de usuário quebra, e o cache precisa ser central senão cada nó mantém uma versão divergente das agregações.

## 1.2 🔴 Agregações de escopo amplo (admin / diretor / supervisor)

Medições reais com 91.293 clientes:

| Operação | Custo |
|---|---:|
| `carteiraSegmento()` no Dashboard (`CarteiraAderenciaResolver`) | ~2.000 ms |
| `/carteira` ordenando por segmento, escopo admin | 655 ms |
| `/carteira` ordenando por grupo, escopo admin | 510 ms |
| `faturamentoComparacao()` sem filtro de vendedor | 862 ms |
| Mesmas operações no escopo de um vendedor (283 clientes) | 6-9 ms |

**A assimetria é a chave da solução:** o escopo caro pertence a **pouquíssimos usuários** (admin, diretores, supervisores). A maioria das 200 pessoas é vendedor, e o escopo de vendedor já é naturalmente rápido. Isso significa que aquecer o cache dos escopos caros cobre ~20 combinações, não 200.

## 1.3 🔴 Cache frio — o furo do modelo atual

`Cache::remember` com TTL de 15 min protege quem chega em segundo lugar. Quem chega **no momento em que a chave expirou paga a conta inteira** — 2 s no Dashboard. Com 200 usuários e TTL de 15 min, isso acontece dezenas de vezes por dia, sempre na cara de alguém, e de forma aparentemente aleatória. É exatamente o padrão que gera a reclamação "às vezes o sistema trava".

### ✅ Fase 2 feita em 2026-08-27 — o terreno para o warming

Três peças novas, e o `DashboardController` caiu de 304 para ~75 linhas:

- **`App\Services\Cache\ChaveEscopo`** — monta toda chave de agregação, num lugar só. Normaliza ordem, duplicata e tipo (`['b','a']`, `['a','b']` e `['a','b','a']` geram a mesma chave), e vira hash quando a lista é longa. `paraDoDia()` embute a data para os blocos que dependem de `now()` por dentro.
- **`App\Services\Cache\CacheDeAgregacao`** — `lembrar()` (controller) e `aquecer()` (job) compartilhando chave e cálculo. O TTL sai de `config('perf.ttl_agregacao_minutos')`, não de literais espalhados.
- **`App\Services\Dashboard\DashboardBlocos`** — os blocos extraídos do controller, um método público por bloco, mais `comRecalculoForcado()`.

**Ganho imediato, com o `metaGauge` entrando no cache:**

| Cenário | Antes | Depois |
|---|---:|---:|
| `/dashboard` admin, cache quente | 23 queries | **5** |
| `/dashboard` vendedor, cache quente | 13 queries | **5** |
| `/dashboard` admin, cache **frio** | 53 queries | 40 queries, ~5 s de SQL |

O contraste entre 5 e 40 é a medida exata do que o cache frio custa — e o argumento numérico para a Fase 3.

**O que ficou de fora do cache, de propósito:** `statusSistema` (uma query em tabela minúscula, e cachear o rótulo "dados atualizados" por 30 min o tornaria mentiroso), `ligacoesStats` (1 query) e `observacoesStats` (3 queries). Esses três refletem o que o usuário acabou de registrar: se ele salva uma observação e o número não muda, ele não conclui "cache velho", conclui "não salvou".

**Correção: cache warming.** Um job agendado recalcula as chaves caras num intervalo menor que o TTL, então a chave nunca expira com alguém esperando:

- job a cada **10 min**, TTL de **30 min** → sempre reescrita antes de expirar
- só para os escopos caros (gestores), que são poucos
- o custo dos 2 s sai do caminho do usuário e vai pro worker

Isto é o que transforma "rápido quando o cache está quente" em "rápido sempre".

### ✅ Fase 3 feita em 2026-08-27

`AquecerCacheDashboardJob` + `EscoposAquecidos` + comando `cache:aquecer`, agendado a cada 10 minutos com `onOneServer()` e `withoutOverlapping(15)`.

**Números reais:** 10 escopos (1 empresa inteira + 9 equipes de supervisor), aquecidos em **9,1 s**. Rodando a cada 10 minutos, é 1,5% de duty cycle — irrelevante para o banco.

**Resultado no Dashboard, escopo admin:**

| | Queries | SQL |
|---|---:|---:|
| Cache frio (o que o azarado pagava) | 40 | ~5.000 ms |
| Cache aquecido pelo job | **5** | **12,8 ms** |

Um detalhe que confirmou o desenho: o supervisor `010395` tem **7 códigos de vendedor mas 8 usuários**. É o caso documentado de `cod_vendedor` compartilhado entre contas — e é por isso que os blocos por usuário (`orcamentosStats`, ligações, observações) chaveiam por `usuarioIds` e não por código.

**Comandos úteis:**

```bash
php artisan cache:aquecer --listar    # quais escopos seriam aquecidos
php artisan cache:aquecer --status    # quando foi o último aquecimento
```

⚠️ **O scheduler não roda sozinho localmente.** Ele depende de um cron real chamando `php artisan schedule:run` a cada minuto — em produção é o toggle *Scheduler* do Forge. Localmente, rodar `cache:aquecer` na mão quando quiser o efeito.

⚠️ **Um worker morto esfria o cache em silêncio.** Por isso o job grava `perf:ultimo-aquecimento`, lido por `cache:aquecer --status`. Sem essa marca, a degradação só apareceria como reclamação de usuário.

### ✅ Fase 5 feita em 2026-08-27 — a Carteira

| Escopo | Antes | Depois |
|---|---:|---:|
| admin | 12 queries / **951 ms** | 8 queries / **51 ms** |
| supervisor | 12 / 214 ms | 8 / 42 ms |
| vendedor | 14 / 21 ms | 8 / 16 ms |

**O achado que valeu mais que o plano:** a Carteira **sem filtro** calcula exatamente a mesma coisa que o card do Dashboard. `baseQuery()` sem filtros é `scopeQuery()` mais um `select('clientes.*')`, e o `CarteiraAderenciaResolver` limpa o select logo no início — a diferença some. Como a pergunta é a mesma, as duas telas passaram a dividir a mesma chave (Regra de ouro nº 8).

O ganho real disso não é economizar uma query: **essa chave já é aquecida pelo job**, então a Carteira sem filtro passou a nunca pagar a agregação, de graça. O plano previa aquecer os KPIs da Carteira separadamente; não foi preciso.

Com filtro ativo, cai num cache próprio de TTL **curto** (10 min). O TTL de 30 só faz sentido para chave que o job reescreve a cada 10 — numa chave que ninguém aquece, ele apenas guarda dado velho por mais tempo.

Também nesta fase: os dois `DISTINCT` sobre a tabela inteira (dropdowns de estado e segmento) cacheados por 6 h — dependem só do escopo, nunca dos filtros, senão o dropdown perderia opções conforme o usuário filtra. E os agendamentos viraram `Inertia::optional()`, carregados só na aba Calendário.

⚠️ **Prop opcional não vem em visita completa.** Entrar por `/carteira?aba=calendario` ou dar F5 é uma visita completa, e o Inertia não envia `optional` nesses casos — sem um `onMounted` que busque, o calendário abre vazio.

## 1.4 🔴 Trabalho síncrono longo dentro do request

O export de Excel da Carteira sem filtro, escopo admin: **95 s e 538 MB de pico**.

Três problemas de uma vez:
- o usuário fica com o navegador pendurado 95 s;
- 538 MB por export, multiplicado por quantas pessoas clicarem junto;
- **atrás do ALB isso vira erro:** o idle timeout padrão é 60 s, então o usuário recebe 504 enquanto o servidor continua queimando RAM gerando um arquivo que ninguém vai receber.

**Correção definitiva:** export vira Job na fila, e o usuário recebe notificação (o sistema de notificações já existe) com link pro arquivo no S3.
**Remendo aceitável pro beta:** subir o idle timeout do ALB pra 300 s.

## 1.5 🟠 Hidratação de Eloquent em volume

Instanciar um model Eloquent por linha é caro: cada objeto carrega atributos originais, casts, relações e flags de estado. Para 89 mil linhas de export, o custo de hidratação compete com o da query.

Onde importa aqui: `CarteiraExport` usa `FromQuery` + `WithChunkReading`, que hidrata models. `->toBase()` (devolve `stdClass` cru) ou `DB::table()` corta boa parte do pico de memória e do tempo.

**Onde NÃO importa:** uma listagem paginada de 30 linhas. Ali a hidratação é ruído — não trocar legibilidade por nada.

## 1.6 🟠 O `COUNT(*)` da paginação

Todo `paginate()` dispara um `COUNT(*)` com os mesmos filtros. Em `clientes` (91k) e `faturamentos` (931k) esse count pode custar tanto quanto a página de dados, porque não tem `LIMIT` pra interromper a leitura.

Todas as listagens do sistema usam `paginate()` hoje. Opções, em ordem de preferência:

- **`simplePaginate()`** — elimina o COUNT. Perde "página 3 de 47", mantém anterior/próxima. Para listas que o usuário navega sequencialmente (Carteira, Leads), a troca vale.
- **Cachear o total** por combinação de filtro.
- Manter `paginate()` onde a tabela é pequena (`orcamentos`, `metas_mensais`, cadastros).

⚠️ `CarteiraController::index()` já passa `total:` calculado por uma query separada sem o join de ordenação — isso continua sendo um COUNT, só que mais barato.

### ✅ Fase 6 feita em 2026-08-27 — e o plano estava mirando errado

| Rota | Queries | SQL |
|---|---|---|
| `/leads` | 11 → **6** | 170 → **26 ms** |
| `/pedidos-abertos` | 10 → 10 | 70 → **28 ms** |
| `/tabela-precos` | 7 → **3** | 72 → **44 ms** |

**A lição desta fase é sobre método, não sobre SQL.** O plano mandava consolidar os `count()` repetidos. Medindo antes de mexer, em `/leads`:

| Suspeito | Custo real |
|---|---:|
| `ORDER BY razao_social` (sem índice) | **48 ms** |
| `DISTINCT estado` (dropdown) | **45 ms** |
| Os 4 counts juntos | 19 ms |

Os counts eram o menor dos três. Consolidá-los rendeu 5 ms; o índice rendeu 47 ms. Quem seguisse o plano sem medir teria feito o trabalho certo no alvo errado.

**Causa raiz:** `leads` nunca recebeu o índice em `razao_social` — a migration de agosto o adicionou em `clientes` e deixou a tabela irmã de fora, apesar de as duas telas terem exatamente o mesmo desenho.

**O anti-padrão que sobreviveu:** `ORDER BY data_previsao_faturamento IS NULL, ... ASC` em Pedidos — 6,6 ms contra 0,73 ms. A Carteira já tinha corrigido isso em agosto; Pedidos não. Removido sem trade-off: medido que **zero** dos 3.517 pedidos em aberto tem previsão nula.

⚠️ **Dois alarmes falsos desmontados pela medição**, que valem mais que os ganhos:

1. O `whereDate()` quase não custava — porque não havia índice em `data_previsao_faturamento` para ele bloquear. Só passou a importar **depois** que o índice foi criado. Corrigi-lo antes teria sido trabalho por nada.
2. A primeira versão da migration indexava `leads.valor_estimado` (a tela ordena por ela). Medindo: **100% dos 17.173 leads têm esse campo nulo**. O índice foi removido antes do commit — ordenar por coluna toda nula não ordena nada, e o índice só custaria escrita nos imports.

**O que ficou deliberadamente como está:** `ORDER BY preco_tabela IS NULL` em `/tabela-precos`. Diferente de Pedidos, ali os nulos existem de verdade (2.557 de 26.989, 9,5%) e a expressão os joga para o fim, o que é o comportamento desejado.

## 1.7 🟠 Boot do Laravel sem os caches de produção

Sem `config:cache`, `route:cache`, `view:cache`, `event:cache` e OPcache com `validate_timestamps=0`, cada requisição relê e reinterpreta configuração, rotas e arquivos do framework. Você já mediu o extremo disso neste projeto: **3,8 s só no `require vendor/autoload.php`** no experimento de bind-mount.

Custa 15 minutos de configuração e sai do caminho de todo request para sempre.

## 1.8 🟠 Payload do Inertia

Cada navegação devolve o JSON de **todas** as props da página. Trocar um filtro na Carteira hoje reenvia a lista de 23 segmentos, os grupos, os KPIs de aderência e a paginação — mesmo que só a lista tenha mudado.

Duas ferramentas do Inertia v2 (já disponível, `inertiajs/inertia-laravel: ^2.0`) que o projeto **não está usando**:

- **Partial reloads** (`only: [...]`) — recarrega só as props que mudaram.
- **Deferred props** (`Inertia::defer()`) — a página renderiza imediatamente e as props caras chegam depois.

### ✅ Fase 8 feita em 2026-08-27 — prefetch na navegação

Os três wrappers (`NavLink`, `DropdownLink`, `ResponsiveNavLink`) passaram a declarar `prefetch` e `cacheFor` como props e repassá-las ao `<Link>`. Foi feito **explicitamente**, e não confiando no fallthrough de atributo, para que a capacidade fique visível na assinatura do componente e não dependa de o `<Link>` continuar sendo o nó raiz do template.

**O critério de qual link ganha o quê mudou durante a implementação.** O plano original dividia por custo ("caras" em `click`, "baratas" em `hover`), mas depois das fases 5 e 6 todas as páginas ficaram entre 4 e 51 ms — a distinção perdeu sentido. O critério passou a ser **comportamental**:

| Onde | Modo | Por quê |
|---|---|---|
| Links dentro de dropdown (9) | `hover` | Abrir o menu já é intenção demonstrada: o usuário está escolhendo entre 2-3 opções |
| "Início" | `hover` | Página mais visitada e uma das mais baratas |
| "Orçamentos" e "Cadastros" (links diretos) | `click` | O mouse passa por eles a caminho de outro item da navbar |
| Logout | nenhum | É ação, não navegação |
| Menu mobile | nenhum | Touch não tem hover; prefetch ali só gastaria dados |

`cacheFor` padrão de 30s evita refazer a busca quando o usuário passa o mouse várias vezes pelo mesmo item.

⚠️ **Recalibrar com dados do ALB depois do deploy.** Prefetch multiplica requisições, e a política acima é conservadora justamente porque foi decidida sem métrica de produção. Se o `RequestCount` subir muito mais que o esperado, mover alguns `hover` para `click`; se sobrar folga, o caminho inverso.

## 1.9 🟡 N+1 e `SELECT *`

Já houve um caso resolvido (itens do pedido na ficha do cliente, com `with('itens:...')`, medido em 11 queries para 20 pedidos e 161 itens). O padrão a manter: **eager loading com lista explícita de colunas**.

`clientes` é uma tabela larga; selecionar só as colunas usadas reduz I/O e hidratação.

## 1.10 🟡 `ORDER BY` em expressão e join só para ordenar

Já documentado no `CLAUDE.md` e vale repetir porque é sutil:

- `ORDER BY col IS NULL, col DESC` **ignora o índice** — 80 ms contra 0,34 ms.
- Desempate com direção divergente (`orderBy(col,'desc')->orderBy('id')` com id ASC) força filesort — 80 ms contra 0,39 ms.
- Ordenar por coluna de outra tabela força join + filesort. Já medido: 510-655 ms no escopo admin. Solução conhecida e medida ("deferred id": 501 ms → 135 ms), ainda não aplicada porque não compõe bem com `paginate()`.

## 1.11 🔴 Índice ESCOLHIDO não é índice SUFICIENTE — olhar o `Extra:`, não o `key:`

Terceira vez que este mesmo erro custa tempo neste projeto (faturamentos em 2026-08-31,
e mais duas em 2026-09-02). O padrão é sempre igual e é traiçoeiro justamente porque o
`EXPLAIN` *parece* bom: o `key:` aponta para o índice certo, e a consulta continua cara.

**O que denuncia é o `Extra:`**, não o `key:`:

| `Extra:` | Significa | Custo |
|---|---|---|
| `Using index` | leitura inteira dentro do índice | barato |
| `Using index condition` | usou o índice e ainda foi à tabela ler colunas | caro |
| `Using where` (com `type=index`) | varreu o índice todo e foi à tabela por linha | muito caro |

**Regra prática:** toda coluna que aparece no `SELECT`, no `WHERE` ou no `ORDER BY`
precisa estar NO índice — não basta a coluna do filtro. Um `<> 'valor'` numa coluna de
fora do índice obriga o MySQL a ir à tabela linha a linha, e ele frequentemente conclui
que varrer tudo sai mais barato.

Medido em `ligacoes` (289 mil linhas sintéticas), em 2026-09-02:

| Consulta | Índice antes | Índice depois | Antes | Depois |
|---|---|---|---:|---:|
| "Última ligação" da Visão do Gestor | `(usuario_id, data_ligacao)` | `+ status` | 969 ms | **154 ms** |

⚠️ O segundo caso **já estava lento antes de qualquer feature nova** e ninguém tinha
medido — só apareceu porque a tabela foi populada com volume realista. Regra de ouro
nº 6: o dado pequeno esconde o problema, não o resolve.

⚠️ **Estender índice não é criar índice.** O índice novo tem o mesmo PREFIXO do
antigo, então o antigo foi removido — não houve aumento de índices na tabela, só de
colunas no fim. Custo de escrita praticamente igual.

⚠️ **Ordem obrigatória: criar o novo ANTES de dropar o antigo.** `usuario_id` tem
chave estrangeira, e o MySQL recusa remover o único índice que a sustenta
(`Cannot drop index ...: needed in a foreign key constraint`).

## 1.12 🔴 "O mais recente de cada X" numa listagem — os três degraus

Caso real da coluna "Último contato" da Carteira, em 2026-09-02. O pedido parece
trivial e passou por três desenhos, cada um derrubado por medição. Vale como receita
porque **qualquer** "último/maior de cada item" numa tela paginada cai aqui.

**Degrau 1 — a forma ingênua: trazer tudo e filtrar em PHP.**
`get()` + `groupBy()->map(first)` traz TODOS os registros dos 30 itens da página e usa
30. Com uma página cujos 30 clientes tinham 271 contatos cada (8.130 linhas lidas para
exibir 30): **215 ms**. Em página típica (~3 por cliente): 6 ms — por isso passa
despercebido até alguém medir com volume.

**Degrau 2 — janela no banco.** `ROW_NUMBER() OVER (PARTITION BY x ORDER BY data DESC,
id DESC)` devolve exatamente 30 linhas: **25 ms** no mesmo pior caso, ~5 ms no típico.

**Degrau 3 — a pergunta que muda tudo: precisa ORDENAR por isso?**
Se sim, os dois degraus anteriores não servem, porque ordenar exige o valor de TODAS as
linhas, não das 30 da página. Medido no escopo admin (92k clientes, 281k contatos):

| Ordenar a Carteira por | Tempo |
|---|---:|
| `data_ultima_compra` (coluna indexada da própria `clientes`) | 1,2 ms |
| `MAX(data_ligacao)` via LEFT JOIN agregado | **987 ms** |
| `MAX(data_ligacao)` via subconsulta correlata | **1.179 ms** |
| `data_ultimo_contato` **desnormalizada em `clientes`** | **0,9 ms** |

Quase 1 s só na ordenação — estoura sozinho o orçamento de 400 ms. A saída foi
**desnormalizar**: `clientes.data_ultimo_contato` + `canal_ultimo_contato`, indexadas.
Ordenar voltou a custar 0,9 ms (`Backward index scan`, 30 linhas) **e** a consulta por
página sumiu de vez.

O precedente estava ao lado o tempo todo: `data_ultima_compra` também é valor derivado
(vem de `faturamentos`) e mora em `clientes` exatamente por isso.

**Quando desnormalizar vale:** valor lido em toda listagem, escrito raramente (aqui,
alguns milhares de contatos por mês → um UPDATE por contato), e que se quer ordenar.

⚠️ **O preço é drift, e o antídoto é dono único.** Um só ponto de escrita
(`UltimoContatoSincronizador`, chamado pelo hook `Ligacao::created()`), colunas fora do
`$fillable` para import/seeder não gravarem por acidente, e um comando de reconstrução
(`carteira:recalcular-ultimo-contato`).

⚠️ **A regra de desempate tem que ser a MESMA no hook e na reconstrução.** Se
divergirem, a coluna muda de valor toda vez que alguém roda a manutenção — bug que
aparece semanas depois e parece "a tela mudou sozinha". Há teste comparando os dois
caminhos.

⚠️ **Desempatar sempre.** `ORDER BY data DESC` sozinho devolve linha arbitrária quando
duas têm a mesma data (acontece: WhatsApp e e-mail no mesmo segundo). Aqui a regra é
"mais recente vence; em empate, o registrado por último".

---

## 1.13 🔴 `ORDER BY … LIMIT` com filtro pouco seletivo — o termo RARO é o mais caro

Medido em 2026-09-03 na busca de titularidade, sobre os 92.209 clientes reais:

| Termo | `LIKE 'termo%'` | `LIKE '%termo%'` |
|---|---:|---:|
| MERCADO (472 achados) | 1,5 ms | 44,7 ms |
| PADARIA (94) | 2,1 ms | 348,7 ms |
| KNTT (3) | 2,1 ms | **511,8 ms** |

**O contra-intuitivo: quanto mais específica a busca, pior o tempo.** Com
`ORDER BY razao_social LIMIT 30`, o MySQL percorre o índice em ordem e para na 30ª linha
que casar. Para "MERCADO" isso acontece cedo; para "KNTT", que tem três ocorrências, ele
percorre as 92 mil entradas antes de desistir. O `EXPLAIN` mostra `type=index` — varredura
do índice inteiro, não `range`.

**Correção:** prefixo primeiro (range no índice), "contém" só quando o prefixo não achou
nada. Quem digita o começo do nome — o caso normal — nunca paga. O único caso caro que
sobra é o termo que não casa por prefixo: 290 ms, e é o mais raro.

⚠️ **Um OR com coluna não indexada derruba o plano inteiro.** `razao_social` já era
indexada, mas casá-la com `nome_fantasia` num OR custava **287,3 ms contra 1,3 ms** — o
`key:` continuava apontando para `clientes_razao_social_index` e mesmo assim o plano era
ruim. Índice novo em `nome_fantasia` (`2026_09_03_130000`) fez o MySQL usar `index_merge`
com `sort_union`. Acrescentar uma terceira coluna àquele OR exige indexá-la junto.

## 1.14 🟠 Quadro do funil — o índice que o escopo do vendedor esconde

O funil de leads (2026-09-03) tem duas leituras por coluna: a contagem agregada e os
primeiros N cards ordenados por "mais parado". Com `leads_funil_idx (cod_vendedor, etapa,
etapa_alterada_em)`, o vendedor e o supervisor resolvem em **1,7 ms**.

**Mas o índice começa por `cod_vendedor`, e admin não filtra nada.** Medido com os 17.173
leads:

| Escopo admin | Antes | Depois |
|---|---:|---:|
| Contagem das 4 colunas | 55,1 ms | **10,4 ms** |
| Cards das 4 colunas | 281,1 ms | **8,1 ms** |

⚠️ **O sintoma estava no `Extra:`, não no `key:`** — de novo. O MySQL ESCOLHIA
`leads_status_index` e fazia `Using temporary` na contagem e `Using where; Using filesort`
nos cards. Com `leads_funil_geral_idx (status, etapa, etapa_alterada_em)` as duas viram
`Using index`. 336 ms para desenhar um quadro estouraria sozinho o orçamento de 400 ms.

**Regra prática que sai daí:** sempre que um índice de escopo começar por `cod_vendedor`,
perguntar quem NÃO filtra por vendedor — admin e diretor existem, e caem noutro plano.

## 1.15 🟢 Índice criado por antecipação — quando vale

`pedidos` ganhou covering index em 2026-09-03 (`(data_pedido, valor_total)` e
`(cod_vendedor, data_pedido, valor_total)`) replicando o padrão que valeu 6-8x em
`faturamentos`. **O ganho hoje é nulo**: a tabela tem 15.991 linhas e a agregação já rodava
em 16 ms mesmo com `type=ALL`.

O índice está lá pelo que vem: o histórico de pedidos emitidos (407.604 linhas no legado)
ainda não foi carregado, e quando entrar essa agregação vira exatamente o caso que custou
4.074 ms em `faturamentos`. **Criar índice com a tabela cheia custa janela de manutenção;
criar com ela vazia custa nada.** É a única situação em que vale antecipar sem medição de
ganho — e o docblock da migration diz isso explicitamente, para ninguém achar que houve
medição favorável.

⚠️ Corolário oposto, que continua valendo: **carga histórica sempre ANTES de criar índice**
(10m40s contra 66s, medido em 2026-08-31). Se o histórico de pedidos for carregado em lote,
pesar dropar esses índices durante a carga.

## 1.16 🟢 Métrica nova num bloco já cacheado — o custo real é o escopo, não a agregação

O gauge "Performance Comercial" do Painel passou a medir **venda (pedido emitido)** além de
faturamento (2026-09-04). Isso **dobrou** o número de agregações do bloco `metaGauge`: eram
duas (meta × faturamento, no mês e no ano), viraram quatro.

A intuição diz que dobrar agregação dobra o custo. Medido, não foi o que aconteceu:

| escopo | antes (só faturamento) | depois (dois tipos) | queries |
|---|---:|---:|---|
| empresa (`null`) | 167,8 ms | **149,0 ms** | 20 → 15 |
| equipe (51 códigos) | 49,2 ms | 57,1 ms | 8 → 9 |
| vendedor (1 código) | 11,7 ms | 17,7 ms | 8 → 9 |

*Medianas de 15 execuções INTERCALADAS entre as duas versões, no mesmo processo, para
cancelar deriva da máquina — em dev, sob Docker/WSL2, medições sequenciais de blocos
diferentes variam 30% entre si e uma comparação "antes, depois" ingênua mede o ruído.*

**Por que o escopo mais caro ficou mais rápido.** Duas economias pagaram a métrica nova:

1. **Resolver o escopo uma vez, não por agregação.** `metaVsRealizado(null)` descobre os
   códigos ativos com 6 queries de roles/perfis do spatie. Com quatro agregações, resolver
   por dentro seriam **24 queries só para redescobrir a mesma lista**. Daí
   `MetaRankingResolver::codigosDoEscopo()` ser público: quem monta um bloco com várias
   agregações no mesmo escopo resolve uma vez e passa adiante.
2. **Não somar duas vezes a mesma coisa.** O KPI "Valor no mês" era uma segunda soma de
   `pedidos.valor_total` na mesma janela e sobre os mesmos códigos que o realizado da aba
   Venda. Agora reaproveita o valor já calculado, e as duas contagens de pedido (mês e ano)
   saem de **uma query só** — a janela do mês é sufixo da do ano, então `COUNT(*)` responde
   o ano e `SUM(data_pedido >= início do mês)` responde o mês, na mesma varredura
   (`type=range`, `Using index`, mesmo padrão de `Ligacao::somarPorCanal`).

**Venda é barata onde faturamento é cara**, e a diferença é de ordem de grandeza — soma do
ano no escopo empresa: `pedidos` **24 ms** contra `faturamentos` **660 ms**. São 46 mil
linhas contra 6,0 milhões, as duas com covering index. Vale lembrar disso antes de recusar
uma métrica de pedido por medo de custo.

⚠️ **Nada disso está no caminho quente.** O bloco é cacheado por 30 min e pré-aquecido pelo
job, então o p95 de quem abre o Painel não muda — os números acima são o custo do
recálculo. A conclusão que interessa é outra: **dobrar o que um bloco cacheado entrega
costuma custar menos que uma ida a mais ao servidor**, e é por isso que as duas abas do card
vêm juntas em vez de serem buscadas no clique.

⚠️ **Mudar o FORMATO de um bloco cacheado exige bumpar `ChaveEscopo::VERSAO`.** O payload
de `metaGauge` deixou de ser `{mes, ano}` e virou `{venda, faturamento}`. Sem o bump, um
payload antigo ainda quente seria entregue ao front novo durante os 30 min de TTL após o
deploy — e quebraria o card no navegador de quem já estava logado, que é exatamente onde
ninguém está olhando o console.

# Parte 2 — O que é BARATO

Ganho alto, esforço baixo. Fazer tudo isto **antes** de considerar máquina maior.

| # | Mudança | Esforço | Ganho esperado |
|---|---|---|---|
| 1 | Redis para cache + sessão + fila | 3 linhas de `.env` + 1 instância | **Muito alto** — tira 2+ queries de todo request e alivia o MySQL |
| 2 | `config/route/view/event:cache` + OPcache `validate_timestamps=0` | 15 min de config | **Muito alto** — some com o boot |
| 3 | `composer install --optimize-autoloader --no-dev` | 1 flag no deploy | Alto |
| 4 | `APP_DEBUG=false` | 1 linha (**e é requisito de segurança**) | Alto |
| 5 | Cache warming dos escopos de gestor | 1 job + agendamento | **Muito alto** — mata o cache frio |
| 6 | Cachear a aderência no `CarteiraController::index()` | mesmo padrão já usado no Dashboard | Alto — `/carteira` custa 2,2 s hoje |
| 7 | Deferred props no Dashboard | 1 linha por prop cara | **Muito alto** na percepção |
| 8 | ✅ `<Link prefetch>` na navegação principal | 1 atributo por link | Alto na percepção |
| 9 | Partial reloads (`only:`) nos filtros | pequeno, por página | Médio-alto |
| 10 | Brotli/gzip no nginx | config | Médio-alto (JSON do Inertia comprime muito) |
| 11 | Cache imutável nos assets com hash | config | Médio |
| 12 | Cachear lookups quase estáticos (`segmentos`: 23 linhas, `grupos_cliente`: 2.429) | pequeno | Médio — elimina query por request |
| 13 | `simplePaginate()` nas listas grandes | 1 palavra por controller | Médio |
| 14 | `->toBase()` nos exports | pequeno | Médio (memória e tempo) |
| 15 | `pm = static` no PHP-FPM | config | Baixo-médio (evita fork) |

**Os 5 primeiros são configuração de deploy.** Nenhum deles é refatoração, e juntos provavelmente entregam a maior parte do caminho até o subsecond.

---

# Parte 3 — O que NÃO vale a pena aqui

Tão importante quanto a lista anterior: onde **não** gastar dinheiro nem tempo.

| Ideia | Por que não |
|---|---|
| **RDS maior** (r7g, m7g, mais RAM) | O banco inteiro tem **337 MB**. Cabe folgado no buffer pool de uma `t4g.small`. RAM além disso não é usada — o dataset já está 100% em memória. Gastar aqui é queimar dinheiro. |
| **CloudFront** | 200 usuários internos, assets de 1,1 MB, todos no Brasil, com o ALB já em sa-east-1. Adiciona complexidade de invalidação por ganho irrelevante. |
| **OPcache JIT** | Rende 5-10% em web PHP típico, às vezes zero. Medir antes; não é prioridade. |
| **RDS Proxy** | Serve para pool de conexões sob centenas de conexões concorrentes. Não é o caso. ~US$ 15/mês por nada. |
| **Micro-otimização de Vue** (`v-memo`, `shallowRef`) | As tabelas renderizam 20-50 linhas. O tempo está no servidor, não no browser. |
| **Reescrever query em SQL cru "porque Eloquent é lento"** | O custo está no plano de execução e na hidratação em volume, não no query builder. Medir primeiro. |
| **Instância t3 (burst)** | Não é "não vale" — é **contraindicado**. Quando o crédito acaba, a CPU é throttled e a latência fica errática, que é o oposto do objetivo. Usar família sem burst (m7g). |

---

# Parte 4 — Armadilhas específicas deste projeto

**Invalidação e dado velho.** Cache resolve latência e cria uma classe nova de bug: o usuário salva uma observação e não a vê. A regra: **agregação e KPI podem ser stale; aquilo que o usuário acabou de alterar, não.** Ao cachear, decidir explicitamente em qual categoria a informação cai.

**`memory_limit` × número de workers.** O export usa `ini_set('memory_limit','1024M')`. Com 20 workers PHP-FPM, o pior caso teórico é muito acima da RAM da máquina. Enquanto o export for síncrono, dimensionar o nó com folga (8 GB) e limitar a concorrência.

**Cross-AZ.** Manter Redis e o RDS primário na mesma AZ do app. Tráfego entre AZs adiciona latência de rede em cada query e ainda é cobrado por GB.

**Métrica de dev engana.** O ambiente local é Docker sobre WSL2 no Windows, que penaliza I/O pesadamente. Número medido aqui serve para **comparar antes/depois**, nunca como previsão do que produção vai fazer. O baseline honesto é o `docker/loadtest.mjs` rodado contra a própria AWS.

**⚠️ Antes de rodar o loadtest, PARE os containers de dev.** Medido em 2026-08-27, mesmo teste (40 usuários, 45 s), com e sem `app`/`queue`/`reverb`/`vite` no ar:

| | Dev rodando | Dev parado |
|---|---:|---:|
| Throughput | 9,2 req/s | **14,3 req/s** |
| `/dashboard` p50 | 3.741 ms | **2.556 ms** |
| `/dashboard` p99 | 18.737 ms | **4.446 ms** |

O `vite` rodando `npm run dev` e o `queue:listen` competem por CPU com o PHP-FPM e distorcem tudo — 55% de throughput. Só `mysql` e `redis` precisam ficar de pé (o loadtest fala com eles via `host.docker.internal`):

```bash
docker compose stop app queue reverb vite
```

**⚠️ Não compare com números de antes de 2026-08-04.** Os resultados de julho foram colhidos com XAMPP (MySQL nativo no Windows); hoje o MySQL roda em container sobre WSL2, que tem I/O mais lento. Comparações entre as duas épocas medem a troca de ambiente, não o código.

**Seed não expõe lentidão.** Regra de ouro nº 6 — foi assim que a query de faturamento passou de "instantânea" pra 1,3 s ao trocar centenas de linhas por 910 mil.

---

# Parte 5 — Checklist de produção

Infra e deploy:

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`
- [ ] `config:cache` + `route:cache` + `view:cache` + `event:cache` no script de deploy
- [ ] `composer install --optimize-autoloader --no-dev`
- [ ] OPcache: `validate_timestamps=0`, `memory_consumption=256`, `max_accelerated_files=20000`
- [ ] Brotli/gzip e cache imutável de assets no nginx
- [ ] `trustProxies(at: '*')` (atrás do ALB)
- [ ] Idle timeout do ALB ≥ 300 s enquanto o export for síncrono
- [ ] Família de instância sem burst
- [ ] Redis e RDS primário na mesma AZ do app

Aplicação:

- [ ] Job de cache warming dos escopos de gestor (10 min / TTL 30 min)
- [ ] Cache da aderência no `CarteiraController::index()`
- [ ] Deferred props no Dashboard
- [ ] `<Link prefetch>` na navegação principal
- [ ] Export de Excel para a fila, entrega por notificação + S3

Observabilidade (sem isto, nada acima é verificável):

- [ ] Laravel Pulse instalado
- [ ] RDS Performance Insights ligado
- [ ] Alarme: `TargetResponseTime` p99 > 2 s
- [ ] Alarme: `HTTPCode_Target_5XX_Count` > 0
- [ ] Sentry (ou equivalente) — com `APP_DEBUG=false` não há erro visível sem isso
