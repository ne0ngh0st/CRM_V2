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
| Quais segmentos | `AbasDaPlanilha` (Supermercadista + as seis abas do Excel, na ordem dele) | Import, abas da tela e resumo vazio saem daqui. Mexeu numa, mexeu nas três |
| Bloqueio | `->middleware('can:ver-visao-diretor')` no grupo de rotas → **403** | Página nova entra no grupo e já nasce protegida; esquecer a checagem no controller deixa de ser possível |
| Rotas | prefixo `/visao-diretor/…`, nomes `visao-diretor.…` | O menu acende por `visao-diretor.*` |
| Controllers | `app/Http/Controllers/VisaoDiretor/`, um por página | |
| Regra / consulta | `app/Services/VisaoDiretor/`, um resolver por página | Regra de ouro nº 8 — o controller só monta a resposta |
| Páginas Vue | `resources/js/Pages/VisaoDiretor/` | |
| Componentes | `resources/js/Components/VisaoDiretor/` | |
| Menu | grupo `visao-diretor` em `constants/navegacao.js`, `visivel: soDiretor` | `isDiretor` mora no objeto de perfil do `AuthenticatedLayout` |
| Layout | `PageHero` → abas (uma por segmento, os 23) → `KpiTile` → `DarkCard` | Os sete com redes na ordem da planilha; o Resumo é a aba DASHBOARD |
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

### ⚠️ Redesenho de 2026-10-14 — o que vale HOJE (o resto desta seção é histórico)

Pedido do Tony: a página é para o **dono da empresa** ver onde a Autopel atua, deixou de
atuar e nunca atuou, e redes/contas/vínculos confundiam mais do que explicavam. Decisões
dele (não reabrir sem ele pedir):

- **Sem redes na tela.** Nada de conta-alvo, vínculo, sugestão, "vínculo suspeito", linha
  expansível, Nova conta, Editar, Observação, Gerar lead.
- **Uma linha por EMPRESA** = raiz do CNPJ, calculada dentro de `reconstruir()`
  (`UniversoDoSegmento::EMPRESA`, nunca coluna — Regra de ouro nº 3). Cliente com CPF ou
  sem CNPJ: por código. Motivo: "pesquisar Carrefour traz duas páginas de Carrefour" — a
  mesma empresa tinha 26 códigos no TOTVS.
- **Colunas:** Nome (razão social em cima — única por empresa; marca e CNPJ da matriz embaixo) · Grupo
  (do TOTVS, o da matriz, lido na hora; CLIENTES DIVERSOS 9998 sai "—") · UF ·
  Filiais (todas as da empresa; lead "—") · Status (Ativo / Perdendo / A trabalhar / Lead) ·
  Vendedor · Ações (mapa e cartão CNPJ). **Capital social sai da tela** mas continua
  ordenando a lista. Última compra também saiu.
- **Nome fantasia = a marca**: trecho antes do primeiro "-" ou "(" do fantasia de cada
  filial, o mais frequente na empresa ("ATACADAO - LJ 1" → ATACADAO). Marcadores do
  cadastro (ENTREGA, ALMOX, MATRIZ, CNPJ BAIXADO, LOJA 01…) não contam
  (`FANTASIA_DE_CADASTRO`); se nenhum se repete, fica sem fantasia e a razão social sobe.
- **Lead de empresa que já é cliente (mesma raiz) não aparece** — a empresa já está na
  linha do cliente.
- **Botões:** cartão CNPJ (`carteira.cartaoCnpj` pela matriz, ou `leads.cartaoCnpj`) e
  Google Maps com o endereço **da Receita**. O endereço não está na base mensal, só no
  cartão: o botão busca o cartão (30 dias de cache) e monta a URL com `urlDoMapa()`. A aba
  é aberta antes do `fetch`, senão o bloqueador de pop-up a barra.
- **Resumo:** uma linha por segmento (Ativos, Perdendo, A trabalhar, Leads), do maior para
  o menor — é também a ordem das abas. Os números são os tiles de cada aba (teste).
- **Tiles da aba (2026-10-15):** Todos · Ativos · **A trabalhar** (perdendo + a trabalhar +
  leads, filtro `?status=conquistar`); o Resumo tem as mesmas duas colunas. A pill de cada linha continua dizendo qual dos três;
  os filtros `inativando`/`inativo`/`lead` continuam aceitos pela URL (links antigos).
- **Um segmento por empresa (2026-10-15).** O cadastro do TOTVS tem filial no segmento
  errado (AMPLA ENERGIA com 1 filial em Supermercadista, LOJAS RENNER com 1 em Drogarias), e
  a regra anterior punha a empresa em toda aba onde tivesse ao menos uma filial. Agora ela
  aparece só no segmento com MAIS filiais; no empate, o da matriz (/0001); sem matriz no
  empate, o da filial cadastrada primeiro. A régua mora em
  `UniversoDoSegmento::criarSegmentoDaEmpresa()`. ⚠️ Consequência aceita: a aba deixa de
  bater com `/carteira?segmento=X`, que conta a filial onde ela está cadastrada.
  Produção no dia: 978 empresas em mais de um segmento, 1.818 filiais fora do segmento da
  própria empresa (600 delas em empresa empatada, a conferir).
