# PALMA CRM v2 — Contexto do Projeto

> Este arquivo é carregado automaticamente pelo Claude Code quando se trabalha nesta pasta.
> É versionado no git, então viaja com o repositório. Mantenha atualizado conforme o projeto evolui.

## Quem toca o projeto
Tony (Antonio Barbosa), desenvolvedor solo na Autopel Soluções (suprimentos corporativos — bobinas térmicas, etiquetas, tags, RFID, papel A4), em São Paulo. Autodidata, dono técnico de todo o ecossistema interno (CRM PALMA, sistema de licitações Laravel, dashboards Power BI, integrações TOTVS/Bling/Correios/PagBank). Comunicação direta e informal, em português. Único dev — não assumir que há outro dev pra revisar/pair.

## O que é este projeto
Refatoração completa do **PALMA** (CRM legado em PHP procedural, MySQL, ~200 usuários/dia, 78-92 páginas, 11 perfis) para stack moderna: **Laravel 11 + Inertia.js + Vue 3**. Motivação central: o PHP legado tem problemas sérios de performance; o objetivo do Laravel + AWS é deixar tudo bem mais rápido/"snappy".

- **Legado de referência:** `C:\Users\antonio.barbosa\OneDrive - autopel.com\Documentos\Sistemas\CRM-AUTOPEL-COMERCIAL` (é o PALMA atual em produção — código-fonte de `https://gestao-comercial.autopel.com`, deploy em `dist\deploy-kinghost`). ⚠️ **Mudou de lugar em 2026-08-10**: era `C:\xampp\htdocs\PLANO-DE-ESCAPE`, mas `C:\xampp` não existe mais nesta máquina (reinstalação) — o repositório é o mesmo (README ainda se chama "PLANO-DE-ESCAPE"), só o caminho mudou. Sessões antigas descreviam essa pasta como "projeto Docker não relacionado ao CRM-V2": **é o legado, sim.** Continua valendo que NÃO é `Site` (snapshot velho do mesmo sistema) nem `PALMA` (landing estática).
- **Escopo reduzido:** de ~78-92 páginas pra **~16 páginas core**, focado só em vendedores internos + representantes.
- **Hospedagem: AWS, no ar desde 2026-08-28 em `https://crm.autopel.online`** (sa-east-1: ALB + 2× EC2 `m7g.large` + RDS MySQL 8 Multi-AZ + ElastiCache Redis + S3). Provisionada **por SSH**, com os scripts versionados em `infra/` — o Laravel Forge não foi descartado, só depende de cartão corporativo que ainda não saiu. Estado, endereços e armadilhas em `docs/deploy-aws.md`. Azure AD fora de escopo (só um hook `azure_id` nullable).

## ⚠️ Duas regras de ouro (confirmadas explicitamente pelo Tony)

1. **Redesenhar > copiar o legado.** Antes de portar qualquer tabela/fluxo/padrão do legado, perguntar: "isso é a forma CERTA de modelar, ou é só como o legado fez por limitação histórica?". Não replicar gambiarra por inércia — o propósito do projeto é justamente corrigir isso. (Não é desculpa pra over-engineering; é não perpetuar decisão ruim.)

2. **CRM-V2 é SÓ comercial.** Não portar perfis/dados/features de **SAC** nem de **Licitação** (cada um tem seu próprio sistema), mesmo que apareçam nos dados reais do legado. Perfis do escopo: vendedor, representante, supervisor, assistente, admin, diretor.

## 🥇 Regra de ouro nº 9: latência é requisito, não polimento — **A MAIS IMPORTANTE DE TODAS**

> A numeração das regras é **cronológica** (ordem em que foram confirmadas), não de prioridade. Esta é a nº 9 por ter nascido em 2026-08-26, mas **precede todas as outras em importância**. Quando qualquer regra colidir com esta, esta vence.

Confirmado enfaticamente pelo Tony (2026-08-26), na preparação do deploy AWS: **os usuários estão TRAUMATIZADOS com sistema lento.** A lentidão do PALMA legado é a razão de o CRM-V2 existir — não é um defeito entre outros, é *o* defeito. Um recurso correto que demora 3 segundos não é "quase pronto": para quem usa, ele é pior que o legado, porque o legado ao menos já é conhecido.

**A regra:** latência é requisito funcional, no mesmo nível de "a query traz o dado certo". Nenhuma feature está pronta enquanto estiver fora do orçamento:

| Interação | Meta p95 |
|---|---:|
| TTFB do servidor | 300 ms |
| Navegação entre páginas (XHR do Inertia) | 400 ms |
| Primeira pintura útil | **1 s** (o "subsecond") |
| Ação de escrita (salvar, agendar, aprovar) | 500 ms |
| Acima de 2 s | **não pode ser síncrono** — vai pra fila |

Não existe "essa página é lenta porque é pesada". Existe página que ainda não foi transformada em assíncrona.

**Relação com a nº 6:** a nº 6 diz *meça com volume real antes de dar por concluído*. A nº 9 diz *qual número é aceitável*. A nº 6 é o método; a nº 9 é a meta.

**Corolário — não confundir com comprar hardware.** Máquina maior compra latência estável e headroom de concorrência; não compra uma agregação de 2 s virar 200 ms. O que rende está catalogado, com custo e ganho medidos, em **`docs/performance.md`** — consultar antes de otimizar qualquer coisa, e antes de gastar dinheiro em infra.

> ⚠️ **Este corolário dizia "o banco inteiro tem 337 MB e cabe em RAM em qualquer instância: overprovisionar o RDS não rende nada". Isso valeu até 2026-08-31 e deixou de valer.** Com a carga do histórico de faturamento (2018-2025), o banco foi para **1,66 GB** numa `db.t4g.small` de 2 GB. A frase sobrevive como princípio — hardware não conserta agregação cara —, mas não como diagnóstico: hoje existe um caso real neste projeto em que RAM é exatamente o recurso que falta. Ver "Carga do histórico de faturamento" mais abaixo, e **medir antes de decidir** (`FreeableMemory`, `SwapUsage`, `ReadIOPS`, `ReadLatency`), nunca repetir de cabeça nenhuma das duas versões desta linha.

## 🔀 Regra de ouro nº 10: sessão paralela trabalha em worktree própria

Confirmado pelo Tony (2026-09-09), depois do que aconteceu em 2026-09-08: duas sessões
editando a MESMA pasta ao mesmo tempo — o dev local chegou a responder 500 no meio de uma
edição alheia, e um `infra/deploy.sh` levou junto um commit feito de outra janela.

**A regra:** se já existe outra sessão/agente trabalhando neste repositório, a sessão nova
**não** trabalha na pasta principal — cria uma worktree própria antes de encostar em
qualquer arquivo. Pasta compartilhada por dois agentes não tem como dar commit limpo: cada
um vê no `git status` o trabalho pela metade do outro.

```bash
git worktree add /c/Users/antonio.barbosa/worktrees/CRM_V2-<assunto> -b <branch>
```

- ⚠️ **Criar a worktree FORA do OneDrive** (`~/worktrees/`, não ao lado do projeto): pasta
  irmã dentro do OneDrive vira uma cópia inteira do repositório sendo sincronizada pra
  nuvem, e o OneDrive já mexe em arquivo debaixo do processo que está escrevendo.
- No Claude Code dá pra usar `/worktree` (e sair com `/exit-worktree`), que faz o mesmo por
  baixo.
- Ao terminar: merge/PR em `main` e `git worktree remove <caminho>`. Worktree abandonada é
  branch esquecida.

### O que a worktree isola — e o que NÃO isola

**Isola** (é o ponto): árvore de arquivos, índice, branch e commits. Acaba o `git add -A`
levando arquivo alheio junto, e o deploy levando commit que não é da sessão.

**NÃO isola — continuam sendo recurso único desta máquina:**
- **O banco.** `palma_v2` e, principalmente, `palma_v2_test`: dois `php artisan test`
  simultâneos derrubam as tabelas um do outro no meio do `migrate:fresh` e produzem
  centenas de `QueryException` que não são bug de código nenhum. **Combinar quem roda a
  suíte**; worktree não resolve isso.
- **O Docker.** O compose sobe da pasta principal, com as portas 3306/6379/8000/8082/5173
  já tomadas e o bind-mount apontando pra lá — então o app que está de pé **não enxerga** o
  que a worktree edita. Não subir um segundo stack; pra ver a mudança rodando, mergear ou
  trabalhar na pasta principal.
- **`vendor/` e `node_modules/`.** São volumes nomeados do Docker, não estão no git: a
  worktree nasce sem eles. Vale pra checagem estática e edição; não pra `artisan` ali
  dentro.
- **O Redis** (cache, sessão e fila são compartilhados).

⚠️ **`infra/deploy.sh` puxa a branch inteira do remoto.** Com mais de uma sessão aberta,
conferir `git log` antes de deployar — o deploy leva o que estiver na branch, não o que
esta sessão fez.

## Stack e convenções
- Backend: **Laravel 11** (travado em v11.55.0, não v12+; o `composer` precisou de `--no-security-blocking` por advisories abertos na branch 11.x — XSS refletido só com `APP_DEBUG=true`, então **`APP_DEBUG=false` é crítico antes de qualquer deploy real**).
- Frontend: **Inertia.js + Vue 3**, scaffold via **Breeze** (stack Vue).
- Permissões: **spatie/laravel-permission** (roles).
- Estilo: Tailwind. Paleta corporativa azul-marinho; ver seção Marca.
- App em **pt_BR** (`APP_LOCALE`). `lang/pt_BR/auth.php` traduzido; `lang/pt_BR/validation.php` ainda NÃO (pendente).
- Estrutura sugerida: `app/Services/` (regras de negócio), `app/Jobs/` (sync TOTVS, notificações), `resources/js/Pages/` (uma por página core), `Components/`, `Layouts/`.

## Banco de dados — ⚠️ isolado de produção
O CRM-V2 **NÃO** conecta no banco de produção do KingHost (`autopel01`), nem pra leitura. Roda 100% num MySQL local, banco **`palma_v2`** (ver `.env`). Os usuários vieram de um **dump pontual** (snapshot único, sem sync ao vivo) da tabela `USUARIOS` do legado — 202 usuários do escopo comercial.

### Rodar localmente — Docker (atual, desde 2026-08-04)
Trocado de XAMPP pra Docker Compose nesta data (decisão do Tony — máquina foi reinstalada/trocada e ficou sem PHP/Composer/Node/XAMPP instalados, sem admin/UAC disponível pra rodar os instaladores; Docker Desktop já estava presente). **`C:\xampp` não é mais usado por este projeto** — se reaparecer em contexto futuro, é resquício de sessão antiga, ignorar.

- **`docker-compose.yml`** (raiz do projeto) — separado do `docker-compose.loadtest.yml` (esse continua só pra teste de carga nginx+PHP-FPM, não mudou). 6 serviços: `mysql` (8.0, porta `3306`, volume `mysql_data`), **`redis`** (7-alpine, porta `6379`), `app` (`php artisan serve` porta `8000`), `queue` (`queue:listen`), `reverb` (`reverb:start`, porta host `8082` → container `8080`, ver nota de porta abaixo), `vite` (`node:22-alpine`, `npm run dev`, porta `5173`).
- **Redis (desde 2026-08-27)** — `CACHE_STORE`, `SESSION_DRIVER` e `QUEUE_CONNECTION` são `redis`, não mais `database`. Tirou 2 queries do MySQL em **toda** requisição autenticada (o SELECT + UPDATE da sessão). Detalhe e números em `docs/performance.md` §1.1.
  - Extensão **`phpredis`** compilada via `pecl` nos **dois** Dockerfiles (`docker/dev` e `docker/php`) — é a mesma que o Forge instala, então dev e produção usam o mesmo client. Se mexer num, mexer no outro.
  - **`REDIS_HOST: redis` está inline no compose** nos serviços `app`/`queue`/`reverb`, igual ao `DB_HOST`. O `.env` sozinho não basta se alguém deixar `127.0.0.1` lá — dentro do container isso aponta pro próprio container.
  - ⚠️ **`--maxmemory-policy volatile-lru`, nunca `allkeys-lru`**: só descarta chave COM TTL, protegendo os jobs da fila (que não têm). Corolário: **`Cache::forever()` é proibido neste projeto** — chave sem TTL fica imune ao descarte e imortal.
  - Sem persistência em disco (`appendonly no`): um restart do container esvazia a fila. Jobs aqui são notificações, então tudo bem em dev.
- **Dockerfile em `docker/dev/Dockerfile`** (não confundir com `docker/php/Dockerfile`, que é só do loadtest) — `php:8.3-cli-alpine` com composer copiado da imagem oficial `composer:2`. Como é Linux (não Windows), `pcntl` existe aqui — a limitação histórica do `laravel/pail`/`PHP_CLI_SERVER_WORKERS` no Windows nativo não se aplica mais dentro do container (não testado se pail funciona agora, só uma observação).
- **Achado importante (replicado do `docker-compose.loadtest.yml`): bind-mount direto do código Windows→WSL2→container é lento pra árvore de muitos arquivos pequenos.** Em vez de copiar o código pra dentro da imagem (o que mataria o live-reload), a solução aqui foi usar **volumes nomeados só pra `vendor/` e `node_modules/`** (`vendor_data`, `node_modules_data` no compose) por cima do bind-mount (`.:/var/www`) do resto do código — código-fonte com live-reload normal, mas as duas árvores de arquivo mais pesadas ficam no storage nativo do Docker. `composer install` rodou em ~9,5s com esse esquema (vs. os 3,8s só pro autoload que o loadtest documentou com bind-mount puro). O serviço `vite` também monta `vendor_data:ro` (só leitura) porque `resources/js/app.js` importa `../../vendor/tightenco/ziggy` direto — sem esse mount o `npm run build`/`dev` quebra com "Could not resolve".
- **`vite.config.js` precisou de `server.origin: 'http://localhost:5173'` explícito** (+ `host: '0.0.0.0'`, `hmr.host: 'localhost'`) — sem isso, o Vite dentro do container detecta o próprio bind `0.0.0.0` e escreve isso no arquivo `public/hot`, gerando `<script src="http://0.0.0.0:5173/...">` no HTML — inválido no browser do host (`0.0.0.0` não é endereço conectável). Com `origin` fixo, funciona normal.
- **⚠️ Gotcha: o Vite dev server pode servir código de front DESATUALIZADO** (achado em 2026-08-10). Sintoma: você altera um `.vue`, salva, recarrega a página e **nada muda** — parece que a alteração não foi aplicada. O arquivo dentro do container está correto (bind-mount funciona, mtime idêntico ao host); o que está velho é o cache de transformação do Vite, porque o watcher não recebe os eventos de inotify através da ponte Windows→WSL2. Como `public/hot` existe, o Laravel serve pelo dev server e ignora o `public/build` — então nem `npm run build` adianta. **O que resolve: `docker compose restart vite`.** Foi adicionado `server.watch.usePolling` no `vite.config.js` como mitigação, mas **não confirmei que funciona** (depois de ligar, uma alteração ainda não foi detectada em teste) — então o restart continua sendo o procedimento confiável. Pra checar rápido se o dev server está velho: `curl -s http://localhost:5173/resources/js/Layouts/AuthenticatedLayout.vue | grep "algum texto novo"`.
- **⚠️ Porta do Reverb remapeada pra `8082`** (não `8080`, que é o padrão do `.env.example`) — porta `8080` já está ocupada pelo Docker do **próprio sistema legado** (`crm-autopel-comercial`, em `Documentos\Sistemas\CRM-AUTOPEL-COMERCIAL\docker-compose.yml` — ver seção "Legado de referência" acima; sessões antigas descreviam isso como projeto não relacionado, o que estava errado). Se esse outro projeto for desligado/removido algum dia, dá pra voltar a `8080:8080` no compose + `REVERB_PORT=8080` no `.env`, mas não é obrigatório.
- ⚠️ **`docker compose build app` NÃO rebuilda `queue` e `reverb`.** Os três usam o mesmo Dockerfile, mas o Compose mantém uma imagem por serviço: reconstruir só `app` deixa os outros dois com a imagem velha. Custou uma hora de investigação em 2026-08-27 — depois de adicionar a extensão phpredis, o worker anunciava "Processing jobs from the [default] queue" e não consumia nada, morrendo em silêncio com `Class "Redis" not found` (visível só em `docker compose logs queue`). **Depois de mexer no Dockerfile, rodar `docker compose build` sem argumento.**
- ⚠️ **O sino em tempo real nunca funcionou no Docker até 2026-08-27.** `REVERB_HOST=localhost` no `.env` aponta, dentro do container, para o próprio container — o broadcast falhava em silêncio (a notificação ia pro banco, o push não saía). Corrigido com `REVERB_HOST: reverb` / `REVERB_PORT: 8080` inline no compose para `app` e `queue`, e as `VITE_REVERB_*` do `.env` desacopladas (o browser continua em `localhost:8082`). Mesmo padrão do `DB_HOST` e do `REDIS_HOST`.
- Subir tudo: `docker compose up -d` (ou serviço a serviço). Primeira vez / após mudar `composer.json` ou `Dockerfile`: `docker compose build app` (reaproveitado por `queue`/`reverb`, mesma imagem). `composer install`/`artisan migrate`/etc. rodam via `docker compose run --rm app <comando>`.
- **Usuário de teste** (só nesta máquina, criado via tinker): `antonio.barbosa@autopel.com` / `homolog123`, role `admin`. Como o `legado:import-usuarios` sempre gera senha aleatória (nunca migra hash de senha do legado, de propósito), rodar de novo depois de reimportar usuários apaga essa senha — resetar de novo via tinker (`$u->password = bcrypt('homolog123'); $u->save();`) se acontecer.
- **Dados reais reimportados com sucesso em 2026-08-04**, depois que o Tony populou um segundo banco `autopel01_homolog` (container `crm-autopel-comercial-db-1`, projeto Docker separado, porta **`3307`** no host — não confundir com o `crm_v2-mysql-1` da `3306`). Achados desse processo:
  - `LEGADO_DB_*` no `.env` aponta pra lá: `LEGADO_DB_HOST=host.docker.internal`, `LEGADO_DB_PORT=3307` (nova env var), `LEGADO_DB_DATABASE=autopel01_homolog`, `LEGADO_DB_USERNAME=root`, `LEGADO_DB_PASSWORD=root`. Precisa de `extra_hosts: ["host.docker.internal:host-gateway"]` no serviço `app` do compose (já adicionado) — sem isso o container não alcança uma porta publicada no host Windows.
  - **`App\Services\Legado\LegadoConexao::pdo()` não suportava porta customizada** (DSN sem `port=`, sempre assumia 3306) — corrigido pra ler `LEGADO_DB_PORT`/`LEGADO_DB_PRODUCAO_PORT` (default 3306, mantém compatibilidade). `ImportUsuariosLegado` também montava um PDO cru duplicado em vez de usar esse serviço — trocado pra usar `LegadoConexao::pdo()` como os outros comandos.
  - **Nomes de tabela em maiúsculas eram case-insensitive no MySQL do XAMPP (Windows) e passaram a quebrar no MySQL do Docker (Linux, case-sensitive pro nome de tabela)**: `ImportLeadsLegado` buscava `base_leads` (real: `BASE_LEADS`) e `ImportOrcamentosHistoricoLegado` buscava `orcamentos` (real: `ORCAMENTOS`) — os dois corrigidos pra maiúsculo. Vale re-checar isso em qualquer código novo que monte SQL cru contra o espelho.
  - Sequência rodada com sucesso: `legado:import-usuarios` (201) → `legado:import-clientes` (89.637) → `legado:import-faturamento` (1.022.202) → `legado:import-pedidos` (6.188 abertos + 9.154 faturados **nessa data**; em 2026-08-10 o espelho já trazia 3.528 abertos + 12.006 faturados, 186.497 itens) → `legado:import-leads` (17.173) → `legado:import-produtos` (26.989) → `legado:import-orcamentos-historico` (2.103, pontual) → `db:seed` de novo (ativa `MetaMensalSeeder`/`LigacaoSeeder`/`SugestaoSeeder`/`ObservacaoSeeder`/`SegmentoVendedorSeeder`, que dependiam de vendedor/representante real existir). Dashboard conferido no browser com números reais batendo (89.637 clientes, aderência 4.939 dentro/15.307 fora do segmento, R$ 94,4M faturamento acumulado 2026, 1.698 orçamentos).
  - `SugestaoSeeder`/`ObservacaoSeeder` continuam gerando texto Lorem Ipsum (Faker) — não fazem parte da rotina de import real, é esperado ver isso nos cards de Observações/Sugestões mesmo com o resto 100% real.
- Reverb/queue/vite não têm mais o problema de "esquecer de subir" do setup XAMPP (eram processos manuais à parte) — agora é tudo `docker compose up -d`, um comando só sobe os 5.
- **Teste de carga / paridade com produção**: continua valendo o `docker-compose.loadtest.yml` (porta `:8090`, ver seção própria mais abaixo) — é um setup diferente (nginx+PHP-FPM com múltiplos workers, código copiado pra imagem), não o `docker-compose.yml` de dev.

### Redesenho da tabela de usuários (feito)
A `USUARIOS` do legado (24 colunas) misturava auth + dados de vendedor + preferências de UI. No v2 foi separado:
- **`users`** — auth/identidade puro (id, name, display_name, username, email, password, tipo_usuario, is_active, last_login_at, last_activity_at + colunas de UI nullable: telefone, estado, foto_perfil, sidebar_color, secondary_color, navbar_template).
- **Roles** via spatie (substitui a coluna `PERFIL` varchar). 6 perfis comerciais seedados (`RoleSeeder`).
- **`vendedor_perfis`** — 1:1 opcional, só quem tem código de vendedor (cod_vendedor, cod_super, cod_gerente, meta_venda, meta_faturamento, segmento, equipe_rep). Corrige tipos ruins do legado (`COD_VENDEDOR`/`COD_SUPER` eram `text`, `COD_GERENTE` era `double`). Obs: `cod_vendedor` NÃO é unique — há casos de código compartilhado entre usuários no legado.
- (O `GrpVendas`, que ficou em aberto aqui por um tempo, foi resolvido em 2026-08-10 como grupo do **cliente** — não tinha a ver com o redesenho de usuários. Ver seção "Grupo de cliente + densidade das tabelas".)

## ⚠️ Regra de ouro nº 3: nunca usar `raiz_cnpj`
Confirmado explicitamente pelo Tony (2026-07-27): `raiz_cnpj` (os 8 primeiros dígitos do CNPJ, usado no legado pra agrupar filiais do mesmo grupo econômico) **não entra em lugar nenhum do CRM-V2**, sem exceção. O legado tem um bug de produção documentado por causa disso (agrupar por raiz_cnpj em vez de `(cod_cliente, loja)` gerou cross-product de 50k+ linhas numa query de carteira). Se precisar agrupar por raiz de CNPJ algum dia, é `LEFT(cnpj, 8)` calculado na query — nunca uma coluna armazenada.

## ⚠️ Regra de ouro nº 4: Carteira/cliente é só leitura no CRM
Confirmado explicitamente pelo Tony (2026-07-27): **sem transferência de carteira, sem edição, sem exclusão de cliente dentro do CRM-V2** — tudo isso é feito no TOTVS e só sincroniza pra cá. A tabela `clientes` é um espelho de leitura (sem soft-delete, sem workflow de reassign, sem tela de editar cadastro). O legado tinha um bug real por causa de um fluxo de transferência mal-feito (rejeitar não revertia a mudança) — não existe mais esse problema porque a feature inteira não entra no v2.

## 🚨 Regra de ouro nº 7: NUNCA rodar comando destrutivo sem confirmar contra qual banco ele aponta
Nasceu de um incidente real em **2026-08-10**: rodei `php artisan test` pra validar uma feature nova. O `phpunit.xml` deste projeto estava com as linhas `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` **comentadas** (vinham assim do scaffold do Breeze), então os testes usaram a conexão padrão — o `palma_v2` de desenvolvimento — e o `RefreshDatabase` fez `migrate:fresh` nele. **Apagou os 89.637 clientes, 1.022.202 faturamentos, 15 mil pedidos, 17 mil leads, 27 mil produtos e os 201 usuários importados.** Deu pra restaurar porque tudo vinha do espelho `autopel01_homolog`, mas isso foi sorte da arquitetura, não do processo.

**A regra:** antes de rodar qualquer coisa que possa escrever/apagar em massa — `php artisan test`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, `db:seed`, truncate/delete cru em tinker — **verificar explicitamente qual banco está na mira** e dizer isso em voz alta antes de executar. "Rodei os testes pra conferir" não é motivo suficiente pra pular a checagem: foi exatamente esse o passo que causou o estrago. Se a resposta for "não tenho certeza", não rodar — perguntar.

**Nunca confiar só em configuração pra isso.** Config pode ser comentada, sobrescrita por `.env`, ou vir errada do scaffold — foi o que aconteceu. Barreiras que existem hoje no repo, e que não devem ser removidas:
- **Banco de teste dedicado: `palma_v2_test`** (no mesmo container MySQL do dev). `phpunit.xml` aponta pra ele com `force="true"`, pra que nem variável de ambiente do container sobrescreva. **É MySQL, não SQLite, de propósito**: as queries do projeto usam SQL específico de MySQL (`CONCAT`, `orderByRaw('... IS NULL')`, `COUNT(DISTINCT ...)`), então SQLite daria confiança falsa — e o SQLite em memória ainda quebra o `RefreshDatabase` aqui ("no such table: roles"). Se o banco sumir: `CREATE DATABASE palma_v2_test` + `GRANT ALL ON palma_v2_test.* TO 'palma'@'%'`.
- **`tests/TestCase.php::garantirBancoDescartavel()`** — trava em código: todo teste aborta com exceção **antes de qualquer migration** se o banco não tiver "test" no nome (ou não for SQLite em memória). Independe do `phpunit.xml` estar certo. ⚠️ Ela é chamada de dentro de `refreshApplication()`, **não** de `setUp()`: o `setUp()` do TestCase do Laravel chama `refreshApplication()` e logo depois `setUpTraits()`, e é `setUpTraits()` que dispara o `migrate:fresh`. A primeira versão desta trava rodava depois de `parent::setUp()` — ou seja, depois do banco já ter sido apagado. Não mover de lugar.
- **`AppServiceProvider`** — `DB::prohibitDestructiveCommands($this->app->isProduction())`, recurso first-party do Laravel 11 que bloqueia `migrate:fresh`/`refresh`/`reset`/`db:wipe`. É a proteção que vale pro deploy futuro no Forge/AWS, onde o mesmo erro seria irreversível (não existe espelho pra reimportar produção). ⚠️ **Só produção**: gatear em `! environment('local')` pega o ambiente `testing` junto e quebra a suíte inteira de um jeito nada óbvio (o `RefreshDatabase` precisa do `migrate:fresh`; sem ele todo teste morre com "table doesn't exist"). Já caí nessa ao escrever esta própria regra.

**Vale também pro legado**: o espelho `autopel01_homolog` (container `crm-autopel-comercial-db-1`, porta 3307) é lido pelos `legado:import-*`. Ele é fonte, nunca destino — nenhum comando do CRM-V2 deve escrever nele.

**Ordem correta de restauração** (se precisar de novo): `migrate` → `db:seed --class=RoleSeeder` (os `legado:import-usuarios` atribuem roles do spatie e falham se elas não existirem) → `legado:import-usuarios` → `import-clientes` → `import-faturamento` → `import-pedidos` → `import-leads` → `import-produtos` → `import-orcamentos-historico` → `db:seed` → resetar a senha do usuário de teste via tinker.

## ⚠️ Regra de ouro nº 8: decisão que se repete mora em UM lugar só
Confirmado explicitamente pelo Tony (2026-08-10), depois da auditoria feita antes de replicar o padrão de tabela pras outras 15 telas. **Vale pro sistema inteiro, não só pra tabela.**

**A regra:** se a mesma decisão — de estilo, de estrutura, de escopo, de regra de negócio — precisa valer em mais de um arquivo, ela tem que existir **uma vez**, num lugar nomeado, e ser referenciada. Copiar a decisão é o que faz o sistema divergir sozinho: ninguém "decide" que a tabela de Leads vai ter linha 4px mais alta que a de Pedidos, isso simplesmente **acontece** quando cada arquivo carrega sua própria cópia.

**Antes de duplicar qualquer coisa, a pergunta é: "se eu mudar de ideia sobre isto, em quantos arquivos vou ter que lembrar de mexer?"** Se a resposta for mais de um, extrair primeiro.

### Onde a decisão vai morar — o critério

| O que se repete | Onde mora | Exemplo real |
|---|---|---|
| Sequência de utilities Tailwind | classe no `resources/css/app.css` (`@layer components`) | `.tbl-td`, `.tbl-acao` |
| Estrutura de marcação (HTML) | componente Vue | `ModalPadrao.vue`, `SortableTh.vue`, `DarkCard.vue` |
| Regra de negócio / cálculo | classe em `app/Services/` | `OrcamentoCalculoService`, `ClienteStatusResolver` |
| Escopo de query por perfil | resolver + método `protected` reusado | `DashboardScopeResolver`, `CarteiraController::baseQuery()` |
| Rótulo / mapa de valores | `resources/js/constants/` ou tabela de lookup | `ROTULOS_STATUS_CARTEIRA`, `segmentos`, `grupos_cliente` |

**Classe no CSS quando o que repete é um punhado de classes; componente quando o que repete é estrutura HTML.** Não jogar marcação no `app.css` (não tira duplicação, só cria indireção), nem transformar em componente Vue o que é só uma sequência de utilities (peso de runtime à toa). Por isso `ModalPadrao.vue` e `SortableTh.vue` guardam os próprios estilos: componente já é fonte única.

