# Celular — CRM-V2 (2026-09-15)

> Como o sistema se comporta abaixo de 640px, e onde cada decisão mora.
> Motivação: o PALMA legado no celular era o menu hambúrguer truncado, a navbar
> esmagada e tabelas de 1200px para arrastar. O v2 não copia isso.

O corte é o `sm` do Tailwind: **639px e abaixo é compacto**, 640px em diante é mesa.
Esse número aparece em DOIS lugares e os dois têm que continuar iguais:

| Lugar | O que decide |
|---|---|
| `resources/css/app.css` (`.tbl-cartoes` e o empilhar do `PageHero`) | CSS |
| `resources/js/composables/useTelaCompacta.js` (`LARGURA_COMPACTA = 639`) | JS (filtros no modal vs inline) |

Esquecer um dos dois deixa a tabela virando cartão numa largura em que os filtros
ainda estão colapsados (ou o contrário), sem nada quebrar em vermelho.

---

## 1. Menu: uma fonte, duas superfícies

Até esta data o menu era escrito DUAS vezes no `AuthenticatedLayout.vue` (nav
horizontal e lista do hambúrguer) e já havia divergido: o link admin "Atualização
de dados" existia só no desktop.

A estrutura mora em **`resources/js/constants/navegacao.js`**. Item novo entra LÁ,
não no layout, não na barra, não na gaveta. Os três consumidores recebem o mesmo
objeto de papéis (`{ isGestor, isAssistente, isAdmin }`).

| Superfície | Componente | Quando |
|---|---|---|
| Nav horizontal | o próprio `AuthenticatedLayout` | `sm` e acima |
| Barra inferior (Início, Carteira, Leads, Pedidos, Mais) | `Components/Navegacao/BarraInferior.vue` | abaixo de `sm` |
| Gaveta "Mais" | `Components/Navegacao/GavetaMobile.vue` | abaixo de `sm`, no botão Mais |

O hambúrguer **não existe mais**. Ícones do menu: `Components/Icones/IconeNav.vue`.

⚠️ **Aqui só mora o NOME da rota**, nunca a URL resolvida. Quem chama faz
`route(item.rota)`. Resolver no `navegacao.js` amarraria o módulo ao Ziggy já
carregado.

⚠️ O layout ainda é remontado a cada visita do Inertia. Os computeds do menu
dependem de `page.url` de propósito, mesmo sem usar o valor: `route().current()`
lê a URL do navegador, que não é reativa. No dia em que o layout persistente
entrar, sem essa dependência o menu congelaria na primeira página.

---

## 2. Anti-padrão: `min-w-0 flex-1` ao lado de `shrink-0`

Num `flex-wrap`, isso NÃO quebra linha no celular: **esmaga** o lado flexível.
O flexbox só quebra quando os itens não cabem no tamanho MÍNIMO deles, e
`min-w-0` declara que aquele mínimo é zero.

Sintoma: título com uma palavra por linha, ou barra de progresso reduzida a um
toco de 30px. Acontecia no `PageHero` (18 páginas) e na barra de aderência do
`CarteiraSegmentoCard`.

Correção: **empilhar abaixo de `sm`** (`flex-col sm:flex-row`), ou mandar o lado
elástico para uma linha própria. Nunca confiar no wrap. Comentário irmão no
`app.css` (antes dos tokens `.tbl*`) e histórico completo no `PageHero.vue`.

O logo do nav usa `min-h-11` no `<Link>` (alvo de 44px) e `h-8` na imagem —
tamanho do glifo e tamanho do alvo são coisas diferentes.

---

## 3. Filtros colapsam; busca e ordenar ficam

A Carteira tem sete `FilterField` mais a busca: a 320px isso era uma coluna de
quase uma tela, e a tabela começava abaixo da primeira dobra.

Abaixo de 640px a faixa vira um botão **"Filtros (N)"** que abre em `ModalPadrao`.
Dois slots no `PageHero`:

| Slot | O que fica |
|---|---|
| `#filtrosFixos` | sempre visível (busca, "Ordenar por" do celular) |
| `#filtros` | colapsa no celular, inline a partir de `sm` |

⚠️ `filtrosAtivos` NÃO É ENFEITE. Com a faixa escondida, ele é a única pista de
que a lista está recortada. Toda página que use `#filtros` passa a contagem, via
`utils/filtros.js` (`contarFiltrosAtivos`).

⚠️ O modal é `v-if="compacta"` e o inline é `v-else`: só UM existe a cada
momento. Fazer isso com `sm:hidden`/`hidden sm:flex` renderizaria o slot duas
vezes, com dois `<select>` para o mesmo filtro.

⚠️ Os filtros aplicam sozinhos (`preserveState: true`), então o modal SOBREVIVE
à visita do Inertia. Trocar uma dessas telas para `preserveState: false` fecha
o modal a cada seleção e parece defeito do `PageHero`.

Ordenar no celular: `Components/Tabela/OrdenarMobile.vue`. Na Carteira a lista
de colunas ordenáveis mora em `constants/carteira.js` (`ORDENACOES_CARTEIRA`) e
espelha `CarteiraController::ORDENACOES` — o `<thead>` some no cartão, então o
seletor é o único acesso.

`FilterField` é `w-full` abaixo de `sm` (não `w-auto`).

---

## 4. Tabela vira cartão — uma marcação, reflow por CSS

Abaixo de 640px, uma tabela marcada com **`.tbl-cartoes`** deixa de ser tabela:
cada `<tr>` vira um cartão empilhado, com o rótulo saindo do `data-rotulo` da
célula. Substitui o scroll horizontal de 1200px numa tela de 360.

