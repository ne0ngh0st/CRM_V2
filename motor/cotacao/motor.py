# -*- coding: utf-8 -*-
"""
Motor de matching: lista do cliente (texto livre) x nosso catalogo (RV).

Camadas, em ordem:
  1. MEMORIA      - par ja validado por um humano antes -> resposta exata
  2. RECALL       - duas portas (indice IDF + fuzzy) juntam os candidatos
  3. PRECISAO     - reordena com score composto + atributos duros (veto)
  4. CLASSIFICA   - AUTOMATICO / REVISAR / TALVEZ / SEM_MATCH, com o motivo
                    escrito por extenso

O passo 4 nunca esconde a duvida: tudo que nao e AUTOMATICO sai com os
melhores candidatos ao lado, inclusive quando a conclusao foi "nao temos".
Quem revisa precisa poder discordar olhando a mesma evidencia.
"""
from pathlib import Path

from rapidfuzz import fuzz, process

from indice import IndiceIDF
from normalizar import (combinam_dim, extrair_atributos, normalizar,
                        token_cabeca)

BASE = Path(__file__).parent

# A memoria (CSV local / API) e assunto do memoria.py. Este arquivo so casa
# texto; nao decide onde as decisoes moram.

# --------------------------------------------------------------- limiares
# Calibrados a mao na primeira rodada. Depois que a memoria tiver algumas
# centenas de decisoes humanas, rode `calibrar.py` para conferir se estes
# numeros ainda sao os que maximizam acerto no automatico.
# Quatro faixas em vez de duas. A amostragem manual da primeira rodada
# mostrou par CORRETO ainda na faixa dos 50 ("LAPIS PRETO NUMERO 2" x "LAPIS
# GRAFITE N.2"), entao cortar em 62 jogava fora item de verdade. Em vez de
# alargar o amarelo e afogar quem revisa, a duvida fraca ganhou faixa propria
# -- da para atacar por valor e parar quando nao compensar mais.
LIM_AUTOMATICO = 88     # score minimo para dispensar revisao humana
LIM_REVISAR = 62        # abaixo disso ainda ha candidato, mas fraco
LIM_TALVEZ = 45         # abaixo disso nem vale a pena olhar
MARGEM_MINIMA = 6       # distancia minima do 1o para o 2o colocado
TOP_K_IDF = 40          # candidatos vindos do indice por relevancia (IDF)
TOP_K_FUZZY = 15        # candidatos vindos da comparacao de string pura
N_SUGESTOES = 3         # candidatos mostrados na planilha de revisao


class Catalogo:
    """Nosso estoque, normalizado e indexado."""

    def __init__(self, itens):
        # itens: lista de dict com codigo, descricao, marca, disponivel, ...
        self.itens = itens
        for it in self.itens:
            texto = (str(it["descricao"]) + " " + str(it.get("marca") or "")).strip()
            it["norm"] = normalizar(texto)
            it["attr"] = extrair_atributos(str(it["descricao"]))
            it["cabeca"] = token_cabeca(it["norm"])
        self.normalizados = [it["norm"] for it in self.itens]
        self.indice = IndiceIDF(self.normalizados)

    def __len__(self):
        return len(self.itens)


def carregar_memoria():
    """No CRM a memoria mora no banco e chega sempre por parametro (cli.py).

    Falhar alto aqui e de proposito: casar sem memoria devolveria tudo como se
    nenhum humano tivesse conferido nada, sem erro nenhum.
    """
    raise RuntimeError("memoria nao informada: passe memoria= para casar()")


# ---------------------------------------------------------- score composto
def _score_texto(a, b, cobertura=None):
    """Quatro olhares sobre o mesmo par, cada um cobrindo uma falha do outro.

    Os tres primeiros comparam letras e palavras, e tratam toda palavra como
    igualmente importante. O quarto (cobertura IDF) e o unico que sabe que
    'INTERFOLHADO' decide o produto e 'UNIVERSAL' nao -- e por isso pesa
    tanto quanto os outros tres somados menos um.
    """
    letras = (0.45 * fuzz.token_set_ratio(a, b)     # ignora ordem e palavra extra
              + 0.35 * fuzz.token_sort_ratio(a, b)  # pune palavra sobrando
              + 0.20 * fuzz.ratio(a, b))            # pega erro de digitacao
    if cobertura is None:
        return letras
    return 0.65 * letras + 0.35 * (cobertura * 100.0)


