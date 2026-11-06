# Mandala nagyker 2026 („Nyitott raktár”) – kiküldhető kampányterv (HTML → PDF). Használat: python3 strategy.py && node render.cjs jobs-pdf.json
import datetime as dt
import json, os
from texts import (NAME, LINE, ONLY, PERIOD, DOTS, NODOT, RULES, PHASES, SEGMENTS, ADS, GOOGLE_KW, GOOGLE_NEG, GOOGLE_HEAD, GOOGLE_DESC,
                   GOOGLE_URL, SOCIAL, STORY, SUBJECTS, SUBJECTS2, MAIL1, MAIL2, MAIL_CALL_OLD, MAIL_CALL_NEW, SEQUENCE, SMS, SCRIPT_OLD,
                   SCRIPT_NEW, COUNTER, TASKS, QUESTIONS, STOCK, STOCK_TOTAL, PHONE, ADDRESS, HOURS, NBSP, MARGIN)

B = os.path.dirname(os.path.abspath(__file__))
FLAGS = ['#2D5A9B', '#ECE7DC', '#B3322B', '#2F7A4D', '#E3B23C']


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def br(s):
    return esc(s).replace('\n', '<br>')


def dot(d, size, sub=True):
    lab = '<small>nagyker<br>árból</small>' if sub else ''
    return f'<span class="pd" style="--sz:{size};--c:{d["color"]};--k:{d["ink"]}"><b>−{d["pct"]}<span>%</span></b>{lab}</span>'


