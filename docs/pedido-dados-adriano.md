# Extrações TOTVS → CRM-V2 — especificação de campos

> O que o CRM-V2 precisa receber do TOTVS, campo a campo, com tipo e exemplo.
> Criado em 2026-08-13.

## 0. Contexto e como ler

O TOTVS aqui é fechado: **sem acesso ao banco, sem API**. Tudo sai por relatório gerado e
gravado em arquivo, agendado de madrugada. Isso define duas coisas no desenho abaixo:

**Um arquivo por tabela do ERP.** Não são relatórios que cruzam pedido com cliente com produto
numa linha só (o que os relatórios atuais fazem). Cada extração puxa uma tabela e seus campos.
Para o gerador de relatório isso costuma ser mais simples, não mais difícil.

**Cada arquivo é o mais largo possível.** O custo aqui está em *criar* uma extração, não no
número de colunas dela. Trinta campos ou oito custa quase o mesmo; voltar depois pedindo "só
mais uma coluna" custa outra rodada inteira. Por isso a lista inclui campos que o CRM não usa
hoje — coluna sobrando no CSV não atrapalha nada.

### O que é obrigatório e o que é conveniência

🔴 **Sem isto, a funcionalidade não existe** — não tem tratamento na importação que invente:
peso e unidade do produto, quantidade por embalagem, código de supervisor do vendedor,
descrição dos códigos de domínio, `Cod_Cliente`+`Loja` em tudo que fale de cliente, sequência
do item, contato do cliente.

🟡 **Isto a gente resolve do lado do CRM se não der** — formato do arquivo, separar cabeçalho
de item, carga incremental, encoding, nome do arquivo, ordem das colunas. Se vier tudo achatado
num arquivo só como hoje, funciona; só dá mais trabalho aqui.

⚫ **Isto pode ser impossível e vale saber logo**: `Cod_Status` do pedido (se o campo não existir
configurado no ERP, nenhum relatório inventa — vira customização) e `Numero_RPS` (se a emissão
de serviço não passa pelo TOTVS, não tem de onde tirar).

### Nomes de campo do Protheus

As colunas `Campo` abaixo são palpite, baseado no vocabulário dos relatórios atuais (`.RLT`,
`COD_CLIENT`/`LOJA`, `GrpVendas`, `Pos.IPI/NCM`, `Grupo Trib.`, `Nome Reduzid`). **Adriano
confirma.** Se o campo tiver outro nome ou não existir, o que vale é a descrição de negócio.
Os exemplos de célula são ilustrativos — servem para tirar dúvida de formato, não são dados
reais.

---

## 1. `clientes.csv` — cadastro de clientes (SA1)

**Grão**: 1 linha por cliente + loja. Snapshot completo a cada carga.

