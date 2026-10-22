# -*- coding: utf-8 -*-
"""
Normalizacao de descricoes de produto + extracao de atributos estruturados.

A ideia central: antes de comparar dois textos, transformamos ambos numa forma
canonica ("ALCOOL 1000 ML" e "ALCOOL LIQUIDO 1LT" viram a mesma unidade de
volume) e extraimos atributos duros (volume, peso, embalagem, dimensao) que
depois funcionam como VETO no matching -- porque 1L e 500ML podem ter textos
quase identicos e nunca sao o mesmo produto.
"""
import json
import re
import unicodedata
from pathlib import Path

import config

BASE = Path(__file__).parent
_SINONIMOS = None


def carregar_sinonimos():
    global _SINONIMOS
    if _SINONIMOS is None:
        with open(config.ARQ_SINONIMOS, encoding="utf-8") as f:
            _SINONIMOS = json.load(f)
    return _SINONIMOS


def sem_acento(txt):
    txt = unicodedata.normalize("NFKD", txt)
    return "".join(c for c in txt if not unicodedata.combining(c))


# ---------------------------------------------------------------- unidades
# fator de conversao para a unidade canonica de cada familia
VOLUME = {"ML": 1, "MILILITRO": 1, "MILILITROS": 1,
          "L": 1000, "LT": 1000, "LTS": 1000, "LITRO": 1000, "LITROS": 1000}
MASSA = {"MG": 0.001, "G": 1, "GR": 1, "GRS": 1, "GRAMA": 1, "GRAMAS": 1,
         "KG": 1000, "KGS": 1000, "QUILO": 1000}
COMPRIMENTO = {"MM": 0.1, "CM": 1, "M": 100, "MT": 100, "MTS": 100,
               "METRO": 100, "METROS": 100}

_NUM = r"(\d+(?:[.,]\d+)?)"
RE_VOLUME = re.compile(_NUM + r"\s*(" + "|".join(sorted(VOLUME, key=len, reverse=True)) + r")\b")
RE_MASSA = re.compile(_NUM + r"\s*(" + "|".join(sorted(MASSA, key=len, reverse=True)) + r")\b")
RE_COMPR = re.compile(_NUM + r"\s*(" + "|".join(sorted(COMPRIMENTO, key=len, reverse=True)) + r")\b")
# embalagem: C/100, CX C/ 50, PCT COM 12, C/100UN
RE_PACK = re.compile(r"\bC(?:OM)?\s*/?\s*(\d+(?:\.\d{3})*)\s*(?:UN|UND|UNI|UNID|UNIDADES?|FLS?|FOLHAS?|PCS?)?\b")
# dimensao: 48X50, 76X102, 0,88X1,88, "18 MM X 50 M", 240X340MM
# a unidade no meio e opcional e e DESCARTADA de proposito: cada fabricante
# escreve a mesma fita como 18X50M, 18MM X 50M ou 1,8CM X 50M. Guardamos os
# numeros crus e deixamos a comparacao tolerar a escala (ver combinam_dim).
_UNID_DIM = r"(?:\s*(?:MM|CM|MTS|MT|M)(?![A-Z]))?"
# o (?<![A-Z]) impede que a dimensao case DENTRO de um token que ja foi
# canonizado: sem ele, "CAIXA C/ 8 X 300" vira "PK8 X 300" e a dimensao
# come o "8 X 300" de volta, deixando um "PK" solto sem quantidade.
RE_DIM = re.compile(r"(?<![A-Z])" + _NUM + _UNID_DIM + r"\s*X\s*" + _NUM + _UNID_DIM)


def _f(txt):
    """'1.000' -> 1000 ; '0,88' -> 0.88"""
    txt = txt.strip()
    if re.fullmatch(r"\d{1,3}(\.\d{3})+", txt):      # separador de milhar
        return float(txt.replace(".", ""))
    return float(txt.replace(",", "."))


