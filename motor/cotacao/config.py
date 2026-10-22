# -*- coding: utf-8 -*-
"""Unico caminho que o motor precisa saber: o do dicionario de sinonimos.

No .exe este arquivo resolvia pasta compartilhada, OneDrive e pasta temporaria.
Dentro do CRM nada disso existe: quem guarda decisoes, catalogo e arquivos e o
Laravel, e o motor so recebe e devolve JSON (ver cli.py).
"""
from pathlib import Path

BASE = Path(__file__).parent

# ⚠️ Mudar este dicionario muda o texto normalizado, que e a CHAVE da memoria.
# Depois de qualquer edicao: php artisan cotacao:renormalizar-memoria
ARQ_SINONIMOS = BASE / "dicionarios" / "sinonimos.json"
