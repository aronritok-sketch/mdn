# Mandala karácsony 2026 – lapozós (karusszel) posztok a valódi termékfotókból: 1080 × 1080 kártyák.
# Használat: python3 social.py && node render.cjs jobs-social.json   (kimenet: ../kozossegi/kepek/)
# A termékfotók: termekfotok/ (a webshop képei, 2026-11-06). Az árak a posztolás napján ellenőrzendők.
import json, os
B = os.path.dirname(os.path.abspath(__file__))
# Az ads.py stílusa és az ajándékcímke zsinórja (az ads.py-t nem futtatjuk: az importja hirdetéseket gyártana).
_ADS = open(os.path.join(B, 'ads.py')).read()
ADS_CSS = _ADS.split('CSS = r"""')[1].split('"""')[0]
TWINE = _ADS.split("TWINE = '")[1].split("'\n")[0]

SETS = {
    'illatok': dict(
        cover=('Füstölő-hét · november 22-ig', 'Melyik illat<br><em>mire jó?</em>', 'Négy kedvenc, négy pillanat – és most kettőt viszel egy áráért.', '1+1', 'minden<br>füstölőre'),
        cards=[
            ('8904234400037.webp', 'Az elcsendesedéshez', 'Nag Champa', 'Édes, púderes, meleg: a jóga- és meditációs órák klasszikusa.', 'Satya · 15 g · 630 Ft'),
            ('8904245400989-scaled-1.webp', 'A lakás frissítéséhez', 'Palo Santo', 'Fás, citrusos, tiszta – a „szent fa” illata.', 'Satya · 15 g · 630 Ft'),
            ('8904245400415-scaled-1.webp', 'Az új kezdethez', 'Fehér zsálya', 'Gyógynövényes, friss: költözés, új év, egy nehéz nap után.', 'Satya · 15 g · 630 Ft'),
            ('8904245400163.webp', 'Az esti nyugalomhoz', 'Szantálfa', 'Krémes, fás, megnyugtató – lefekvés előtt egy szál.', 'Satya · 15 g · 630 Ft'),
        ],
        end=('Egyet fizetsz,<br><em>kettőt viszel.</em>', 'Két csomagból az olcsóbbat mi álljuk – vegyes illatokkal is. Több mint 400 füstölő.', 'mandala.hu/fustolok'),
    ),
    'ajandekok': dict(
        cover=('Mikulás · rendelj december 3-án 12 óráig', '5 apró ajándék<br><em>5 000 Ft alatt</em>', 'Díszcsomagolásban, kézzel írt kártyával – ez most nem kerül semmibe.', '0 Ft', 'díszcsomagolás<br>és kártya'),
        cards=[
            ('9990000050864.webp', 'Aki most kezd jógázni', 'Rózsakvarc csakrás mala', 'Karkötő hét csakrakővel – a szív köve középen.', '4 970 Ft'),
            ('9990000050857.webp', 'Aki szereti a fa illatát', 'Szantálfa karmala', 'Illatos szantálfa gyöngyök, meditációhoz és mindennapra.', '3 830 Ft'),
            ('8902276202916.webp', 'Aki mindent megkapott már', 'Palo Santo olaj', 'Pár csepp a párologtatóba, és a szoba illata megváltozik.', '1 200 Ft'),
            ('9990000013128-1.webp', 'Aki minden este gyújt egyet', 'Fa lótusz füstölőtartó', 'Faragott lótuszvirág, a hamu szépen összegyűlik benne.', '2 160 Ft'),
            ('9990000049424.webp', 'A kassza mellé, a csizmába', 'Szerencsekarkötő', 'Piros zsinór, kínai szerencsepénz – kis jókívánság.', '3 690 Ft'),
        ],
        end=('Ajándék<br><em>a csizmába.</em>', '15 000 Ft felett egy füstölőt is teszünk a csomagba. Mikulásra december 3-án 12 óráig rendelj.', 'mandala.hu'),
    ),
}

