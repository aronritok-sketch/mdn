#!/usr/bin/env python3
"""
Szezonális főoldali képek összeállítása a bolt SAJÁT termékfotóiból (valódi kompozitálás, nincs képgeneráló AI).

Mit csinál
  1. Betölti a termékfotók eredetijét (a webshop 2000×2256-os, fehér / világos hátterű képei).
  2. Kivágja a terméket: rembg (isnet-general-use), ha elérhető; különben fehér-matt kivágás
     (háttérbecslés + tollazott alfa). Utána szélszín-tisztítás (a fehér háttér visszaverődését levonjuk).
  3. Eljárásos hátteret épít a téma palettájában: fal + asztallap (dió / len / okker papír-ív),
     finom textúrával, meleg „aranyóra” fényeséssel, opcionálisan levélárnyékkal vagy ablakfénnyel,
     halvány mandala vonalrajzzal (tools/gen-mandalas.py SVG-i).
  4. Elhelyezi a termékeket valós méretarányban (cm-ben megadott szélesség), egy fényirányhoz igazított
     kontaktárnyékkal és hosszú, puha vetett árnyékkal, újravilágítással (perem- és oldalfény),
     a hátsó tárgyakon mélységélesség-elmosással.
  5. Füstölős jelenetekhez eljárásos füstcsíkok, gyertyákhoz láng és fényudvar.
  6. Őszi színkezelés: meleg fehéregyensúly, osztott tónus (meleg csúcsfény / szilva-olív árnyék),
     lágy vignetta, filmszemcse.
  7. WebP-be ment PONTOSAN a slot fájlneveivel (name-{szélesség}.webp), minden srcset-szélességben.

A téma mandala_picture() függvénye az assets/img/{név}-{szélesség}.webp fájlokat globbolja, így a kimenet
egyszerűen bemásolható (ugyanazzal a névvel felülírja a régit; plusz szélesség = plusz srcset-elem).

Használat
  python3 -I tools/season-compose.py --src <osz-kepek/src> --work <munkamappa> --out <osz-kepek/out>
         [--sets borostyan,okker,len] [--slots hero,fustolok,...] [--cut-only] [--proof]

  --src    a termékfotók mappája (manifest.json + témánkénti almappák)
  --work   gyorsítótár (kivágások, mandala-raszterek); újrafuttatáskor nem vág ki újra
  --out    kimenet: <out>/<set>/<fájlnév>-<szélesség>.webp, valamint <out>/_proof/ előnézetek

Függőségek: numpy, Pillow, opencv-python-headless; opcionális: rembg[cpu] + onnxruntime, cairosvg.
A futás determinisztikus (rögzített véletlenmag), ugyanaz a bemenet ugyanazt a képet adja.
"""
from __future__ import annotations

import argparse
import json
import math
import os
import sys
from dataclasses import dataclass, field
from pathlib import Path

import cv2
import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
MANDALA_SVG = ROOT / 'wp-theme/mandala/assets/mandala'

# ---------------------------------------------------------------------------
# Paletta (theme.json / assets/css változók) – sRGB hex
# ---------------------------------------------------------------------------
PAL = {
    'paper': '#F7F4EE', 'sand': '#EEE7DB', 'line': '#DDD3C3', 'ink': '#1C1916', 'muted': '#6E6357',
    'accent': '#A9581A', 'deep': '#8A4512', 'maroon': '#6B2A2A', 'night': '#16120F',
    'accent_soft': '#F4E7D6', 'gold': '#E2B77A', 'saffron': '#D9A05B', 'sage': '#7E8F6A', 'sky': '#6F8A9A',
}


def hex2lin(h: str) -> np.ndarray:
    """Hex szín → lineáris RGB (0..1)."""
    h = h.lstrip('#')
    c = np.array([int(h[i:i + 2], 16) for i in (0, 2, 4)], np.float32) / 255
    return srgb2lin(c)


def srgb2lin(a):
    a = np.asarray(a, np.float32)
    return np.where(a <= 0.04045, a / 12.92, ((a + 0.055) / 1.055) ** 2.4).astype(np.float32)


def lin2srgb(a):
    a = np.clip(np.asarray(a, np.float32), 0, 1)
    return np.where(a <= 0.0031308, a * 12.92, 1.055 * np.power(a, 1 / 2.4) - 0.055).astype(np.float32)


def smoothstep(e0, e1, x):
    t = np.clip((x - e0) / (e1 - e0 + 1e-9), 0, 1)
    return t * t * (3 - 2 * t)


def blur(a, sigma):
    """Gauss-elmosás (sigma px-ben), 2D vagy 3 csatornás tömbre."""
    if sigma <= 0.3:
        return a
    return cv2.GaussianBlur(a, (0, 0), sigmaX=float(sigma), sigmaY=float(sigma), borderType=cv2.BORDER_REFLECT)