| Coluna | Campo | Tipo | Exemplo | Nota |
|---|---|---|---|---|
| `Filial` | `A1_FILIAL` | texto(2) | `01` | |
| `Cod_Cliente` | `A1_COD` | texto(6) | `001234` | 🔴 chave |
| `Loja` | `A1_LOJA` | texto(2) | `01` | 🔴 chave |
| `CNPJ_CPF` | `A1_CGC` | texto(14) | `10970887006569` | só dígitos, sem pontuação |
| `Tipo_Pessoa` | `A1_PESSOA` | texto(1) | `J` | F ou J |
| `Inscricao_Estadual` | `A1_INSCR` | texto(18) | `116123456789` | |
| `Razao_Social` | `A1_NOME` | texto(60) | `SUPERMERCADO MODELO LTDA` | |
| `Nome_Fantasia` | `A1_NREDUZ` | texto(30) | `SUPERMERCADO MODELO` | |
| `Endereco` | `A1_END` | texto(60) | `AV BRASIL` | logradouro, sem número |
| `Numero` | — | texto(10) | `1500` | hoje vem junto no endereço |
| `Complemento` | `A1_COMPLEM` | texto(30) | `GALPAO 2` | |
| `Bairro` | `A1_BAIRRO` | texto(30) | `CENTRO` | |
| `Municipio` | `A1_MUN` | texto(40) | `SAO PAULO` | hoje só temos UF e CEP |
| `Cod_Municipio_IBGE` | `A1_COD_MUN` | texto(7) | `3550308` | |
| `UF` | `A1_EST` | texto(2) | `SP` | |
| `CEP` | `A1_CEP` | texto(8) | `01310100` | só dígitos |
| `DDD` | `A1_DDD` | texto(3) | `11` | |
| `Telefone` | `A1_TEL` | texto(15) | `33334444` | |
| `Celular` | `A1_CELULAR` | texto(15) | `999998888` | |
| `Email_NFe` | `A1_EMAIL` | texto(60) | `fiscal@modelo.com.br` | é o que temos hoje — é fiscal |
| `Email_Comercial` | — | texto(60) | `compras@modelo.com.br` | se existir campo separado |
| `Contato` | `A1_CONTATO` | texto(40) | `MARIA SILVA` | 🔴 |
| `Cod_Vendedor` | `A1_VEND` | texto(6) | `000042` | 🔴 define a carteira |
| `Cod_Segmento` | *(Segmento 1)* | texto(6) | `101` | 🔴 só o código; nome vem de `dominios` |
| `Cod_Grupo_Vendas` | `A1_GRPVEN` | texto(6) | `9998` | 🔴 idem |
| `Cod_Cond_Pagamento` | `A1_COND` | texto(3) | `007` | |
| `Tipo_Frete_Padrao` | `A1_TPFRET` | texto(1) | `C` | C=CIF, F=FOB |
| `Cod_Transportadora` | `A1_TRANSP` | texto(6) | `000015` | |
| `Limite_Credito` | `A1_LC` | decimal(15,2) | `50000.00` | decidir se vendedor pode ver |
| `Bloqueado` | `A1_MSBLQL` | texto(1) | `N` | S/N |
| `Data_Cadastro` | `A1_DTCAD` | data | `2019-03-14` | separa cliente novo de antigo inativo |
| `Data_Ultima_Compra` | `A1_ULTCOM` | data | `2026-07-28` | **se existir, elimina um relatório** |

> `Data_Ultima_Compra` hoje vem de um relatório separado só pra isso (`Ultimo faturamento`).
> Se `A1_ULTCOM` for mantido atualizado, aquele relatório pode ser desligado.

---

## 2. `produtos.csv` — cadastro de produtos (SB1)

**Grão**: 1 linha por código de produto. Snapshot completo.

| Coluna | Campo | Tipo | Exemplo | Nota |
|---|---|---|---|---|
| `Cod_Produto` | `B1_COD` | texto(15) | `BOB80X40` | 🔴 chave |
| `Descricao` | `B1_DESC` | texto(100) | `BOBINA TERMICA 80X40` | |
| `Tipo` | `B1_TIPO` | texto(2) | `PA` | 🔴 discrimina produto × serviço |
| `Unidade` | `B1_UM` | texto(3) | `UN` | 🔴 unidade de venda |
| `Segunda_Unidade` | `B1_SEGUM` | texto(3) | `CX` | 🔴 |
| `Fator_Conversao` | `B1_CONV` | decimal(12,4) | `30.0000` | 🔴 |
| `Tipo_Conversao` | `B1_TIPCONV` | texto(1) | `M` | 🔴 M=multiplica, D=divide |
| `Peso_Liquido` | `B1_PESO` | decimal(12,4) | `0.2200` | 🔴 por 1 unidade de venda |
| `Peso_Bruto` | `B1_PESBRU` | decimal(12,4) | `0.2400` | 🔴 é o que conta pro frete |
| `Qtd_Embalagem` | `B1_QE` | decimal(12,2) | `30.00` | 🔴 "quantidade na caixa" |
| `Tipo_Embalagem` | `B1_EMBALAG` | texto(15) | `CAIXA` | |
| `Cod_Grupo` | `B1_GRUPO` | texto(4) | `0012` | nome vem de `dominios` |
| `Categoria_Autopel` | — | texto(20) | `bobina` | bobina/etiqueta/tag/suply/volante — hoje é de-para manual em Excel; pode não existir no TOTVS |
| `NCM` | `B1_POSIPI` | texto(8) | `48119010` | |
| `Aliquota_IPI` | `B1_IPI` | decimal(5,2) | `3.25` | o CRM tem 3,25% fixo hoje |
| `Origem` | `B1_ORIGEM` | texto(1) | `0` | |
| `Preco_Tabela` | `B1_PRV1` | decimal(15,4) | `12.9000` | **se confiável, elimina o upload manual de preço** |
| `Ativo` | `B1_MSBLQL` | texto(1) | `S` | |
| `Data_Ultima_Alteracao` | — | data | `2026-08-01` | permite carga incremental |

