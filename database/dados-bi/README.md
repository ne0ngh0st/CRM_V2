# Dados de referência do BI

Exportados do `autopel01` (MariaDB do PALMA legado, KingHost) em **2026-09-16**, antes do
desligamento previsto para 31/10/2026. São a única cópia fora do legado: não existem no
TOTVS nem no CRM-V2.

| Arquivo | Linhas | Destino |
|---|---:|---|
| `IBGE_MUNICIPIOS.csv` | 5.571 | `bi.ibge_municipios` |
| `de_para_municipio.csv` | 5.623 | `bi.de_para_municipio` (IBGE + ~52 exceções de grafia) |
| `IBGE_INDICADORES_MUNICIPIO.csv` | 5.570 | `bi.indicadores_municipio` |
| `potencial_mercado_estado.csv` | 27 | `bi.potencial_mercado_estado` |
| `METAS_MENSAIS.csv` | 4.811 | **não é carregado** — só conferência. O BI lê `metas_mensais` do CRM |
| `ddl-tabelas-referencia.sql` | — | DDL original das tabelas acima |
| `views-legado.sql` | — | Definição das views `vw_bi_*` do legado, só referência |

## Formato dos CSVs

Export do phpMyAdmin, e o carregador (`bi:carregar-referencias`) depende exatamente disto:

- UTF-8 **com BOM**;
- separador `;`, todo campo entre aspas duplas;
- decimal com **vírgula** (`10526,00`), sem separador de milhar;
- data e hora em `dd/mm/aaaa hh:mm:ss`.

⚠️ Reexportar com outras opções (vírgula como separador, ponto decimal) quebra a carga.
