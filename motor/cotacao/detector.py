# -*- coding: utf-8 -*-
"""
Le a planilha do cliente sem saber o layout dela.

Cada cliente manda de um jeito: aba com nome qualquer, cabecalho na linha 7,
coluna chamada "Ds material" ou "ESPECIFICACAO DO OBJETO" ou "Produto", as
vezes nem cabecalho tem. Fixar indice de coluna quebra no proximo arquivo.

Aqui a escolha e feita por evidencia -- nome da coluna E conteudo dela -- e
o resultado sai com o grau de confianca, para o operador conferir o
mapeamento antes de confiar no cruzamento. Quando a deteccao erra, o jeito
de corrigir e passar --coluna descricao=E na linha de comando, sem mexer no
codigo.
"""
import csv
import re
import unicodedata
from pathlib import Path

import openpyxl

MAX_LINHAS_CABECALHO = 25    # ate onde procuramos a linha de cabecalho
MIN_LINHAS_DADOS = 3


def _chave(txt):
    txt = unicodedata.normalize("NFKD", str(txt or "").upper())
    txt = "".join(c for c in txt if not unicodedata.combining(c))
    return re.sub(r"[^A-Z0-9]+", " ", txt).strip()


# Cada campo e reconhecido por DUAS evidencias que precisam concordar:
#   nome   - como a coluna se chama
#   evitar - nomes parecidos que sao outra coisa ("Cd material" nao e descricao)
#   forma  - o que o conteudo da coluna precisa parecer
# So o nome nao basta: num arquivo real "Cd material" e "Ds material" convivem,
# e as duas casam com a palavra MATERIAL.
PADROES = {
    "descricao": {
        "nome": (r"\b(DESCRICAO|DS MATERIAL|DESCR|ESPECIFICACAO|DISCRIMINACAO|"
                 r"PRODUTO|MATERIAL|ITEM|OBJETO|MERCADORIA|DENOMINACAO)\b", 10),
        "evitar": r"^(CD|COD|CODIGO|NR|N|QT|VL|DT)\b|\b(GRUPO|LOCAL|TIPO|CLASSE)\b",
        "forma": lambda pf: pf["txt"] > 0.5 and pf["tam"] >= 8,
    },
    "cod_cliente": {
        "nome": (r"\b(CD MATERIAL|CODIGO|COD|CD|REF|REFERENCIA|SKU)\b", 8),
        "evitar": r"\b(LOCAL|DEPOSITO|ESTOQUE|EMPRESA|FILIAL|CENTRO|"
                  r"ORDEM|PEDIDO|FORNECEDOR|BARRA)\b",
        "forma": lambda pf: pf["distintos"] > 0.01,
    },
    "qtd": {
        "nome": (r"\b(QT|QTD|QTDE|QUANT|QUANTIDADE|QT ORIGINAL|QT MATERIAL|"
                 r"QT SOLICITADA)\b", 9),
        "evitar": r"\b(VL|VALOR|PRECO|SALDO|ESTOQUE)\b",
        "forma": lambda pf: pf["num"] > 0.7,
    },
    "unidade": {
        "nome": (r"\b(UN|UND|UNID|UNIDADE|UNIDADE MEDIDA|UM|EMBALAGEM|"
                 r"APRESENTACAO)\b", 7),
        "evitar": r"\b(VL|VALOR|PRECO|UNITARIO)\b",
        "forma": lambda pf: pf["txt"] > 0.5 and pf["tam"] <= 12,
    },
    "preco_unit": {
        "nome": (r"\b(VL UNITARIO|VALOR UNITARIO|PRECO UNITARIO|PRECO UNIT|"
                 r"VL UNIT|UNITARIO|PRECO|VALOR UNIT)\b", 8),
        "evitar": r"\b(TOTAL|GERAL)\b",
        "forma": lambda pf: pf["num"] > 0.7,
    },
    "valor_total": {
        "nome": (r"\b(VL TOTAL ITEM|VALOR TOTAL|VL TOTAL|TOTAL ITEM|TOTAL)\b", 7),
        "evitar": r"\b(ORDEM|GERAL|PEDIDO)\b",
        "forma": lambda pf: pf["num"] > 0.7,
    },
    "subgrupo": {
        "nome": (r"\b(SUB GRUPO|SUBGRUPO|GRUPO|CATEGORIA|FAMILIA|CLASSE|"
                 r"SEGMENTO|LINHA)\b", 6),
        "evitar": r"\b(CD|COD|CODIGO)\b",
        "forma": lambda pf: pf["txt"] > 0.5,
    },
    "marca": {
        "nome": (r"\b(MARCA|FABRICANTE|FORNECEDOR)\b", 7),
        "evitar": r"\b(CD|COD|CODIGO|NOME FANTASIA)\b",
        "forma": lambda pf: pf["txt"] > 0.5,
    },
}