> ⚠️ **Peso sem unidade não serve pra nada.** Se a bobina é vendida em `MIL` ou `CX` e o peso
> está cadastrado por `UN`, o cálculo `peso × quantidade` dá número absurdo e ninguém percebe —
> parece só que o sistema errou o frete. Por isso as quatro colunas de unidade/conversão são
> tão obrigatórias quanto o peso em si.

---

## 3. `vendedores.csv` — cadastro de vendedores (SA3)

**Grão**: 1 linha por código de vendedor. Snapshot completo.

Hoje isso **não existe** como extração — os vendedores vieram de um dump feito uma vez da base
do sistema antigo. Quer dizer que mudança de supervisor no TOTVS nunca chega ao CRM, e a
hierarquia é o que define escopo de carteira, ranking de metas, visão do gestor e para quem vai
a notificação de aprovação de orçamento.

| Coluna | Campo | Tipo | Exemplo | Nota |
|---|---|---|---|---|
| `Filial` | `A3_FILIAL` | texto(2) | `01` | |
| `Cod_Vendedor` | `A3_COD` | texto(6) | `000042` | 🔴 chave |
| `Nome` | `A3_NOME` | texto(40) | `JOANA PEREIRA` | |
| `Nome_Reduzido` | `A3_NREDUZ` | texto(20) | `JOANA` | |
| `CPF_CNPJ` | `A3_CGC` | texto(14) | `12345678901` | |
| `Email` | `A3_EMAIL` | texto(60) | `joana.pereira@autopel.com` | 🔴 casa o vendedor com o usuário do CRM |
| `Cod_Supervisor` | `A3_SUPER` | texto(6) | `000007` | 🔴 **a hierarquia é o que falta hoje** |
| `Cod_Gerente` | — | texto(6) | `000003` | |
| `Cod_Diretor` | — | texto(6) | `000001` | |
| `Tipo` | `A3_TIPO` | texto(1) | `I` | I=interno, R=representante |
| `Ativo` | `A3_MSBLQL` | texto(1) | `S` | |

---

## 4. `dominios.csv` — tabelas de domínio (SX5 / SE4)

**Grão**: 1 linha por tipo + código. Snapshot completo.

Hoje o nome do segmento e do grupo de vendas são **raspados de uma tabela de faturamento**, o
que já quebrou o cálculo de aderência em silêncio e deixa ~331 códigos de segmento e 27 de
grupo sem nome nenhum na tela.

| Coluna | Tipo | Exemplo | Nota |
|---|---|---|---|
| `Tipo_Dominio` | texto(20) | `SEGMENTO` | 🔴 chave |
| `Codigo` | texto(6) | `101` | 🔴 chave |
| `Descricao` | texto(60) | `SUPERMERCADISTA` | 🔴 |
| `Ativo` | texto(1) | `S` | |

Tipos necessários:

