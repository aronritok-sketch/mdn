# Mandala nagyker 2026 – nyomtatható anyagok: plakát (A4), polccímkék (4 × A6), pötty-ív (A4), pultkártya (A5), partnerlap (2 × A4).
# Használat: python3 prints.py && node render.cjs jobs-print.json
import json, os
from texts import (DOTS, NODOT, RULES, POSTER, SHELF, COUNTER, PHONE, EMAIL, ADDRESS, HOURS, REG_URL, PERIOD, LINE, ONLY,
                   MARGIN, STOCK, STOCK_TOTAL, STOCK_DATE, WHO, STEPS, NBSP)

B = os.path.dirname(os.path.abspath(__file__))
FLAGS = ['#2D5A9B', '#ECE7DC', '#B3322B', '#2F7A4D', '#E3B23C']  # az imazászló öt színe: kék, fehér, piros, zöld, sárga

BASE = r"""
@import url('fonts/fonts.css');
@font-face{font-family:'InterV';font-weight:100 900;src:url(fonts/inter-normal-latin.woff2) format('woff2');unicode-range:U+0000-00FF,U+2000-206F,U+2212}
@font-face{font-family:'InterV';font-weight:100 900;src:url(fonts/inter-normal-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA,U+1E00-1E9F}
:root{--night:#16120F;--paper:#F7F4EE;--sand:#EEE7DB;--line:#DDD3C3;--ink:#1C1916;--muted:#6E6357;--saffron:#A9581A;--saffron-l:#E0A36A;--maroon:#6B2A2A}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--paper)}
body{color:var(--ink);font:400 10pt/1.45 'Inter',sans-serif;-webkit-font-smoothing:antialiased;font-feature-settings:'kern' 1}
.pg{position:relative;overflow:hidden;page-break-after:always;background:var(--paper)}
.kick{font:600 8pt/1.2 'Inter';letter-spacing:.18em;text-transform:uppercase;color:var(--saffron)}
h1,h2,h3{font-family:'Cormorant Garamond',serif;font-weight:500;letter-spacing:-.012em;text-wrap:balance}
h1 em,h2 em,h3 em{font-style:italic;color:var(--maroon)}
p{text-wrap:pretty}
.num{font-variant-numeric:tabular-nums lining-nums}
.flags{position:absolute;left:0;right:0;top:0;height:var(--fh,4mm);display:flex}
.flags i{flex:1}
/* pötty (nyomtatásban) */
.pd{position:relative;flex:none;width:var(--sz);height:var(--sz);border-radius:50%;background:var(--c);color:var(--k);display:flex;flex-direction:column;align-items:center;justify-content:center;
 box-shadow:0 .5mm 1.2mm rgba(0,0,0,.22)}
.pd b{font:700 calc(var(--sz)*.40)/1 'InterV','Inter';letter-spacing:-.05em;font-variant-numeric:lining-nums;display:flex;align-items:flex-start}
.pd b span{font-size:.5em;margin-top:.12em;margin-left:.03em;letter-spacing:0}
.pd small{margin-top:calc(var(--sz)*.035);font:600 calc(var(--sz)*.085)/1.15 'Inter';letter-spacing:.07em;text-transform:uppercase;text-align:center}
.pd.flat{box-shadow:none}
.legend{display:flex;gap:9mm;align-items:flex-end}
.legend .it{display:flex;flex-direction:column;align-items:center;text-align:center;gap:3mm}
.legend .it > div > strong{display:block;font:600 10pt/1.2 'Inter'}
.legend .it > div > span{display:block;font-size:9pt;color:var(--muted)}
.rules{display:grid;grid-template-columns:1fr 1fr;gap:4mm 8mm}
.rules div{border-top:1px solid var(--line);padding-top:2.2mm}
.rules b{display:block;font:600 7.4pt/1.3 'Inter';letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:.8mm}
.rules p{font-size:9.4pt;line-height:1.42}
"""


def flags(h='4mm'):
    return f'<div class="flags" style="--fh:{h}">' + ''.join(f'<i style="background:{c}"></i>' for c in FLAGS) + '</div>'


def dot(d, size, sub=True, flat=False):
    lab = '<small>nagyker<br>árból</small>' if sub else ''
    return f'<div class="pd{" flat" if flat else ""}" style="--sz:{size};--c:{d["color"]};--k:{d["ink"]}"><b>−{d["pct"]}<span>%</span></b>{lab}</div>'


def doc(css, body, title):
    return f'<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>{title}</title><style>{BASE}{css}</style></head><body>{body}</body></html>'