CSS = r"""
@import url('fonts/fonts.css');
@font-face{font-family:'InterV';font-weight:100 900;src:url(fonts/inter-normal-latin.woff2) format('woff2');unicode-range:U+0000-00FF,U+2000-206F,U+2212}
@font-face{font-family:'InterV';font-weight:100 900;src:url(fonts/inter-normal-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA,U+1E00-1E9F}
:root{--night:#16120F;--paper:#F7F4EE;--sand:#EEE7DB;--line:#DDD3C3;--ink:#1C1916;--muted:#6E6357;--saffron:#A9581A;--saffron-l:#E0A36A;--maroon:#6B2A2A;--g:#2F7A4D;--y:#E3B23C;--r:#B3322B}
@page{size:A4;margin:0}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:210mm;background:var(--paper)}
body{color:var(--ink);font:400 10.2pt/1.5 'Inter';-webkit-font-smoothing:antialiased;font-feature-settings:'kern' 1}
.page{position:relative;width:210mm;height:297mm;overflow:hidden;page-break-after:always;padding:19mm 17mm 16mm;background:var(--paper)}
.page.dark{background:var(--night);color:var(--paper)}
.flags{position:absolute;left:0;right:0;top:0;height:3mm;display:flex}.flags i{flex:1}
.pn{position:absolute;left:17mm;right:17mm;bottom:9mm;display:flex;justify-content:space-between;font:500 7.5pt/1 'Inter';letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.dark .pn{color:rgba(247,244,238,.55)}
.kick{font:600 8pt/1.2 'Inter';letter-spacing:.18em;text-transform:uppercase;color:var(--saffron)}
.dark .kick{color:var(--saffron-l)}
h2{margin:3mm 0 6mm;font:500 33pt/1 'Cormorant Garamond',serif;letter-spacing:-.012em;text-wrap:balance}
h2 em,h3 em{font-style:italic;color:var(--maroon)}
.dark h2 em{color:var(--saffron-l)}
h3{margin:0 0 2mm;font:500 16pt/1.12 'Cormorant Garamond',serif}
h4{margin:0 0 1.4mm;font:600 7.8pt/1.3 'Inter';letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
p{margin:0 0 2.6mm;text-wrap:pretty}
.lead{font:400 12.2pt/1.5 'Inter';color:#2d2722;max-width:155mm}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:7mm 9mm}
.cols3{display:grid;grid-template-columns:repeat(3,1fr);gap:6mm}
.box{background:var(--sand);border-radius:2mm;padding:5mm 5.5mm}
.box.line{background:transparent;border:1px solid var(--line)}
.box.night{background:var(--night);color:var(--paper)}
ul.clean{margin:0;padding:0;list-style:none}
ul.clean li{position:relative;padding-left:5mm;margin:0 0 1.7mm}
ul.clean li::before{content:'';position:absolute;left:0;top:2.2mm;width:2.4mm;height:1px;background:var(--saffron)}
ol.num{margin:0;padding:0;list-style:none;counter-reset:n}
ol.num li{position:relative;padding-left:9mm;margin:0 0 2.4mm;counter-increment:n}
ol.num li::before{content:counter(n);position:absolute;left:0;top:-1.2mm;font:500 16pt/1 'Cormorant Garamond',serif;color:var(--saffron);font-variant-numeric:lining-nums}
table{width:100%;border-collapse:collapse;font-size:8.9pt;font-variant-numeric:tabular-nums lining-nums}
th{text-align:left;font:600 7.2pt/1.3 'Inter';letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding:2mm 2mm 2mm 0;border-bottom:1.5px solid var(--ink)}
td{padding:2.1mm 2mm 2.1mm 0;border-bottom:1px solid var(--line);vertical-align:top}
td.n,th.n{text-align:right;white-space:nowrap}
.tight td{padding:1.4mm 2mm 1.4mm 0;font-size:8.3pt;line-height:1.38}
.big{font:500 30pt/1 'Cormorant Garamond',serif;font-variant-numeric:lining-nums;white-space:nowrap}
.small{font-size:8.2pt;color:var(--muted)}
.src{font-size:7.3pt;color:var(--muted);line-height:1.4}
.note{border-left:2px solid var(--saffron);padding:1mm 0 1mm 4mm;margin:3mm 0}
.pd{position:relative;flex:none;display:inline-flex;width:var(--sz);height:var(--sz);border-radius:50%;background:var(--c);color:var(--k);flex-direction:column;align-items:center;justify-content:center;vertical-align:middle;box-shadow:0 .4mm 1mm rgba(0,0,0,.2)}
.pd b{font:700 calc(var(--sz)*.40)/1 'InterV','Inter';letter-spacing:-.05em;font-variant-numeric:lining-nums;display:flex;align-items:flex-start}
.pd b span{font-size:.5em;margin-top:.12em;margin-left:.03em;letter-spacing:0}
.pd small{margin-top:calc(var(--sz)*.035);font:600 calc(var(--sz)*.085)/1.15 'Inter';letter-spacing:.07em;text-transform:uppercase;text-align:center}
/* borító */
.cover{padding:0}
.cover img.bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 60%}
.cover .sc{position:absolute;inset:0;background:linear-gradient(180deg,rgba(16,12,10,.9) 0%,rgba(16,12,10,.55) 40%,rgba(16,12,10,0) 62%,rgba(16,12,10,.78) 100%)}
.cover .in{position:absolute;left:17mm;right:17mm;top:22mm}
.cover .lg{height:10mm}
.cover h1{margin-top:22mm;font:500 56pt/.92 'Cormorant Garamond',serif;color:var(--paper);letter-spacing:-.015em}
.cover h1 em{font-style:italic;color:var(--saffron-l)}
.cover .sub{width:135mm;margin-top:6mm;font:400 12.5pt/1.45 'Inter';color:rgba(247,244,238,.92)}
.cover .dots{position:absolute;right:17mm;top:118mm;display:flex;gap:2mm;align-items:flex-end}
.cover .meta{position:absolute;left:17mm;right:17mm;bottom:15mm;display:flex;justify-content:space-between;align-items:flex-end;font:500 9pt/1.5 'Inter';color:rgba(247,244,238,.86)}
.cover .meta b{display:block;font:500 15pt/1.2 'Cormorant Garamond',serif;color:var(--paper)}
/* naptár */
.cal{display:grid;grid-template-columns:30mm repeat(40,1fr);font-size:6.6pt;row-gap:1.5mm;align-items:center;min-height:7.4mm}
.cal.hd{min-height:0}
.flow{display:grid;grid-template-columns:repeat(6,1fr);gap:0;margin-top:3mm}
.flow div{position:relative;background:var(--sand);padding:3mm 3.2mm 3mm 3.2mm;margin-right:4mm;border-radius:1.5mm;font-size:8pt;line-height:1.35}
.flow div b{display:block;font:600 7.2pt/1.2 'Inter';letter-spacing:.08em;text-transform:uppercase;color:var(--saffron);margin-bottom:1mm}
.flow div:not(:last-child)::after{content:'→';position:absolute;right:-3.6mm;top:50%;transform:translateY(-50%);color:var(--muted);font-size:9pt}
.flow div.end{background:var(--night);color:var(--paper)}.flow div.end b{color:var(--saffron-l)}
.cal .lab{font:600 6.9pt/1.2 'Inter';letter-spacing:.04em;text-transform:uppercase;color:var(--muted);padding-right:2mm}
.cal .day{text-align:center;color:var(--muted);font-size:5.8pt;font-variant-numeric:tabular-nums}
.cal .day.we{color:#b9ad9b}
.cal .wk{font:600 6.3pt/1 'Inter';color:var(--ink);border-left:1px solid #CFC4B2;padding-left:.8mm;white-space:nowrap}
.bar{height:6.4mm;border-radius:1.2mm;display:flex;align-items:center;padding:0 2mm;font:600 6.8pt/1.1 'Inter';color:var(--paper);white-space:nowrap;overflow:hidden}
.bar.a{background:#8C6A43}.bar.b{background:var(--night)}.bar.c{background:var(--maroon)}.bar.d{background:var(--saffron)}.bar.e{background:var(--g)}
.bar.lt{background:var(--sand);color:var(--ink)}
.mk{width:3.2mm;height:3.2mm;border-radius:50%;background:var(--saffron);justify-self:center}
.mk.r{background:var(--r)}.mk.o{background:transparent;border:1.5px solid var(--saffron)}
/* galéria */
.gal{display:grid;gap:4mm;align-items:start}
.gal img{width:100%;display:block;border-radius:1mm;box-shadow:0 .5mm 1.8mm rgba(0,0,0,.14)}
.gal .cap{margin-top:1.3mm;font-size:7pt;color:var(--muted)}
.sw{display:flex;gap:2.6mm;margin-top:3mm}
.sw div{flex:1;height:15mm;border-radius:1.2mm;position:relative}
.sw span{position:absolute;left:2mm;bottom:1.5mm;font:500 6.4pt/1.2 'Inter'}
.copy td:first-child{width:34mm;color:var(--muted);font-size:7.9pt}
.copy td{font-size:8.6pt}
.step{border-top:1px solid var(--line);padding:2.2mm 0}
.step b{display:block;font:600 7.4pt/1.3 'Inter';letter-spacing:.1em;text-transform:uppercase;color:var(--saffron);margin-bottom:.8mm}
.step p{font-size:8.6pt;line-height:1.42;margin:0}
.obj p{font-size:8.4pt;line-height:1.4;margin:0 0 2mm}
.obj b{font-weight:600}
"""

FL = '<div class="flags">' + ''.join(f'<i style="background:{c}"></i>' for c in FLAGS) + '</div>'


def page(inner, n, dark=False, cls=''):
    return f'<section class="page {"dark" if dark else ""} {cls}">{FL}{inner}<div class="pn"><span>Mandala · Nyitott raktár · nagyker kampányterv</span><span>{n}</span></div></section>'


pages = []
n = 1
# 1. Borító – a nyitott ajtó
pages.append(f'''<section class="page dark cover"><img class="bg" src="../ai/ajto-portrait-1.jpg" alt=""><div class="sc"></div>{FL}
<div class="in"><img class="lg" src="logo-light.svg" alt="Mandala"><h1>Nyitott<br><em>raktár.</em></h1>
<p class="sub">Nagyker kampányterv: régi partnerek vissza, újak be. A készlet 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból – csak személyesen, a Bakáts utcában.</p></div>
<div class="meta"><div><b>Mandala nagyker</b>Viszonteladói kampány · {PERIOD}</div><div style="text-align:right"><b>HelloProVision</b>Áron · 2026. november 6.</div></div></section>''')

