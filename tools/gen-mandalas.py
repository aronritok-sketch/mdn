#!/usr/bin/env python3
"""
Mandala-grafikák generálása a bolt színeiben (SVG, kis méret, éles minden felbontáson).

Kimenet: assets/mandala/
  medal-*.svg   színes medálok (szándék kártyák, választássegítő, üres állapotok)
  lines-*.svg   vonalas mandalák currentColor-ral (hős háttér, vízjelek, CSS maszk)
  glyph.svg     apró mandala jel (felirat előtti dísz, elválasztó)

Futtatás: python3 tools/gen-mandalas.py   (utána tools/build-theme.py átmásolja a témába)
A rajz determinisztikus: ugyanaz a bemenet ugyanazt a fájlt adja (nincs véletlen), így a git diff tiszta.
"""
import math
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / 'assets/mandala'

# A téma palettája (assets/css/vars.css)
PAPER, SAND, LINE, INK = '#F7F4EE', '#EEE7DB', '#DDD3C3', '#1C1916'
ACCENT, DEEP, MAROON, NIGHT = '#A9581A', '#8A4512', '#6B2A2A', '#16120F'
GOLD, SAFFRON, SAGE, SKY = '#E2B77A', '#D9A05B', '#7E8F6A', '#6F8A9A'


def f(x):
    return f'{x:.2f}'.rstrip('0').rstrip('.')


def pol(r, a):
    a = math.radians(a - 90)
    return r * math.cos(a), r * math.sin(a)


def ring(n, shape_id, offset=0.0):
    """n példány egy <defs> alakzatból, körbeforgatva (<use> – kis fájlméret)."""
    return ''.join(f'<use href="#{shape_id}" transform="rotate({f(offset + i * 360 / n)})"/>' for i in range(n))


def petal(r0, r1, w, tip=0.0):
    """Hegyes lótuszszirom r0-tól r1-ig (felfelé), w szélességgel; tip>0 visszahajló csúcs."""
    x0, y0 = 0, -r0
    y1 = -r1
    c = -(r0 + (r1 - r0) * 0.45)
    if tip:
        return (f'M{f(x0)} {f(y0)}C{f(w)} {f(c)} {f(w * .55)} {f(y1 + tip)} 0 {f(y1)}'
                f'C{f(-w * .55)} {f(y1 + tip)} {f(-w)} {f(c)} {f(x0)} {f(y0)}Z')
    return f'M0 {f(y0)}Q{f(w)} {f(c)} 0 {f(y1)}Q{f(-w)} {f(c)} 0 {f(y0)}Z'


def teardrop(r0, r1, w):
    """Csepp: kerek talp kívül, hegy befelé."""
    m = (r0 + r1) / 2
    return (f'M0 {f(-r0)}C{f(w)} {f(-m)} {f(w)} {f(-r1)} 0 {f(-r1)}'
            f'C{f(-w)} {f(-r1)} {f(-w)} {f(-m)} 0 {f(-r0)}Z')


def scallop(r, n, depth):
    """Hullámos kör (ívsor) n ívvel."""
    pts = []
    for i in range(n):
        a0, a1 = i * 360 / n, (i + 1) * 360 / n
        x0, y0 = pol(r, a0)
        x1, y1 = pol(r, a1)
        cx, cy = pol(r + depth, (a0 + a1) / 2)
        pts.append((x0, y0, cx, cy, x1, y1))
    d = f'M{f(pts[0][0])} {f(pts[0][1])}' + ''.join(f'Q{f(cx)} {f(cy)} {f(x1)} {f(y1)}' for _, _, cx, cy, x1, y1 in pts) + 'Z'
    return d


def dots(r, n, size, offset=0.0):
    return ''.join(f'<circle cx="{f(pol(r, offset + i * 360 / n)[0])}" cy="{f(pol(r, offset + i * 360 / n)[1])}" r="{f(size)}"/>' for i in range(n))


