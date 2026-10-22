# -*- coding: utf-8 -*-
"""Ponte entre o Laravel e o motor de cotacao. So JSON entra e sai.

    python -I cli.py casar --pedido lista.xlsx --catalogo cat.json \\
                           --memoria mem.json --saida resultado.json
    python -I cli.py normalizar --entrada textos.json --saida chaves.json

O motor (normalizar, indice, motor, detector) e o mesmo do Cotacao.exe, sem
alteracao de logica: o Laravel cuida de banco, arquivo e tela; daqui so sai a
comparacao. Erro de leitura da lista do cliente sai com codigo 2 e a mensagem
em stderr, para a tela mostrar o motivo em vez de "falhou".
"""
import argparse
import json
import sys
from pathlib import Path

# `python -I` nao poe a pasta do script no sys.path (e e por isso que se usa
# -I: nada do diretorio corrente entra). Os modulos do motor moram aqui ao lado.
sys.path.insert(0, str(Path(__file__).resolve().parent))

import detector  # noqa: E402
from motor import Catalogo, casar  # noqa: E402
from normalizar import normalizar  # noqa: E402

CAMPOS_PEDIDO = ("cod_cliente", "descricao", "subgrupo", "unidade", "marca",
                 "qtd_periodo", "valor_periodo", "ordens", "preco_unit")


def _ler_json(caminho):
    with open(caminho, encoding="utf-8") as f:
        return json.load(f)


def _gravar_json(caminho, dados):
    with open(caminho, "w", encoding="utf-8") as f:
        json.dump(dados, f, ensure_ascii=False)


def _catalogo(itens):
    # O motor le estes campos; o resto do registro do Laravel e ignorado.
    return [{
        "codigo": str(it["codigo"]),
        "descricao": str(it["descricao"]),
        "marca": str(it.get("marca") or ""),
        "preco": float(it.get("preco") or 0),
        "fisico": float(it.get("fisico") or 0),
        "reserva": float(it.get("reserva") or 0),
        "disponivel": float(it.get("disponivel") or 0),
        "comprado": float(it.get("comprado") or 0),
        "grupo": str(it.get("grupo") or ""),
    } for it in itens]


def cmd_casar(args):
    try:
        leitura = detector.ler_pedido(args.pedido)
    except ValueError as e:
        print(str(e), file=sys.stderr)
        return 2
    pedidos = detector.consolidar(leitura.registros)
    if not pedidos:
        print("Nao encontrei produtos nesse arquivo. Confira se a planilha tem "
              "uma coluna com a descricao dos itens.", file=sys.stderr)
        return 2

    catalogo = Catalogo(_catalogo(_ler_json(args.catalogo)))
    if len(catalogo) == 0:
        print("O estoque de revenda esta vazio.", file=sys.stderr)
        return 2

    resultados = casar(pedidos, catalogo, memoria=_ler_json(args.memoria))

    itens = []
    for r in resultados:
        p = r["pedido"]
        item = {c: p.get(c) for c in CAMPOS_PEDIDO}
        item.update({
            "texto_normalizado": p["norm"],
            "status": r["status"],
            "score": r["score"],
            "motivos": r["motivos"],
            "codigo": r["item"]["codigo"] if r["item"] else None,
            "alternativas": [{"codigo": it["codigo"], "score": round(s, 1)}
                             for s, it in r["alternativas"]],
        })
        itens.append(item)

    _gravar_json(args.saida, {
        "leitura": {
            "aba": leitura.aba,
            "linha_cabecalho": leitura.linha_cabecalho,
            "avisos": leitura.avisos,
            "resumo": leitura.resumo(),
        },
        "catalogo": len(catalogo),
        "itens": itens,
    })
    return 0


def cmd_normalizar(args):
    textos = _ler_json(args.entrada)
    _gravar_json(args.saida, [normalizar(t) for t in textos])
    return 0


def main(argv=None):
    ap = argparse.ArgumentParser()
    sub = ap.add_subparsers(dest="comando", required=True)

    c = sub.add_parser("casar")
    c.add_argument("--pedido", required=True)
    c.add_argument("--catalogo", required=True)
    c.add_argument("--memoria", required=True)
    c.add_argument("--saida", required=True)
    c.set_defaults(func=cmd_casar)

    n = sub.add_parser("normalizar")
    n.add_argument("--entrada", required=True)
    n.add_argument("--saida", required=True)
    n.set_defaults(func=cmd_normalizar)

    args = ap.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
