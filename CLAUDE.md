# PALMA CRM v2 — Contexto do Projeto

> Este arquivo é carregado automaticamente pelo Claude Code quando se trabalha nesta pasta.
> É versionado no git, então viaja com o repositório. Mantenha atualizado conforme o projeto evolui.

## Quem toca o projeto
Tony (Antonio Barbosa), desenvolvedor solo na Autopel Soluções (suprimentos corporativos — bobinas térmicas, etiquetas, tags, RFID, papel A4), em São Paulo. Autodidata, dono técnico de todo o ecossistema interno (CRM PALMA, sistema de licitações Laravel, dashboards Power BI, integrações TOTVS/Bling/Correios/PagBank). Comunicação direta e informal, em português. Único dev — não assumir que há outro dev pra revisar/pair.

## O que é este projeto
Refatoração completa do **PALMA** (CRM legado em PHP procedural, MySQL, ~200 usuários/dia, 78-92 páginas, 11 perfis) para stack moderna: **Laravel 11 + Inertia.js + Vue 3**. Motivação central: o PHP legado tem problemas sérios de performance; o objetivo do Laravel + AWS é deixar tudo bem mais rápido/"snappy".

- **Legado de referência:** `C:\xampp\htdocs\PLANO-DE-ESCAPE` (é o PALMA atual em produção — código-fonte de `https://gestao-comercial.autopel.com`, deploy fica em `PLANO-DE-ESCAPE\dist\deploy-kinghost`). NÃO é `C:\xampp\htdocs\Site` (snapshot mais antigo/desatualizado do mesmo sistema — usado por engano numa sessão anterior, cuidado ao reusar achados antigos que citem esse caminho) nem `C:\xampp\htdocs\PALMA` (landing estática, não é o sistema).
- **Escopo reduzido:** de ~78-92 páginas pra **~16 páginas core**, focado só em vendedores internos + representantes.
- Hospedagem futura: **AWS via Laravel Forge**. Azure AD fora de escopo (só um hook `azure_id` nullable).

## ⚠️ Duas regras de ouro (confirmadas explicitamente pelo Tony)

1. **Redesenhar > copiar o legado.** Antes de portar qualquer tabela/fluxo/padrão do legado, perguntar: "isso é a forma CERTA de modelar, ou é só como o legado fez por limitação histórica?". Não replicar gambiarra por inércia — o propósito do projeto é justamente corrigir isso. (Não é desculpa pra over-engineering; é não perpetuar decisão ruim.)

2. **CRM-V2 é SÓ comercial.** Não portar perfis/dados/features de **SAC** nem de **Licitação** (cada um tem seu próprio sistema), mesmo que apareçam nos dados reais do legado. Perfis do escopo: vendedor, representante, supervisor, assistente, admin, diretor.

## Stack e convenções
- Backend: **Laravel 11** (travado em v11.55.0, não v12+; o `composer` precisou de `--no-security-blocking` por advisories abertos na branch 11.x — XSS refletido só com `APP_DEBUG=true`, então **`APP_DEBUG=false` é crítico antes de qualquer deploy real**).
- Frontend: **Inertia.js + Vue 3**, scaffold via **Breeze** (stack Vue).
- Permissões: **spatie/laravel-permission** (roles).
- Estilo: Tailwind. Paleta corporativa azul-marinho; ver seção Marca.
- App em **pt_BR** (`APP_LOCALE`). `lang/pt_BR/auth.php` traduzido; `lang/pt_BR/validation.php` ainda NÃO (pendente).
- Estrutura sugerida: `app/Services/` (regras de negócio), `app/Jobs/` (sync TOTVS, notificações), `resources/js/Pages/` (uma por página core), `Components/`, `Layouts/`.

## Banco de dados — ⚠️ isolado de produção
O CRM-V2 **NÃO** conecta no banco de produção do KingHost (`autopel01`), nem pra leitura. Roda 100% num MySQL local do XAMPP, banco **`palma_v2`** (ver `.env`). Os usuários vieram de um **dump pontual** (snapshot único, sem sync ao vivo) da tabela `USUARIOS` do legado — 202 usuários do escopo comercial.