### O que ISSO custou aqui, quando não foi seguido
- `px-3 py-2.5` copiado à mão em **14 componentes/páginas** de tabela. Mudar a densidade era editar 14 arquivos e torcer pra não esquecer nenhum.
- `class="h-3.5 w-3.5"` repetido em cada `<svg>` de ação — 5 vezes só na Carteira, prestes a virar ~50 no sistema.
- Corrente de 6 classes `disabled:` copiada por botão.
- Dois modais de observação (`Carteira` e `Leads`) com a mesma função e **layouts divergentes**, porque cada um foi escrito separado.
- Os `legado:import-*` montando PDO cru em vez de usar `LegadoConexao` — quando entrou porta customizada, um comando quebrou e os outros não.
- O legado inteiro é o exemplo-mor: identidade do usuário lida de `$_SESSION` em **257 arquivos**, e por isso a simulação de usuário só funcionava em 7 deles.

### O que continua legitimamente inline (não é violação)
Valor que **varia por caso de uso** não é decisão repetida: `min-w-[1000px]` na tabela (depende do nº de colunas), `max-w-[220px]` numa célula, o `<path>` de cada ícone, o texto de cada `title`. A regra é sobre a decisão ser a mesma, não sobre o valor ser parecido.

### Efeito colateral a vigiar
Centralizar cria dependência de **ordem** e de **cascata**, que não existia quando tudo era cópia. Caso real: no `app.css`, `.tbl-acao:disabled:hover` e `.tbl-acao-verde:hover` têm a mesma especificidade — quem ganha é o que vier por último no arquivo. Reordenar o CSS "pra organizar" quebra o estado desabilitado sem erro nenhum. Quando extrair algo, **comentar no próprio arquivo o que não pode ser reordenado/removido**.

## Marca Autopel
- **Logos:** em `public/images/` (`autopel-logo-white.png` = versão branca pra fundo escuro; `autopel-logo.png` = colorido). Originais em `C:\Users\antonio.barbosa\OneDrive - autopel.com\Documentos\Arte` (VETOR-03 = branco, VETOR-01 = cor).
- **Cores oficiais** (de `Arte\Tema.json`): teal `#005A6F`, cyan `#00A9CE`, navy `#0F3A69`, cinza `#C8C9C7`. Secundária/acento âmbar `#ff8f00`. (O token azul `#0f4c75` que aparece por aí é próximo mas não idêntico ao navy oficial.)
- **Gotcha técnico:** `<style scoped>` do Vue NÃO alcança elementos SVG criados via `document.createElementNS` no JS (não recebem o `data-v-*`) → fill/animação por classe scoped não aplicam e o SVG vira preto. Solução: setar fill/opacity/animation inline no JS; deixar só `@keyframes` num `<style>` global. (Aprendido no `resources/js/Components/TriangleMosaic.vue`.)

## Design System de Dashboard — regra pra novas páginas (confirmado por Tony, 2026-07-28)
Depois de várias rodadas de ajuste na Home, esse é o padrão visual **aprovado** pra reusar em toda página nova daqui pra frente. Inspirado no `home-comercial.php` do legado (headers pretos sólidos, cards densos), mas sem os hacks visuais dele (ver lixo não portado logo abaixo).

Componentes base, em `resources/js/Components/` (não dentro de `Dashboard/` — são genéricos, qualquer página pode usar):
- **`DarkCard.vue`** — card padrão do sistema. Header preto sólido (`bg-corp-black` `#1a1a1a`) com ícone (slot `#icon`, SVG inline simples — sem lib de ícones no projeto), título e **subtítulo obrigatório** (mesmo que curto). O subtítulo existe para manter os headers de cards vizinhos na mesma altura (`min-h-[3.5rem]`) — um `DarkCard` sem subtítulo fica mais baixo que os vizinhos e quebra o alinhamento da fileira. Corpo branco, cantos retos (`rounded` padrão do Tailwind = 4px, nunca `rounded-lg`/`rounded-xl`), borda `border-gray-300`. Slot `#actions` no canto direito do header pra botões (ex.: toggle "Ver evolução").
- **`PageHero.vue`** — cabeçalho de página, usado no topo do conteúdo (substitui o slot `#header` genérico do `AuthenticatedLayout`, que não é mais usado no Dashboard). Slots: `#icon`, `#subtitle`, `#meta` (pills à direita), `#filtros` (barra cinza-clara abaixo do header, pra filtros de visão).
- **`StatusPill.vue`** — badge semântica com tons `ok`/`warn`/`danger`/`neutral` (cores exatas do Tailwind: green/amber/red 50-700-300). Usada tanto nas pills do `PageHero` quanto em badges de linha de lista (ex.: "Aprovado"/"Rejeitado" nos orçamentos).
- **`KpiTile.vue`** — todo número de KPI vira uma "tile" com borda própria (`border-gray-200 bg-gray-50`), nunca texto solto. Regra de layout: container `flex flex-wrap gap-2` — **nunca** grid fixo com colunas travadas, **nunca** `overflow-x-auto`/scroll horizontal. Os tiles encolhem e quebram linha quando não cabem; cabem numa linha só quando há espaço. Números grandes (moeda) usam a prop `compact` (fonte menor); se mesmo assim apertar a fileira, isolar o tile num `<div>` próprio pra forçar a quebra pra linha seguinte (ver `PedidosAtencaoCard.vue`, "Valor em risco").
- **`FilterField.vue`** — label uppercase + select compacto, usado dentro do slot `#filtros` do `PageHero`.

Convenções de layout:
- Container de página: `mx-auto w-full max-w-[1800px] px-3 sm:px-4 lg:px-6` — não `max-w-7xl` (usa a largura real da tela). Mesmo valor no nav/header do `AuthenticatedLayout`, pra não ficar mais estreito que o conteúdo.
- Fundo de página: `bg-zinc-100` (era `bg-gray-50`).
- Cards com lista de registros recentes ("resumo"): título de subseção uppercase (`text-xs font-semibold uppercase text-gray-400`) + `<ul class="max-h-80 space-y-2 overflow-y-auto">`, item com `StatusPill` quando tiver status (padrão usado em Observações, Orçamentos e Pedidos Atrasados).

Aplicado em `Dashboard.vue` + tudo em `resources/js/Components/Dashboard/`. Toda página nova deve reusar esses 5 componentes em vez de recriar cards do zero.

**Regra de ouro nº 5: tabelas de dados e botões de ação (confirmado por Tony, 2026-07-27; densidade revisada em 2026-08-10).** Padrão aprovado depois da revisão da tabela de usuários da página Equipe. **Toda tabela nova usa os tokens de `resources/css/app.css` (`@layer components`) — nunca reescrever as classes na mão.** Referência canônica de uso: `resources/js/Components/Carteira/CarteiraTabela.vue`.

| Token | Onde | O que aplica |
|---|---|---|
| `.tbl` | `<table>` | `w-full text-sm` (largura mínima, ex. `min-w-[1000px]`, continua inline) |
| `.tbl-head-row` | `<tr>` do `<thead>` | fundo `bg-gray-50`, `border-b-2 border-gray-300`, texto `uppercase text-[0.65rem] tracking-wide text-gray-500`, `divide-x` |
| `.tbl-th` | `<th>` | `px-3 py-1.5 font-semibold` |
| `.tbl-body` | `<tbody>` | `divide-y divide-gray-200` |
| `.tbl-row` | `<tr>` do corpo | `divide-x divide-gray-200` + `hover:bg-gray-50/60` |
| `.tbl-td` | `<td>` | `px-3 py-1.5 text-center align-middle` |
| `.tbl-main` | 1ª linha de célula de duas linhas (razão social) | `mx-auto block truncate font-medium leading-4` |
| `.tbl-sub` | 2ª linha da mesma célula (CNPJ, código) | `block text-[0.65rem] leading-3 text-gray-400` |
| `.tbl-wrap` | `<div>` em volta da `<table>` | `overflow-x-auto` |
| `.tbl-acoes` | `<div>` dentro do `<td>` de ações | `flex flex-wrap items-center justify-center gap-1` |
| `.tbl-acao` | botão-ícone da coluna "Ações" | `inline-flex h-6 w-6 ... rounded border` (a cor vem do modificador) |
| `.tbl-acao-{neutro,teal,cyan,navy,amber,verde,danger}` | modificador de cor do botão | ícone + borda coloridos, tint no hover |

O que **não** precisa ser escrito na tabela, porque já vem dos tokens:
- **Tamanho do ícone**: `.tbl-acao svg` já aplica `h-3.5 w-3.5`. Não pôr classe de tamanho no `<svg>`.
- **Estado desabilitado**: `.tbl-acao:disabled` já cobre cursor, cor, opacidade e neutraliza o hover. Não repetir corrente de `disabled:` no HTML. ⚠️ No `app.css` esse bloco tem que ficar **depois** dos modificadores de cor — mesma especificidade, ganha quem vem por último; se subir, botão desabilitado volta a acender no hover.
- **Centralização do bloco truncado**: `.tbl-main` e `.tbl-trunc` já trazem `mx-auto`. Não repetir no HTML. ⚠️ E não remover do token achando que é decoração: são blocos com `max-w-[Npx]` inline, e bloco mais estreito que a célula encosta na ESQUERDA — o `text-center` do `.tbl-td` centraliza o texto dentro da caixa, não a caixa dentro da célula. Sem `mx-auto` o nome fica deslocado do próprio cabeçalho e da `.tbl-sub` de baixo (88px numa coluna de 480px). Até 2026-09-04 o `mx-auto` estava copiado no HTML de 8 tabelas e faltava no token; a 9ª tabela a nascer (a busca "Quem cuida do cliente?") saiu torta por isso — Regra de ouro nº 8 em estado puro.
- **Cor do texto da célula**: `.tbl-td` já é `text-gray-600`. Só usar `.tbl-main` (destaque) ou `.tbl-sub` (apoio) quando quiser fugir disso — nada de `text-gray-700` avulso.

Uma mesma cor pode servir a mais de uma função (aprovar e ligar são verdes; agendar e simular são cyan) — o que não pode é a mesma função mudar de cor entre telas. Era exatamente o que acontecia antes: agendar era âmbar nos Leads e cyan na Carteira, observação era navy nos Leads e âmbar na Carteira.

Sub-tabela de itens da linha expandida: `.tbl-itens`, `.tbl-itens-head-row`, `.tbl-itens-th`, `.tbl-itens-row`, `.tbl-itens-td` (o `<tbody>` reusa `.tbl-body`).

O que **continua legitimamente inline** (varia por tabela, não é gambiarra): `min-w-[Npx]` na `<table>`, `max-w-[Npx]` junto de `.tbl-main`/`.tbl-trunc`, e o `<path>` de cada ícone.

**Padronização concluída em 2026-08-10** nas 14 tabelas de dados do sistema: Carteira, Leads, Orçamentos, Pedidos (Abertos + Emitidos), Tabela de Preços, Equipe, Matéria-Prima, as 4 de Cadastros, Metas, Visão Gestor e Carteira/Detalhes.
- **Fora do padrão de propósito**: a mini-tabela do `Dashboard/CarteiraSegmentoCard.vue`. Não é tabela de dados — é a legenda de um KPI dentro de card, com alinhamento esquerda/direita, sem divisórias e com a linha inteira sendo um `<Link>`. Aplicar os tokens ali (centralizar tudo, pôr `divide-x`) pioraria. **Não "padronizar" essa depois achando que ficou pra trás.**
  - **Mesmo caso, e o contrário aconteceu**: `Dashboard/SegmentosInativosCard.vue` nasceu (05/09/2026) USANDO os tokens e ficou visivelmente errado — corrigido em 2026-09-10, a pedido do Tony ("mal formatado, fora do padrão do resto da página"). O card é faixa de 1800px com três colunas: o `divide-x` desenhava duas réguas verticais no meio do vazio, e os números paravam no centro da faixa, longe do nome que explicam. Além de adotar a linguagem de card, a folga da largura passou a ir para uma **barra de ranking** (proporcional ao MAIOR potencial, não ao total — com 20+ segmentos, fatia do total vira fiapo invisível), porque numa faixa dessa largura o problema não é só o estilo da célula: é ter 60% do card sem informação nenhuma.
  - **A regra que sai daí**: dentro de card, `.tbl*` é o padrão ERRADO — os tokens existem para tabela de dados de página cheia. Card usa alinhamento esquerda/direita, filete `border-gray-100` entre linhas e nenhuma divisória vertical.
- **Pendente (não é tabela, mas fica visivelmente diferente na mesma tela)**: os botões de ação do `Carteira/CalendarioAgendamentos.vue` continuam `h-7 w-7` e só coloridos no hover. Ficam na aba Calendário da mesma página `/carteira`, ao lado da tabela já padronizada. Resolver junto de um token genérico de botão-ícone fora de tabela (`ExportarExcelButton` e os cards do Catálogo de Facas cairiam no mesmo).

- **Por que centralizado**: é a Regra de ouro nº 8 aplicada às tabelas. O literal `px-3 py-2.5` estava copiado à mão em 14 componentes/páginas, então todo ajuste de densidade driftava entre eles. Mudar a altura de linha do sistema inteiro agora é editar `app.css`.
- **Coluna ordenável**: `Components/Tabela/SortableTh.vue` no lugar do `<th class="tbl-th">`. Só marcar como ordenável coluna que o backend saiba ordenar (whitelist no controller) — senão o clique não faz nada e parece bug. Ver "Ordenação por clique no header" mais abaixo, inclusive os achados de performance.
- **Células (header e corpo)**: sempre `text-center align-middle` — nunca `text-left`/`align-top`. Divisórias vêm dos tokens (`divide-x`/`divide-y`), nunca bordas manuais por célula.
- **Botões de ação** (coluna "Ações"): nunca texto sublinhado. Sempre `.tbl-acao` + um modificador de cor + ícone SVG inline `h-3.5 w-3.5` (sem lib de ícones, mesmo padrão do `DarkCard`). `title` no botão faz o papel de label (tooltip nativo), sem texto visível ao lado do ícone.
- **A cor identifica a função, e o ícone já nasce colorido** (confirmado por Tony, 2026-08-10). Até então a cor só aparecia no hover, o que obrigava a passar o mouse por cima de todos pra achar o botão certo. Mapa fixo:

  | Função | Modificador |
  |---|---|
  | ver detalhes, copiar, trocar senha | `.tbl-acao-neutro` (cinza) |
  | aprovar | `.tbl-acao-verde` |
  | simular usuário | `.tbl-acao-cyan` |
  | enviar p/ setor | `.tbl-acao-amber` |
  | rejeitar | `.tbl-acao-danger` |
  | editar | `.tbl-acao-teal` |
  | agendar | `.tbl-acao-cyan` |
  | orçamento, documento/PDF | `.tbl-acao-navy` |
  | observação, toggle de status | `.tbl-acao-amber` |
  | ligar | `.tbl-acao-verde` (convenção de telefonia, pedido do Tony) |
  | excluir | `.tbl-acao-danger` (vermelho) |

- **⚠️ `cyan`/`amber` da marca não servem pro ícone.** Sobre branco dão ~2,8:1 e ~2,2:1, abaixo dos 3:1 que um glifo fino precisa. Por isso o `tailwind.config.js` define `cyan`/`amber` como objeto `{ DEFAULT, dark }`: borda e tint usam o tom da marca (`DEFAULT`, e todo `text-cyan`/`bg-amber/10` que já existia continua igual), o glifo usa `-dark`. **Não trocar por `cyan-700`/`amber-600`** — este projeto sobrescreve as cores `cyan`/`amber` do Tailwind, então essas escalas não existem e o build quebra com "class does not exist".
- **`StatusPill` dentro de linha de tabela usa `size="sm"`.** O default (`md`) é pras pills do `PageHero`/`DarkCard`, onde sobra espaço; dentro da tabela a badge é o que define a altura da linha.
- **O que realmente controla a altura da linha** (achado ao densificar em 2026-08-10): não é o padding, é a célula mais alta. Numa célula de duas linhas o `line-height` padrão do `text-sm` (20px) somado ao da 2ª linha dominava tudo — daí `leading-4`/`leading-3` nos tokens `.tbl-main`/`.tbl-sub`. Na Carteira a linha caiu de ~56px pra ~40px (-29%) com padding, `leading`, botão (`h-7`→`h-6`) e pill ajustados **juntos**; mexer só no `py-` renderia quase nada.

**Regra de ouro nº 6: testar performance com volume real antes de dar por concluído (confirmado por Tony, 2026-07-29).** Motivo: ao importar o `FATURAMENTO` real (910.447 linhas) pra substituir o seed mockado (algumas centenas de linhas), a query de "Comparação de Faturamento" do Home (`DashboardController::faturamentoComparacao`) saltou de instantânea pra **1,3 segundo** pra quem vê a empresa inteira (admin/diretor/supervisor sem filtro de vendedor) — `EXPLAIN` confirmou `type: ALL`, table scan nas 895 mil linhas, `Using temporary; Using filesort`. Causa: `whereYear('data_emissao', $ano)` envolve a coluna numa função, o que impede o MySQL de usar qualquer índice nela (não sargable), mesmo existindo um índice em `(cod_vendedor, data_emissao)` — que só ajuda quem já filtra por vendedor (2ms nesse caso). Dado mockado (centenas de linhas) nunca ia expor isso; só apareceu com volume real.
- **Como aplicar**: toda vez que um seed mockado for substituído por import de dado real (ver `docs/importacao-dados-legado.md`), ou toda vez que uma query nova for escrita sobre tabela que vai ter volume real (`faturamentos`, `pedidos`, `clientes`, etc.), rodar `EXPLAIN` na query e medir tempo real (`microtime()`/tinker) **antes** de considerar a tarefa concluída — não confiar que "funcionou rápido com o seed" significa que vai continuar rápido com 900k linhas.
- **Sintomas a procurar no `EXPLAIN`**: `type: ALL` (table scan), `key: NULL` (nenhum índice usado), `Using filesort`/`Using temporary` em query que devia ser coberta por índice. Primeiro instinto: nunca envolver a coluna de data/filtro numa função no `WHERE` (`YEAR(col)`, `DATE(col)`, etc.) — usar intervalo direto (`col BETWEEN ... AND ...`) e criar índice cobrindo o caso "sem filtro de vendedor", não só o caso "com filtro de vendedor".
- **Mas isso pode não ser suficiente — index não é bala de prata.** No caso real: troquei `whereYear()` por `whereBetween()` e criei o índice em `data_emissao` sozinha, e o tempo só caiu de 1,3s pra 862ms — o `EXPLAIN` continuou mostrando `type: ALL`, índice ignorado. Motivo: **100% das 910 mil linhas importadas são do mesmo ano** (só existe um ano de histórico no espelho hoje), então o intervalo `BETWEEN` não filtra nada — o MySQL corretamente prefere ler a tabela inteira em vez de ficar saltando pelo índice pra "filtrar" 100% das linhas. Índice só ajuda quando a condição corta uma fração real da tabela.
  - ⚠️ **Atualização de 2026-08-31, e o desfecho é mais interessante que a lição original.** Com o histórico carregado (5,85 M de linhas, 2018-2026), o ano corrente virou ~18% da tabela — a seletividade que faltava passou a existir. **E o índice continuou sendo ignorado.** A causa não era mais seletividade: era o índice não **cobrir** a coluna somada. Sem `valor_total` nele, o MySQL teria que ir na tabela linha a linha para somar, e de novo concluiu que varrer tudo saía mais barato. Com `(data_emissao, valor_total)` a leitura acontece inteira dentro do índice (`Using index`) e a mesma consulta caiu de 4.074 ms para 660 ms. **Sempre olhar `Extra:` no EXPLAIN, não só `key:`** — índice escolhido e índice suficiente são coisas diferentes, e a diferença aqui foi 6x.
- **Fix de verdade nesse caso**: como é um KPI de dashboard (não precisa de frescor ao segundo), envolvi a agregação em `Cache::remember(..., now()->addMinutes(15), ...)` — primeira chamada continua ~880ms, mas todo mundo que abrir o Home nos 15 minutos seguintes (mesmo escopo/ano) pega do cache (~2ms). Lição: quando o índice não resolve porque a query genuinamente precisa varrer quase tudo, a resposta costuma ser reduzir **quantas vezes** isso roda (cache, ou uma tabela de rollup pré-agregada), não insistir em indexar uma coluna de baixa seletividade.

## Estado atual (2026-07-24)
✅ **Login funcional** em homologação, testado end-to-end. Sem registro público (usuários vêm do TOTVS/admin, igual ao legado); `/` redireciona pra login ou dashboard; login exige `is_active = true`.
✅ **Tela de login redesenhada** — split-screen (painel navy à esquerda + form branco à direita), com mosaico de triângulos interativo nas cores da marca (`resources/js/Components/TriangleMosaic.vue` + `resources/js/Layouts/GuestLayout.vue`), hover que segue o mouse, logo Autopel branco.
✅ **Git:** repo em `main`, remote `https://github.com/ne0ngh0st/CRM_V2.git`.

### HOME / dashboard — construída em 2026-07-27, em rodada de fechamento de gaps
Pra onde o login redireciona (`/dashboard`, `DashboardController`). V1 cobre: Status do Sistema, seletor de visão (supervisor/vendedor), gauge de Metas do Mês (com "Meta"→"Objetivo" pra representante), Ligações do Mês, Comparação de Faturamento, Sugestões e Melhorias, Observações Recentes.

Comparado contra a página real em produção (`https://gestao-comercial.autopel.com/home-comercial`, fonte em `PLANO-DE-ESCAPE\pages\COMERCIAL\home-comercial.php` + partials em `includes/reports/home_*.php`, carregados via lazy-load por `includes/ajax/home_secao_ajax.php`), 3 gaps rápidos já foram fechados: 2º gauge "Acumulado do Ano" (`MetaGaugeCard`/`MetaGaugeRing`, soma `metas_mensais` Jan..mês), KPIs de Observações (hoje/mês/clientes únicos) dentro do card "Ligações e Observações" (agora visível pra todos os perfis exceto assistente, não só vendedor/representante), e gráfico de faturamento retrátil (`FaturamentoComparisonChart`, colapsado por padrão, só monta o Chart.js ao clicar "Ver evolução").

Ainda faltam 3 blocos maiores que dependem de domínios de dados que o CRM-V2 não tem (decisão consciente: página própria futura, não widget da Home): **Carteira por Segmento** (tabela `CLIENTES` de produção, 89.800 linhas), **Estatísticas de Orçamentos** (tabela `ORCAMENTOS`, workflow de aprovação completo, 2.200 linhas) e **Pedidos que Requerem Atenção / Pedidos Emitidos** (`PEDIDOS_EM_ABERTO` 65.185 linhas + `META_VENDA`).

**Regra de ouro em ação:** o legado atual (`PLANO-DE-ESCAPE`) tem lixo que NÃO foi portado: easter-eggs "Sthefany" (banner de foguete) e "Bobinito" (popup secreto), hardcode de "dupla supervisão Sandra/Renata" na lógica de faturamento (`home_dupla_supervisao_alessandra_belo.php`), e um hardcode de busca por usuário com nome "AMERICO" pra resolver supervisor de quem tem perfil "vendas internas". Também tem tabela-por-ano continuando (`FATURAMENTO_2025`, `FATURAMENTO_2026`) — não replicado, v2 usa uma tabela `faturamentos` só.

### Schema + widgets de Carteira/Orçamentos/Pedidos — construído em 2026-07-27
Os 3 widgets que faltavam na Home (Carteira por Segmento, Estatísticas de Orçamentos, Pedidos que Requerem Atenção) estão prontos e testados — `CarteiraSegmentoCard`, `OrcamentosStatsCard`, `PedidosAtencaoCard` (`resources/js/Components/Dashboard/`), alimentados pelo `DashboardController` (métodos `carteiraSegmento`/`orcamentosStats`/`pedidosAtencao`, escopados como tudo mais via `DashboardScopeResolver`, visíveis pra todos os perfis exceto assistente). Status ativo/inativando/inativo da carteira é calculado por `App\Services\Carteira\ClienteStatusResolver` (290/365 dias desde a última compra em `faturamentos`, nunca uma coluna armazenada). Seeders (`SegmentoVendedorSeeder`, `ClienteSeeder`, `OrcamentoSeeder`, `PedidoSeeder`) já rodam via `DatabaseSeeder`. Rodei 3 agentes de exploração no legado real (`PLANO-DE-ESCAPE`) — Orçamentos, Pedidos (aberto+emitidos), Carteira (vendedor+admin) — e desenhei as tabelas já debloatadas:
- **`clientes`** — grão = filial (`cod_cliente`+`loja`, não CNPJ — CNPJ se repete entre filiais e o legado tem um bug de produção documentado por causa disso). Só leitura (ver Regra nº 4).
- **`carteira_motivos_inatividade`** — anotação por cliente, mantida do legado mas consolidada numa chave só (o legado tinha raiz/cnpj/cod_client+loja coexistindo por causa de migrações incrementais). `carteira_clientes_ocultos` e `clientes_contatados` existiram brevemente nessa rodada inicial mas foram removidas por completo em 2026-07-27 (ver seção "Reformulação das ações da Carteira" abaixo) — não portar de volta.
- **`segmentos_vendedor`** — **regra de negócio real, confirmada pelo Tony**: cada vendedor só atende 1-2 segmentos (setor do cliente — supermercadista, órgão público, drogaria etc., não confundir com produto). "Carteira por Segmento" é um relatório de **aderência**: quantos clientes ativos/inativando/inativos o vendedor tem DENTRO do(s) seu(s) segmento(s) vs. FORA. Era `segmentos_referencia_2026` no legado (chaveado por nome — quebra se o usuário troca de nome; aqui é por `cod_vendedor`, e um vendedor pode ter mais de uma linha = mais de um segmento). Cálculo em `App\Services\Carteira\CarteiraAderenciaResolver`, replicando `carteira_clientes_stats_aderencia_completa` do legado — sem a camada de "de-para" de segmento nem os tipos especiais `INATIVOS GERAL`/`PRIMEIRO CONTATO` (complexidade de página própria, cortada de propósito). Segmentos reais usados: SUPERMERCADISTA (domina, ~50-70% dos vendedores), ORGAO PUBLICO, DROGARIAS, REDE DE LOJAS, AEROPORTOS, etc. — ver `SegmentoVendedorSeeder`.
- **`orcamentos` + `orcamento_itens`** — nível de aprovação (nenhum/supervisor/diretor) é derivado do maior desconto entre os itens (regra de negócio real do legado: <10% auto-aprova, 10-15% supervisor, >15% diretor, sem exceção pro perfil). `itens_orcamento` era um JSON solto num TEXT no legado — agora é tabela normalizada.
- **`pedidos` + `pedido_itens`** — unifica o que no legado eram DUAS tabelas (`PEDIDOS_EM_ABERTO` + `META_VENDA`, essa última um nome errado — é o relatório completo de pedidos emitidos no TOTVS, não tem nada a ver com "meta"). `data_faturamento IS NULL` = pedido em aberto; a tabela inteira = pedidos emitidos.
- **Cortado por decisão do Tony**: `carteira_transferencias`/`carteira_vendedor_override` (Regra nº 4), `CLIENTES_GRUPOS`/`GRUPOS_CLIENTES` (0 uso real em produção), `snapshot_status_clientes` (dashboard de volatilidade — feature própria futura, fora de escopo aqui), a camada de "de-para" de segmento e os tipos especiais de segmento do legado (ver acima). (`clientes_para_cadastro`/fila de leads foi cortado nesta rodada mas voltou a entrar em escopo em 2026-07-27 à noite — ver seção "Leads, Cadastros e demais páginas" abaixo.)

As páginas completas de Carteira e Orçamentos já foram construídas (ver seção abaixo); Pedidos em Aberto segue só com o widget-resumo na Home.

### Reformulação das ações da Carteira — 2026-07-27
A página `/carteira` (`resources/js/Pages/Carteira/Index.vue` + `CarteiraController.php`) já existia com uma tabela de clientes e 4 botões de ação (ocultar, marcar contatado, motivo de inatividade, observações — só vendedor/representante). Depois de comparar com o legado real (3 agentes de exploração: um no CRM-V2, um no legado `PLANO-DE-ESCAPE`), o conjunto de ações foi reformulado:
- **Removidos por completo**: "ocultar cliente" e "marcar como contatado" — não fazem mais parte do sistema (models `CarteiraClienteOculto`/`ClienteContatado`, tabelas `carteira_clientes_ocultos`/`clientes_contatados` e as rotas `carteira.ocultar`/`carteira.contatado` foram apagados, não só desativados).
- **Motivo de inatividade fundido com a badge de Status**: deixou de ser um botão na coluna Ações e virou parte da própria pill de status — só aparece (clicável) quando `status === 'inativo'`, com um pequeno ícone indicando "motivo pendente" (`!`) ou "motivo já registrado" (✓). Isso também matou a expansão de linha (clique pra abrir um `<tr>` extra), que só existia pra mostrar motivo + contato — ambos removidos/relocados, então a tabela virou linhas planas. `MotivoInatividadeModal.vue` agora pré-preenche com o motivo mais recente ao abrir (antes sempre abria em branco, mesmo já tendo motivo registrado).
- **Ações reais passaram a depender do perfil** (`resources/js/Components/Carteira/CarteiraTabela.vue`, props `podeVerDetalhes`/`podeLigar`/`podeAgendar`/`podeOrcamento`/`podeObservar`):
  - **"Ver detalhes"** (rota `carteira.detalhes` → dados cadastrais + KPIs + histórico de pedidos): **liberado pra todos os perfis** desde 2026-08-10 (decisão do Tony; antes era só gestor). Não há middleware de perfil na rota nem lógica de role na página — quem limita é `CarteiraController::autorizarCliente()`, pelo `cod_vendedor` do escopo, então o vendedor só alcança cliente da própria carteira. Coberto por `tests/Feature/CarteiraDetalhesTest.php`.
  - **Vendedor, representante** (`isVendedor`): além de detalhes, "Realizar ligação", "Agendar ligação" e "Criar orçamento".
  - **Observações**: todos os perfis.
  - **Observações liberadas pra todos os perfis** — `ObservacaoController::store` aceita `cliente_id` (Carteira) ou `cnpj` (form livre da Home). Modal da Carteira lista o histórico do cliente e confirma o save. Coluna `cliente_id` adicionada em `observacoes`.