- **Lista para o Cadastro corrigir no TOTVS:** `php artisan visao-diretor:filiais-fora-do-segmento`
  grava `storage/app/private/relatorios/filiais-fora-do-segmento-<data>.xlsx` — as filiais
  com segmento diferente do da empresa, sem empate primeiro (coluna "Conferir (empate)").
  Mesma régua da tela. Não envia nada.
- **Invariantes testadas** (`UniversoDoSegmentoTest`): filiais de cada linha == Carteira
  `?busca=<raiz>&agrupar=0` (o link da célula); soma das filiais de todas as abas ==
  Carteira inteira por filial; tile == lista filtrada; Resumo == tiles.
  ⚠️ O número de CLIENTES aqui (empresas) não é o da Carteira agrupada (códigos): unidades
  diferentes, de propósito.
- **O import de leads parou de criar/ligar conta** (`totvs:import-leads`), e a carga da
  Receita e o `leads:classificar-segmento` pararam de sugerir rede. As colunas `rede` e
  `filiais_rede` do CSV são lidas e ignoradas.
- **Fase 2 (depois do OK do dono):** apagar o código das redes (`ContaEstrategica*`,
  `ClientesDaConta`, `ContaDoLead`, `LeadDaConta`, `Vinculo*`, `BuscaNasRedes`,
  `SugestaoDeVinculo`, `MaioresPorSegmentoResolver`, ranking ABRAS, rotas de escrita, os
  componentes Vue sem uso, o filtro `?conta_alvo=` da Carteira e dos Leads) e dropar as
  tabelas. Drop não tem volta — por isso ficou para depois.
  ⚠️ **Desde 2026-10-16 `SugestaoDeVinculo`, `VinculoPorCliente`, `VinculoPorRazaoSocial`
  e `TermoDeBusca` são usados pela página Maiores redes (§2b) e NÃO entram na Fase 2.**

Medido no dev (14/10): tabela 44-54 ms, Resumo 110 ms, UFs 77 ms; reconstrução ~26 s
(era ~14 s — o passo do fantasia por empresa). A busca segue em ~430 ms (a regex do
`TermoDeBusca`, não mudou neste redesenho).

Substitui a planilha `MAIORES POR SEGMENTO - CRM.xlsx`: as maiores redes do **mercado** em
sete segmentos (Supermercadista, Drogarias, Rede de Lojas, Alimentação, Postos, Material de
Construção, Estacionamentos), com o número de filiais que cada uma tem no Brasil. É o
retrato das maiores redes do país, **onde já atendemos e onde não** — a função da página.

A tela é **uma aba por segmento**: Supermercadista primeiro (não existe na planilha, ver
abaixo), depois a ordem da planilha (Drogarias, Rede de Lojas, Alimentação, Postos,
Construção, Estacionamentos), mais o **Resumo** no lugar da aba DASHBOARD. As abas existem
mesmo sem conta cadastrada. Desde 2026-10-13 são os 23 segmentos, e cada aba é uma
tabela paginada (redes, depois clientes e leads) que vem do servidor ao abrir — ver
"Uma tabela por segmento" abaixo.

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
| `segmentos.especialista_user_id` | o responsável do segmento (as abas da planilha diziam "DROGARIAS - Inaya") — ver "Especialista do segmento" abaixo |

- **Vínculo por grupo** é o caso comum: ESTAPAR são 21 grupos no TOTVS (um por UF), o
  McDonald's uns dez. Código avulso cobre cliente sem grupo próprio.
- ⚠️ **Grupo `9998` (CLIENTES DIVERSOS) é recusado** — é o balde de "sem grupo real" e
  ligaria ~30% da base a uma conta só.
- Nunca `raiz_cnpj` (Regra de ouro nº 3).
- **`ClientesDaConta`** é a única definição de "os clientes desta conta". A página conta
  por ela e o filtro `?conta_alvo=` da Carteira filtra por ela.

### Histórico da observação (2026-09-24)

`contas_estrategicas.observacao` é o texto **vigente** (tabela e Excel); cada versão fica
em `conta_estrategica_observacoes` (texto, autor, data). Na tela: clicar na célula
Observação abre o histórico, e o modal de edição mostra o histórico logo abaixo do campo.

- ⚠️ **Quem escreve o histórico é só o gancho `ContaEstrategica::saved`** — não o
  controller. São três caminhos que mexem na observação (criar, editar e a carga da
  planilha); gancho de model é o que impede um deles de ficar de fora.
- Salvar sem mudar o texto **não** gera versão; apagar o texto **gera** (`texto` nulo,
  "Observação apagada").
- Autor nulo = carga da planilha. Durante simulação, o autor é o **admin**, não o alvo.
- A migration transformou a observação que já existia na primeira versão de cada conta,
  datada da criação dela.

### Especialista do segmento (2026-09-22)

Marcado pela **estrela** no quadro **Equipe → Segmentos** (`/equipe/segmentos`), e só
lá — o seletor que existia no cabeçalho desta página foi removido (decisão do Tony, com
o diretor). Um por segmento: a estrela em outra pessoa tira a anterior; clicar na acesa
desmarca. Quem marca: admin + diretor (o mesmo gate desta seção); supervisor continua
arrastando pessoas no quadro, mas não aponta o responsável.