# 2. Röviden
n += 1
dots_row = ''.join(f'<div style="display:flex;align-items:center;gap:3mm">{dot(d, "16mm", False)}<div><b>{d["name"]}</b><br><span class="small">{d["text"]}</span></div></div>' for d in DOTS)
pages.append(page(f'''<div class="kick">Röviden</div><h2>Kinyitjuk a raktárt, <em>és behívjuk</em> a partnereket.</h2>
<p class="lead">{LINE} {ONLY} A kampány célja kettő: a régi, elmaradt partnereket visszahozni, és új viszonteladókat szerezni – karácsony előtt, amikor a boltok töltik a polcot.</p>
<div style="display:flex;gap:9mm;margin:7mm 0 8mm">{dots_row}</div>
<div class="cols">
<div><h4>Mit csinálunk</h4><ol class="num">
<li><b>Pötty a polcon.</b> A kijelölt tételek zöld, sárga vagy piros pöttyöt kapnak; a pultnál a pötty szerint adjuk a kedvezményt.</li>
<li><b>Mindenkit felhívunk,</b> aki valaha vett tőlünk – előbb az elveszett és elmaradó partnereket. A Partner CRM hívólistája és forgatókönyve viszi.</li>
<li><b>Partnerlevél nov. 11-én, emlékeztető nov. 30-án,</b> hívás után személyes levél.</li>
<li><b>Új partnerek:</b> cégkeresés és levélsor a Megkeresésben, hideghívás, Google-keresés nagyker szavakra, posztok.</li>
<li><b>A bemutatóterem a színpad:</b> plakát, polccímke, pultkártya, félretett tételek polca.</li></ol></div>
<div><h4>Miért így</h4><ul class="clean">
<li>A személyes vásárlás <b>nagyobb kosarat</b> hoz, és újraépíti a kapcsolatot – aki bejön, az látja az új árut is.</li>
<li>A <b>webshop árai érintetlenek</b>: a kiskereskedelmi karácsonyi kampány és a nagyker árlista nem keveredik.</li>
<li>A pötty <b>egyszerű</b>: a polcon, a plakáton, a levélben és a hirdetésben ugyanaz a három szín.</li>
<li>A <b>Partner CRM</b> mindenhez kész: szakaszok, hívólista, forgatókönyv, bemutatótermi időpont a naptárba, mérés.</li></ul>
<div class="box" style="margin-top:4mm"><h4>Döntés kell nov. 9-ig</h4><p style="margin:0;font-size:9pt">A dátumok, mi kap pöttyöt, és hat apró szabály – a 18. oldalon, egyenként.</p></div></div>
</div>''', n))

# 3. Kiindulás
n += 1
stock_rows = ''.join(f'<tr><td>{k}</td><td class="n">{v}</td></tr>' for k, v in STOCK)
pages.append(page(f'''<div class="kick">Kiindulás</div><h2>Sok a partner, <em>kevés</em> vásárol.</h2>
<div class="cols">
<div><h4>Partnerek – a Partner CRM próbaimportja (2026. okt. 12.)</h4>
<table><tr><th>Szakasz</th><th class="n">Partner</th></tr>
<tr><td>Viszonteladói fiók a webshopban</td><td class="n">401</td></tr>
<tr><td>Aktív (60 napon belül rendelt)</td><td class="n">10</td></tr>
<tr><td>Elmaradó (60–180 napja nem rendelt)</td><td class="n">21</td></tr>
<tr><td>Elveszett (180+ napja nem rendelt)</td><td class="n">39</td></tr>
<tr><td>Megszűnt (Pihenő)</td><td class="n">39</td></tr></table>
<p class="small" style="margin-top:2mm">A számokat a kampány indulása előtt az élő CRM-ből frissítjük. A legnagyobb számlás vevők között sok olyan cég van, akinek nincs webshop-fiókja: őket az Import fül „Partner lesz” gombjával vesszük fel, mielőtt hívunk.</p>
<div class="note"><b>A lényeg:</b> négyszáz fiókból tíz partner vásárol rendszeresen. A legolcsóbb új forgalom a már ismert partnereknél van – őket hívjuk elsőként.</div></div>
<div><h4>Raktáron a webshop szerint (nov. 6., élő feed)</h4>
<table class="tight"><tr><th>Kategória</th><th class="n">Termék</th></tr>{stock_rows}<tr><td><b>Összesen</b></td><td class="n"><b>{STOCK_TOTAL:,}</b></td></tr></table>
<p class="small" style="margin-top:2mm">Termékféleség, nem darabszám; a JUTA-készlet a Partner CRM Készlet nézetében látszik.</p>
<h4 style="margin-top:5mm">Ár és jog</h4>
<p style="font-size:8.8pt">A viszonteladói kedvezményre nem vonatkozik a fogyasztói árcsökkentési szabály (30 napos legalacsonyabb ár) – az a vásárlóknak szóló akciókra érvényes. Ezért itt lehet százalékot írni. Két óvatosság: a nyilvános hirdetés mindig kimondja, hogy viszonteladóknak szól; és a bemutatóteremben a plakát jelzi, hogy a pötty a vásárlónak nem jár.</p></div>
</div>'''.replace(f'{STOCK_TOTAL:,}', f'{STOCK_TOTAL:,}'.replace(',', NBSP)), n))