| `Tipo_Dominio` | Exemplo de linha | Para quê |
|---|---|---|
| `SEGMENTO` | `101` = `SUPERMERCADISTA` | cálculo de aderência da carteira |
| `GRUPO_VENDAS` | `9998` = `CLIENTES DIVERSOS` | coluna Grupo da carteira |
| `CONDICAO_PAGAMENTO` | `007` = `28 DDL` | pedido, cliente e nota |
| `STATUS_PEDIDO` | `020` = `AGUARDANDO ARTE` | ⚫ ver abaixo |
| `GRUPO_PRODUTO` | `0012` = `BOBINAS` | catálogo |
| `TRANSPORTADORA` | `000015` = `TRANSPORTES XYZ` | se vier código de transportadora |

> **Se o gerador não conseguir juntar tudo num arquivo só** (é uma união de tabelas diferentes,
> pode não dar), tudo bem: um arquivo pequeno por tipo — `dominio_segmento.csv`,
> `dominio_grupo_vendas.csv` etc. Mesmas quatro colunas, sem a coluna `Tipo_Dominio`. 🟡

> ⚫ **`STATUS_PEDIDO` é a pendência mais antiga.** Hoje o status do pedido em aberto só existe
> como texto livre num campo de histórico, sem padrão — o sistema antigo "resolvia" procurando
> pedaços de palavra (`BLOQ`, `LIBER`, `AGUARD`) com expressão regular no navegador. Não é
> status, é adivinhação. O CRM-V2 marca todo pedido em aberto como "Aguardando classificação do
> TOTVS" até existir código de verdade. **Se esse campo não existir configurado no ERP, isso
> não é problema de extração — é customização.**

---

## 5. `pedidos.csv` — cabeçalho de pedido (SC5)

**Grão**: 1 linha por pedido.

| Coluna | Campo | Tipo | Exemplo | Nota |
|---|---|---|---|---|
| `Filial` | `C5_FILIAL` | texto(2) | `01` | 🔴 chave |
| `Numero_Pedido` | `C5_NUM` | texto(6) | `045128` | 🔴 chave |
| `Cod_Cliente` | `C5_CLIENTE` | texto(6) | `001234` | 🔴 |
| `Loja` | `C5_LOJACLI` | texto(2) | `01` | 🔴 |
| `Cod_Vendedor` | `C5_VEND1` | texto(6) | `000042` | 🔴 |
| `Data_Emissao` | `C5_EMISSAO` | data | `2026-08-05` | |
| `Data_Previsao_Faturamento` | — | data | `2026-09-02` | alimenta o calendário de programações |
| `Data_Entrega` | `C5_FECENT` | data | `2026-09-05` | |
| `Data_PCP` | — | data | `2026-08-28` | |
| `Data_Faturamento` | — | data | *(vazio)* | 🔴 **vazio = pedido em aberto** |
| `Cod_Cond_Pagamento` | `C5_CONDPAG` | texto(3) | `007` | |
| `Tipo_Frete` | `C5_TPFRETE` | texto(1) | `C` | C=CIF, F=FOB |
| `Cod_Transportadora` | `C5_TRANSP` | texto(6) | `000015` | |
| `Carga` | — | texto(10) | `2026-1180` | |
| `Cod_Status` | — | texto(3) | `020` | ⚫ ver `STATUS_PEDIDO` |
| `Peso_Liquido_Total` | `C5_PESOL` | decimal(12,4) | `158.4000` | |
| `Peso_Bruto_Total` | `C5_PBRUTO` | decimal(12,4) | `172.0000` | |
| `Valor_Total` | — | decimal(15,2) | `8450.00` | |
| `Observacao` | — | texto(500) | `AGUARDANDO APROVACAO DE ARTE` | o campo de histórico de hoje |

---

## 6. `pedido_itens.csv` — itens de pedido (SC6)

**Grão**: 1 linha por item de pedido.

