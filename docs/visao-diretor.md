# Visão Diretor

Seção do menu com análises estratégicas para o dono da empresa. Nasceu em 2026-09-18 com a
página **Maiores por Segmento**, e fixa o padrão das que vierem depois.

> **Por que não é um Power BI:** o BI responde "quanto"; esta seção responde "quanto, de
> quem, e o que eu faço com isso" — todo nome é um link para a entidade viva do CRM
> (carteira filtrada, ficha do cliente, carteira do vendedor). Página que não tiver pelo
> menos um número clicável levando a uma lista do CRM provavelmente deveria ser um BI.

## 1. O padrão da seção

| Decisão | Onde mora | Por quê |
|---|---|---|
| Quem entra | Gate **`ver-visao-diretor`** (`AppServiceProvider`) = admin + diretor | Uma decisão só. As telas antigas copiam `hasAnyRole([...])` em cada controller; aqui o grupo de rotas inteiro passa pelo mesmo gate |
| Quais segmentos | `AbasDaPlanilha` (as seis abas do Excel, na ordem dele) | Import, abas da tela e resumo vazio saem daqui. Mexeu numa, mexeu nas três |
| Bloqueio | `->middleware('can:ver-visao-diretor')` no grupo de rotas → **403** | Página nova entra no grupo e já nasce protegida; esquecer a checagem no controller deixa de ser possível |
| Rotas | prefixo `/visao-diretor/…`, nomes `visao-diretor.…` | O menu acende por `visao-diretor.*` |
| Controllers | `app/Http/Controllers/VisaoDiretor/`, um por página | |
| Regra / consulta | `app/Services/VisaoDiretor/`, um resolver por página | Regra de ouro nº 8 — o controller só monta a resposta |
| Páginas Vue | `resources/js/Pages/VisaoDiretor/` | |
| Componentes | `resources/js/Components/VisaoDiretor/` | |
| Menu | grupo `visao-diretor` em `constants/navegacao.js`, `visivel: soDiretor` | `isDiretor` mora no objeto de perfil do `AuthenticatedLayout` |
| Layout | `PageHero` → abas (uma por segmento) → `KpiTile` → `DarkCard` | Abas iguais às da planilha; o Resumo é a aba DASHBOARD |
| Tabela | tokens `.tbl*` + `.tbl-cartoes` (celular) | Regra de ouro nº 5, `docs/mobile.md` |
| Dentro de card | alinhamento esquerda/direita, sem `.tbl*` | Lição do `SegmentosInativosCard` (10/09) |
| Links | todo nome → entidade do CRM | É a razão da seção existir |
| Número clicável | **tem que bater** com o total da lista que abre, e há teste afirmando isso | "Os números da tela têm que bater" |
| Custo | orçamento da Regra de ouro nº 9 | Diretor também é usuário traumatizado |
| Faturamento | **nunca** agregar `faturamentos` ao vivo — ler `faturamento_cliente_mensal` | §3 |
| Excel | `CatalogoDeExportacoes` | Mesmo caminho das outras planilhas |

### Escopo

A seção vê a **empresa inteira**. Não usa `DashboardScopeResolver` nem o seletor de visão:
admin e diretor já resolvem para escopo `null` lá, e a pergunta estratégica é sobre o
mercado, não sobre a carteira de alguém. Recortar por vendedor é o que os links fazem.

### Filtro que atravessa para a Carteira

Quando uma página precisa abrir "exatamente estes clientes" na Carteira, o recorte vira um
filtro da própria Carteira (ex.: `?conta_alvo=`), **definido num serviço só** que a página
de origem também usa para contar. Assim o número clicado e a lista aberta vêm da mesma
definição. O filtro:

- entra em `CarteiraController::baseQuery()` (vale para lista, KPIs, total e Excel de uma vez);
- entra em `assinaturaDosFiltros()` **com a versão do dado** — sem isso o total cacheado da
  Carteira fica 10 min atrás de uma edição feita aqui;
- é anunciado por uma faixa com "Limpar recorte" (filtro invisível parece lista quebrada);
- só vale para quem passa no gate; para os demais é ignorado.

## 2. Página: Maiores por Segmento

`/visao-diretor/maiores-por-segmento`

Substitui a planilha `MAIORES POR SEGMENTO - CRM.xlsx`: as maiores redes do **mercado** em
seis segmentos (Drogarias, Rede de Lojas, Alimentação, Postos, Material de Construção,
Estacionamentos), com o número de filiais que cada uma tem no Brasil.

A tela é **uma aba por segmento**, na mesma ordem da planilha (Drogarias, Rede de Lojas,
Alimentação, Postos, Construção, Estacionamentos), mais o **Resumo** no lugar da aba
DASHBOARD. As seis abas existem mesmo sem conta cadastrada. A troca de aba é local —
o payload traz todas as tabelas, igual virar a folha no Excel.