# 4. Az ajánlat és a pötty-rendszer
n += 1
leg = ''.join(f'<div style="text-align:center">{dot(d, s)}<div style="margin-top:2.5mm"><b>{d["name"]}</b><br><span class="small">{d["text"]}</span></div></div>' for d, s in zip(DOTS, ['30mm', '34mm', '38mm']))
rules = ''.join(f'<tr><td style="width:28mm"><b>{k}</b></td><td>{esc(v)}</td></tr>' for k, v in RULES)
pages.append(page(f'''<div class="kick">Az ajánlat</div><h2>A pötty színe <em>a kedvezmény.</em></h2>
<div style="display:flex;justify-content:space-around;align-items:flex-end;margin:4mm 0 3mm">{leg}</div>
<p class="small" style="text-align:center;margin-bottom:6mm">{NODOT} A színek az imazászló színei – a Mandala saját világából.</p>
<div class="cols"><div><h4>Feltételek (javaslat – a 18. oldalon dönteni kell)</h4><table class="tight">{rules}</table></div>
<div><h4>Mi kap pöttyöt? Javaslat a Partner CRM Készlet nézetéből</h4>
<table class="tight"><tr><th>Pötty</th><th>Melyik tétel</th></tr>
<tr><td>{dot(DOTS[2], '7mm', False)}</td><td><b>„Nem fogy”</b> – 90 napja nem kelt el, vagy több van belőle, mint fél év eladása.</td></tr>
<tr><td>{dot(DOTS[1], '7mm', False)}</td><td><b>Lassan fogy</b> – a 90 napos eladás alapján fél évnél tovább tartana.</td></tr>
<tr><td>{dot(DOTS[0], '7mm', False)}</td><td><b>A többi</b>, ami nem új és nem a legkeresettebb.</td></tr>
<tr><td><span class="small">nincs</span></td><td><b>Új érkezés</b> (60 nap), a <b>„fogy”</b> lista (2 hét alatt elfogy), és ami nem érné meg.</td></tr></table>
<p class="small" style="margin-top:2mm">A cél a készlet 80%-a; ha a javaslat ennél kevesebbet ad, a zöld kört bővítjük. A pötty a terméken (címke) vagy a polcon (polccímke, ha egy polc egy színű).</p>
<div class="box" style="margin-top:3mm"><h4>A pultnál</h4><p style="margin:0;font-size:8.6pt">Tételenként a nagyker árból, a pötty szerint. A számla megjegyzésébe „NYITOTT RAKTÁR” kerül – ebből mérjük a kampányt. Részletek a pultkártyán (9. oldal).</p></div></div></div>''', n))

# 5. Kinek mit
n += 1
seg = ''.join(f'<tr><td><b>{s["name"]}</b><br><span class="small">{s["crm"]}</span></td><td class="n">{s["n"]}</td><td>{esc(s["msg"])}</td><td>{esc(s["how"])}</td></tr>' for s in SEGMENTS)
pages.append(page(f'''<div class="kick">Célcsoportok</div><h2>Kit, mivel, <em>milyen sorrendben.</em></h2>
<table><tr><th style="width:42mm">Kinek</th><th class="n">Hány</th><th>Üzenet</th><th style="width:44mm">Hogyan</th></tr>{seg}</table>
<p class="small" style="margin-top:2mm">A darabszám az okt. 12-i próbaimportból; az új partnereknél a cél, hány céget keresünk meg. A Partner CRM a vásárlás után magától Aktívra teszi a partnert – így a nov. 30-i emlékeztető már csak annak megy, aki még nem jött.</p>
<div class="cols" style="margin-top:6mm">
<div class="box"><h4>Régi partnereknek</h4><p style="font-size:9pt;margin:0">„Rég jártál nálunk.” Nem bocsánatkérés és nem könyörgés: van egy jó okunk hívni. A hívásban kérdezünk is – mi fogyott, mi hiányzik, miért maradt el –, és felírjuk a kartonra. Ebből tanulunk a januári tervhez.</p></div>
<div class="box"><h4>Új partnereknek</h4><p style="font-size:9pt;margin:0">„Van egy polcod? Nekünk raktárunk.” Az első vásárlásnál a legnagyobb a kockázat – a pötty és a személyes látogatás csökkenti: élőben látja, mit kap, és olcsóbban próbálja ki. A jelentkezés a helyszínen, adószámmal is megy.</p></div></div>
<h4 style="margin-top:8mm">Egy partner útja a Partner CRM-ben</h4>
<div class="flow"><div><b>Hívólista</b>A szakasz szerint, napi 20-as adag, a felelős nevén.</div><div><b>Hívás</b>Forgatókönyv; kérdések, félretétel a kartonra.</div>
<div><b>Eredmény</b>„Bemutatóterem” → időpont a naptárba; „Visszahívás” → a sor tetejére.</div><div><b>Levél</b>A hívás ablakából, a hívó kolléga nevében.</div>
<div><b>Látogatás</b>Pötty a polcon, számla „NYITOTT RAKTÁR” megjegyzéssel.</div><div class="end"><b>Aktív</b>A számla-importtal a partner magától Aktív lesz.</div></div>''', n))

# 6. Ütemterv (naptár: nov. 9. – dec. 18.)
n += 1
d0 = dt.date(2026, 11, 9)
days = [d0 + dt.timedelta(i) for i in range(40)]
col = lambda d: (d - d0).days + 2
MON = {11: 'nov.', 12: 'dec.'}
cal = '<div class="cal hd"><div></div>' + ''.join(
    f'<div class="wk" style="grid-column:{col(d)} / span 7">{MON[d.month]} {d.day}.</div>' for d in days if d.weekday() == 0) + '</div>'
cal += '<div class="cal hd"><div></div>' + ''.join(f'<div class="day{" we" if d.weekday() >= 5 else ""}" style="grid-column:{col(d)}">{d.day}</div>' for d in days) + '</div>'


def bar(label, a, b, cls, text=''):
    a, b = dt.date.fromisoformat(a), dt.date.fromisoformat(b)
    return f'<div class="cal"><div class="lab">{label}</div><div class="bar {cls}" style="grid-column:{col(a)} / {col(b) + 1}">{text}</div></div>'


def marks(label, dates, cls=''):
    return '<div class="cal"><div class="lab">' + label + '</div>' + ''.join(f'<div class="mk {cls}" style="grid-column:{col(dt.date.fromisoformat(x))}"></div>' for x in dates) + '</div>'