| Coluna | Campo | Tipo | Exemplo | Nota |
|---|---|---|---|---|
| `Filial` | `C6_FILIAL` | texto(2) | `01` | 🔴 chave |
| `Numero_Pedido` | `C6_NUM` | texto(6) | `045128` | 🔴 chave |
| `Sequencia` | `C6_ITEM` | texto(2) | `01` | 🔴 chave — ver nota |
| `Cod_Produto` | `C6_PRODUTO` | texto(15) | `BOB80X40` | |
| `Descricao` | `C6_DESCRI` | texto(100) | `BOBINA TERMICA 80X40` | |
| `Unidade` | `C6_UM` | texto(3) | `UN` | |
| `Qtd_Vendida` | `C6_QTDVEN` | decimal(12,4) | `720.0000` | |
| `Qtd_Liberada` | `C6_QTDLIB` | decimal(12,4) | `360.0000` | |
| `Qtd_Entregue` | `C6_QTDENT` | decimal(12,4) | `360.0000` | |
| `Preco_Unitario` | `C6_PRCVEN` | decimal(15,4) | `11.7400` | |
| `Desconto_Pct` | `C6_DESCONT` | decimal(5,2) | `8.50` | |
| `Valor_Total` | `C6_VALOR` | decimal(15,2) | `8452.80` | |
| `Peso_Liquido` | — | decimal(12,4) | `158.4000` | peso do item |
| `Data_Entrega_Item` | `C6_ENTREG` | data | `2026-09-05` | |
| `Numero_Nota` | `C6_NOTA` | texto(9) | `000145872` | ver nota abaixo |
| `Serie_Nota` | `C6_SERIE` | texto(3) | `1` | |

> **Por que `Sequencia` é obrigatória** 🔴: sem um número estável de item, a importação é
> obrigada a apagar todos os itens do pedido e reinserir a cada carga. Foi exatamente isso que
> produziu 583 pedidos com itens duplicados/misturados aqui. Com sequência, vira atualização
> item a item e o problema não existe.

> **Por que a nota aparece no item, não só no cabeçalho**: é assim que faturamento parcial
> funciona — um pedido pode ser faturado em várias notas, item a item. `Qtd_Liberada` existir
> separada de `Qtd_Vendida` sugere que isso acontece de verdade aqui.

---

## 7. `documentos_fiscais.csv` — cabeçalho de nota (SF2)

**Grão**: 1 linha por documento fiscal.

| Coluna | Campo | Tipo | Exemplo | Nota |
|---|---|---|---|---|
| `Filial` | `F2_FILIAL` | texto(2) | `01` | 🔴 chave |
| `Serie` | `F2_SERIE` | texto(3) | `1` | 🔴 chave — nota sem série é ambígua |
| `Numero` | `F2_DOC` | texto(9) | `000145872` | 🔴 chave |
| `Especie` | `F2_ESPECIE` | texto(5) | `NF` | discrimina NF-e × nota de serviço |
| `Tipo` | `F2_TIPO` | texto(1) | `N` | normal / devolução / complementar |
| `Numero_RPS` | *(a descobrir)* | texto(12) | `000000004512` | ⚫ ver seção 10 |
| `Serie_RPS` | *(a descobrir)* | texto(3) | `A` | ⚫ |
| `Cod_Cliente` | `F2_CLIENTE` | texto(6) | `001234` | 🔴 |
| `Loja` | `F2_LOJA` | texto(2) | `01` | 🔴 |
| `Cod_Vendedor` | `F2_VEND1` | texto(6) | `000042` | |
| `Data_Emissao` | `F2_EMISSAO` | data | `2026-08-06` | |
| `Cod_Cond_Pagamento` | `F2_COND` | texto(3) | `007` | |
| `Valor_Produtos` | `F2_VALBRUT` | decimal(15,2) | `8450.00` | |
| `Valor_Total` | `F2_VALFAT` | decimal(15,2) | `8724.63` | |
| `Valor_IPI` | `F2_VALIPI` | decimal(15,2) | `274.63` | |
| `Valor_ICMS` | `F2_VALICM` | decimal(15,2) | `1520.00` | |
| `Valor_ISS` | `F2_VALISS` | decimal(15,2) | `0.00` | serviço |
| `Peso_Liquido` | `F2_PESOL` | decimal(12,4) | `158.4000` | |
| `Peso_Bruto` | `F2_PESOB` | decimal(12,4) | `172.0000` | |
| `Chave_NFe` | `F2_CHVNFE` | texto(44) | `35260812345678000199550010001458721234567890` | permite linkar pro DANFE |
| `Cancelada` | — | texto(1) | `N` | 🔴 **nota cancelada não pode contar como faturamento** |
| `Data_Cancelamento` | — | data | *(vazio)* | |

