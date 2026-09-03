# Gaps do legado que ainda não existem no CRM-V2

Levantamento de 2026-08-10. Diferente da primeira análise (que foi só de páginas, pelo menu
do `sidebar.php`), esta varre o que está **escondido dentro** das páginas: os 87 endpoints em
`includes/ajax/`, 31 em `includes/api/`, 24 em `includes/crud/`, além de `management/`,
`reports/`, `pdf/` e `calendar/`.

Legado: `Documentos\Sistemas\CRM-AUTOPEL-COMERCIAL` (é o PLANO-DE-ESCAPE relocado).

## Como priorizar: uso real, não tamanho do código

Contagem de linhas no espelho `autopel01_homolog` — é o que separa feature viva de código
morto. Vale repetir essa checagem antes de construir qualquer item daqui.

| Tabela | Linhas | Leitura |
|---|---|---|
| `RESPOSTAS_LIGACAO` | 19.188 | **feature muito usada** |
| `observacoes` | 10.798 | portada |
| `LIGACOES` | 8.028 | portada |
| `clientes_para_cadastro` | 488 | portada |
| `LEADS_MANUAIS` | 271 | portada (virou `leads`) |
| `observacoes_excluidas` | 169 | usada de leve |
| `PERGUNTAS_LIGACAO` | 11 | as perguntas do roteiro |
| `observacoes_categorias` | 0 | **código morto, não portar** |
| `MARKETING_WP_LEADS_RAW` | tabela não existe | webhook nunca ativado |

---

## Decisões do Tony em 2026-08-10 (sobre esta análise)

| Item | Decisão | Estado |
|---|---|---|
| Roteiro de perguntas da ligação | **Não entra.** Ligação é só contagem de chamadas | ✅ feito — KPI fantasma e colunas removidos |
| Observações (resposta / excluir / categorias) | **Fica mão única mesmo** | ✅ encerrado, não é gap |
| PDF de bobina | **Fazer, igualzinho o legado** | ✅ feito |
| Transferência de leads | **Não entra** | ✅ encerrado |
| Edição de lead | **Talvez** — bugava muito no legado; se entrar, tem que funcionar direito | 🔍 análise abaixo |
| Simular usuário | **Talvez** — se fizer, funciona em toda página (no legado só na primeira) | 🔍 análise abaixo |
| "Menores" (item 9) | Revisar | 🔍 análise abaixo |

## 1. ⚠️ Bug no v2, achado durante a varredura: KPI fantasma no Dashboard

`ligacoes` tem `perguntas_respondidas_count` e `perguntas_obrigatorias_count`, e o
`DashboardController` (~linha 129) calcula um percentual de "perguntas respondidas" em cima
delas. **Só o `LigacaoSeeder` escreve nessas colunas.** `CarteiraController::registrarLigacao`
e `LeadController::registrarLigacao` criam a ligação sem tocá-las, então em uso real elas
ficam 0 pra sempre.

Ou seja: esse KPI hoje só mostra número porque o seed inventa. Com dado real ele exibe 0%
permanente — e como não quebra nada, passa despercebido.

É metade de uma feature que não foi portada (item 2 abaixo). Decidir: ou porta o roteiro de
perguntas, ou remove o KPI e as duas colunas. Deixar como está é o pior dos casos.

## 2. Roteiro de perguntas da ligação — o maior gap real

`includes/management/gerenciar_perguntas_ligacao.php` (429 linhas), tabelas
`PERGUNTAS_LIGACAO` (11 perguntas) e `RESPOSTAS_LIGACAO` (**19.188 respostas**).

Quando o vendedor registra uma ligação no legado, ele responde um roteiro (obrigatórias +
opcionais). É o que alimenta o KPI do item 1 e as estatísticas de ligação do gestor. No v2,
"Realizar ligação" só grava o registro e dispara o `tel:`.

Pelo volume (2,4 respostas por ligação), é a feature escondida mais usada do sistema.

## 3. Observações — três pedaços faltando

O v2 tem `index`, `porCliente`, `porLead`, `store` e `togglePin`. O legado tem mais:

- **Resposta a observação** (`crud/enviar_resposta_observacao.php` + `ajax/observacao_supervisor_ajax.php`) — thread supervisor↔vendedor. Hoje no v2 observação é mão única.
- **Excluir/restaurar observação** (`crud/excluir_observacao.php`, `restaurar_observacao.php`, tabela `observacoes_excluidas` com 169 linhas) — soft-delete com restauração. O v2 não deixa apagar observação nenhuma.
- ~~Categorias de observação~~ (`observacoes_categorias`) — **0 linhas em produção. Não portar.**

## 4. Simular usuário (impersonation) — por que bugava, e por que no v2 não bugaria