- **"Realizar ligação"** replica o "puxa o ramal" do legado (`assets/js/ligacao.js::fazerLigacaoDireta`): normaliza o telefone e dispara `tel:`/`callto:`, registra em `ligacoes` (com `cliente_id`).
- **"Agendar ligação"**: modal + tabela `agendamentos_ligacoes`. Aba **Calendário** na própria Carteira (`CalendarioAgendamentos.vue`) — grid mensal + lista de próximos + marcar realizado/cancelado.
- **"Criar orçamento"** navega pra `/orcamentos/novo` com query params e pré-preenche o form (era `/orcamentos` + modal; virou página própria em 2026-07-28, ver seção "Orçamento redesenhado" abaixo).
- **Hero de aderência**: `CarteiraSegmentoCard` (mesmo da Home) no topo da página `/carteira`, abaixo do PageHero.

### Leads, Cadastros e demais páginas — construído em 2026-07-27 à noite (sessão à parte, revisado em 2026-07-28)
Rodada grande que expandiu bastante o escopo original das ~16 páginas core. Adicionado nessa rodada:
- **Leads** (`/leads`, `LeadController`, tabela `leads`) — prospecção separada da Carteira (que é só cliente já existente no TOTVS). `origem` distingue import TOTVS (`sistema`), cadastro pelo vendedor (`manual`) e form do site (`wordpress`). Ligação/agendamento reusam os mesmos modelos da Carteira (`Ligacao`, `AgendamentoLigacao`), agora com `lead_id` nullable ao lado de `cliente_id`. Essa feature **tinha sido cortada** na rodada anterior (ver nota acima) — reentrou em escopo por decisão do Tony nesta sessão. Webhook do site: ver a seção própria "Captura de leads do site (WordPress)" abaixo. CSV: `marketing:import-wp-csv`. `legado:import-leads` não mexe em `manual` nem `wordpress`.
- **Cadastros** (`/cadastros`, `CadastroController`) — hub único com 4 tipos de "solicitação" (bobina, etiqueta, cliente novo no TOTVS, lead manual rápido), cada uma vira um `mailto:` pro setor certo (PCP, Cadastro, Cadastro Cliente). `ClienteParaCadastro` (tabela `clientes_para_cadastro`) é só essa fila de solicitação — **não** é a tabela `clientes` real nem quebra a Regra nº 4 (não cria/edita cliente de verdade, só pede pro time de Cadastros criar no TOTVS).
- **Metas** (`/metas`, `MetaController` + `MetaRankingResolver`) — ranking de metas vs. realizado, só gestor (admin/diretor/supervisor), com edição de meta escopada (supervisor só edita quem é `cod_super` dele).
- **Visão do Gestor** (`/visao-gestor`) — painel gerencial de observações/ligações da equipe.
- **Tabela de Preços** (`/tabela-precos`) — consulta de produtos, aberta a todos os perfis exceto assistente.
- **Pedidos Emitidos** (`/pedidos-emitidos`) — complementa `/pedidos-abertos` (que já existia).
- **Perfil** — upload de foto (`ProfileController::updateFoto`/`destroyFoto`); auto-exclusão de conta removida (não fazia sentido, usuário vem do TOTVS/admin).
- **Carteira**: ganhou `detalhes` (KPIs + histórico de pedidos do cliente, só gestor) e trocou "ocultar"/"marcar contatado" por ligação/agendamento/observações (ver seção "Reformulação das ações da Carteira" acima).

**Revisão de 2026-07-28** (checagem pós-sessão, já corrigido): 3 problemas reais encontrados e corrigidos — (1) `ProfileController::updateFoto` confiava na extensão de arquivo enviada pelo cliente em vez do MIME real detectado no servidor, abrindo brecha pra upload de webshell disfarçado de imagem; (2) `PasswordController` tinha baixado a senha mínima pra 6 caracteres "igual ao legado" (contraria a Regra de Ouro nº 1 — não copiar limitação do legado sem repensar); restaurado `Password::defaults()` do Laravel; (3) `CarteiraController::registrarMotivoInatividade`/`registrarLigacao`/`registrarAgendamento` não checavam se o cliente estava no escopo do usuário (diferente de `detalhes`/`atualizarAgendamento`, que já checavam) — um vendedor podia registrar ação em cliente de fora da carteira dele. Extraído `autorizarCliente()` reusado nos 5 métodos. Ainda pendente: dropar a tabela órfã `leads_manuais`/model `LeadManual` (dado já migrado pra `leads`, tabela ficou pra trás sem uso).

### Orçamento redesenhado — 2026-07-28
Pedido do Tony: a tela de novo/editar orçamento devia "parecer um PDF mesmo, como no legado", e o PDF gerado devia ser visualmente fiel ao legado. Investigação no legado (`PLANO-DE-ESCAPE`) mostrou que o "PDF" de lá **não é PDF de verdade** — é HTML com CSS de impressão + `window.print()` automático (gambiarra por falta de lib, não design). Mantivemos o `barryvdh/laravel-dompdf` já instalado e só redesenhamos o template pra ficar visualmente parecido, gerando PDF binário de verdade.

Mudou bastante coisa:
- **Novo/Editar Orçamento virou página cheia** (`/orcamentos/novo`, `/orcamentos/{id}/editar`, `OrcamentoController::novo`/`editar`), substituindo o modal antigo (`OrcamentoFormModal.vue` foi apagado). A página (`Pages/Orcamentos/Form.vue`) usa um componente `OrcamentoSheet.vue` que imita uma "folha de papel" (header com logo+CNPJ da Autopel, doc-box "ORÇAMENTO Nº X", grid de dados do cliente/orçamento, tabela de itens, observações, outras informações, footer) — cores da paleta oficial Autopel (navy/teal/cyan/âmbar), não as cores ad-hoc do legado.
- **IPI (3,25% embutido no preço)**: novo campo `orcamentos.tipo_produto_servico` (produto/serviço) e `orcamento_itens.calcula_ipi` (por linha, sempre `false` forçado no servidor quando `tipo_item = etiqueta` — etiqueta nunca tem IPI). Modo Produto mostra 4 colunas de valor (s/IPI derivado, c/IPI editável, totais); modo Serviço só 2. Cálculo centralizado em `App\Services\Orcamento\OrcamentoCalculoService` (`baseSemIpi = valor / 1.0325`, nunca armazenado, sempre recalculado).
- **Decisão deliberada (não é bug replicado)**: o legado compara `preco_tabela` direto contra o valor COM IPI embutido pra decidir desconto/nível de aprovação, o que infla o desconto aparente. No CRM-V2 a base de comparação é normalizada (remove IPI antes de comparar, via `OrcamentoCalculoService::baseParaDesconto()`, usado por `OrcamentoController::recalcularAprovacao()` antes de chamar `NivelAprovacaoCalculator` — que continua com a mesma assinatura de sempre).
- **Calculadora de precificação de etiqueta** (`Components/Etiquetas/EtiquetaCalculadora.vue`, endpoint `POST /orcamentos/etiquetas/calcular`) — **estritamente admin-only** (tela e endpoint; o legado tinha o endpoint de custo liberado pra bem mais perfis que a tela, corrigido aqui). Fórmula por m² (`App\Services\Etiquetas\EtiquetaPrecificadorService`, réplica fiel do legado): `custo_total = preco_m2_materia_prima × (largura_total_m × metros_rolo)`, `preco_sugerido = custo_total / 0.7`. **Quirk herdado do legado, não corrigido de propósito** (fora do escopo combinado): a "margem bruta %" exibida compara o preço de venda POR ETIQUETA contra o custo do ROLO INTEIRO — unidades diferentes, pode dar números absurdos (ex.: -69542%) se a quantidade pedida for bem menor que o rendimento do rolo. Os dois indicadores de "throughput" (etiquetas/rolo vs. metros necessários pra qtd pedida) também nunca são cruzados/validados entre si, igual no legado. Vale revisitar se incomodar no uso real.
- **Matéria-prima de etiqueta** — CRUD completo admin-only (`EtiquetaMateriaPrimaController`, tabela `etiquetas_materia_prima`: categoria/fabricante/cód/desc/largura/preço R$ por m²/ativo), acessível em `/orcamentos/materia-prima` (link só aparece pro admin na página de Orçamentos). Sem seeder — Tony cadastra os dados reais de custo pela própria tela.
- **Busca de cliente/produto no formulário**: `GET /orcamentos/busca-clientes` (busca em `Cliente` + `Lead::visivel()`, retorna nome/cnpj/telefone/email/estado/cep — sem endereço/número/bairro/cidade, que nem o legado nem o `clientes` atual têm estruturado) e `GET /orcamentos/busca-produtos` (busca em `Produto`, autocompleta código+descrição+preço de tabela no item).
- **Aprovação do cliente** (separada da aprovação interna gestor/diretor) — decisão do Tony: **fora de escopo**, não construído.
- Campos novos em `orcamentos`: `observacoes`, `variacao_producao_personalizado`, `prazo_producao`, `garantia_imagem`, `texto_importante` (os 3 últimos vêm pré-preenchidos com o texto padrão do legado, editáveis).

### Sistema de Notificações — construído em 2026-07-28
Investigação no legado (`PLANO-DE-ESCAPE\includes\management\notificacoes_interno.php`) mostrou que o sino de lá era um dos maiores causadores de problemas de performance: cada poll (60s originalmente, depois 5min como remendo) recomputava 6-15 queries do zero — incluindo `STR_TO_DATE()` não indexável em coluna TEXT, DDL (`ALTER TABLE`/`SHOW INDEX`) rodando dentro do endpoint de leitura a cada request, e 2 sistemas de sino paralelos, um deles morto. Nenhuma dessas queries nem esses padrões foram portados.

**Princípio do redesenho**: notificação é **escrita no momento do evento**, nunca recomputada na leitura. Ler é sempre um `SELECT ... WHERE user_id = ? AND lida_em IS NULL` na tabela `notificacoes`, indexada em `(user_id, lida_em)` — O(1), independente de quantas notificações existam.

**Entrega**: Tony escolheu **Laravel Reverb** (WebSocket self-hosted, first-party desde o Laravel 11) em vez de polling, mesmo sabendo que isso significa mais um processo local pra lembrar de subir — ver aviso na seção "Rodar localmente" acima e em `composer.json` (script `dev` já inclui `reverb:start`). Canal privado padrão do Laravel (`App.Models.User.{id}`, autorizado em `routes/channels.php`), evento `App\Events\NotificacaoCriada` (`ShouldBroadcast`, nome customizado `notificacao.criada`). Front: `resources/js/Components/NotificationBell.vue`, usa `window.Echo` (`resources/js/echo.js`, configurado por `install:broadcasting`/`reverb:install`) — carrega o histórico não lido via `GET /notificacoes` ao montar, escuta o canal em tempo real, e recarrega no `visibilitychange` (rede reconectando) como rede de segurança.

**Peças**:
- **`notificacoes`** (migration) — `user_id`, `tipo`, `titulo`, `mensagem`, `link`, `referencia_tipo`/`referencia_id` (chave de idempotência dos jobs agendados), `lida_em`. Unique em `(user_id, tipo, referencia_tipo, referencia_id)`.
- **`App\Services\Notificacao\NotificacaoService`** — único ponto de criação; dispara o evento de broadcast junto.
- **Gatilhos event-driven** (direto no controller que já muda o estado, sem observer/listener separado): `OrcamentoController::recalcularAprovacao` notifica o(s) aprovador(es) quando o orçamento entra em `pendente` (resolvido via `cod_super` do vendedor → supervisor certo; nível `diretor` notifica todos admin+diretor; sem supervisor mapeado cai pra admin+diretor também, pra não ficar órfão); `aprovar()`/`rejeitar()` notificam quem criou o orçamento. `ObservacaoController::store` notifica o dono da carteira do cliente (via `cliente.cod_vendedor`) ou o dono do lead (`lead.user_id`), quando o autor é outra pessoa.
- **Jobs diários** (`app/Jobs/`, agendados em `routes/console.php` via `Schedule::job()`): `NotificarAgendamentosDoDiaJob` (agendamentos de ligação de hoje) e `NotificarPedidosAtencaoJob` (pedidos atrasados/vencendo, mesmo critério do `DashboardController::pedidosAtencao`) — ambos idempotentes via `referencia_tipo`/`referencia_id`, não duplicam se rodarem 2x. `ExpurgarNotificacoesLidasJob` (semanal) apaga lidas há 30+ dias — o legado nunca tinha expurgo de verdade e as tabelas só cresciam.
- **Nota**: o scheduler do Laravel (`Schedule::job()`) só dispara sozinho com um cron real rodando `php artisan schedule:run` a cada minuto — em produção isso é o toggle "Scheduler" do Forge; localmente não roda em background automaticamente (não é bloqueante pro dia a dia, só afeta os 2 jobs diários — pra testar na mão, `php artisan schedule:run` ou disparar o Job direto via `php artisan tinker`).

### Exportação Excel — construído em 2026-07-29
Botão "Gerar Excel" (`.xlsx` real, via `maatwebsite/excel` — dependência nova no `composer.json`, sem `vendor:publish` necessário, o service provider é auto-discovered) em 9 páginas de listagem: Carteira, Orçamentos, Pedidos (Abertos + Emitidos), Leads, Metas, Equipe, Tabela de Preços e Cadastros (uma exportação por aba: bobina/etiqueta/cliente/lead, via `?recurso=`). **Nenhuma alteração de boot/infra** — sem migration nova, sem processo novo pra subir (ao contrário do Reverb, é síncrono, não usa fila/queue), sem env var nova. Só muda que `composer install` agora traz o `maatwebsite/excel` (+ `phpoffice/phpspreadsheet`).

**Regra de design**: o export nunca tem lógica de filtro/escopo própria — cada controller teve sua query de listagem (antes um closure local dentro de `index()`) extraída pra um método `protected` (`baseQuery()`/`listaQuery()` ou equivalente), reusado tanto por `index()` quanto pelo novo `exportar()`. Garante que o Excel reflete exatamente o mesmo escopo por perfil (`DashboardScopeResolver`/`EquipeScopeResolver`) e os mesmos filtros ativos na tela — sem isso, um filtro novo adicionado no futuro na tela poderia silenciosamente não valer pro export. Front: `resources/js/Components/ExportarExcelButton.vue`, botão-ícone que dispara download direto (`window.location.href`, sem fetch/blob/CSRF) quando não há filtro ativo, ou mostra um popup leve (reaproveita `Modal.vue`, sem componente de toast novo) avisando "o Excel será gerado só com os dados filtrados" quando há — `temFiltrosAtivos` é computado em cada página (não no componente), porque o que conta como "filtro" varia por página (ex.: `ano`/`mes` em Pedidos Emitidos/Metas são estruturais, não contam pro aviso).

**Achado de performance real (Regra de ouro nº6 em ação)**: a Carteira sem filtro, escopo admin (89.643 clientes) estourou o `memory_limit` padrão do PHP — medido em ~538MB de pico e ~95s (mesmo com `WithChunkReading` no `maatwebsite/excel`, que só reduz idas ao banco; o PhpSpreadsheet mantém todas as células como objeto em memória até escrever o `.xlsx`, então volume alto de linhas custa caro independente de chunk). Fix: `ini_set('memory_limit', '1024M')` + `set_time_limit(300)` só dentro de `exportar()`/`exportarAbertos()`/`exportarEmitidos()` das tabelas grandes (Carteira, Pedidos, Leads, Tabela de Preços — não Orçamentos/Equipe/Metas/Cadastros, cujas tabelas são pequenas o bastante pra não precisar). Testado de ponta a ponta: export completo sem filtro, export filtrado (popup + query string corretos), e regressão de escopo (vendedor comum exporta só a própria carteira).

**⚠️ Risco não testado, só se aplica quando migrar pra produção (Forge/AWS)**: `ini_set()` **não tem efeito** se o pool do PHP-FPM travar `memory_limit`/`max_execution_time` via `php_admin_value` (comum em configs mais restritivas de hosting) — só `ini_set` normal (`php_value`) é sobrescrevível em runtime. Se isso acontecer, o sintoma vai ser o export de Carteira/Pedidos sem filtro travando com 500 silencioso em produção mesmo funcionando local. Verificar a config do pool no Forge antes de considerar essa feature "pronta pra produção" (ver também `APP_DEBUG=false` na lista de pendências, mesmo motivo de nunca ter sido validado em prod real).

### Segmentos reais do TOTVS — redesenho em 2026-07-29
A lista de segmentos usada em `segmentos_vendedor` era **inventada** (nomes tipo "BIONEXO", "SUPRIMENTOS", "TRANSPORTADORAS" que não existem no TOTVS) e o campo era texto livre (`segmento` varchar), comparado diretamente contra `clientes.cod_segmento` (código numérico real, ex. "101"). Esse mismatch **quebrava silenciosamente** o cálculo de aderência inteiro (`CarteiraAderenciaResolver`, widget da Home e página `/carteira`) desde que `clientes` passou a ter dado real importado — o join nome×código nunca batia, então "dentro do segmento" sempre dava 0/baixo, sem erro visível (só funcionava no seed antigo porque o `ClienteSeeder` também usava nomes fake, mascarando o bug).

Corrigido, com Tony confirmando a fonte real: tabela `ultimo_faturamento` do TOTVS (espelho `autopel01_homolog`), colunas `Segmento1` (código) + `Descricao1` (nome) — 23 segmentos reais, ex. 101=SUPERMERCADISTA, 103=ORGAO PUBLICO, 109=DROGARIAS. Redesenho:
- **`segmentos`** (nova tabela) — `codigo` + `nome`, seedada com os 23 valores reais (`SegmentoSeeder`). Único ponto de verdade pro nome de cada código.
- **`segmentos_vendedor.segmento`** (varchar) virou **`segmento_id`** (FK pra `segmentos`). Atribuição vendedor→segmento continua sendo decisão manual de negócio (não vem de import TOTVS) — corrigida pela tela Equipe (`EditarUsuarioModal.vue`, checkboxes com os 23 segmentos reais).
- **`clientes.cod_segmento`** (código bruto do TOTVS, `COD_SEG`) tinha **zero-padding inconsistente na origem** — o mesmo código aparecia como `"101"` e `"000101"` dependendo do registro (confirmado na própria `CLIENTES` do TOTVS, não é bug do import). `ImportClientesLegado::normalizarSegmento()` agora normaliza (`(string)(int)$valor`) antes de gravar, pra bater com `segmentos.codigo`. Rodado `legado:import-clientes` de novo pra corrigir os 89.643 clientes já importados.
- Todo lugar que comparava/exibia segmento foi corrigido pra usar o join `clientes.cod_segmento = segmentos.codigo`: `CarteiraAderenciaResolver`, `CarteiraController` (listagem, filtro `aderencia`, dropdown de filtro, `detalhes()`), `CarteiraExport`. A coluna "Segmento" da Carteira e o filtro agora mostram o **nome** (com fallback pro código bruto se não houver match — existem ~331 clientes com código fora dos 23 conhecidos, tipo `100`/`102`/`110`, sem descrição em nenhuma tabela do TOTVS acessível; não inventado).
- `ClienteSeeder`/`SegmentoVendedorSeeder` atualizados pra gerar `cod_segmento`/`segmento_id` no mesmo formato do dado real (código, não nome) — evita reintroduzir esse tipo de mismatch entre seed e produção no futuro.

**Vendedor sem segmento definido (mesmo dia)**: nem todo vendedor tem 1+ segmento — vendedor recém-chegado ou generalista pode não ter nenhum. Antes disso virar caso normal, "aderência" tratava ausência de segmento igual a "fora do segmento" (0%), o que é enganoso (parece indisciplina, mas é só falta de cadastro). `CarteiraAderenciaResolver` agora tem um terceiro estado, `sem_segmento` (via `NOT EXISTS` correlacionado em `segmentos_vendedor`), **excluído do denominador** de `pctDentro`/`pctFora` — só entra no `total` geral. Refletido em `CarteiraController` (per-row `aderencia`, filtro `?aderencia=sem_segmento`, dropdown), `CarteiraExport` ("Sem segmento definido") e `CarteiraSegmentoCard.vue` (tile extra, só aparece se houver algum). `SegmentoVendedorSeeder` ganhou ~10% de chance de zero segmento pra vendedor interno.

**Regra de negócio confirmada por Tony (2026-07-29): todo `representante` atende só SUPERMERCADISTA**, sem exceção — `SegmentoVendedorSeeder` força isso (sem randomização, sem chance de zero) pra esse perfil; só `vendedor` (interno) mantém a distribuição variada de demonstração. Aplicado também nos 138 representantes reais já cadastrados (dado corrigido direto no banco, não só no seeder).

### Ambiente Docker de teste de carga (nginx+PHP-FPM) — 2026-07-30
Motivação: `php artisan serve` (o servidor de dev do `composer run dev`) processa **uma requisição por vez** — não dá pra usar ele pra estimar como o sistema aguenta usuários simultâneos, porque qualquer teste de carga contra ele mede a fila do servidor de dev, não a aplicação. No Linux dá pra contornar com `PHP_CLI_SERVER_WORKERS`, mas essa opção depende de `pcntl_fork`, que não existe nos builds de PHP pra Windows (mesmo motivo do `laravel/pail` fora do `composer run dev`). Solução: um stack Docker separado com nginx + PHP-FPM (múltiplos workers de verdade), pra ter uma aproximação real de como o Forge/produção vai se comportar, sem precisar subir nada na AWS.

**Arquivos** (não fazem parte do fluxo normal de dev, só do teste de carga):
- `docker-compose.loadtest.yml` (raiz do projeto) — sobe 2 serviços: `php` (build local) e `nginx` (imagem oficial `nginx:alpine`).
- `docker/php/Dockerfile`, `docker/php/www.conf` (pool do PHP-FPM), `docker/php/local.ini` (`memory_limit`, `opcache`).
- `docker/nginx.conf` — vhost padrão Laravel (root em `public/`, proxy `.php` pro FPM).
- `docker/loadtest.mjs` — script Node (sem dependência externa, só `fetch` nativo) que loga N "usuários virtuais" (mesma conta, sessões independentes) e fica navegando aleatoriamente pelas páginas core por X segundos, com think-time entre requisições. Uso: `node docker/loadtest.mjs [usuarios] [duracaoSegundos]` (padrão 40/30).

**Como usar:**
```
docker compose -f docker-compose.loadtest.yml build php   # só na 1ª vez ou depois de mudar código PHP
docker compose -f docker-compose.loadtest.yml up -d
node docker/loadtest.mjs 40 45                             # 40 usuários, 45s
docker compose -f docker-compose.loadtest.yml down          # derruba quando terminar
```
App fica em `http://localhost:8090` (separado do `php artisan serve` em `:8000` — dá pra deixar os dois de pé ao mesmo tempo, portas diferentes).

**Decisão de design importante (achado, não escolha a priori): código é `COPY` pra dentro da imagem, não bind-mount.** Primeira tentativa usava bind-mount (`.:/var/www`, igual ao Compose normal) e cada requisição levava **5-6 segundos** — isoladamente, `require vendor/autoload.php` sozinho levava 3,8s. Causa: a ponte de arquivo Windows→WSL2→container é extremamente lenta pra árvores de muitos arquivos pequenos (exatamente o padrão do autoload do Composer/Laravel), e com `opcache.validate_timestamps=1` isso significa um `stat()` por arquivo incluído em **toda** requisição. Fix: `Dockerfile` faz `COPY . /var/www` (código fica no filesystem nativo do Linux dentro da imagem) + `opcache.validate_timestamps=0` — igual é feito em produção de verdade (Forge também não faz bind-mount de outro SO). **Consequência prática: mudou código PHP, precisa rebuildar a imagem** (`docker compose -f docker-compose.loadtest.yml build php && docker compose -f docker-compose.loadtest.yml up -d --force-recreate`) — não tem live-reload aqui, diferente do `composer run dev`.

**Banco: não é isolado.** `DB_HOST` aponta pra `host.docker.internal:3306` — o mesmo MySQL do XAMPP que o dev normal usa, banco `palma_v2` com os dados reais já importados. Testar carga aqui bate nas mesmas tabelas que você usa no dia a dia (só leitura na maioria das páginas testadas, sem risco de corromper nada, mas evite rodar em paralelo com algo sensível a estado exato do banco).

**Pool do PHP-FPM**: `pm.max_children = 20` em `docker/php/www.conf`, dimensionado pra simular um servidor modesto tipo EC2 `t3.medium` (2 vCPU / 4GB) — o que o Forge rodaria em produção nesse projeto. Ajustável ali se quiser simular servidor maior/menor.

**Achado real do primeiro teste de carga (40 usuários virtuais, 45s), depois do fix de bind-mount:**
- Sem carga nenhuma (1 requisição), `/dashboard` já leva 2,1s, `/carteira` 2,2s, `/equipe` 1,3s — bem mais que as outras páginas (~0,2-0,3s).
- Sob 40 usuários simultâneos: `/dashboard` p50 8,4s (p99 12,2s), `/carteira` p50 7,7s (p99 10,7s), `/equipe` p50 10,0s (p99 13,8s). Zero erros/crashes — o stack aguentou, só ficou lento.
- **Causa identificada**: de faturamentoComparacao(), regra 6, só essa query do Dashboard tem `Cache::remember`. As outras 3 agregações do Home (`carteiraSegmento()`, `orcamentosStats()`, `pedidosAtencao()`) rodam sem cache a cada request — e `carteiraSegmento()` é a pior, porque chama o mesmo `CarteiraAderenciaResolver` (LEFT JOIN em `segmentos`/`segmentos_vendedor` sobre as ~89 mil linhas de `clientes`) que a própria página `/carteira` também roda sem cache. Sob concorrência, esse custo por-request (~2s sozinho) vira fila de contenção real.
- **Fix aplicado e confirmado no mesmo dia**: `Cache::remember` (~15 min, chave por escopo) em `carteiraSegmento()`, `orcamentosStats()` (chave por hash de `usuarioIds`, que pode ser uma lista grande) e `pedidosAtencao()` — mesmo padrão do `faturamentoComparacao()`. Refeito o teste de carga (40 usuários, 45s) depois do fix: `/dashboard` p50 caiu de 8,6s pra **1,8s** (p99 12,2s → 3,3s), throughput geral subiu de 6,0 pra **15,5 req/s** (2,6x), total de requisições atendidas na mesma janela quase dobrou (305 → 749). Zero erros nos dois testes. `/carteira` e `/equipe` (não alterados) também melhoraram bastante (7,5s→2,8s e 9,4s→4,6s de p50) só por sobrar mais worker do PHP-FPM livre — confirma que o Dashboard estava monopolizando capacidade. Ainda são as páginas mais pesadas do sistema; se precisar espremer mais, o próximo alvo é cachear a mesma agregação (`CarteiraAderenciaResolver`) dentro do próprio `CarteiraController::index()`.

### Levantamento de gaps contra o legado + Catálogo — 2026-08-10
Cruzamento das ~90 páginas do legado (pelo menu real, `includes/components/sidebar.php`, filtrado pelos 6 perfis do escopo) contra as páginas do v2. **Decisões de escopo do Tony nesta data — não reabrir sem ele pedir:**
- ❌ **Central de Demandas** (`demandas-sistema.php` + `gestao-demandas.php`) — não entra. O `/sugestoes` do v2 cobre a necessidade.
- ❌ **B2G** (Carteira/Análises/Produtos/Não Confirmados B2G, 4 páginas) — não entra, mesmo aparecendo no menu de supervisor/diretor/admin do legado.
- ❌ **Importar Bases** — fica por fora do CRM-V2 mesmo (continua só `php artisan legado:import-*`, sem tela).
- ❌ **Mapa de Usuários** (seção "Organização" do legado) — o que já existe na Equipe basta.
- ✅ **Catálogo de Facas + Catálogo de Produtos** — construídos (abaixo).

**Gaps que sobraram e ainda não foram decididos** (não são bugs, são escopo em aberto): "Quem cuida do cliente?" (busca global de titularidade por CNPJ, topo do `cadastro.php` do legado — útil porque a Carteira do vendedor é escopada e não mostra cliente de outro); tela de **importar tabela de preços** (admin, hoje o v2 só consulta); página própria de **Calendário** (v2 só tem a aba de agendamentos dentro da Carteira); **Devoluções de Faturamento**; **Organograma de Faturamento por Equipe**; **lixeira/auditoria de exclusões** (`admin_gestao_unificado.php` — o v2 não tem trilha de auditoria nenhuma).

