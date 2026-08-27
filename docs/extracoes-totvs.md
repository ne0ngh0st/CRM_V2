# Extrações do TOTVS para o novo CRM Comercial

> Documento de trabalho para a conversa com o Adriano.
> Autopel — CRM Comercial (novo). Agosto/2026.

## 1. O que é isso

O novo CRM Comercial está sendo finalizado e precisa ser alimentado com dados do TOTVS.

Hoje ele é abastecido **de forma indireta**: aproveita os arquivos que já são gerados para o
sistema comercial atual, que passam por uma rotina intermediária antes de chegar. Funciona, mas
significa que o CRM só atualiza depois que o sistema antigo atualiza, e que ele só enxerga os
campos que aqueles relatórios já traziam.

A proposta é o CRM passar a receber os dados direto, em arquivos próprios.

**Importante**: o que está abaixo é o que o CRM precisa do lado de cá — **não é uma
especificação do que tem que ser feito**. Não conheço as limitações do TOTVS. É provável que
parte disso seja inviável, cara, ou simplesmente não exista. A ideia é servir de pauta para
decidirmos juntos o que dá e o que não dá.

## 2. Formato geral proposto

**Um arquivo por cadastro/tabela**, em vez de relatórios que cruzam várias entidades numa linha
só (que é o formato dos atuais). A ideia é que cada extração puxe uma tabela e seus campos —
imagino que seja mais simples de montar assim, mas me corrija se for o contrário.

Cada arquivo com **o máximo de campos possível**, mesmo os que o CRM não usa hoje. Coluna
sobrando não atrapalha nada do lado de cá, e evita voltar depois pedindo "só mais um campo".

São 8 arquivos:

| Arquivo | Conteúdo | Uma linha por |
|---|---|---|
| `clientes.csv` | cadastro de clientes | cliente + loja |
| `produtos.csv` | cadastro de produtos | produto |
| `vendedores.csv` | cadastro de vendedores | vendedor — **✅ já existe** |
| `dominios.csv` | tabelas de código × descrição | código |
| `pedidos.csv` | pedidos (capa) | pedido |
| `pedido_itens.csv` | itens de pedido | item |
| `documentos_fiscais.csv` | notas (capa) | nota |
| `documento_fiscal_itens.csv` | itens de nota | item |

### Legenda das tabelas abaixo

- **🔴 Essencial** — sem esse campo a funcionalidade não existe; não tem como contornar do lado
  do CRM.
- *(sem marca)* — importante, mas se não der, seguimos sem.
- **Campo** — meu palpite de onde sai no Protheus, pelo padrão dos relatórios atuais. **Pode
  estar errado** — o que vale é a descrição.
- **Exemplo** — só para tirar dúvida de formato (data, decimal, CNPJ com ou sem pontuação). Não
  são dados reais.

---

## 3. Os arquivos

### 3.1 `clientes.csv` — uma linha por cliente + loja

