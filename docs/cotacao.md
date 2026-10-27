# Cotação — lista do cliente × estoque de revenda

> Substitui o `Cotacao.exe` (fonte em `Documentos\Sistemas\COMPRAS\cotacao`) e a API que
> ele mantinha na KingHost (`gestao-comercial.autopel.com/cotacao-api/`, tabelas
> `cotacao_decisao*` no `autopel01`), que saiu do ar com a KingHost em 31/10/2026.
> Construído em 2026-10-22.

## O que é

O comercial recebe do cliente uma lista de compras em texto livre ("alcool 1000 ml") e
precisa saber o que temos no estoque ("ALCOOL LIQUIDO 70% 1LT"). A tela `/cotacoes`
recebe a planilha, compara cada item com o estoque de revenda e mostra o que precisa de
conferência humana. **Cada conferência fica guardada para todo mundo**: na próxima lista,
de qualquer cliente e de qualquer pessoa, o mesmo texto já sai "Já validado antes".

Quem usa (decisão do Tony, 22/10): vendedor, representante, supervisor, diretor, admin.
Venda interna e assistente não. **Custo (último preço de compra do Relatório de Revenda) para
vendedor, supervisor, diretor e admin** (vendedor desde 27/10; representante não, por ser externo), na tela, na busca e na planilha. Tudo isso mora em
`App\Services\Cotacao\CotacaoService` (`PERFIS`, `PERFIS_CUSTO`).

## Peças

```
RELATORIOS TOTVS/Relatório revenda/Relatorio de Revenda DD-MM-AAAA.xlsx (um por dia)
  └─ totvs:import-estoque-revenda (de hora em hora, :15) ──▶ estoque_revenda + estoque_revenda_cargas
upload ──▶ cotacoes (processando) ──▶ ProcessarCotacaoJob
  └─ python -I motor/cotacao/cli.py casar ──▶ cotacao_itens
conferência ──▶ cotacao_decisoes (histórico) + cotacao_memoria (decisão atual)
```

| O quê | Onde |
|---|---|
| Motor (normalizar, índice IDF, pontuação, detector de planilha) | `motor/cotacao/*.py` — cópia do .exe, **sem alteração de lógica** |
| Ponte JSON | `motor/cotacao/cli.py` (`casar`, `normalizar`) |
| Perfis, custo, rótulos, memória, chamada ao motor | `app/Services/Cotacao/CotacaoService.php` |
| Telas | `resources/js/Pages/Cotacoes/{Index,Show,Conferir}.vue` |
| Planilha | `app/Exports/CotacaoExport.php`, recurso `cotacao` na central de downloads |
| Estoque | `app/Console/Commands/ImportEstoqueRevendaTotvs.php` |
| Migração do .exe | `cotacao:importar-memoria`, `cotacao:renormalizar-memoria` |

## Decisões e armadilhas

- ⚠️ **A chave da memória é o `texto_normalizado` produzido pelo motor Python.** Nunca
  calcular em PHP. Mudou `dicionarios/sinonimos.json` ou `normalizar.py` →
  `php artisan cotacao:renormalizar-memoria`, senão as decisões antigas param de casar sem
  erro nenhum e os itens voltam para a fila.
- ⚠️ **Aba e coluna (RFP da Sthefany, 22/10).** O arquivo traz capa, a lista a cotar
  (aba RFP, 324 itens) e o histórico "Volumetria". A escolha por célula preenchida
  pegou a Volumetria, e "Categoria Descrição" ganhou de "Descrição" (mesmo peso, a
  primeira vence): 497 linhas viraram 2 categorias que a memória já tinha como "não
  trabalhamos", e a tela disse Conferida, R$ 0. Com mais de uma aba, quem envia
  escolhe qual é a lista — o motor lê só essa. CSV e arquivo de uma aba seguem
  direto. O desconto de VOLUMETRIA/CAPA/RESUMO só vale quando nenhuma aba foi
  marcada. Nome com CATEGORIA não é coluna de descrição. O preço desse RFP
  continua R$ 0 de propósito: a coluna "VALOR C/ IMPOSTOS" está vazia.
- **Paridade com o .exe**: conferida em 22/10 na lista do Almox (589 itens × 4.111 SKUs):
  589 de 589 idênticos em status, score, código, alternativas e motivos, no Windows, no
  Alpine de dev e num Python glibc.
- ⚠️ **O motor é rápido no glibc e lento no Alpine**: ~7 s em glibc (Windows e Ubuntu
  de produção), ~43 s no Docker de dev (Alpine/musl). Quem pesa é o `process.cdist` do
  rapidfuzz. Não é defeito do código; medir latência real na app-2, nunca no dev.
- ⚠️ **numpy está no `requirements.txt` sem ser dependência declarada do rapidfuzz**: o
  `cdist` o importa em tempo de execução. Sem ele o motor quebra só na hora de comparar.
- O venv fica em `/opt/cotacao-venv` nos dois ambientes (`docker/dev/Dockerfile`,
  `infra/provisionar-servidor.sh`); o `infra/deploy.sh` faz o `pip install`. Caminho em
  `config/cotacao.php`.
