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
Venda interna e assistente não. **Custo (último preço de compra do 026) só para
supervisor, diretor e admin**, na tela, na busca e na planilha. Tudo isso mora em
`App\Services\Cotacao\CotacaoService` (`PERFIS`, `PERFIS_CUSTO`).

## Peças

```
RELATORIOS TOTVS/CSV/Estoque necessidade - SQL.csv (026)
  └─ totvs:import-estoque-revenda ──▶ estoque_revenda
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

## Estoque (relatório 026)

Recorte do RV: **filial 05, ATIVO, tipo RV/PA** (conferido pelo SRM, 4.170/4.170 itens).
Só as colunas que a Cotação usa — necessidade, fornecedor e consumo são do SRM.

- ⚠️ **O import nunca falha a corrente do `totvs:atualizar`**: arquivo ausente, colunas
  erradas ou arquivo truncado (< 50% do que está gravado) saem com sucesso, com o motivo
  na saída da `/atualizacoes`, e não tocam a tabela. Quem denuncia estoque parado é a
  própria `/cotacoes` (pill "Estoque de dd/mm", âmbar depois de 2 dias úteis).
- ⚠️ **Até 22/10 o 026 só tinha sido visto em xlsx.** Os nomes de coluna vêm do
  dicionário do SRM e os números assumem o formato dos outros CSVs ("1.234,56"). Conferir
  o primeiro export real com `totvs:import-estoque-revenda --dry-run`.
- Nome do arquivo em `config/totvs.php` (`estoque_revenda`): `CSV/Estoque necessidade*SQL.csv`
  ou `CSV/026*.csv`. O envio para o S3 já cobre `CSV/*.csv`.

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