| Coluna | Campo | Tipo | Exemplo | Obs. |
|---|---|---|---|---|
| `Filial` | `A1_FILIAL` | texto(2) | `01` | |
| `Cod_Cliente` | `A1_COD` | texto(6) | `001234` | 🔴 |
| `Loja` | `A1_LOJA` | texto(2) | `01` | 🔴 |
| `CNPJ_CPF` | `A1_CGC` | texto(14) | `10970887006569` | só dígitos |
| `Tipo_Pessoa` | `A1_PESSOA` | texto(1) | `J` | |
| `Inscricao_Estadual` | `A1_INSCR` | texto(18) | `116123456789` | |
| `Razao_Social` | `A1_NOME` | texto(60) | `SUPERMERCADO MODELO LTDA` | 🔴 |
| `Nome_Fantasia` | `A1_NREDUZ` | texto(30) | `SUPERMERCADO MODELO` | |
| `Endereco` | `A1_END` | texto(60) | `AV BRASIL` | sem o número |
| `Numero` | — | texto(10) | `1500` | |
| `Complemento` | `A1_COMPLEM` | texto(30) | `GALPAO 2` | |
| `Bairro` | `A1_BAIRRO` | texto(30) | `CENTRO` | |
| `Municipio` | `A1_MUN` | texto(40) | `SAO PAULO` | |
| `Cod_Municipio_IBGE` | `A1_COD_MUN` | texto(7) | `3550308` | |
| `UF` | `A1_EST` | texto(2) | `SP` | |
| `CEP` | `A1_CEP` | texto(8) | `01310100` | só dígitos |
| `DDD` | `A1_DDD` | texto(3) | `11` | |
| `Telefone` | `A1_TEL` | texto(15) | `33334444` | |
| `Celular` | `A1_CELULAR` | texto(15) | `999998888` | |
| `Email_NFe` | `A1_EMAIL` | texto(60) | `fiscal@modelo.com.br` | |
| `Email_Comercial` | — | texto(60) | `compras@modelo.com.br` | se houver campo separado |
| `Contato` | `A1_CONTATO` | texto(40) | `MARIA SILVA` | 🔴 |
| `Cod_Vendedor` | `A1_VEND` | texto(6) | `000042` | 🔴 |
| `Cod_Segmento` | *(Segmento 1)* | texto(6) | `101` | 🔴 só o código |
| `Cod_Grupo_Vendas` | `A1_GRPVEN` | texto(6) | `9998` | 🔴 só o código |
| `Cod_Cond_Pagamento` | `A1_COND` | texto(3) | `007` | |
| `Tipo_Frete_Padrao` | `A1_TPFRET` | texto(1) | `C` | C=CIF, F=FOB |
| `Cod_Transportadora` | `A1_TRANSP` | texto(6) | `000015` | |
| `Limite_Credito` | `A1_LC` | decimal(15,2) | `50000.00` | |
| `Bloqueado` | `A1_MSBLQL` | texto(1) | `N` | |
| `Data_Cadastro` | `A1_DTCAD` | data | `2019-03-14` | |
| `Data_Ultima_Compra` | `A1_ULTCOM` | data | `2026-07-28` | se for mantido atualizado |

---

### 3.2 `produtos.csv` — uma linha por produto

| Coluna | Campo | Tipo | Exemplo | Obs. |
|---|---|---|---|---|
| `Cod_Produto` | `B1_COD` | texto(15) | `BOB80X40` | 🔴 |
| `Descricao` | `B1_DESC` | texto(100) | `BOBINA TERMICA 80X40` | 🔴 |
| `Tipo` | `B1_TIPO` | texto(2) | `PA` | 🔴 separa produto de serviço |
| `Unidade` | `B1_UM` | texto(3) | `UN` | 🔴 |
| `Segunda_Unidade` | `B1_SEGUM` | texto(3) | `CX` | 🔴 |
| `Fator_Conversao` | `B1_CONV` | decimal(12,4) | `30.0000` | 🔴 |
| `Tipo_Conversao` | `B1_TIPCONV` | texto(1) | `M` | 🔴 |
| `Peso_Liquido` | `B1_PESO` | decimal(12,4) | `0.2200` | 🔴 |
| `Peso_Bruto` | `B1_PESBRU` | decimal(12,4) | `0.2400` | 🔴 |
| `Qtd_Embalagem` | `B1_QE` | decimal(12,2) | `30.00` | 🔴 |
| `Tipo_Embalagem` | `B1_EMBALAG` | texto(15) | `CAIXA` | |
| `Cod_Grupo` | `B1_GRUPO` | texto(4) | `0012` | |
| `Categoria_Autopel` | — | texto(20) | `bobina` | bobina/etiqueta/tag/suply/volante, se existir no TOTVS |
| `NCM` | `B1_POSIPI` | texto(8) | `48119010` | |
| `Aliquota_IPI` | `B1_IPI` | decimal(5,2) | `3.25` | |
| `Origem` | `B1_ORIGEM` | texto(1) | `0` | |
| `Preco_Tabela` | `B1_PRV1` | decimal(15,4) | `12.9000` | |
| `Ativo` | `B1_MSBLQL` | texto(1) | `S` | |
| `Data_Ultima_Alteracao` | — | data | `2026-08-01` | |