| Onde aparece | Como |
|---|---|
| Equipe → Segmentos | estrela no cartão da pessoa + selo no cabeçalho da ilha |
| Visão Diretor → Resumo | coluna própria, com foto; cabeçalho da aba (link para o quadro) |
| Painel → Segmentos Atendidos | ao lado da barra de cada segmento |

- **Fonte única: `App\Services\Segmentos\EspecialistasDoSegmento::porCodigo()`**, e
  **componente único: `Components/Segmentos/EspecialistaSelo.vue`** (Regra de ouro nº 8).
- ⚠️ **No Painel vem numa prop à parte (`especialistasSegmento`), FORA do bloco
  cacheado de segmentos.** O bloco vive 30 min no Redis; dentro dele a estrela levaria
  até meia hora para aparecer. Há teste afirmando que aparece no request seguinte.
- ⚠️ **Especialista inativo não aparece** (a coluna continua gravada; reativar a pessoa
  devolve a marcação).
- A carga inicial da planilha ainda preenche o especialista pelo título da aba
  ("DROGARIAS - Inaya") quando o segmento não tem nenhum — isso não mudou.

### Gerar lead a partir da conta (2026-09-24)

Conta com status **Lead** (nenhuma loja nossa) ganha o botão âmbar "Gerar lead" na
coluna Ações. O modal pede o **responsável** e um recado opcional; o lead nasce no funil
(`origem = manual`, etapa Novo) **na carteira do responsável**, que é avisado pelo sino.
Segmento, UF, site e filiais vão numa observação assinada por quem abriu o lead.

- **Regra em `App\Services\VisaoDiretor\LeadDaConta`**; o controller só valida a entrada.
- ⚠️ **Atribuição na criação, não transferência** (transferir lead segue fora do escopo,
  decisão de 2026-08-10). Antes disto o único caminho era a diretoria cadastrar pelo
  /cadastros, e o lead nascia com `cod_vendedor` nulo: admin/diretor não têm código, e
  ninguém via o lead.
- ⚠️ **Quem pode receber é decidido pelo CÓDIGO de vendedor, não pelo perfil**: é o código
  que torna o lead visível no `LeadController`. Perfil novo com carteira entra sozinho.
- O sugerido é o **especialista do segmento**, se ele tiver código.
- **Um lead por conta**: `contas_estrategicas.lead_id` + `lockForUpdate`. Lead excluído
  libera a conta para gerar de novo.
- **Conta com cliente na carteira não gera lead**: a rede já tem vendedor na Carteira.
- ⚠️ **O lead NÃO muda o status da conta.** Ela continua "Lead" até virar cliente no TOTVS
  e ser vinculada — o status é derivado dos vínculos. ⚠️ O responsável pelo lead **não
  aparece na tabela** (decisão da diretoria, 2026-10-07): ao lado do vendedor eram duas
  pessoas na mesma linha sem dizer quem era o quê. O lead se vê em `/leads?conta_alvo=`.
- Testes: `tests/Feature/VisaoDiretor/LeadDaContaTest.php` (12), 4 mutações mordidas.

### Derivados

| Coluna | De onde |
|---|---|
| Clientes | `cod_cliente` distintos das filiais casadas pelos vínculos |
| Status | `MAX(data_ultima_compra)` → `ClienteStatusResolver` (mesmo corte da Carteira). **Sem vínculo = Lead** |
| Vendedor | vendedores distintos dessas filiais — o de mais filiais por extenso, os demais em "+N" (tooltip com todos). Só quem atende no sistema, nunca o dono do lead |

Linha expandida: os clientes da conta, com **UF** = todas as UFs das filiais do cliente
(a da âncora primeiro, "SP +2", lista no tooltip).

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

### Sugestão pelo nome dos clientes (2026-10-06)

```bash
php artisan visao-diretor:sugerir-vinculos --dry-run --detalhe
```

A carga inicial só comparava o nome da conta com o nome do **grupo** do TOTVS, e em
produção só 110 das 404 contas tinham vínculo: o grupo costuma ter outro nome (BRASIL
PARK) e um terço da base está no 9998, sem grupo (C VALE, NORMATEL, REDE FURNAS).
`VinculoPorCliente` compara com o **nome fantasia e a razão social de cada filial**.

- Casa pelo COMEÇO do nome, com fronteira de palavra, ignorando palavras de tipo
  (`SugestaoDeVinculo::marca()`). "BRASIL PARK" casa "BRASIL PARK JABAQUARA", não
  "SANTA LOLLA - BRASIL PARK SHOP".
- Filial avulsa só entra no segmento da conta; grupo, se a **maioria** das filiais dele
  casar (e traz as de outro nome, como "AUTO BRASIL ESTAC"); código avulso, idem — o
  000800 não entra por uma escola.
- Uma palavra só casando o começo de um nome maior precisa de 6+ letras ("MINHA" pegava
  "MINHA DROGARIA"); nome de filial mais curto que o da conta precisa de 2+ palavras
  ("POSTO DO PARQUE" casava "Posto Parque Dez").
