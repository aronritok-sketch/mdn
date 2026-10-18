"""
Őszi jelenetek a season-compose.py-hoz: slotonként a termékek elrendezése, készletenként (set) a háttér,
a fény és a színkezelés.

Készletek (egy készlet = egy „fotós munkája”, minden slot ugyanabban a fényben):
  borostyan – sötét diófa asztal, sötét fal, gyertyafény hátulról-jobbról, borostyán csúcsfények
  okker     – mély okker papír-ív, alacsony őszi nap jobbról, lombon átszűrt levélárnyék
  len       – világos len abrosz, meleg vakolat, ablakfény jobbról, hosszú puha árnyékok

Koordináták: a vászon arányában (0..1); y = a tárgy talpvonala. A szöveges zónák a slot-táblából jönnek
(a hero bal 60%-a 35% magasságtól lefelé, a csempék alsó 35%-a), ott a kép nyugodt és sötétebb.
"""
import importlib.util
import sys
from pathlib import Path

_spec = importlib.util.spec_from_file_location('season_compose', Path(__file__).with_name('season-compose.py'))
if 'season_compose' in sys.modules:
    sc_mod = sys.modules['season_compose']
else:
    sc_mod = importlib.util.module_from_spec(_spec)
    sys.modules['season_compose'] = sc_mod
    _spec.loader.exec_module(sc_mod)

Item, Light, Scene = sc_mod.Item, sc_mod.Light, sc_mod.Scene

SETS = ['borostyan', 'okker', 'len']


# ---------------------------------------------------------------------------
# Készlet-alapok
# ---------------------------------------------------------------------------
def look(set_name, wall_line=0.5, light_pos=(0.95, 0.40), dark_left=True):
    """A készlet közös háttere, fénye és színkezelése."""
    if set_name == 'borostyan':
        bd = dict(kind='wood', wall='#22170F', wall_var=0.16, wood='#2A1B11', wood_light='#6B4429',
                  wall_line=wall_line, spread=2.3, dof=0.014, floor_depth=0.55, junction_soft=0.012,
                  light_pos=light_pos, light_radius=0.75, amb_falloff=0.55,
                  smoke=dict(color='#F3DFC6', gain=0.6, opacity=0.6), halo=0.55)
        light = Light(dir_xy=(1.0, -0.45), elev=16, key_color='#FFB466', key=1.5, amb_color='#4B3A31', amb=0.22,
                      shadow_depth=0.5, rim=0.9)
        grade = dict(exposure=1.0, wb=(1.05, 1.0, 0.86), contrast=0.10, white=2.6, hi=(1.04, 1.0, 0.90),
                     lo=(0.98, 0.95, 1.04), sat=0.95, vignette=0.38, vig_center=(0.7, 0.45), grain=0.010)
    elif set_name == 'okker':
        bd = dict(kind='sweep', top='#7E3B1A', bottom='#A9582A', bend=wall_line + 0.05, wall_depth=0.5, dof=0.006,
                  light_pos=light_pos, light_radius=0.95, amb_falloff=0.45,
                  gobo=dict(density=0.55, leaf=0.05, branches=6, angle=-20, soft=0.010, strength=0.7),
                  smoke=dict(color='#FBE9D2', gain=0.55, opacity=0.5), halo=0.4)
        light = Light(dir_xy=(1.0, -0.2), elev=13, key_color='#FFC88C', key=1.15, amb_color='#7A4630', amb=0.20,
                      shadow_depth=0.25, rim=0.7)
        grade = dict(exposure=1.0, wb=(1.03, 1.0, 0.88), contrast=0.12, white=2.4, hi=(1.03, 1.0, 0.91),
                     lo=(0.97, 0.96, 1.03), sat=0.95, vignette=0.32, vig_center=(0.7, 0.45), grain=0.010)
    else:  # len
        bd = dict(kind='linen', wall='#CDBBA3', wall_var=0.08, cloth='#E6D9C4', wall_line=wall_line, spread=2.0,
                  dof=0.008, floor_depth=0.55, junction_soft=0.015, light_pos=light_pos, light_radius=1.0,
                  amb_falloff=0.5, window=dict(rect=(0.52, -0.2, 1.25, 0.95), skew=-0.30, soft=0.03, outside=0.35),
                  smoke=dict(color='#FFFFFF', gain=0.5, opacity=0.35), halo=0.3)
        light = Light(dir_xy=(1.0, -0.3), elev=20, key_color='#FFE0B4', key=1.0, amb_color='#8E7C6C', amb=0.30,
                      shadow_depth=0.35, rim=0.5)
        grade = dict(exposure=1.0, wb=(1.03, 1.0, 0.92), contrast=0.08, white=2.2, hi=(1.02, 1.0, 0.94),
                     lo=(0.98, 0.97, 1.02), sat=0.92, vignette=0.30, vig_center=(0.7, 0.45), grain=0.008)
    return bd, light, grade