def combinam_dim(a, b):
    """(24, 34) e (240, 340) sao a mesma medida em unidades diferentes.

    So aceita quando o MESMO fator serve para os dois lados -- (24,34) x
    (240,34) continua sendo conflito.
    """
    if a is None or b is None:
        return None
    for fator in (1, 10, 100, 1000):
        if all(abs(x * fator - y) < 0.01 for x, y in zip(a, b)):
            return True
        if all(abs(x - y * fator) < 0.01 for x, y in zip(a, b)):
            return True
    return False


def extrair_atributos(texto):
    """Le os atributos duros do texto BRUTO (antes de destruir a pontuacao)."""
    t = sem_acento(texto.upper())
    at = {"volume_ml": None, "massa_g": None, "compr_cm": None,
          "pack": None, "dim": None}

    # A ORDEM IMPORTA e o texto vai sendo consumido: quem casa primeiro tira
    # o trecho do caminho dos proximos. "CAIXA C/ 8 X 300 M" e uma caixa com
    # 8 rolos de 300m -- se a dimensao ler antes, vira "8x300" e inventa um
    # conflito com a nossa descricao que nunca existiu.
    m = RE_PACK.search(t)
    if m:
        at["pack"] = _f(m.group(1))
        t = t[:m.start()] + " " + t[m.end():]

    m = RE_VOLUME.search(t)
    if m:
        at["volume_ml"] = round(_f(m.group(1)) * VOLUME[m.group(2)], 3)
        t = t[:m.start()] + " " + t[m.end():]

    m = RE_MASSA.search(t)
    if m:
        at["massa_g"] = round(_f(m.group(1)) * MASSA[m.group(2)], 3)
        t = t[:m.start()] + " " + t[m.end():]

    m = RE_DIM.search(t)
    if m:
        at["dim"] = tuple(sorted((_f(m.group(1)), _f(m.group(2)))))
    else:
        m = RE_COMPR.search(t)
        if m:
            at["compr_cm"] = round(_f(m.group(1)) * COMPRIMENTO[m.group(2)], 3)
    return at


# ---------------------------------------------------------------- texto
STOPWORDS = {"DE", "DA", "DO", "DAS", "DOS", "E", "A", "O", "AS", "OS",
             "EM", "NO", "NA", "PARA", "COM", "SEM", "REF", "TIPO", "UN"}


def normalizar(texto):
    """Texto canonico para comparacao fuzzy."""
    sin = carregar_sinonimos()
    t = sem_acento(str(texto).upper())

    # medidas viram token canonico -> '1LT' e '1000 ML' colapsam, '48X50M' e
    # '48 x 50' tambem. Mesma ordem de extrair_atributos, pelo mesmo motivo:
    # o pack ("C/ 8") sai da frente antes de a dimensao olhar o texto.
    t = RE_PACK.sub(lambda m: f" PK{_f(m.group(1)):g} ", t)
    t = RE_VOLUME.sub(lambda m: f" VOL{_f(m.group(1)) * VOLUME[m.group(2)]:g} ", t)
    t = RE_MASSA.sub(lambda m: f" MAS{_f(m.group(1)) * MASSA[m.group(2)]:g} ", t)
    t = RE_DIM.sub(lambda m: " DIM{:g}X{:g} ".format(*sorted((_f(m.group(1)), _f(m.group(2))))), t)

    t = t.replace("P/", " PARA ").replace("C/", " COM ").replace("S/", " SEM ")
    t = re.sub(r"[^A-Z0-9]+", " ", t)

    toks = []
    for tk in t.split():
        tk = sin.get(tk, tk)                 # expande abreviacao
        if tk in STOPWORDS:
            continue
        toks.append(tk)
    return " ".join(toks)


def token_cabeca(texto_normalizado):
    """Substantivo principal: primeiro token 'de conteudo'.

    Serve de ancora -- 'ALCOOL' e 'ALGODAO' sao textualmente parecidos mas
    produtos distintos; sem ancora o fuzzy erra feio.
    """
    for tk in texto_normalizado.split():
        if len(tk) >= 4 and not tk[0].isdigit() and not tk.startswith(("VOL", "MAS", "PK", "DIM")):
            return tk
    toks = texto_normalizado.split()
    return toks[0] if toks else ""