> **Por que peso e unidade andam juntos**: o vendedor precisa saber quanto um orçamento vai
> pesar para decidir CIF ou FOB. Se o produto é vendido em `MIL` ou `CX` mas o peso está
> cadastrado por `UN`, a conta dá errado sem ninguém perceber. Por isso os campos de unidade e
> conversão são tão necessários quanto o peso.

---

### 3.3 `vendedores.csv` — uma linha por vendedor

**✅ Essa já existe.** Eu já recebo hoje uma tabela nesse formato, e ela atende — tem inclusive
o código de supervisor, que é o campo mais crítico da lista inteira. Pelo que entendi ela é
gerada sob demanda, não automaticamente; o único ajuste seria entrar na rotina diária junto com
os outros arquivos.

Colunas que ela traz hoje:

| Coluna | Exemplo | Obs. |
|---|---|---|
| `COD_VENDEDOR` | `000001` | 🔴 chave |
| `RAZAO_SOCIAL` | `RD` | 🔴 |
| `FANTASIA` | `REPRESENTANTE` | |
| `ESTADO` | `SP` | |
| `TELEFONE` | *(vazio)* | |
| `TIPO` | `EXTERNO` | |
| `EMAIL` | `.` | 🔴 ver nota abaixo |
| `COD_SUPERVISOR` | `010002` | 🔴 **o campo mais importante** |
| `NOME_SUPERVISOR` | `ROBERTO BAROLI` | |
| `COD_GERENTE` | `000054` | |
| `NOME_GERENTE` | `DANIEL DAYAN` | |
| `ATIVO` | `INATIVO` | |

Se for fácil acrescentar, ajudariam: `FILIAL`, `CPF_CNPJ` e `COD_DIRETOR`/`NOME_DIRETOR`.
Se não der, seguimos sem — nenhum deles é bloqueante.

**Duas dúvidas sobre o conteúdo:**

- `TIPO` = `EXTERNO` corresponde a representante, e `INTERNO` a vendedor da casa? É assim que o
  CRM separa os dois perfis.
- `EMAIL` veio como `.` nessa linha. Isso é o preenchimento padrão de e-mail vazio na base
  toda, ou é caso isolado? Pergunto porque **o e-mail é o que liga o vendedor do TOTVS ao
  usuário do CRM** — se boa parte da base estiver assim, preciso de outro jeito de fazer essa
  ligação.

> **Sobre o formato**: os campos vêm com espaços à direita (largura fixa). Isso a gente resolve
> na importação, sem problema nenhum — só vale saber se é assim em todos os arquivos, porque aí
> já trato de uma vez.

> **Por que essa tabela importa tanto**: hoje o CRM usa uma cópia de vendedores feita uma única
> vez, então mudança de supervisor no TOTVS nunca chega até ele. E a hierarquia é o que define
> quem vê qual carteira, o ranking de metas e para quem vai cada aprovação de desconto.

---

### 3.4 `dominios.csv` — código × descrição

Uma linha por código. É a tradução dos códigos que aparecem nos outros arquivos.

| Coluna | Tipo | Exemplo | Obs. |
|---|---|---|---|
| `Tipo_Dominio` | texto(20) | `SEGMENTO` | 🔴 |
| `Codigo` | texto(6) | `101` | 🔴 |
| `Descricao` | texto(60) | `SUPERMERCADISTA` | 🔴 |
| `Ativo` | texto(1) | `S` | |

Tipos necessários:

| `Tipo_Dominio` | Exemplo |
|---|---|
| `SEGMENTO` | `101` = `SUPERMERCADISTA` |
| `GRUPO_VENDAS` | `9998` = `CLIENTES DIVERSOS` |
| `CONDICAO_PAGAMENTO` | `007` = `28 DDL` |
| `STATUS_PEDIDO` | `020` = `AGUARDANDO ARTE` |
| `GRUPO_PRODUTO` | `0012` = `BOBINAS` |
| `TRANSPORTADORA` | `000015` = `TRANSPORTES XYZ` |

> **Se juntar tudo num arquivo só não der** (imagino que sejam tabelas diferentes), um arquivo
> por tipo resolve igual: `dominio_segmento.csv`, `dominio_grupo_vendas.csv`, etc., com as
> mesmas colunas menos a primeira.