`includes/ajax/simular_usuario_ajax.php` (187 linhas) + `listar_usuarios_simulacao_ajax.php`.
Admin entra como qualquer usuário sem senha. O Tony relata que "só pegava na primeira página".

**Causa medida no código, não suposta:**

| | Legado | CRM-V2 |
|---|---|---|
| Arquivos que resolvem "quem é o usuário" | **257** (`$_SESSION['usuario']` lido direto) | **89** (`$request->user()` / `auth()->user()`) |
| Quantos sabem que existe simulação | **7** | não se aplica |

O legado guarda identidade em três chaves de sessão (`usuario`, `usuario_simulado`,
`usuario_original_admin`) e cada página monta o próprio escopo lendo `$_SESSION['usuario']`
na mão. A simulação foi enxertada depois e só 7 dos 257 arquivos foram ensinados sobre ela —
os outros 250 continuavam vendo o admin. Não é bug de detalhe: é consequência de não haver
fonte única de identidade.

**No v2 o problema não existe por construção.** Identidade vem sempre do guard do Laravel, e
todo resolver de escopo recebe o usuário como parâmetro
(`DashboardScopeResolver::resolve(User $user, ...)`, idem `EquipeScopeResolver`,
`MetaRankingResolver`, `CarteiraAderenciaResolver`). Trocar o usuário autenticado
(`Auth::login($alvo)`, guardando o id original na sessão) faz **todas** as 89 chamadas
enxergarem o alvo automaticamente — nenhuma página precisa saber que a simulação existe.

Se for construir, o que não pode faltar: banner fixo "você está simulando X" com botão de
sair (senão o admin esquece e acha que o sistema está errado), bloqueio de simular outro
admin, e **trilha de auditoria** de quem simulou quem e quando — o legado não tem nada disso,
e é a feature mais sensível de toda a lista.

## 5. Modo manutenção

`includes/ajax/toggle_manutencao_ajax.php` + `includes/utils/verificar_manutencao.php` +
`index_manutencao.php`. Admin derruba o sistema pros outros perfis por um toggle.

No v2 o equivalente nativo é `php artisan down --secret=...`, que já resolve — só não tem
botão. Provavelmente não vale tela própria.

## 6. PDF das solicitações de bobina e etiqueta

`includes/pdf/solicitacao_bobina_pdf.php`, `solicitacao_etiqueta_pdf.php` e
`solicitacao_pdf_layout.php`. O legado gera PDF da ficha de solicitação.

O v2 (`CadastroController`) só monta um `mailto:` com o texto no corpo. Como o
`barryvdh/laravel-dompdf` já está instalado e o template de orçamento já foi feito, é barato.

## 7. Leads — editar (transferência foi descartada)

`crud/editar_lead.php`, `crud/transferir_leads.php`, `includes/leads/transferencia_lib.php`.
O v2 tem listar, ligar, agendar e excluir — mas **não editar** um lead nem passar leads de um
vendedor pra outro.

**Transferência de leads: descartada pelo Tony (2026-08-10).** Não construir.

**Edição de lead: em aberto**, com a ressalva de que "bugava muito" no legado. O sintoma está
no próprio código: existe um `crud/resetar_edicao_lead.php` só pra destravar lead que ficou
preso em edição — ou seja, o legado tinha algum estado de "em edição" que vazava e precisava
de um botão de emergência pra limpar. Some-se a isso três CRUDs paralelos pro mesmo conceito
(`editar_lead.php`, `editar_lead_manual.php`, `crud/editar_cliente.php`), herança de quando
lead manual e lead de importação eram tabelas diferentes.

No v2 isso já está resolvido na origem: **uma** tabela `leads` com a coluna `origem`
(`sistema`/`manual`), sem estado de edição nenhum. Um `update` normal de formulário Inertia
não tem como reproduzir o bug do legado, porque não existe o lock que o causava. O que
precisa de atenção não é o travamento, é decidir **quem pode editar o quê**.

⚠️ `ImportLeadsLegado` faz `DELETE FROM leads WHERE origem = 'sistema'` e reinsere tudo
([ImportLeadsLegado.php:59](../app/Console/Commands/ImportLeadsLegado.php:59)) — não é
update, é apagar e recriar (o registro volta com id novo). `origem = manual` é preservado de
propósito. Ou seja: **editar um lead `origem = sistema` é trabalho que some no próximo
import**, junto com o id — o que também levaria junto ligações/agendamentos amarrados por
`lead_id`, se houver.

Recomendação: liberar edição só em `origem = manual`. Se quiser editar os de `sistema`
também, aí a decisão é maior — o import precisaria virar upsert por chave estável em vez de
delete+insert, e isso é outra tarefa.

## 8. Webhook de leads do site (WordPress)