⚠️ **É lista de ALVOS, não de faturamento.** Na planilha, "ATENDIMENTO" e "STATUS" eram
preenchidos à mão cruzando com o CRM, e envelheciam no dia seguinte. Aqui os dois são
**derivados** — só nome, UF, filiais de mercado, site e observação são digitados.
Nossas lojas, penetração e fat. 12 m saíram da tela de propósito: o diretor quer a
rede, o status e quem atende — não a conta de penetração.

### Modelo

| Tabela | O quê |
|---|---|
| `contas_estrategicas` | a conta-alvo: segmento, nome, UF, filiais de mercado, site, observação, ordem |
| `conta_estrategica_vinculos` | quais clientes do CRM são essa conta: `tipo` = `grupo` (cod_grupo do TOTVS) ou `cliente` (cod_cliente), `origem` = `sugestao` ou `manual` |
| `segmentos.especialista_user_id` | o responsável do segmento (as abas da planilha diziam "DROGARIAS - Inaya") |

- **Vínculo por grupo** é o caso comum: ESTAPAR são 21 grupos no TOTVS (um por UF), o
  McDonald's uns dez. Código avulso cobre cliente sem grupo próprio.
- ⚠️ **Grupo `9998` (CLIENTES DIVERSOS) é recusado** — é o balde de "sem grupo real" e
  ligaria ~30% da base a uma conta só.
- Nunca `raiz_cnpj` (Regra de ouro nº 3).
- **`ClientesDaConta`** é a única definição de "os clientes desta conta". A página conta
  por ela e o filtro `?conta_alvo=` da Carteira filtra por ela.

### Derivados

| Coluna | De onde |
|---|---|
| Clientes | `cod_cliente` distintos das filiais casadas pelos vínculos |
| Status | `MAX(data_ultima_compra)` → `ClienteStatusResolver` (mesmo corte da Carteira). **Sem vínculo = Lead** |
| Atendimento | vendedores distintos dessas filiais |

⚠️ **Invariante testada:** "Clientes" == total de `/carteira?conta_alvo=X` (agrupada).

### Carga inicial

```bash
php artisan diretor:importar-maiores-segmento "caminho/MAIORES POR SEGMENTO - CRM.xlsx" --dry-run
```

Lê as seis abas pelo **nome da aba** e as colunas pelo **nome do cabeçalho** (as abas têm
ordens diferentes). Importa nome, UF, filiais, OBS e SITE; **não** importa ATENDIMENTO nem
STATUS. Sugere vínculos em duas camadas (`SugestaoDeVinculo`): prefixo com fronteira de
token (não exige segmento — Magazine Luiza no TOTVS é 115, não 108) e, se o prefixo não
alcançar, token distintivo **com maioria no mesmo segmento**. Palavra de tipo (FARMA,
MAGAZINE, SORVETES, ESTACIONAMENTOS) não conta. Medido na planilha real (dry-run em
18/09): prefixo sozinho 47/383; combinada **99/383 contas com sugestão** (173 grupos);
token solto 240 com 789 FPs. Idempotente, nunca apaga vínculo `manual`. O relatório do
fim lista as 284 contas sem vínculo e as divergências Excel × CRM — é a lista de revisão.

Depois da carga, a planilha morre: tudo se edita na tela.

## 3. Rollup de faturamento — `faturamento_cliente_mensal`

A página Maiores por Segmento **não lê** esta tabela (saiu da tela em 18/09). O rollup
continua existindo para as próximas páginas da seção e para o `totvs:atualizar`.

Somar 12 meses de faturamento de **uma** conta direto em `faturamentos` (ESTAPAR, 162
códigos) levou **~10 s** em dev (18/09): a tabela tem ~6 M de linhas e nenhum índice por
`cod_cliente` — e criar um custa caro em toda recarga (10m40s contra 66s, ver 31/08).

Por isso a seção lê uma tabela pré-agregada, `(cod_cliente, mes) → valor_total, notas`.

- `php artisan faturamento:rollup-mensal --desde=AAAA-MM --ate=AAAA-MM` recalcula os meses
  pedidos (delete + insert). A carga histórica inteira roda uma vez, à mão.
- Depois de toda rodada `totvs:atualizar` com sucesso, o mês corrente e o anterior são
  recalculados sozinhos (o relatório FAT é recorte de mês inteiro).
- ⚠️ **Invariante:** soma do rollup por mês == soma de `faturamentos` no mesmo mês. Há
  teste. Se `faturamentos` for recarregado por outro caminho (`legado:import-faturamento-arquivo`),
  rodar o rollup para os meses tocados.
- A página mostra a data do último rollup: dado velho não acende luz vermelha.

## 4. Próximas páginas

Candidatas já conversadas: curva ABC de clientes, evolução por segmento, as "500 maiores"
e "Clientes BR Supply" (abas da mesma planilha, deixadas de fora da página 1 por decisão do
Tony). Toda página nova: entra no grupo de rotas do gate, ganha item no grupo
`visao-diretor` do menu, e ganha uma seção neste arquivo.