def mandala(sym, style, pal, line_only=False):
    """
    sym: alapszimmetria (8, 12, 16…); style: rétegváltozat (0–3); pal: (vonal, kitöltés, kiemelés, háttér).
    line_only: csak körvonal currentColor-ral (maszkhoz, vízjelhez).
    """
    stroke, fill, accent, bg = pal
    if line_only:
        stroke = fill = accent = 'currentColor'
    sw = 0.9
    nofill = 'none'
    defs = []
    body = []

    def shape(id_, d):
        defs.append(f'<path id="{id_}" d="{d}"/>')

    def g(content, *, st=stroke, fl=nofill, w=sw, op=None):
        o = f' opacity="{op}"' if op else ''
        body.append(f'<g fill="{fl}" stroke="{st}" stroke-width="{f(w)}" stroke-linejoin="round"{o}>{content}</g>')

    lf = nofill if line_only else fill
    la = nofill if line_only else accent

    # 1. közép
    body.append(f'<circle r="4.2" fill="{accent if not line_only else "currentColor"}"/>')
    shape('c1', teardrop(6, 15 + style, 3.6))
    g(ring(sym // 2 if sym >= 12 else sym, 'c1'), fl=la, w=sw * .8)
    body.append(f'<circle r="{17 + style}" fill="none" stroke="{stroke}" stroke-width="{f(sw)}"/>')

    # 2. belső lótusz – két eltolt sor
    r0 = 18 + style
    shape('p1', petal(r0, r0 + 22, 9, tip=3))
    shape('p1v', f'M0 {f(-r0 - 3)}L0 {f(-r0 - 17)}')
    g(ring(sym, 'p1', 180 / sym), fl=lf, w=sw)
    shape('p2', petal(r0, r0 + 27, 7.5, tip=4))
    g(ring(sym, 'p2'), fl=la if style % 2 == 0 else lf, w=sw)
    g(ring(sym, 'p1v'), w=sw * .6, op='.7')

    # 3. pontsor + kettős kör
    r = r0 + 31
    body.append(f'<circle r="{f(r)}" fill="none" stroke="{stroke}" stroke-width="{f(sw * .8)}"/>')
    body.append(f'<g fill="{accent if not line_only else "currentColor"}">{dots(r + 3.6, sym * 3, 1.15)}</g>')
    body.append(f'<circle r="{f(r + 7)}" fill="none" stroke="{stroke}" stroke-width="{f(sw * .8)}"/>')

    # 4. külső szirmok ereződéssel, a változat szerint
    r1 = r + 7
    if style in (0, 2):
        shape('p3', petal(r1, r1 + 24, 11, tip=5))
        shape('p3i', petal(r1 + 3, r1 + 18, 5.5))
        g(ring(sym, 'p3'), fl=lf)
        g(ring(sym, 'p3i'), fl=la, w=sw * .7)
        top = r1 + 24
    else:
        shape('p3', teardrop(r1, r1 + 20, 8))
        shape('p3i', teardrop(r1 + 4, r1 + 13, 3.5))
        g(ring(sym, 'p3', 180 / sym), fl=lf)
        g(ring(sym, 'p3i', 180 / sym), fl=la, w=sw * .7)
        shape('p3b', petal(r1, r1 + 25, 6, tip=4))
        g(ring(sym, 'p3b'), fl=lf, w=sw * .8)
        top = r1 + 25

    # 5. ívsor és külső pontkoszorú
    rs = top + 2
    body.append(f'<path d="{scallop(rs, sym * 2, 5.5)}" fill="none" stroke="{stroke}" stroke-width="{f(sw * .9)}"/>')
    body.append(f'<g fill="{accent if not line_only else "currentColor"}">{dots(rs + 5.2, sym * 2, 1.4, 180 / (sym * 2))}</g>')
    if style >= 2:
        shape('sp', f'M-2.2 {f(-rs - 6)}L0 {f(-rs - 12)}L2.2 {f(-rs - 6)}Z')
        g(ring(sym * 2, 'sp'), fl=stroke if not line_only else 'currentColor', st='none', w=0)
    body.append(f'<circle r="{f(rs + 9 + (4 if style >= 2 else 0))}" fill="none" stroke="{stroke}" stroke-width="{f(sw * .7)}" opacity=".6"/>')

    size = rs + 14 + (4 if style >= 2 else 0)
    bgc = '' if line_only or not bg else f'<circle r="{f(size)}" fill="{bg}"/>'
    vb = f'{f(-size)} {f(-size)} {f(size * 2)} {f(size * 2)}'
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}" class="mandala-art" aria-hidden="true">'
            f'<defs>{"".join(defs)}</defs>{bgc}{"".join(body)}</svg>')


def glyph():
    """Apró jel: 8 szirom + kör + pont (a felirat előtti dísz, maszkként)."""
    s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-12 -12 24 24"><g fill="#000">'
    for i in range(8):
        s += f'<path d="{petal(4.2, 11.5, 2.6)}" transform="rotate({i * 45})"/>'
    s += '<circle r="1.6"/>'
    # Csak fekete (átlátszó háttér): CSS maszkként az alfa számít, fehér kitöltés nem lehet benne.
    return s + '</g></svg>'


PALETTES = {
    # név: (vonal, kitöltés, kiemelés, háttér)
    'saffron': (DEEP, '#F6E6CF', SAFFRON, '#FBF3E6'),
    'maroon': (MAROON, '#F1DEDA', '#B9605A', '#F8EEEC'),
    'sage': ('#51613F', '#E3E9D8', SAGE, '#F1F4EB'),
    'sky': ('#3F5A6A', '#DDE7EC', SKY, '#EEF3F6'),
    'gold-night': (GOLD, '#2A221B', SAFFRON, NIGHT),
    'sand': (DEEP, SAND, ACCENT, PAPER),
}

if __name__ == '__main__':
    OUT.mkdir(parents=True, exist_ok=True)
    medals = [('saffron', 12, 0), ('sage', 16, 1), ('maroon', 12, 2), ('sky', 16, 3), ('gold-night', 24, 2), ('sand', 8, 1)]
    for name, sym, style in medals:
        (OUT / f'medal-{name}.svg').write_text(mandala(sym, style, PALETTES[name]))
    for i, (sym, style) in enumerate([(24, 2), (16, 0), (12, 3)], 1):
        (OUT / f'lines-{i}.svg').write_text(mandala(sym, style, PALETTES['sand'], line_only=True))
    (OUT / 'glyph.svg').write_text(glyph())
    for p in sorted(OUT.glob('*.svg')):
        print(f'{p.name:24} {p.stat().st_size / 1024:5.1f} KB')