**Catálogo (nav) — `/tabela-precos` + `/catalogo-facas`.** O item "Tabela de Preços" da navbar virou um dropdown **"Catálogo"** com duas páginas (mesmo padrão dos dropdowns "Visão Gestor"/"Carteira" que já existiam no `AuthenticatedLayout`). Rotas e controllers seguem separados; a Tabela de Preços não mudou.
- **Achado que evitou trabalho duplicado**: o "Catálogo de Produtos" do legado (`catalogo-produtos.php`) **é a mesma coisa que a Tabela de Preços do v2** — lê `CODIGO_PRODUTOS` (`COD_PROD/DESC_PROD/CAT_PROD/UN_PROD/PRCVENDA`), que é exatamente a tabela `produtos` já importada, só que entrando filtrado por `?categoria=`. O `TabelaPrecoController` já tem filtro de categoria, então não foi criada página nova pra isso.
- **Facas: 8 páginas → 1.** O legado tinha 8 arquivos PHP quase idênticos (~270 linhas cada), um por catálogo, cada um lendo um JSON de `assets/data/`. No v2 é **uma** página com filtro de catálogo, alimentada por duas tabelas: `facas` (tipo, item, largura, altura, observacao) e `faca_recursos` (descricao + imagem, normalizado — mesmo tratamento que `orcamento_itens` recebeu). 127 facas, 269 recursos, 8 tipos.
- Dados versionados no repo (`database/data/facas/*.json`, copiados do legado) + imagens em `public/images/facas/<tipo>/` (88 arquivos). Seeder `FacaSeeder` (roda pelo `DatabaseSeeder`, não depende de `.env` nem do legado no disco). `largura`/`altura` são **string** de propósito: o catálogo real tem `"0/160"` (largura móvel) e `"Ø 40"` (faca redonda).
- **Bug do legado corrigido, não replicado**: a view do legado casava `detalhes[i]` com `icones[i]` posicionalmente, mas em **74 dos 127 registros** os dois arrays têm tamanhos diferentes — então a legenda exibida não correspondia à imagem. A causa principal eram linhas `"Observação: ..."` misturadas dentro de `detalhes`. O `FacaSeeder` resolve isso **uma vez, na importação**: extrai as observações pra `facas.observacao` (o que realinha 25 dos 27 itens de balança), pareia detalhe↔imagem quando as contagens batem (balança/especiais/lacres: cada imagem É um recorte nomeado) e, quando não batem, trata a imagem como ilustração da faca e os detalhes como atributos só-texto (tags/rótulos/A4/bag tag). Ex.: item 1 da balança, que no legado aparecia com a observação de picote como legenda da imagem, agora tem legenda derivada do recorte e a observação em campo próprio.
- Sem exportação Excel nesta página (é grid de cards, não tabela).
- **CRUD pela tela, admin-only** (2026-08-10, pedido do Tony — o pessoal de artes está produzindo facas novas). Mesmo padrão do `EtiquetaMateriaPrimaController`: `abort_unless(role === 'admin')` em todo método de escrita, checado no controller (não em middleware de rota). Admin vê "+ Nova faca" no PageHero e botões de editar/excluir por card (mesma linguagem visual da Regra de ouro nº 5, mas **`h-7 w-7` de propósito** — é grid de cards, não tabela, então não usa o token `.tbl-acao`/`h-6 w-6`, que existe pra economizar altura de linha); os outros perfis veem a página exatamente como antes. Modal `Components/Catalogo/FacaFormModal.vue`: dá pra escolher as imagens **já no cadastro**. Como o endpoint de upload precisa do id da faca, elas ficam numa fila local (com prévia via `URL.createObjectURL`) e sobem em sequência logo após o `store` — pro usuário é uma operação só. A primeira versão exigia salvar antes de aparecer o campo de imagem, o que o Tony apontou como incômodo na hora de usar.
- **Como a tela descobre o id da faca nova**: `store()` devolve `back()->with('recursoCriadoId', $faca->id)` e o `HandleInertiaRequests` compartilha isso em `flash`. Não procurar a faca recém-criada dentro da lista `facas` — ela pode estar fora do filtro/busca ativos e simplesmente não aparecer lá (foi a primeira tentativa, descartada por isso). Se mesmo assim o registro novo não estiver na listagem, a página limpa os filtros pra ele ficar visível.
- **⚠️ Upload vai pra `storage/app/public/facas/` (servido como `/storage/facas/...`), NÃO pra `public/images/facas/`.** As imagens de `public/images/facas/` vieram do legado e são versionadas no git; num deploy Forge o diretório do código é recriado a cada release, então arquivo enviado pela tela que fosse parar lá sumiria na próxima subida. Por isso a coluna `faca_recursos.imagem` guarda o **caminho web completo sem a barra inicial** (`images/facas/balanca/X.png` ou `storage/facas/<uuid>.png`) e o controller só prefixa `/` — os dois casos ficam uniformes. Excluir uma faca só apaga do disco o que começa com `storage/facas/`; imagem do legado é do repositório, não se mexe. **Precisa de `php artisan storage:link`** (rodado em 2026-08-10; refazer se o `public/storage` sumir).
- Extensão do arquivo enviado vem do **MIME detectado no servidor**, nunca do nome do cliente (mesmo cuidado do `ProfileController::updateFoto`, que já teve essa correção de segurança) — nome é UUID, então upload não sobrescreve nada nem controla o caminho.
- **⚠️ O `FacaSeeder` agora só popula banco vazio.** Antes ele apagava tudo e reimportava; com CRUD na tela isso passaria por cima do que o admin cadastrou à mão, e o `db:seed` roda inteiro em toda restauração. Pra forçar reimportação dos JSONs originais: limpar `facas` + `faca_recursos` antes de rodar.
- Testado: `tests/Feature/CatalogoFacaCrudTest.php` (8 testes — cadastro, item duplicado por catálogo, upload indo pro storage, recurso exigindo descrição ou imagem, arquivo não-imagem recusado, exclusão apagando só a imagem enviada e preservando a versionada, 403 pra não-admin, `podeGerenciar` por perfil).
- Testado: `tests/Feature/CatalogoFacaTest.php` (6 testes — listagem, filtro por tipo, tipo inválido, busca por medida e por recorte, URL pública da imagem, exige login) + conferência de que os 88 arquivos de imagem referenciados existem em disco.
- **`UserFactory` estava quebrada** e foi corrigida junto (não preenchia `username`/`display_name`/`is_active`, obrigatórios desde o redesenho da tabela `users`) — qualquer teste que criasse usuário morria com "Field 'username' doesn't have a default value". ~~Os 7 testes que ainda falham em `php artisan test` são do scaffold do Breeze.~~ **Resolvidos em 2026-08-27 — a suíte está 100% verde (104 testes).** Eles testavam features que o projeto removeu de propósito, então foram **invertidos em vez de deletados**: agora protegem a decisão. `RegistrationTest` afirma que `/register` não existe e que ninguém cria conta pela web (se um `breeze:install` refeito reativar a rota, a suíte acusa — cenário perigoso num sistema comercial); `ProfileTest` usa `display_name` (o `name` é o nome legal do TOTVS, e há teste provando que o formulário não o altera) e afirma que a auto-exclusão de conta continua fora; o `ExampleTest` virou `InicioTest`, cobrindo o `InicioController`. **Suíte verde é pré-requisito, não luxo**: com falhas crônicas ninguém repara numa regressão de verdade.

### Varredura profunda do legado + PDF de bobina — 2026-08-10
Segunda passada de gap analysis, agora no que está **escondido dentro** das páginas (87 endpoints em `includes/ajax/`, 31 em `includes/api/`, 24 em `includes/crud/`, mais `management/`/`reports/`/`pdf/`). Matriz completa em **`docs/gap-legado.md`** — consultar de lá antes de reabrir qualquer discussão de escopo.

Técnica que se mostrou útil e vale repetir: **contar linhas da tabela no espelho `autopel01_homolog` antes de decidir portar.** Foi o que separou feature viva de código morto — `RESPOSTAS_LIGACAO` tem 19.188 linhas (muito usada), enquanto `observacoes_categorias` tem 0 e `MARKETING_WP_LEADS_RAW` nem existia no espelho do v1 (o webhook do site nunca ligou lá; no v2 a staging é `marketing_wp_leads_raw`). Tamanho de código não diz nada; volume de dado diz.

**Decisões do Tony nesta data** (não reabrir): ligação é **só contagem de chamadas**, sem roteiro de perguntas; observação continua **mão única** (sem resposta/exclusão/categorias); **transferência de leads não entra**; PDF de bobina **entra e deve ficar igual ao legado**. Em aberto: edição de lead e simular usuário (análise em `docs/gap-legado.md`).

**KPI fantasma removido.** `ligacoes` tinha `perguntas_respondidas_count`/`perguntas_obrigatorias_count` e o `DashboardController` calculava uma média de "perguntas respondidas" em cima delas — **só o `LigacaoSeeder` escrevia nessas colunas**, então com dado real daria 0% pra sempre; e o valor nem chegava ao front (`mediaRespostas` não era consumido por nenhum componente). Colunas dropadas (migration `2026_08_10_140000`), model/seeder limpos, e o `ligacoesStats()` passou a agregar no banco (`COUNT`/`SUM`) em vez de dar `get()` em todas as ligações do mês só pra contar 3 coisas.

**PDF da solicitação de bobina** (`GET /cadastros/bobinas/{bobina}/pdf`, botão-ícone na tabela). O legado gerava PDF (`includes/pdf/solicitacao_bobina_pdf.php`) e o v2 só mandava `mailto:`.
- Template `resources/views/solicitacoes/bobina-pdf.blade.php` (dompdf, que já estava no projeto pro orçamento) replicando o layout FPDF do legado: header navy `#101521` com faixa verde, selo de status colorido, título em destaque, duas colunas, grade de características, bloco de observações, rodapé.
- **⚠️ As cores são as do legado (navy + verde `#2E7D32`), não a paleta oficial Autopel** — pedido explícito do Tony ("igualzinho o legado"). Isso deixa o PDF de bobina e o de orçamento propositalmente diferentes entre si; se um dia unificar, é decisão de marca.
- `App\Services\Solicitacoes\BobinaPdfPresenter` concentra os rótulos, **cópia fiel dos `mapear*()` do legado** — inclusive os NCMs no rótulo de NF ("Venda – NCM 48119010"), que é o que o time de Cadastro usa pra abrir o item. Se mudar lá, mudar aqui.
- Escopo igual ao da listagem: quem não é gestor só baixa a própria ficha.
- Testado: `tests/Feature/SolicitacaoBobinaPdfTest.php` (7 testes — PDF binário válido, **contagem de páginas**, rótulos do legado, campo vazio virando `-`, cor/rótulo do selo por status, 403 pra ficha de outro vendedor, gestor baixando qualquer uma).
- **⚠️ Gotcha do dompdf que custou uma versão inteira: nunca usar `float` dentro de elemento `position: fixed`.** O rodapé da primeira versão tinha dois `<span>` flutuantes (`float: left` / `float: right`) e o dompdf perdia a conta da altura da página — o PDF saía com **11 páginas, todas em branco menos o header**, com o conteúdo inteiro sumido. O arquivo continuava sendo um PDF válido de ~880 KB, então a asserção de assinatura `%PDF-` passava numa boa. Alinhar por tabela resolve. Confirmado por bisecção (reintroduzir o float reproduz as 11 páginas), depois de uma hipótese errada de que a causa era `<main>` sem `display: block` — que não era.
- **Lição de teste, não só de CSS**: "gera um PDF válido" não prova nada sobre layout. O teste que presta é contar `/Type /Page` no binário e checar que o conteúdo tem volume. Vale pra qualquer PDF novo aqui (inclusive o de orçamento, que hoje não tem essa checagem). Pra inspecionar visualmente: `apk add poppler-utils && pdftoppm -png -r 90 arquivo.pdf saida` dentro do container `app`, e abrir o PNG.

### Simulação de usuário ("ver como X") — construído em 2026-08-10
Admin entra na pele de outro usuário pra reproduzir o que ele vê. No legado isso existia mas "só pegava na primeira página" — a causa, medida no código: identidade era lida de `$_SESSION['usuario']` em **257 arquivos** e só **7** sabiam que a simulação existia.

**Por que aqui funciona em toda página, sem esforço por página:** o v2 tem uma fonte única de identidade (o guard do Laravel). `Auth::login($alvo)` faz os ~89 pontos que chamam `$request->user()` e todos os resolvers de escopo (`DashboardScopeResolver`, `EquipeScopeResolver`, `MetaRankingResolver`, `CarteiraAderenciaResolver`) enxergarem o alvo automaticamente. Nenhuma tela precisa saber que a simulação existe — inclusive filtros de query string continuam funcionando normalmente, porque o escopo deriva do usuário, não da URL.

- **`SimulacaoController`** — `iniciar` (admin-only) e `encerrar`. Chaves de sessão `simulacao_admin_id`/`_nome`/`_log_id`.
- **⚠️ Custo por request: ZERO query.** O banner é alimentado por prop compartilhada no `HandleInertiaRequests` que lê **só da sessão** — por isso o nome do admin é gravado na sessão no início, em vez de resolvido por `User::find()` a cada request. Não existe middleware novo no caminho. Medido em teste (`test_simulacao_nao_adiciona_query_por_request`): 7 queries sem simulação, 6 com. Se algum dia alguém trocar isso por uma consulta ao banco, o teste quebra.
- **Cache do Dashboard não vaza** — as chaves (`dashboard:*`) derivam do **escopo resolvido** (`codVendedores`/hash de `usuarioIds`), não do id do logado. Simular um vendedor cai na mesma chave que o próprio vendedor usaria: sem vazamento de dado do admin e ainda reaproveitando cache. Se alguém mudar essas chaves pra incluir o usuário logado, vira bug de dado errado na simulação.
- **Regras**: não simula a si mesmo, nem outro admin, nem usuário inativo; não aninha (encerre antes de trocar de alvo). **`encerrar` NÃO exige perfil admin** — quem está autenticado durante a simulação é o alvo (normalmente vendedor); a autorização é "existe simulação nesta sessão". Se admin sumir/for desativado no meio, a sessão é derrubada em vez de deixar alguém preso na pele de outro.
- **Auditoria**: tabela `simulacoes_usuario` (admin, alvo, ip, início, fim) — escrita só nas duas transições, nunca por request. O legado não registrava nada.
- **Rota**: `POST /simulacao/encerrar` tem que ficar declarada **antes** de `POST /simulacao/{usuario}`, senão o model binding tenta achar um usuário chamado "encerrar" e dá 404.
- Entrada pela tela **Equipe** (botão de olho por linha, só admin e só pra alvo válido). Banner âmbar fixo no topo do `AuthenticatedLayout` com "Encerrar simulação".
- Testado: `tests/Feature/SimulacaoUsuarioTest.php` (11 testes — inclusive persistência em 8 páginas diferentes com filtros na query string, banner presente/ausente, o simulado conseguindo encerrar, auditoria, e o teste de custo zero de query).

### Grupo de cliente + densidade das tabelas — 2026-08-10
Rodada de acabamento visual, com uma feature de dado junto.

**Grupo de cliente (resolve o item "revisitar `GrpVendas`" que estava em aberto).** A Carteira passou a mostrar a coluna **Grupo** (descrição, não código) e perdeu a coluna **Aderência** (decisão do Tony — a aderência continua existindo como filtro e no card de KPIs do topo, só saiu da tabela).
- Fonte: **`CLIENTES.GrpVendas`** do TOTVS. O código está em `CLIENTES`, mas **a descrição só existe em `ultimo_faturamento.Descricao`** — exatamente a mesma separação de `Segmento1`/`Descricao1`, então o desenho copiou o de `segmentos`: `clientes.cod_grupo` guarda o código e `grupos_cliente` (`codigo` + `nome`) é o lookup. Nunca gravar o nome em `clientes` — foi o mismatch nome×código que quebrou a aderência silenciosamente em julho.
- **Não confundir com `GRUPOS_CLIENTES`/`CLIENTES_GRUPOS` do legado.** São outra feature (agrupamento manual chaveado por `raiz_cnpj`, proibido pela Regra de ouro nº 3), com 5 grupos e 17 vínculos, e continuam fora de escopo. O CLAUDE.md antigo dizia que "grupo de cliente" tinha 0 uso real — isso valia pra *aquelas* tabelas, não pro `GrpVendas`, que tem 2.429 grupos e cobre 100% da base.
- Populado dentro do **próprio `legado:import-clientes`** (não é comando separado, de propósito: comando à parte deixaria importar cliente com grupo que ainda não existe no lookup). Conferido no espelho antes de modelar: nenhum código tem duas descrições, então o mapa é 1:1.
- Números depois do import real: 2.429 grupos, **0 clientes sem grupo**, e 27 clientes cujo código não tem descrição em lugar nenhum do TOTVS — esses caem no fallback pro código bruto, mesmo tratamento dos ~331 segmentos órfãos. `9998 = CLIENTES DIVERSOS` é o balde de "sem grupo real" e sozinho concentra ~30% da base.
- `ClienteSeeder` gera `cod_grupo` (código, não nome) a partir de 8 grupos fake, com o mesmo peso de ~30% no 9998 — pra o seed não sugerir que todo cliente tem grupo próprio.
- Coluna **Grupo** também entrou no export Excel da Carteira. **Aderência continua no Excel** (só saiu da tela) — se incomodar, é só remover de `CarteiraExport::headings()`/`map()`.

**Densidade.** Linha da Carteira caiu de ~56px pra ~40px (-29%) via os tokens da Regra de ouro nº 5. O nome do cliente era `font-semibold` e ficou pesado demais na tela cheia — `.tbl-main` usa `font-medium`.

### Ordenação por clique no header — 2026-08-10
`Components/Tabela/SortableTh.vue` (genérico, pra reusar nas outras tabelas) + whitelist `CarteiraController::ORDENACOES`. O parâmetro é `ordenar=<campo>_<asc|desc>`; os valores antigos `nome_asc`/`ultima_compra_desc`/`ultima_compra_asc` continuam válidos, então link salvo não quebra. O select "Ordenar por" do `PageHero` saiu (virou UI duplicada).

**A whitelist é segurança, não organização.** O campo vem da query string e vira ORDER BY. Nunca passar o valor cru pro `orderBy()`. Coberto por `tests/Feature/CarteiraOrdenacaoTest.php` (8 testes, incluindo tentativa de injeção verificando o SQL gerado).

**Achados de performance (Regra de ouro nº 6 aplicada — tudo medido com os 91.293 clientes reais):**
- **A tela já era lenta antes de existir ordenação por clique.** `ORDER BY razao_social LIMIT 30` sem índice dava `type: ALL` + `Using filesort` e **120ms** por carregamento, no escopo admin. Com índice em `razao_social`: **0,67ms** (180x). Migration `2026_08_10_200000` adiciona índice em `razao_social`, `data_ultima_compra`, `estado` e `cod_segmento` (os três últimos servem também aos filtros, que também eram full scan).
- **Aqui índice funciona, diferente do caso da Regra nº 6.** O que decide é o `LIMIT 30`: o MySQL lê o índice em ordem e para na 30ª linha. Não depende da seletividade da coluna — `estado` tem 27 valores distintos e mesmo assim caiu de 69ms pra 0,48ms.
- **⚠️ `ORDER BY data_ultima_compra IS NULL, data_ultima_compra DESC` (como era escrito) ignora o índice.** `IS NULL` é uma expressão, e expressão como primeira chave de ordenação não usa índice: 80ms mesmo com o índice criado, contra **0,34ms** sem ela. E é desnecessário no DESC — NULL é o menor valor, então "nunca comprou" já cai no fim sozinho. No ASC os NULLs passaram a vir primeiro (mudança de comportamento assumida: quem nunca comprou é o mais frio). MySQL 8 não tem `NULLS LAST`.
- **⚠️ O desempate por `id` tem que seguir a direção da coluna.** `orderBy(coluna, 'desc')->orderBy('id')` (id sempre ASC) mistura sentidos, nenhum índice atende, e volta o filesort — 80ms contra 0,39ms. O desempate existe pra paginação não repetir/pular registro quando há muitos valores iguais.
- **Ordenar por nome de grupo/segmento é caro e não tem conserto barato**, porque o nome mora em outra tabela e o LEFT JOIN força filesort. Medido pelo caminho real do controller: **vendedor (283 clientes) 6-9ms; supervisor (8.576) 106ms; admin sem filtro (91.293) 510ms no grupo e 655ms no segmento.** Aceito porque (a) é ação explícita, não custo de carregamento, (b) o perfil que domina o uso diário nem sente, (c) a tela ficou muito mais rápida no geral. **Se incomodar, o próximo passo já medido é o "deferred id": ordenar só `id` num subselect e buscar as linhas depois — leva 501ms pra 135ms.** Não foi feito porque não compõe bem com `paginate()`.
- **O `COUNT` da paginação não paga o join de ordenação**: `index()` passa `total:` calculado por `filtradaQuery()` (sem o join). Vale porque o join é num campo `unique`, logo 1:1, e não muda a contagem — travado por teste. **Se um dia entrar aqui um join que possa multiplicar linha, esse atalho passa a dar total errado.**

**`ModalPadrao.vue` é a casca padrão de modal do sistema** (criada em 2026-08-10). Header preto com ícone/título/subtítulo + botão de fechar, corpo, e slot `#footer` opcional pras ações. Todo modal novo usa ele; `Modal.vue` direto só quando não quiser header preto. Props: `titulo` (obrigatório), `subtitulo`, `maxWidth`, `closeable`.
- **Por que é componente e não classe no `app.css`:** aqui o que se repete é *marcação* (header, X, estrutura de 3 blocos), não um punhado de classes — ver o critério na Regra de ouro nº 8.
- Ação no `#footer` que precisa submeter um `<form>` do corpo: usar `form="id-do-form"` no botão (é o que o `ObservacoesModal` faz), senão o submit não alcança o form de outro slot.
- Os modais de FORMULÁRIO que já existiam (`MotivoInatividadeModal`, `AgendarLigacaoModal`, `EditarUsuarioModal`, `NovoUsuarioModal`, `TrocarSenhaModal`, `SupervisorMassaModal`, `MateriaPrimaFormModal`, `FacaFormModal`, `RejeitarOrcamentoModal`, `ItemTipoModal`, `CadastroDetalhesModal`) **ainda não foram migrados** — continuam com `Modal.vue` direto e cabeçalho próprio. Migrar quando encostar em cada um. Os de CONFIRMAÇÃO já foram (ver abaixo).

### 🚫 `confirm()` do navegador não existe mais neste projeto — 2026-09-10

**Nenhuma tela chama `window.confirm()`/`alert()`/`prompt()`.** Eram 9 pontos (excluir lead,
excluir solicitação de cadastro, excluir faca, remover recorte da faca, excluir
matéria-prima, remover foto de perfil, simular usuário, reimportar tudo e transformar
orçamento em pedido no Portal) e todos passaram a usar a mesma casca.

**Por que não é só estética:** o diálogo nativo muda de cara em cada navegador, não sabe
distinguir "excluir" de "só conferir" — e no Chrome ele oferece *"não deixar este site
abrir mais caixas de diálogo"*. Marcada, `confirm()` passa a devolver `false` **sem
perguntar nada**: a ação some em silêncio e o usuário jura que clicou.

| O que se repete | Onde mora |
|---|---|
| A casca visual da confirmação (header preto, ícone, botões) | `Components/ConfirmacaoModal.vue` |
| O `await` que mantém o call-site parecido com o antigo | `composables/useConfirmacao.js` |
| Empilhamento de modal aberto dentro de modal | `Components/Modal.vue` (const `pilha`) |

O call-site fica assim, e o template leva **uma linha**:

```js
const { confirmacao, confirmar, aoConfirmar, aoCancelar } = useConfirmacao();
if (! await confirmar({ titulo: 'Excluir lead', mensagem: '…', tom: 'danger' })) return;
```
```html
<ConfirmacaoModal v-bind="confirmacao" @confirmar="aoConfirmar" @close="aoCancelar" />
```

- **O tom é a única decisão de cor**: `danger` (destrói dado) · `atencao` (irreversível ou
  pesado, mas não destrutivo — ação para fora do CRM, reimportação em massa, simular
  usuário) · `neutro` (só um "tem certeza?").
- **`mensagem` diz o que vai acontecer; `detalhe` diz a consequência**, no bloco tingido. É
  o que o `confirm()` nativo empurrava para dentro de um `\n\n` que ninguém lia.
- **Quando NÃO usar o composable**: se a confirmação tem `useForm` e o servidor pode
  recusar com erro de validação, ela vira componente próprio usando o `ConfirmacaoModal`
  direto (é o caso de `ExcluirOrcamentoModal` e `ExcluirUsuarioModal`, que eram dois
  modais brancos escritos à mão e hoje só passam props).
- ⚠️ **A `pilha` do `Modal.vue` fica num `<script>` normal, NÃO no `<script setup>`.**
  Aquele bloco é o corpo do `setup()` e roda uma vez por instância — a pilha nasceria vazia
  em cada modal e todos se achariam o do topo. Foi exatamente esse o primeiro erro aqui, e
  o sintoma era o ESC fechar a confirmação E o formulário por baixo dela, levando junto o
  que estava sendo editado. Sem a pilha, fechar o de cima também destravava o scroll da
  página com o de baixo ainda aberto.
- Os três modais de aviso do `ExportarExcelButton` também deixaram de ser marcação
  própria: o de "filtros ativos" virou `ConfirmacaoModal` e os dois informativos ganharam o
  header preto do `ModalPadrao`.

**Modal de observações unificado.** Existiam dois componentes com a mesma função e layouts divergentes (`Carteira/ObservacaoModal.vue` com histórico em cima, `Leads/ObservacaoLeadModal.vue` com histórico embaixo). Os dois foram apagados e substituídos por **`Components/Observacoes/ObservacoesModal.vue`**, que recebe `subtitulo`, `historicoUrl` e `payload` — header preto no padrão `DarkCard`, histórico em lista com borda cyan à esquerda, form embaixo. Reusar esse em qualquer página nova que precise de observação. (O form livre de observação do Home, em `Dashboard/LigacoesStatsCards.vue`, é um card inline e **não** usa esse modal — continua como estava.)

**⚠️ `Modal.vue` passou a centralizar verticalmente.** Vale pra **todos** os modais do sistema, não só o de observações: o scaffold do Breeze alinhava tudo no topo (`py-6` + `mb-6`). Agora o scroll fica no container de fora e um `flex min-h-full items-center` no de dentro (padrão do Headless UI). **Não "simplificar" pondo `flex` direto no container que rola** — modal mais alto que a viewport tem o topo cortado e fica inalcançável. O wrapper do flex é `pointer-events-none` e o painel `pointer-events-auto` de propósito: sem isso o clique no vazio bate no wrapper em vez do backdrop e o "clicar fora pra fechar" para de funcionar. Também trocou `rounded-lg` por `rounded` (Design System) e o backdrop cinza do Breeze por `bg-corp-black/60`.

### Itens de pedido faturado sumindo no import — corrigido em 2026-08-10
Sintoma: na ficha do cliente (`/carteira/{id}/detalhes`) e em `/pedidos-emitidos`, todo pedido **faturado** aparecia com "Itens = 0" e a linha expansível abria vazia. Pedido **em aberto** mostrava itens normalmente. Atingia **7.493 dos 10.245 clientes com pedido (73%)**, que são os que só têm pedido faturado.

**Causa: `memory_limit` de 512M estourado dentro do `ImportPedidosLegado`**, montando `$todosItens` antes de fatiar com `array_chunk()`. Os 161.114 itens de `META_VENDA` ficavam em memória **três vezes ao mesmo tempo** (`fetchAll` + `$itensPorNumero` + a lista final). A rodada de pedidos em aberto (25 mil itens) sempre coube — daí o sintoma parecer específico de "faturado", em vez de um import quebrado.

**Regra de ouro nº 6 outra vez**: o import passava com 9.154 faturados e passou a morrer com 12.006. E a falha **não era silenciosa** — dava `Allowed memory size exhausted` e saía com erro —, mas ficou despercebida porque estava no meio da sequência de restauração do incidente da manhã, e as tabelas ficavam com os cabeçalhos certos e só os itens faltando.

**Correção** (`processarGrupos`): insere em lotes de 1.000 enquanto percorre, em vez de materializar a lista inteira; dá `unset` no resultado do `fetchAll` assim que o agrupamento termina; e libera cada grupo depois de gravado. ⚠️ O laço percorre `array_keys($itensPorNumero)`, **não** o array direto — dar `unset` na coleção sendo iterada por valor faz o PHP separar/copiar o array e dobra o consumo exatamente onde se quer aliviar. Se voltar a apertar, o próximo passo é trocar `fetchAll()` pelo cursor do PDO.

**Depois da correção**: 15.523 pedidos e **186.497 itens** (161.114 faturados + 25.383 abertos), **zero** pedidos sem item.

**Lição de diagnóstico**: cheguei a descartar memória como causa porque simulei o agrupamento em memória e coube. A simulação montava só duas das três cópias — faltava justamente a que estoura. Simulação que não reproduz o passo suspeito não é evidência sobre ele.

**Linha expansível na ficha do cliente (mesma data).** `/carteira/{id}/detalhes` passou a abrir os itens ao clicar na linha do pedido, igual a `/pedidos-abertos` e `/pedidos-emitidos` — reusando os tokens `.tbl-itens*`. `detalhes()` carrega os itens com `with('itens:...')` (eager load, **uma** query a mais pra página inteira, não uma por pedido): medido em 11 queries pra 20 pedidos e 161 itens. São 20 pedidos por página e o cliente com mais pedidos da base tem 32, então não vale paginar item.
- **Bug corrigido junto**: `Detalhes.vue` tinha uma **cópia local** de `ROTULOS_STATUS_PEDIDO`/`TONS_STATUS_PEDIDO`, e a cópia não tinha `pendente_totvs` — todo pedido em aberto exibia a string crua `pendente_totvs` na coluna Status em vez de "Aguardando classificação do TOTVS". Agora importa de `constants/pedidos.js`. Regra de ouro nº 8 de novo, e o tipo de divergência que só aparece quando um valor novo entra no enum.

### Título TOTVS de bobina/etiqueta — corrigido em 2026-08-11
As regras de nome das solicitações de cadastro tinham sido portadas errado. **Isso importa mais que uma diferença cosmética: o time de Cadastro copia esse texto literalmente pra abrir o item no TOTVS**, então título divergente vira item cadastrado errado. Fontes do legado: `includes/solicitacoes/bobina_titulo.php` e `gerar_titulo_padronizado_etiqueta()` em `includes/ajax/solicitacoes_etiquetas.php`.

**Bobina — faltava a regra da gramatura.** O tipo de papel no título sai da **gramatura**, não do campo `papel`; o `papel` é só fallback pra gramatura que não mapeia. Mapa: `44 → TS KPH BC`, `48 → TERMICO`, `55 → TERMOSCRIPT`. O v2 validava e gravava `gramatura` mas nunca a passava pro resolver, então `44 + termicco` saía `BOBINA TERMICO ...` em vez de `BOBINA TS KPH BC ...`. As gramaturas 72/105/167 (que só o v2 oferece) não mapeiam e caem no papel — comportamento do próprio legado, não precisou de decisão nova.