# ---------------------------------------------------------------------------
# Slotok
# ---------------------------------------------------------------------------
def hero(set_name, w, h):
    """Hős: hangtálak meleg fényben. A fókusz a jobb oldalon (x 0.62–0.95), a bal 60% (szöveg) és a mobil
    középső oszlopa (x 0.38–0.62, ott a szöveg a kép 15–92%-át fedi) sötét, nyugodt fal és asztal.
    A jobb alsó sarokban (x 0.71–0.94, y 0.79–0.92) a „hét hangtála” kártya ül: oda nem kerül tárgy."""
    bd, light, grade = look(set_name, wall_line=0.50, light_pos=(0.93, 0.40))
    grade['calm'] = [(0.0, 0.25, 0.55, 1.0, 0.22)]
    if set_name == 'len':
        # világos készlet: a szöveg alatti bal oldal és a mobil középső oszlopa árnyékban
        # (az ablakfény csak a jobb harmadra esik)
        bd.update(amb_falloff=0.85, light_radius=0.70,
                  window=dict(rect=(0.66, -0.2, 1.3, 0.95), skew=-0.30, soft=0.03, outside=0.18))
        grade['calm'] = [(0.0, 0.0, 0.64, 1.0, 0.62)]
    elif set_name == 'okker':
        grade['calm'] = [(0.0, 0.05, 0.58, 1.0, 0.32)]
    ppc = w * 0.205 / 20.0
    if set_name == 'borostyan':
        bd['pool'] = (0.88, 0.70, 0.30, 0.9)
        bd['wall_var'] = 0.22
        bd['sheen'] = (0.86, 0.73, 0.10, 0.16, 0.6)
        items = [
            Item('5624/1', x=0.715, y=0.755, scale=1.30, spec=0.3, exposure=0.85),
            Item('7777/0', x=0, y=0, on='5624/1', seat=0.50, spec=0.9),
            Item('7818/0', x=0.875, y=0.735, spec=0.9, reflect=0.08),
            Item('1376/2', x=0.805, y=0.690, depth=0.35, spec=0.7, smoke=[(None, None, 0.62, 11)]),
            Item('1384/0', x=0.945, y=0.665, depth=0.30, exposure=0.26, selflit=True, shadow=0.4,
                 flames=[(0.23, 0.045, 0.040), (0.75, 0.27, 0.036), (0.51, 0.555, 0.040)]),
            Item('4740/0', x=0.800, y=0.768, rot=2, reflect=0.06),
        ]
        # életlen, meleg háttérfények a kép tetején (mobilon csak a felső sáv látszik tisztán)
        effects = [dict(kind='bokeh', spots=[(0.47, 0.06, 0.055, 0.018, '#FFB466'), (0.56, 0.11, 0.045, 0.014, '#FFC27A'),
                                             (0.405, 0.12, 0.040, 0.010, '#E9A15A')])]
    elif set_name == 'okker':
        items = [
            Item('5624/1', x=0.715, y=0.760, scale=1.30, spec=0.3, exposure=0.85),
            Item('3522/0', x=0, y=0, on='5624/1', seat=0.47, spec=0.9),
            Item('3519/0', x=0.880, y=0.725, depth=0.2, spec=0.8),
            Item('4740/0', x=0.835, y=0.768, rot=-3),
        ]
        effects = []
    else:
        items = [
            Item('5630/1', x=0.715, y=0.760, scale=1.30, spec=0.3, exposure=0.85),
            Item('7734/0', x=0, y=0, on='5630/1', seat=0.47, spec=0.9),
            Item('3553/0', x=0.885, y=0.725, depth=0.2, spec=0.8),
            Item('4740/0', x=0.835, y=0.768, rot=-3),
        ]
        effects = []
    return Scene(w, h, ppc, bd, light, items, grade, seed=21, horizon=-0.9, effects=effects)