> **Sobre `STATUS_PEDIDO`**: hoje o status do pedido em aberto chega só como texto livre no
> campo de histórico, sem padrão — o sistema atual tenta adivinhar procurando pedaços de
> palavra. Se existir no ERP um campo de status com valores fechados, resolve. **Se não
> existir, isso não é problema de extração e sim de configuração** — vale a gente conversar
> sobre o que é viável.

---

### 3.5 `pedidos.csv` — uma linha por pedido

| Coluna | Campo | Tipo | Exemplo | Obs. |
|---|---|---|---|---|
| `Filial` | `C5_FILIAL` | texto(2) | `01` | 🔴 |
| `Numero_Pedido` | `C5_NUM` | texto(6) | `045128` | 🔴 |
| `Cod_Cliente` | `C5_CLIENTE` | texto(6) | `001234` | 🔴 |
| `Loja` | `C5_LOJACLI` | texto(2) | `01` | 🔴 |
| `Cod_Vendedor` | `C5_VEND1` | texto(6) | `000042` | 🔴 |
| `Data_Emissao` | `C5_EMISSAO` | data | `2026-08-05` | 🔴 |
| `Data_Previsao_Faturamento` | — | data | `2026-09-02` | 🔴 |
| `Data_Entrega` | `C5_FECENT` | data | `2026-09-05` | |
| `Data_PCP` | — | data | `2026-08-28` | |
| `Data_Faturamento` | — | data | *(vazio)* | 🔴 vazio = em aberto |
| `Cod_Cond_Pagamento` | `C5_CONDPAG` | texto(3) | `007` | |
| `Tipo_Frete` | `C5_TPFRETE` | texto(1) | `C` | |
| `Cod_Transportadora` | `C5_TRANSP` | texto(6) | `000015` | |
| `Carga` | — | texto(10) | `2026-1180` | |
| `Cod_Status` | — | texto(3) | `020` | ver `STATUS_PEDIDO` acima |
| `Peso_Liquido_Total` | `C5_PESOL` | decimal(12,4) | `158.4000` | |
| `Peso_Bruto_Total` | `C5_PBRUTO` | decimal(12,4) | `172.0000` | |
| `Valor_Total` | — | decimal(15,2) | `8450.00` | 🔴 |
| `Observacao` | — | texto(500) | `AGUARDANDO APROVACAO DE ARTE` | |

---

### 3.6 `pedido_itens.csv` — uma linha por item de pedido

| Coluna | Campo | Tipo | Exemplo | Obs. |
|---|---|---|---|---|
| `Filial` | `C6_FILIAL` | texto(2) | `01` | 🔴 |
| `Numero_Pedido` | `C6_NUM` | texto(6) | `045128` | 🔴 |
| `Sequencia` | `C6_ITEM` | texto(2) | `01` | 🔴 |
| `Cod_Produto` | `C6_PRODUTO` | texto(15) | `BOB80X40` | 🔴 |
| `Descricao` | `C6_DESCRI` | texto(100) | `BOBINA TERMICA 80X40` | |
| `Unidade` | `C6_UM` | texto(3) | `UN` | |
| `Qtd_Vendida` | `C6_QTDVEN` | decimal(12,4) | `720.0000` | 🔴 |
| `Qtd_Liberada` | `C6_QTDLIB` | decimal(12,4) | `360.0000` | |
| `Qtd_Entregue` | `C6_QTDENT` | decimal(12,4) | `360.0000` | |
| `Preco_Unitario` | `C6_PRCVEN` | decimal(15,4) | `11.7400` | 🔴 |
| `Desconto_Pct` | `C6_DESCONT` | decimal(5,2) | `8.50` | |
| `Valor_Total` | `C6_VALOR` | decimal(15,2) | `8452.80` | 🔴 |
| `Peso_Liquido` | — | decimal(12,4) | `158.4000` | |
| `Data_Entrega_Item` | `C6_ENTREG` | data | `2026-09-05` | |
| `Numero_Nota` | `C6_NOTA` | texto(9) | `000145872` | |
| `Serie_Nota` | `C6_SERIE` | texto(3) | `1` | |

> **Por que a sequência do item é essencial**: sem um número estável por item, cada carga
> obriga o CRM a apagar todos os itens do pedido e regravar. Com a sequência, ele atualiza item
> a item — mais rápido e sem risco de duplicar.