Portado em 2026-09-01. O v1 nunca ligou de verdade (tabela ausente no espelho; GET
ainda responde 405 em produção). No v2:

- `POST /webhooks/wordpress-leads` — mesmo contrato HTTP do v1 (Bearer ou
  `X-Webhook-Token`, envelope JSON cru em staging).
- Staging em `marketing_wp_leads_raw` (nada do form é descartado) — é a prova de
  captura (último recebimento na `/leads`).
- Lead comercial `origem = wordpress`. Dono em `marketing_wp_formularios`
  (identificador `*` = 010617; form específico = nova linha, não env).
- A `/leads` mostra se o webhook está ligado (segredo), a URL, o último POST e
  um botão admin de lead de teste. O WordPress em si ainda precisa ser apontado
  para essa URL.
- `legado:import-leads` continua apagando só `origem = sistema`.
- CSV histórico: `php artisan marketing:import-wp-csv`.

Desligar: esvaziar `WP_LEADS_WEBHOOK_SECRET` (503 `webhook_not_configured`).

## 9. Os "menores" — revisados em 2026-08-10

Em ordem de utilidade real, não de esforço:

1. **Modal de aderência por segmento** (`ajax/aderencia_segmento_modal_ajax.php`) — **o mais
   útil dos quatro.** Hoje o `CarteiraSegmentoCard` mostra "4.939 dentro / 15.307 fora" e o
   vendedor não tem como saber *quais* clientes são. Sem isso, o número informa mas não gera
   ação. E é barato: o `CarteiraAderenciaResolver` já calcula a classificação por cliente, e
   a `/carteira` já tem o filtro `?aderencia=` — dá pra resolver tornando os tiles clicáveis,
   levando pra Carteira já filtrada, sem modal nem endpoint novo.
2. **Drill-down de metas** (`reports/metas_detalhes.php` + `ajax/metas_detalhes_ajax.php`) —
   abre a composição do realizado de um vendedor. Mesma ideia: a página `/metas` mostra o
   ranking, mas não o "de onde veio esse número".
3. **Projeção de faturamento** (`reports/grafico_projecoes.php`) — o v2 tem comparação
   ano a ano, não projeção de fechamento do mês. Útil pra gestor, mas é feature nova de
   verdade (precisa definir o método de projeção), não um port.
4. **Preferências de modal por usuário** (`utils/salvar_configuracoes_modais.php`) — "não
   mostrar de novo". Conveniência pura; só vale se algum modal do v2 estiver incomodando.

`reports/vendas_reais_mes_totvs.php` e `estatisticas_ligacoes*.php` já estão cobertos pela
Visão do Gestor e pelos KPIs da Home — não são gap.

## 10. Já mapeado na análise anterior, segue em aberto

~~Busca "quem cuida do cliente?" (titularidade por CNPJ)~~ **— FECHADO em 2026-09-03**, vive no topo da solicitação de cliente novo em `/cadastros` (ver `App\Services\Cadastros\BuscaTitularidade`); importar tabela de preços por tela,
Calendário como página própria, Devoluções de Faturamento, Organograma de Faturamento por
Equipe, lixeira/auditoria de exclusões (`admin_gestao_unificado.php`, `crud/excluidos.php`,
`mover_para_lixao.php`, `excluir_definitivamente.php`).

---

## Confirmadamente fora — não reabrir

- **Regra nº 4** (carteira é só leitura): `crud/transferir_carteira.php`, `editar_cliente.php`, `excluir_cliente.php`, `restaurar_cliente.php`, `ocultar_registro.php`, `marcar_cliente_ligado_ajax.php`.
- **Regra nº 3** (`raiz_cnpj` proibido): `ajax/carteira_filiais_ajax.php` agrupa filiais por raiz de CNPJ — se um dia precisar listar filiais, é `LEFT(cnpj,8)` na query.
- **Decisão do Tony (2026-08-10)**: Central de Demandas, B2G, Importar Bases, Mapa de Usuários.
- **Regra nº 2**: tudo de Licitação, SAC, E-commerce/Bling e Marketing.
- **Cortado antes**: dashboard de volatilidade (`includes/volatility/`), `observacoes_categorias` (morto).

## Lixo do legado — não portar em hipótese alguma

`includes/ajax/`: 10 arquivos `test_*.php` / `test-*.php`, `debug_editar.php`,
`snake_leaderboard_ajax.php` (jogo da cobrinha), `migrar_*_ajax.php` (migrações pontuais já
rodadas), `verificar_estrutura_pregoes.php`. Em `includes/pdf/`: 5 arquivos `teste_*.php`.
Na raiz de `pages/`: os 8 `diagnostico_*.php`. Somados com os easter-eggs já documentados no
CLAUDE.md (Sthefany, Bobinito), é o retrato de por que o v2 existe.
