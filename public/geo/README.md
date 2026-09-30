# Geografia estática do mapa da Carteira

Lidos pelo navegador em `Components/Carteira/MapaCarteira.vue`. O servidor só manda
contagem por código IBGE; a coordenada vem daqui e fica no cache do navegador.

| Arquivo | O que é | Fonte | Baixado em |
|---|---|---|---|
| `municipios.json` | `{ "<código IBGE>": [lat, lng, "Nome", "UF"] }` — coordenada da **sede** de cada município (5.570) | `csv/municipios.csv` de [kelvins/municipios-brasileiros](https://github.com/kelvins/municipios-brasileiros), licença MIT | 2026-09-30 |
| `ufs.json` | GeoJSON do contorno das 27 UFs, qualidade mínima | API de malhas do IBGE: `servicodados.ibge.gov.br/api/v3/malhas/paises/BR?formato=application/vnd.geo+json&qualidade=minima&intrarregiao=UF` | 2026-09-30 |
| `mesorregioes.json` | GeoJSON das 137 mesorregiões, qualidade mínima, coordenadas arredondadas a 3 casas; `properties.nome` acrescentado | Mesma API, `intrarregiao=mesorregiao`; o nome vem de `database/dados-bi/IBGE_MUNICIPIOS.csv` | 2026-09-30 |

- **O quinto elemento de cada município em `municipios.json` é o código da mesorregião**,
  tirado do mesmo `IBGE_MUNICIPIOS.csv`. ⚠️ O SERVIDOR lê este arquivo para contar os
  clientes de cada mesorregião (`App\Services\Geografia\Mesorregioes`), e o navegador lê
  o mesmo arquivo para saber em que região desenhar cada cidade — é o que garante que o
  número do selo e o contorno usam a mesma divisão. Ao regenerar, manter os dois lados.
- `mesorregioes.json` tem 538 KB (134 KB comprimido) e só é baixado quando alguém entra
  num estado.

- A chave de `municipios.json` é o mesmo código de `clientes.cod_municipio` (que vem do
  `de_para_municipio` do schema do BI). Município criado depois desta data não tem
  coordenada aqui: a bolha não é desenhada, mas o cliente continua na lista e na conta.
- É a **sede**, não o centroide do polígono: num município grande o centroide cai no
  meio do mato, e a sede é onde os clientes estão.
- `municipios.json` foi gerado do CSV trocando `codigo_uf` pela sigla; as demais colunas
  (SIAFI, DDD, fuso) foram descartadas.