---

### 3.7 `documentos_fiscais.csv` — uma linha por nota

| Coluna | Campo | Tipo | Exemplo | Obs. |
|---|---|---|---|---|
| `Filial` | `F2_FILIAL` | texto(2) | `01` | 🔴 |
| `Serie` | `F2_SERIE` | texto(3) | `1` | 🔴 |
| `Numero` | `F2_DOC` | texto(9) | `000145872` | 🔴 |
| `Especie` | `F2_ESPECIE` | texto(5) | `NF` | 🔴 |
| `Tipo` | `F2_TIPO` | texto(1) | `N` | |
| `Numero_RPS` | — | texto(12) | `000000004512` | ver pergunta 2 |
| `Serie_RPS` | — | texto(3) | `A` | |
| `Cod_Cliente` | `F2_CLIENTE` | texto(6) | `001234` | 🔴 |
| `Loja` | `F2_LOJA` | texto(2) | `01` | 🔴 |
| `Cod_Vendedor` | `F2_VEND1` | texto(6) | `000042` | 🔴 |
| `Data_Emissao` | `F2_EMISSAO` | data | `2026-08-06` | 🔴 |
| `Cod_Cond_Pagamento` | `F2_COND` | texto(3) | `007` | |
| `Valor_Produtos` | `F2_VALBRUT` | decimal(15,2) | `8450.00` | 🔴 |
| `Valor_Total` | `F2_VALFAT` | decimal(15,2) | `8724.63` | 🔴 |
| `Valor_IPI` | `F2_VALIPI` | decimal(15,2) | `274.63` | |
| `Valor_ICMS` | `F2_VALICM` | decimal(15,2) | `1520.00` | |
| `Valor_ISS` | `F2_VALISS` | decimal(15,2) | `0.00` | |
| `Peso_Liquido` | `F2_PESOL` | decimal(12,4) | `158.4000` | |
| `Peso_Bruto` | `F2_PESOB` | decimal(12,4) | `172.0000` | |
| `Chave_NFe` | `F2_CHVNFE` | texto(44) | `35260812345678000199550010001458721234567890` | |
| `Cancelada` | — | texto(1) | `N` | 🔴 nota cancelada não pode contar como faturamento |
| `Data_Cancelamento` | — | data | *(vazio)* | |

---

### 3.8 `documento_fiscal_itens.csv` — uma linha por item de nota

| Coluna | Campo | Tipo | Exemplo | Obs. |
|---|---|---|---|---|
| `Filial` | `D2_FILIAL` | texto(2) | `01` | 🔴 |
| `Serie` | `D2_SERIE` | texto(3) | `1` | 🔴 |
| `Numero` | `D2_DOC` | texto(9) | `000145872` | 🔴 |
| `Sequencia` | `D2_ITEM` | texto(2) | `01` | 🔴 |
| `Cod_Produto` | `D2_COD` | texto(15) | `BOB80X40` | 🔴 |
| `Descricao` | — | texto(100) | `BOBINA TERMICA 80X40` | |
| `Unidade` | `D2_UM` | texto(3) | `UN` | |
| `Quantidade` | `D2_QUANT` | decimal(12,4) | `720.0000` | 🔴 |
| `Preco_Unitario` | `D2_PRCVEN` | decimal(15,4) | `11.7400` | 🔴 |
| `Valor_Total` | `D2_TOTAL` | decimal(15,2) | `8452.80` | 🔴 |
| `Peso_Liquido` | `D2_PESO` | decimal(12,4) | `158.4000` | |
| `CFOP` | `D2_CF` | texto(4) | `5102` | |
| `Numero_Pedido` | `D2_PEDIDO` | texto(6) | `045128` | 🔴 liga a nota ao pedido |
| `Sequencia_Pedido` | `D2_ITEMPV` | texto(2) | `01` | |

---

## 4. Formato dos arquivos

Tudo aqui é preferência — se o gerador não permitir, a gente adapta do lado do CRM.