---

## 8. `documento_fiscal_itens.csv` — itens de nota (SD2)

**Grão**: 1 linha por item de documento fiscal.

| Coluna | Campo | Tipo | Exemplo | Nota |
|---|---|---|---|---|
| `Filial` | `D2_FILIAL` | texto(2) | `01` | 🔴 chave |
| `Serie` | `D2_SERIE` | texto(3) | `1` | 🔴 chave |
| `Numero` | `D2_DOC` | texto(9) | `000145872` | 🔴 chave |
| `Sequencia` | `D2_ITEM` | texto(2) | `01` | 🔴 chave |
| `Cod_Produto` | `D2_COD` | texto(15) | `BOB80X40` | |
| `Descricao` | — | texto(100) | `BOBINA TERMICA 80X40` | |
| `Unidade` | `D2_UM` | texto(3) | `UN` | |
| `Quantidade` | `D2_QUANT` | decimal(12,4) | `720.0000` | |
| `Preco_Unitario` | `D2_PRCVEN` | decimal(15,4) | `11.7400` | |
| `Valor_Total` | `D2_TOTAL` | decimal(15,2) | `8452.80` | |
| `Peso_Liquido` | `D2_PESO` | decimal(12,4) | `158.4000` | peso realizado |
| `CFOP` | `D2_CF` | texto(4) | `5102` | |
| `Numero_Pedido` | `D2_PEDIDO` | texto(6) | `045128` | 🔴 liga de volta ao pedido |
| `Sequencia_Pedido` | `D2_ITEMPV` | texto(2) | `01` | 🔴 ligação no grão de item |

---

## 9. Formato dos arquivos

Vale para os 8. Tudo nesta seção é 🟡 — se o gerador não permitir, a gente adapta.

| Item | Preferência | Exemplo |
|---|---|---|
| Delimitador | `;` | |
| Encoding | UTF-8 | |
| Cabeçalho | linha 1 = nomes das colunas, sem linha de título antes | |
| Data | `AAAA-MM-DD` | `2026-08-13` |
| Vazio | campo vazio de verdade | não `0`, `00/00/0000` nem `N/A` |
| Decimal | ponto, sem separador de milhar | `8450.00`, não `8.450,00` |
| Texto | sem quebra de linha dentro do campo | risco no campo de observação |
| Nome do arquivo | fixo, sem data no nome | `clientes.csv` |

**Marcador de fim de gravação** — isto vale insistir mesmo que o resto seja flexível: depois de
gravar todos os arquivos, escrever um arquivo vazio (`pronto.txt`). A importação só roda quando
ele aparece. Sem isso, se a leitura começar no meio da escrita, entra CSV cortado e o sistema
importa dado parcial **sem dar erro nenhum**.

**Onde gravar**: um diretório fixo que o Adriano já use hoje. O CRM vai lá buscar em vez de
receber — assim o horário e a repetição em caso de falha ficam do nosso lado, e quando o sistema
migrar pro servidor na AWS quem muda é a gente, não ele.

---

## 10. Perguntas ao Adriano — antes de ele construir qualquer coisa

Cada uma muda a forma de alguma extração. Perguntar é bem mais barato que receber CSV com
coluna vazia.

1. **`B1_PESO`, `B1_PESBRU` e `B1_QE` estão preenchidos de verdade, ou só em parte do cadastro?**
   Pedir amostra de ~200 linhas e contar. Se a cobertura for baixa, o cálculo de peso no
   orçamento não sai do papel.
