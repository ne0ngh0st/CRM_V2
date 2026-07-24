# PALMA CRM v2 — Contexto do Projeto

> Este arquivo é carregado automaticamente pelo Claude Code quando se trabalha nesta pasta.
> É versionado no git, então viaja com o repositório. Mantenha atualizado conforme o projeto evolui.

## Quem toca o projeto
Tony (Antonio Barbosa), desenvolvedor solo na Autopel Soluções (suprimentos corporativos — bobinas térmicas, etiquetas, tags, RFID, papel A4), em São Paulo. Autodidata, dono técnico de todo o ecossistema interno (CRM PALMA, sistema de licitações Laravel, dashboards Power BI, integrações TOTVS/Bling/Correios/PagBank). Comunicação direta e informal, em português. Único dev — não assumir que há outro dev pra revisar/pair.

## O que é este projeto
Refatoração completa do **PALMA** (CRM legado em PHP procedural, MySQL, ~200 usuários/dia, 78-92 páginas, 11 perfis) para stack moderna: **Laravel 11 + Inertia.js + Vue 3**. Motivação central: o PHP legado tem problemas sérios de performance; o objetivo do Laravel + AWS é deixar tudo bem mais rápido/"snappy".

- **Legado de referência:** `C:\xampp\htdocs\Site` (é o PALMA atual em produção; NÃO é `C:\xampp\htdocs\PALMA`, que é só landing estática).
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
- Em aberto (não bloqueia nada): se esse redesenho substitui a discussão do `GrpVendas`/raiz_cnpj no redesenho mais amplo do banco.

## Marca Autopel
- **Logos:** em `public/images/` (`autopel-logo-white.png` = versão branca pra fundo escuro; `autopel-logo.png` = colorido). Originais em `C:\Users\antonio.barbosa\OneDrive - autopel.com\Documentos\Arte` (VETOR-03 = branco, VETOR-01 = cor).
- **Cores oficiais** (de `Arte\Tema.json`): teal `#005A6F`, cyan `#00A9CE`, navy `#0F3A69`, cinza `#C8C9C7`. Secundária/acento âmbar `#ff8f00`. (O token azul `#0f4c75` que aparece por aí é próximo mas não idêntico ao navy oficial.)
- **Gotcha técnico:** `<style scoped>` do Vue NÃO alcança elementos SVG criados via `document.createElementNS` no JS (não recebem o `data-v-*`) → fill/animação por classe scoped não aplicam e o SVG vira preto. Solução: setar fill/opacity/animation inline no JS; deixar só `@keyframes` num `<style>` global. (Aprendido no `resources/js/Components/TriangleMosaic.vue`.)

## Estado atual (2026-07-24)
✅ **Login funcional** em homologação, testado end-to-end. Sem registro público (usuários vêm do TOTVS/admin, igual ao legado); `/` redireciona pra login ou dashboard; login exige `is_active = true`.
✅ **Tela de login redesenhada** — split-screen (painel navy à esquerda + form branco à direita), com mosaico de triângulos interativo nas cores da marca (`resources/js/Components/TriangleMosaic.vue` + `resources/js/Layouts/GuestLayout.vue`), hover que segue o mouse, logo Autopel branco.
✅ **Git:** repo em `main`, remote `https://github.com/ne0ngh0st/CRM_V2.git`.

### Próxima página: HOME / dashboard (não iniciada)
Pra onde o login redireciona. É um **dashboard de performance comercial** cujo centro é **meta vs. faturamento do mês** (gauge circular de % de atingimento). No legado (`C:\xampp\htdocs\Site\home.php`) a home tem: card "Status do Sistema" (frescor das bases, dados D-1); seletor de visão por supervisor/vendedor (só gestor); cards de estatísticas de ligações (só vendedor/rep); gráfico de comparação de faturamento; bloco "Metas do Mês" (o gauge — destaque; "Meta"→"Objetivo" pra representante); seção "Sugestões e Melhorias". Tudo varia por perfil. **Ao construir: aplicar as duas regras de ouro** — não portar 1:1, repensar o que faz sentido (ex.: o easter-egg de banner de foguete pra "Sthefany" claramente NÃO entra).

## Pendências
- Construir as ~16 páginas core (próxima: home).
- Traduzir `lang/pt_BR/validation.php`.
- `APP_DEBUG=false` antes de qualquer deploy real.
- Revisitar `GrpVendas` no redesenho de banco.
- E-mail transacional (planejado, não feito): AWS SES + PHPMailer via `antonio.barbosa@autopel.com`, notificando Cadastros e PCP.