- Grava como **sugestão**, só ACRESCENTA, não toca conta com vínculo manual; grupo ou
  código que casa com duas contas fica fora das duas (vai para o relatório).
- Dry-run em dev (06/10): 84 das 284 contas sem vínculo ganham vínculo (17 grupos, 382
  códigos). As outras, em boa parte, não são clientes — continuam Lead, que é o certo.
- Testes: `VinculoPorClienteTest` (11).

### Uma conta, um lead (2026-10-05)

Achado em produção: 8 contas com DOIS leads — o que a diretoria abriu pelo "Gerar lead"
(manual, **sem CNPJ**, com dono) e o que a prospecção trouxe depois para a mesma rede
(com CNPJ, sem dono). Regras hoje:

- **`FusaoDeLeads`**: conta com o par vira UM lead. **Fica o da diretoria** (dono,
  observação de contexto, aviso no sino), que herda CNPJ, razão social legal e campos
  vazios; contatos, agendamentos, observações, orçamentos e captura do site passam para
  ele; o outro é apagado. Roda no fim de todo `totvs:import-leads` e pelo comando
  `visao-diretor:juntar-leads [--dry-run]`. Continua `manual` de propósito: o import
  ignora CNPJ de lead manual, então a duplicata não renasce e a planilha não troca o dono.
- ⚠️ Dois leads com CNPJs DIFERENTES na mesma conta não são juntados — são duas empresas.
- **"Gerar lead" não cria o segundo**: conta com lead sem dono → atribui aquele; CNPJ
  informado (campo opcional do modal) que já é lead sem dono → liga e atribui; já é lead
  com dono ou é cliente → recusa. Atribuir lead SEM dono não é transferência.

### Aba Supermercadista: ranking da ABRAS + leads pelo CNAE (2026-10-08)

A aba não veio da planilha da diretoria. As contas são as **200 primeiras do ranking da
ABRAS** (`database/data/visao-diretor/ranking-abras-2026.json`, extraído do PDF em
`DOCS/CRM/ranking-abras-2026.pdf`), na ordem do ranking.

```bash
php artisan diretor:importar-ranking-abras --dry-run --detalhe
```

- O JSON guarda o **nome curto** de cada rede (o que aparece na tela e o que casa com o nome
  das nossas filiais) ao lado da razão social da ABRAS. "S.S. COMÉRCIO DE ALIMENTOS" virou
  "S.S. COMÉRCIO" porque, sem palavra de marca, o nome casava qualquer "Comércio de
  Alimentos" da base. Ranking do ano seguinte: gerar outro JSON e rodar de novo.
- ⚠️ **Sem faturamento** — nem na tela nem no arquivo (decisão do Tony, 2026-10-08).
- Idempotente pela chave de nome da prospecção (`chaveDeNome`): rodar de novo atualiza a
  posição (= `ordem`) e não duplica; nome, observação e UF já preenchidos não mudam. Conta
  da aba fora do ranking vai para depois dele.
- **Os clientes são ligados pela IDENTIDADE JURÍDICA** (`VinculoPorRazaoSocial`): razão
  social da ABRAS (e as do campo `razoes`, para quem ela publica só a marca: Assaí =
  Sendas, GPA = Cia Brasileira de Distribuição) igual à dos nossos clientes, ou cortada
  pelo TOTVS em 40 caracteres, expandida para as filiais do mesmo CNPJ raiz (calculado em
  memória, Regra de ouro nº 3). Entra como sugestão e SUBSTITUI a que a conta tinha.