def _comparar_atributo(va, vb):
    """None se algum lado nao declarou; True/False se da para comparar."""
    if va is None or vb is None:
        return None
    return va == vb


# atributo -> (rotulo, bonus quando confere, penalidade quando conflita)
PESOS_ATRIBUTO = (
    ("volume_ml", "volume", 9, 40),
    ("massa_g", "peso", 9, 40),
    # dimensao pune pouco de proposito: neste ramo ela e o atributo mais
    # sujo -- 20X26MM, 16X260 folhas e 0,50X50M convivem na mesma base e
    # nem sempre medem a mesma coisa. Volume e peso sao confiaveis; medida
    # linear so serve como indicio.
    ("dim", "dimensao", 7, 12),
    ("pack", "embalagem", 6, 16),
    ("compr_cm", "comprimento", 5, 20),
)


def pontuar(pedido, item, cobertura=None):
    """Score final 0-100 + lista de motivos legiveis."""
    score = _score_texto(pedido["norm"], item["norm"], cobertura)
    motivos = []
    ap, ai = pedido["attr"], item["attr"]

    for chave, rotulo, bonus, penal in PESOS_ATRIBUTO:
        if chave == "dim":
            r = combinam_dim(ap[chave], ai[chave])
        else:
            r = _comparar_atributo(ap[chave], ai[chave])
        if r is True:
            score += bonus
            motivos.append(rotulo + " confere")
        elif r is False:
            score -= penal
            motivos.append("CONFLITO de " + rotulo +
                           " (" + str(ap[chave]) + " x " + str(ai[chave]) + ")")
        elif ap[chave] is not None and ai[chave] is None:
            motivos.append(rotulo + " nao declarado na nossa descricao")

    # ancora: o substantivo principal do pedido precisa existir na nossa descricao
    cabeca = pedido["cabeca"]
    if cabeca:
        melhor = max((fuzz.ratio(cabeca, tk) for tk in item["norm"].split()), default=0)
        if melhor >= 88:
            score += 4
        elif melhor < 70:
            score -= 22
            motivos.append("produto-base '" + cabeca + "' nao aparece na nossa descricao")

    # marca pedida explicitamente pelo cliente
    marca = str(pedido.get("marca") or "").strip().upper()
    if len(marca) >= 3 and marca in item["norm"]:
        score += 5
        motivos.append("marca confere")

    return max(0.0, min(100.0, score)), motivos


def _tem_conflito(motivos):
    return any(m.startswith("CONFLITO") for m in motivos)


def _preferir_com_estoque(pontuados):
    """Entre candidatos de descricao identica, mantem so o de maior estoque.

    Devolve a lista sem os irmaos redundantes, preservando a ordem. Isso faz
    duas coisas ao mesmo tempo: escolhe o SKU que a empresa quer girar, e
    desfaz o empate artificial que impedia o item de virar AUTOMATICO --
    porque o "segundo colocado" era ele mesmo com outro codigo.
    """
    melhor_por_texto = {}
    for i, (score, _, item) in enumerate(pontuados):
        chave = item["descricao"].strip().upper()
        atual = melhor_por_texto.get(chave)
        if atual is None or item["disponivel"] > pontuados[atual][2]["disponivel"]:
            melhor_por_texto[chave] = i
    guardar = set(melhor_por_texto.values())
    return [p for i, p in enumerate(pontuados) if i in guardar]


def _resultado(pedido, item, score, status, motivos, alternativas):
    return {
        "pedido": pedido,
        "item": item,
        "score": round(score, 1),
        "status": status,
        "motivos": motivos,
        "alternativas": alternativas,
    }