cal += bar('Előkészítés', '2026-11-09', '2026-11-10', 'lt', 'pötty')
cal += bar('1. hullám', '2026-11-11', '2026-11-27', 'b', 'Nyitott raktár')
cal += bar('2. hullám', '2026-11-30', '2026-12-18', 'c', 'Utolsó kör karácsony előtt')
cal += marks('Partnerlevél', ['2026-11-11', '2026-11-30'], 'r')
cal += bar('Hívás: régi', '2026-11-11', '2026-11-20', 'a', 'elveszettek elöl')
cal += bar('Hívás: újak', '2026-11-16', '2026-12-11', 'd', 'hideghívás, a levélsor után')
cal += bar('Levélsor: újak', '2026-11-16', '2026-12-04', 'lt', '3 levél, 0 / +4 / +7 nap')
cal += bar('Google-keresés', '2026-11-11', '2026-12-18', 'e', 'nagyker szavak, napi 3 000 Ft')
cal += marks('Posztok', ['2026-11-11', '2026-11-18', '2026-12-01'])
cal += marks('Heti riport', ['2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07', '2026-12-14'], 'o')
ph = ''.join(f'<div><h4>{p["short"]}</h4><h3>{p["name"]}</h3><p style="font-size:8.8pt">{p["what"]}</p></div>' for p in PHASES)
pages.append(page(f'''<div class="kick">Ütemterv</div><h2>November 9. – december 18.</h2>
<div style="margin:2mm 0 6mm">{cal}</div><p class="small">Szürke nap: hétvége – a bemutatóterem zárva. Nyitvatartás: {HOURS}</p>
<div class="cols3" style="margin-top:6mm">{ph}</div>
<div class="note" style="margin-top:4mm">A mostani −10%-os személyes akció (nettó 100 000 Ft felett) november 10-én zár – a Nyitott raktár másnap indul, így nem fut két szabály egyszerre. Aki nov. 9–10-én bejön, annak már elmondjuk, mi jön.</div>
<h4 style="margin-top:6mm">Heti ritmus (javaslat)</h4>
<table class="tight"><tr><th style="width:32mm">Mikor</th><th>Mit</th><th style="width:40mm">Ki</th></tr>
<tr><td>Hétfő reggel</td><td>Heti riport: hívások, látogatások, visszatért és új partnerek, „NYITOTT RAKTÁR” számlák; a lejárt félretételek vissza a polcra.</td><td>HelloProVision, bolt</td></tr>
<tr><td>Hétköznap 10–12</td><td>20 hívás a hívólistából – a boltok ilyenkor már nyitva vannak, de még nincs délutáni forgalom. Visszahívások a sor tetején.</td><td>Értékesítő</td></tr>
<tr><td>Hívás után, azonnal</td><td>Levél a hívás ablakából; időpont a naptárba; a félretett tétel a „Félretéve” polcra.</td><td>Aki hívott</td></tr>
<tr><td>Nyitvatartásban</td><td>Látogatók a bemutatóteremben: pötty szerinti kedvezmény, új partner jelentkezése a helyszínen, látogatás a kartonra.</td><td>Bolt</td></tr>
<tr><td>Péntek</td><td>A hét új jelentkezőinek jóváhagyása; a jövő heti hívólista ellenőrzése.</td><td>Pénzügy / admin</td></tr></table>''', n))

# 7. Csatornák 1 – telefon és levél
n += 1
pages.append(page(f'''<div class="kick">Csatornák · 1</div><h2>Telefon és <em>partnerlevél.</em></h2>
<div class="cols">
<div><h3>Telefon – a fő csatorna</h3><ul class="clean">
<li><b>Hívólisták a Partner CRM-ben</b> (Új partnerek → Hideghívás → Hívólisták, szűrőből): Elveszett, Elmaradó, Érdeklődik, Aktív – napi 20-as adagokra osztva.</li>
<li><b>Két forgatókönyv</b> (14–15. oldal): régi és új partnernek, kifogásokkal; a hívó mód egyesével adja a partnert.</li>
<li><b>Eredmény egy koppintással.</b> „Bemutatóterem” → időpont a naptárba; „Visszahívás” → a sor tetejére; 3 sikertelen → Pihenő.</li>
<li><b>Hívás után levél</b> ugyanabból az ablakból, a hívó kolléga nevében (szövegek: 16. oldal).</li>
<li>Félretétel: a kartonra jegyzet, a polcra „Félretéve” kártya.</li></ul></div>
<div><h3>Partnerlevél – nov. 11. és nov. 30.</h3><ul class="clean">
<li><b>MailerLite, „Viszonteladók” csoport</b> – kész HTML: <code>levelek/partnerlevel-nov11.html</code>, fejléckép, sötét mód, szöveges változat.</li>
<li><b>Vagy a Partner CRM Partnerlevelek</b> (Hírlevél fajta, blokkokkal) – adatcsomag a <code>szovegek.md</code>-ben. Ugyanannak a partnernek csak az egyik menjen.</li>
<li><b>Jogalap:</b> a CRM csak hozzájárulással vagy jóváhagyott céges címmel küld; a MailerLite csoportban a feliratkozott viszonteladók vannak.</li>
<li><b>Tárgy:</b> „{esc(SUBJECTS[0][1])}”</li>
<li>Nov. 30.: „{esc(SUBJECTS2[0][1])}” – csak Elmaradó, Elveszett, Érdeklődik (aki vásárolt, az addigra Aktív).</li></ul>
<div class="box" style="margin-top:3mm"><h4>Fejléckép</h4><p style="margin:0;font-size:8.6pt">A levélben a kép a téma hirdetéskép-mappájából töltődik – a következő téma-kiadással kerül ki (nov. 10.).</p></div></div>
</div>''', n))