- **Processar é sempre assíncrono** (Regra nº 9). A decisão de um item volta em ~430 ms
  no dev (piso do `artisan serve` incluído) e só recarrega o próximo item.
- Decidir um item aplica a decisão a **todos os itens da mesma cotação com o mesmo texto
  normalizado** — para o motor são o mesmo produto.
- Histórico append-only: "desfazer" insere `ANULADO` e apaga a decisão atual; o clique
  errado não some da auditoria. "Pular" não grava nada na memória.
- Conflito na importação do .exe: se o CRM já tem decisão diferente para o mesmo texto,
  **fica a do CRM** (é mais nova).

## Estoque (Relatório de Revenda)

**O fluxo (combinado pelo Tony em 22/10/2026):** quem monta o RV salva todo dia um xlsx
NOVO em `RELATORIOS TOTVS/Relatório revenda/` (ex.: `Relatorio de Revenda 22-10-2026.xlsx`),
sem sobrescrever o anterior. A pasta (e o S3) é a coleção de todas as versões; o banco
guarda só a última foto (`estoque_revenda`) e o registro de cada carga
(`estoque_revenda_cargas`: arquivo, data do RV, itens). É o mesmo relatório que o .exe lia,
já no recorte filial 05 / ativos (conferido pelo SRM contra o 026), então não há filtro.

- ⚠️ **A versão que vale é a DATA DO NOME** (`DD-MM-AAAA`, aceita `-`, `_` ou `.`), nunca a
  data de modificação: abrir e salvar um RV antigo o faria "voltar". O resto do nome não
  importa (o primeiro veio como "Revanda"). Arquivo sem data é ignorado e listado na
  saída; o `~$...xlsx` que o Excel cria com a planilha aberta também.
- **Lê a aba `Geral` pelo nome das colunas** (`Codigo`, `Descricao`, `Marca`, `Ult.Preco`,
  `Fisico`, `Reserva`, `Disponivel`, `Comprado`, `Grupo`). No xlsx os números já vêm
  numéricos; texto cai no formato brasileiro, com ou sem "R$". O RV não tem unidade:
  `estoque_revenda.unidade` fica nula.
- ⚠️ **Fora da rodada horária do TOTVS.** A pasta está em `totvs.fora_da_impressao`: sem
  isso, cada RV novo faria o `totvs:atualizar` achar que algo mudou e reimportar
  faturamento, pedidos e clientes inteiros. O import do RV tem agendamento próprio
  (`routes/console.php`, minuto 15, depois do `totvs:sincronizar-s3` do minuto 0) e só
  trabalha quando aparece RV de data mais nova que a carregada; senão lista a pasta e sai.
  `--forcar` recarrega. Primeiro xlsx real: 4.223 itens em ~10 s no dev.
- ⚠️ **Nunca falha**: sem arquivo, sem a aba, colunas erradas ou truncado (< 50% do
  gravado) saem com sucesso, com o motivo na saída, e não tocam a tabela. Quem denuncia
  estoque parado é a `/cotacoes`: a pill "RV de dd/mm" fica âmbar com mais de 2 dias
  úteis, e cada cotação grava de que dia era o RV que usou (`cotacoes.estoque_em`).
- **Envio ao S3**: os dois scripts (`infra/enviar-relatorios-totvs.sh` e `.ps1`) sobem
  `Relat*rio revenda/*.xlsx` (o `*` no lugar do "ó" porque o acento pode chegar ao Linux
  composto ou decomposto, e o `.ps1` precisa ficar ASCII no PowerShell 5.1). O servidor
  baixa todas as versões: ~1,5 MB por dia, ~0,5 GB por ano em disco.
- Retrato completo: código que sumiu do RV sai do estoque. Item de cotação antiga que
  apontava para ele aparece como "Saiu do estoque de revenda".
- Histórico do estoque dia a dia NÃO vai para tabela: é assunto do SRM. Os arquivos estão
  todos na pasta e no S3 se um dia precisar.

## Migração do .exe

1. Snapshot salvo em `storage/app/cotacao/snapshot-kinghost-2026-10-22.json` (44
   decisões, 43 da Sthefany, 1 da Lucivania — as duas são vendedoras).
2. `php artisan cotacao:importar-memoria storage/app/cotacao/snapshot-kinghost-2026-10-22.json`
   (idempotente; casa o usuário do Windows com o e-mail do CRM).
3. Depois do go-live, importar também o `aprendizado/decisoes.csv` de cada .exe: o que
   foi decidido depois que a KingHost saiu do ar ficou só no computador da pessoa.
4. Aposentar o .exe.

## Fora daqui (próximos passos)

- "Gerar orçamento" a partir dos itens conferidos.
- Sinônimos sugeridos (`aprender.py` do .exe) com tela de aprovação.
- Gabarito (`gabarito.csv` do .exe tem 137 linhas e nenhuma resposta) para calibrar os
  limiares do `motor.py`.