- ⚠️ **Nome de marca NÃO liga cliente nesta aba** (Tony, 08/10: "se não achou eles, o
  vínculo está fraco"). A primeira versão usava `VinculoPorCliente`: não achou Assaí, GPA
  nem Sonda, e das redes que só o nome ligava a maioria era homônimo ("Comercial Reis" ×
  atacadista de embalagens). Sem razão social casando, a sugestão por nome é LIMPA e a rede
  fica como Lead até alguém ligar à mão. Vínculo manual nunca é tocado.
- Razão social "igual" ignora acento, pontuação, sufixo societário, "& CIA"/"E CIA",
  conectivos e o plural de SUPERMERCADO. **Prefixo não vale** — "SUPERMERCADO BEL" não é
  BELTRAME, "CASA SANTA" não é CASA SANTA LUZIA —, exceto quando o TOTVS cortou o nome.

**Os leads.** A base antiga (`origem = sistema`, ~14 mil em produção) não tinha segmento.
`SegmentoDosLeads` preenche pelo **CNAE principal** da Receita (`cnpj_situacoes.cnae_principal`,
lido pela carga mensal no campo 11 do Estabelecimentos e gravado também pelo cartão CNPJ):
4711-3/01, 4711-3/02, 4712-1/00, 4639-7/01 (atacado de alimentos) e 4724-5/00
(hortifrúti) → SUPERMERCADISTA.

- ⚠️ **Nem todo lead `sistema` é supermercado**: em produção (08/10), dois vendedores tinham
  ~3,7 mil leads de transportadora e calçados. CNAE fora do mapa deixa o lead **sem
  segmento**; nunca chuta.
- Só preenche (não sobrescreve) e só nas bases importadas; manual e site não são tocados.
- O lead classificado ganha **sugestão** da rede cujo nome casa (mesma regra da
  prospecção). O mercado pequeno que não é rede do ranking continua só como lead.
- Roda sozinho no fim da carga da Receita, ou à mão: `php artisan leads:classificar-segmento --dry-run`.
- Atacado de mercadorias em geral (4691-5/00, 4693-1/00) ficou fora do mapa.
- Produção (08/10): 4.296 dos 14.101 leads `sistema` ativos eram supermercado/mercearia;
  o resto é calçados, entrega e logística. Assaí, GPA e Sonda foram ligados à mão (grupos
  60 e 10, códigos 000911 e 005507): o nome do ranking não bate com o do cliente.

### Uma tabela por segmento: redes, clientes e leads (2026-10-13)

Pedido do Tony: além das redes, ver **todo cliente e todo lead** de cada segmento, paginado,
e **"é tudo a mesma coisa"** — uma tabela só, nada de alternar entre "redes" e "clientes".
Decisões dele (não reabrir sem ele pedir):

- **Os 23 segmentos** viram abas: os sete com redes primeiro (ordem da planilha), depois os
  demais do maior para o menor. Nos 16 sem redes a tabela começa direto nos clientes.
- **Redes primeiro**, na ordem do ranking/planilha. A linha da rede continua como era:
  expande para os clientes dela, tem Editar, Observação e Gerar lead.
- **Depois, todos os outros clientes e leads, misturados, por capital social da Receita**
  (do maior para o menor; sem capital vai para o fim).
- **Ninguém duas vezes:** cliente que é de uma rede DO SEGMENTO está dentro da linha da
  rede, não solto; lead ligado à rede com confirmação, idem. Lead cujo CNPJ já é de
  cliente não aparece (CNPJ EXATO, a regra do import — filial de rede que ainda não compra
  continua como lead).
- ⚠️ Foi tentado antes, e recusado no mesmo dia: redes e clientes em duas listas com um
  alternador. "Ficou confuso."

| O que se repete | Onde mora |
|---|---|
| A tabela (redes + avulsos), contagens, paginação, Excel | `App\Services\VisaoDiretor\UniversoDoSegmento` |
| As linhas de rede | `MaioresPorSegmentoResolver::linhas()` (as mesmas do Resumo) |
| Clientes e leads pré-calculados | `visao_diretor_universo` (só `reconstruir()` escreve) |
| A tela | `ContasSegmentoTabela.vue` (linha com `tipo` = rede, cliente ou lead) |
| Excel | recurso `clientes-leads-segmento` no `CatalogoDeExportacoes` |

**Grão.** Cliente entra no segmento se tem ao menos uma filial nele — o mesmo conjunto de
`/carteira?segmento=X` agrupada. Filiais e capital contam só as filiais daquele segmento;
a **última compra é a de todas as filiais**, a mesma da pill da Carteira. Cliente com
filiais em dois segmentos aparece nos dois. Só redes DO segmento recolhem: a Magazine Luiza
(TOTVS 115, rede em Rede de Lojas) aparece solta em Magazines. Lead entra pelo nome do
segmento (`leads.segmento`), fora da etapa "Outros"; **lead sem segmento não aparece**.

**Busca: a rede aparece pelo que está dentro dela (2026-10-13).** Digitar "OBA" traz a
FARTURA, porque o Oba Hortifruti é uma filial dela. Antes o cliente sumia: não aparecia
solto (é da rede) e a rede não aparecia (o nome é outro). Regra única em `BuscaNasRedes`,
usada pela tabela do segmento E pelo Resumo — uma rede casa se casar:
- o nome dela, ou um apelido ("Também conhecida como", `contas_estrategicas.apelidos`,
  marcas separadas por vírgula, editadas no modal — serve à rede-alvo sem cliente ligado);
- o nome fantasia, a razão social ou o CNPJ de uma filial ligada (pelos vínculos);
- o nome, a razão social ou o CNPJ de um lead ligado com confirmação.

A tela diz por quê, embaixo do nome ("encontrado: OBA HORTIFRUTI" / "também conhecida
como: …"), e ao abrir a rede quem casou vem primeiro, destacado.

⚠️ **Casar = COMEÇO DE PALAVRA, nunca "contém"** (`TermoDeBusca`, usado nos quatro lugares
— redes em PHP, filiais/leads/clientes/linha expandida em SQL). Com "contém", "OBA" trazia
SOROCOBA, COBASI e BOBATO e enterrava o que se procurava. Ignora maiúsculas e acentos
("bom preco" acha "Bom Preço"); `%` e `_` digitados são texto. Quatro ou mais dígitos casam
pelo começo do CNPJ.

**Linha de cliente = razão social, nunca o fantasia de uma loja (2026-10-13).** Na tabela e
na linha expandida da rede, a linha é o CLIENTE inteiro (status e última compra de todas
as lojas), e por isso mostra a razão social, como a Carteira agrupada. Antes mostrava o
fantasia da filial-âncora ("REDE OBA - VILA NOVA") ao lado do status do cliente, e parecia
que aquela loja estava ativa. O fantasia continua na busca e aparece na linha de baixo
quando é ele que explica o resultado. "Lojas" na linha expandida abre na Carteira
exatamente aquelas filiais (`conta_alvo` + código + `agrupar=0`).

**Alerta de vínculo suspeito (2026-10-13).** Achado em produção: o código 000242 é a
ESTAPAR (1.626 estacionamentos) e estava ligado ao CARREFOUR e ao MATEUS, porque um ou dois
estacionamentos ficam em loja deles e levam o nome — a sugestão automática puxou o código
inteiro, e a aprovação em massa das sugestões (13/10) o congelou. `VinculosSuspeitos` marca,
nas redes da página aberta (~46 ms em dev), o vínculo que:
- está ligado a **outra rede** também; ou
- tem **10+ filiais e menos de 10%** com o nome da rede (palavras de marca ou apelido) na
  razão social ou no fantasia.

A linha da rede mostra "⚠ N vínculos suspeitos" (clique abre o Editar) e o modal destaca o
vínculo em vermelho com o motivo. Só avisa; quem revisa decide. Rede ligada pela razão social
(Assaí = Sendas) cai na segunda regra até alguém pôr a razão no "Também conhecida como".

**Paginação e números.** A paginação corre sobre a concatenação (as redes ocupam as
primeiras posições). Os tiles contam LINHAS da tabela — uma rede vale uma — e ignoram o
próprio filtro de status; busca e UF valem para redes e clientes. O rodapé "Subtotal das
redes" soma as redes exibidas em **todas** as páginas (vem do servidor).

⚠️ **Invariantes testadas:** (1) avulsos + clientes recolhidos nas redes == total de
`/carteira?segmento=X`; (2) cada tile == total da lista filtrada por ele; (3) a página 2
começa exatamente onde a 1 parou, atravessando de redes para clientes.

**Por que uma tabela pré-calculada.** Ordenar ao vivo por capital (que mora em
`cnpj_situacoes`) custou **820 ms** em Supermercadista (dev, 13/10). Na tabela, com índice
em `(segmento, capital_ordem, id)`, os clientes da página saem em poucos ms; o número de
consultas não cresce com clientes nem com redes (há teste).
- ⚠️ `capital_ordem` = capital ou -1, nunca `ORDER BY capital IS NULL` (expressão não usa
  índice). `lead` é palavra reservada do MySQL (`LEAD()`): alias de contagem é `leads`.
- O **status** é calculado na consulta a partir da data, então não envelhece. O que
  envelhece é quem entra e sai — a tela mostra "atualizada às HH:MM".
- **Quem é da rede é lido ao vivo** (vínculos + `Lead::ligadoAConta`), não gravado na
  tabela: editar os vínculos de uma conta vale no request seguinte.

**Reconstrução** (`php artisan visao-diretor:reconstruir-universo`, transação única; ~14 s
no dev): fim de todo `totvs:atualizar` (está em `AtualizadorTotvs::DERIVADOS`), fim do
`totvs:import-leads` e de hora em hora (:50), para o que muda entre importações.

Testes: `tests/Feature/VisaoDiretor/UniversoDoSegmentoTest.php` (verificado por mutação).

## 2b. Página: Maiores redes

`/visao-diretor/maiores-redes` (2026-10-16). Das maiores redes do **mercado** (planilha
`MAIORES POR SEGMENTO 2026.xlsx` da diretoria, aba BASE 2026), em quais a Autopel atua.
Não é a Maiores por Segmento (que lista NOSSOS clientes por segmento do TOTVS) nem usa
`contas_estrategicas` ("é outra coisa, não é pra juntar", Tony).

### Decisões do Tony (2026-10-16)

- Parêntese com o **dono** (`POSTOS SHELL (RAÍZEN)`) ou **descrição** (`SMART FIT
  (ACADEMIAS)`): fica só a empresa. O dono não entra em nada, nem na vinculação.
- **Bandeiras somadas viram uma linha cada** (Carrefour, Atacadão, Sam's Club). As lojas de
  cada bandeira foram pesquisadas (out/2026); sem dado público, `sem_numero`.
- **Franqueado conta** como loja da rede (vínculo pelo nome fantasia).
- **Status: só Ativo e A trabalhar.** Rede Ativa = pelo menos um cliente ativo (290 dias);
  lead, perdendo, inativo e rede sem ninguém = A trabalhar.
- A linha da rede **abre** e mostra cada cliente/lead com responsável, última compra e
  status — e é ali que admin/diretor **vinculam e tiram** clientes à mão.
- Mesmo gate da seção (`ver-visao-diretor`).

### Dados

| O quê | Onde |
|---|---|
| A planilha limpa | `database/data/visao-diretor/redes-mercado-2026.json`, gerado por `scripts/redes_mercado_xlsx_para_json.py` (decisões linha a linha em `DECISOES`, lojas pesquisadas em `LOJAS`) |
| As 11 categorias e o segmento TOTVS equivalente | `CategoriasDeRede` |
| As redes e os contadores | `redes_mercado` |
| Quem é de cada rede | `redes_mercado_vinculos` (`nivel` nome/grupo/mesma_empresa/manual, `recusado`) |
| Motor de vínculo | `VinculoDeRedes` (única definição de "clientes desta rede") |
| Escrita (carga, reconstrução, manual, contadores) | `MaioresRedes` |
| Leitura da página | `MaioresRedesResolver` |
| Linha de cliente/lead (nome, responsável, última compra) | `DetalheDosVinculos` |

### O motor (`VinculoDeRedes`)

Grão `cod_cliente` (o da Carteira agrupada; cada franqueado é um código). Níveis:
**nome** (começo do fantasia/razão, travas do `VinculoPorCliente`), **grupo** do TOTVS
(pelo nome ou maioria das filiais; 9998 nunca), **mesma_empresa** (raiz do CNPJ calculada em
memória, Regra nº 3). Lead: mesma regra de nome; lead de empresa que já é cliente fica fora.

- ⚠️ **Cada cliente pertence a UMA rede**, garantido pelo banco (coluna gerada `dono`
  única). Desempate: nível (nome > grupo > empresa), nome mais específico (SHELL SELECT >
  SHELL), UF da sede (só para nome/empresa — o grupo não se parte), rede maior. Empate em
  tudo: fica fora (aba Conflitos do Excel de revisão).
- ⚠️ **Categoria sem segmento TOTVS** (Conveniência, Beleza, Óticas, Serviços): a filial
  vale em qualquer segmento; seguram as travas de nome.
- ⚠️ **Grupo do TOTVS de outro segmento entra se o nome dele (com a palavra de tipo) é o da
  rede, ou o começo dele com 2+ palavras** (`grupoDeOutroSegmento`). Caso real: grupo 2109
  MILKY MOO, cliente cadastrado como Supermercadista, rede "MILKY MOO MILKSHAKES". A palavra
  de tipo segura "SUPERMERCADO CARIOCA" fora de "DROGARIAS CARIOCA". Só nesta página.
- Duplicatas da planilha (mesma rede escrita duas vezes) saem no JSON (`pular`).

### Reconstrução e vínculo manual

- `visao-diretor:importar-redes` (carga do JSON + vínculos) e `visao-diretor:vincular-redes`
  (só vínculos). Este roda no fim do `totvs:atualizar` (`AtualizadorTotvs::DERIVADOS`).
  Medido no dev: ~4,4 s para 893 redes.
- ⚠️ **A reconstrução refaz só os vínculos do motor.** `manual` e `recusado` (feitos pela
  tela) nunca são tocados: o código manual sai da disputa, o par recusado não é refeito.
- Vincular move o cliente se ele estava em outra rede. Remover grava `recusado`.
- Rede que sai do JSON numa recarga fica `ativa = false` (some da tela, vínculos guardados).
- Os contadores (clientes, filiais, ativos, leads, vendedor, última compra, status) moram
  na linha da rede; toda escrita termina em `MaioresRedes::recalcular()`. A página não agrega
  nada ao vivo. Vendedor da rede = o que atende mais clientes dela, "(+N)" quando há outros.

### Excel da página

Botão "Gerar Excel" no card do Resumo (todas as redes) e no cabeçalho da tabela de cada aba
(a categoria com os filtros de status e busca). Recurso `maiores-redes` do
`CatalogoDeExportacoes`, mesmo gate da seção. Duas abas: **Redes** (as colunas da tabela) e
**Clientes e leads** (o que a linha aberta mostra de cada rede, com CNPJ e como vinculou).
As redes saem de `MaioresRedesResolver::consulta()` (a query da lista, com os filtros de
`MaioresRedesResolver::filtros()`, os mesmos da tela) e os vínculos de
`MaioresRedes::vinculosDasRedes()` (o mesmo da linha aberta, em lote). Medido no dev: 893
redes em ~70 ms, sem vínculos (o volume real de vínculos só existe em produção).

### CNPJ da rede na Receita (2026-10-17)

Pedido do Tony: saber se as redes estão com o CNPJ **baixado ou inapto**. Opção escolhida:
achar o CNPJ da PRÓPRIA rede (a empresa dona das lojas), não só olhar os nossos clientes —
a maioria das 893 redes não tem cliente nosso.

| O quê | Onde |
|---|---|
| Regra de escolha (nome, ramo, lojas vivas, confiança) | `CnpjDasRedes` |
| Carga (arquivos, gravação) | `visao-diretor:cnpj-das-redes` (`AcharCnpjDasRedes`), domingo 06:00 |
| Download e leitura da base aberta | `App\Services\Receita\BaseAberta` (compartilhado com `receita:importar-situacoes`) |
| Prefixos de CNAE de cada categoria | `CategoriasDeRede::CATEGORIAS[...]['cnaes']` |
| CNPJ, razão, confiança, lojas ativas | colunas `cnpj*` de `redes_mercado` |
| Situação do CNPJ | `cnpj_situacoes` (a carga grava a matriz; a carga semanal mantém) |
| Filiais NOSSAS com CNPJ irregular | contador `redes_mercado.filiais_cnpj_irregular` (`recalcular()`) |

**Como escolhe** (protótipo validado contra os vínculos de produção em 2026-10-16):
1. Razão social começando com a marca da rede (Empresas) → raízes candidatas.
2. Lojas com a marca no nome fantasia, OU de uma raiz da etapa 1 (Estabelecimentos). Conta
   por rede e raiz as lojas **ativas no ramo** da categoria (CNAE).
3. Vence a empresa com mais **lojas vivas** no ramo: incorporada ou fechada perde para a
   que opera (Lojas Americanas antiga, baixada × Americanas S.A.).
4. Confiança: **Confiável** (3+ lojas vivas e 40%+ das lojas ativas com o nome, ou 30%+ das
   lojas da planilha), **Franquia** (o nome em 10+ lojas e ninguém domina — não grava CNPJ),
   **Provável**, **Só achou empresa fechada** (candidata a rede fechada), **Não
   identificado** (não grava CNPJ). `Informado à mão` vem da tela.

A regra de nome é a do vínculo de clientes (`VinculoPorCliente::alternativas()`/`casa()`,
públicas por isso): a rede é a "conta", o nome da Receita é o "cliente".

**Validação** (protótipo, 2026-10-16): das 214 redes "confiáveis" com cliente nosso
vinculado, **172 (80%) batem** com a raiz do CNPJ dos clientes; das 42 que não batem, ~20 são
o CRM ligando a rede a um homônimo ou empresa já baixada, ~17 são clientes ligados pelo
grupo do TOTVS numa empresa sem o nome da rede (não dá para julgar), ~5 são erro do
protótipo (Farmácias São Paulo → Drogaria São Paulo; Rio Park → Allpark). Entre o que dá
para julgar, 90%+ de acerto. "Batem pelo grupo" também conta como acerto: Extrafarma →
Pague Menos, Tumelero → Telhanorte, Pacheco → DPSP.

**Tela**: coluna **Receita** com o veredito da rede NO MERCADO (`SituacaoDaRede`, gravado
em `redes_mercado.situacao_rede` por `recalcular()`): **Ativa** (empresa ATIVA, ou 3+ lojas
ativas com o nome — franquia, marca que seguiu com outra empresa), **Rede fechada** (só
empresa baixada/inapta e nenhuma loja ativa com o nome) ou **Sem informação**; embaixo, o
tamanho dela hoje na Receita. Tile/filtro **Rede fechada** (`?receita=fechada`). Na linha
aberta, o bloco **CNPJ da rede na Receita** com **Corrigir CNPJ** (outro CNPJ, ou "Sem CNPJ
único"). Excel: colunas da Receita e "Situação da rede" na aba Redes.

- ⚠️ **É pesquisa de mercado: as filiais dos NOSSOS clientes não entram no veredito**
  (Tony, 2026-10-17). A 1ª versão contava "filiais nossas irregulares" e chegou a propor
  "cadastro desatualizado" — errado: o Burger King compra da gente com o CNPJ antigo (BGK)
  baixado, e a Raia tem 102 fechadas em 4.062. O contador `filiais_cnpj_irregular` segue
  calculado e só aparece no Excel.
- ⚠️ **O informado à mão a carga não sobrescreve** (`cnpj_confianca = manual`). "Sem CNPJ
  único" também é manual, com `cnpj` nulo.
- ⚠️ A correção usa o cartão CNPJ (`CartaoCnpjService::consultar`) para conferir que o CNPJ
  existe e trazer a razão social; com as fontes fora do ar, salva mesmo assim e a situação
  chega na carga semanal (o `receita:importar-situacoes` inclui `redes_mercado.cnpj` nos
  CNPJs de interesse).
- ⚠️ **A carga lê cada zip DUAS vezes** (empresas → lojas → empresas das finalistas →
  matrizes), então baixa os 20 de uma vez: ~6,5 GB de disco até o fim (a app-2 tem 22 GB
  livres). Só as ~5 raízes que disputam cada rede seguem para a etapa 3 — sem isso seriam
  400 mil empresas na memória. Medido em dev (base 2026-10, zips no disco): **20,6 min**
  (690 + 386 + 53 + 103 s), resultado idêntico ao protótipo.
- Limitações conhecidas: franquia não tem "o CNPJ da rede"; bandeira de outra empresa
  (Carrefour Express) costuma sair Não identificado; nome genérico pode pegar homônimo
  (Bio Ritmo → escola de natação). É para isso que existe a correção manual.

### Excel de revisão

`php artisan visao-diretor:redes-vinculos` grava `storage/app/private/relatorios/
maiores-redes-vinculos-<data>.xlsx`, só lendo: aba POR SEGMENTO 2026 (layout da planilha da
diretoria + vendedor, última compra, status), Redes, Vínculos, Conflitos, Sem vínculo,
Limpeza. Foi a prova pedida antes da tela existir.

### Testes

`VinculoDeRedesTest` (motor; mutações aplicadas: nível, UF, raiz do lead, segmento nulo) e
`MaioresRedesTest` (gate, tiles = lista, manual e recusa sobrevivendo à reconstrução —
verificado por mutação —, unicidade no banco, arquivamento) e `CnpjDasRedesTest` (empresa
viva × antiga baixada, só fechada, franquia, loja fora do ramo, manual preservado, dry-run,
filtro e contador de filiais irregulares, correção pela tela, Excel).

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