**Etiqueta — a regra era outra inteira.** Formato certo: `ETIQUETA <descrição das medidas> <DIMENSÃOxMETRAGEM M> <nomenclatura> - <TIPO ADESIVO>`. Quatro erros de uma vez: (1) `medidas` levava `preg_replace('/\s+/','')`, colando tudo (`40X40 SEGURANÇA LATERAIS` → `40X40SEGURANÇALATERAIS`) — o certo é separar dimensão de descrição por regex e **inverter a ordem**; (2) `metragem` não entrava no título (o campo existia no form e era ignorado); (3) tipo de adesivo ficava no meio, sendo que é **sufixo** separado por `" - "`; (4) `saida_rolo` entrava no título — e como o controller forçava default `'f1'`, **toda** etiqueta saía com um `F1` espúrio. O legado é explícito: *"Título TOTVS sem saída de rolo (F1–F4 fica na arte)"*. O campo continua sendo coletado e gravado no v2, só não compõe o nome.

- Tudo mora em `App\Services\Cadastros\SolicitacaoTituloResolver` (Regra de ouro nº 8 — o front **não** tem cópia da regra, e não deve ganhar uma).
- **Como as regras foram validadas**: script que dá `require` no `bobina_titulo.php` **original do legado** e roda os dois lado a lado sobre os mesmos casos. Os valores esperados dos testes vieram dessa execução, não foram escritos à mão. Vale repetir a técnica pra qualquer outra regra portada — ler o código dos dois e comparar "no olho" foi o que deixou passar esses 4 erros na primeira vez.
- Testes: `tests/Unit/SolicitacaoTituloResolverTest.php` (12 casos, sem banco) trava as regras; `tests/Feature/SolicitacaoTituloTest.php` (4) trava a **ligação controller→resolver**, que é onde o bug morava de fato — o resolver antigo estava certo pro que recebia, o controller é que passava o argumento errado.
- **Diferença estrutural que sobrou de propósito**: o legado recalcula o título da bobina a cada leitura (`bobina_titulo_da_solicitacao()`, usado na listagem/e-mail/PDF); a etiqueta ele grava. O v2 grava os dois no `store` e não recalcula — é o certo, mas significa que **correção de regra não alcança registro antigo sozinha**. Daí `php artisan cadastros:recalcular-titulos` (com `--dry-run`). Rodar depois de qualquer mudança no resolver.
- **Form**: escolher a gramatura sugere o papel correspondente, igual ao legado — mas atenção, esse mapa é **outro** (`44 → kpr`, matéria-prima) e não o do título (`44 → TS KPH BC`, nome no TOTVS). Ligado ao `@change` do select, **não** a um `watch`: watch dispararia no prefill e sobrescreveria o papel que veio salvo.

### E-mail transacional de Cadastros — 2026-08-28
Tony recebeu credenciais de um SMTP relay dedicado (**smtplw.com.br**, usuário `autopel`, remetente `no-reply.crm@solucoes.autopel.com`, porta 587) e pediu pra trocar o `mailto:` de Cadastros (bobina/etiqueta/cliente) por envio real. `.env`: `MAIL_MAILER=smtp` + host/porta/usuário/senha/from — não é AWS SES (o que estava planejado antes de existir essa credencial).

- **`App\Mail\CadastroSolicitacaoMail`** (Mailable único, reusado pelos 3 fluxos — Regra de ouro nº 8) substitui os 3 builders `mailtoBobina`/`mailtoEtiqueta`/`mailtoCliente` do `CadastroController`, que continuam existindo (montam to/cc/subject/body) mas agora alimentam `Mail::queue()` em vez de virar link `mailto:` pro front. `MailtoPanel.vue` foi apagado; o front mostra só um banner verde de confirmação (`flashEnvio`, chave nova no lugar de `flashMailto`).
- **`ShouldQueue` de propósito**: SMTP externo não pode segurar a resposta do POST (Regra de ouro nº 9, orçamento de 500ms pra escrita). Vai pra fila Redis, processada pelo worker `queue` já existente.
- **Bobina leva a ficha em PDF anexada** (mesma `BobinaPdfPresenter`/view do `pdfBobina()`); etiqueta e cliente são só texto.
- **⚠️ Gotcha real, não hipotético: anexo binário cru quebra a serialização da fila.** Passar os bytes do PDF direto como propriedade do Mailable faz o `queue:work` falhar com `InvalidPayloadException: Unable to JSON encode payload... Malformed UTF-8 characters` — o payload da fila é JSON, e PDF não é UTF-8 válido. Fix: `base64_encode()` no construtor, `base64_decode()` só dentro de `attachments()`. Reproduzido e confirmado corrigido via `tinker` antes de considerar pronto (ver também a lição de teste do PDF de bobina, seção acima — esse bug só aparece com o PDF de verdade, não com uma string curta de teste).
- **⚠️ Editar `.env` não basta pro worker já rodando.** `queue:work` é processo de vida longa; ele lê env no boot e mantém em memória. Depois de trocar `MAIL_*` no `.env`, é preciso `docker compose restart queue` (e `app`, por segurança) — sem isso o worker continua tentando `127.0.0.1:2525` (default antigo) e todo e-mail cai em `failed_jobs`.
- **Testes não disparam e-mail real**: `phpunit.xml` já forçava `MAIL_MAILER=array` e `QUEUE_CONNECTION=sync` (chumbado desde antes, sem precisar de mudança agora) — suíte inteira roda sem tocar o SMTP real nem a fila Redis compartilhada com o dev.
- Sem teste automatizado novo pra esse fluxo especificamente (validado manualmente via `tinker`, ponta a ponta, com o PDF real). Se for mexer de novo aqui, vale um `Mail::fake()` em feature test.

**🔓 Destravado em 2026-08-28 — `CadastroController::EMAILS` aponta pros e-mails reais.** Depois de um teste ponta a ponta ter mandado e-mail real pro `pcp.sp@autopel.com`/`cadastro@autopel.com` de verdade (Tony reagiu: *"Nunca teste nada com os emails de produção ta loco kkk"* — ver [[feedback-never-test-with-production-emails]] na memória), os destinos foram temporariamente redirecionados pra `antonio.barbosa@autopel.com` via uma constante (`EMAIL_DESTINO_TESTE`, já removida) até o Tony confirmar. Confirmado no mesmo dia — a partir de agora, toda solicitação de bobina/etiqueta/cliente vai de verdade pro setor: `pcp.sp@autopel.com` (bobina/etiqueta, cc `cadastro@autopel.com`) e **`cadastro.geral@autopel.com`** (cliente — mudou recentemente, não é mais `cadastro.cliente@autopel.com`). Validado só estaticamente (reflection na constante + suíte de testes, que usa `MAIL_MAILER=array`) — **de propósito, não disparei nenhum e-mail real pra confirmar**, pra não repetir o erro anterior.
  - ⚠️ **Superado em 2026-08-29**: os destinos acima continuam sendo os corretos, mas hoje existe um interruptor que os intercepta — `CADASTROS_REDIRECIONAR_PARA` (ver seção "Beta liberado" abaixo). Enquanto ele tiver valor, NADA chega ao PCP nem ao Cadastro (esvaziado em 2026-08-31, então hoje chega). A constante `EMAIL_DESTINO_TESTE` não existe mais.

**Reformulação do corpo do e-mail + cc do solicitante — mesma data, pedido do Tony depois de ver como o legado formata.** Fonte real: `pages/SISTEMA/cadastro.php::montarCorpoEmailCadastro` (cliente) e `includes/solicitacoes/solicitacao_email_lib.php::montar_corpo_email_bobina/etiqueta` (bobina/etiqueta), ambos no `CRM-AUTOPEL-COMERCIAL`.
- Corpo estruturado em seções `TÍTULO\n======\n` (unificado — o legado tinha duas convenções diferentes entre cliente e bobina/etiqueta, aqui virou uma só). Helpers novos no `CadastroController`: `cabecalhoSecao()`, `linhas()`, `secao()`, `blocoTexto()`, `assinaturaEmail()` — Regra de ouro nº 8, pra não repetir a formatação 3 vezes.
- **Bobina reusa `BobinaPdfPresenter::montar()` pros rótulos** (resumo/comerciais/técnicas) em vez de duplicar mapas — o e-mail e o PDF anexado mostram exatamente os mesmos rótulos/valores, sempre.
- **Não portamos o bloco de "raiz de CNPJ já na carteira"** (matriz/filial, detecção automática por `raiz_cnpj`) do `montarTextoRaizNaCarteiraEmail` do legado — depende da Regra de ouro nº 3 (proibida). O `cadastro_raiz_opcao` (filial/nova_entrega) do formulário continua existindo, só não tem mais aquele bloco especial de aviso no corpo do e-mail.
- **Solicitante sempre em cópia, nos 3 tipos** (pedido novo do Tony, não existia no legado): `enviarEmail()` mescla `$dados['solicitanteEmail']` (vem de `$model->user?->email`, sempre o dono do registro — não o usuário que clicou "enviar", relevante no reenvio via botão "Enviar p/ Cadastro") com o `cc` fixo do setor, deduplicado.
- **Achado ao rodar a suíte depois da mudança**: dois testes de `SolicitacaoBobinaPdfTest` já estavam quebrados antes desta sessão (`Impressão`/`Rebobinamento` removidos do `BobinaPdfPresenter` numa rodada anterior no mesmo dia, substituídos por "Uso obrigatório de tubete" — igual ao legado, `mapearImpressao`/`mapearRebobinamento` são funções mortas lá, nunca renderizadas). Corrigidos os testes pra refletir o comportamento atual (correto), não revertido o presenter.
- **Gap encontrado nesta rodada e resolvido depois**: `tubete_obrigatorio` era validado/gravado/exibido (PDF e e-mail) mas o formulário `CadastroBobinaForm.vue` não tinha o campo, então vinha sempre `null`. O campo entrou (rádio Sim/Não + diâmetro) — confirmado em 2026-09-05.
- **Operacional, não é bug do código**: o worker `queue` morreu sozinho (`ProcessTimedOutException`, timeout de 700s do `queue:listen`) no meio dos testes desta sessão. `docker compose up -d queue` religou e ele retomou o job pendente sozinho. Se voltar a acontecer, vale investigar—não investigado a fundo ainda.

**PDF de etiqueta — mesma data, pedido do Tony ao notar que só a bobina vinha com ficha anexada.** A etiqueta nunca teve PDF no CRM-V2 (só a bobina), mas o legado tem sim (`includes/pdf/solicitacao_etiqueta_pdf.php`), então não é feature nova — é paridade que faltou.
- **`App\Services\Solicitacoes\EtiquetaPdfPresenter`** + `resources/views/solicitacoes/etiqueta-pdf.blade.php`, espelhando `BobinaPdfPresenter`/`bobina-pdf.blade.php` (mesmo template visual — Regra de ouro nº 8, um estilo só). Rota `cadastros.etiquetas.pdf`, botão "Ficha em PDF" na tabela (`CadastroEtiquetaTabela.vue`), e o e-mail de etiqueta passou a anexar o PDF igual ao de bobina (`enviarEmailEtiqueta()`, espelha `enviarEmailBobina()`).
- **Extraído `App\Services\Solicitacoes\SolicitacaoFormatador`** (número/data/sim-não/mapear/status-cor/logo) — antes vivia duplicado dentro do `BobinaPdfPresenter`; virou estático e compartilhado pelos dois presenters. `BobinaPdfPresenter` foi refatorado pra usar isso (comportamento idêntico, testes confirmam).
- **Seção exclusiva da etiqueta: "Saída de rolo"**, com a imagem F1-F4 (`public/images/F{1..4}.png`, já existiam, usadas pelo form) — cópia de `etiqueta_inserirImagemSaidaRolo()` do legado. Como `saida_rolo` sempre tem valor (`storeEtiqueta` defaulta pra `'f1'` se não escolhido), essa seção **sempre aparece** no PDF de etiqueta — diferente da bobina, onde toda seção é mais esparsa.
- **⚠️ Achado real: com "Saída de rolo" + observações longas, o PDF de etiqueta pode virar 2 páginas** (a bobina nunca passa de 1, testado/travado). Isso é overflow de conteúdo legítimo — dompdf pagina certo, nada quebra — mas o footer "Página 1 de 1" **hardcoded** (herdado do template da bobina) ficaria mentiroso nesse caso. Trocado nos dois PDFs (bobina e etiqueta, pra não ter dois padrões — Regra de ouro nº 8) por numeração real via CSS paged media (`counter(page)`).
  - **⚠️ `counter(pages)` (total de páginas) não é confiável neste dompdf 3.x** — testei e voltou "Página 1 de 0". Por isso o rodapé só mostra "Página N" (página atual), sem "de M". Não tentar reintroduzir o total sem validar de novo com uma prova visual (`pdftoppm`), não só contar `/Type /Page` no binário.
- Teste novo: `tests/Feature/SolicitacaoEtiquetaPdfTest.php` (6 casos, espelha `SolicitacaoBobinaPdfTest.php`) — inclusive que a saída de rolo aparece com imagem existente em disco (`assertFileExists`).
- **Validado visualmente, não só "PDF válido"**: renderizado via `pdftoppm` (mesma técnica documentada acima pro PDF de bobina) antes de considerar pronto — "gera PDF válido" não prova layout certo, é a mesma lição de 2026-08-10.

### Beta liberado: senha, alarmes e performance — 2026-08-29

Rodada que fechou o checklist de pré-beta. Detalhe completo em `docs/deploy-aws.md`
(§7.10, §7.11, §8.0 e armadilhas 9.15-9.18); aqui fica só o que muda decisão futura.

**Interruptor operacional `CADASTROS_REDIRECIONAR_PARA` — ✅ ESVAZIADO em 2026-08-31.**
Preenchido, TODA solicitação de bobina/etiqueta/cliente vai só pra esse endereço, sem cc,
com o destino real no prefixo do assunto (`[TESTE → pcp.sp@...]`). Vazio, vai pros setores
de verdade. Mora em `config/cadastros.php` e é lido por `config()`, nunca `env()` — com o
config cacheado em produção, `env()` devolveria null e a proteção sumiria justamente onde
protege. Substituiu a constante `EMAIL_DESTINO_TESTE`, que era editada no código e
revertida na mão.

O aviso que estava aqui — "se o beta abrir com isso preenchido, o time de Cadastro não
recebe nada e ninguém percebe" — **aconteceu**: uma beta tester enviou uma solicitação e
ela caiu só na caixa do Tony. Esvaziado nos dois nós em 2026-08-31; a partir daí bobina e
etiqueta vão pra `pcp.sp@autopel.com` (cc `cadastro@autopel.com`) e cliente pra
`cadastro.geral@autopel.com`, sempre com o solicitante em cópia.
- ⚠️ **Mexer no `.env` não basta, e são TRÊS passos, não um.** `php artisan config:cache`
  (o config está cacheado em produção), `systemctl reload php8.3-fpm` (com
  `opcache.validate_timestamps=0` o FPM não vê o config novo sozinho) e
  `php artisan queue:restart` — este último é o que realmente importa, porque quem envia é
  o worker (`Mail::queue`), processo de vida longa que leu a config no boot. Sem ele o
  redirecionamento continua valendo com o `.env` já corrigido.
- ⚠️ **Validado só estaticamente** (config resolvendo vazia nos dois nós + worker com 19s
  de uptime), de propósito: disparar um teste pra confirmar mandaria e-mail pro PCP e pro
  Cadastro de verdade — ver [[feedback_never_test_with_production_emails]]. A prova de
  fogo é a próxima solicitação real de um beta tester.

**"Esqueci minha senha" funciona.** O Breeze já trazia o fluxo inteiro; faltavam três
coisas: o e-mail saía em inglês assinado "Laravel", o controller vazava quais contas
existem (erro diferente pra e-mail inexistente permite enumerar usuários), e usuário
inativo recebia link. O e-mail virou `App\Mail\RedefinirSenhaMail` com view própria na
identidade da Autopel — o `MailMessage` do Laravel injeta rodapé em inglês e não tem gancho
pra trocar sem publicar as views do framework.
- ⚠️ O logo entra por `$message->embed()` (anexo referenciado por `cid:`). **Nunca trocar
  por `data:` URI** — Gmail e Outlook descartam imagem em base64. O `render()` mostra
  base64, mas isso é só o caminho de preview; a mensagem MIME real sai com `Content-ID`.

**12 alarmes do CloudWatch**, tópico SNS `crm-v2-alertas`, scripts em `infra/monitoramento/`.
- ⚠️ **Os 9 nativos não teriam pego o incidente daquela manhã** (SG sem regra de 8080 pra
  si mesmo travou a fila 6 horas): a AWS via CPU em 0,4%, ALB saudável, zero 5xx. Daí as 3
  métricas customizadas que `metricas:publicar` envia a cada minuto (fila, idade do
  aquecimento, jobs falhados).
- ⚠️ **`treat-missing-data=breaching` nos alarmes da aplicação**, ao contrário dos nativos:
  ali dado ausente significa "não houve erro"; aqui significa que o scheduler parou de
  publicar, ou seja, o detector morreu. Silêncio não pode ser lido como saúde.

**Ordenação por grupo/segmento REMOVIDA** da Carteira (decisão do Tony). O nome mora em
outra tabela, o LEFT JOIN forçava filesort: 596/603 ms contra 106 ms do padrão. Quem
precisa recortar usa o filtro, que compara o código na própria `clientes` (138 ms).

**Teto de paginação: `perf.max_paginas` = 30.**
- ⚠️ A causa **não** é o OFFSET encarecendo aos poucos — supus isso e o `EXPLAIN`
  desmentiu. É **troca de plano** do otimizador, e é penhasco: página 30 usa o índice e lê
  900 linhas (96 ms); página 40 vira `type=ALL` + filesort, lê 90.770 linhas (1.084 ms).
  Sem teto, a última página custava 2,5 s e era alcançável num clique.

**Performance medida em produção sob carga** (40 usuários, 2.217 requisições, zero erros,
48,2 req/s): todo p95 entre 157 e 387 ms, dentro do orçamento de 400 ms da Regra nº 9.
- ⚠️ O teste de carga exige **usuário dedicado**, criado e apagado em volta: o login tem
  rate limit por e-mail+IP (5 tentativas) e 40 workers com a mesma conta estouram na hora.

**⚠️ Lição que se repetiu o dia inteiro: quase todo defeito foi verificação que não
percorria o caminho real.** O pacote do S3 nunca instalado mas marcado ✅ por inspeção; o
teste de escrita usando um prefixo que o código não usa; benchmark medindo resposta 409 por
faltar o header `X-Inertia-Version`; e dois testes de ordenação que passavam por
coincidência e continuaram verdes depois da feature ser removida. Antes de marcar algo como
pronto, perguntar por qual caminho aquilo foi comprovado.

### Badge "online agora" da Equipe nunca contou ninguém — corrigido em 2026-08-31

Reportado pelo Tony sobre produção. Não era erro de contagem: **`users.last_activity_at`
não era escrita por nenhum caminho do sistema**, então a coluna era NULL para os 201
usuários, a badge dizia "0 online agora" com o sistema em pleno uso e o filtro "Presença
→ Online agora" nunca devolvia ninguém. O `EquipeController` (leitura, janela de 5 min)
estava correto desde sempre — faltava a fonte do dado.

**Por que passou despercebido tanto tempo**: o `ImportUsuariosLegado` *parecia* popular a
coluna (havia `'last_activity_at' => $row['ULTIMA_ATIVIDADE']` num `fill()` de primeira
carga), mas ela não está no `$fillable` do `User` — o Eloquent descartava em silêncio.
Duas linhas de código morto que descreviam uma funcionalidade inexistente. Mesmo caso de
`last_login_at` e `email_verified_at` no mesmo `fill()`, ambos igualmente ignorados; as
duas de `last_*` foram removidas (o valor do legado é atividade no sistema *antigo*, e
importá-lo faria a badge mentir logo depois de cada import).

**`App\Http\Middleware\RegistrarAtividade`** (registrado no `web(append:)` do
`bootstrap/app.php`) é o **único** lugar que escreve presença — Regra de ouro nº 8.
- ⚠️ **Escreve em `terminate()`, não em `handle()`**: a resposta já saiu para o cliente
  quando o UPDATE roda (Regra de ouro nº 9). Consequência visível e travada por teste:
  quem abre a Equipe **não** aparece na própria contagem naquele request, só no seguinte
  — a tela recarrega sozinha a cada 45s, então some em segundos. Não é defeito.
- ⚠️ **Trinco `Cache::add("presenca:{id}", …, 60)`**: sem ele seria um UPDATE por página
  aberta por usuário. Com ele, o custo dentro do request é um comando do Redis e o banco
  leva no máximo um UPDATE por usuário por minuto. Chave com TTL, nunca `Cache::forever`.
- ⚠️ **Durante simulação marca o ADMIN, não o alvo.** O guard devolve o alvo, mas quem
  está usando o sistema é o admin — marcar o alvo mostraria "online agora", para a equipe
  inteira, um vendedor que pode estar em casa. Presença segue a pessoa, não o guard.
- Usa `DB::table` e não o model, de propósito: presença não é edição de cadastro, não deve
  mexer em `users.updated_at` nem disparar evento de model.
- **Sair do sistema não zera a coluna**, de propósito: quem desloga para de gerar atividade
  e cai da lista quando a janela de `EquipeController::MINUTOS_ONLINE` (5 min) expira.
- Testado: `tests/Feature/PresencaOnlineTest.php` (6 casos). O primeiro afirma que a coluna
  começa NULL — sem isso, um teste que só olhasse a badge voltaria a passar se a escrita
  sumisse de novo. Suíte inteira verde (160 testes) depois da mudança.
- **Validado pelo caminho real, não só por teste** (lição de 2026-08-29): request de browser
  de verdade contra o app, conferindo a coluna no banco antes (NULL para 201) e depois.

### Carga do histórico de faturamento (2018-2025) — 2026-08-31

O `faturamentos` deixou de ser "só o ano corrente". Foram carregados **oito anos** vindos
dos Excels do TOTVS em `RELATORIOS TOTVS\Legacy`, em dev e em produção:

| | Antes | Depois |
|---|---:|---:|
| Linhas | 1.032.099 | **5.853.279** |
| Período | 2026 | **2018-01-15 → 2026-08-04** |
| Acumulado | R$ 478 mi | **R$ 4,71 bi** |
| Banco | 337 MB | **1,66 GB** |

**Duas peças novas**, e a separação entre elas é deliberada:
- **`scripts/faturamento_xlsx_para_csv.py`** — lê o xlsx em streaming e normaliza para CSV.
  É Python porque o PhpSpreadsheet mantém toda célula como objeto em memória (o mesmo
  motivo do estouro já documentado nos exports); o arquivo de 2025 tem 285 MB.
  ⚠️ **Mapeia por NOME de coluna, nunca por posição** — as abas do 2025 têm ordem
  diferente das dos outros anos, e um mapeamento posicional corromperia 1,1 M de linhas
  em silêncio, porque os tipos ainda "cabem" nas colunas erradas. Absorve também
  cabeçalho em L1/L2/L3, `EMISSAO` ora texto ora datetime, número ora float ora
  texto brasileiro.
- **`legado:import-faturamento-arquivo`** — importa esse CSV. **Nunca trunca**; com
  `--ano` apaga só aquele ano, o que torna a carga repetível sem duplicar.

**⚠️ A ordem entre carga e índice não é detalhe: é 10x.** Recarregar um ano com os quatro
índices presentes levou **10m40s**; com dois, **66s**. Carga histórica sempre antes de
criar índice.

**⚠️ Risco novo, aceito conscientemente pelo Tony (2026-08-31): `legado:import-faturamento`
sem `--desde` faz TRUNCATE e apaga os oito anos.** Até esta carga isso era recuperável — o
espelho tinha tudo que produção tinha. **Agora não**: o espelho só tem 2026, e o histórico
existe apenas no RDS e nos CSVs da máquina do Tony. Foi proposta uma trava no comando e o
Tony optou por não criá-la. Não adicionar sem ele pedir; apenas não rodar esse comando sem
`--desde` contra um banco com histórico.

**Série mista, de propósito:** só 2024 e 2025 vieram dos arquivos "final (com negativos)",
que descontam devoluções (11.026 e 22.232 linhas negativas). De 2018 a 2023 não há nenhuma.
Por isso 2024 (R$ 742,5 mi) aparece **abaixo** de 2023 (R$ 815,3 mi) — parte dessa queda é
artefato, não mercado. O Tony vai gerar os anos antigos com devolução depois; é só rodar de
novo com `--ano`.

**⚠️ Memória do RDS virou o recurso escasso** (ver o aviso na Regra nº 9). Medido em
produção após a carga: `FreeableMemory` caiu de 445 MB para ~90 MB e `SwapUsage` subiu para
320 MB e continua crescendo. Não há degradação hoje — `ReadIOPS` 1,4 e `ReadLatency` 0,5 ms
provam que o working set está em memória, e o p95 do ALB ficou em **143 ms** —, mas a folga
acabou. **Antes de carregar o histórico de pedidos (+380 MB projetados), subir para
`db.t4g.medium`.** Aqui o upgrade compra o recurso certo: a CPU está em 6-16%, é RAM que
falta. (`small` e `medium` têm 2 vCPU as duas — se algum dia o gargalo for CPU, esse
upgrade não resolve nada.)

**Lição de processo que custou um deploy:** a migration `090000` foi editada depois de já
ter rodado em produção. Migration aplicada não roda de novo, então a edição não teve efeito
lá e o banco ficou com quatro índices enquanto o arquivo dizia dois. **Migration já
aplicada nunca se edita — a correção é sempre uma migration nova** (foi a `110000`, feita
idempotente porque dev e produção chegaram nela em estados diferentes).

### Captura de leads do site (WordPress) — endurecida em 2026-09-01

`POST /webhooks/wordpress-leads`. O form do site vira captura crua
(`marketing_wp_leads_raw`) e depois lead comercial (`origem=wordpress`) na
`/leads`. Dono em `marketing_wp_formularios` (fallback `*` = 010617; form novo
= nova linha). Rota em `routes/webhooks.php`, fora do grupo `web`.

**⚠️ O TOKEN VIAJA NA QUERY STRING (`?token=…`) e isso NÃO é para "consertar".**
O plugin de webhook do site tem UM campo: a URL. Não existe onde pôr header.
Header continua aceito (`Authorization: Bearer`, `X-Webhook-Token`) para o dia
em que a origem mudar, mas quem funciona hoje é a URL. Trocar isso por
"header, que é o certo" desliga a integração em produção sem erro nenhum
aparecer — o site simplesmente para de mandar lead e ninguém percebe.
Consequência assumida: o token aparece no access log do nginx e do ALB. O
estrago é limitado de propósito — o endpoint só INSERE, não lê nem apaga.

**⚠️ Staging e lead NÃO compartilham transação, e esse é o ponto do desenho.**
O WordPress dispara uma vez e nunca reenvia. A versão anterior gravava os dois
dentro de um `DB::transaction`: qualquer falha ao criar o lead (campo estourando
tamanho, deadlock, `*` não cadastrado) fazia rollback e levava o envelope junto
— o lead do cliente sumia sem deixar rastro, justamente o que a staging existia
para impedir. Hoje o envelope é commitado sozinho e a promoção é best-effort,
com o erro contabilizado na própria linha (`tentativas`, `erro`).

**O que sustenta o "funciona sem ninguém cuidar":**
- `PromoverCapturasWpPendentesJob` — a cada minuto, promove o que ficou com
  `lead_id` nulo. É a rede de segurança; desligar o agendamento não quebra nada
  de forma visível (o webhook segue respondendo 201), só congela em silêncio o
  que falhou. A barra da `/leads` passa a ser o único aviso.
- `ExpurgarCapturasWpJob` — diário. Envelope > 180 dias sai; lead comercial
  **nunca** é apagado. A exceção é o lead de TESTE, apagado com 24h — é o que
  permite o botão "Enviar lead de teste" existir sem sujar a carteira de ninguém.
- Idempotência por `payload_hash` em janela de 10 min: retry do WP devolve 200
  com o mesmo `staging_id`, não um segundo lead. Mesmo e-mail em 24h reaproveita
  o lead existente (o envelope novo é guardado do mesmo jeito).
- `throttle:120,1` na rota. Não é contra o uso normal, é contra o token vazado.
  Junto com o teto de 256 KB por corpo, evita enchimento de disco num longText.
- `MarketingWpFormularioSeeder` garante a linha `*`. Sem ela o lead nasce com
  `cod_vendedor` nulo e, como `LeadController::scopeQuery` filtra por
  `whereIn('cod_vendedor', …)`, **nenhum vendedor o vê** — entra e some. A
  migration insere a linha, mas migration roda uma vez só; o seeder é a rede.

**O formulário real do site, lido do HTML dele (não suposto):** `/fale-conosco`
tem DOIS forms CF7 — o **f96** é só a newsletter do rodapé (campo `email`), e o
**f83** é o "Fale Conosco" de verdade: `name`, `empresa`, `segmento`, `cnpj`,
`estado`, `cidade`, `endereco`, `email`, `mc4wp-PHONE`, `assunto`, `itens[]`,
`mensagem`. **Cada form precisa do webhook configurado na própria aba** — o
painel do plugin é por formulário, não global. Foi por isso que o primeiro envio
real não chegou: o webhook estava no f96.

⚠️ **`mc4wp-PHONE`**: o Mailchimp for WordPress prefixa os campos que controla, e
o telefone é um deles. Sem tirar esse prefixo em `normalizarChave()`, nenhum alias
bate e o lead nasce **sem telefone** — o único dado que o vendedor usa para agir.
O mesmo descuido perdia `cnpj`, `estado`, `cidade`, `endereco` e `segmento`, que
têm coluna em `leads` e alimentam os filtros da tela.

⚠️ **`leads.estado` é varchar(2)** e o campo do site é texto livre. "São Paulo"
passa por `normalizarUf()` e vira `SP`; o que não é estado vira **null**. Truncar
daria `Sã` e o filtro de estado passaria a mentir.

