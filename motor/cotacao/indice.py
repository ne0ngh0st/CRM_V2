# -*- coding: utf-8 -*-
"""
Indice invertido com IDF -- a camada de recall.

Por que nao usar so fuzzy puro: numa base de 4 mil SKUs, 'A4' aparece em 127
descricoes e 'SULFITE' em 14. Um comparador de string trata os dois tokens
igual, entao 'PAPEL SULFITE A4 75G' devolve PASTA A4, ENVELOPE A4, DIVISORIA
A4... e o sulfite verdadeiro nem entra na lista de candidatos.

IDF resolve: token raro pesa muito, token banal pesa quase nada. O score de
recall e "quanto do peso da consulta o documento cobre".

Erro de digitacao entra por outra porta: token da consulta que nao existe no
vocabulario e reaproximado dos tokens conhecidos por similaridade, com um
desconto proporcional a distancia.
"""
import math
from collections import defaultdict

from rapidfuzz import fuzz, process

SIM_MINIMA_TOKEN = 82    # abaixo disso nao consideramos "a mesma palavra"
MAX_VIZINHOS = 3         # quantos tokens parecidos aceitamos por token orfao

# Tokens de medida criados pelo normalizador (DIM20X26, VOL1000, PK100...).
# Ficam de fora da cobertura porque sao quase unicos na base -- teriam o IDF
# mais alto de todos e decidiriam sozinhos a nota. Medida ja e julgada pelo
# sistema de atributos do motor, com bonus e veto proprios; conta-la aqui de
# novo seria pesar a mesma evidencia duas vezes, e com o peso errado.
CANONICOS = ("DIM", "VOL", "MAS", "PK")


class IndiceIDF:
    def __init__(self, documentos):
        """documentos: lista de strings ja normalizadas."""
        self.documentos = documentos
        self.postings = defaultdict(set)      # token -> indices dos documentos
        self.peso_doc = [0.0] * len(documentos)

        for i, doc in enumerate(documentos):
            for tk in set(doc.split()):
                self.postings[tk].add(i)

        n = max(len(documentos), 1)
        self.idf = {tk: math.log(1 + n / len(docs))
                    for tk, docs in self.postings.items()}

        for i, doc in enumerate(documentos):
            self.peso_doc[i] = sum(self.idf.get(tk, 0.0) for tk in set(doc.split()))

        self.vocabulario = list(self.postings)
        self.idf_medio = (sum(self.idf.values()) / len(self.idf)) if self.idf else 0.0

    # ------------------------------------------------------------------
    def _expandir(self, tokens):
        """token -> [(token_no_indice, fator)]. Trata palavra desconhecida."""
        expandido = []
        for tk in tokens:
            if tk in self.postings:
                expandido.append((tk, 1.0))
                continue
            # palavra que nao existe na nossa base: pode ser erro de digitacao
            # ou termo que so o cliente usa. Procura os vizinhos mais proximos.
            vizinhos = process.extract(tk, self.vocabulario, scorer=fuzz.ratio,
                                       score_cutoff=SIM_MINIMA_TOKEN,
                                       limit=MAX_VIZINHOS)
            for viz, sim, _ in vizinhos:
                expandido.append((viz, (sim / 100.0) ** 2))
        return expandido

    def cobertura(self, consulta, i):
        """Quanto do PESO da consulta o documento i cobre (0 a 1).

        Diferente de comparar strings: 'PAPEL TOALHA INTERFOLHADO UNIVERSAL
        3D' contra 'PAPEL TOALHA INTERF. 3D BRANCA C/2000 FLS' tem metade das
        palavras diferentes, e um comparador de texto puro da nota baixa. Mas
        as palavras que decidem -- TOALHA, INTERFOLHADO -- sao justamente as
        raras, e sao as que batem. Aqui elas dominam a conta.

        Palavra que so o cliente usa entra no denominador com peso medio: nao
        e ignorada (senao qualquer palavra a mais sairia de graca) nem tratada
        como decisiva (senao um adjetivo qualquer derrubaria o par certo).
        """
        tokens = {t for t in consulta.split() if not t.startswith(CANONICOS)}
        if not tokens:
            return 0.0
        doc = {t for t in self.documentos[i].split() if not t.startswith(CANONICOS)}
        peso_consulta = sum(self.idf.get(tk, self.idf_medio) for tk in tokens)
        if peso_consulta <= 0:
            return 0.0
        comum = sum(self.idf.get(tk, 0.0) for tk in tokens & doc)
        return comum / peso_consulta

    def buscar(self, consulta, limite=40):
        """Devolve [(indice_documento, cobertura_0_a_1)] ordenado."""
        tokens = set(consulta.split())
        if not tokens:
            return []

        expandido = self._expandir(tokens)
        peso_consulta = sum(self.idf.get(tk, 0.0) * f for tk, f in expandido)
        if peso_consulta <= 0:
            return []

        acumulado = defaultdict(float)
        for tk, fator in expandido:
            peso = self.idf.get(tk, 0.0) * fator
            if peso <= 0:
                continue
            for i in self.postings[tk]:
                acumulado[i] += peso

        # cobertura da consulta, com leve desconto para documento muito longo
        # (senao a descricao mais comprida da base ganha sempre)
        pontuados = []
        for i, soma in acumulado.items():
            cobertura = soma / peso_consulta
            excesso = self.peso_doc[i] / (self.peso_doc[i] + peso_consulta)
            pontuados.append((i, cobertura * (1.0 - 0.25 * excesso)))

        pontuados.sort(key=lambda x: -x[1])
        return pontuados[:limite]