# 8. Csatornák 2 – új partnerek, keresés, közösségi
n += 1
kw = ', '.join(GOOGLE_KW)
pages.append(page(f'''<div class="kick">Csatornák · 2</div><h2>Új partnerek: <em>keresés, levél, hívás.</em></h2>
<div class="cols">
<div><h3>Megkeresés a Partner CRM-ben</h3><ul class="clean">
<li><b>Cégkeresés típus és város szerint</b> (Új partnerek → Megkeresés): ajándékbolt, jógastúdió, masszázs, hangterápia, ezoterikus bolt, webáruház – Budapest és a megyeszékhelyek. Cél: ~150 cég.</li>
<li><b>Levélsor</b>, magázva, 3 levél (0 / +4 / +7 nap), a végén hívás. Csak jóváhagyott céges címre.</li>
<li><b>Hideghívás</b> a levélsor után, az „új partner” forgatókönyvvel; „Regisztrál” vagy „Bemutatóterem” eredménnyel.</li>
<li>Jelentkezés: <code>mandala.hu/viszonteladoknak</code> – 3 lépés, 1–2 munkanap; a pultnál azonnal.</li></ul>
<h3 style="margin-top:5mm">Webshop</h3><ul class="clean">
<li>Kampánysáv <b>csak a Viszonteladóknak oldalon és a viszonteladói felületen</b> – a nyilvános felső sávba nem, mert a vásárló nem kaphatja meg.</li></ul></div>
<div><h3>Google-keresés – nagyker szándékra</h3><ul class="clean">
<li>Kulcsszavak (kifejezés-egyezés): {esc(kw)}.</li>
<li>Kizárás: {esc(', '.join(GOOGLE_NEG))}, és a kiskereskedelmi kampány szavai.</li>
<li>Cél-oldal: a Viszonteladóknak oldal; <b>hívás-bővítmény</b> a bemutatóterem számával, hely-bővítmény a Cégprofilból. Napi 3 000 Ft.</li></ul>
<h3 style="margin-top:5mm">Facebook, Instagram, Cégprofil</h3><ul class="clean">
<li>Három organikus poszt (nov. 11., 18., dec. 1.), a boltosoknak címezve, és egy Cégprofil-ajánlat a kampány idejére.</li>
<li><b>Fizetett Meta-hirdetést nem javaslunk:</b> a közönségünk vásárló, a „−50%” nekik szólna, és nem kaphatják meg. Ha mégis: csak cégoldal-kezelőknek, 40 000 Ft-os próba.</li></ul></div>
</div>''', n))

# 9. Bemutatóterem
n += 1
cnt = ''.join(f'<li>{esc(x)}</li>' for x in COUNTER)
pages.append(page(f'''<div class="kick">Bemutatóterem</div><h2>Ahol a kampány <em>megtörténik.</em></h2>
<div class="cols">
<div><div class="gal" style="grid-template-columns:1fr 1fr"><div><img src="../out/kepek/plakat-A4-nyitott-raktar.jpg"><div class="cap">Plakát, A4 (A3-ra is nagyítható) – a bejáratnál és a pultnál</div></div>
<div><img src="../out/kepek/polccimkek-4xA6.jpg"><div class="cap">Polccímkék, 4 × A6: zöld, sárga, piros polc, és a „Félretéve” kártya</div></div></div>
<h4 style="margin-top:5mm">Ami kell</h4><ul class="clean" style="font-size:8.8pt">
<li>Pötty-ív: 3 × 24 db 40 mm-es és 96 db 20 mm-es pötty (A4, körcímkére vagy papírra). Gyorsabb: boltban kapható színes pöttymatrica és a plakát jelmagyarázata.</li>
<li>Pultkártya (A5) a pultra, 100 partnerlap (A4, kétoldalas) a látogatóknak.</li>
<li>Egy „Félretéve” polc – névvel, dátummal.</li></ul></div>
<div><div class="gal" style="grid-template-columns:1fr"><div style="width:62%"><img src="../out/kepek/pultkartya-A5.jpg"></div></div>
<h4 style="margin-top:4mm">A pultnál – így adjuk a kedvezményt</h4><ol class="num" style="font-size:8.3pt">{cnt}</ol></div>
</div>''', n))

# 10. Keret és célok
n += 1
pages.append(page(f'''<div class="kick">Keret és célok</div><h2>Kis pénz, <em>sok hívás.</em></h2>
<div class="cols">
<div><h4>Keret</h4><table><tr><th>Tétel</th><th class="n">Ft</th></tr>
<tr><td>Nyomtatás: pötty-ívek, plakátok, polccímkék, pultkártya, 100 partnerlap</td><td class="n">≈ 20 000</td></tr>
<tr><td>Google-keresés, napi 3 000 Ft × 38 nap</td><td class="n">≈ 114 000</td></tr>
<tr><td>Meta – nem javasoljuk (ha mégis: próba)</td><td class="n">0 (40 000)</td></tr>
<tr><td><b>Összesen</b></td><td class="n"><b>≈ 134 000</b></td></tr></table>
<p class="small" style="margin-top:2mm">A karácsonyi kiskereskedelmi keretből (novemberre 600 000 Ft) nem veszünk el: ez külön, kicsi tétel. A legnagyobb „költség” a kedvezmény maga és a hívásokra szánt idő – napi kb. 1,5 óra.</p>
<h4 style="margin-top:5mm">A kedvezmény ára</h4><p style="font-size:8.8pt">A pötty a lassan fogyó készletet viszi: a piros tétel pénzzé válik, ami eddig a polcon állt. A cél nem az árrés, hanem a forgalom, a hely és a visszatérő partner.</p></div>
<div><h4>Célok (javaslat)</h4><table><tr><th>Mit mérünk</th><th class="n">Cél</th></tr>
<tr><td>Hívás a régi partnereknek (elért)</td><td class="n">90</td></tr>
<tr><td>Visszatért partner (Elmaradó / Elveszett → vásárolt)</td><td class="n">15</td></tr>
<tr><td>Első vásárlás az Érdeklődőkből</td><td class="n">8</td></tr>
<tr><td>Új jóváhagyott viszonteladó</td><td class="n">20</td></tr>
<tr><td>… ebből vásárolt</td><td class="n">10</td></tr>
<tr><td>„NYITOTT RAKTÁR” számla</td><td class="n">50</td></tr>
<tr><td>Nettó forgalom a pöttyös számlákon</td><td class="n">≈ 5,5 M Ft</td></tr>
<tr><td>A „nem fogy” lista hossza</td><td class="n">a felére</td></tr></table>
<p class="small" style="margin-top:2mm">Feltevés: egy vásárlás átlagosan nettó 110 000 Ft (a −10%-os akció 100 000 Ft-os küszöbéből; a CRM-ből pontosítjuk).</p>
<h4 style="margin-top:4mm">Honnan látjuk</h4><ul class="clean" style="font-size:8.6pt"><li>Partner CRM: szakaszváltások, hívás-statisztika, bemutatótermi időpontok, új jelentkezések.</li><li>Számlázz.hu-export: a „NYITOTT RAKTÁR” megjegyzésű számlák.</li><li>Google Ads: hívások, kattintás a jelentkezésre.</li></ul></div>
</div>''', n))