def rules_html(rules=RULES):
    return '<div class="rules">' + ''.join(f'<div><b>{k}</b><p>{v}</p></div>' for k, v in rules) + '</div>'


jobs = []

# ── 1. plakát, A4 ──────────────────────────────────────────────────────────────
css = r"""@page{size:A4;margin:0}
.pg{width:210mm;height:297mm;padding:20mm 17mm 0}
.top{display:flex;justify-content:space-between;align-items:center}
.top img{height:9mm}
h1{margin:8mm 0 3mm;font-size:70pt;line-height:.9}
.lead{font:400 14pt/1.42 'Inter';max-width:150mm;color:#2d2722}
.legend{margin:8mm 0 4mm;justify-content:space-between;padding:0 4mm}
.pg > .rules{grid-template-columns:repeat(3,1fr);gap:4mm 6mm}
.rules p{font-size:8.8pt;line-height:1.38}
.nodot{font-size:9.2pt;color:var(--muted);text-align:center;margin-bottom:6mm}
.band{position:absolute;left:0;right:0;bottom:0;height:52mm;background:var(--night);color:var(--paper);padding:9mm 17mm;display:grid;grid-template-columns:32mm 1fr 62mm;gap:7mm;align-items:center}
.band .qr{width:32mm;height:32mm;background:#fff;padding:2mm;border-radius:1mm}
.band .qr img{width:100%;height:100%;display:block}
.band b{display:block;font:500 17pt/1.15 'Cormorant Garamond',serif;margin-bottom:1.5mm}
.band span{font-size:9pt;color:rgba(247,244,238,.8)}
.band .ph{text-align:right}
.band .ph strong{display:block;font:600 15pt/1.2 'Inter';font-variant-numeric:tabular-nums}
.band .ph span{display:block;margin-top:1.5mm}
.cons{position:absolute;left:17mm;right:17mm;bottom:56mm;font-size:8pt;color:var(--muted);text-align:center}
"""
leg = ''.join(f'<div class="it">{dot(d, s)}<div><strong>{d["name"]}</strong><span>{d["text"]}</span></div></div>' for d, s in zip(DOTS, ['36mm', '41mm', '46mm']))
body = f"""<section class="pg">{flags('5mm')}
<div class="top"><img src="logo-dark.svg" alt="Mandala"><div class="kick">{POSTER['kick']}</div></div>
<h1>Nyitott<br><em>raktár.</em></h1>
<p class="lead">{POSTER['lead']}</p>
<div class="legend">{leg}</div>
<p class="nodot">{NODOT}<br>A pöttyös kedvezmény viszonteladóknak szól – vásárlóként a bolti áron vásárolhatsz.</p>
{rules_html()}
<div class="band"><div class="qr"><img src="qr-reg.svg" alt=""></div>
<div><b>Még nem vagy a partnerünk?</b><span>Olvasd be, és jelentkezz viszonteladóként – adószámmal a pultnál is segítünk, és már ma megkaphatod.</span></div>
<div class="ph"><strong>{PHONE.replace(' ', NBSP)}</strong><span>{ADDRESS}<br>{HOURS}</span></div></div>
</section>"""
open(os.path.join(B, 'p-plakat.html'), 'w').write(doc(css, body, 'Nyitott raktár – plakát'))
jobs += [{'html': 'p-plakat.html', 'pdf': '../out/nyomtatni/plakat-A4-nyitott-raktar.pdf', 'w': 794, 'h': 1123},
         {'html': 'p-plakat.html', 'png': '../out/kepek/plakat-A4-nyitott-raktar.jpg', 'w': 794, 'h': 1123, 'scale': 2}]

# ── 2. polccímkék, 4 × A6 egy A4-en (vágójellel) ───────────────────────────────
css = r"""@page{size:A4;margin:0}
.pg{width:210mm;height:297mm;display:grid;grid-template-columns:105mm 105mm;grid-template-rows:148.5mm 148.5mm}
.c{position:relative;padding:12mm 10mm 9mm;display:flex;flex-direction:column;align-items:center;text-align:center;outline:.2mm dashed #b9ad9b;outline-offset:-.1mm;overflow:hidden}
.c .flags{height:3mm}
.c .pd{margin-top:6mm}
.c p{margin-top:8mm;font:500 17pt/1.18 'Cormorant Garamond',serif;max-width:80mm}
.c .ft{position:absolute;left:10mm;right:10mm;bottom:8mm;font:600 6.6pt/1.3 'Inter';letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}
.c h3{margin-top:8mm;font-size:34pt}
.c ul{list-style:none;margin-top:6mm;width:100%;text-align:left}
.c li{border-bottom:.3mm solid var(--line);padding:5.4mm 0 1.2mm;font:600 7.4pt/1 'Inter';letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
"""
cards = []
for key, txt in SHELF:
    d = next(x for x in DOTS if x['key'] == key)
    cards.append(f'<div class="c">{flags("3mm")}{dot(d, "56mm")}<p>{txt}</p><div class="ft">Nyitott raktár · viszonteladóknak · december 18-ig</div></div>')
