"""
Gera TODOS os arquivos da marca PALMA a partir de uma definição só.

É a fonte única da marca (Regra de ouro nº 8): a geometria da mão, as cores e a
composição "PALMA / por Autopel" moram AQUI. Os arquivos em `public/images/palma/`,
`public/images/pwa/` e o `public/favicon.ico` são saída deste script — não editar à
mão; mudou a marca, muda aqui e roda de novo.

A mão foi vetorizada da arte original (`Arte/LOGOS PALMA/LOGO PALMA.png`, 2026-10-02):
13 polígonos, com as cores travadas na paleta oficial (navy #0F3A69, teal #005A6F,
cyan #00A9CE, âmbar #FF8F00) em vez dos tons aproximados do PNG.

O texto do SVG sai em CURVAS (contornos da Inter, a mesma fonte dos PDFs, em
public/fonts/inter): logo não pode depender de a fonte estar instalada em quem abre.
O PNG desenha a mesma Inter com os MESMOS avanços de letra, então SVG e PNG batem.

Uso (no host — o container não tem Python):
    pip install pillow fonttools
    python scripts/gerar-marca-palma.py
"""

import json
import os

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image, ImageDraw, ImageFont

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTES = os.path.join(RAIZ, 'public', 'fonts', 'inter')
SAIDA = os.path.join(RAIZ, 'public', 'images', 'palma')
PWA = os.path.join(RAIZ, 'public', 'images', 'pwa')

# Cores: fonte única em resources/js/constants/marca-palma.json (o mosaico do login lê o mesmo).
with open(os.path.join(RAIZ, 'resources', 'js', 'constants', 'marca-palma.json'), encoding='utf-8') as _f:
    _P = json.load(_f)
NAVY, TEAL, TEAL_CLARO, TEAL_MEDIO, CYAN, AMBAR = (_P[k] for k in ('navy', 'teal', 'tealClaro', 'tealMedio', 'cyan', 'ambar'))
BRANCO, PRETO = '#FFFFFF', '#1A1A1A'

# Mão: viewBox 0 0 580 682. Os polígonos não se sobrepõem; o vão entre eles é o
# "filete branco" da arte, então ele some em fundo transparente — é o desenho.
SIMBOLO_L, SIMBOLO_A = 580, 682
MAO = [
    ([(359, 10), (296, 42), (239, 199), (311, 254)], TEAL_CLARO),   # dedo médio
    ([(168, 65), (130, 105), (162, 332), (228, 207)], NAVY),         # indicador
    ([(508, 76), (444, 89), (322, 258), (398, 333)], CYAN),          # anelar
    ([(59, 178), (10, 217), (86, 383), (155, 341)], NAVY),           # polegar
    ([(305, 260), (234, 212), (172, 334)], TEAL_CLARO),
    ([(308, 271), (93, 390), (259, 482)], TEAL),
    ([(319, 271), (268, 479), (425, 373)], TEAL_MEDIO),
    ([(570, 284), (441, 371), (533, 452)], TEAL_MEDIO),              # mínimo
    ([(427, 382), (272, 490), (390, 540)], AMBAR),
    ([(438, 382), (377, 635), (530, 465)], CYAN),
    ([(91, 399), (189, 665), (255, 491)], NAVY),
    ([(264, 501), (196, 672), (361, 645)], TEAL_CLARO),
    ([(274, 500), (368, 637), (387, 549)], CYAN),
]

# Composição horizontal, em unidades do próprio logo (altura total 120).
LOGO_A = 120
ESC_MAO = LOGO_A / SIMBOLO_A
TXT_X = SIMBOLO_L * ESC_MAO + 30      # respiro entre a mão e o texto (16 ficava colado na navbar)
NOME, NOME_CAP, NOME_BASE, NOME_TRACK = 'PALMA', 48, 72, 0.02
SUB, SUB_CAP, SUB_BASE = 'por Autopel', 14, 100

# Variantes: (mão, PALMA, por Autopel, filete). Mão None = colorida.
# Filete = o contorno branco entre os triângulos, como na arte original. Só a variante
# `-escuro` precisa dele: em fundo claro o vão transparente já É o filete, mas em fundo
# navy os dedos navy sumiriam sem ele.
VARIANTES = {
    '': (None, NAVY, '#6B7280', None),                         # fundo claro
    '-escuro': (None, BRANCO, (255, 255, 255, 191), BRANCO),   # colorido em fundo escuro
    '-branco': (BRANCO, BRANCO, (255, 255, 255, 191), None),   # monocromático em fundo escuro
    '-preto': (PRETO, PRETO, '#4B5563', None),                 # impressão monocromática
}
FILETE = 10  # largura do filete, em unidades da mão (o vão da arte tem ~8)