| Item | Preferência | Exemplo |
|---|---|---|
| Delimitador | `;` | |
| Encoding | UTF-8 | |
| Cabeçalho | linha 1 com os nomes das colunas, sem linha de título antes | |
| Data | `AAAA-MM-DD` | `2026-08-13` |
| Campo vazio | vazio mesmo | não `0`, `00/00/0000` nem `N/A` |
| Decimal | ponto, sem separador de milhar | `8450.00` |
| Texto | sem quebra de linha dentro do campo | atenção no campo de observação |
| Nome do arquivo | fixo, sem data no nome | `clientes.csv` |

**Frequência**: diária, de madrugada, como já é feito hoje. A tabela de vendedores, que hoje é
gerada sob demanda, entraria nessa mesma rotina.

**Cadastros** (clientes, produtos, vendedores, domínios): arquivo completo a cada dia.
**Movimento** (pedidos e notas): se houver campo de data de alteração, só o que mudou; senão,
uma janela dos últimos 60-90 dias serve.

---

## 5. Entrega dos arquivos

Duas coisas, e a segunda é a que mais importa:

**Onde gravar.** Um diretório fixo, no lugar que for mais prático — o CRM vai lá buscar em vez
de receber. Assim horário e nova tentativa em caso de falha ficam do nosso lado, e quando o
sistema for para o servidor definitivo não precisa mexer em nada aí.

**Um arquivo avisando que terminou.** Depois de gravar todos, criar um arquivo vazio
(`pronto.txt`). O CRM só começa a importar quando ele aparece. Sem isso, se a leitura pegar um
arquivo no meio da gravação, entra dado pela metade **sem dar erro nenhum** — é o tipo de
problema que só aparece semanas depois.

---

## 6. Perguntas

### Premissas minhas — me corrija se estiver errado

Desenhei o documento assumindo estas três coisas. Se alguma não procede, muda bastante o que
faz sentido pedir:

- **Que dá para montar extração nova internamente**, sem depender de chamado ou consultoria da
  TOTVS. Se depender, faz mais sentido tentar encaixar o máximo possível expandindo os
  relatórios que já rodam, e cortar o resto.
- **Que os campos de peso e embalagem do cadastro de produto estão preenchidos.**
- **Que a tabela de vendedores que eu já recebo hoje** pode entrar na geração automática sem
  grande esforço (ver 3.3).

### Perguntas

1. **Uma amostra do cadastro de produto** — umas 50 linhas com `Cod_Produto`, `Descricao`,
   `Unidade`, `Segunda_Unidade`, `Fator_Conversao`, `Peso_Liquido` e `Qtd_Embalagem` juntos.
   O que preciso enxergar é **a qual unidade o peso se refere**: se a bobina é vendida em caixa
   e o peso está cadastrado por unidade, a conta de frete sai errada sem dar erro nenhum. É o
   único jeito de confirmar isso antes de construir.
2. **Onde fica o RPS?** A emissão de nota de serviço passa pelo TOTVS, pela prefeitura, ou por
   outro sistema? Se for fora, existe algum retorno que traga o número de volta?
3. **O campo de contato do cliente existe e está preenchido?** É um contato ou vários?
4. **Existe um status de pedido com valores fechados no ERP,** ou só o texto livre do histórico?
5. **O preço de venda do cadastro de produto é confiável?** Hoje a tabela de preços do CRM vem
   de planilha subida à mão.
6. **A data de última compra do cliente é mantida atualizada** no cadastro?
7. **Existe data de última alteração** nas tabelas de pedido e nota? Define se a carga pode ser
   só do que mudou.
8. **Um pedido pode gerar mais de uma nota** (faturamento parcial)?
9. **Duas dúvidas sobre a tabela de vendedores** (detalhe em 3.3): `TIPO` = `EXTERNO`
   corresponde a representante? E o e-mail preenchido com `.` é padrão da base ou caso isolado?

---

## 7. Uma observação sobre os relatórios atuais

Os relatórios que rodam hoje (cadastro de clientes, pedidos em aberto, meta de vendas, cadastro
de produto com NCM) **continuam do jeito que estão** — eles alimentam o sistema comercial atual,
que segue no ar. As extrações novas conviveriam com eles, e só quando o CRM novo assumir é que
faria sentido desligar as antigas.