def fustolok(set_name, w, h):
    """Füstölők (magas csempe, 1.36:1): réz füstölő-égető és lótusz-tartó füsttel; alsó 35% nyugodt (szöveg),
    a tárgyak a középső oszlopban (x 0.25–0.75), hogy a mobil 0.81:1 vágás is megtartsa."""
    bd, light, grade = look(set_name, wall_line=0.46, light_pos=(0.85, 0.30))
    grade['calm'] = [(0.0, 0.72, 1.0, 1.0, 0.18)]
    grade['vig_center'] = (0.5, 0.42)
    ppc = w * 0.27 / 15.0
    if set_name == 'borostyan':
        bd['hangings'] = [dict(key='6297/0', box=(0.06, 0.24, 0.94, 0.74), rect=(0.10, -0.08, 0.90, 0.40),
                               gain=0.30, folds=0.22)]
        bd['pool'] = (0.62, 0.66, 0.30, 0.6)
        bd['sheen'] = (0.62, 0.66, 0.10, 0.14, 0.5)
        items = [
            Item('3968/0', x=0.500, y=0.650, spec=0.9, smoke=[(0.50, 0.16, 0.55, 5)]),
            Item('1376/2', x=0.300, y=0.705, spec=0.7, smoke=[(None, None, 0.50, 17)]),
            Item('6644/0', x=0.690, y=0.750, spec=0.1, exposure=0.8),
            Item('1409/0', x=0.760, y=0.600, depth=0.35, spec=0.6),
        ]
    elif set_name == 'okker':
        items = [
            Item('3968/0', x=0.500, y=0.650, spec=0.9, smoke=[(0.50, 0.16, 0.55, 5)]),
            Item('1376/2', x=0.300, y=0.705, spec=0.7, smoke=[(None, None, 0.50, 17)]),
            Item('6644/0', x=0.690, y=0.750, spec=0.1, exposure=0.8),
            Item('90/1', x=0.770, y=0.600, depth=0.3, spec=0.4),
        ]
    else:
        items = [
            Item('3968/0', x=0.500, y=0.650, spec=0.9, smoke=[(0.50, 0.16, 0.55, 5)]),
            Item('1376/2', x=0.300, y=0.705, spec=0.7, smoke=[(None, None, 0.50, 17)]),
            Item('6644/0', x=0.690, y=0.750, spec=0.1, exposure=0.8),
            Item('1409/0', x=0.760, y=0.600, depth=0.3, spec=0.6),
        ]
    return Scene(w, h, ppc, bd, light, items, grade, seed=33, horizon=-1.0)


def lakberendezes(set_name, w, h):
    """Lakberendezés (fél csempe, 1.37:1): fa Buddha, Ganésha-mécsestartó két égő mécsessel, rattan nap a falon.
    Alsó 35% nyugodt (szöveg)."""
    bd, light, grade = look(set_name, wall_line=0.55, light_pos=(0.85, 0.35))
    grade['calm'] = [(0.0, 0.70, 1.0, 1.0, 0.18)]
    grade['vig_center'] = (0.5, 0.42)
    ppc = w * 0.29 / 24.0
    if set_name == 'borostyan':
        bd['pool'] = (0.66, 0.62, 0.30, 0.8)
        bd['sheen'] = (0.66, 0.68, 0.10, 0.14, 0.4)
    stand = '3018/0' if set_name == 'okker' else '3016/0'
    items = [
        Item('6642/0', x=0.360, y=0.440, wall=True, scale=0.52, depth=0.2, spec=0.1, shadow=0.9),
        Item('6621/0', x=0.360, y=0.680, spec=0.25),
        Item('2885/0', x=0.690, y=0.690, spec=0.8, flames=[(0.125, 0.555, 0.028), (0.875, 0.515, 0.028)]),
        Item(stand, x=0.905, y=0.625, depth=0.35, spec=0.2),
    ]
    return Scene(w, h, ppc, bd, light, items, grade, seed=44, horizon=-1.0)


def ruhazat(set_name, w, h):
    """Ruházat (fél csempe, 1.37:1): felülnézeti „őszi rétegek” – indiai sálak egymásra terítve és a drapírozott
    rozsdabarna pashmina. A sálfotók lapos, egyenes nézetűek, a pashmina felülről készült, így felülnézetben
    hitelesek. Valódi ember nélkül. Alsó 35% nyugodt (szöveg)."""
    bd, light, grade = look(set_name, wall_line=0.5, light_pos=(0.90, 0.10))
    surface = {'borostyan': 'wood', 'okker': 'paper', 'len': 'linen'}[set_name]
    bd.update(kind='flat', surface=surface, dof=0.0)
    if set_name == 'borostyan':
        bd.update(wood='#2C1C12', wood_light='#5E3B22')
    grade['calm'] = [(0.0, 0.74, 1.0, 1.0, 0.10)]
    grade['vig_center'] = (0.55, 0.40)
    ppc = w * 0.46 / 62.0
    base = {'borostyan': ('6997/0', '6994/0'), 'okker': ('7031/0', '6994/0'), 'len': ('7037/0', '6997/0')}[set_name]
    items = [
        Item(base[0], x=0.310, y=1.06, wall=True, rot=18, drop=0.008, spec=0.05, shadow=0.6, fill=True),
        Item(base[1], x=0.640, y=0.97, wall=True, rot=-10, drop=0.008, spec=0.05, shadow=0.7, fill=True),
        Item('6144/0', x=0.620, y=0.70, wall=True, scale=1.0, rot=-6, drop=0.012, spec=0.12, shadow=0.9, z=5),
    ]
    return Scene(w, h, ppc, bd, light, items, grade, seed=55, horizon=-1.2)