⚠️ **UMA MARCAÇÃO SÓ.** Renderizar `<table>` para desktop e uma segunda árvore
de cartões para celular seria o mesmo dado duas vezes — coluna nova entra na
tabela e é esquecida no cartão (foi assim que o rótulo de status do Pedido
divergiu entre back e front em 2026-09-09).

⚠️ **É OPT-IN** (`.tbl-cartoes` na `<table>`). Tabela não convertida continua
rolando, então o rollout é incremental.

⚠️ **Tudo com combinador de filho direto (`>`).** A linha expandida contém uma
`.tbl-itens` DENTRO de uma célula: seletor descendente viraria essa sub-tabela
em cartão também, e ela não tem `data-rotulo` em nada.

Papéis de célula (nenhum é obrigatório; o default já é "campo"):

| Classe | Papel no cartão |
|---|---|
| `.tbl-td-titulo` | manchete, largura cheia, filete embaixo |
| `.tbl-td-acoes` | rodapé, botões em alvo de 44px |
| `.tbl-td-expandir` | chevron vira "Ver itens" no rodapé (não pode só esconder: é a única pista) |
| `.tbl-td-expansao` | célula de `colspan` da linha expandida |
| `.tbl-td-oculto` | some no cartão (checkbox em massa, contagem de itens redundante) |

### Utilities que VENCEM o `@layer components`

`min-w-[1200px]` e `max-w-[220px]` no HTML são utilities e ganham do CSS dos
cartões, independente de especificidade. Por isso cada tabela convertida usa
`sm:min-w-[…]` e `sm:max-w-[…]` — sem o prefixo, o cartão sai certo **dentro
de uma página rolando 1000px na horizontal**.

O estado vazio (`colspan` "nenhum registro") sai da `<table>` e vira um `<p>`
ao lado: uma linha `colspan=9` no cartão vira um cartão mudo.

Linha de totais (só Metas tem): o `tfoot` entra no mesmo reflow
(`.tbl-cartoes > :is(tbody, tfoot)`).

### Expansão (itens do pedido / orçamento / filiais)

A sub-tabela **continua rolando na horizontal, de propósito**: é detalhe
secundário e não tem `data-rotulo`. O que não pode é esse scroll virar scroll
da PÁGINA — daí o `overflow-x-auto` na `.tbl-td-expansao` e um `tbl-wrap`
em volta da `.tbl-itens`, com `min-w-[560px]` no cartão.

Se o conteúdo da expansão for um `grid`, ele precisa de **`grid-cols-1`
explícito**. Coluna implícita é `auto`, e `auto` nunca encolhe abaixo do
conteúdo: a `.tbl-itens` de 560px esticava a coluna vizinha para fora da tela
e NADA acusava (a página não rolava).

### Convertidas (2026-09-15)

Carteira, Leads, Pedidos em aberto, Pedidos emitidos, Orçamentos, Equipe,
Metas, Visão do Gestor, Meus downloads.

Não convertidas de propósito (filtros já colapsam; a tabela em si ainda rola
dentro do `tbl-wrap`): Cadastros, Tabela de Preços, Matéria-prima, ficha do
cliente. O quadro **Evolução Comercial** do Painel também continua com scroll
interno — é tabela mês a mês, não listagem de registros.

---

## 5. Segmentos Atendidos não é `.tbl-cartoes`

O card do Painel é **legenda de KPI dentro de card**, não tabela de página
cheia. Os tokens `.tbl*` (célula centrada, `divide-x`) foram aplicados aqui em
05/09 e revertidos em 10/09 exatamente porque ficaram errados — mesma razão
que mantém a mini-tabela do `CarteiraSegmentoCard` fora dos tokens.

No celular o que ele precisa é só **não rolar a página**: empilha nome / barra
/ números na mesma marcação (`.seg-lista` + grid scoped, `sm:overflow-x-auto`
e `sm:min-w-[520px]` — o `sm:` é o que impede o `min-w` de vazar abaixo de
640px).

---

## 6. Pilha de camadas

Modal, confirmação e gaveta mobile compartilham **`usePilhaDeCamadas.js`**.
Trava o scroll do `<body>` e decide quem é a de cima (ESC fecha só uma).

⚠️ O array é do **módulo**, nunca do `setup()`. Um `const pilha = []` no corpo
do componente nasce vazio em cada instância — foi o primeiro erro do
`Modal.vue`, e o ESC fechava a confirmação E o formulário por baixo.

---

## 7. Como medir (o teste de servidor não vê isto)

Defeito de layout só aparece no navegador. `docker/medir-mobile.mjs` fala CDP
com o Edge/Chrome da máquina e responde três perguntas:

1. a PÁGINA rola na horizontal? (`scrollWidth > clientWidth`)
2. algum `truncate` está CORTADO?
3. algum alvo de toque está abaixo de 44px?

```
node docker/medir-mobile.mjs                      # 320,360,414 nas telas de campo
node docker/medir-mobile.mjs 360 /carteira,/leads
IMAGENS=1 node docker/medir-mobile.mjs 360 /dashboard
```

⚠️ Espera o Inertia montar, não só o `load`. Página vazia não tem overflow
nenhum, então espera frouxa diria "tudo certo".

⚠️ Depois de editar `.vue`, `docker compose restart vite` — o watcher não
atravessa a ponte Windows→WSL2 (gotcha antigo do compose).

Medido em 2026-09-15, 320 e 360px, nas telas convertidas: **zero overflow,
zero truncate cortado**.

---

## 8. O que ainda não é

- Layout persistente do Inertia (o menu ainda remonta a cada visita).
- Cadastros / Tabela de Preços / Matéria-prima / ficha do cliente em
  `.tbl-cartoes`.
- Evolução Comercial (ComparacaoCard) empilhada — hoje o scroll é interno ao
  card, não da página.
- Converter as `.tbl-itens` da expansão em cartão também.