class Fonte:
    def __init__(self, arquivo):
        self.caminho = os.path.join(FONTES, arquivo)
        self.tt = TTFont(self.caminho)
        self.upm = self.tt['head'].unitsPerEm
        self.cap = self.tt['OS/2'].sCapHeight
        self.cmap = self.tt.getBestCmap()
        self.gs = self.tt.getGlyphSet()

    def escala(self, cap_px):
        return cap_px / self.cap

    def avancos(self, texto, cap_px, track=0.0):
        """Posição x de cada letra — a MESMA lista serve ao SVG e ao PNG."""
        k = self.escala(cap_px)
        tam = self.upm * k
        x, pos = 0.0, []
        for ch in texto:
            pos.append(x)
            x += self.tt['hmtx'][self.cmap[ord(ch)]][0] * k + track * tam
        return pos, x - track * tam

    def caminho_svg(self, texto, cap_px, x0, base, track=0.0):
        k = self.escala(cap_px)
        pos, _ = self.avancos(texto, cap_px, track)
        partes = []
        for ch, dx in zip(texto, pos):
            pen = SVGPathPen(self.gs, ntos=lambda v: f'{v:.2f}'.rstrip('0').rstrip('.'))
            self.gs[self.cmap[ord(ch)]].draw(TransformPen(pen, (k, 0, 0, -k, x0 + dx, base)))
            partes.append(pen.getCommands())
        return ' '.join(partes)


BOLD = Fonte('Inter-Bold.ttf')
REG = Fonte('Inter-Regular.ttf')
_, NOME_L = BOLD.avancos(NOME, NOME_CAP, NOME_TRACK)
_, SUB_L = REG.avancos(SUB, SUB_CAP)
LOGO_L = TXT_X + NOME_L
SUB_X = LOGO_L - SUB_L                 # "por Autopel" alinhado à direita do PALMA


def css(cor):
    if isinstance(cor, tuple):
        return f'rgba({cor[0]},{cor[1]},{cor[2]},{cor[3] / 255:.2f})'
    return cor


def rgba(cor):
    if isinstance(cor, tuple):
        return cor
    return tuple(int(cor[i:i + 2], 16) for i in (1, 3, 5)) + (255,)


def poligonos_svg(cor, k=1.0, filete=None):
    out = []
    if filete:
        # Primeiro o filete (contorno grosso), depois as cores por cima: sobra a borda
        # externa e o vão entre as peças, exatamente o desenho da arte.
        pts = ' '.join
        out.append(f'  <g fill="{filete}" stroke="{filete}" stroke-width="{FILETE * k:.2f}" stroke-linejoin="round">')
        for p_, _ in MAO:
            out.append('    <polygon points="' + pts(f'{x * k:.2f},{y * k:.2f}' for x, y in p_) + '"/>')
        out.append('  </g>')
    for pts, c in MAO:
        p = ' '.join(f'{x * k:.2f},{y * k:.2f}' for x, y in pts)
        out.append(f'  <polygon points="{p}" fill="{cor or c}"/>')
    return out


def escrever(nome, linhas):
    with open(os.path.join(SAIDA, nome), 'w', encoding='utf-8', newline='\n') as f:
        f.write('\n'.join(linhas) + '\n')


def svgs():
    for suf, (mao, nome, sub, filete) in VARIANTES.items():
        escrever(f'palma-simbolo{suf}.svg', [
            f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {SIMBOLO_L} {SIMBOLO_A}" role="img" aria-label="PALMA">',
            *poligonos_svg(mao, filete=filete),
            '</svg>',
        ])
        escrever(f'palma-logo{suf}.svg', [
            f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {LOGO_L:.2f} {LOGO_A}" role="img" aria-label="PALMA por Autopel">',
            *poligonos_svg(mao, ESC_MAO, filete),
            f'  <path fill="{css(nome)}" d="{BOLD.caminho_svg(NOME, NOME_CAP, TXT_X, NOME_BASE, NOME_TRACK)}"/>',
            f'  <path fill="{css(sub)}" d="{REG.caminho_svg(SUB, SUB_CAP, SUB_X, SUB_BASE)}"/>',
            '</svg>',
        ])
        # Compacto: sem "por Autopel" e com o PALMA centrado na mão. É o da navbar —
        # a 40px de altura o subtítulo teria 5px e viraria um risco cinza.
        escrever(f'palma-logo-compacto{suf}.svg', [
            f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {LOGO_L:.2f} {LOGO_A}" role="img" aria-label="PALMA">',
            *poligonos_svg(mao, ESC_MAO, filete),
            f'  <path fill="{css(nome)}" d="{BOLD.caminho_svg(NOME, NOME_CAP, TXT_X, (LOGO_A + NOME_CAP) / 2, NOME_TRACK)}"/>',
            '</svg>',
        ])


SS = 4  # superamostragem: desenha 4x maior e reduz, para borda suave