def ajandek(set_name, w, h):
    """Ajándék (széles sáv, 2.35:1): arany integető szerencsemacska, réz kancsó, háromlábú béka.
    A tárgyak a jobb 60%-ban (x 0.42–0.86), mert a csempe a kép bal 38%-át a #F4E7D6 háttérbe olvasztja,
    mobilon pedig 1.63:1-re vág középen (x 0.15–0.85)."""
    bd, light, grade = look(set_name, wall_line=0.50, light_pos=(0.70, 0.25))
    grade['vig_center'] = (0.65, 0.5)
    grade['vignette'] = grade.get('vignette', 0.3) * 0.6
    bd['amb_falloff'] = 0.25
    light.amb *= 1.6
    ppc = h * 0.60 / 12.5 * (1486 / 1828)
    items = [
        Item('6822/0', x=0.480, y=0.800, scale=0.80, depth=0.35, spec=0.9),
        Item('1465/0', x=0.635, y=0.935, spec=0.6),
        Item('2735/1', x=0.790, y=0.905, spec=0.8),
    ]
    if set_name == 'borostyan':
        bd['pool'] = (0.66, 0.78, 0.40, 0.7)
        bd['sheen'] = (0.66, 0.80, 0.18, 0.12, 0.4)
    return Scene(w, h, ppc, bd, light, items, grade, seed=66, horizon=-0.8)


def kalauz(set_name, w, h):
    """Hangtál-kalauz (4:3, keretben, nincs rajta szöveg): három különböző tál, párna, ütő –
    „minden tálnak saját hangja van”."""
    bd, light, grade = look(set_name, wall_line=0.38, light_pos=(0.90, 0.30))
    grade['vig_center'] = (0.5, 0.55)
    grade['vignette'] = grade.get('vignette', 0.3) * 0.8
    ppc = w * 0.36 / 20.0
    if set_name == 'borostyan':
        bd['pool'] = (0.60, 0.70, 0.40, 0.6)
        bd['sheen'] = (0.70, 0.66, 0.14, 0.16, 0.4)
        items = [
            Item('3519/0', x=0.240, y=0.620, depth=0.15, spec=0.8),
            Item('5624/1', x=0.530, y=0.830, scale=1.05, spec=0.3, exposure=0.85),
            Item('7734/0', x=0, y=0, on='5624/1', seat=0.48, spec=0.9),
            Item('7818/0', x=0.810, y=0.675, spec=0.9),
            Item('4740/0', x=0.790, y=0.915, rot=-6),
        ]
    elif set_name == 'okker':
        items = [
            Item('3553/0', x=0.240, y=0.620, depth=0.15, spec=0.8),
            Item('5624/1', x=0.530, y=0.830, scale=1.05, spec=0.3, exposure=0.85),
            Item('3522/0', x=0, y=0, on='5624/1', seat=0.47, spec=0.9),
            Item('7818/0', x=0.810, y=0.675, spec=0.9),
            Item('4740/0', x=0.790, y=0.915, rot=-6),
        ]
    else:
        items = [
            Item('7777/0', x=0.255, y=0.620, depth=0.15, spec=0.8),
            Item('5630/1', x=0.535, y=0.835, scale=1.05, spec=0.3, exposure=0.85),
            Item('7734/0', x=0, y=0, on='5630/1', seat=0.47, spec=0.9),
            Item('3553/0', x=0.815, y=0.675, spec=0.9),
            Item('4740/0', x=0.790, y=0.920, rot=-6),
        ]
    return Scene(w, h, ppc, bd, light, items, grade, seed=77, horizon=-1.0)


BUILDERS = {'hero': hero, 'fustolok': fustolok, 'lakberendezes': lakberendezes, 'ruhazat': ruhazat,
            'ajandek': ajandek, 'kalauz': kalauz}


def build(slot, set_name, w, h):
    f = BUILDERS.get(slot)
    return f(set_name, w, h) if f else None