2. **Onde vive o RPS?** Emissão de serviço passa pelo TOTVS, pela prefeitura, ou outro sistema?
   Se for fora, existe retorno que traga o número? Se não, esse campo morre aqui.
3. **`A1_CONTATO` existe e está populado? É um contato ou vários?** No sistema antigo alguém
   criou uma coluna de contato na marra, o que sugere que o TOTVS nunca entregou isso. Se
   confirmar, contato vira dado nativo do CRM e a alteração cadastral fica bem mais simples.
4. **`A1_ULTCOM` é mantido atualizado?** Se sim, elimina um relatório inteiro.
5. **`B1_PRV1` é o preço de venda real?** Se sim, elimina o upload manual de tabela de preço.
6. **`B1_IPI` varia por produto?** O CRM tem 3,25% fixo hoje.
7. **Existe data de última alteração** nas tabelas de pedido e nota? Define se a carga
   incremental é por alteração ou por janela móvel de dias.
8. **Qual o conjunto real de status do pedido, e ele existe como campo no ERP?**
9. **Faturamento parcial acontece?** Um pedido pode gerar mais de uma nota?
10. **Criar relatório novo depende de chamado/consultoria, ou ele mesmo monta?** Isso decide se
    a gente insiste em extração nova ou tenta encaixar tudo expandindo os relatórios que já
    existem.

---

## 11. O que isso substitui

| Hoje | Passa a ser |
|---|---|
| `210 - CADASTRO DE CLIENTES.RLT` | `clientes.csv` (expandido) |
| `Ultimo faturamento - SQL.csv` | **desligado** — `Data_Ultima_Compra` vem no cliente |
| `200 - PEDIDOS EM ABERTO COM STATUS.RLT` | `pedidos.csv` + `pedido_itens.csv` |
| `232 - META VENDA.RLT` | idem — `Data_Faturamento` vazia já discrimina aberto de faturado, não precisa de dois relatórios pra isso |
| `FATURAMENTO` | `documentos_fiscais.csv` + `documento_fiscal_itens.csv` |
| `005 - CADASTRO DE PRODUTO COM NCM.RLT` | `produtos.csv` (expandido) |
| `CODIGO PRODUTOS - SQL.xlsx` (de-para manual) | `produtos.Categoria_Autopel`, se existir no TOTVS |
| upload manual de tabela de preço | `produtos.Preco_Tabela`, se `B1_PRV1` for confiável |
| dump pontual de usuários do sistema antigo | `vendedores.csv` |
| nomes raspados da tabela de faturamento | `dominios.csv` |

> ⚠️ **Pedir extrações NOVAS, não alterar o `200`, `232`, `210` e `005`.** Esses ainda alimentam
> o sistema antigo em produção — mexer neles derruba o que está rodando. As novas convivem com
> as antigas até o CRM-V2 assumir, e só então as velhas são desligadas.

---

## 12. Tipos no banco do CRM-V2

Referência interna, não vai pro Adriano.

| Tipo na especificação | MySQL |
|---|---|
| `texto(n)` | `VARCHAR(n)` |
| `data` | `DATE` |
| `decimal(12,4)` | `DECIMAL(12,4)` — quantidade e peso |
| `decimal(15,2)` | `DECIMAL(15,2)` — valor em reais |
| `decimal(15,4)` | `DECIMAL(15,4)` — preço unitário |
| `decimal(5,2)` | `DECIMAL(5,2)` — percentual |
| `texto(1)` S/N | `BOOLEAN` na importação |

Chaves e índices: chave natural de cada extração vira `UNIQUE`; `Cod_Cliente`+`Loja`,
`Cod_Vendedor`, `Cod_Produto` e as colunas de data usadas em filtro entram como índice.

**Não** armazenar nome de domínio junto do código (segmento, grupo, condição) — só o código,
com o nome resolvido por join contra a tabela de domínios. Guardar o nome junto foi o que
quebrou o cálculo de aderência em silêncio uma vez.