def desenhar_mao(d, cor, k, dx, dy, filete=None):
    if filete:
        for pts, _ in MAO:
            xy = [(dx + x * k, dy + y * k) for x, y in pts]
            d.polygon(xy, fill=rgba(filete))
            # Aresta a aresta: o `width` do d.polygon não engrossa o contorno quando há fill.
            for (ax, ay), (bx, by) in zip(xy, xy[1:] + xy[:1]):
                d.line((ax, ay, bx, by), fill=rgba(filete), width=max(1, round(FILETE * k)))
            for px, py in xy:  # cantos arredondados, como o stroke-linejoin do SVG
                r = FILETE * k / 2
                d.ellipse((px - r, py - r, px + r, py + r), fill=rgba(filete))
    for pts, c in MAO:
        d.polygon([(dx + x * k, dy + y * k) for x, y in pts], fill=rgba(cor or c))


def desenhar_texto(img, fonte, texto, cap, x0, base, track, cor, k):
    # Camada própria: `d.text` com alfa sobre fundo transparente não compõe, sobrescreve.
    camada = Image.new('RGBA', img.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(camada)
    pos, _ = fonte.avancos(texto, cap, track)
    ft = ImageFont.truetype(fonte.caminho, size=round(fonte.upm * fonte.escala(cap) * k))
    for ch, dx in zip(texto, pos):
        d.text(((x0 + dx) * k, base * k), ch, font=ft, fill=rgba(cor), anchor='ls')
    img.alpha_composite(camada)


def png_logo(arquivo, suf, largura, compacto=False):
    mao, nome, sub, filete = VARIANTES[suf]
    k = largura * SS / LOGO_L
    img = Image.new('RGBA', (round(LOGO_L * k), round(LOGO_A * k)), (0, 0, 0, 0))
    desenhar_mao(ImageDraw.Draw(img), mao, ESC_MAO * k, 0, 0, filete)
    if compacto:
        desenhar_texto(img, BOLD, NOME, NOME_CAP, TXT_X, (LOGO_A + NOME_CAP) / 2, NOME_TRACK, nome, k)
    else:
        desenhar_texto(img, BOLD, NOME, NOME_CAP, TXT_X, NOME_BASE, NOME_TRACK, nome, k)
        desenhar_texto(img, REG, SUB, SUB_CAP, SUB_X, SUB_BASE, 0, sub, k)
    img.resize((largura, round(LOGO_A * largura / LOGO_L)), Image.LANCZOS).save(os.path.join(SAIDA, arquivo), optimize=True)


def icone(lado, ocupacao, fundo, raio=0.0, cor=None, filete=None):
    """Mão centrada num quadrado. `ocupacao` = fração do lado que a mão ocupa."""
    S = lado * SS
    img = Image.new('RGBA', (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    if fundo:
        d.rounded_rectangle((0, 0, S - 1, S - 1), radius=round(S * raio), fill=rgba(fundo))
    k = S * ocupacao / max(SIMBOLO_L, SIMBOLO_A)
    desenhar_mao(d, cor, k, (S - SIMBOLO_L * k) / 2, (S - SIMBOLO_A * k) / 2, filete)
    return img.resize((lado, lado), Image.LANCZOS)


def pngs():
    # E-mail (cabeçalho navy) e uso geral: e-mail não exibe SVG. 2x da largura exibida.
    for suf in VARIANTES:
        png_logo(f'palma-logo{suf}.png', suf, 600)
        png_logo(f'palma-logo-compacto{suf}.png', suf, 480, compacto=True)
    icone(512, 0.92, None).save(os.path.join(SAIDA, 'palma-simbolo.png'), optimize=True)

    # Ícones: a mão SOZINHA, fundo transparente (pedido do Tony, "tipo os da Google"), com
    # o filete branco da arte — é ele que mantém os dedos navy visíveis numa aba escura
    # ou num papel de parede escuro.
    for lado, nome in ((512, 'icon-512.png'), (192, 'icon-192.png')):
        icone(lado, 0.92, None, filete=BRANCO).save(os.path.join(PWA, nome), optimize=True)
    icone(32, 0.98, None, filete=BRANCO).save(os.path.join(PWA, 'favicon-32.png'), optimize=True)
    icone(256, 0.98, None, filete=BRANCO).save(
        os.path.join(RAIZ, 'public', 'favicon.ico'), sizes=[(16, 16), (32, 32), (48, 48)])

    # ⚠️ Os dois abaixo NÃO podem ser transparentes, por regra das plataformas: o Android
    # recorta o `maskable` em círculo e pinta de PRETO o que for transparente; o iOS faz o
    # mesmo no apple-touch-icon. Fundo branco = o mesmo visual dos apps da Google.
    icone(512, 0.58, BRANCO).save(os.path.join(PWA, 'icon-512-maskable.png'), optimize=True)
    icone(180, 0.74, BRANCO).save(os.path.join(PWA, 'apple-touch-icon.png'), optimize=True)

if __name__ == '__main__':
    os.makedirs(SAIDA, exist_ok=True)
    svgs()
    pngs()
    print('Marca PALMA gerada em public/images/palma, public/images/pwa e public/favicon.ico.')