# 11. Vizuális irány (brief)
n += 1
sw = ''.join(f'<div style="background:{c};{"border:1px solid #DDD3C3;" if c in ("#F7F4EE", "#ECE7DC") else ""}"><span style="color:{"#1C1916" if c in ("#F7F4EE", "#ECE7DC", "#E3B23C", "#EEE7DB") else "#F7F4EE"}">{t}</span></div>'
             for c, t in [('#16120F', 'éj'), ('#F7F4EE', 'papír'), ('#A9581A', 'sáfrány'), ('#6B2A2A', 'bordó'), ('#2F7A4D', 'zöld −10'), ('#E3B23C', 'sárga −30'), ('#B3322B', 'piros −50'), ('#2D5A9B', 'kék (zászló)')])
pages.append(page(f'''<div class="kick">Vizuális irány</div><h2>Egy pötty, <em>ami mindenhol ugyanazt jelenti.</em></h2>
<div class="cols">
<div><h4>A gondolat</h4><p style="font-size:9.2pt">A raktárban így áraz az ember: kézzel ragaszt egy színes pöttyöt a tételre. Ebből lett a kampány jele – a három szín az imazászlóé, amit a Mandala maga is árul. A pötty nem díszítés: ez a mechanika. A polcon, a plakáton, a levélben és a hirdetésben ugyanaz, ugyanazzal a jelentéssel.</p>
<h4 style="margin-top:4mm">Akiktől tanultunk</h4><ul class="clean" style="font-size:8.8pt">
<li><b>Otl Aicher (rendszer):</b> a szín információ. Három szín, három jelentés, sehol egy negyedik.</li>
<li><b>Alan Fletcher (hang):</b> „simogasd a közhelyet, amíg metaforává nem dorombol”. Az akciós matrica a legkopottabb közhely – itt imazászló-pötty lesz belőle.</li>
<li><b>A raktár maga (mozgás):</b> a jel a polctól a pultig vezet; a levél fejlécén a pöttyök a termékeken ülnek, ahogy a polcon lesznek.</li></ul>
<h4 style="margin-top:4mm">Ami nálunk új</h4><p style="font-size:8.8pt">A partnerlapon árrés-táblázat (mennyit hoz egy pötty), és a kampány fizikailag is megépül: pötty-ív, polccímke, pultkártya.</p></div>
<div><h4>Képek</h4><p style="font-size:8.8pt">A jelenetek AI-val készültek, a Mandala valódi termékfotóiból (szobrok, hangtálak, Sandesh füstölők, imazászló): raktárpolc, nyitott ajtó, egy partner boltja, csomagolás. A jeleneteken nincs olyan tárgy, amit a bolt ne árulna; az egyik képen az ablakban látszott a Parlament (a Bakáts utcából nem látszik) – újrageneráltuk.</p>
<h4 style="margin-top:4mm">Betű és szín</h4><p style="font-size:8.8pt">Cormorant Garamond + Inter, mint a webshopon; papír, éj, sáfrány, bordó, és a három pötty-szín.</p><div class="sw">{sw}</div>
<h4 style="margin-top:5mm">Amit nem csinálunk</h4><ul class="clean" style="font-size:8.8pt"><li>„Végkiárusítás”, csupa nagybetű, felkiáltójel, villogó piros-sárga akciós sablon.</li><li>„Akár 50%” – a pötty tételenként mondja meg, mennyi.</li><li>Webes kedvezményt soha nem ígérünk.</li></ul></div>
</div>''', n))


# 12–13. Kreatívok
def gal(items, cols):
    return f'<div class="gal" style="grid-template-columns:repeat({cols},1fr)">' + ''.join(f'<div><img src="{src}"><div class="cap">{cap}</div></div>' for src, cap in items) + '</div>'


names = {'raktar': 'Nyitott raktár (fő)', 'regi': 'Régi partnereknek', 'uj': 'Új partnereknek', 'utolso': 'Utolsó kör (dec.)'}
n += 1
feed = [(f'../out/kepek/{i}-{k}-feed.jpg', f'{names[k]} · 4:5') for i, k in enumerate(ADS, 1)]
story = [(f'../out/kepek/{i}-{k}-story.jpg', f'{names[k]} · 9:16') for i, k in enumerate(ADS, 1)]
pages.append(page(f'''<div class="kick">Kreatívok · 1</div><h2>Négy üzenet, <em>egy jel.</em></h2>
{gal(feed, 4)}<div style="height:5mm"></div>{gal(story, 4)}
<p class="small" style="margin-top:3mm">Minden képen: a pötty-csoport, a telefonszám gombként, a cím és a nyitvatartás. Méretek: 4:5, 9:16, 1:1, 1200 × 628, 1200 × 600 – összesen 20 kép a <code>kepek/</code> mappában.</p>''', n))
n += 1
land = [(f'../out/kepek/{i}-{k}-land.jpg', f'{names[k]} · 1200 × 628') for i, k in enumerate(ADS, 1)]
pages.append(page(f'''<div class="kick">Kreatívok · 2</div><h2>Fekvő formátum, <em>levél, partnerlap.</em></h2>
{gal(land, 2)}<div style="height:4mm"></div>
<div class="gal" style="grid-template-columns:1.25fr 1fr 1fr"><div><img src="../out/kepek/level-fejlec-1200x600.jpg"><div class="cap">A partnerlevél fejléce: a pöttyök a termékeken – szöveg nélkül, a cím a levélben élő szöveg</div></div>
<div><img src="../pv/p-lap-1.jpg"><div class="cap">Partnerlap, 1. oldal</div></div><div><img src="../pv/p-lap-2.jpg"><div class="cap">Partnerlap, 2. oldal – árrés-táblázat</div></div></div>''', n))

# 14–15. Forgatókönyvek


def script_page(sc, kick, title, extra=''):
    steps = ''.join(f'<div class="step"><b>{i}. {t}</b><p>{br(x)}</p></div>' for i, (t, x) in enumerate(sc['steps'], 1))
    obj = ''.join(f'<p><b>„{esc(q)}”</b><br>{esc(a)}</p>' for q, a in sc['objections'])
    return f'''<div class="kick">{kick}</div><h2>{title}</h2>
<div class="cols"><div>{steps}</div><div><h4>Ha ezt mondja</h4><div class="obj">{obj}</div>{extra}</div></div>'''


