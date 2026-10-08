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
sete segmentos (Supermercadista, Drogarias, Rede de Lojas, Alimentação, Postos, Material de
Construção, Estacionamentos), com o número de filiais que cada uma tem no Brasil. É o
retrato das maiores redes do país, **onde já atendemos e onde não** — a função da página.

A tela é **uma aba por segmento**: Supermercadista primeiro (não existe na planilha, ver
abaixo), depois a ordem da planilha (Drogarias, Rede de Lojas, Alimentação, Postos,
Construção, Estacionamentos), mais o **Resumo** no lugar da aba DASHBOARD. As abas existem
mesmo sem conta cadastrada. A troca de aba é local —
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