# palavras que denunciam uma aba de tabela dinamica / resumo, nao de dados
ABAS_RUINS = re.compile(r"\b(DINAMICA|RESUMO|GRAFICO|CAPA|INSTRUCOES|TOTAIS?)\b")


# --------------------------------------------------------------- leitura crua
def _ler_xlsx(caminho):
    """Devolve [(nome_aba, matriz_de_valores)]."""
    wb = openpyxl.load_workbook(caminho, read_only=True, data_only=True)
    abas = []
    for nome in wb.sheetnames:
        ws = wb[nome]
        linhas = [list(r) for r in ws.iter_rows(values_only=True)]
        abas.append((nome, linhas))
    wb.close()
    return abas


def _ler_csv(caminho):
    for enc in ("utf-8-sig", "latin-1"):
        try:
            with open(caminho, encoding=enc, newline="") as f:
                amostra = f.read(8192)
                f.seek(0)
                try:
                    dial = csv.Sniffer().sniff(amostra, delimiters=";,\t|")
                except csv.Error:
                    dial = csv.excel
                    dial.delimiter = ";" if amostra.count(";") > amostra.count(",") else ","
                return [(Path(caminho).stem, [list(r) for r in csv.reader(f, dial)])]
        except UnicodeDecodeError:
            continue
    raise ValueError("nao consegui ler o CSV em utf-8 nem latin-1")


def _carregar(caminho):
    ext = Path(caminho).suffix.lower()
    if ext in (".csv", ".txt", ".tsv"):
        return _ler_csv(caminho)
    return _ler_xlsx(caminho)


# ------------------------------------------------------------ escolha da aba
def _densidade(linhas):
    """Quantas celulas preenchidas em media -- separa dados de resumo."""
    if not linhas:
        return 0.0
    cheias = sum(1 for lin in linhas for c in lin if c not in (None, ""))
    return cheias / max(len(linhas), 1)


def escolher_aba(abas):
    melhor, melhor_nota = None, -1.0
    for nome, linhas in abas:
        if len(linhas) < MIN_LINHAS_DADOS:
            continue
        nota = len(linhas) * _densidade(linhas)
        if ABAS_RUINS.search(_chave(nome)):
            nota *= 0.25          # nao descarta, so despriotiza
        if nota > melhor_nota:
            melhor, melhor_nota = (nome, linhas), nota
    if melhor is None:
        raise ValueError("nenhuma aba com dados suficientes")
    return melhor


# ------------------------------------------------------- linha do cabecalho
def _nota_cabecalho(linha, linhas_abaixo):
    """Cabecalho: muitas celulas de texto curto, distintas, com dados embaixo."""
    celulas = [c for c in linha if c not in (None, "")]
    if len(celulas) < 2:
        return 0.0
    textos = [c for c in celulas if isinstance(c, str)]
    if len(textos) < len(celulas) * 0.6:
        return 0.0
    if len(set(_chave(c) for c in textos)) < len(textos) * 0.8:
        return 0.0               # cabecalho nao repete nome de coluna
    curtos = sum(1 for t in textos if len(t) <= 40)
    nota = len(celulas) + curtos
    # bonus forte se os nomes batem com o que procuramos
    for regra in PADROES.values():
        rx, peso = regra["nome"]
        if any(re.search(rx, _chave(t)) for t in textos):
            nota += peso
    # exige dados abaixo
    if not any(any(c not in (None, "") for c in l) for l in linhas_abaixo[:3]):
        return 0.0
    return nota


def _parece_dados(linha, linhas_abaixo):
    """A linha e um produto, nao um titulo de coluna?

    Uma lista colada de e-mail comeca direto no primeiro item. Se tratarmos
    essa linha como cabecalho, o primeiro produto do cliente desaparece sem
    aviso -- o pior tipo de erro que este programa pode cometer. O sinal e
    o comprimento: nome de coluna e curto, descricao de produto e longa e
    parecida com as descricoes logo abaixo.
    """
    def maior_texto(lin):
        textos = [str(c) for c in lin if isinstance(c, str) and c.strip()]
        return max((len(t) for t in textos), default=0)

    aqui = maior_texto(linha)
    if aqui < 20:
        return False
    abaixo = [maior_texto(l) for l in linhas_abaixo[:5]
              if any(c not in (None, "") for c in l)]
    if not abaixo:
        return False
    media = sum(abaixo) / len(abaixo)
    return abs(aqui - media) <= max(10, 0.4 * media)