Rodar localmente:
- MySQL do XAMPP precisa estar ligado (`C:\xampp\mysql_start.bat` — não sobe sozinho).
- Servidor: `php artisan serve --port=8000` na raiz do projeto.
- Assets: `npm run build` (ou `npm run dev`).
- Usuário de teste (senha só existe local, resetada manualmente): `antonio.barbosa@autopel.com` / `homolog123`.

### Redesenho da tabela de usuários (feito)
A `USUARIOS` do legado (24 colunas) misturava auth + dados de vendedor + preferências de UI. No v2 foi separado:
- **`users`** — auth/identidade puro (id, name, display_name, username, email, password, tipo_usuario, is_active, last_login_at, last_activity_at + colunas de UI nullable: telefone, estado, foto_perfil, sidebar_color, secondary_color, navbar_template).
- **Roles** via spatie (substitui a coluna `PERFIL` varchar). 6 perfis comerciais seedados (`RoleSeeder`).
- **`vendedor_perfis`** — 1:1 opcional, só quem tem código de vendedor (cod_vendedor, cod_super, cod_gerente, meta_venda, meta_faturamento, segmento, equipe_rep). Corrige tipos ruins do legado (`COD_VENDEDOR`/`COD_SUPER` eram `text`, `COD_GERENTE` era `double`). Obs: `cod_vendedor` NÃO é unique — há casos de código compartilhado entre usuários no legado.
- Em aberto (não bloqueia nada): se esse redesenho substitui a discussão do `GrpVendas` no redesenho mais amplo do banco.

## ⚠️ Regra de ouro nº 3: nunca usar `raiz_cnpj`
Confirmado explicitamente pelo Tony (2026-07-27): `raiz_cnpj` (os 8 primeiros dígitos do CNPJ, usado no legado pra agrupar filiais do mesmo grupo econômico) **não entra em lugar nenhum do CRM-V2**, sem exceção. O legado tem um bug de produção documentado por causa disso (agrupar por raiz_cnpj em vez de `(cod_cliente, loja)` gerou cross-product de 50k+ linhas numa query de carteira). Se precisar agrupar por raiz de CNPJ algum dia, é `LEFT(cnpj, 8)` calculado na query — nunca uma coluna armazenada.

## ⚠️ Regra de ouro nº 4: Carteira/cliente é só leitura no CRM
Confirmado explicitamente pelo Tony (2026-07-27): **sem transferência de carteira, sem edição, sem exclusão de cliente dentro do CRM-V2** — tudo isso é feito no TOTVS e só sincroniza pra cá. A tabela `clientes` é um espelho de leitura (sem soft-delete, sem workflow de reassign, sem tela de editar cadastro). O legado tinha um bug real por causa de um fluxo de transferência mal-feito (rejeitar não revertia a mudança) — não existe mais esse problema porque a feature inteira não entra no v2.

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