def blur_big(a, sigma):
    """Nagy sugarú elmosás gyorsan: lekicsinyít, elmos, visszanagyít."""
    if sigma < 24:
        return blur(a, sigma)
    f = max(1, int(sigma / 12))
    h, w = a.shape[:2]
    small = cv2.resize(a, (max(1, w // f), max(1, h // f)), interpolation=cv2.INTER_AREA)
    small = blur(small, sigma / f)
    return cv2.resize(small, (w, h), interpolation=cv2.INTER_LINEAR)


# ---------------------------------------------------------------------------
# Zaj és textúra
# ---------------------------------------------------------------------------
def value_noise(h, w, cell, rng, stretch=(1.0, 1.0)):
    """Sima értékzaj: véletlen rács köbös nagyítással. cell = jellemző méret px; stretch = (x, y) nyújtás."""
    cx, cy = max(1.0, cell * stretch[0]), max(1.0, cell * stretch[1])
    gw, gh = int(math.ceil(w / cx)) + 2, int(math.ceil(h / cy)) + 2
    g = rng.random((gh, gw)).astype(np.float32)
    big = cv2.resize(g, (int(gw * cx), int(gh * cy)), interpolation=cv2.INTER_CUBIC)
    ox, oy = int(cx * 0.5), int(cy * 0.5)
    return big[oy:oy + h, ox:ox + w]


def fbm(h, w, cell, rng, octaves=5, gain=0.5, stretch=(1.0, 1.0)):
    """Fraktál (fBm) zaj 0..1 körül normálva."""
    acc = np.zeros((h, w), np.float32)
    amp, tot = 1.0, 0.0
    for o in range(octaves):
        c = cell / (2 ** o)
        if c < 1.2:
            break
        acc += amp * value_noise(h, w, c, rng, stretch)
        tot += amp
        amp *= gain
    acc /= tot
    return (acc - acc.mean()) / (acc.std() + 1e-6) * 0.18 + 0.5


# ---------------------------------------------------------------------------
# Termékek: kulcs → (fájl a manifestből, valós szélesség cm-ben, megjegyzés)
# A cm-érték a tárgy képen látható befoglaló szélessége (pl. tál átmérője), ebből jön a méretarány.
# ---------------------------------------------------------------------------
PRODUCT_CM = {
    '7777/0': 20.0,   # Full moon hangtál 0,97 kg
    '7818/0': 15.0,   # Full moon hangtál 0,5 kg
    '3522/0': 14.5,   # Antik Naga talpas hangtál (arany)
    '3519/0': 14.5,   # Antik Naga talpas hangtál (sötét patina)
    '3553/0': 16.0,   # Antikolt hangtál 670 g
    '7734/0': 15.5,   # Mintás hangtál 0,64 kg
    '7704/2': 15.0,   # Mintás hangtál 0,57 kg
    '5624/1': 17.0,   # Hangtál párna kocka L – lila/bordó
    '5618/0': 17.0,   # Hangtál párna kocka L – sárga
    '5630/1': 17.0,   # Hangtál párna kocka L – piros
    '4740/0': 17.0,   # Hangtál ütő bőr
    '6644/0': 13.0,   # Palo Santo 50 g
    '1376/2': 10.0,   # Lótusvirág füstölőtartó S (pálcával)
    '1409/0': 9.5,    # Kerámia füstölőtartó – bordó
    '1396/0': 10.0,   # Buddhás lefelé áramló füstölőtartó
    '3968/0': 15.0,   # Füstölő égető – nagy (réz, láncon)
    '90/1': 9.0,      # Minimalista kerámia füstölőtartó
    '6144/0': 58.0,   # Pashmina kendő virágos barna (drapírozva)
    '6794/0': 36.0,   # Bucket hat gombás barna
    '6776/0': 38.0,   # Narancssárga kalap
    '6787/0': 38.0,   # Natúr barna kalap
    '2885/0': 21.0,   # Ganésha mécsestartó
    '3016/0': 11.0,   # Gyertyatartó L – barna
    '3018/0': 11.0,   # Gyertyatartó M – natúr
    '6621/0': 24.0,   # Thai fa Buddha
    '6642/0': 50.0,   # Rattan fali dekor kerek
    '6636/0': 46.0,   # Tengerifű tükör
    '6475/1': 15.0,   # Malacka mécsestartó
    '1384/0': 16.0,   # Aura gyertya szett (3 db)
    '1465/0': 12.5,   # Arany integető cica – legyező
    '1467/0': 12.5,   # Arany integető cica – rózsa
    '1462/0': 12.5,   # Arany integető cica – kanji
    '2735/1': 13.0,   # Három lábú béka
    '6822/0': 14.0,   # Réz kancsó veretes
    '6817/0': 15.0,   # Réz kancsó diamond
    '6847/1': 14.0,   # Réz kulacs pohárral
    '4554/0': 14.0,   # Nepal special masala tea
    '1052/1': 9.0,    # Goloka cédrus illóolaj
    '1692/0': 16.0,   # Antikolt kerámia párologtató
    '2129/0': 11.0,   # Amitabha Buddha réz szobor
    '1874/0': 22.0,   # Buddha mellplasztika L
    '84/0': 12.5,     # Moszkva mule bögre
    '74/0': 15.0,     # Rézkanna 1 l
    '6997/0': 60.0,   # Sál indiános barna
    '6994/0': 60.0,   # Sál indiános bordó
}

CUT_KEYS = sorted(PRODUCT_CM)


@dataclass
class Product:
    key: str
    name: str
    url: str
    rgb: np.ndarray      # lineáris RGB, kivágva, a tárgy befoglaló dobozára vágva
    alpha: np.ndarray    # 0..1
    cm: float            # valós szélesség

    @property
    def px_per_cm(self):
        return self.alpha.shape[1] / self.cm


# ---------------------------------------------------------------------------
# Kivágás
# ---------------------------------------------------------------------------
_REMBG = None


def rembg_session(work: Path):
    """rembg munkamenet (isnet-general-use); None, ha nem elérhető vagy a modell nem tölthető le."""
    global _REMBG
    if _REMBG is not None:
        return _REMBG or None
    try:
        os.environ.setdefault('U2NET_HOME', str(work / 'models'))
        from rembg import new_session  # noqa: WPS433
        _REMBG = new_session('isnet-general-use')
    except Exception as exc:  # pragma: no cover - csak tartalék út
        print('  rembg nem elérhető, fehér-matt kivágás:', exc, file=sys.stderr)
        _REMBG = False
    return _REMBG or None


def estimate_background(img: np.ndarray, alpha: np.ndarray) -> np.ndarray:
    """A háttér színe pixelenként: normalizált konvolúció a biztosan háttér pixeleken (α≈0)."""
    m = (alpha < 0.02).astype(np.float32)
    sig = max(img.shape[:2]) * 0.03
    num = blur_big(img * m[..., None], sig)
    den = blur_big(m, sig)[..., None]
    glob = (img * m[..., None]).sum((0, 1)) / max(m.sum(), 1)
    return np.where(den > 0.02, num / np.maximum(den, 1e-4), glob).astype(np.float32)


def white_matte(img: np.ndarray) -> np.ndarray:
    """Tartalék kivágás fehér / világos háttérhez: a szegélyből becsült háttértől való távolság → alfa."""
    h, w = img.shape[:2]
    border = np.concatenate([img[:8].reshape(-1, 3), img[-8:].reshape(-1, 3),
                             img[:, :8].reshape(-1, 3), img[:, -8:].reshape(-1, 3)])
    bg = np.median(border, 0)
    d = np.linalg.norm(img - bg, axis=2)
    a = smoothstep(0.035, 0.12, d)
    a = cv2.morphologyEx(a, cv2.MORPH_CLOSE, np.ones((9, 9), np.uint8))
    # lyukak kitöltése: a legnagyobb összefüggő folt és belseje
    bw = (a > 0.5).astype(np.uint8)
    n, lab, stats, _ = cv2.connectedComponentsWithStats(bw)
    if n > 1:
        big = 1 + int(np.argmax(stats[1:, cv2.CC_STAT_AREA]))
        keep = (lab == big).astype(np.uint8)
        ff = keep.copy()
        mask = np.zeros((h + 2, w + 2), np.uint8)
        cv2.floodFill(ff, mask, (0, 0), 1)
        holes = (ff == 0)
        a = np.maximum(a * keep, holes.astype(np.float32))
    return blur(a, 1.2)


def cut_product(src_dir: Path, entry: dict, work: Path) -> Path:
    """Egy termékfotó kivágása → RGBA PNG a gyorsítótárban (lineáris szín helyett sRGB-t tárolunk)."""
    key = f"{entry['id']}_{entry['image_index']}"
    out = work / 'cut' / f'{key}.png'
    if out.exists():
        return out
    out.parent.mkdir(parents=True, exist_ok=True)
    pil = Image.open(src_dir.parent / entry['file']).convert('RGB')
    img = np.asarray(pil, np.float32) / 255
    sess = rembg_session(work)
    if sess is not None:
        from rembg import remove  # noqa: WPS433
        cut = remove(pil, session=sess, post_process_mask=True)
        alpha = np.asarray(cut, np.float32)[..., 3] / 255
    else:
        alpha = white_matte(img)
    # szélszín-tisztítás: F = (I - (1-α)·B) / α  – a fehér háttér nem „dereng” át a peremen
    bgc = estimate_background(img, alpha)
    a3 = np.maximum(alpha, 0.04)[..., None]
    fg = np.clip((img - (1 - alpha[..., None]) * bgc) / a3, 0, 1)
    fg = np.where(alpha[..., None] > 0.985, img, fg)
    # a nagyon halvány perem levágása (glória ellen)
    alpha = np.clip((alpha - 0.05) / 0.92, 0, 1)
    ys, xs = np.where(alpha > 0.02)
    y0, y1, x0, x1 = ys.min(), ys.max() + 1, xs.min(), xs.max() + 1
    rgba = np.dstack([fg, alpha])[y0:y1, x0:x1]
    Image.fromarray((rgba * 255 + 0.5).astype(np.uint8), 'RGBA').save(out)
    return out


def load_manifest(src: Path) -> dict:
    m = json.loads((src / 'manifest.json').read_text())
    idx = {}
    for lst in m.values():
        for e in lst:
            idx[f"{e['id']}/{e['image_index']}"] = e
    return idx


_PCACHE: dict = {}


def product(key: str, ctx) -> Product:
    if key in _PCACHE:
        return _PCACHE[key]
    e = ctx.manifest[key]
    p = cut_product(ctx.src, e, ctx.work)
    rgba = np.asarray(Image.open(p), np.float32) / 255
    pr = Product(key, e['name'], e['url'], srgb2lin(rgba[..., :3]), rgba[..., 3].copy(), PRODUCT_CM[key])
    _PCACHE[key] = pr
    return pr


def crop_texture(key: str, ctx, box) -> np.ndarray:
    """Textil (falikép, ágyterítő) belső téglalapja textúraként – nem kivágás, csak a szövet maga.
    box = (x0, y0, x1, y1) a fotó arányában."""
    e = ctx.manifest[key]
    img = np.asarray(Image.open(ctx.src.parent / e['file']).convert('RGB'), np.float32) / 255
    h, w = img.shape[:2]
    x0, y0, x1, y1 = [int(v) for v in (box[0] * w, box[1] * h, box[2] * w, box[3] * h)]
    return srgb2lin(img[y0:y1, x0:x1])


# ---------------------------------------------------------------------------
# Fény
# ---------------------------------------------------------------------------
@dataclass
class Light:
    """Egy fő fényforrás (ablak / gyertya / alacsony nap) és a szórt fény.
    dir_xy: a fény FELŐLI irány a képsíkban (x jobbra, y lefelé); elev: magasság fokban (alacsony = hosszú árnyék)."""
    dir_xy: tuple = (-1.0, -0.3)
    elev: float = 22.0
    key_color: str = '#FFC98A'
    key: float = 1.0
    amb_color: str = '#6E5A4E'
    amb: float = 0.35
    shadow_depth: float = 0.35   # árnyék a padló síkjában: + = a néző felé, - = a fal felé
    shadow_soft: float = 1.0
    rim: float = 0.6

    @property
    def L3(self):
        d = np.array(self.dir_xy, np.float32)
        d /= np.linalg.norm(d) + 1e-9
        e = math.radians(self.elev)
        return np.array([d[0] * math.cos(e), d[1] * math.cos(e), math.sin(e)], np.float32)


# ---------------------------------------------------------------------------
# Jelenet
# ---------------------------------------------------------------------------
@dataclass
class Item:
    key: str
    x: float               # a tárgy talppontjának közepe (vászon arány, 0..1)
    y: float               # talpvonal (vászon arány)
    scale: float = 1.0     # szorzó a jelenet cm→px arányán felül
    depth: float = 0.0     # 0 = élességi sík; >0 = hátrébb (mosás); <0 = előrébb
    flip: bool = False
    rot: float = 0.0       # fok
    shadow: float = 1.0    # vetett árnyék erőssége
    contact: float = 1.0   # kontaktárnyék
    smoke: list = field(default_factory=list)    # füstforrás(ok) a tárgy arányában [(u, v, hossz, mag)]
    flames: list = field(default_factory=list)   # láng(ok) a tárgy arányában [(u, v, méret)]
    floor_y: float | None = None  # ha a tárgy egy másikon ül (tál a párnán): a padló érintkezési vonala
    z: float | None = None        # rajzolási sorrend (alapból y); a hordozó tárgy után kell jönnie
    on: str | None = None         # a hordozó tárgy kulcsa (pl. a tál a párnán ül): x, y, floor_y, z ebből jön
    seat: float = 0.36            # a hordozó befoglaló dobozában felülről mért arány, ahol a talp ül
    dx: float = 0.0               # vízszintes eltolás a hordozóhoz képest (vászon arány)
    exposure: float = 1.0
    reflect: float = 0.0   # tükröződés fényes asztalon
    crop: tuple | None = None  # a kivágás egy része (u0, v0, u1, v1)
    spec: float = 0.6          # fémes csúcsfény erőssége (réz, sárgaréz: magas; textil: alacsony)
    selflit: bool = False      # saját fényű tárgy (égő gyertya): nem a jelenet fénye világítja, hanem a lángja
    wall: bool = False         # falon lógó tárgy (rattan nap, tükör): nincs padlóárnyék, csak rövid vetett árnyék a falon


@dataclass
class Scene:
    w: int
    h: int
    px_per_cm: float               # az élességi síkban (y = horizon_ref) a cm→px arány
    backdrop: dict
    light: Light
    items: list
    grade: dict
    seed: int = 7
    horizon: float = -0.6          # perspektíva: a horizont (vászon arány, lehet a képen kívül)
    effects: list = field(default_factory=list)


class Ctx:
    def __init__(self, src, work):
        self.src = Path(src)
        self.work = Path(work)
        self.manifest = load_manifest(self.src)


# ---------------------------------------------------------------------------
# Háttér
# ---------------------------------------------------------------------------
def wood_albedo(h, w, rng, base='#3A2618', light='#6A4529', plank=260, grain=26.0):
    """Eljárásos diófa asztallap a deszka síkjában (x = rost iránya).
    Szabálytalan évgyűrű-vonalak (változó sűrűség, csavarodás), finom rostok, deszkánként eltérő tónus."""
    yy = np.arange(h, dtype=np.float32)[:, None] * np.ones((1, w), np.float32)
    warp = (fbm(h, w, plank * 1.2, rng, 4, stretch=(5, 1)) - 0.5) * 2
    warp2 = (fbm(h, w, plank * 0.3, rng, 3, stretch=(8, 1)) - 0.5) * 2
    freq = 1 + 0.6 * (fbm(h, w, plank * 2, rng, 2, stretch=(3, 1)) - 0.5)
    ph = (yy / grain) * freq + warp * 9.0 + warp2 * 2.5
    rings = np.abs(np.sin(np.pi * ph)) ** 6 * (0.4 + 0.6 * fbm(h, w, plank * 0.5, rng, 2, stretch=(6, 1)))
    fibres = fbm(h, w, 18, rng, 3, stretch=(22, 1))
    tone = fbm(h, w, plank * 3, rng, 3, stretch=(4, 1))
    plank_id = (yy // plank).astype(int)
    pv = (np.sin(plank_id * 12.9898 + 4.1) * 43758.5453) % 1.0
    t = 0.55 + 0.9 * (tone - 0.5) + 0.25 * (pv - 0.5) + 0.35 * (fibres - 0.5) - 0.18 * rings
    seam = np.exp(-((yy % plank) / 1.6) ** 2)
    t = np.clip(t, 0, 1)
    a, b = hex2lin(base), hex2lin(light)
    col = a + (b - a) * t[..., None]
    col *= (1 - 0.6 * seam)[..., None]
    return col.astype(np.float32)


def linen_albedo(h, w, rng, base='#E9DFCF', var=0.06, thread=3.2):
    """Len / vászon szövés: finom vízszintes és függőleges szálzaj, enyhe gyűrődéssel."""
    hx = fbm(h, w, thread * 6, rng, 3, stretch=(30, 1))   # vízszintes szálak
    vy = fbm(h, w, thread * 6, rng, 3, stretch=(1, 30))   # függőleges szálak
    slub = fbm(h, w, 140, rng, 3, stretch=(8, 1))
    crease = fbm(h, w, 600, rng, 4)
    t = 0.5 * hx + 0.5 * vy
    col = hex2lin(base) * (1 + var * 2.2 * (t - 0.5) + var * (slub - 0.5) + 0.10 * (crease - 0.5))[..., None]
    return col.astype(np.float32)


def plaster_albedo(h, w, rng, base='#2B211B', var=0.10):
    """Vakolt fal: lágy foltosság + finom szemcse."""
    t = fbm(h, w, 500, rng, 5) * 0.7 + fbm(h, w, 30, rng, 3) * 0.3
    return (hex2lin(base) * (1 + var * 3 * (t - 0.5))[..., None]).astype(np.float32)


def paper_sweep_albedo(h, w, rng, top='#B5642A', bottom='#C77A3C', var=0.03):
    """Színes papír-ív (végtelenített háttér): felülről lefelé lágy átmenet, alig látható szemcse."""
    yy = np.linspace(0, 1, h, dtype=np.float32)[:, None, None]
    col = hex2lin(top) * (1 - yy) + hex2lin(bottom) * yy
    t = fbm(h, w, 700, rng, 4)
    col = col * (1 + var * 3 * (t - 0.5))[..., None]
    return np.broadcast_to(col, (h, w, 3)).astype(np.float32).copy()


def mandala_layer(w, h, cx, cy, size, which=1, work: Path | None = None):
    """Halvány mandala vonalrajz (a téma lines-N.svg-je) raszterként, 0..1 maszk."""
    try:
        import cairosvg  # noqa: WPS433
    except Exception:
        return np.zeros((h, w), np.float32)
    s = int(size)
    cache = (work / 'mandala' / f'lines-{which}-{s}.png') if work else None
    if cache and cache.exists():
        m = np.asarray(Image.open(cache), np.float32)[..., 3] / 255
    else:
        svg = (MANDALA_SVG / f'lines-{which}.svg').read_text().replace('currentColor', '#000')
        import io
        png = cairosvg.svg2png(bytestring=svg.encode(), output_width=s, output_height=s)
        im = Image.open(io.BytesIO(png)).convert('RGBA')
        if cache:
            cache.parent.mkdir(parents=True, exist_ok=True)
            im.save(cache)
        m = np.asarray(im, np.float32)[..., 3] / 255
    out = np.zeros((h, w), np.float32)
    x0, y0 = int(cx - s / 2), int(cy - s / 2)
    xa, ya, xb, yb = max(0, x0), max(0, y0), min(w, x0 + s), min(h, y0 + s)
    if xb > xa and yb > ya:
        out[ya:yb, xa:xb] = m[ya - y0:yb - y0, xa - x0:xb - x0]
    return out


def perspective_floor(tex, w, h, y_far, spread=1.9, tilt=0.0):
    """A deszka-síkú textúrát a padló trapézára vetíti: a távoli él (y_far) keskenyebb, az alsó szélesebb."""
    th, tw = tex.shape[:2]
    src = np.float32([[0, 0], [tw, 0], [tw, th], [0, th]])
    yf = y_far * h
    cx = w / 2 + tilt * w
    far_half = w * 0.75
    near_half = far_half * spread
    dst = np.float32([[cx - far_half, yf], [cx + far_half, yf], [cx + near_half, h * 1.02], [cx - near_half, h * 1.02]])
    M = cv2.getPerspectiveTransform(src, dst)
    return cv2.warpPerspective(tex, M, (w, h), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)


def leaf_gobo(w, h, rng, density=0.5, leaf=60, branches=5, angle=-25):
    """Levél-árnyék minta (őszi, alacsony nap egy ablak előtti fa lombján át). 1 = árnyék.
    Kis felbontáson rajzol, majd életlenre mos: így nem rajzolt levél, hanem fény-folt hatású."""
    f = 4
    sw, sh = w // f, h // f
    img = np.zeros((sh, sw), np.uint8)
    lf = leaf / f
    for b in range(branches):
        x = rng.uniform(-0.2, 1.2) * sw
        y = rng.uniform(-0.2, 0.6) * sh
        ang = math.radians(angle + rng.uniform(-35, 35))
        for s in range(int(28 * density + 10)):
            x += math.cos(ang) * lf * 0.9 + rng.normal(0, lf * 0.3)
            y += math.sin(ang) * lf * 0.9 + rng.normal(0, lf * 0.3)
            ang += rng.normal(0, 0.18)
            for _ in range(3):
                lx = x + rng.normal(0, lf * 1.1)
                ly = y + rng.normal(0, lf * 1.1)
                a = rng.uniform(0, 180)
                L = lf * rng.uniform(0.6, 1.25)
                cv2.ellipse(img, (int(lx), int(ly)), (int(L), int(L * 0.42)), a, 0, 360, 255, -1, cv2.LINE_AA)
            cv2.line(img, (int(x), int(y)), (int(x - math.cos(ang) * lf), int(y - math.sin(ang) * lf)),
                     255, max(1, int(lf * 0.12)), cv2.LINE_AA)
    g = img.astype(np.float32) / 255
    g = cv2.resize(g, (w, h), interpolation=cv2.INTER_LINEAR)
    return np.clip(g, 0, 1)


def window_light(w, h, rect, skew=0.35, soft=40, bars=True):
    """Ablakfény-folt (két ablakszárny, középső osztással): 1 = fény. rect = (x0, y0, x1, y1) arányban."""
    m = np.zeros((h, w), np.float32)
    x0, y0, x1, y1 = rect[0] * w, rect[1] * h, rect[2] * w, rect[3] * h
    sk = skew * (y1 - y0)
    poly = np.float32([[x0 + sk, y0], [x1 + sk, y0], [x1, y1], [x0, y1]])
    cv2.fillPoly(m, [poly.astype(np.int32)], 1.0, cv2.LINE_AA)
    if bars:
        bw = (x1 - x0) * 0.035
        xm = (x0 + x1) / 2
        bar = np.float32([[xm - bw + sk, y0], [xm + bw + sk, y0], [xm + bw, y1], [xm - bw, y1]])
        cv2.fillPoly(m, [bar.astype(np.int32)], 0.0, cv2.LINE_AA)
        ym = (y0 + y1) / 2
        skm = sk * 0.5
        hb = np.float32([[x0 + skm, ym - bw * 0.8], [x1 + skm, ym - bw * 0.8], [x1 + skm, ym + bw * 0.8],
                         [x0 + skm, ym + bw * 0.8]])
        cv2.fillPoly(m, [hb.astype(np.int32)], 0.0, cv2.LINE_AA)
    return blur_big(m, soft)


def build_backdrop(sc: Scene, ctx: Ctx, rng):
    """Visszaadja: albedo (lin RGB), kulcsfény-térkép K, szórt fény A, mélységtérkép D (0 = éles, 1 = messze),
    padló maszk F (1 = asztallap)."""
    w, h, bd = sc.w, sc.h, sc.backdrop
    kind = bd['kind']
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    yy /= h
    xx /= w
    if kind == 'sweep':
        alb = paper_sweep_albedo(h, w, rng, bd.get('top', '#B5642A'), bd.get('bottom', '#C77A3C'))
        floor = smoothstep(bd.get('bend', 0.55) - 0.12, bd.get('bend', 0.55) + 0.12, yy)
        depth = 1 - floor * smoothstep(0.4, 1.0, yy)
        depth = np.clip(depth * bd.get('wall_depth', 0.55), 0, 1)
    else:
        yw = bd.get('wall_line', 0.5)
        wall = plaster_albedo(h, w, rng, bd.get('wall', '#2B211B'), bd.get('wall_var', 0.1))
        if bd.get('wall_tex') == 'linen':
            wall = linen_albedo(h, w, rng, bd.get('wall', '#D9CDBB'), 0.04, 4)
        if kind == 'wood':
            tex = wood_albedo(int(h * 1.4), int(w * 1.6), rng, bd.get('wood', '#3A2618'), bd.get('wood_light', '#6A4529'),
                              plank=bd.get('plank', int(h * 0.42)), grain=bd.get('grain', h * 0.012))
        else:
            tex = linen_albedo(int(h * 1.2), int(w * 1.4), rng, bd.get('cloth', '#E6DBC9'), 0.06, bd.get('thread', 3.0))
        flo = perspective_floor(tex, w, h, yw, bd.get('spread', 1.9))
        # a falra függesztett textil (valódi termék) – életlen téglalap
        for hang in bd.get('hangings', []):
            t = crop_texture(hang['key'], ctx, hang['box'])
            x0, y0, x1, y1 = [int(v) for v in (hang['rect'][0] * w, hang['rect'][1] * h, hang['rect'][2] * w,
                                                 hang['rect'][3] * h)]
            tw, th = x1 - x0, y1 - y0
            t = cv2.resize(t, (tw, th), interpolation=cv2.INTER_AREA) * hang.get('gain', 1.0)
            # puha redők: alacsony frekvenciás fény-árnyék moduláció függőlegesen
            folds = 1 + hang.get('folds', 0.12) * (fbm(th, tw, tw * 0.25, rng, 2, stretch=(0.35, 3)) - 0.5) * 2
            t = t * folds[..., None]
            m = np.zeros((h, w), np.float32)
            m[max(0, y0):min(h, y1), max(0, x0):min(w, x1)] = 1
            region = np.zeros_like(wall)
            ya, yb, xa, xb = max(0, y0), min(h, y1), max(0, x0), min(w, x1)
            region[ya:yb, xa:xb] = t[ya - y0:yb - y0, xa - x0:xb - x0]
            m = blur(m, 2)
            wall = wall * (1 - m[..., None]) + region * m[..., None]
            # vetett árnyék a falikép alatt (kissé eltolva a fénnyel szemben)
            sh = blur_big(np.roll(m, (int(h * 0.01), int(-np.sign(sc.light.dir_xy[0]) * w * 0.006)), (0, 1)), h * 0.012)
            wall *= (1 - 0.35 * np.clip(sh - m, 0, 1))[..., None]
        soft = h * bd.get('junction_soft', 0.012)
        floor = smoothstep(yw - soft / h, yw + soft / h, yy)
        alb = wall * (1 - floor[..., None]) + flo * floor[..., None]
        # mélység: a fal messze van, az asztallap közelít
        fd = 1 - smoothstep(yw, 1.0, yy)
        depth = np.where(floor > 0.5, fd * bd.get('floor_depth', 0.6), 1.0) * (1 - floor) + floor * fd * bd.get('floor_depth', 0.6)
        depth = np.clip(depth, 0, 1)
    # --- fény ---
    L = sc.light
    lp = bd.get('light_pos', (1.1, -0.2))      # a fényfolt középpontja (arány, lehet képen kívül)
    lr = bd.get('light_radius', 1.1)
    dx = (xx - lp[0]) * (w / h)
    dy = (yy - lp[1])
    dist = np.sqrt(dx * dx + dy * dy)
    K = np.exp(-(dist / lr) ** 2) * L.key
    if bd.get('pool'):
        px, py, pr, pk = bd['pool']
        d2 = np.sqrt(((xx - px) * w / h) ** 2 + ((yy - py) / 0.55) ** 2)
        K += pk * np.exp(-(d2 / pr) ** 2)
    if bd.get('sheen'):
        # fényes asztallap: a fényforrás elmosódott tükröződése a lap síkjában (függőlegesen nyújtott folt)
        sx_, sy_, sw_, sh_, sk_ = bd['sheen']
        sheen = np.exp(-(((xx - sx_) * w / h) / sw_) ** 2 - ((yy - sy_) / sh_) ** 2)
        sc._sheen = (sk_ * sheen * floor).astype(np.float32)
    else:
        sc._sheen = None
    if bd.get('gobo'):
        g = bd['gobo']
        gob = leaf_gobo(w, h, rng, g.get('density', 0.5), g.get('leaf', h * 0.05), g.get('branches', 5),
                        g.get('angle', -25))
        gob = blur_big(gob, h * g.get('soft', 0.012))
        K *= (1 - g.get('strength', 0.75) * gob)
        sc._gobo = gob * g.get('strength', 0.75)
    else:
        sc._gobo = None
    if bd.get('window'):
        wd = bd['window']
        wl = window_light(w, h, wd['rect'], wd.get('skew', 0.35), h * wd.get('soft', 0.02), wd.get('bars', True))
        K = K * (wd.get('outside', 0.25) + (1 - wd.get('outside', 0.25)) * wl)
    A = np.full((h, w), L.amb, np.float32)
    A *= 1 - bd.get('amb_falloff', 0.35) * smoothstep(0.0, 1.0, np.abs(xx - lp[0]))
    if bd.get('mandala'):
        md = bd['mandala']
        ml = mandala_layer(w, h, md['x'] * w, md['y'] * h, md['size'] * h, md.get('which', 1), ctx.work)
        alb = alb * (1 - md.get('alpha', 0.06) * ml[..., None]) + hex2lin(md.get('color', PAL['gold'])) * md.get('alpha', 0.06) * ml[..., None]
    return alb.astype(np.float32), K.astype(np.float32), A.astype(np.float32), depth.astype(np.float32), floor.astype(np.float32)


# ---------------------------------------------------------------------------
# Tárgyak elhelyezése, újravilágítás, árnyék
# ---------------------------------------------------------------------------
def item_scale(sc: Scene, it: Item, pr: Product):
    """cm→px a jelenet perspektívájában: a talpvonal (y) és a horizont távolságával arányos."""
    ref = 0.75
    persp = 1.0 if it.wall else (it.y - sc.horizon) / (ref - sc.horizon)
    target_w = pr.cm * sc.px_per_cm * persp * it.scale
    return target_w / pr.alpha.shape[1]


def prepare_item(sc, it, pr):
    rgb, a = pr.rgb, pr.alpha
    if it.crop:
        hh, ww = a.shape
        u0, v0, u1, v1 = it.crop
        rgb = rgb[int(v0 * hh):int(v1 * hh), int(u0 * ww):int(u1 * ww)]
        a = a[int(v0 * hh):int(v1 * hh), int(u0 * ww):int(u1 * ww)]
    s = item_scale(sc, it, pr)
    nw, nh = max(2, int(a.shape[1] * s)), max(2, int(a.shape[0] * s))
    interp = cv2.INTER_AREA if s < 1 else cv2.INTER_CUBIC
    rgb = cv2.resize(rgb, (nw, nh), interpolation=interp)
    a = np.clip(cv2.resize(a, (nw, nh), interpolation=interp), 0, 1)
    if it.flip:
        rgb, a = rgb[:, ::-1].copy(), a[:, ::-1].copy()
    if it.rot:
        M = cv2.getRotationMatrix2D((nw / 2, nh), it.rot, 1.0)
        rgb = cv2.warpAffine(rgb, M, (nw, nh), flags=cv2.INTER_LINEAR, borderValue=0)
        a = cv2.warpAffine(a, M, (nw, nh), flags=cv2.INTER_LINEAR, borderValue=0)
    return rgb.astype(np.float32), a.astype(np.float32)


def relight(rgb, a, L: Light, Kloc, Aloc, exposure=1.0, gobo=None, spec_k=0.6):
    """A stúdióban szemből világított fotó átvilágítása a jelenet fényére.
    - álnormál a maszkból (párna-normál): a fény felőli perem világosabb, a túloldal sötétebb
    - széles irányított átmenet a tárgyon át
    - peremfény (rim) a fény felőli szélen, meleg színnel
    - a tárgy alja felé enyhe takarás (ambient occlusion)"""
    h, w = a.shape
    size = math.sqrt(h * w)
    hgt = blur(a, size * 0.06)
    gy, gx = np.gradient(hgt)
    k = size * 0.6
    n = np.dstack([-gx * k, -gy * k, np.ones_like(gx)])
    n /= np.linalg.norm(n, axis=2, keepdims=True)
    L3 = L.L3
    lam = np.clip(n @ L3, 0, 1)
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    u = ((xx / w - 0.5) * L3[0] + (yy / h - 0.5) * L3[1]) / (np.hypot(L3[0], L3[1]) + 1e-6)
    broad = 0.62 + 0.75 * (u + 0.5)                                   # ~0.62 … 1.37
    ao = 1 - 0.35 * smoothstep(0.80, 1.0, yy / h)
    kc, ac = hex2lin(L.key_color), hex2lin(L.amb_color)
    key = (lam / max(L3[2] + 0.35, 0.4)) * np.clip(broad, 0.25, 1.6)
    light = Aloc[..., None] * ac * 2.2 + Kloc[..., None] * kc * key[..., None] * 1.25
    out = rgb * exposure * light * ao[..., None]
    # fémes csúcsfény: ami a stúdiófotón fényes tükröződés volt, az most a jelenet fényét tükrözi
    lum0 = (0.2126 * rgb[..., 0] + 0.7152 * rgb[..., 1] + 0.0722 * rgb[..., 2])
    spec = smoothstep(0.25, 0.85, lum0) * np.clip(broad - 0.5, 0, 1.2)
    out += (spec * Kloc * spec_k)[..., None] * kc * rgb / np.maximum(lum0[..., None], 0.05) * 0.9
    # peremfény: a fény felőli szél
    edge = np.clip(a - cv2.erode(a, np.ones((3, 3), np.uint8), iterations=max(1, int(size * 0.006))), 0, 1)
    edge = blur(edge, max(0.8, size * 0.003))
    facing = np.clip((n[..., 0] * L3[0] + n[..., 1] * L3[1]) / (np.hypot(L3[0], L3[1]) + 1e-6), 0, 1)
    out += (edge * facing * L.rim * Kloc)[..., None] * kc * 0.5
    return out.astype(np.float32)


def affine_shadow(a, base_y, kx, ky):
    """A tárgy maszkjából a padlóra vetett árnyék: (x, y) → (x + kx·(base-y), base + ky·(base-y)).
    Visszaadja az árnyék maszkot és a magasság-csatornát (0 = talp, 1 = csúcs) a cél koordinátákban."""
    h, w = a.shape
    # forrás: a magasság a talptól
    hgt = np.clip((base_y - np.arange(h, dtype=np.float32)[:, None]) / max(base_y, 1), 0, 1) * np.ones((1, w), np.float32)
    M = np.float32([[1, -kx, kx * base_y], [0, -ky, base_y * (1 + ky)]])
    return M, hgt


def composite_items(sc: Scene, ctx: Ctx, alb, K, A, depth, floor, rng):
    """Az árnyékok (padló) és a tárgyak rétegei; hátulról előre rajzol."""
    w, h, L = sc.w, sc.h, sc.light
    # hordozó tárgyak (párna) feloldása: a rajta ülő tárgy talpvonala a hordozó tetejéhez igazodik
    by_key = {it.key: it for it in sc.items}
    for it in sc.items:
        if it.on:
            sup = by_key[it.on]
            spr = product(sup.key, ctx)
            _, sa = prepare_item(sc, sup, spr)
            sh_ = sa.shape[0] / h
            it.x = sup.x + it.dx
            it.y = sup.y - sh_ + it.seat * sh_
            it.floor_y = sup.y
            it.z = sup.y + 0.0001
            it.depth = sup.depth
    items = sorted(sc.items, key=lambda it: (it.z if it.z is not None else it.y, -it.depth))
    S_cast = np.zeros((h, w), np.float32)
    S_ao = np.zeros((h, w), np.float32)
    placed = []
    d = np.array(L.dir_xy, np.float32)
    d /= np.linalg.norm(d) + 1e-9
    cot = 1 / math.tan(math.radians(max(L.elev, 4)))
    for it in items:
        pr = product(it.key, ctx)
        rgb, a = prepare_item(sc, it, pr)
        ih, iw = a.shape
        cx, by = it.x * w, it.y * h
        x0, y0 = int(round(cx - iw / 2)), int(round(by - ih))
        placed.append((it, pr, rgb, a, x0, y0))
        if it.shadow <= 0 and it.contact <= 0:
            continue
        # --- vetett árnyék: a fénnyel ellentétes irányba, a padló síkjában ---
        big = np.zeros((h, w), np.float32)
        xa, ya, xb, yb = max(0, x0), max(0, y0), min(w, x0 + iw), min(h, y0 + ih)
        if xb <= xa or yb <= ya:
            continue
        big[ya:yb, xa:xb] = a[ya - y0:yb - y0, xa - x0:xb - x0]
        if it.wall:
            off = ih * 0.04
            sh = np.roll(big, (int(off * 0.6), int(-d[0] * off)), (0, 1))
            S_cast = np.maximum(S_cast, blur_big(sh, ih * 0.03) * 0.6 * it.shadow)
            continue
        base = (it.floor_y if it.floor_y is not None else it.y) * h
        kx = -d[0] * cot * 0.9
        ky = -L.shadow_depth * cot * 0.35 * (1 if d[1] <= 0 else -1)
        M = np.float32([[1, -kx, kx * base], [0, -ky, base * (1 + ky)]])
        sh = cv2.warpAffine(big, M, (w, h), flags=cv2.INTER_LINEAR, borderValue=0)
        hy = np.clip((base - np.arange(h, dtype=np.float32)[:, None]) / max(ih, 1), 0, 1) * np.ones((1, w), np.float32)
        hmap = cv2.warpAffine(hy * big, M, (w, h), flags=cv2.INTER_LINEAR, borderValue=0)
        hmap = np.where(sh > 0.01, hmap / np.maximum(sh, 1e-3), 0)
        soft = L.shadow_soft * ih
        s1, s2, s3 = blur(sh, soft * 0.015 + 1), blur_big(sh, soft * 0.06 + 2), blur_big(sh, soft * 0.16 + 3)
        hm = blur(hmap, soft * 0.05 + 1)
        wgt1 = 1 - smoothstep(0.0, 0.35, hm)
        wgt3 = smoothstep(0.35, 1.0, hm)
        wgt2 = np.clip(1 - wgt1 - wgt3, 0, 1)
        sh = (s1 * wgt1 + s2 * wgt2 + s3 * wgt3) * (1 - 0.55 * smoothstep(0.2, 1.0, hm))
        S_cast = np.maximum(S_cast, sh * it.shadow)
        # --- kontaktárnyék: a talp körüli sötét, lapos ellipszis ---
        bottom = a[int(ih * 0.9):]
        cols = np.where(bottom.max(0) > 0.5)[0]
        if len(cols) and it.floor_y is None:
            cw = (cols.max() - cols.min()) * 1.05
            ccx = x0 + (cols.min() + cols.max()) / 2
            ao = np.zeros((h, w), np.float32)
            cv2.ellipse(ao, (int(ccx), int(base - ih * 0.012)), (int(cw / 2), max(2, int(cw * 0.06))), 0, 0, 360, 1.0, -1,
                        cv2.LINE_AA)
            S_ao = np.maximum(S_ao, blur(ao, cw * 0.035 + 1) * it.contact)
            ao2 = np.zeros((h, w), np.float32)
            cv2.ellipse(ao2, (int(ccx), int(base)), (int(cw * 0.62), max(2, int(cw * 0.12))), 0, 0, 360, 1.0, -1, cv2.LINE_AA)
            S_ao = np.maximum(S_ao, blur(ao2, cw * 0.09 + 1) * 0.55 * it.contact)
    # --- a háttér megvilágítása az árnyékokkal ---
    amb = hex2lin(L.amb_color) * 2.2
    key = hex2lin(L.key_color) * 1.25
    scene = alb * (A[..., None] * amb * (1 - 0.35 * S_ao[..., None]) + K[..., None] * key * (1 - 0.92 * S_cast[..., None]))
    scene *= (1 - 0.55 * S_ao)[..., None]
    if getattr(sc, '_sheen', None) is not None:
        scene += (sc._sheen * (1 - S_cast) * (1 - S_ao))[..., None] * key * 0.25
    # mélységélesség a háttéren
    bg_blur = sc.backdrop.get('dof', 0.006) * h
    if bg_blur > 0.5:
        far = blur_big(scene, bg_blur)
        mid = blur(scene, bg_blur * 0.35)
        dd = depth[..., None]
        scene = scene * np.clip(1 - dd * 2, 0, 1) + mid * np.clip(1 - np.abs(dd * 2 - 1), 0, 1) + far * np.clip(dd * 2 - 1, 0, 1)
    sc._post = []
    for it, pr, rgb, a, x0, y0 in placed:
        ih, iw = a.shape
        xa, ya, xb, yb = max(0, x0), max(0, y0), min(w, x0 + iw), min(h, y0 + ih)
        if xb <= xa or yb <= ya:
            continue
        Kl = K[ya:yb, xa:xb]
        Al = A[ya:yb, xa:xb]
        Kf = np.zeros((ih, iw), np.float32)
        Af = np.zeros((ih, iw), np.float32)
        Kf[ya - y0:yb - y0, xa - x0:xb - x0] = Kl
        Af[ya - y0:yb - y0, xa - x0:xb - x0] = Al
        Kf = blur(Kf, 2)
        # a már lerakott árnyékok a tárgyon (pl. elöl álló tárgy árnyéka a hátsóra)
        Sl = np.zeros((ih, iw), np.float32)
        Sl[ya - y0:yb - y0, xa - x0:xb - x0] = S_cast[ya:yb, xa:xb]
        if it.selflit:
            # égő gyertya: a viasz a lángtól felülről kap meleg fényt, lefelé halványul
            vv = np.linspace(0, 1, ih, dtype=np.float32)[:, None]
            glow = 0.55 + 0.9 * np.exp(-((vv - 0.1) / 0.45) ** 2)
            lit = rgb * it.exposure * glow[..., None] * hex2lin('#FFC27A')[None, None] * 1.1
        else:
            lit = relight(rgb, a, L, Kf * (1 - 0.5 * Sl), Af, it.exposure * sc.grade.get('item_exposure', 1.0),
                          spec_k=it.spec)
        # tükröződés fényes asztalon
        if it.floor_y is not None and it.contact > 0:
            # a hordozó tárgyon (párna) ülő tárgy kontaktárnyéka közvetlenül a már lerakott képre
            bottom = a[int(ih * 0.88):]
            cols = np.where(bottom.max(0) > 0.5)[0]
            if len(cols):
                cw = (cols.max() - cols.min()) * 1.1
                ccx = x0 + (cols.min() + cols.max()) / 2
                ao = np.zeros((h, w), np.float32)
                cv2.ellipse(ao, (int(ccx), int(y0 + ih * 0.975)), (int(cw * 0.55), max(2, int(cw * 0.13))), 0, 0, 360, 1.0,
                            -1, cv2.LINE_AA)
                ao = blur(ao, cw * 0.05 + 1)
                scene *= (1 - 0.85 * it.contact * ao)[..., None]
        if it.reflect > 0 and it.floor_y is None:
            rh = min(ih, h - (y0 + ih))
            if rh > 4:
                rr = lit[::-1][:rh]
                ra = a[::-1][:rh] * np.linspace(1, 0, rh, dtype=np.float32)[:, None] ** 1.6 * it.reflect
                rr = blur(rr, ih * 0.01 + 1)
                ra = blur(ra, ih * 0.01 + 1)
                ys0 = y0 + ih
                xa2, xb2 = max(0, x0), min(w, x0 + iw)
                sub = scene[ys0:ys0 + rh, xa2:xb2]
                rA = ra[:, xa2 - x0:xb2 - x0, None]
                scene[ys0:ys0 + rh, xa2:xb2] = sub * (1 - rA) + rr[:, xa2 - x0:xb2 - x0] * rA
        # mélységélesség a tárgyon
        sig = max(0.0, it.depth) * h * 0.01
        if sig > 0.4:
            pad = int(sig * 3)
            lit = cv2.copyMakeBorder(lit * a[..., None], pad, pad, pad, pad, cv2.BORDER_CONSTANT, value=0)
            aa = cv2.copyMakeBorder(a, pad, pad, pad, pad, cv2.BORDER_CONSTANT, value=0)
            lit, aa = blur(lit, sig), blur(aa, sig)
            lit = lit / np.maximum(aa[..., None], 1e-4)
            x0b, y0b = x0 - pad, y0 - pad
        else:
            aa = a
            x0b, y0b = x0, y0
        ih2, iw2 = aa.shape
        xa, ya, xb, yb = max(0, x0b), max(0, y0b), min(w, x0b + iw2), min(h, y0b + ih2)
        sub = scene[ya:yb, xa:xb]
        A2 = aa[ya - y0b:yb - y0b, xa - x0b:xb - x0b, None]
        scene[ya:yb, xa:xb] = sub * (1 - A2) + lit[ya - y0b:yb - y0b, xa - x0b:xb - x0b] * A2
        for (u, v, length, seed) in it.smoke:
            if u is None:   # a legfelső átlátszatlan pont (pl. a füstölőpálca csúcsa)
                ys_, xs_ = np.where(a > 0.5)
                i_ = int(np.argmin(ys_))
                u, v = xs_[i_] / iw, ys_[i_] / ih
            sc._post.append(('smoke', x0 + u * iw, y0 + v * ih, length * h, seed))
        for (u, v, size) in it.flames:
            sc._post.append(('flame', x0 + u * iw, y0 + v * ih, size * h, max(0.0, it.depth) * h * 0.01))
    return scene


# ---------------------------------------------------------------------------
# Effektek: füst, láng, fényudvar, bokeh
# ---------------------------------------------------------------------------
def smoke_layer(w, h, x, y, length, seed, drift=-0.15):
    """Füstcsík: néhány vékony szál, amely felfelé szálló, egyre kanyargósabb pályán szétterül.
    A kimenet 0..1 sűrűség (a hívó színezi és adja hozzá)."""
    rng = np.random.default_rng(seed)
    f = 2
    sw, sh = w // f, h // f
    canvas = np.zeros((sh, sw), np.float32)
    x0, y0, Lp = x / f, y / f, length / f
    for strand in range(5):
        px, py = x0, y0
        vx, vy = 0.0, -1.0
        phase = rng.uniform(0, 6.28)
        amp = rng.uniform(0.25, 0.6)
        pts = []
        steps = int(Lp)
        for s in range(steps):
            t = s / steps
            wob = (math.sin(phase + t * 11.0) * amp * t ** 1.2
                   + math.sin(phase * 1.7 + t * 23.0) * amp * 0.6 * t ** 2.0)
            vx = 0.85 * vx + 0.15 * (wob + drift * t + rng.normal(0, 0.05) * t)
            px += vx
            py += vy * (1 - 0.25 * t)
            pts.append((px, py, t))
        for i in range(1, len(pts)):
            (xa, ya, ta), (xb, yb, tb) = pts[i - 1], pts[i]
            width = 0.6 + 6.0 * ta ** 1.6
            alpha = (1 - ta) ** 1.3 * (0.55 if strand else 0.9) * smoothstep(0.0, 0.04, ta)
            cv2.line(canvas, (int(xa * 4), int(ya * 4)), (int(xb * 4), int(yb * 4)), float(alpha),
                     max(1, int(width)), cv2.LINE_AA, shift=2)
    # örvénylés: zajjal eltolt mintavételezés
    rngn = np.random.default_rng(seed + 1)
    nx = (fbm(sh, sw, Lp * 0.12, rngn, 4) - 0.5) * Lp * 0.30
    ny = (fbm(sh, sw, Lp * 0.12, rngn, 4) - 0.5) * Lp * 0.16
    yy, xx = np.mgrid[0:sh, 0:sw].astype(np.float32)
    hfac = np.clip((y0 - yy) / max(Lp, 1), 0, 1) ** 1.2
    canvas = cv2.remap(canvas, (xx + nx * hfac).astype(np.float32), (yy + ny * hfac).astype(np.float32), cv2.INTER_LINEAR)
    canvas = 0.55 * blur(canvas, 0.8) + 0.45 * blur(canvas, 4 + Lp * 0.01)
    return cv2.resize(canvas, (w, h), interpolation=cv2.INTER_LINEAR)


def flame_layer(w, h, x, y, size):
    """Gyertyaláng: csepp alakú, fehér-sárga mag, narancs szél, kékes tő; plusz fényudvar.
    Visszaad: (láng színe RGB, láng alfa, fényudvar RGB) – lineáris, additív."""
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    fh = size
    fw = size * 0.32
    v = (y - yy) / fh                     # 0 a tövénél, 1 a csúcson
    u = (xx - x) / fw
    prof = np.where(v < 0.25, np.sqrt(np.clip(v / 0.25, 0, 1)), np.clip((1 - v) / 0.75, 0, 1) ** 0.8)
    inside = np.clip(1 - np.abs(u) / np.maximum(prof, 1e-3), 0, 1) * (v > 0) * (v < 1)
    core = inside ** 0.6
    alpha = blur(core, max(0.6, size * 0.04))
    col = np.dstack([np.ones_like(core), 0.62 + 0.32 * inside, 0.22 + 0.55 * inside ** 2]) * 3.2
    col[..., 2] += 0.6 * np.exp(-((v - 0.04) / 0.06) ** 2) * (np.abs(u) < 1)
    d = np.sqrt(((xx - x) / (size * 1.5)) ** 2 + ((yy - (y - size * 0.45)) / (size * 1.5)) ** 2)
    halo = np.exp(-d ** 2) * 0.45 + np.exp(-(d / 3.0) ** 2) * 0.06
    halo_rgb = np.dstack([halo, halo * 0.62, halo * 0.30])
    return col.astype(np.float32), alpha.astype(np.float32), halo_rgb.astype(np.float32)


def bokeh_layer(w, h, spots, rng):
    """Életlen háttérfények (meleg, kerek foltok)."""
    acc = np.zeros((h, w, 3), np.float32)
    for (x, y, r, inten, colhex) in spots:
        m = np.zeros((h, w), np.float32)
        cv2.circle(m, (int(x * w), int(y * h)), int(r * h), 1.0, -1, cv2.LINE_AA)
        m = blur(m, r * h * 0.18)
        ring = np.clip(m - blur(m, r * h * 0.25) * 0.9, 0, 1)
        acc += (m * 0.85 + ring * 0.6)[..., None] * hex2lin(colhex) * inten
    return acc


def apply_effects(scene, sc: Scene, rng):
    w, h = sc.w, sc.h
    L = sc.light
    for e in getattr(sc, '_post', []):
        if e[0] == 'smoke':
            _, x, y, length, seed = e
            dens = smoke_layer(w, h, x, y, length, seed, sc.backdrop.get('smoke_drift', -0.12))
            sm = sc.backdrop.get('smoke', {})
            # a füst a fényt szórja: a kulcsfény színében világít, a háttér előtt halványan
            lightcol = hex2lin(sm.get('color', '#E9DCCB')) * sm.get('gain', 0.6)
            op = sm.get('opacity', 0.55)
            scene = scene * (1 - op * 0.35 * dens[..., None]) + lightcol * op * dens[..., None]
        elif e[0] == 'flame':
            _, x, y, size, sig = e
            col, a, halo = flame_layer(w, h, x, y, size)
            if sig > 0.4:   # a háttérben álló gyertya lángja is életlen
                col, a = blur(col * a[..., None], sig), blur(a, sig)
                col = col / np.maximum(a[..., None], 1e-4)
            scene = scene + halo * sc.backdrop.get('halo', 0.35)
            scene = scene * (1 - a[..., None]) + col * a[..., None]
    for fx in sc.effects:
        if fx['kind'] == 'bokeh':
            scene = scene + bokeh_layer(w, h, fx['spots'], rng)
        elif fx['kind'] == 'glow':
            yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
            d = np.sqrt(((xx / w - fx['x']) * w / h) ** 2 + (yy / h - fx['y']) ** 2) / fx['r']
            scene = scene + np.exp(-d ** 2)[..., None] * hex2lin(fx.get('color', '#FFB869')) * fx.get('k', 0.2)
    return scene


# ---------------------------------------------------------------------------
# Színkezelés
# ---------------------------------------------------------------------------
def grade(scene, sc: Scene, rng):
    """Őszi színkezelés lineárisból sRGB-be: expozíció, meleg fehéregyensúly, lágy tónusgörbe (vállal),
    osztott tónus (meleg csúcsfény, szilva-olív árnyék), telítettség, vignetta, filmszemcse."""
    g = sc.grade
    h, w = scene.shape[:2]
    x = scene * g.get('exposure', 1.0)
    wb = np.array(g.get('wb', (1.04, 1.0, 0.90)), np.float32)
    x = x * wb
    # filmes váll (Reinhard-szerű, fehérpont)
    wp = g.get('white', 2.2)
    x = x * (1 + x / (wp * wp)) / (1 + x)
    s = lin2srgb(x)
    # tónusgörbe (S) és fekete emelés
    c = g.get('contrast', 0.12)
    s = s + c * (s - 0.5) * (1 - np.abs(2 * s - 1))
    lift = g.get('lift', 0.015)
    s = lift + (1 - lift) * s
    # osztott tónus a luminancia szerint
    lum = (0.2126 * s[..., 0] + 0.7152 * s[..., 1] + 0.0722 * s[..., 2])[..., None]
    hi = np.array(g.get('hi', (1.03, 1.0, 0.93)), np.float32)
    lo = np.array(g.get('lo', (1.0, 0.97, 1.03)), np.float32)
    s = s * (lo + (hi - lo) * smoothstep(0.15, 0.85, lum))
    sat = g.get('sat', 1.0)
    lum = (0.2126 * s[..., 0] + 0.7152 * s[..., 1] + 0.0722 * s[..., 2])[..., None]
    s = lum + (s - lum) * sat
    # vignetta
    yy, xx = np.mgrid[0:h, 0:w].astype(np.float32)
    vc = g.get('vig_center', (0.6, 0.45))
    d = np.sqrt(((xx / w - vc[0]) * (w / h) / g.get('vig_aspect', 1.6)) ** 2 + (yy / h - vc[1]) ** 2)
    vig = 1 - g.get('vignette', 0.25) * smoothstep(0.35, 1.2, d)
    s = s * vig[..., None]
    # sötétebb zóna a szöveg alatt (opcionális)
    for z in g.get('calm', []):
        x0, y0, x1, y1, k = z
        m = np.zeros((h, w), np.float32)
        m[int(y0 * h):int(y1 * h), int(x0 * w):int(x1 * w)] = 1
        m = blur_big(m, h * 0.12)
        s = s * (1 - k * m)[..., None]
    # filmszemcse (luminancia, a középtónusokban a legerősebb)
    gr = g.get('grain', 0.012)
    if gr > 0:
        n = rng.normal(0, 1, (h, w)).astype(np.float32)
        n = 0.7 * n + 0.3 * blur(n, 0.9)
        s = s + (gr * n * (0.4 + 0.6 * (1 - np.abs(2 * lum[..., 0] - 1))))[..., None]
    return np.clip(s, 0, 1)


def render(sc: Scene, ctx: Ctx):
    rng = np.random.default_rng(sc.seed)
    alb, K, A, depth, floor = build_backdrop(sc, ctx, rng)
    scene = composite_items(sc, ctx, alb, K, A, depth, floor, rng)
    scene = apply_effects(scene, sc, rng)
    return grade(scene, sc, rng)


# ---------------------------------------------------------------------------
# Kimenet
# ---------------------------------------------------------------------------
def save_webp(img: np.ndarray, path: Path, size, q=80, max_kb=None):
    """Átméretezés (terület-átlagolással) és WebP mentés; ha túl nagy, a minőséget lépésenként csökkenti."""
    w, h = size
    src = (img * 255 + 0.5).astype(np.uint8)
    sh, sw = src.shape[:2]
    # középre vágás a cél arányra
    tr = w / h
    if abs(sw / sh - tr) > 0.002:
        if sw / sh > tr:
            nw = int(round(sh * tr))
            x0 = (sw - nw) // 2
            src = src[:, x0:x0 + nw]
        else:
            nh = int(round(sw / tr))
            y0 = (sh - nh) // 2
            src = src[y0:y0 + nh]
    pil = Image.fromarray(src).resize((w, h), Image.LANCZOS)
    path.parent.mkdir(parents=True, exist_ok=True)
    qq = q
    while True:
        pil.save(path, 'WEBP', quality=qq, method=6)
        kb = path.stat().st_size / 1024
        if not max_kb or kb <= max_kb or qq <= 60:
            return kb, qq
        qq -= 4


# A slotok: fájlnév → szélességek és magasságok (a téma srcset-je a szélességet a névből veszi)
SLOTS = {
    'hero': {'file': 'hangtalak-gyertyafeny', 'sizes': [(800, 396), (1600, 792), (2400, 1188)], 'master': (3200, 1584)},
    'fustolok': {'file': 'fustolok-csakra', 'sizes': [(800, 588), (1600, 1176)], 'master': (2400, 1764)},
    'lakberendezes': {'file': 'lakberendezes-belso', 'sizes': [(600, 438), (1200, 876)], 'master': (2400, 1752)},
    'ruhazat': {'file': 'ruhazat-to', 'sizes': [(800, 584), (885, 646)], 'master': (2400, 1752)},
    'ajandek': {'file': 'szerencsemacska', 'sizes': [(800, 340), (1100, 468)], 'master': (2800, 1192)},
    'kalauz': {'file': 'hangtalak-studio', 'sizes': [(800, 600), (1600, 1200)], 'master': (2400, 1800)},
}


def export(img, slot, set_name, out: Path):
    info = SLOTS[slot]
    res = []
    for (w, h) in info['sizes']:
        p = out / set_name / f"{info['file']}-{w}.webp"
        kb, q = save_webp(img, p, (w, h), q=80, max_kb=250 if w >= 1600 else 160)
        res.append((p.name, w, h, round(kb, 1), q))
    return res


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--src', required=True)
    ap.add_argument('--work', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--sets', default='')
    ap.add_argument('--slots', default='')
    ap.add_argument('--cut-only', action='store_true')
    ap.add_argument('--proof', action='store_true', help='JPEG előnézet is az _proof mappába')
    ap.add_argument('--scale', type=float, default=1.0, help='gyors próbához: a mesterméret szorzója (pl. 0.5)')
    args = ap.parse_args(argv)
    ctx = Ctx(args.src, args.work)
    if args.cut_only:
        for k in CUT_KEYS:
            if k in ctx.manifest:
                print('kivágás', k, cut_product(ctx.src, ctx.manifest[k], ctx.work).name, flush=True)
        return
    from importlib import util
    sys.modules.setdefault('season_compose', sys.modules[__name__])
    spec = util.spec_from_file_location('season_scenes', Path(__file__).with_name('season-scenes.py'))
    scenes = util.module_from_spec(spec)
    spec.loader.exec_module(scenes)
    sets = [s for s in (args.sets.split(',') if args.sets else scenes.SETS)]
    slots = [s for s in (args.slots.split(',') if args.slots else SLOTS)]
    out = Path(args.out)
    report = {}
    for set_name in sets:
        for slot in slots:
            mw, mh = SLOTS[slot]['master']
            mw, mh = int(mw * args.scale), int(mh * args.scale)
            sc = scenes.build(slot, set_name, mw, mh)
            if sc is None:
                continue
            img = render(sc, ctx)
            res = export(img, slot, set_name, out) if args.scale == 1.0 else []
            if args.proof or args.scale != 1.0:
                pp = out / '_proof' / f'{set_name}-{slot}.jpg'
                pp.parent.mkdir(parents=True, exist_ok=True)
                Image.fromarray((img * 255 + 0.5).astype(np.uint8)).resize((1400, int(1400 * mh / mw)), Image.LANCZOS).save(pp, quality=88)
            used = [{'key': it.key, 'name': product(it.key, ctx).name, 'url': product(it.key, ctx).url} for it in sc.items]
            report.setdefault(set_name, {})[slot] = {'files': res, 'products': used}
            print(set_name, slot, res, flush=True)
    if args.scale == 1.0:
        (out / 'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=1))


if __name__ == '__main__':
    main()