def achar_cabecalho(linhas):
    """Devolve (indice, nota). Indice -1 significa "esta planilha nao tem
    cabecalho" -- os dados comecam na primeira linha."""
    melhor_i, melhor_nota = 0, -1.0
    for i in range(min(MAX_LINHAS_CABECALHO, len(linhas) - 1)):
        nota = _nota_cabecalho(linhas[i], linhas[i + 1:])
        if nota > melhor_nota:
            melhor_i, melhor_nota = i, nota

    if melhor_nota < 12 and _parece_dados(linhas[melhor_i], linhas[melhor_i + 1:]):
        return -1, melhor_nota
    return melhor_i, melhor_nota


# ------------------------------------------------------------ mapear colunas
def _perfil_coluna(valores):
    """Estatisticas que ajudam a reconhecer o papel da coluna."""
    vals = [v for v in valores if v not in (None, "")]
    if not vals:
        return {"n": 0, "txt": 0.0, "num": 0.0, "tam": 0.0, "distintos": 0.0}
    txt = [v for v in vals if isinstance(v, str) and not _parece_numero(v)]
    num = [v for v in vals if _parece_numero(v)]
    return {
        "n": len(vals),
        "txt": len(txt) / len(vals),
        "num": len(num) / len(vals),
        "tam": sum(len(str(v)) for v in txt) / len(txt) if txt else 0.0,
        "distintos": len(set(map(str, vals))) / len(vals),
    }


def _parece_numero(v):
    if isinstance(v, (int, float)):
        return True
    return bool(re.fullmatch(r"-?[\d.,]+", str(v).strip()))


def mapear_colunas(cabecalho, corpo):
    """Devolve {campo: indice} + relatorio de como cada escolha foi feita."""
    nomes = [_chave(c) for c in cabecalho]
    perfis = [_perfil_coluna([lin[i] if i < len(lin) else None for lin in corpo])
              for i in range(len(cabecalho))]

    mapa, relatorio, usados = {}, [], set()

    for campo, regra in PADROES.items():
        rx, peso = regra["nome"]
        evitar, forma = regra["evitar"], regra["forma"]
        candidatos = []

        for i, nome in enumerate(nomes):
            if i in usados or not nome or perfis[i]["n"] == 0:
                continue
            if evitar and re.search(evitar, nome):
                continue
            if not re.search(rx, nome):
                continue
            if not forma(perfis[i]):
                continue          # nome combina mas o conteudo desmente
            candidatos.append((peso, i, "coluna '%s' + conteudo compativel" % cabecalho[i]))

        # plano B da descricao: nenhuma coluna se chama nada util, entao
        # procuramos a cara de uma descricao -- texto longo e variado.
        if campo == "descricao" and not candidatos:
            for i, pf in enumerate(perfis):
                if i in usados or pf["n"] == 0:
                    continue
                if pf["txt"] > 0.7 and pf["tam"] >= 12 and pf["distintos"] > 0.2:
                    candidatos.append((pf["tam"] / 10.0, i,
                                       "sem nome reconhecivel; escolhida pelo conteudo "
                                       "(texto de ~%.0f caracteres, variado)" % pf["tam"]))

        if candidatos:
            peso, i, motivo = max(candidatos, key=lambda c: c[0])
            mapa[campo] = i
            usados.add(i)
            relatorio.append((campo, cabecalho[i], motivo, peso))

    return mapa, relatorio


# ------------------------------------------------------------------- fachada
class Leitura:
    def __init__(self, aba, linha_cabecalho, cabecalho, mapa, relatorio, registros, avisos):
        self.aba = aba
        self.linha_cabecalho = linha_cabecalho
        self.cabecalho = cabecalho
        self.mapa = mapa
        self.relatorio = relatorio
        self.registros = registros
        self.avisos = avisos

    def resumo(self):
        linhas = ["aba escolhida     : %s" % self.aba,
                  "linha do cabecalho: %s" % ("sem cabecalho -- os dados comecam "
                                              "na linha 1"
                                              if self.linha_cabecalho < 0
                                              else self.linha_cabecalho + 1),
                  "colunas reconhecidas:"]
        for campo, nome, motivo, _ in self.relatorio:
            linhas.append("   %-12s <- '%s'   (%s)" % (campo, nome, motivo))
        faltando = [c for c in ("descricao",) if c not in self.mapa]
        if faltando:
            linhas.append("   NAO ACHOU: " + ", ".join(faltando))
        for a in self.avisos:
            linhas.append("   aviso: " + a)
        return "\n".join(linhas)


def _num(v):
    if v in (None, ""):
        return 0.0
    if isinstance(v, (int, float)):
        return float(v)
    txt = str(v).strip().replace("R$", "").strip()
    if re.fullmatch(r"-?\d{1,3}(\.\d{3})*(,\d+)?", txt):     # 1.234,56
        txt = txt.replace(".", "").replace(",", ".")
    else:
        txt = txt.replace(",", ".")
    try:
        return float(txt)
    except ValueError:
        return 0.0