cards.append('<div class="c">' + flags('3mm') + '<div class="kick" style="margin-top:8mm">Nyitott raktár</div><h3>Félretéve</h3><ul>'
             + ''.join(f'<li>{x}</li>' for x in ['Kinek (cég, név)', 'Telefon', 'Mit', 'Eddig vár (3 munkanap)', 'Ki tette félre'])
             + '</ul><div class="ft">A pötty szerinti kedvezménnyel, a pultnál</div></div>')
open(os.path.join(B, 'p-polc.html'), 'w').write(doc(css, f'<section class="pg">{"".join(cards)}</section>', 'Nyitott raktár – polccímkék'))
jobs += [{'html': 'p-polc.html', 'pdf': '../out/nyomtatni/polccimkek-4xA6.pdf', 'w': 794, 'h': 1123},
         {'html': 'p-polc.html', 'png': '../out/kepek/polccimkek-4xA6.jpg', 'w': 794, 'h': 1123, 'scale': 2}]

# ── 3. pötty-ív: 3 oldal 40 mm (24 db), 1 oldal 20 mm vegyes (96 db) ──────────
css = r"""@page{size:A4;margin:0}
.pg{width:210mm;height:297mm;display:grid;justify-content:space-evenly;align-content:space-evenly}
.big{grid-template-columns:repeat(4,40mm);grid-template-rows:repeat(6,40mm)}
.sm{grid-template-columns:repeat(8,20mm);grid-template-rows:repeat(12,20mm)}
.cap{position:absolute;left:0;right:0;bottom:3mm;text-align:center;font-size:6.5pt;color:var(--muted)}
"""
pages = []
for d in DOTS:
    pages.append('<section class="pg big">' + ''.join(dot(d, '40mm', flat=True) for _ in range(24))
                 + f'<p class="cap">{d["name"]} · 40 mm-es körcímkére (4 × 6) vagy sima papírra, kivágva</p></section>')
pages.append('<section class="pg sm">' + ''.join(dot(d, '20mm', sub=False, flat=True) for d in DOTS for _ in range(32))
             + '<p class="cap">Kis tételekre (mala, füstölő, ékszer) · 20 mm-es körcímkére (8 × 12)</p></section>')
open(os.path.join(B, 'p-potty.html'), 'w').write(doc(css, ''.join(pages), 'Nyitott raktár – pötty-ív'))
jobs += [{'html': 'p-potty.html', 'pdf': '../out/nyomtatni/potty-iv-A4.pdf', 'w': 794, 'h': 1123}]

# ── 4. pultkártya, A5 ─────────────────────────────────────────────────────────
css = r"""@page{size:A5;margin:0}
.pg{width:148mm;height:210mm;padding:13mm 12mm 10mm}
.pg h2{font-size:28pt;margin:2.5mm 0 4.5mm;line-height:1}
.row{display:flex;gap:4mm;align-items:center;margin-bottom:5mm}
ol{list-style:none;counter-reset:n}
ol li{position:relative;padding-left:7mm;margin-bottom:2.4mm;counter-increment:n;font-size:8.6pt;line-height:1.38}
ol li::before{content:counter(n);position:absolute;left:0;top:-.6mm;font:500 13pt/1 'Cormorant Garamond',serif;color:var(--saffron);font-variant-numeric:lining-nums}
.inv{margin-top:4mm;background:var(--night);color:var(--paper);border-radius:1.5mm;padding:3.5mm 4.5mm;font-size:8.4pt}
.inv b{font:700 11pt/1.2 'InterV','Inter';letter-spacing:.08em;color:var(--saffron-l)}
"""
row = ''.join(dot(d, '17mm', sub=False) for d in DOTS) + '<p style="font-size:8.4pt;color:var(--muted)">a nagyker árból, tételenként<br>pötty nélkül: nagyker ár</p>'
body = (f'<section class="pg">{flags("3mm")}<div class="kick">A pultnál · Nyitott raktár</div><h2>Így adjuk a <em>kedvezményt.</em></h2>'
        f'<div class="row">{row}</div><ol>' + ''.join(f'<li>{x}</li>' for x in COUNTER) + '</ol>'
        '<div class="inv">Számla-megjegyzés minden pöttyös vásárlásnál: <b>NYITOTT RAKTÁR</b></div></section>')