**A mensagem do cliente vira observação no lead.** `mensagem`, `itens[]` e
`local_conhecimento` não têm coluna em `leads` e são o conteúdo do pedido — sem
isso o vendedor teria que abrir o payload cru para saber o que a pessoa quer.
⚠️ A observação nasce com **`user_id` NULO** (a coluna virou nullable na migration
`180000`): quem escreveu foi o cliente, não um usuário do CRM, e atribuir ao
vendedor seria autoria falsa — além de contar como atividade dele na Visão do
Gestor, que agrega por `user_id`. Quem exibe autor usa **`Observacao::nomeAutor()`**,
que cai para "Formulário do site"; os quatro pontos que faziam
`$o->user->display_name` direto quebrariam com property on null.

**O filtro de assunto existe mas está DESLIGADO** (`assuntos_nao_comerciais` vazia). O f83 é um Fale Conosco geral que também roteia SAC,
Licitação e Ouvidoria — Regra de ouro nº 2, esses têm sistema próprio e não podem
cair no funil do vendedor. Ficam na staging com o motivo em `erro`, e o e-mail do
site para o marketing sai para todos de qualquer jeito (não passa pelo CRM).
Formulário SEM campo de assunto passa direto, senão form novo nasceria mudo.

⚠️ **Por que desligado**: o primeiro envio real do site chegou com assunto
"Outros" — escolhido pelo próprio Tony ao testar. Isso respondeu na prática a
dúvida que a lista tentava resolver: quem preenche classifica de qualquer jeito,
então filtrar por aí descartaria orçamento de verdade. O mecanismo continua no
código e travado por teste; é só preencher `assuntos_nao_comerciais` se um dia o
ruído de SAC/Ouvidoria incomodar mais que perder lead.

**Se o mapeamento estiver errado no dia 1** (o plugin posta num formato que o
`WpLeadPayloadParser` ainda não conhece), a captura entra, o envelope é
guardado e a promoção marca `payload_sem_campos_comerciais` sem retentar — de
propósito, o mesmo parser daria o mesmo resultado. Ensine o alias novo ao
parser e rode `php artisan marketing:repromover-wp` (tem `--dry-run`). O
envelope cru da primeira captura real dá para inspecionar em
`GET /leads/{lead}/captura`. **Conferir isso na primeira captura de verdade**
— o formato exato do plugin nunca foi visto, só JSON e form-encoded genéricos.

**⚠️ O token nunca é renderizado na tela.** A `/leads` é vista pelos ~200
vendedores; a barra mostra só a URL base. O segredo mora no `.env` do servidor
e no campo do plugin.

**Testes**: `WordpressLeadWebhookTest` (caminho feliz, 13) e
`WordpressLeadWebhookResilienciaTest` (11 — token na URL, 429 do throttle, 413,
retry, dedupe, envelope sobrevivendo à falha, expurgo). O teste do throttle roda
**em processo, não por rajada de curl**: contra o `artisan serve`, que atende uma
requisição por vez, 130 chamadas levam mais que a janela de 60s e o limite nunca
aparece — dá verde sem provar nada.

### Contato por canal: WhatsApp, e-mail e "último contato" — 2026-09-02

A Carteira e os Leads passaram a ter três botões de contato no lugar de um (ligar,
WhatsApp, e-mail), a Carteira ganhou a coluna **Último contato**, e o Painel e a Visão
do Gestor passaram a mostrar quantos contatos vieram de cada canal.

**A coluna `ligacoes.tipo_contato` já existia** com o enum certo (`telefonica`,
`whatsapp`, `email`, `presencial`) desde a migration original — só que os dois
controllers gravavam `'telefonica'` chumbado e nada nunca lia a quebra. Não foi preciso
migration de schema para a feature; a única migration é de índice (abaixo).

**Definição de "último contato" (decisão do Tony):** só contato registrado em
`ligacoes` — ligação, WhatsApp, e-mail, presencial. **Observação NÃO conta**: é nota
interna, e incluí-la faria o indicador subir quando alguém só anota algo sem falar com
o cliente.

#### Onde cada decisão mora (Regra de ouro nº 8)
| O que se repete | Onde mora |
|---|---|
| Lista de canais válidos (validação) | `Ligacao::TIPOS_CONTATO` |
| Quebra por canal numa agregação | `Ligacao::somarPorCanal()` / `lerPorCanal()` |
| "Último contato" (coluna desnormalizada) | `UltimoContatoSincronizador` |
| Rótulos e ordem dos canais no front | `resources/js/constants/contatos.js` |
| Normalização de telefone e links | `resources/js/utils/contato.js` |
| Os três botões de contato | `Components/Contato/BotoesContato.vue` |

`normalizarTelefone` estava **copiada linha a linha** em `CarteiraTabela.vue` e
`LeadsTabela.vue` — com Leads entrando no escopo seriam três cópias. Foi extraída.

- **`BotoesContato.vue` NÃO registra o contato**: emite `contato` com o canal e quem
  chama decide a rota (`carteira.ligacao` ou `leads.ligacao`). É o que permite a
  marcação viver num lugar só sem o componente conhecer rotas.
- ⚠️ **O POST sai ANTES de abrir o discador/WhatsApp/e-mail.** Inverter a ordem faz a
  navegação cancelar a requisição e o contato não entra na métrica.
- WhatsApp abre em **aba nova** (`window.open`), diferente do `tel:`: o WhatsApp Web
  abriria por cima do CRM e o vendedor perderia a página e os filtros.

#### Validação do canal — é segurança, não organização
⚠️ O canal chega pela requisição e vira valor de um **enum do MySQL**. Sem o
`Rule::in(Ligacao::TIPOS_CONTATO)`, um valor arbitrário grava **string vazia em
silêncio** fora do modo estrito, e a métrica por canal passa a mentir sem erro nenhum
aparecer. Mesmo tipo de risco da whitelist de ordenação da Carteira. Coberto por teste,
inclusive nos Leads.

O escopo por vendedor (`autorizarCliente`/`autorizarLead`) continua valendo para os
canais novos — WhatsApp e e-mail não podem virar porta lateral para registrar atividade
em cliente alheio. Também coberto por teste.

#### Cores dos botões (Regra de ouro nº 5)
Mapa novo, com uma cor nova no `tailwind.config.js`:

| Função | Modificador | Cor |
|---|---|---|
| ligar | `.tbl-acao-verde` | green-700 (inalterado) |
| WhatsApp | **`.tbl-acao-whats`** | `#128C7E`, verde da **marca WhatsApp** |
| e-mail | `.tbl-acao-teal` | teal da Autopel (compartilha com "editar") |

⚠️ **`whats` não é cor da Autopel** — existe só para o botão ser reconhecido de
relance. O verde vivo da logo (`#25D366`) dá **1,98:1** sobre branco e sumiria como
ícone; `#128C7E` dá 4,14:1. Mesmo raciocínio dos tons `-dark` de cyan/âmbar.

⚠️ O ícone do WhatsApp é **preenchido** (`fill="currentColor"`), não traçado como
todos os outros — é o glifo da marca. Trocar por um contorno genérico tira exatamente
o que faz o botão ser identificado sem ler o tooltip.

Os três canais ficam numa progressão verde → verde-azulado → teal: leem-se como uma
família na mesma linha, e o ícone diz qual é qual.

#### Botão desabilitado em vez de link quebrado
- **WhatsApp exige DDD**: `wa.me` precisa de DDI+DDD+número. Telefone de 8-9 dígitos
  (**~5,6 mil clientes da base**) abriria o WhatsApp num número errado, sem erro. O
  botão fica desabilitado.
- **E-mail**: 85.111 dos 92.209 clientes têm e-mail; sem e-mail, desabilitado.

#### `min-w` da tabela subiu de 1000 para 1200px
Entraram uma coluna e dois botões. Com 1000 a coluna Ações espremia e os 7 botões
empilhavam um por linha, triplicando a altura da linha em tela média. A 1500px de
viewport a linha fica em **42px**, sem scroll horizontal.

#### `clientes.data_ultimo_contato` — desnormalização deliberada, e por quê
A coluna "Último contato" é **ordenável**, igual a "Última Compra". Isso só é possível
porque o valor virou **coluna indexada da própria `clientes`** (migration
`2026_09_02_110000`). Medido no escopo admin (92k clientes, 281k contatos):

| Ordenar a Carteira por | Tempo |
|---|---:|
| `data_ultima_compra` (referência, coluna indexada daqui) | 1,2 ms |
| `MAX(data_ligacao)` via LEFT JOIN agregado | **987 ms** |
| `MAX(data_ligacao)` via subconsulta correlata | **1.179 ms** |
| `data_ultimo_contato` desnormalizada | **0,9 ms** |

Quase 1 s só na ordenação estoura sozinho o orçamento de 400 ms — mesmo motivo que
tirou 'grupo'/'segmento' da whitelist em 2026-08-29. **O precedente estava ao lado o
tempo todo**: `data_ultima_compra` também é valor derivado (vem de `faturamentos`) e
mora em `clientes` exatamente por isso.

Ganho extra: a listagem **deixou de fazer uma consulta por página** em `ligacoes` — o
dado já vem na linha do cliente.

⚠️ **O preço da desnormalização é drift, e o antídoto é dono único.** Três travas:
1. **Um só ponto de escrita**: `App/Services/Carteira/UltimoContatoSincronizador.php`,
   chamado pelo hook `Ligacao::created()`. É hook de model, e não código nos
   controllers (ao contrário das notificações deste projeto), porque notificação
   esquecida é notificação a menos, mas coluna desnormalizada esquecida é **dado
   errado na tela** — silencioso, e usado pela ordenação. Já são dois pontos que criam
   contato (Carteira e Leads) e qualquer tela nova seria um terceiro.
2. **Fora do `$fillable` do `Cliente`**, de propósito: é assim que um import ou seeder
   acabaria gravando ali um valor que não veio de `ligacoes`.
3. **`php artisan carteira:recalcular-ultimo-contato`** reconstrói tudo a partir de
   `ligacoes`. Seguro rodar sempre (valor derivado, logo idempotente); ~17 s para 92k
   clientes. A migration usa **o mesmo serviço**, não uma cópia do SQL.

⚠️ **A regra de desempate tem que ser idêntica no hook e na reconstrução**: mais
recente vence; em empate de `data_ligacao` (WhatsApp e e-mail no mesmo segundo), vence
o registrado por último. Se divergirem, a coluna muda de valor toda vez que alguém
rodar a manutenção, e ninguém liga uma coisa na outra. **Há teste comparando os dois
caminhos** — é o teste mais importante deste conjunto.

⚠️ **Contato retroativo não sobrescreve** um mais recente (o `WHERE` do sincronizador),
senão um registro antigo lançado depois faria o cliente "voltar no tempo" na ordenação.

#### Performance (Regra de ouro nº 6 e nº 9) — medido com 281-289 mil contatos
Arco completo em `docs/performance.md` §1.12 ("os três degraus"). O que muda decisão:

- ⚠️ **A primeira versão da coluna custava 215 ms** — trazia TODOS os contatos dos 30
  clientes da página (8.130 linhas para exibir 30) e ficava com o primeiro de cada
  grupo em PHP. Uma janela (`ROW_NUMBER`) baixou para 25 ms; a desnormalização zerou.
  Em página típica as três empatam em ~5 ms — **por isso a forma ingênua passa
  despercebida sem medir com volume**.
- **A quebra por canal é de graça**: entra como coluna `SUM(tipo_contato = ?)` na
  agregação que já existia. Painel **+0,53 ms**; Visão do Gestor **+1,84 ms**.
- Ordenar por "Último contato" ficou indistinguível das outras ordenações na página
  real (tudo no piso do `artisan serve`, ~185-240 ms mesmo em página trivial).

#### 🔴 Achado colateral: a Visão do Gestor já estava lenta e ninguém tinha medido
`VisaoGestorController::ultimaLigacao()` (`MAX(data_ligacao) GROUP BY usuario_id`, sem
filtro de data) levava **1.025 ms** com 289 mil contatos — sozinha, era 2 s da página.
Não é regressão desta feature; é dívida que só apareceu porque a tabela foi populada
com volume realista.

Causa: `status <> 'excluida'` não estava no índice, então o MySQL varria as 294 mil
entradas e ia à tabela linha a linha. **Índice ESCOLHIDO ≠ índice SUFICIENTE** — o
`key:` já apontava certo; quem denunciava era o `Extra:`. Mesma lição de 2026-08-31.

**Migration `2026_09_02_100000`** estende `(usuario_id, data_ligacao)` com `status`:
**969 ms → 154 ms** (6,3x). Não é índice novo — mesmo prefixo, o antigo é removido.

⚠️ **Criar o novo ANTES de dropar o antigo**: `usuario_id` tem chave estrangeira e o
MySQL recusa remover o único índice que a sustenta.

⚠️ **A migration é idempotente** (checa `SHOW INDEX` antes de criar/dropar) porque dev
e produção podem chegar nela em estados diferentes — mesmo cuidado da `110000` de
agosto.

⚠️ Entrou nesta leva, e não "depois", porque os botões de WhatsApp e e-mail
**multiplicam as linhas desta tabela**: o que hoje é um contato por cliente vira três.
(Próximo passo já medido, se 154 ms incomodar: trocar o `GROUP BY` por subconsulta
correlata por vendedor — 102 ms. Não feito: 1,5x contra os 6,3x do índice.)

#### Testes
`tests/Feature/ContatoPorCanalTest.php` (18 casos): canal gravado por WhatsApp/e-mail,
default telefônico, canal inválido recusado sem gravar nada, escopo por vendedor,
Leads com o mesmo contrato, `ultimoContato` exposto com data e canal, contato
`excluida` ignorado, empate de data, hook atualizando a coluna, contato retroativo não
sobrescrevendo, **reconstrução batendo com o hook**, reconstrução zerando cliente sem
contato, ordenação nos dois sentidos, quebra por canal no Painel e na Visão do Gestor,
e canal zerado vindo como 0 e não ausente. Suíte inteira verde: **244 testes**.

⚠️ O teste do desempate foi verificado **por mutação** (removida a regra, ele falha) —
teste que passa não prova que pega o erro, e já houve dois testes de ordenação passando
por coincidência neste projeto em 2026-08-29.

#### O que ficou de fora
- **Export Excel da Carteira não ganhou a coluna "Último contato"** — hoje seria fácil
  (o valor virou coluna de `clientes`), mas o export já leva ~95 s e não foi medido de
  novo. Se fizer falta, é o próximo item e agora é barato.
- A ficha do cliente (`/carteira/{id}/detalhes`) não lista o histórico de contatos —
  só a Carteira mostra o último.

### Sete frentes: orçamento, funil, supervisor-vendedor e titularidade — 2026-09-03

Rodada grande, saída do uso real do beta. Detalhe de cada decisão está nos docblocks dos
arquivos citados; aqui fica só o que muda decisão futura.

#### 1. Preço de tabela travava o salvamento do orçamento (bug do beta)

A Fernanda não conseguia salvar quando o preço de tabela vinha com muitas casas. **Não era
validação do servidor** (a regra é só `nullable|numeric|min:0`): era `step="0.01"` num
`<input type="number">` recebendo valor de 4 casas de `produtos.preco_tabela decimal(12,4)`.
O navegador abortava o submit com `stepMismatch` e o balão nativo aparecia longe do campo —
para quem usa, "o botão não faz nada"; o jeito era apagar dois dígitos.

- **O campo deixou de ser input.** Preço de tabela é a REFERÊNCIA do fornecedor e o
  denominador do desconto que define o nível de aprovação — não é campo de digitação do
  vendedor. Entra pela busca de produto e fica `—` quando não há produto vinculado.
- ⚠️ **Dano invisível junto**: `orcamento_itens.preco_tabela` era `decimal(12,2)` e o MySQL
  **arredonda** casa excedente em silêncio (Note 1265). O valor era truncado ao gravar, e
  ele é o denominador do desconto — um centésimo a menos pode fazer um item cruzar as
  fronteiras de 10%/15% e mudar QUEM aprova. Migration `2026_09_03_090000` levou a 4 casas.
- ⚠️ **Sem backfill, de propósito.** O preço de tabela de um orçamento é a referência
  *daquele momento*; reescrevê-lo mudaria retroativamente o nível de aprovação de
  documentos já aprovados.
- Conferido que a calculadora de etiqueta **não** tem o mesmo problema: `precoSugerido` já
  é `round(…, 2)`.

#### 2. Os subtotais do orçamento mentiam (ambiguidade do beta)

O orçamento 2110 (KNTT), todo de etiquetas e em modo Serviço, imprimia *"Subtotal s/ IPI
2.224,80"* e *"Subtotal etiquetas 8.832,00"* — dois baldes de mesma natureza, com nomes
sugerindo tributação diferente, num documento sem IPI nenhum. "Subtotal s/ IPI" era só o
nome do balde PRODUTOS.

- **Os totais passaram a falar de IMPOSTO, nunca de categoria.** `resumo()` devolve
  `subtotalSemIpi`, `valorIpi` e `totalGeral`, e **as três sempre fecham**. Sem IPI: duas
  linhas ("Subtotal", "VALOR TOTAL"). Com IPI: três, e a soma bate.
- ⚠️ O contrato antigo **não fechava**: com IPI, `subtotalProdutos + subtotalEtiquetas` não
  dava `totalGeral`. Ninguém tinha notado porque não havia teste nenhum sobre isso.
- **`resources/js/utils/orcamento.js`** (novo) — a matemática de IPI vivia copiada em
  `Form.vue` e `ItensTabela.vue`, com arredondamentos e ORDENS DE OPERAÇÃO diferentes entre
  si e do PHP (`total/1,0325` no back vs `qtd × unit/1,0325` no front). Divergência de
  centavos entre tela e PDF era estrutural. Agora é um módulo só, espelho do serviço PHP.
- ⚠️ **`OrcamentoSheet.vue` e `pdf.blade.php` são um par** (o próprio blade já avisava):
  rótulo, ordem e regra de exibição têm que bater nos dois.
- Testes: `tests/Unit/Orcamento/OrcamentoCalculoServiceTest.php` (16 casos). ⚠️ Dois
  fixtures foram **achados por busca, não por intuição**, porque a suíte passava com o
  código errado: `qtd=2, unit=1,51` distingue as duas ordens de operação (2,92 vs 2,93), e
  três itens (41×5,22 / 10×6,37 / 22×1,01) distinguem subtrair o IPI de somá-lo por linha
  (9,44 vs 9,45). Sem eles o teste não morde — verificado por mutação.

#### 3. Supervisor também é vendedor — alternador global

Exceção da Autopel: supervisor atende clientes diretamente. O sistema resolvia o escopo
dele como **só a equipe**, então **9.387 clientes (10,2% da base)** que são carteira pessoal
de supervisor não eram vistos por ninguém nesse perfil. Pior: **ROBERTO (`000197`) tem
equipe vazia** — `[]` num `whereIn` devolve zero linhas, e ele via **tela em branco no
sistema inteiro** apesar de ter 1.649 clientes no nome dele.

- **`App\Services\Escopo\ModoVisao`** — sessão `visao_modo` (`equipe` | `pessoal`), lida
  DENTRO do `DashboardScopeResolver`. `resolve()` é chamado em 18 pontos de 8 controllers;
  passar parâmetro em cada chamada é o que o legado fez (`supervisor_apenas_proprios`
  threaded à mão por ~15 arquivos) e por isso ficou inconsistente entre telas.
- **Custo por request: ZERO query** — mesmo desenho da simulação de usuário. Travado por
  teste.
- ⚠️ **A prop compartilhada chama-se `modoVisao`, NÃO `visao`.** Seis páginas já mandam uma
  prop de PÁGINA chamada `visao` (dropdowns de supervisor/vendedor), e no Inertia a prop de
  página sobrescreve a compartilhada: com o nome colidindo o alternador **não aparecia** em
  nenhuma delas, sem erro no console. Só apareceu abrindo a Carteira no navegador — nenhum
  teste de escopo pegaria, porque o servidor resolvia certo e quem sumia era o botão. Há
  teste travando isso agora.
- ⚠️ **Modo Equipe é equipe PURA** (decisão do Tony): a carteira pessoal não se mistura com
  a da equipe.
- ⚠️ **`/equipe` e `/metas` são a exceção**: ali o supervisor entra JUNTO da equipe, porque
  a pergunta de uma tela de gestão é "por quem esta pessoa responde?". A regra mora em
  `EquipeScopeResolver::codigosEquipeDe()`, compartilhada pelas duas.
- **`MetaRankingResolver::usuariosDoEscopo()`** passou a incluir `supervisor`. ⚠️ **Efeito
  colateral visível, e é a correção**: havia **R$ 9,04 mi** de meta gravados em códigos de
  supervisor que nunca eram somados; o gauge de meta da EMPRESA INTEIRA muda de valor.
- Supervisor **vê** a própria meta mas **não edita** — quem atribui é admin/diretor.
- Fora de contexto HTTP (fila, `cache:aquecer`) o modo é sempre `equipe`: o job monta chave
  de cache a partir do escopo, e "herdar" modo pessoal aqueceria chave que ninguém procura.

#### 4. Painel: aba Venda ao lado de Faturamento

Venda (pedido emitido) é o que o vendedor influencia hoje; faturamento é consequência.
`ComparacaoCard.vue` substitui `FaturamentoComparisonChart.vue`, com **Venda como aba
padrão**. As duas séries vêm juntas — alternar não vai ao servidor.

- ⚠️ **As duas abas usam a MESMA janela (D-1, D-3 na segunda)**, porque o card fica ao lado
  do KPI "Valor no mês". O faturamento usava ano civil cheio; manter duas convenções daria
  dois números para o mesmo mês na mesma tela. A chave do faturamento virou `paraDoDia()`.
- ⚠️ **Bloco de cache PRÓPRIO.** Os dois têm o mesmo formato de retorno, então chave
  compartilhada não daria erro — só entregaria o número errado na aba errada, em silêncio.
  Travado por teste (verificado por mutação).
- ⚠️ **A série do ano anterior nasce vazia** e o card DECLARA isso. O histórico de pedidos
  emitidos ainda não foi carregado (existe no legado); 2025 inteiro tem 67 pedidos. Não é
  bug de query, é ausência de dado — e o aviso some sozinho quando o import entrar.
- Gestor continua vendo o Power BI, não o gráfico.

#### 5. Captura do site: ficha legível + notificações

O botão "ver payload" despejava `JSON.stringify` num `<pre>`. Quem abre é vendedor.

- **`CapturaWordpressDetalhe.vue`** — campos rotulados; JSON cru vira bloco "Dados
  técnicos" recolhido e **decidido no servidor**, só para admin (um `v-if` no Vue não
  impediria o vendedor de ler o payload na resposta da rota).
- ⚠️ Os rótulos comerciais saem de `WpLeadPayloadParser::extrairCampos()`, o MESMO
  dicionário que a promoção usa. Duplicar o mapa no front faria a ficha mostrar um valor e
  o lead guardar outro.
- ⚠️ **Três formas de envelope**: webhook e teste guardam em `parsed`, CSV em `colunas`, e
  o payload pode vir como string quando o JSON está corrompido. A ficha aguenta as três.
- **Notificação de lead novo** — disparada em `WpLeadIngestor::promover()`, não no
  controller do webhook (a promoção também roda pelo job de retry).
  - ⚠️ **Chaveada pela CAPTURA (`marketing_wp_lead_raw.id`), não pelo lead.** É o que faz o
    retry ser seguro. Testar isso exige simular a falha real (zerar `lead_id`): chamar
    `promover()` três vezes seguidas não exercita nada, porque ele retorna cedo.
  - ⚠️ **Notifica TODOS os usuários do `cod_vendedor`** — a regra é "quem é avisado é quem
    enxerga", e o código é documentadamente não único.
  - ⚠️ **Lead sem `cod_vendedor` notifica os admins**: é a falha mais silenciosa da
    integração — sem código, ninguém vê o lead e ele entra e some.
  - **Lead de teste não notifica.** ⚠️ Consequência: o botão "Enviar lead de teste" deixou
    de provar o caminho da notificação; para verificá-lo, captura real ou tinker.
- **Captura travada** avisa o admin. ⚠️ O que evita o aviso por minuto é o escopo
  `pendentes()`, não a chave de idempotência — a chave cobre workers concorrentes. São dois
  mecanismos diferentes.

#### 6. Funil de leads (kanban)

`leads.status` era `ativo | inativo | convertido | excluido` — descrevia o REGISTRO, não a
NEGOCIAÇÃO; por isso "convertido" convivia com "inativo".

- **`etapa`** (`novo → em_contato → orcamento → negociacao`, mais `ganho`/`perdido`),
  **`etapa_alterada_em`**, **`motivo_perda`**. `status` encolheu para `ativo | excluido`.
- ⚠️ **Ganho e Perdido não são colunas** — são desfechos que tiram o card do quadro.
- ⚠️ **`etapa_alterada_em`, nunca `updated_at`**: qualquer edição toca o `updated_at` e uma
  correção de telefone "reanimaria" um lead esquecido há meses.
- ⚠️ **A migração deriva a etapa do que já estava gravado** (convertido→ganho,
  inativo→perdido, com ligação→em_contato). O carimbo inicial é `updated_at`, então o
  "parado há X dias" nasce **aproximado** até o primeiro movimento real.
- **Três formas de mover, um endpoint só** (`PATCH /leads/{lead}/etapa`): botão `→`,
  arrastar, e auto-avanço.
- ⚠️ **AUTO-AVANÇO NUNCA RETROCEDE** — registrar contato num lead em Negociação não pode
  puxá-lo de volta, e nunca toca lead com desfecho. É a regra que faz o quadro não brigar
  com o vendedor. ⚠️ As duas guardas de `avancarAutomaticamentePara()` se sobrepõem de
  propósito; ver o docblock antes de "simplificar".
- ⚠️ **O gancho de contato entra no `Ligacao::created()` que já existe**, não num segundo
  listener.
- **`orcamentos.lead_id`** (novo) — o orçamento só copiava nome/CNPJ como texto. Além do
  funil, permite responder "quantos orçamentos saíram deste lead?".
- ⚠️ **O quadro NUNCA carrega a coluna inteira**: 17.173 leads, quase todos em "Novo".
  `LIMIT 20` por coluna + total agregado, e "carregar mais" **por cursor, não offset**.
  Travado por `tests/Feature/Performance/FunilDeQueriesTest.php`.
- ⚠️ **Mover card usa `fetch`, não `router.patch`**: o redirect do Inertia refazia a visita
  e, como `funil` é prop OPCIONAL, ela não voltava — o quadro sumia a cada movimento.
  Confirmado no navegador.

#### 7. "Quem cuida do cliente?" (gap do legado, fechado)

Vive em `/cadastros` — é onde a resposta evita o erro. Mostra razão social, documento,
responsável e supervisor. Nada mais.

> **Realocada em 2026-09-04 (mudança só estética, pedido do Tony).** Saiu do topo da
> solicitação de cliente novo e passou a ficar no **nível da página, logo abaixo da barra
> de filtros do `PageHero` e acima das abas** — a pergunta precede QUALQUER solicitação,
> inclusive bobina e etiqueta, então é contexto de página e não passo de um formulário.
> Junto virou **`DarkCard`**: era o único bloco da tela desenhado do zero (Regra de ouro
> nº 8) e, pior, o `bg-gray-50` do painel próprio era **exatamente a mesma cor do
> `.tbl-head-row`** — a tabela de resultados não tinha separação do fundo e parecia vazar
> do container. Num corpo branco de card os tokens de tabela voltam a funcionar como no
> resto do sistema. O campo de busca também adotou a gramática da barra de filtros logo
> acima (rótulo micro em maiúsculas + `py-1.5 text-xs`), para as duas linhas lerem como
> uma coisa só. Nada de servidor mudou.

- ⚠️ **É a ÚNICA consulta de cliente do sistema que IGNORA o escopo do vendedor**, e o
  escopo ausente É a feature: a Carteira é escopada e por isso não responde "de quem é esse
  CNPJ?". O que limita o dano é o conteúdo ser pobre — sem status, sem última compra, sem
  telefone, sem valores. Cada campo novo ali é decisão consciente. Há teste afirmando que
  esses campos NÃO saem.
- ⚠️ **Sem `raiz_cnpj`** (Regra nº 3) e sem `REPLACE()` na coluna: os dígitos digitados são
  remontados na máscara e a busca vira `cnpj LIKE 'prefixo%'`, que usa o índice. Conferido
  antes de escrever: 91.451 clientes com CNPJ mascarado, 746 com CPF mascarado, **zero** só
  com dígitos.
- Inclui as solicitações pendentes de `clientes_para_cadastro` — é o que impede a segunda
  solicitação duplicada.

#### Performance — tudo medido com volume real (Regras nº 6 e nº 9)

| O quê | Antes | Depois |
|---|---:|---:|
| Funil, contagem das 4 colunas (admin, 17k leads) | 55,1 ms | **10,4 ms** |
| Funil, cards das 4 colunas (admin) | 281,1 ms | **8,1 ms** |
| Titularidade, nome raro ("KNTT", 92k clientes) | 360 ms | **9,5 ms** |
| Titularidade, `razao_social` OR `nome_fantasia` | 287,3 ms | **1,3 ms** |

- ⚠️ **Duas vezes o problema foi `Extra:`, não `key:`.** No funil sem escopo o MySQL
  ESCOLHIA `leads_status_index` e mesmo assim fazia `Using temporary; Using filesort`.
  Mesma lição de 2026-08-31: índice escolhido ≠ índice suficiente.
- ⚠️ **O termo RARO é o mais caro numa busca por nome.** Com `ORDER BY … LIMIT 30`, o MySQL
  percorre o índice até achar 30 matches; para um termo com poucas ocorrências ele percorre
  as 92 mil. Quanto mais específica a busca, pior o tempo — daí prefixo primeiro e "contém"
  só como fallback.
- ⚠️ **Um OR com coluna não indexada derruba o plano inteiro** — daí o índice novo em
  `nome_fantasia`. Acrescentar uma terceira coluna àquele OR exige indexá-la junto.