CSS = ADS_CSS + r"""
html,body{width:1080px;height:1080px}
.c{position:relative;width:1080px;height:1080px;overflow:hidden;background:var(--paper);color:var(--ink);--u:9.4px}
.c.dark{background:var(--night);color:var(--paper)}
.ph{position:absolute;left:0;right:0;top:0;height:700px;background:var(--phbg,#fff);display:flex;align-items:center;justify-content:center}
.ph img{width:100%;height:100%;object-fit:contain}
.band{position:absolute;left:0;right:0;top:700px;bottom:0;padding:44px 66px 0}
.k{font:600 21px/1.2 'Inter';letter-spacing:.16em;text-transform:uppercase;color:var(--saffron)}
.n{margin-top:14px;font:500 66px/1 'Cormorant Garamond',serif;letter-spacing:-.01em}
.d{margin-top:16px;font:400 27px/1.38 'Inter';color:#3a332c;max-width:760px}
.pr{position:absolute;right:66px;top:52px;font:600 24px/1 'Inter';color:var(--muted);font-variant-numeric:tabular-nums}
.ix{position:absolute;right:66px;bottom:44px;font:500 18px/1 'Inter';letter-spacing:.08em;color:var(--muted)}
.lg{position:absolute;left:66px;bottom:44px;height:32px}
.cv .eyebrow{left:66px;top:84px}
.cv h1{left:66px;top:150px;width:900px;font:500 118px/.92 'Cormorant Garamond',serif;letter-spacing:-.012em}
.cv .sub{left:66px;top:440px;width:620px;font:400 30px/1.36 'Inter'}
.cv .tag{left:auto;right:96px;top:520px;width:270px;--tbs:100px;--tr:6deg}
.cv .logo{top:80px;right:66px}
.cv .url{left:66px;top:auto;bottom:70px}
"""


def edge(img):
    """A termékfotó szélének színe (a fotó mellé ez kerül, hogy ne legyen szürke sáv)."""
    from PIL import Image
    im = Image.open(os.path.join(B, 'termekfotok', img)).convert('RGB')
    w, h = im.size
    px = [im.getpixel((x, y)) for x in (2, w - 3) for y in range(0, h, max(1, h // 20))]
    r, g, b = (sorted(c[i] for c in px)[len(px) // 2] for i in range(3))
    return f'#{r:02x}{g:02x}{b:02x}'


def card(set_key, i, n, c):
    img, kick, name, desc, price = c
    return f"""<!doctype html><html lang="hu"><head><meta charset="utf-8"><style>{CSS}</style></head><body><div class="c" style="--phbg:{edge(img)}">
<div class="ph"><img src="termekfotok/{img}" alt=""></div>
<div class="band"><div class="k">{kick}</div><div class="n">{name}</div><div class="d">{desc}</div><div class="pr">{price}</div></div>
<img class="lg" src="logo-dark.svg" alt=""><div class="ix">{i} / {n}</div></div></body></html>"""


def cover(c, last=False):
    if last:
        h1, sub, url = c
        eyebrow, tb, ts = '', '', ''
    else:
        eyebrow, h1, sub, tb, ts = c
        url = 'mandala.hu'
    tag = f'<div class="tag"><div class="card"><span class="hole"></span><b>{tb}</b><small>{ts}</small></div>{TWINE}</div>' if tb else ''
    return f"""<!doctype html><html lang="hu"><head><meta charset="utf-8"><style>{CSS}</style></head><body><div class="ad cv grain" style="--w:1080px;--h:1080px;--u:9.4px;--px:66px">
<div class="eyebrow">{eyebrow}</div><h1>{h1}</h1><p class="sub">{sub}</p>{tag}
<img class="logo" src="logo-light.svg" alt="Mandala"><div class="url">{url}</div></div></body></html>"""


jobs = []
os.makedirs(os.path.join(B, '..', 'kozossegi', 'kepek'), exist_ok=True)
for key, s in SETS.items():
    pages = [cover(s['cover'])] + [card(key, i, len(s['cards']), c) for i, c in enumerate(s['cards'], 1)] + [cover(s['end'], last=True)]
    for i, html in enumerate(pages):
        name = f'soc-{key}-{i}.html'
        open(os.path.join(B, name), 'w').write(html)
        jobs.append({'html': name, 'png': f'../kozossegi/kepek/{key}-{i}.jpg', 'w': 1080, 'h': 1080})
json.dump(jobs, open(os.path.join(B, 'jobs-social.json'), 'w'))
print(len(jobs), 'kártya')