open(os.path.join(B, 'p-pult.html'), 'w').write(doc(css, body, 'Nyitott raktár – pultkártya'))
jobs += [{'html': 'p-pult.html', 'pdf': '../out/nyomtatni/pultkartya-A5.pdf', 'w': 560, 'h': 794},
         {'html': 'p-pult.html', 'png': '../out/kepek/pultkartya-A5.jpg', 'w': 560, 'h': 794, 'scale': 2}]

# ── 5. partnerlap, 2 × A4 (kiküldhető a partnereknek, a hívás utáni levélhez csatolva) ─────
css = r"""@page{size:A4;margin:0}
.pg{width:210mm;height:297mm;padding:0 17mm}
.hero{position:relative;margin:0 -17mm;height:118mm;overflow:hidden}
.hero img.bg{width:100%;height:100%;object-fit:cover;object-position:center 22%}
.hero .lg{position:absolute;left:17mm;top:12mm;height:9mm}
.hero .fl{position:absolute;inset:0 0 auto 0}
.pg h1{margin:9mm 0 3mm;font-size:58pt;line-height:.92}
.lead{font:400 13pt/1.45 'Inter';color:#2d2722;max-width:160mm}
.only{margin-top:2.5mm;font:600 10pt/1.4 'Inter';color:var(--maroon)}
.legend{margin:9mm 0 0;gap:12mm}
.steps{position:absolute;left:17mm;right:17mm;bottom:22mm;display:grid;grid-template-columns:repeat(3,1fr);gap:7mm}
.steps div{border-top:1.5px solid var(--ink);padding-top:3mm}
.steps b{display:block;font:500 15pt/1.15 'Cormorant Garamond',serif;margin-bottom:1.5mm}
.steps b i{font-style:normal;color:var(--saffron);margin-right:1.5mm;font-variant-numeric:lining-nums}
.steps p{font-size:9pt;color:#3a332c}
.foot{position:absolute;left:17mm;right:17mm;bottom:9mm;display:flex;justify-content:space-between;font:500 7.5pt/1 'Inter';letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.p2{padding-top:20mm}
.p2 h2{font-size:30pt;margin:2.5mm 0 4mm;line-height:1}
table{width:100%;border-collapse:collapse;font-size:9.4pt;font-variant-numeric:tabular-nums lining-nums}
th{text-align:left;font:600 7.2pt/1.3 'Inter';letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding:0 2mm 2mm 0;border-bottom:1.5px solid var(--ink)}
td{padding:2.3mm 2mm 2.3mm 0;border-bottom:1px solid var(--line)}
td.n,th.n{text-align:right;white-space:nowrap}
td .pd{display:inline-flex;vertical-align:middle;margin-right:2mm}
tr.best td{font-weight:600}
.small{font-size:8pt;color:var(--muted);margin-top:2mm}
.two{display:grid;grid-template-columns:1.15fr 1fr;gap:9mm;margin-top:8mm}
.stock{display:grid;grid-template-columns:1fr auto;gap:1mm 4mm;font-size:9pt}
.stock span:nth-child(even){text-align:right;font-variant-numeric:tabular-nums}
.r1 .rules{grid-template-columns:1fr;gap:2.2mm}.r1 .rules div{padding-top:1.6mm}.r1 .rules p{font-size:8.6pt;line-height:1.36}
.contact{position:absolute;left:0;right:0;bottom:0;height:50mm;background:var(--night);color:var(--paper);padding:8mm 17mm;display:grid;grid-template-columns:30mm 1fr 1fr;gap:7mm;align-items:center}
.contact .qr{width:30mm;height:30mm;background:#fff;padding:2mm;border-radius:1mm}
.contact .qr img{width:100%;height:100%;display:block}
.contact b{display:block;font:500 15pt/1.2 'Cormorant Garamond',serif;margin-bottom:1.5mm}
.contact span{font-size:8.6pt;color:rgba(247,244,238,.82);line-height:1.5}
.contact strong{display:block;font:600 14pt/1.2 'Inter';font-variant-numeric:tabular-nums;margin-bottom:1.5mm}
"""
w, r = MARGIN['w'], MARGIN['r']
fmt = lambda n: f'{n:,}'.replace(',', NBSP) + NBSP + 'Ft'
rows = [f'<tr><td>Pötty nélkül</td><td class="n">{fmt(w)}</td><td class="n">{fmt(r)}</td><td class="n">{fmt(r - w)}</td><td class="n">–</td></tr>']
for d in DOTS:
    pay = w * (100 - int(d['pct'])) // 100
    rows.append(f'<tr class="{"best" if d["pct"] == "50" else ""}"><td>{dot(d, "8mm", sub=False)}{d["name"]}</td><td class="n">{fmt(pay)}</td><td class="n">{fmt(r)}</td>'
                f'<td class="n">{fmt(r - pay)}</td><td class="n">+{fmt(w - pay)}</td></tr>')
