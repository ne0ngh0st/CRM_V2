"""
Ranking ABRAS (PDF da associação) -> database/data/visao-diretor/ranking-abras-<ano>.json

    python -I scripts/abras_pdf_para_json.py <ranking.pdf> <saida.json> [--base <json atual>]

Consumido por `php artisan diretor:importar-ranking-abras`. Ver docs/visao-diretor.md,
"Aba Supermercadista".

- O faturamento é DESCARTADO (decisão do Tony, 2026-10-08: sem faturamento na página).
- Com --base, as posições que já existem no JSON atual mantêm `nome` e `razoes` curados à
  mão (o nome curto de "CARREFOUR COMÉRCIO E INDÚSTRIA" é "CARREFOUR"); as novas ganham o
  nome derivado da razão social, sem sufixo societário. Quem decide se um nome é genérico
  demais é o import (SugestaoDeVinculo), não este script.
- Para com erro se a sequência de posições tiver buraco ou repetição. A única correção
  aceita é o número cortado pelo PDF ("29" no lugar de "229"): o fim da posição esperada.
"""

import json
import re
import sys

from pypdf import PdfReader

# " 1.014 956 AUTOSSERVICO W. L. LTDA. - ME ES 1.675.203"
LINHA = re.compile(r"^\s*([\d.]+)\s+([\d.]+|Nova)\s+(.+?)\s+([A-Z]{2})\s+[\d][\d .]*$")

SUFIXOS = re.compile(
    r"(\s*[-–]\s*(ME|EPP)\.?|\s+(LTDA|LIMITADA)\.?|\s+S[./]?\s?A\.?|\s+EIRELI|\s+EIRELE|\s+(ME|EPP)\.?"
    r"|\s*&\s*CIA\.?|\s+E\s+CIA\.?)\s*$",
    re.IGNORECASE,
)


def numero(s: str) -> int:
    return int(s.replace(".", ""))


def nome_curto(razao: str) -> str:
    nome = razao.strip()
    while True:
        novo = SUFIXOS.sub("", nome).strip(" .,-")
        if novo == nome or not novo:
            return nome
        nome = novo


def extrair(pdf: str) -> list[dict]:
    linhas = []
    for pagina in PdfReader(pdf).pages:
        for bruta in (pagina.extract_text() or "").splitlines():
            m = LINHA.match(bruta)
            if m:
                linhas.append(m.groups())

    ranking, esperada = [], 1
    for pos, pos25, razao, uf in linhas:
        p = numero(pos)
        if p != esperada:
            if str(esperada).endswith(str(p)):
                print(f"aviso: posição {pos!r} lida como {esperada} ({razao})", file=sys.stderr)
                p = esperada
            else:
                sys.exit(f"erro: esperava a posição {esperada}, veio {pos!r} ({razao})")
        ranking.append({
            "posicao": p,
            "posicao_2025": None if pos25 == "Nova" else numero(pos25),
            "razao_social": re.sub(r"\s+", " ", razao).strip(),
            "uf": uf,
        })
        esperada += 1

    return ranking


def main() -> None:
    args = sys.argv[1:]
    base = None
    if "--base" in args:
        i = args.index("--base")
        base = args[i + 1]
        del args[i:i + 2]
    if len(args) != 2:
        sys.exit(__doc__)
    pdf, saida = args

    curados = {}
    if base:
        with open(base, encoding="utf-8") as f:
            curados = {r["posicao"]: r for r in json.load(f)["ranking"]}

    ranking = []
    for r in extrair(pdf):
        antigo = curados.get(r["posicao"])
        # O PDF é a verdade. Foi assim que apareceu o erro da primeira extração (08/10): a
        # linha cortada "29 239 ORGANIZAÇÃO MARINGA" (a 229) tomou o lugar da 29 real.
        if antigo and antigo["razao_social"] != r["razao_social"]:
            print(f"aviso: posição {r['posicao']} divergia do JSON ({antigo['razao_social']!r}); "
                  f"vale o PDF ({r['razao_social']!r}) — conferir o nome curto", file=sys.stderr)
            antigo = None
        item = {**r, "nome": antigo["nome"] if antigo else nome_curto(r["razao_social"])}
        # Ordem das chaves igual à do JSON curado (diff legível).
        item = {k: item[k] for k in ("posicao", "posicao_2025", "razao_social", "nome", "uf")}
        if antigo and antigo.get("razoes"):
            item["razoes"] = antigo["razoes"]
        ranking.append(item)

    # Empresas homônimas ("LEMOS SUPERMERCADO" PE e RN): o import acha a conta pelo nome, e
    # dois do ranking com o mesmo nome virariam "ambíguo" na segunda rodada. A UF separa;
    # se não separar (dois "AVÍCOLA CENTRAL" na PB), a posição.
    for chave in ("uf", "posicao"):
        contagem = {}
        for r in ranking:
            contagem[r["nome"]] = contagem.get(r["nome"], 0) + 1
        for r in ranking:
            if contagem[r["nome"]] > 1:
                r["nome"] = f"{r['nome']} ({r[chave]})" if chave == "uf" else f"{r['nome'][:-1]}, {r['posicao']})"

    with open(saida, "w", encoding="utf-8", newline="\n") as f:
        json.dump({
            "fonte": f"Ranking ABRAS 2026 — todas as {len(ranking)} posições, extraídas por "
                     "scripts/abras_pdf_para_json.py (posicao_2025 nulo = entrou no ranking este "
                     "ano). `razoes` = razões sociais conhecidas quando a ABRAS publica só a marca.",
            "ranking": ranking,
        }, f, ensure_ascii=False, indent=2)
        f.write("\n")

    print(f"{len(ranking)} posições gravadas em {saida}")


if __name__ == "__main__":
    main()