# ------------------------------------------------------------------ match
def casar(pedidos, catalogo, memoria=None, progresso=None):
    """pedidos: lista de dict com pelo menos 'descricao'. Devolve resultados."""
    memoria = memoria if memoria is not None else carregar_memoria()

    for p in pedidos:
        p["norm"] = normalizar(p["descricao"])
        p["attr"] = extrair_atributos(str(p["descricao"]))
        p["cabeca"] = token_cabeca(p["norm"])

    # recall em bloco (rapido, multithread)
    consultas = [p["norm"] for p in pedidos]
    bruto = process.cdist(consultas, catalogo.normalizados,
                          scorer=fuzz.token_set_ratio, workers=-1, dtype="uint8")

    por_codigo = {it["codigo"]: it for it in catalogo.itens}
    resultados = []

    for i, p in enumerate(pedidos):
        if progresso and i % 100 == 0:
            progresso(i, len(pedidos))

        # ---- camada 1: memoria humana
        lembrado = memoria.get(p["norm"])
        if lembrado:
            cod = str(lembrado.get("codigo") or "").strip()
            if lembrado.get("decisao") == "NAO_TEMOS":
                resultados.append(_resultado(
                    p, None, 100.0, "SEM_MATCH_CONFIRMADO",
                    ["decisao humana anterior: nao trabalhamos com este item"], []))
                continue
            if cod in por_codigo:
                resultados.append(_resultado(
                    p, por_codigo[cod], 100.0, "APRENDIDO",
                    ["par validado por " + str(lembrado.get("usuario") or "humano") +
                     " em " + str(lembrado.get("data") or "?")], []))
                continue

        # ---- camada 2 e 3: recall e reordenacao
        # recall por duas portas, porque cada uma erra de um jeito:
        #  - IDF acha o item certo quando o termo decisivo e raro (SULFITE)
        #  - fuzzy acha quando o cliente escreveu errado ou abreviou tudo
        # ordena por score decrescente. `linha` e uint8: negar estoura o tipo.
        linha = bruto[i]
        idx = {j for j, _ in catalogo.indice.buscar(p["norm"], limite=TOP_K_IDF)}
        idx.update(sorted(range(len(linha)), key=linha.__getitem__,
                          reverse=True)[:TOP_K_FUZZY])

        pontuados = []
        for j in idx:
            cob = catalogo.indice.cobertura(p["norm"], j)
            s, mot = pontuar(p, catalogo.itens[j], cobertura=cob)
            pontuados.append((s, mot, catalogo.itens[j]))
        pontuados.sort(key=lambda x: -x[0])

        # Desempate entre codigos que tem a MESMA descricao. 4% do catalogo
        # sao SKUs assim (mesmo produto cadastrado varias vezes, fornecedores
        # diferentes). Nenhum criterio de texto os separa -- e nao ha o que
        # separar: para o cliente sao o mesmo item. Mandar isso para revisao
        # humana e desperdicar o tempo dela numa escolha que nao e dela;
        # a regra comercial (vender o que tem mais em casa) resolve.
        pontuados = _preferir_com_estoque(pontuados)

        melhor_s, melhor_mot, melhor_it = pontuados[0]
        segundo_s = pontuados[1][0] if len(pontuados) > 1 else 0.0
        margem = melhor_s - segundo_s

        # ---- camada 4: decisao
        if melhor_s < LIM_TALVEZ:
            status, item = "SEM_MATCH", None
            melhor_mot = melhor_mot + [
                "nada parecido no catalogo (melhor candidato: %.0f pontos)" % melhor_s]
        elif melhor_s < LIM_REVISAR:
            status, item = "TALVEZ", None
            melhor_mot = melhor_mot + [
                "semelhanca fraca (%.0f pontos): pode ser equivalente com outro "
                "nome, ou pode ser produto diferente" % melhor_s]
        elif melhor_s >= LIM_AUTOMATICO and not _tem_conflito(melhor_mot) \
                and margem >= MARGEM_MINIMA:
            status, item = "AUTOMATICO", melhor_it
        else:
            # toda linha amarela precisa dizer POR QUE e amarela, senao quem
            # revisa nao sabe do que desconfiar
            status, item = "REVISAR", melhor_it
            if _tem_conflito(melhor_mot):
                melhor_mot = melhor_mot + ["ambiguo: atributo conflitante"]
            elif margem < MARGEM_MINIMA:
                melhor_mot = melhor_mot + [
                    "ambiguo: 2o colocado quase empatado (%.0f x %.0f)" % (segundo_s, melhor_s)]
            else:
                melhor_mot = melhor_mot + [
                    "parecido mas nao o bastante para decidir sozinho "
                    "(%.0f pontos, precisaria de %d)" % (melhor_s, LIM_AUTOMATICO)]

        # Em SEM_MATCH o melhor candidato nao vira resposta -- mas ele nao pode
        # sumir da planilha. Quem revisa precisa ver o que foi descartado e com
        # quantos pontos, senao "nao temos" e uma afirmacao sem prova. Por isso
        # a lista de sugestoes comeca no 1o colocado, e nao no 2o.
        inicio = 0 if item is None else 1
        alternativas = [(s, it) for s, _, it in
                        pontuados[inicio:inicio + N_SUGESTOES]]
        resultados.append(_resultado(p, item, melhor_s, status, melhor_mot, alternativas))

    if progresso:
        progresso(len(pedidos), len(pedidos))
    return resultados