leg = ''.join(f'<div class="it">{dot(d, s)}<div><strong>{d["name"]}</strong><span>{d["text"]}</span></div></div>' for d, s in zip(DOTS, ['30mm', '34mm', '38mm']))
steps = ''.join(f'<div><b><i>{i}</i>{t}</b><p>{x}</p></div>' for i, (t, x) in enumerate(STEPS, 1))
stock = ''.join(f'<span>{k}</span><span>{v}</span>' for k, v in STOCK)
body = f"""<section class="pg"><div class="hero"><img class="bg" src="../ai/raktar-portrait-1.jpg" alt=""><div class="fl">{flags('4mm')}</div><img class="lg" src="logo-dark.svg" alt="Mandala"></div>
<h1>Nyitott <em>raktár.</em></h1>
<p class="lead">{LINE} Hogy melyik tétel mennyivel, azt a polcon egy színes pötty mutatja. {PERIOD.replace('2026. ', '2026.' + NBSP)}</p>
<p class="only">{ONLY}</p>
<div class="legend">{leg}</div>
<div class="steps">{steps}</div>
<div class="foot"><span>Mandala nagyker · partnerajánlat</span><span>1 / 2</span></div></section>
<section class="pg p2">{flags('4mm')}
<div class="kick">Mennyit hoz egy pötty?</div><h2>Ugyanazért adod el.<br><em>A különbség nálad marad.</em></h2>
<table><thead><tr><th>Pötty</th><th class="n">Te fizetsz</th><th class="n">Nálad a polcon</th><th class="n">Neked marad</th><th class="n">Többlet</th></tr></thead><tbody>{''.join(rows)}</tbody></table>
<p class="small">Példa: egy {fmt(w)} nagyker árú tétel, amit a boltodban {fmt(r)}-ért adsz el. A te árazásod a tiéd – a pötty a beszerzési áradat csökkenti.</p>
<div class="two"><div><div class="kick">A feltételek</div><div class="r1" style="margin-top:3mm">{rules_html(RULES[:6])}</div></div>
<div><div class="kick">Kinek szól</div><p style="margin:2.5mm 0 6mm;font-size:9.4pt">{WHO}</p>
<div class="kick">Mi van raktáron</div><p style="margin:2.5mm 0 2.5mm;font-size:9.4pt">A webshopban {STOCK_DATE} {f"{STOCK_TOTAL:,}".replace(",", NBSP)} termék volt raktáron, Nepálból és Indiából:</p><div class="stock">{stock}</div></div></div>
<div class="contact"><div class="qr"><img src="qr-lap.svg" alt=""></div>
<div><b>Még nem vagy a partnerünk?</b><span>Jelentkezz: {REG_URL} – három lépés, 1–2 munkanap. Adószámmal a pultnál is elintézzük.</span></div>
<div><strong>{PHONE.replace(' ', NBSP)}</strong><span>{ADDRESS}<br>{HOURS}<br>{EMAIL}</span></div></div></section>"""
open(os.path.join(B, 'p-lap.html'), 'w').write(doc(css, body, 'Nyitott raktár – partnerajánlat'))
jobs += [{'html': 'p-lap.html', 'pdf': '../out/Mandala-partnerajanlat-nyitott-raktar.pdf', 'w': 794, 'h': 1123}]

os.makedirs(os.path.join(B, '..', 'out', 'nyomtatni'), exist_ok=True)
os.makedirs(os.path.join(B, '..', 'out', 'kepek'), exist_ok=True)
json.dump(jobs, open(os.path.join(B, 'jobs-print.json'), 'w'))
print(len(jobs), 'feladat')