- **`pedidos` ganhou covering index** (`2026_09_03_140000`) replicando o padrão dos
  faturamentos. ⚠️ **O ganho HOJE é nulo** — a tabela tem 15.991 linhas e já roda em 16 ms.
  O índice está lá pelo histórico que vem (407.604 linhas no legado); criá-lo depois, com a
  tabela cheia, custaria janela de manutenção.

#### Lições de processo desta rodada

- ⚠️ **Verificar teste por MUTAÇÃO virou obrigatório, e provou-se necessário.** Cinco
  testes desta leva passavam com o código quebrado na primeira versão. Em dois casos o
  fixture teve que ser **procurado por busca exaustiva**, porque nenhum valor "natural"
  distinguia o certo do errado.
- ⚠️ **Três defeitos só apareceram no navegador**, nenhum deles pegável por teste de
  servidor: a colisão de nome de prop, o quadro sumindo a cada movimento, e o nome fantasia
  escondido no resultado da titularidade.
- ⚠️ **`document.getElementById('app').dataset.page` é o payload INICIAL** e não acompanha
  as visitas do Inertia. Ler dali para conferir estado depois de uma ação dá resultado
  errado — perdi tempo "diagnosticando" um alternador que já funcionava.
- ⚠️ **A suíte roda contra um `palma_v2_test` único nesta máquina.** Dois agentes rodando
  `php artisan test` ao mesmo tempo derrubam as tabelas um do outro no meio do
  `migrate:fresh` e produzem centenas de `QueryException` que não são bug de código. Se
  acontecer: `DROP DATABASE palma_v2_test; CREATE DATABASE palma_v2_test …` e rodar de novo.


### Gauge de metas passou a medir Venda, com alternador — 2026-09-04

O card "Performance Comercial" do Painel media só **faturamento**: nota emitida, que sai
dias depois do pedido. O vendedor via a própria performance medida por um número que ele
não move no dia. Agora o card tem **duas abas — Venda e Faturamento — com Venda como
padrão**, mesmo alternador e mesma justificativa do card de Comparação (2026-09-03).

**Não foi preciso migration.** `metas_mensais.tipo` já era `enum('faturamento','venda')`
desde a migration original, `/metas` já editava e rankeava os dois, e
`MetaRankingResolver` já sabia somar `pedidos.valor_total` por `data_pedido`. O que
faltava era o gauge saber perguntar.

#### O de-para tipo → fonte do realizado mora num lugar só

| tipo | realizado | tabela / coluna |
|---|---|---|
| `venda` | pedido emitido (aberto ou faturado) | `pedidos.valor_total` por `data_pedido` |
| `faturamento` | nota emitida | `faturamentos.valor_total` por `data_emissao` |

`MetaRankingResolver::queryRealizado()` é a única definição disso. Ela substituiu
`faturamentoPorCodigo()` + `vendaPorCodigo()`, que eram o **mesmo código** com outra tabela
e outra coluna de data — com as duas cópias, "qual é a fonte do realizado de venda" tinha
duas respostas possíveis e o gauge novo seria uma terceira (Regra de ouro nº 8).

`metaVsFaturamento()` virou **`metaVsRealizado(..., string $tipo)`** e devolve
`realizado`, não `faturamento` — o componente do front foi renomeado junto
(`MetaFatCard.vue` → `MetaRealizadoCard.vue`, campo `dados.realizado`). Nome de campo que
descreve metade dos casos é como um rótulo errado entra na tela sem ninguém reparar.

- ⚠️ **`TIPOS` é whitelist, não organização.** O tipo vem da aba e escolhe QUAL TABELA
  responde. Valor desconhecido **estoura** (`InvalidArgumentException`); cair num default
  "faturamento" mostraria o realizado errado sob o rótulo certo, sem erro nenhum. Mesmo
  risco da whitelist de ordenação da Carteira. Coberto por teste.

#### O KPI "Pedidos emitidos" mudou de universo — e o admin vai notar

Os tiles agora usam **a mesma lista de códigos resolvida para a meta** (vendedor,
representante e supervisor ativos com código), em vez da tabela inteira de pedidos.

- ⚠️ **Em dev isso é 81% do valor do mês** (R$ 3,99 mi → R$ 763 mil; 1.171 → 345 pedidos) e
  75% do ano. Não é regressão: era a mesma divergência que já existia entre o Painel e
  `/metas`, só que agora ficaria a cinco centímetros do "Realizado" da aba Venda, na mesma
  leitura — dois números para a mesma coisa.
- ⚠️ **Consequência conhecida e aceita:** o tile diverge da listagem `/pedidos-emitidos`,
  para onde ele mesmo linka — lá o escopo é operacional (todo pedido do escopo bruto), aqui
  é a equipe comercial ativa. Se um dia isso incomodar mais que a contradição interna do
  card, o conserto é uma linha (voltar a passar `$codVendedores` cru para
  `pedidosEmitidos()`).
- **A igualdade "Valor no mês" = "Realizado" da aba Venda é ESTRUTURAL**, não uma promessa
  de comentário: o tile lê o número que a agregação de venda já calculou, em vez de somar de
  novo. Travado por teste.

#### Performance — medido com volume real (Regras nº 6 e nº 9)

O bloco dobrou de agregações (2 → 4) e **o escopo mais caro ficou mais rápido**:

| escopo | antes | depois | queries |
|---|---:|---:|---|
| empresa (`null`) | 167,8 ms | **149,0 ms** | 20 → 15 |
| equipe (51 códigos) | 49,2 ms | 57,1 ms | 8 → 9 |
| vendedor (1 código) | 11,7 ms | 17,7 ms | 8 → 9 |

Arco completo em `docs/performance.md` §1.16. O que muda decisão:

- ⚠️ **Medições intercaladas, não sequenciais.** Em dev sob Docker/WSL2 o mesmo bloco varia
  30% entre execuções; a primeira comparação "antes/depois" que fiz apontou +35% e era
  ruído. A resposta certa é rodar as duas versões alternadas no mesmo processo.
- **O custo real era o escopo, não a agregação.** Resolver `null` (empresa) custa 6 queries
  de roles/perfis do spatie; com quatro agregações resolvendo por dentro seriam 24 queries
  só para redescobrir a mesma lista. Daí `codigosDoEscopo()` ser público — quem monta um
  bloco com várias agregações resolve **uma vez** e passa adiante.
- **Venda é barata onde faturamento é cara:** soma do ano no escopo empresa, `pedidos`
  **24 ms** contra `faturamentos` **660 ms** (46 mil linhas contra 6,0 milhões, as duas com
  covering index).
- As duas contagens de pedido saem de **uma query só** (`COUNT(*)` para o ano,
  `SUM(data_pedido >= início do mês)` para o mês — a janela do mês é sufixo da do ano).
  `type=range`, `Using index`.

#### ⚠️ `ChaveEscopo::VERSAO` foi para `v2`

O payload de `metaGauge` deixou de ser `{mes, ano}` e virou `{venda, faturamento}`. Sem o
bump, um payload v1 ainda quente seria entregue ao front novo durante os 30 min de TTL após
o deploy e quebraria o card no navegador de quem já estava logado — exatamente onde ninguém
olha o console. **Toda mudança de FORMATO de bloco cacheado exige esse bump**; o histórico
fica no docblock da constante.

#### Dois detalhes de tela

- **O anel encurta percentual grande** (`999+%` acima de mil, arredondado de 100 a 999).
  Não é firula: com meta de venda zerada ou em ordem de grandeza errada, um `37281.4%`
  transbordava o círculo e cobria a legenda. O valor exato continua no card logo abaixo.
- **`MetaMensalSeeder` gerava meta de venda entre 500 e 4.000** — escala de *quantidade de
  pedidos* — enquanto a de faturamento ia a 200 mil e o realizado de venda soma milhões. Em
  dev o gauge mostrava percentuais de cinco dígitos e parecia defeito de cálculo. Agora as
  duas nascem em R$ e na mesma ordem de grandeza. ⚠️ **Reseed não é automático**: o banco de
  dev ainda tem os valores antigos (e os meses 8-12 zerados por alguma rodada anterior), então
  o gauge de venda local segue mostrando número absurdo até `metas_mensais` ser limpa e
  semeada de novo.

#### Testes

`tests/Feature/DashboardMetaVendaTest.php` (10 casos). ⚠️ Os valores do fixture são
**todos diferentes entre si** de propósito (meta venda 4.000, meta faturamento 250, pedido
1.000, nota 500): venda e faturamento têm formato de retorno idêntico, então com números
parecidos trocar a tabela de um pela do outro passaria verde. Cobre também o corte D-1, o
escopo por vendedor, escopo vazio, tipo inválido, a separação mês/ano da query fundida, a
igualdade tile ↔ realizado, o contrato da prop com o front e um **teto de queries** (9) que
denuncia a volta da resolução de escopo por agregação.

⚠️ **Verificado por mutação**, não só por estar verde: quebrar `queryRealizado`, o tipo da
meta, a ordem das contagens ou a reutilização do valor faz o teste correspondente falhar.
Suíte inteira verde: **346 testes**.

### Painel redesenhado: tabela no lugar do gráfico, retrato diário e Potencial — 2026-09-05 a 09-08

Quatro dias de iteração no Painel, quase toda ela vinda do uso real do beta e de recados do
diretor repassados pelo Tony. Vale ler junto de `docs/performance.md` §1.17.

**A régua do diretor, que explica metade das decisões abaixo: "MENOS É MAIS, nada de
análises complicadas, simples e direto."** Toda vez que um quadro daqui ficou com cinco
números por painel, ele mandou cortar.

#### 1. O gráfico ano-a-ano virou tabela, e ganhou aba de dia

`FaturamentoComparisonChart.vue` (Chart.js) foi apagado. No lugar, **`ComparacaoCard.vue`**:
meses nas colunas, ACUMULADO à direita, e **dois alternadores** — `Venda | Faturamento` e
`Mês | Dia`. Motivo declarado do Tony: **a direção não gosta de gráfico.**

- O card chama-se **"Evolução Comercial"** desde 08/09 (era "Comparação") — pedido do
  diretor, que também não reconheceu o quadro pelo nome antigo.
- A aba **Dia** é o "retrato diário" que ele pediu: **só o mês corrente**, duas linhas ("No
  dia" e "Acumulado"). "Virou o mês não traz mais" — cai naturalmente, porque a janela é
  sempre mês corrente até D-1.
- ⚠️ **As duas abas usam a MESMA janela D-1.** Já estava assim desde 03/09 e continua
  valendo: duas convenções dariam dois números para o mesmo mês na mesma tela.
- O gauge "Acumulado do ano" saiu do card de Performance Comercial — o subtotal da tabela
  passou a responder isso, e manter os dois era repetir.

⚠️ **`somaMensal()` tinha um bug de janela que só a tabela expôs**: passava `12` fixo como
mês final, então a série do ano corrente não era cortada em D-1. No gráfico ninguém via; na
tabela virou uma coluna com número maior que o acumulado. Corrigido com `$mesFim`, e há dois
testes de regressão — verificados por mutação (13.899 contra 3.900).

#### 2. Pedido eternamente em aberto não conta como venda (regra dos 180 dias)

O Tony estranhou ver venda de 2025 no perfil admin e pediu para investigar. **Não era erro de
importação** — provei que o arquivo do TOTVS traz exatamente aqueles pedidos (10/34/67 por
ano). São 111 pedidos de 2023-2025 ainda abertos, R$ 271.443,06, e a explicação é dele:
*"as vezes é uma maquina que a empresa compra e sai a nota lá (ja vi sendo faturado um onibus
da mercedes em 2013 rsrs)"*.

Decisão dele: **não apagar o resíduo, mas não deixá-lo virar métrica.**

**`Pedido::scopeContaComoVenda()`**, com `DIAS_MAXIMO_EM_ABERTO = 180`: pedido faturado conta
sempre; pedido em aberto conta enquanto for mais novo que 180 dias.

- ⚠️ **Aplicado em exatamente TRÊS pontos de métrica** — `vendaComparacao` (série mensal e
  diária) e `MetaRankingResolver::queryRealizado('venda')`, mais o tile de pedidos emitidos.
  **NUNCA nas listagens** (`/pedidos-abertos`, `/pedidos-emitidos`): lá a pergunta é
  operacional, e sumir com o pedido velho da lista é o que impediria alguém de resolvê-lo.
- ⚠️ **O corte não podia ser "só pedido faturado"**: 83% do valor de setembro é pedido ainda
  em aberto, então essa versão apagaria o mês corrente da tela. Medido antes de escolher.
- Efeito em produção: 2025 foi de R$ 198.293 para **R$ 0**; 2026 caiu 0,02% (R$ 458,73 mi →
  R$ 458,65 mi). O joelho da idade está em 90 dias (98,8% do valor), e 180 foi escolhido por
  folga.
- ⚠️ **O `OR` do escopo quebra o índice no escopo empresa** (`type: ALL`, 197 ms); por
  vendedor segue indexado (mediana 15,6 ms). Aceito porque o bloco é cacheado e aquecido —
  mas é o primeiro lugar a olhar quando `pedidos` receber o histórico.

#### 3. "Potencial da Carteira" nasceu por família de produto e morreu no mesmo dia

Construído em 05/09 cruzando família (bobina/etiqueta/tag) com faturamento. O diretor cortou
em 06/09 — complicado demais — e o Tony decidiu: **família sai da tela "nesse 1 momento"**.

⚠️ **A medição concordou com ele, e por um motivo que vale guardar:** o quadro com família
custava **41,5 s** no escopo empresa, porque cruzava `produtos` sobre as 5,87 M linhas de
`faturamentos`. Sem família, a mesma pergunta sai de `clientes` (já indexada) e custa
**39 ms por vendedor / 261 ms na empresa** — foi isso que permitiu **dar o quadro ao ADM**,
que era o outro pedido do diretor.

**O back-end de família continua no repositório, sem uso na Home**: `FamiliaProduto`,
`PotencialCarteiraResolver`, a tabela `potencial_pesos`, o comando `potencial:importar-pesos`
e o filtro `?sem_familia=` da Carteira. Está testado e volta quando a regra de família for
fechada com a diretoria. ⚠️ Consequência a lembrar: **o filtro `sem_familia` ficou
inalcançável pela interface** — nenhum link aponta para ele.

#### 4. O quadro que sobrou: "Segmentos Atendidos"

`SegmentosInativosCard.vue` + `App\Services\Carteira\SegmentosInativosResolver`. Uma linha
por segmento, com quantos clientes **inativos** há nele. "Inativo" é o mesmo corte da
Carteira (365 dias sem compra, ou nunca) via `ClienteStatusResolver` — uma pergunta, uma
resposta.

**🚨 A INVARIANTE, e o caminho errado que percorri até ela.** Em 08/09 restringi a lista aos
segmentos que a pessoa atende (era o que o nome do card dizia). O resultado: o quadro somava
66.753 enquanto o card "Carteira por Segmento", a cinco centímetros, mostrava 73.940 para a
mesma carteira. Tentei resolver com legenda — o subtítulo passou a dizer *"66.753 dos 73.940
estão nos seus segmentos"*. **O Tony recusou, e a frase dele é a regra: "os dois números têm
que bater em todos os casos".**

> **Número que precisa de legenda para não parecer errado já perdeu a confiança.** Não é
> sobre estar certo — os dois estavam. É que o usuário não tem como saber em qual acreditar,
> e a dúvida contamina a tela inteira, não só o card.