**Regra de ouro nº 5: tabelas de dados e botões de ação (confirmado por Tony, 2026-07-27).** Padrão aprovado depois da revisão da tabela de usuários da página Equipe — reusar em toda tabela nova do sistema (referência canônica: `resources/js/Components/Equipe/UsuariosGrupo.vue`):
- **Header da tabela**: fundo `bg-gray-50`, borda inferior mais forte (`border-b-2 border-gray-300`) pra separar claramente do corpo, texto `text-center uppercase text-[0.65rem] tracking-wide text-gray-500`.
- **Células (header e corpo)**: sempre `text-center align-middle` — nunca `text-left`/`align-top`. Divisórias claras entre linha e coluna via `divide-x divide-gray-200` em cada `<tr>` e `divide-y divide-gray-200` no `<tbody>` (não bordas manuais por célula).
- **Linha com hover sutil**: `hover:bg-gray-50/60` no `<tr>` do corpo.
- **Botões de ação** (coluna "Ações"): nunca texto sublinhado. Sempre botões-ícone quadrados `h-7 w-7`, `rounded` (nunca `rounded-lg`), `border border-gray-200`, ícone SVG inline simples (sem lib de ícones, mesmo padrão do `DarkCard`), com cor de destaque só no hover — uma cor por tipo de ação (ex.: teal pra editar, cinza pra ação neutra, âmbar pra toggle de status, vermelho pra excluir). `title` no botão faz o papel de label (tooltip nativo), sem texto visível ao lado do ícone.

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
- **`carteira_clientes_ocultos`, `clientes_contatados`, `carteira_motivos_inatividade`** — anotações/preferências por cliente, mantidas do legado mas consolidadas numa chave só (o legado tinha raiz/cnpj/cod_client+loja coexistindo por causa de migrações incrementais).
- **`segmentos_vendedor`** — **regra de negócio real, confirmada pelo Tony**: cada vendedor só atende 1-2 segmentos (setor do cliente — supermercadista, órgão público, drogaria etc., não confundir com produto). "Carteira por Segmento" é um relatório de **aderência**: quantos clientes ativos/inativando/inativos o vendedor tem DENTRO do(s) seu(s) segmento(s) vs. FORA. Era `segmentos_referencia_2026` no legado (chaveado por nome — quebra se o usuário troca de nome; aqui é por `cod_vendedor`, e um vendedor pode ter mais de uma linha = mais de um segmento). Cálculo em `App\Services\Carteira\CarteiraAderenciaResolver`, replicando `carteira_clientes_stats_aderencia_completa` do legado — sem a camada de "de-para" de segmento nem os tipos especiais `INATIVOS GERAL`/`PRIMEIRO CONTATO` (complexidade de página própria, cortada de propósito). Segmentos reais usados: SUPERMERCADISTA (domina, ~50-70% dos vendedores), ORGAO PUBLICO, DROGARIAS, REDE DE LOJAS, AEROPORTOS, etc. — ver `SegmentoVendedorSeeder`.
- **`orcamentos` + `orcamento_itens`** — nível de aprovação (nenhum/supervisor/diretor) é derivado do maior desconto entre os itens (regra de negócio real do legado: <10% auto-aprova, 10-15% supervisor, >15% diretor, sem exceção pro perfil). `itens_orcamento` era um JSON solto num TEXT no legado — agora é tabela normalizada.
- **`pedidos` + `pedido_itens`** — unifica o que no legado eram DUAS tabelas (`PEDIDOS_EM_ABERTO` + `META_VENDA`, essa última um nome errado — é o relatório completo de pedidos emitidos no TOTVS, não tem nada a ver com "meta"). `data_faturamento IS NULL` = pedido em aberto; a tabela inteira = pedidos emitidos.
- **Cortado por decisão do Tony**: `carteira_transferencias`/`carteira_vendedor_override` (Regra nº 4), `CLIENTES_GRUPOS`/`GRUPOS_CLIENTES` (0 uso real em produção), `snapshot_status_clientes`/`clientes_para_cadastro` (features próprias — dashboard de volatilidade e fila de leads — fora de escopo aqui), a camada de "de-para" de segmento e os tipos especiais de segmento do legado (ver acima).

Ainda faltam as páginas completas (Carteira, Orçamentos, Pedidos em Aberto) — a Home só tem os widgets-resumo, igual ao padrão do legado.

## Pendências
- Construir as demais ~15 páginas core (Carteira, Orçamentos, Pedidos em Aberto são as próximas candidatas óbvias, já com schema + seeders + widget-resumo na Home prontos) — reusar o Design System de Dashboard (seção acima).
- Traduzir `lang/pt_BR/validation.php`.
- `APP_DEBUG=false` antes de qualquer deploy real.
- Revisitar `GrpVendas` no redesenho de banco.
- E-mail transacional (planejado, não feito): AWS SES + PHPMailer via `antonio.barbosa@autopel.com`, notificando Cadastros e PCP.
