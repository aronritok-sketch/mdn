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
    """Hős: hangtálak meleg fényben, a fókusz a középtől jobbra (x 0.55–0.90), bal oldal és mobil-oszlop sötét."""
    bd, light, grade = look(set_name, wall_line=0.50, light_pos=(0.93, 0.40))
    grade['calm'] = [(0.0, 0.30, 0.52, 1.0, 0.25)]
    ppc = w * 0.165 / 20.0
    if set_name == 'borostyan':
        bd['hangings'] = [dict(key='6250/0', box=(0.24, 0.10, 0.96, 0.92), rect=(0.56, -0.10, 1.03, 0.46),
                               gain=0.16, folds=0.25)]
        bd['pool'] = (0.88, 0.74, 0.30, 0.9)
        bd['sheen'] = (0.86, 0.74, 0.10, 0.16, 0.6)
        items = [
            Item('5624/1', x=0.700, y=0.750, scale=1.32, spec=0.3, exposure=0.85),
            Item('7777/0', x=0, y=0, on='5624/1', seat=0.50, spec=0.9),
            Item('7818/0', x=0.590, y=0.775, reflect=0.10),
            Item('4740/0', x=0.560, y=0.840, rot=3, reflect=0.08),
            Item('1376/2', x=0.815, y=0.705, depth=0.35, smoke=[(None, None, 0.62, 11)]),
            Item('1384/0', x=0.905, y=0.680, depth=0.3, exposure=0.42, selflit=True, shadow=0.4, flames=[(0.23, 0.045, 0.040), (0.75, 0.27, 0.036), (0.51, 0.555, 0.040)]),
        ]
    elif set_name == 'okker':
        items = [
            Item('5618/0', x=0.705, y=0.755),
            Item('3522/0', x=0, y=0, on='5618/0', seat=0.42),
            Item('3519/0', x=0.835, y=0.700, depth=0.25),
            Item('4740/0', x=0.585, y=0.835, rot=-4),
            Item('6644/0', x=0.905, y=0.785),
        ]
    else:
        items = [
            Item('5630/1', x=0.705, y=0.755),
            Item('7734/0', x=0, y=0, on='5630/1', seat=0.40),
            Item('3553/0', x=0.850, y=0.705, depth=0.25),
            Item('4740/0', x=0.585, y=0.835, rot=-4),
            Item('6644/0', x=0.905, y=0.795),
        ]
    return Scene(w, h, ppc, bd, light, items, grade, seed=21, horizon=-0.9)


BUILDERS = {'hero': hero}


def build(slot, set_name, w, h):
    f = BUILDERS.get(slot)
    return f(set_name, w, h) if f else None