n += 1
pages.append(page(script_page(SCRIPT_OLD, 'Szövegek · forgatókönyv 1', 'Régi partnernek: <em>„rég járt nálunk”.</em>',
                              '<div class="box" style="margin-top:3mm"><h4>A Partner CRM-ben</h4><p style="margin:0;font-size:8.4pt">Hideghívás → Forgatókönyvek → Új: „Nyitott raktár – régi partner”, meglévő partnereknek. A lépések és a kifogások innen másolhatók (szovegek.md).</p></div>'), n))
n += 1
pages.append(page(script_page(SCRIPT_NEW, 'Szövegek · forgatókönyv 2', 'Új partnernek: <em>az első bejövetel.</em>',
                              '<div class="box" style="margin-top:3mm"><h4>Magázva, rövid</h4><p style="margin:0;font-size:8.4pt">Az első hívás célja nem az eladás, hanem a jelentkezés vagy egy időpont. Két perc; ha nem érdekli, megköszönjük – a CRM fél év múlva újra felhozza.</p></div>'), n))

# 16. Levelek
n += 1
subj = ''.join(f'<tr><td>{k}</td><td>{esc(s)}<br><span class="small">{esc(p)}</span></td></tr>' for k, s, p in SUBJECTS)
seq = ''.join(f'<tr><td>{"0. nap" if d == 0 else f"+{d} nap"}</td><td><b>{esc(s)}</b><br><span class="small">{esc(b.split(chr(10) + chr(10))[1][:150])}…</span></td></tr>' for d, s, b in SEQUENCE)
pages.append(page(f'''<div class="kick">Szövegek · levelek</div><h2>Partnerlevél, emlékeztető, <em>levélsor.</em></h2>
<div class="cols">
<div><h4>Partnerlevél, nov. 11. – tárgy és előnézeti szöveg</h4><table class="copy tight">{subj}</table>
<p class="small" style="margin-top:1.5mm">Az A megy: benne van az ajánlat és a mérték. A csoport 200 címnél kisebb – A/B tesztnek nincs értelme.</p>
<h4 style="margin-top:4mm">A levél menete</h4><ul class="clean" style="font-size:8.6pt"><li>Fejléc: a pöttyök a termékeken.</li><li>„Nyitott raktár: a készlet 80%-a pöttyöt kapott” – két bekezdés, telefon-gomb.</li><li>A pötty színe a kedvezmény + a feltételek, egy kártyán.</li><li>Karácsony előtt: mi van raktáron, belépés a nagyker árakhoz.</li><li>„Hívj, és félretesszük” – sötét sáv, nagy telefonszám.</li><li>Cím, nyitvatartás, „Szeretettel: a Mandala csapata”.</li></ul>
<h4 style="margin-top:4mm">Emlékeztető, nov. 30.</h4><p style="font-size:8.6pt">„{esc(SUBJECTS2[0][1])}” – {esc(MAIL2['intro'][0])}</p></div>
<div><h4>Hívás után – régi partnernek (tegezve)</h4><p style="font-size:8.3pt">{br(MAIL_CALL_OLD['body'][:360])}…</p>
<h4 style="margin-top:3mm">Levélsor új partnereknek (magázva)</h4><table class="copy tight">{seq}</table>
<h4 style="margin-top:3mm">SMS / Viber – csak annak, akivel beszéltél</h4><p style="font-size:8.3pt">{esc(SMS)}</p></div>
</div>''', n))

# 17. Hirdetés- és posztszövegek
n += 1
gh = ''.join(f'<tr><td>{esc(h)}</td><td class="n small">{len(h)}</td></tr>' for h in GOOGLE_HEAD)
gd = ''.join(f'<tr><td>{esc(d)}</td><td class="n small">{len(d)}</td></tr>' for d in GOOGLE_DESC)
soc = ''.join(f'<div style="margin-bottom:3mm"><h4>{esc(t)}</h4><p style="font-size:8.2pt;line-height:1.4">{br(x)}</p></div>' for t, _, x in SOCIAL[:2])
pages.append(page(f'''<div class="kick">Szövegek · hirdetés és poszt</div><h2>Google-keresés, <em>posztok.</em></h2>
<div class="cols">
<div><h4>Reszponzív keresési hirdetés – címek (≤ 30)</h4><table class="tight">{gh}</table>
<h4 style="margin-top:3mm">Leírások (≤ 90)</h4><table class="tight">{gd}</table>
<p class="small" style="margin-top:1.5mm">Cél-oldal: {GOOGLE_URL}</p></div>
<div>{soc}<p class="small">A dec. 1-i poszt, a Cégprofil-ajánlat és a 3 kockás story a <code>szovegek.md</code>-ben.</p></div>
</div>''', n))

# 18. Teendők és döntések
n += 1
tk = ''.join(f'<tr><td>{esc(t)}</td><td>{esc(w)}</td><td class="n">{esc(d)}</td></tr>' for t, w, d in TASKS)
qs = ''.join(f'<li><b>{esc(k)}:</b> {esc(v)}</li>' for k, v in QUESTIONS)
pages.append(page(f'''<div class="kick">Teendők</div><h2>Ki, mit, <em>mikorra.</em></h2>
<table class="tight"><tr><th>Teendő</th><th style="width:36mm">Ki</th><th class="n" style="width:24mm">Mikorra</th></tr>{tk}</table>
<h4 style="margin-top:5mm">Döntés kell nov. 9-ig – Áron és a Mandala</h4><ol class="num" style="font-size:8.6pt">{qs}</ol>''', n))

html = f'<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Mandala – Nyitott raktár – nagyker kampányterv</title><style>{CSS}</style></head><body>{"".join(pages)}</body></html>'
open(os.path.join(B, 'strategy.html'), 'w').write(html)
json.dump([{'html': 'strategy.html', 'pdf': '../out/Mandala-nagyker-kampanyterv-2026.pdf', 'w': 794, 'h': 1123}], open(os.path.join(B, 'jobs-pdf.json'), 'w'))
print(len(pages), 'oldal')