Então: **o quadro lista TODOS os segmentos em que o escopo tem cliente inativo, e os
atendidos vêm MARCADOS** (ponto teal), nunca filtrados. Marcar em vez de filtrar atende
junto o pedido do diretor ("colocar todos os segmentos que essa pessoa atende, e destacar na
listagem") sem quebrar a soma.

- ⚠️ **`total` == inativos da carteira do escopo, sempre.** Há teste conferindo isso contra o
  próprio `ClienteStatusResolver` — o mesmo caminho do outro card —, e não contra número
  escrito à mão. **Filtro de linha aqui é proibido**: filtre a EXIBIÇÃO (o "ver mais"),
  nunca o somatório.
- Segmento atendido **sem nenhum inativo aparece com zero** e marcado. Somar zero não mexe na
  invariante.
- ⚠️ A lista mostra **3 linhas** e abre o resto no clique; o TOTAL é sempre da carteira
  inteira, esteja a lista aberta ou não.

#### 5. Potencial = inativos × peso, **em caixas**

A diretoria entregou a matriz em 08/09 (planilha `Potencial segmentos 08-09-2026.xlsx`, em
`Documentos\DOCS\CRM`). Escala **0-20**, e a **unidade é CAIXA** (Tony): o peso é quantas
caixas um cliente daquele segmento tende a comprar. 20 em SUPERMERCADISTA e REVENDA, 0 em
ÓRGÃO PÚBLICO, CORPORATIVO, TRANSPORTE e mais oito.

- **`segmentos.peso_potencial`** (migration `2026_09_08_100000`), e não tabela nova: o peso é
  1:1 com o segmento. A `potencial_pesos` (segmento × família) segue existindo e sem uso —
  responde outra pergunta. Está escrito na migration qual é qual.
- ⚠️ **Default 0, nunca 1.** Peso ausente vale "não é alvo", não "vale um": segmento novo do
  TOTVS entra zerado e fica fora do ranking até alguém decidir, em vez de aparecer no meio da
  lista sem ninguém ter escolhido nada.
- ⚠️ **A migration PREENCHE os valores, não só cria a coluna.** Dev e produção já têm os 23
  segmentos gravados e o seeder só alcança banco semeado de novo — sem o `update` a coluna
  nasceria zerada em produção, a tela mostraria potencial 0 para todo mundo, e isso passaria
  por "ainda não calibraram os pesos" por meses. Os mesmos valores estão no `SegmentoSeeder`,
  para banco novo e para a suíte; **mudança de peso mexe nos dois lugares** enquanto não
  houver tela de edição.
- **A tabela é ordenada por potencial**, com inativos como desempate. Ordenar por inativos
  deixaria no topo justamente o segmento que a diretoria marcou como fora do alvo.
- ⚠️ **A unidade aparece na tela em três lugares** (cabeçalho "(caixas)", `20 cx/cliente` sob
  cada número, `cx` no total): todo outro número grande do Painel é em reais, e potencial sem
  unidade seria lido como dinheiro.
- O peso vai impresso embaixo do potencial de propósito — o vendedor lê "12.205 × 20" e
  confere de cabeça. Número que ninguém consegue conferir é número em que ninguém confia.

Produção logo após o deploy, escopo empresa: **73.940 inativos, 474.581 caixas de potencial,
26 segmentos, 20 atendidos** — e o total batendo com o card vizinho.

#### 6. Cards altos: principal visível, detalhe expansível

Quatro cards ocupavam vertical demais. A primeira tentativa recolhia o card inteiro e estava
errada por dois motivos — escondia o número que se lê de relance e, dentro de um grid
`items-stretch`, o card recolhido continuava ocupando a altura da fileira.

**`DarkCard` ganhou o slot `#detalhes`** (props `rotuloDetalhes` e `chaveDetalhes`): o
principal fica sempre visível, a lista de registros recentes abre no clique. Estado por
pessoa e por navegador em `localStorage`, **toda leitura e escrita dentro de try/catch** —
em janela anônima o acessor estoura, e um card não pode derrubar a página por isso.

⚠️ A capacidade mora no `DarkCard`, não em cada card: são quatro lugares querendo o mesmo
comportamento (Regra de ouro nº 8).

#### 7. Power BI: fora o iframe, e a faixa foi parar no topo

O embed de 560px saiu em 06/09 a pedido do diretor — para quem não estava logado na conta
Microsoft ele mostrava só a tela de login, que foi exatamente a captura que ele mandou.
Restou o atalho.

Em 08/09 o Tony pediu a faixa **acima do Segmentos Atendidos** e "pinta de azul pra
destacar". Duas correções na sequência dele, e elas eram problemas diferentes:

- **"Está feia" era estrutura, não cor.** A faixa reusava o desenho do `DarkCard` (header de
  3,5 rem, título + subtítulo comprido, botão contornado) sem nada embaixo — lê como card
  quebrado. Virou objeto próprio: mais baixa que um header de card, **a linha inteira é o
  link**, ícone num chip, e a ressalva do seletor de visão saiu do subtítulo para o `title`.
- **"Tá muito escuro" era cor.** O navy encaixado entre o PageHero preto e o header preto do
  card de baixo virava só mais uma faixa escura. Trocado pelo **cyan da marca (#00A9CE)**, o
  tom mais claro da paleta, com filete navy na borda e botão branco.
- ⚠️ **Sobre esse cyan o texto é NAVY, nunca branco**: branco dá 2,4:1 e some; navy dá 5,2:1.
- ⚠️ **É a única quebra deliberada do header preto do Design System, e só funciona por ser
  única** — esta é a única coisa da Home que leva para FORA do CRM. Se outro bloco ganhar cor
  de header, os dois param de destacar. A regra é "um azul na página".

#### 8. Gestor passou a ver tudo

Caiu o `&& ! $eGestor` de `vendaComparacao`, `faturamentoComparacao` e do bloco de segmentos.
O "resumo da equipe ao filtrar" que o diretor pediu **não exigiu código novo** — o
`DashboardScopeResolver` já escopa tudo; bastou deixar de esconder.

⚠️ **`AquecerCacheDashboardJob` PRECISOU passar a aquecer os dois blocos de comparação** (o
próprio job já avisava disso em comentário). Sem isso o primeiro ADM a abrir a Home pagaria a
agregação fria do escopo empresa.

#### 9. `ChaveEscopo::VERSAO` foi de `v2` a `v6` em quatro dias

Cinco bumps, e o histórico completo está no docblock da constante. O que fica de lição:

⚠️ **Mudança de FORMATO de bloco cacheado exige bump, e "renomear campo" é mudança de
formato.** Esqueci duas vezes em 05/09 e o sintoma é sempre o mesmo — card renderizado com os
números faltando, durante os 30 min de TTL, só para quem já estava logado. **Não quebra nada
em vermelho**, então nenhum teste acusa; quem acusa é abrir a página no navegador depois do
deploy.

⚠️ Dois bumps no mesmo dia (v5 e v6, 08/09) foram o custo de mudar de ideia sobre o formato
com o bloco já no ar. É mais barato que o card mutilado.

#### 10. Achados de performance e correções de dado (Regras nº 6 e nº 9)

| O quê | Antes | Depois |
|---|---:|---:|
| Potencial com família, escopo empresa | **41,5 s** | descartado |
| Segmentos × inativos, escopo empresa | — | **261 ms** |
| Segmentos × inativos, escopo vendedor | — | **39 ms** |
| Série diária do mês, qualquer escopo | — | **3-38 ms** |

Bugs de dado corrigidos no caminho, todos encontrados por auditoria e não por teste:

- **Dupla contagem na carteira** (178 contra 172): agrupar por segmento contava duas vezes o
  código atendido por mais de um segmento. Resolvido com uma derivada `carteiraPorCodigo()`.
- **`MAX(NULL = 'BOBINA')` é NULL**, não 0 — clientes com produto órfão sumiam da lista.
  `COALESCE(..., 0)`.
- **130 divergências numa auditoria**: `codigosSemFamilia()` não fazia join com a carteira, e
  o link listava clientes que já tinham saído do vendedor.
- **`max(0, …)` era código morto** — a mutação mostrou que removê-lo não quebrava nada.
  Trocado por um teste que morde.
- **Link "não funciona"**: funcionava; o alvo tinha 60×21px. Virou 213×44px (7,4× maior).

#### Lições de processo desta rodada

- ⚠️ **Três defeitos só apareceram no navegador**, nenhum pegável por teste de servidor: o
  card mutilado pelo cache velho (duas vezes), o `only_full_group_by` recusando `GROUP BY`
  por alias, e o alvo de clique pequeno demais.
- ⚠️ **A suíte roda contra um `palma_v2_test` único nesta máquina.** Em 08/09 uma sessão
  paralela do Tony estava editando a MESMA pasta (`FrescorDoDado`, `/atualizacoes`) e o dev
  local chegou a responder 500 no meio de uma edição de lá. Não é bug: é working tree
  compartilhado. **Commitar só os próprios arquivos**, nunca `git add -A`. → foi daqui que
  nasceu a **Regra de ouro nº 10** (sessão paralela em worktree própria), no topo deste
  arquivo.
- ⚠️ **`infra/deploy.sh` puxa a branch inteira.** Um deploy meu levou junto o commit
  `87f6cc9` do Tony, feito de outra janela. Não foi problema — era um fix que ele queria em
  produção —, mas **conferir `git log` antes de deployar** quando há mais de uma sessão
  aberta.

Suíte inteira verde ao fim: **415 testes**.

### O status do pedido deixou de ser constante — 2026-09-09

A coluna Status de `/pedidos-abertos` mostrava **"Aguardando classificação do TOTVS" em
100% das linhas**. O Tony sugeriu escondê-la ("se não tivermos nada a mostrar talvez seja
melhor não mostrar nada") e, medindo, a sugestão estava certa sobre o sintoma e o dado
existia o tempo todo — sendo jogado fora no import.

**O diagnóstico, conferido no banco de PRODUÇÃO:** 3.478 pedidos em aberto, todos
`pendente_totvs`; 88.486 faturados, todos `faturado`. `status` era uma função de
`data_faturamento IS NULL`, ao lado de colunas que já diziam isso. Os outros quatro
valores do enum (`separacao`/`bloqueio`/`wms`/`liberado`) tinham **zero linhas** — só o
seeder os escrevia. E o filtro oferecia 6 opções das quais **5 devolviam tela vazia**.

**⚠️ A documentação afirmava que o `HISTORICO` era "texto livre sem padrão", e estava
errada.** Contados os moldes no arquivo real, o texto é livre só na CAUDA: 11 frases-molde
cobrem **99,86%** dos 3.478 pedidos (`INCLUIDO NA CARGA` 44%, `COM BLOQUEIO DE ESTOQUE`
29,5%, `LIBERADO PARA MONTAGEM DE CARGA` 12%, `ENVIO PARA O WMS` 7,2%…). O campo estava
preenchido em 100% das linhas e o import **nem o lia** — não estava sequer em
`exigirColunas`.

Detalhe completo em `docs/importacao-dados-legado.md` §8.3. Aqui fica o que muda decisão:

- **`App\Services\Pedidos\StatusPedidoResolver`** é o único lugar que traduz. Também é o
  dono da LISTA de status e dos RÓTULOS — o front não tem cópia de mapa nenhum: o servidor
  manda `statusRotulo` pronto e `constants/pedidos.js` guarda só a COR. Antes o rótulo
  vivia em duas cópias, e a do front já tinha ficado para trás uma vez (Regra nº 8).
- ⚠️ **A sequência do processo veio do Tony, não dos nomes**: *"incluído na carga significa
  que montaram a carga, o próximo estágio é a separação"*. `em_carga` vem ANTES de
  `separacao`; a leitura ingênua inverteria os dois. Há teste travando isso.
- ⚠️ **Bloqueio é separado por MOTIVO** (estoque / crédito / arte / rejeição de crédito),
  decisão do Tony: a ação é diferente em cada caso — PCP, financeiro, artes. É **um terço
  da carteira de pedidos em aberto** travada por motivo acionável, que antes era invisível.
- ⚠️ **O que não é reconhecido NÃO vira etapa chutada.** A pill some da tela e o texto cru
  fica em `pedidos.historico_totvs`, visível ao expandir a linha, com a data do movimento.
  Foi assim que o pedido do Tony ("não mostrar nada") continuou valendo para os 0,14% em
  que realmente não há o que mostrar. Classificar nunca destrói informação.
- 🚨 **A defesa contra o TOTVS mudar a redação é o AVISO DO IMPORT, não o resolver.** Se
  "COM BLOQUEIO DE ESTOQUE" virar outra frase, mil pedidos perdem a pill e **nada quebra em
  vermelho** — nenhum teste falha, nenhum alarme dispara. Quem denuncia é o bloco que
  `totvs:import-pedidos-abertos` imprime no fim, com contagem e exemplos. Se esse bloco
  sair de lá, a feature passa a degradar em silêncio. É o mesmo formato da fila parada de
  29/08 e da badge "0 online" de 31/08: **dado que some não acende luz vermelha.**
- ⚠️ **Por que isto não repete a gambiarra do legado** (que derivava status do mesmo texto):
  lá era regex genérica (`BLOQ|CANCEL` contra `LIBER|FATUR`) espalhada pelo JS do front, e
  "PEDIDO FATURADO POR PEDIDO NA NF - WMS" casava em duas regras ao mesmo tempo. Aqui é um
  lugar só, cada molde é uma frase específica, e **os moldes são mutuamente exclusivos** —
  travado por teste, e é a invariante que importa.
- ⚠️ **A ordem da lista de MOLDES não decide nada** (ao contrário do que a primeira versão
  do código afirmava). Descoberto por mutação: inverter a lista mantinha o teste verde.
  Encurtar um molde para algo genérico é que quebra a exclusividade — e aí a ordem passa a
  decidir em silêncio.
- **`pendente_totvs` continua no enum**, com significado novo: era "ninguém classificou
  ainda", virou "o CRM não reconheceu este movimento". Manter a string evitou um UPDATE em
  massa nas 3.478 linhas de produção que não compraria nada.
- **A ação com o Adriano segue de pé mas deixou de ser bloqueante**: código estruturado no
  relatório continua sendo melhor que ler frase. Hoje 99,86% já têm etapa.

**⚠️ Lição de teste que se confirmou de novo: cinco dos meus testes passavam com o código
quebrado.** Oito mutações foram aplicadas de propósito (ordem dos moldes, molde genérico,
rótulo cru, import não gravando o texto, filtro sem whitelist, fallback chutando etapa).
Sete morderam; **a oitava não**, e foi ela que revelou que meu próprio comentário sobre a
ordem da lista estava errado. O teste falso foi substituído por um de exclusividade mútua,
que morde. Verificar por mutação não é zelo — foi o que corrigiu a documentação.

Suíte inteira verde: **488 testes**.

### Central de downloads: toda planilha registra, e o volume decide o caminho — 2026-09-09

Pedido do Tony: *"as planilhas ficam disponíveis por 7 dias mas não tem lugar nenhum pra
acessar depois que já saiu a notificação"*. Estava certo, e o buraco era maior que isso.

**O diagnóstico:** existiam duas realidades. A Carteira gerava em fila, guardava em
`exportacoes` e avisava pelo sino — mas o ÚNICO ponteiro para o arquivo era essa
notificação, que some ao ser lida; depois disso a planilha ficava mais 7 dias no disco
inalcançável. As outras **oito** exportações nem registro tinham: `Excel::download()`
direto, arquivo na pasta do navegador de quem clicou, nada no servidor.

**`/exportacoes` ("Meus downloads", no menu do usuário)** lista as planilhas da própria
pessoa com estado, filtros do pedido, linhas, tamanho, prazo e botão de baixar.

#### 🥇 O achado que mudou o escopo: três exportações já violavam a Regra nº 9

Ao medir para decidir o desenho, apareceu o que ninguém tinha medido (Regra de ouro nº 6):

| Planilha | Linhas | Geração |
|---|---:|---:|
| Tabela de preços | 26.989 | **18,7 s** |
| Leads | 17.173 | **14,1 s** |
| Pedidos em aberto | 3.478 | **2,6 s** |
| Orçamentos | 1.864 | 1,4 s |
| Equipe / Metas | ~130 | ~0,2 s |

⚠️ **Lentidão que não estoura não vira chamado.** Só a Carteira tinha sido tratada, porque
só ela dava erro visível (504 do ALB aos 60 s). As outras apenas travavam a aba por 15-19 s
— e o usuário aprende a não clicar no botão, o que ninguém reporta.

**Por isso o caminho passou a ser escolhido pelo VOLUME, não pela tela**
(`config('exportacoes.limite_linhas_sincrono')`, 2.500 linhas): até lá baixa na hora, acima
vai para a fila e avisa pelo sino. A mesma Carteira leva ~95 s para um admin (92 mil
clientes) e menos de 1 s para um vendedor com 283 — a prop fixa `assincrono` por página só
podia acertar um dos dois casos.

⚠️ **O primeiro corte foi 5.000 e estava errado**: a 0,75 ms/linha daria 3,7 s de aba
travada, dentro do limite e fora do orçamento. Só apareceu medindo a GERAÇÃO, não estimando.
Números completos em `docs/performance.md` §1.18.

#### Onde cada decisão mora (Regra de ouro nº 8)

| O que se repete | Onde mora |
|---|---|
| Como montar cada planilha (query + export + nome) | `CatalogoDeExportacoes` |
| Autorização de cada exportação | o mesmo catálogo |
| Registrar, gerar, expirar, notificar | `GeradorDeExportacao` |
| Prazo de validade e corte de volume | `config/exportacoes.php` |
| Rótulo de cada recurso | `CatalogoDeExportacoes::RECURSOS` |
| A resposta ao clique (pronta / fila / erro) | `ExportaPlanilha::entregarPlanilha()` |

- ⚠️ **O catálogo existe porque a mesma planilha é montada em DOIS contextos** — a
  requisição e o job. Com a montagem no controller, o job carrega uma segunda cópia da
  regra, e `GerarExportacaoCarteiraJob` já era essa segunda cópia: um filtro novo na tela
  deixaria o Excel para trás em silêncio.
- ⚠️ **A autorização mora no catálogo, não no controller**, pelo mesmo motivo: precisa valer
  nos dois caminhos, senão a fila vira porta lateral. Como o plano é sempre montado dentro
  da requisição (é ele que conta as linhas), o 403 continua saindo na hora do clique.
- Nove métodos de query viraram **públicos** para o catálogo chamá-los — cada um com o
  docblock dizendo por quê. É o mesmo motivo de `CarteiraController::listaQuery` já ser.
- **O arquivo é SEMPRE escrito no disco, mesmo no caminho síncrono**, e a resposta é o link.
  Streamar direto seria uma linha a menos, mas o arquivo não existiria depois — e "existir
  depois" é a feature inteira. De quebra, todo download sai por um ponto só, com uma
  checagem de dono só.
- ⚠️ **Todo endpoint de exportação virou POST** (oito eram GET): pedir uma planilha CRIA um
  registro e pode enfileirar um job. Como GET, um prefetch do navegador geraria arquivo
  sozinho. Travado por teste (405 no GET).
- ⚠️ **O botão não sabe qual caminho vai acontecer** e não deve saber: faz sempre a mesma
  requisição Inertia e reage ao flash `exportacao` (`pronta` dispara o download,
  `enfileirada` abre o aviso, `erro` avisa). A prop `assincrono` foi removida.
- **O caminho síncrono NÃO notifica**: o arquivo já desceu no navegador, e um sino dizendo
  "está pronto aquilo que você acabou de baixar" é ruído — ruído é o que faz as pessoas
  pararem de olhar o sino. Travado por teste.

#### 🔴 Dois defeitos silenciosos corrigidos no caminho

**1. O supervisor em "Minha carteira" recebia a planilha da EQUIPE INTEIRA.** O job
reconstrói a query com uma Request sintética e **sem sessão** — e o modo Equipe/Pessoal mora
exatamente na sessão, com `ModoVisao::atual()` devolvendo EQUIPE fora de HTTP (de propósito,
por causa do aquecimento de cache). Escopo mais amplo que o da tela, num arquivo com a base
de clientes de outras pessoas, sem erro nenhum aparecer. Corrigido com a coluna
`exportacoes.modo_visao` + restauração do modo no gerador, via sessão sintética.

⚠️ **O teste disso passava com o bug.** Rodando no mesmo processo, ele enxergava a sessão
que a requisição anterior deixou no container e acertava por acidente. Só passou a morder
com um `session()->flush()` antes de invocar o job — que é o que o worker realmente tem.
**Descoberto por mutação**, não por leitura.

**2. Exportação órfã ficava "Preparando" para sempre.** Aconteceu durante esta própria
sessão: o container `queue` morreu com `ProcessTimedOutException` (o timeout de 700 s do
`queue:listen`, já conhecido) e, como o Redis de dev não persiste, o job evaporou junto.
⚠️ **O `failed()` do job não cobre esse caso** — ele só roda se o worker estiver vivo para
chamá-lo. A tela passou a mostrar "Interrompida" pela idade (`Exportacao::travou()`, 60 min)
e o `ExpurgarExportacoesJob` corrige o estado no banco. Mesma família da fila parada de
29/08 e da badge "0 online" de 31/08: **dado que some não acende luz vermelha.**

#### Relação com o fix do sino de 2026-09-08

O commit `7d02bed` (feito no dia anterior, de outra janela) corrigiu o clique na
notificação de planilha, que abria pelo Inertia e descartava o `.xlsx` em silêncio. Ele
diagnosticou o mesmo buraco por outro lado — *"não existe tela de exportações para
reencontrá-lo"* — e as duas peças são **complementares, não redundantes**: o sino é o
atalho de quem está com a notificação na frente; a central é onde se reencontra a planilha
depois. Os três testes dele foram portados para `CentralDeDownloadsTest`.

⚠️ **`Notificacao::TIPOS_DOWNLOAD` contém só `exportacao_pronta`, e tem que continuar
assim.** A notificação de ERRO aponta para a central (uma página HTML): se alguém a
incluir ali, o clique passa a tentar baixar a página. Travado por teste.

#### Detalhes que vão surpreender depois

- **`GerarExportacaoCarteiraJob` virou um shim `@deprecated`** que só delega. Existe para
  sobreviver ao deploy: um job de exportação vive minutos na fila, e a classe sumindo faria
  o payload em voo cair em `failed_jobs` — o usuário esperando para sempre. Pode ser apagado
  algumas horas depois de subir.
- **Cadastros entra como quatro recursos** (`cadastros-bobina`, `-etiqueta`, `-cliente`,
  `-lead`), não um só: cada aba gera colunas diferentes, e quem exportar duas no mesmo dia
  precisa distinguir os arquivos.
- **`ordenar` não aparece nos filtros da central**: muda a ordem, não quais linhas entram. A
  coluna existe para responder "por que este arquivo tem 300 linhas?", e `Ordenação: nome_asc`
  só acrescentava jargão a uma resposta que era, corretamente, "base completa".
- **A linha vencida continua na lista**, sem o botão. Esconder o histórico faria a pessoa
  achar que nunca exportou aquilo e gerar de novo.
- **A tela se recarrega sozinha só enquanto há planilha em preparo** (mesmo desenho de
  `/atualizacoes`).
- `LIMITE_LINHAS_SINCRONO = 20000` era uma constante **declarada no trait e nunca usada por
  ninguém**. Virou o config, agora com número medido.

#### Testes

`tests/Feature/CentralDeDownloadsTest.php` (18 casos): volume decidindo o caminho nos dois
sentidos, síncrono não notificando, job gerando/notificando/falhando, modo de visão
atravessando a fila, download só do dono (nem admin), expirada e processando não baixáveis,
listagem escopada, rótulo e filtros vindos prontos do servidor, órfã aparecendo como
interrompida e sendo fechada pelo expurgo, autorização de Equipe/Metas valendo no catálogo,
e o GET recusado com 405.

⚠️ **Sete mutações aplicadas de propósito; a primeira rodada revelou um teste falso** (o do
modo de visão). Verificar por mutação continua sendo o que separa teste de decoração.

Suíte inteira verde: **503 testes**.

## Pendências
- 🟡 **Integração "orçamento vira pedido" no Portal Autopel — CONSTRUÍDA em 2026-09-10,
  falta só dado no de-para para homologar.** **Análise, mapa e armadilhas em
  `docs/integracao-portal-pedidos.md`** — ler de lá antes de encostar no assunto; o PDF
  original está em `docs/API-Pedidos-Autopel.pdf`.
  - **O que existe**: botão "Transformar em pedido" em `/orcamentos` (só em orçamento
    **aprovado**) → `POST /orcamentos/{id}/portal` → `GeradorDePedidoNoPortal` →
    `EnviarPedidoAoPortalJob`. Config em `config/portal.php`, 4 tabelas de espelho
    `portal_*`, e `orcamentos` ganhou `cliente_id` + `portal_*`. 30 testes,
    **verificados por mutação** (5 mutações, todas mordidas).
  - 🚧 **Restrito a ADMIN durante a homologação** (decisão do Tony, 10/09). Dono e diretor
    veem o botão desabilitado com "em breve". **Para liberar**: em
    `OrcamentoController::podeEnviarAoPortal()`, trocar o `=== 'admin'` pela linha
    comentada logo acima. A restrição guarda a ROTA, não só o botão.
  - ⚠️ **O interruptor mestre `PORTAL_PEDIDOS_HABILITADO` nasce DESLIGADO**, e a rota
    responde 404 quando desligado. Ligar por engano cria pedido de verdade no Portal.
  - ⚠️ **A divisão em dois tempos é o desenho, não detalhe**: de-para e montagem do
    payload rodam DENTRO da requisição (é onde o vendedor precisa ver "falta o
    representante"); só a chamada HTTP vai para a fila, porque sozinha custa ~500 ms e
    estouraria o orçamento de escrita da Regra nº 9.
  - ⚠️ **O payload é congelado em `orcamentos.portal_payload`.** O contrato de
    idempotência deles é "reenvie a requisição IDÊNTICA com a mesma chave" — remontar o
    corpo na retentativa faria uma edição no meio virar 409 sem motivo aparente.
  - ⚠️ **Erro do Portal notifica TODA vez; sucesso só uma.** O `NotificacaoService`
    deduplica por `referenciaTipo`+`referenciaId`; usar isso no erro deixaria a segunda
    falha MUDA e o vendedor concluiria que deu certo. Travado por teste.
  - 🔴 **Falta para homologar: as tabelas `portal_*` estão VAZIAS.** Não há token do
    integrador nem endpoint de resolução, então a carga inicial é manual. O que o Marcelo
    precisa mandar está na §4.5 do doc: uma tripla válida do homolog (`createdBy`,
    `clientId`, o `clientRepresentativeId` que pertence a esse cliente) e um `productId`.
  - 🟢 **O DE-PARA FOI ENCONTRADO em 2026-09-10 — e o Portal é o `sic`.** A API identifica
    tudo por id interno e não tem endpoint de listagem, mas o schema foi lido pela página
    **`/descoberta`** do próprio app do Lovable (que é um explorador de metadados sobre a
    Integrador API — não precisou do token do nosso lado). O mapa completo, com amostra
    real e as armadilhas, está na **§4.3 do doc**. Resumo: `clients` casa por
    **`code` + `store`** (↔ `cod_cliente` + `loja`), `products` por **`code`**, `users`
    por **`email`/`protheus_seller_code`**.
    - ⚠️ **NÃO usar `autopel_code` nem `external_id`**: `clients` tem três colunas
      parecidas com código e duas com loja; `autopel_code` é nulo em parte das linhas.
    - ✅ **Verificado contra o `palma_v2`** (§4.4 do doc): os 9 pares da amostra existem
      aqui e **8 batem** com CNPJ e razão social idênticos. **Um divergiu**: o par
      `000001/0001` aponta para empresas DIFERENTES nos dois lados.
    - 🚨 **Daí a regra obrigatória: o de-para NUNCA confia só em `code`+`store`.** Um
      `clientId` errado cria pedido para a empresa errada, e o `201` volta bonito — nada
      no CRM acusaria. Conferir SEMPRE o CNPJ (`clients.document` × dígitos de
      `clientes.cnpj`); divergiu, **recusa o envio e sinaliza**. Mesmo para produto.
    - ⚠️ **`clients.seller_one` NÃO espelha o nosso `cod_vendedor`** — formato igual,
      valores divergentes em 3 dos 9 casos. Não usar para nada; o vendedor sai de
      `users.email`/`protheus_seller_code`.
  - 🟢 **Mas existe um caminho já no ar, achado no mesmo dia: a `api-integrador.autopel.com`**
    (zip em `docs/autopel-integrador-api-*.zip`, feita para o projeto do Lovable). É uma API
    **somente leitura** com metadados e `SELECT` parametrizado sobre **quatro bancos MySQL —
    `sac`, `b2b`, `easy`, `sic`** —, confirmados de pé por chamada real ao `/health` em
    2026-09-09. Se o banco do Portal for um deles (`sic` é o candidato: o pedido "passa pelo
    SAC/SIC depois pro TOTVS"), o de-para sai de lá sem eles construírem nada. **Falta o
    `API_TOKEN`** — sem ele só o `/health` responde. Ver §4.2 do doc.
    - ⚠️ **Isso não substitui pedir os endpoints de resolução (§4.1).** Ler as tabelas
      deles por HTTP é o mesmo acoplamento de schema que eles quiseram evitar, só que por
      outro transporte — serve para destravar e descobrir, não para virar produção sem
      combinar. E o token é **único e global**: quem o tem lê os quatro bancos inteiros.
    - 🔎 **Achado colateral que pode valer mais**: a view `sac.sacautopel.ConsultaBasePedidos`
      tem **status estruturado** (`status`, `data_bip`, `data_embarque`, `previsao_entrega`,
      `nota_fiscal`) e até uma timeline pronta. É candidata a substituir a leitura de frases
      do `HISTORICO` (`StatusPedidoResolver`, 09/09) e a ser a fonte do histórico de pedidos
      emitidos que está pendente. ⚠️ Esbarra na Regra de ouro nº 2 — decisão do Tony.
  - ⚠️ **Duas armadilhas que a própria documentação deles sinaliza**: `unitPrice` é em
    **centavos** e mandar reais **não dá erro** (grava R$ 0,12 no lugar de R$ 12,50); e
    retentar com `Idempotency-Key` NOVA é o único caminho que ainda duplica pedido — a
    chave tem que nascer persistida no orçamento, nunca ser gerada na hora do envio.
  - ⚠️ **Pergunta em aberto que muda o valor do pedido**: o CRM guarda `valor_unitario` com
    o IPI de 3,25% embutido e a API não tem campo de IPI. Confirmar com eles qual valor vai
    no `unitPrice` **antes** do primeiro envio real. Desde 10/09 sabe-se que
    `autopel_sic.products` tem `ipi`/`ipi_rate`/`ncm` — o Portal calcula imposto sozinho,
    então provavelmente é SEM IPI. **Provável não basta**: errar custa 3,25% por pedido.
  - ✅ **`clientRepresentativeId` respondido pelo schema**: é a pessoa da **Autopel**
    (`clients_representatives.user_id` → `users.id`), não contato do cliente. ⚠️ Se o
    vendedor não estiver cadastrado como representante daquele cliente no Portal, a API
    recusa com 404 — cadastro do lado deles, mas aparece como "não deixa enviar" na
    nossa tela.
  - ⚠️ O token de homologação veio por WhatsApp em texto puro: serve para homologar, mas
    pedir outro para produção. Nunca versionar — mora no `.env`, lido por `config()`.
- 🔴 **As metas de VENDA em produção são, na maioria, lixo de seed.** Conferido no RDS em
  2026-09-04, logo após o deploy: `metas_mensais` só tem os meses **8 a 12** (nada de
  janeiro a julho), e as metas de venda valem **R$ 1.874 (ago), R$ 1.817 (out), R$ 1.930
  (nov), R$ 1.892 (dez)** — exatamente a faixa 500-4.000 do `MetaMensalSeeder` antigo, que
  gerava escala de *quantidade de pedidos*. Só **setembro** tem valor plausível
  (R$ 5,13 mi). Como a aba Venda virou a PADRÃO do gauge em 2026-09-04, esse lixo é a
  primeira coisa que o vendedor vê: o acumulado do ano marca **485,7%**, comparando
  R$ 24,9 mi de realizado contra uma meta de um mês só. O gauge está certo; a meta é que
  não existe. **Cadastrar pelo `/metas`** (campo "Meta venda / pedidos emitidos (R$)",
  admin ou diretor) — não há como o sistema distinguir seed de meta real sozinho. As metas
  de faturamento estão melhores mas também só cobrem ago-dez.
- ~~🔴 **Sincronização de dados parada desde 2026-08-10.**~~ **Resolvida em 2026-09-04.**
  O diagnóstico da linha antiga ("import automático travado no Adriano") estava errado: os
  importadores `totvs:import-*` e a ponte S3 já existiam e estavam deployados nos dois nós,
  e os CSVs estavam no bucket desde 03/09 — faltava `TOTVS_RELATORIOS_DIR` no `.env`, a
  pasta `storage/app/totvs`, e **qualquer agendamento chamando aquilo**. Hoje
  `totvs:atualizar` roda de hora em hora no cron da app-2.
  - ⚠️ **Não perguntar "até que data está o dado" para este arquivo — ele envelhece.**
    A resposta viva está na tela admin-only **`/atualizacoes`** (menu do usuário): idade
    do dado, inventário do S3, histórico das rodadas e botão de disparo. Qualquer número
    escrito aqui estará errado no dia seguinte.
  - **O fluxo do Tony é UM passo: gerar o relatório no TOTVS e salvar na pasta.** O envio
    para o S3 é automático (vigia na Inicialização do Windows, a cada 5 min) e o import
    também (cron horário). Detalhe completo em `docs/importacao-dados-legado.md` §10 — a
    tela é a §10.6, a automação do envio é a §10.7.
  - ⚠️ **`FAT` e `Pedidos emitidos` são RECORTE**: o import apaga só a faixa de datas que
    está dentro do arquivo e repõe. Gerar sempre o **mês corrente inteiro**, não só os
    dias novos — assim uma falha de um dia se conserta sozinha na subida seguinte.
  - ⚠️ **A lição que fica: dado velho não acende luz vermelha.** Os 12 alarmes ficaram
    verdes o mês inteiro — CPU, ALB e 5xx não sabem que a última nota fiscal é de trinta
    dias atrás. Mesmo formato da fila parada de 29/08 e da badge "0 online" de 31/08. Não
    existe alarme de frescor de dado neste sistema; se um número precisa estar fresco,
    alguém tem que medir a idade dele. **Vale criar essa métrica** (é o mesmo caminho do
    `metricas:publicar`, que já publica idade do aquecimento de cache).
- **Carregar o histórico de pedidos emitidos** — **março a setembro/2026 já entraram**
  (04 e 05/09; de 15.523 para 69.454 pedidos e 905.228 itens). Falta **janeiro e
  fevereiro/2026** e **2025 inteiro**, este último o que a aba Venda do painel precisa para
  comparar ano vs. ano. ⚠️ **Desde a regra dos 180 dias (2026-09-08) a coluna 2025 marca
  R$ 0, não mais R$ 198 mil**: o pouco que havia era resíduo de pedido eternamente em
  aberto, que deixou de contar como venda. O card declara isso na tela, e o aviso some
  sozinho quando o import entrar. O material existe no legado
  (`pedidos_status`, 407.604 linhas). ⚠️ Carga
  histórica **antes** de criar índice, nunca depois (a lição de 2026-08-31 nos faturamentos,
  10m40s contra 66s) — os covering index de `pedidos` já estão criados, então pesar se vale
  dropá-los durante a carga.
- **Fechar com a diretoria a regra de FAMÍLIA DE PRODUTO** (bobina / etiqueta / tag de
  gôndola) e decidir se o quadro de Potencial volta a ter colunas por família. O back-end
  está pronto e testado desde 2026-09-05 (`FamiliaProduto`, `PotencialCarteiraResolver`,
  `potencial_pesos`, `potencial:importar-pesos`), só não é usado na Home. ⚠️ **Se voltar,
  não voltar pelo mesmo caminho**: cruzar família com `faturamentos` custou **41,5 s** no
  escopo empresa e é o que inviabilizou a primeira versão — precisa de tabela de apoio ou
  recorte de escopo. ⚠️ Enquanto isso, o filtro `?sem_familia=` da Carteira está
  **inalcançável pela interface** (nenhum link aponta para ele).
- **Não há tela para editar `segmentos.peso_potencial`.** Os pesos da diretoria (08/09/2026)
  vivem na migration `2026_09_08_100000` e no `SegmentoSeeder`, então peso novo hoje é
  deploy. Se a diretoria passar a revisar isso com frequência, vale uma tela admin nos
  moldes de `/orcamentos/materia-prima`.
- **Preencher `orcamentos.lead_id` retroativamente**, se fizer falta. A coluna existe desde
  2026-09-03 mas só é preenchida em orçamento novo; os históricos não têm vínculo com lead.
- **O funil não tem relatório de conversão.** `etapa_alterada_em` responde "parado há X
  dias", mas não "onde os leads morrem" — isso exigiria a tabela de histórico de etapa que
  ficou fora de escopo por decisão (2026-09-03). Reabrir só se a pergunta aparecer no uso.
- Avaliar coluna "Último contato" no export Excel da Carteira (ficou de fora em 2026-09-02; ficou barato depois da desnormalização — é só mais uma coluna de `clientes`).
- ~~Adicionar campo "tubete obrigatório" no `CadastroBobinaForm.vue`.~~ **Feito** — o rádio
  Sim/Não e o diâmetro estão no formulário, e a solicitação real de 01/09 tem `'nao'`
  gravado (escolha do usuário, não default). Conferido na auditoria de 2026-09-05; a
  pendência tinha ficado marcada como aberta depois de já ter sido resolvida.
- Popular `etiquetas_materia_prima` com dados reais de custo (Tony faz pela tela `/orcamentos/materia-prima`).
- Revisitar a fórmula de "margem bruta %" da calculadora de etiqueta se o quirk herdado do legado (unidade por-etiqueta vs. custo-do-rolo) incomodar no uso real.
- Dropar tabela órfã `leads_manuais` + model `LeadManual` (ver revisão 2026-07-28 acima).
- Expandir detalhes do cliente (filtros de pedidos, itens por linha) se precisar — o básico (KPIs + lista) já está.
- Traduzir `lang/pt_BR/validation.php`.
- ~~`APP_DEBUG=false` antes de qualquer deploy real.~~ Aplicado e conferido em produção em 2026-08-28 (404 sai limpo, sem stack trace).
- ~~🔴 **BLOQUEADOR DE BETA — `ImportUsuariosLegado` reseta a senha de quem já existe.**~~ **Resolvido em 2026-08-27.** Trocado por `firstOrNew` + bloco de *primeira carga*. Auditando o resto do comando, o problema era maior que a senha: o import sobrescrevia **tudo** a cada rodada, inclusive o que o próprio usuário edita no CRM — `display_name` e `telefone` (via `ProfileUpdateRequest`) e `foto_perfil` (via `ProfileController::updateFoto`). Com beta testers, cada reimportação apagaria a foto e o nome de exibição de todo mundo. Também saíram do update `last_login_at`/`last_activity_at`: quem escreve isso é o login no CRM-V2, e o valor do legado é o login no sistema *antigo*. Continuam vindo do legado (que é dono): `name`, `username`, `tipo_usuario`, `is_active`, `estado` e as cores de UI. Os outros 5 imports já eram seguros (`import-leads` deleta só `origem = 'sistema'`; clientes/produtos usam `upsert`; faturamento/pedidos são espelho puro).
  - ⚠️ **Fica um risco não resolvido**: a chave do import é o `email`, e o usuário pode trocar o próprio e-mail em `/profile`. Se um beta tester fizer isso, a próxima importação não o reconhece e **cria um usuário duplicado**. Resolver quando incomodar — provavelmente chaveando por `username`, que o CRM não deixa editar.
- **Deploy AWS: FEITO em 2026-08-28** — o sistema está no ar em `https://crm.autopel.online` com dados reais (91.293 clientes, 1.032.099 faturamentos, 201 usuários) e latência de 83-204 ms nas páginas core, dentro do orçamento da Regra nº 9. Provisionado por SSH via `infra/provisionar-servidor.sh`, `infra/deploy.sh` e `infra/configurar-daemons.sh`; S3 por IAM role (`infra/iam/`). **Estado atual, endereços reais e armadilhas: `docs/deploy-aws.md` §0.0 e §9.11-9.17.**
  - ~~**Falta para abrir o beta**: alarmes, testes 7.5/7.9 e `MAIL_MAILER`.~~ **Tudo feito em 2026-08-29** — 12 alarmes com entrega comprovada, 7.5 (5/5) e 7.9 (6/6) passando, SMTP ligado. ~~O que sobrou é decisão, não trabalho: esvaziar `CADASTROS_REDIRECIONAR_PARA` para os e-mails chegarem aos setores.~~ **Esvaziado em 2026-08-31** — ver a seção "Beta liberado" acima.
  - ⚠️ **Lição que vale além do deploy**: quatro defeitos apareceram no caminho e três eram o mesmo erro — *verificação que não percorre o caminho real*. O pior: este guia marcava "uploads no S3 ✅ feito em 27/08" e o pacote `league/flysystem-aws-s3-v3` nunca tinha sido instalado — marcado como pronto **por inspeção de código, não por execução**. Antes de marcar qualquer coisa ✅, perguntar se a verificação passa pelo mesmo código, prefixo, header e credencial que a produção usa.
- **Performance: ver `docs/performance.md`** (Regra de ouro nº 9) — catálogo do que é caro e do que é barato, com checklist de produção. ⚠️ **A lista de pendências mora lá, não aqui**: a cópia que existia nesta linha ficou desatualizada (listava Redis, cache warming e export em fila como pendentes muito depois de estarem prontos) e mandava otimizar coisa já otimizada. Regra de ouro nº 8 — decisão que se repete mora em um lugar só. Medição real em produção (2026-08-28): páginas core entre **83 e 204 ms**, dentro do orçamento de 400 ms.
- ~~**Validar `ini_set(memory_limit/max_execution_time)` dos exports Excel no pool PHP-FPM real**~~ **Validado em produção em 2026-08-28**: `ini_set` sobe o `memory_limit` de 512M para 1024M e `set_time_limit` vai de 60 para 300, via FPM. Funciona porque o `infra/provisionar-servidor.sh` põe esses valores num `.ini` normal — **se algum dia virarem `php_admin_value` no pool, os exports voltam a dar 500 silencioso**.
- ~~Revisitar `GrpVendas` no redesenho de banco.~~ Feito em 2026-08-10 — virou `clientes.cod_grupo` + `grupos_cliente`, ver seção "Grupo de cliente + densidade das tabelas".
- ~~E-mail transacional (planejado, não feito): AWS SES + PHPMailer via `antonio.barbosa@autopel.com`, notificando Cadastros e PCP.~~ **Feito em 2026-08-28** — ver seção "E-mail transacional de Cadastros" abaixo. Não foi AWS SES: Tony recebeu credenciais de um SMTP relay dedicado (`smtplw.com.br`) e usamos isso via mailer `smtp` nativo do Laravel.
- **Importação de dado real**: concluída pra todos os domínios comerciais — `clientes`, `faturamentos`, `pedidos`+`pedido_itens`, `leads`, `produtos` (rotina recorrente) e `orcamentos`+`orcamento_itens` (migração pontual, `legado:import-orcamentos-historico`, 1.885 orçamentos históricos — não roda de novo, orçamento novo nasce direto na tela). Detalhe completo (mapeamento de coluna por domínio, achados de performance, decisões de escopo) em `docs/importacao-dados-legado.md`.
- **Ação fora do CRM-V2, já não bloqueante**: pedir pro Adriano incluir um código de status estruturado no relatório "Pedidos em Aberto com Status" do TOTVS. ⚠️ **Deixou de ser urgente em 2026-09-09**: o `HISTORICO` acabou se revelando bem mais estruturado do que esta linha supunha, e 99,86% dos pedidos em aberto já ganham etapa por ele (`StatusPedidoResolver`). Um código de verdade continua sendo melhor que ler frase — quando existir, o resolver passa a lê-lo e os moldes viram fallback. Ver `docs/importacao-dados-legado.md` §8.3.
- Considerar cachear a agregação de aderência (`CarteiraAderenciaResolver`) também dentro do `CarteiraController::index()` — é o próximo alvo se `/carteira`/`/equipe` ainda incomodarem sob carga real (ver seção "Ambiente Docker de teste de carga" acima; o mesmo fix já foi aplicado no Dashboard).