def ler_pedido(caminho, forcar=None, aba=None):
    """Le a lista do cliente e devolve uma Leitura.

    forcar: {'descricao': 'E'} ou {'descricao': 4} para corrigir a deteccao.
    """
    abas = _carregar(caminho)
    if aba:
        escolhida = next(((n, l) for n, l in abas if n == aba), None)
        if escolhida is None:
            raise ValueError("aba '%s' nao existe. Abas: %s"
                             % (aba, ", ".join(n for n, _ in abas)))
        nome_aba, linhas = escolhida
    else:
        nome_aba, linhas = escolher_aba(abas)

    i_cab, nota = achar_cabecalho(linhas)
    if i_cab < 0:
        # planilha sem cabecalho: nenhuma linha e sacrificada, e as colunas
        # passam a ser reconhecidas so pelo conteudo
        largura = max((len(l) for l in linhas), default=0)
        cabecalho = [""] * largura
        corpo = [lin for lin in linhas if any(c not in (None, "") for c in lin)]
    else:
        cabecalho = [c if c not in (None, "") else "" for c in linhas[i_cab]]
        corpo = [lin for lin in linhas[i_cab + 1:]
                 if any(c not in (None, "") for c in lin)]

    mapa, relatorio = mapear_colunas(cabecalho, corpo)

    avisos = []
    if nota < 12:
        avisos.append("cabecalho pouco evidente -- confira a linha %d" % (i_cab + 1))

    for campo, ref in (forcar or {}).items():
        idx = _indice_coluna(ref, cabecalho)
        mapa[campo] = idx
        relatorio = [r for r in relatorio if r[0] != campo]
        relatorio.append((campo, cabecalho[idx] if idx < len(cabecalho) else "?",
                          "forcado pelo operador", 99))

    if "descricao" not in mapa:
        raise ValueError("nao identifiquei a coluna de descricao.\n"
                         "Colunas disponiveis: %s\n"
                         "Use --coluna descricao=<letra ou numero>"
                         % ", ".join("%s=%s" % (_letra(i), c)
                                     for i, c in enumerate(cabecalho) if c))

    registros = []
    for lin in corpo:
        def pega(campo, padrao=""):
            i = mapa.get(campo)
            if i is None or i >= len(lin) or lin[i] is None:
                return padrao
            return lin[i]

        descricao = str(pega("descricao")).strip()
        if not descricao or _parece_numero(descricao):
            continue
        registros.append({
            "cod_cliente": str(pega("cod_cliente")).strip(),
            "descricao": descricao,
            "subgrupo": str(pega("subgrupo")).strip(),
            "unidade": str(pega("unidade")).strip(),
            "marca": str(pega("marca")).strip(),
            "qtd": _num(pega("qtd")),
            "preco_unit": _num(pega("preco_unit")),
            "valor_total": _num(pega("valor_total")),
        })

    if not registros:
        avisos.append("nenhuma linha de produto encontrada abaixo do cabecalho")

    return Leitura(nome_aba, i_cab, cabecalho, mapa, relatorio, registros, avisos)


def _letra(i):
    letra = ""
    i += 1
    while i:
        i, r = divmod(i - 1, 26)
        letra = chr(65 + r) + letra
    return letra


def _indice_coluna(ref, cabecalho):
    ref = str(ref).strip()
    if ref.isdigit():
        return int(ref) - 1
    if re.fullmatch(r"[A-Za-z]+", ref):
        idx = 0
        for c in ref.upper():
            idx = idx * 26 + (ord(c) - 64)
        return idx - 1
    alvo = _chave(ref)
    for i, c in enumerate(cabecalho):
        if _chave(c) == alvo:
            return i
    raise ValueError("coluna '%s' nao encontrada" % ref)


# --------------------------------------------------- consolidacao por produto
def consolidar(registros):
    """Junta as repeticoes do mesmo material e soma o consumo do periodo.

    Um historico de ordens de compra repete o mesmo item varias vezes; uma
    lista de cotacao simples nao repete nenhum. Os dois casos passam por aqui
    sem tratamento especial.
    """
    agregado = {}
    for r in registros:
        chave = r["cod_cliente"] or r["descricao"].upper()
        reg = agregado.get(chave)
        if reg is None:
            reg = dict(r)
            reg["qtd_periodo"] = 0.0
            reg["valor_periodo"] = 0.0
            reg["ordens"] = 0
            agregado[chave] = reg
        if len(r["descricao"]) > len(reg["descricao"]):
            reg["descricao"] = r["descricao"]
        reg["qtd_periodo"] += r["qtd"]
        reg["valor_periodo"] += r["valor_total"] or (r["qtd"] * r["preco_unit"])
        reg["ordens"] += 1
        if r["preco_unit"]:
            reg["preco_unit"] = r["preco_unit"]

    pedidos = list(agregado.values())
    pedidos.sort(key=lambda p: -p["valor_periodo"])
    return pedidos
