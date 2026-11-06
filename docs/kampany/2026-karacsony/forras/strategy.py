# Mandala karácsony 2026 – kampányterv PDF (HTML → Playwright). Használat: python3 strategy.py && node render.cjs jobs-pdf.json
import json, os, datetime
from texts import PHASES, TOPBAR, BANNER, CART, META, GOOGLE_HEAD, GOOGLE_DESC, GOOGLE_PROMO, EMAILS, SOCIAL, TASKS

B = os.path.dirname(os.path.abspath(__file__))
ADS_CSS = open(os.path.join(B, 'ads.py')).read().split('CSS = r"""')[1].split('"""')[0]
TW = '<svg class="twine" viewBox="0 0 60 90"><path d="M30 88 C 14 70, 12 40, 26 6" fill="none" stroke="var(--twine)" stroke-width="3.2" stroke-linecap="round"/><path d="M30 88 C 46 70, 44 38, 28 4" fill="none" stroke="var(--twine)" stroke-width="3.2" stroke-linecap="round"/><circle cx="27" cy="5" r="3.4" fill="var(--twine)"/></svg>'


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def tag(big, small, bg='', size=''):
    st = (f'--tagbg:{bg};--tagc:var(--paper);' if bg else '') + size
    return f'<div class="tag" style="{st}"><div class="card"><span class="hole"></span><b>{big}</b><small>{small}</small></div>{TW}</div>'


CSS = ADS_CSS + r"""
@page{size:A4;margin:0}
html,body{width:210mm;height:auto;overflow:visible;background:var(--paper)}
body{color:var(--ink);font:400 10.2pt/1.5 'Inter';--u:4px;font-feature-settings:'kern' 1}
.page{position:relative;width:210mm;height:297mm;overflow:hidden;page-break-after:always;padding:18mm 17mm 16mm;background:var(--paper)}
.page.dark{background:var(--night);color:var(--paper)}
.pn{position:absolute;left:17mm;right:17mm;bottom:9mm;display:flex;justify-content:space-between;font:500 7.5pt/1 'Inter';letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.dark .pn{color:rgba(247,244,238,.55)}
.kick{font:600 8pt/1.2 'Inter';letter-spacing:.18em;text-transform:uppercase;color:var(--saffron)}
.dark .kick{color:var(--saffron-l)}
h2{margin:3mm 0 6mm;font:500 34pt/1 'Cormorant Garamond',serif;letter-spacing:-.012em;text-wrap:balance}
h2 em,h3 em{font-style:italic;color:var(--maroon)}
.dark h2 em{color:var(--saffron-l)}
h3{margin:0 0 2mm;font:500 17pt/1.1 'Cormorant Garamond',serif}
h4{margin:0 0 1.2mm;font:600 8pt/1.3 'Inter';letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
p{margin:0 0 2.6mm;text-wrap:pretty}
.lead{font:400 12.5pt/1.5 'Inter';color:#2d2722;max-width:150mm}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:7mm 9mm}
.cols3{display:grid;grid-template-columns:repeat(3,1fr);gap:6mm}
.box{background:var(--sand);border-radius:2mm;padding:5mm 5.5mm}
.box.line{background:transparent;border:1px solid #DDD3C3}
.box.night{background:var(--night);color:var(--paper)}
ul.clean{margin:0;padding:0;list-style:none}
ul.clean li{position:relative;padding-left:5mm;margin:0 0 1.8mm}
ul.clean li::before{content:'';position:absolute;left:0;top:2.2mm;width:2.4mm;height:1px;background:var(--saffron)}
ol.num{margin:0;padding:0;list-style:none;counter-reset:n}
ol.num li{position:relative;padding-left:9mm;margin:0 0 2.6mm;counter-increment:n}
ol.num li::before{content:counter(n);position:absolute;left:0;top:-1.2mm;font:500 17pt/1 'Cormorant Garamond',serif;color:var(--saffron);font-variant-numeric:lining-nums}
table{width:100%;border-collapse:collapse;font-size:9pt;font-variant-numeric:tabular-nums lining-nums}
th{text-align:left;font:600 7.3pt/1.3 'Inter';letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding:2mm 2mm 2mm 0;border-bottom:1.5px solid var(--ink)}
td{padding:2.2mm 2mm 2.2mm 0;border-bottom:1px solid #DDD3C3;vertical-align:top}
td.n,th.n{text-align:right;white-space:nowrap}
.big{font:500 28pt/1 'Cormorant Garamond',serif;font-variant-numeric:lining-nums;white-space:nowrap}
.small{font-size:8.3pt;color:var(--muted)}
.src{font-size:7.4pt;color:var(--muted);line-height:1.4}
.note{border-left:2px solid var(--saffron);padding:1mm 0 1mm 4mm;margin:3mm 0}
/* borító */
.cover{padding:0}
.cover img.bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 60%;-webkit-mask-image:none;mask-image:none}
.cover .sc{position:absolute;inset:0;background:linear-gradient(180deg,rgba(16,12,10,.86) 0%,rgba(16,12,10,.35) 46%,rgba(16,12,10,0) 62%,rgba(16,12,10,.7) 100%)}
.cover .in{position:absolute;left:17mm;right:17mm;top:20mm}
.cover .lg{height:11mm}
.cover h1{position:static;width:auto;margin-top:24mm;font:500 50pt/.95 'Cormorant Garamond',serif;color:var(--paper);letter-spacing:-.015em}
.cover h1 em{font-style:italic;color:var(--saffron-l)}
.cover .sub{position:static;width:130mm;margin-top:6mm;font:400 13pt/1.45 'Inter';color:rgba(247,244,238,.9)}
.cover .meta{position:absolute;left:17mm;right:17mm;bottom:15mm;display:flex;justify-content:space-between;align-items:flex-end;font:500 9pt/1.5 'Inter';color:rgba(247,244,238,.85)}
.cover .meta b{display:block;font:500 15pt/1.2 'Cormorant Garamond',serif;color:var(--paper)}
/* szakasz-kártyák */
.phase{display:grid;grid-template-columns:44mm 1fr;gap:6mm;padding:4.5mm 0;border-top:1px solid #DDD3C3}
.phase:first-of-type{border-top:1.5px solid var(--ink)}
.phase .ph{position:relative;height:44mm;border-radius:1.5mm;overflow:hidden}
.phase .ph img{width:100%;height:100%;object-fit:cover;object-position:center 75%}
.phase .ph .tag{right:2.5mm;left:auto;top:3.5mm;width:20mm;--u:2.2px;--tbs:22px;--tr:5deg}
.phase .d{font:600 7.5pt/1.3 'Inter';letter-spacing:.12em;text-transform:uppercase;color:var(--saffron)}
.phase h3{margin:1.2mm 0 1.6mm;font-size:18pt}
.phase p{font-size:9pt;margin-bottom:1.4mm}
.phase p b{font-weight:600}
/* naptár */
.cal{display:grid;grid-template-columns:26mm repeat(46,1fr);font-size:6.8pt;row-gap:1.6mm;align-items:center}
.cal .lab{font:600 7pt/1.2 'Inter';letter-spacing:.06em;text-transform:uppercase;color:var(--muted);padding-right:2mm}
.cal .day{text-align:center;color:var(--muted);font-size:6pt}
.cal .wk{font:600 6.5pt/1 'Inter';color:var(--ink);border-left:1px solid #CFC4B2;padding-left:1mm}
.bar{height:7mm;border-radius:1.2mm;display:flex;align-items:center;padding:0 2mm;font:600 7pt/1.1 'Inter';color:var(--paper);white-space:nowrap;overflow:hidden}
.bar.f{background:#8C6A43}.bar.b{background:var(--night)}.bar.m{background:var(--maroon)}.bar.k{background:var(--saffron)}.bar.u{background:#5b4a3c}
.bar.lt{background:var(--sand);color:var(--ink)}
.dot{width:3.2mm;height:3.2mm;border-radius:50%;background:var(--saffron);justify-self:center}
.dot.o{background:transparent;border:1.5px solid var(--saffron)}
/* galéria */
.gal{display:grid;gap:4mm;align-items:start}
.gal img{width:100%;display:block;border-radius:1mm;box-shadow:0 .6mm 2mm rgba(0,0,0,.12)}
.gal .cap{margin-top:1.4mm;font-size:7.2pt;color:var(--muted)}
.sw{display:flex;gap:3mm;margin-top:3mm}
.sw div{flex:1;height:16mm;border-radius:1.2mm;position:relative}
.sw span{position:absolute;left:2mm;bottom:1.5mm;font:500 6.6pt/1.2 'Inter'}
.copy td:first-child{width:36mm;color:var(--muted);font-size:8pt}
.copy td{font-size:8.8pt}
.ch{font-size:7.4pt;color:var(--muted);white-space:nowrap}
.sig{margin-top:6mm;font:500 20pt/1 'Cormorant Garamond',serif}
.tight td{padding:1.4mm 2mm 1.4mm 0;font-size:8.4pt;line-height:1.38}
"""


def page(inner, n, dark=False, cls=''):
    return f'<section class="page {"dark" if dark else ""} {cls}">{inner}<div class="pn"><span>Mandala · Karácsony 2026 · kampányterv</span><span>{n}</span></div></section>'


pages = []
# 1. Borító
pages.append(f'''<section class="page dark cover"><img class="bg" src="../ai/fust-portrait-1.jpg" alt=""><div class="sc"></div>
<div class="in"><img class="lg" src="logo-light.svg" alt="Mandala"><h1>Karácsonyi<br><em>kampányterv</em></h1>
<p class="sub">Füstölő-hét, Black Week, Mikulás és karácsony – november 13-tól december 24-ig, egy hangon a webshopban, a hirdetésekben, a hírlevélben és a bemutatóteremben.</p></div>
<div class="meta"><div><b>Mandala · mandala.hu</b>Kiskereskedelmi kampány · 2026. november – december</div><div style="text-align:right"><b>HelloProVision</b>Áron · 2026. november 6.</div></div></section>''')

# 2. Röviden
ph_rows = ''.join(f'<li><b>{p["name"]}</b> ({p["short"]}) – {p["offer"]}.</li>' for p in PHASES)
pages.append(page(f'''<div class="kick">Röviden</div><h2>Négy szakasz, <em>egy</em> történet.</h2>
<p class="lead">A november a Black Weekről szól, a december a karácsonyi ajándékról. A kettőt egy ívbe fűzzük: előbb egy olcsó, kedves belépő (1+1 füstölő), aztán a nagy kedvezmény, végül ajándék árengedmény nélkül – csomagolás, kártya, egy füstölő a dobozban, és egy biztos határidő.</p>
<div class="cols" style="margin-top:8mm">
<div><h4>Mit csinálunk</h4><ol class="num">{ph_rows}</ol></div>
<div><h4>Miért így</h4><ul class="clean">
<li>A <b>Füstölő-hét</b> kis pénzből hoz új vásárlót és feliratkozót, és felépíti a Black Week remarketing-közönségét.</li>
<li>A <b>Black Week</b> a csúcs: itt a legnagyobb a kedvezmény, és itt veszik meg sokan a karácsonyi ajándékot is.</li>
<li><b>Decemberben</b> nem engedünk az árból: az árszabály miatt a Black Week ára még 30 napig viszonyítási ár, és a csomagolás, a kártya, az ajándék füstölő a kosárértéket sem viszi le.</li>
<li>Minden üzenet a <b>mandala.hu</b>-ra visz, és a bemutatóterem is ugyanazt mondja.</li></ul></div>
</div>
<div class="cols3" style="margin-top:9mm">
<div class="box"><h4>Keret</h4><div class="big">600 000 Ft</div><p class="small">novemberben, ahogy most – a Black Week alatt a napi keret kétszerese. Decemberre javaslat: 700 000 Ft.</p></div>
<div class="box"><h4>Cél novemberben</h4><div class="big">130 rendelés</div><p class="small">a webshopban (októberben 88), legalább 20 000 Ft-os átlagos kosárral.</p></div>
<div class="box"><h4>Indulás</h4><div class="big">nov. 13.</div><p class="small">péntek. Ehhez november 9-ig kell a jóváhagyásotok az ajánlatokra.</p></div>
</div>
<div class="note" style="margin-top:9mm"><b>Tőletek ennyi kell (részletek a 17. oldalon):</b> az ajánlatok jóváhagyása, a füstölő-készlet, a szállítási határidők a raktárral és a GLS-szel, hány hírlevél mehet, és hogy a bemutatóteremben is érvényesek-e az ajánlatok.</div>''', 2))

# 3. Honnan indulunk
pages.append(page(f'''<div class="kick">Kiindulás</div><h2>Honnan <em>indulunk</em>.</h2>
<div class="cols3">
<div><h4>Webes rendelés · október</h4><div class="big">88</div><p class="small">1 782 403 Ft bruttó forgalom</p></div>
<div><h4>Átlagos kosár · október</h4><div class="big">≈ 20 250 Ft</div><p class="small">az ingyenes szállítás határa 25 000 Ft</p></div>
<div><h4>Google · okt. 27. – nov. 2.</h4><div class="big">4,7 / 5,9</div><p class="small">ROAS: Performance Max (10 rendelés) / Keresés (3 rendelés)</p></div>
</div>
<div class="cols" style="margin-top:9mm">
<div><h3>Amit látunk</h3><ul class="clean">
<li><b>Sokan tesznek kosárba, kevesebben fizetnek.</b> Az elmúlt hetekben egyszerűsítettük a pénztárt, és két kosár-emlékeztető levél megy. A kampányban a kosárban megjelenő üzenetek segítenek: „még egy füstölő, és az egyik ingyen van”, „még X Ft az ajándék füstölőig”.</li>
<li><b>Az átlagos kosár 25 000 Ft alatt van.</b> A 15 000 Ft-os ajándék-küszöb és az ajándékcsomag a kosarat felfelé húzza.</li>
<li><b>A Google jól hoz.</b> A Performance Max és a Keresés nyereséges, ezekre építünk.</li>
<li><b>A Meta most nem rögzíti a vásárlásokat</b> (a kosárba tételt igen). A Black Week előtt ezt rendbe tesszük, hogy a Meta vásárlásra tudjon optimalizálni.</li></ul></div>
<div><h3>Ami a piacon igaz</h3>
<p>A PwC 2025-ös magyar felmérése szerint a Black Friday-vásárlást tervezők <b>40%-a a karácsonyi ajándékait is</b> ekkor veszi meg. Ezért a Black Week üzenetében már ott az ajándék, és decemberben a határidő lesz a fő érv.</p>
<p>A Black Friday sok helyen már nem nagy szám, mert mindig van valahol akció. Nálunk ez előny: a kézzel válogatott, egyedi darab nem az a termék, amit máshol olcsóbban talál.</p>
<div class="box line" style="margin-top:4mm"><h4>Árszabály – erre figyelünk</h4><p style="font-size:9pt;margin:0">Akciós árnál a korábbi ár a <b>30 napon belüli legalacsonyabb ár</b> (új terméknél 15 nap). A Black Friday-ár az akció után még 30 napig viszonyítási ár marad – ezért decemberben nem százalékos kedvezménnyel, hanem ajándékkal dolgozunk.</p></div>
</div></div>
<p class="src" style="margin-top:8mm">Források: Mandala webshop, havi riport (2026. október); HelloProVision Hirdetéskezelő (Google Ads, okt. 27. – nov. 2.); PwC Magyarország, 2025. novemberi Black Friday-felmérés (3 386 online vásárló); Budapest Főváros Kormányhivatala: „Útikalauz a Black Friday és Black November útvesztőjéhez” (árkormányrendelet, 30 napos szabály).</p>''', 3))

# 4–5. A négy szakasz
def phase_block(p, img, t):
    return f'''<div class="phase"><div class="ph"><img src="../out/web/ai-{img}" alt="">{t}</div><div><div class="d">{p["dates"]}</div><h3>{p["name"]}: {p["offer"].lower() if p["key"] in ("bw",) else p["offer"]}</h3>
<p>{p["rule"]}</p><p><b>Nem jár / nem adódik össze:</b> {p["excl"]}</p><p><b>Cél:</b> {p["goal"]}</p></div></div>'''

pages.append(page(f'''<div class="kick">Az ajánlatok</div><h2>A négy <em>szakasz</em>.</h2>
{phase_block(PHASES[0], 'fust-portrait-1.jpg', tag('1+1', 'füstölő'))}
{phase_block(PHASES[1], 'bw-portrait-1.jpg', tag('−20%', 'szinte mindenre', 'var(--saffron)'))}
{phase_block(PHASES[2], 'mikulas-portrait-1.jpg', tag('0 Ft', 'díszcsomagolás'))}''', 4))
pages.append(page(f'''{phase_block(PHASES[3], 'karacsony-portrait-1.jpg', tag('dec. 18.', '12 óráig'))}
<div class="cols" style="margin-top:8mm">
<div><h3>Miért ezek a mértékek</h3><ul class="clean">
<li><b>1+1 füstölő:</b> egy csomag 590–1 040 Ft, így az ajándék kicsi, de érezhető – és aki füstölőt vesz, gyakran füstölőtartót és illóolajat is tesz mellé.</li>
<li><b>20% / 10%:</b> a 20% elég nagy ahhoz, hogy a Black Weeken is kitűnjön; a hangtálak egyedi, nagy értékű darabok, ott a 10% is komoly összeg.</li>
<li><b>Nincs decemberi százalék:</b> az árszabály és a kosárérték miatt. Helyette ajándék, határidő, utalvány.</li></ul>
<p class="small">A mértékek javaslatok: a ti árrésetekkel nézzétek át, és ha kell, módosítunk (pl. 15% / 10%).</p></div>
<div><h3>Amire figyelünk</h3><ul class="clean">
<li><b>Pontos szöveg mindenhol:</b> „két csomagból az olcsóbb ingyenes” – így nincs félreértés a pénztárnál.</li>
<li><b>Készlet:</b> az 1+1 alatt a füstölő akár kétszer olyan gyorsan fogy; a Nag Champa, Palo Santo és Green Tree illatokból legyen elég.</li>
<li><b>Határidők:</b> december 3. és december 18., 12:00 – a raktárral és a GLS-szel egyeztetve, és csak ha biztosan tartható.</li>
<li><b>Kiskereskedelmi kampány:</b> a viszonteladók a saját feltételeiket kapják, a Black Week nem rájuk szól.</li></ul></div></div>''', 5))

# 6. Naptár
start = datetime.date(2026, 11, 9)
days = [start + datetime.timedelta(d) for d in range(46)]  # nov. 9 – dec. 24.
def col(d):
    return (d - start).days + 2  # 1. oszlop a címke

def bar(row, a, b, cls, text):
    return f'<div class="bar {cls}" style="grid-row:{row};grid-column:{col(a)}/{col(b) + 1}">{text}</div>'

D = lambda m, d: datetime.date(2026, m, d)
cal = ['<div class="lab" style="grid-row:1"></div>']
for d in days:
    if d.weekday() == 0:
        cal.append(f'<div class="wk" style="grid-row:1;grid-column:{col(d)}/span 7">{d.month}.{d.day:02d}.</div>')
rows = ['Ajánlat', 'Webshop', 'Hírlevél', 'Meta', 'Google', 'Közösségi', 'Bemutatóterem']
for i, r in enumerate(rows):
    cal.append(f'<div class="lab" style="grid-row:{i + 2};grid-column:1">{r}</div>')
cal += [bar(2, D(11, 13), D(11, 22), 'f', 'Füstölő-hét · 1+1'), bar(2, D(11, 23), D(11, 30), 'b', 'Black Week · −20%'),
        bar(2, D(12, 1), D(12, 6), 'm', 'Mikulás'), bar(2, D(12, 7), D(12, 18), 'k', 'Karácsony · dec. 18. 12:00'), bar(2, D(12, 19), D(12, 24), 'u', 'Utalvány')]
cal += [bar(3, D(11, 9), D(11, 12), 'lt', 'beállítás'), bar(3, D(11, 13), D(12, 24), 'lt', 'felső sáv + banner + kosár-üzenetek fázisonként')]
for (m, d), o in [((11, 13), ''), ((11, 20), 'o'), ((11, 23), ''), ((11, 27), ''), ((11, 30), ''), ((12, 2), ''), ((12, 9), ''), ((12, 16), ''), ((12, 20), 'o')]:
    cal.append(f'<div class="dot {o}" style="grid-row:4;grid-column:{col(D(m, d))}"></div>')
cal += [bar(5, D(11, 9), D(11, 12), 'lt', 'mérés'), bar(5, D(11, 13), D(11, 22), 'f', '16 000/nap'), bar(5, D(11, 23), D(11, 30), 'b', '30 000/nap'), bar(5, D(12, 1), D(12, 18), 'k', 'dec.: javaslat 36 000/nap'), bar(5, D(12, 19), D(12, 24), 'u', 'utalvány')]
cal += [bar(6, D(11, 9), D(11, 12), 'lt', 'akciók'), bar(6, D(11, 13), D(11, 22), 'f', 'PMax + Keresés'), bar(6, D(11, 23), D(11, 30), 'b', 'Black Week'), bar(6, D(12, 1), D(12, 18), 'k', 'ajándék + határidő'), bar(6, D(12, 19), D(12, 24), 'u', '')]
cal += [bar(7, D(11, 13), D(12, 18), 'lt', 'heti 3 poszt + story; a Black Weekben napi story')]
cal += [bar(8, D(11, 12), D(11, 30), 'lt', 'novemberi plakát'), bar(8, D(12, 1), D(12, 24), 'lt', 'decemberi plakát + személyes átvétel')]
pages.append(page(f'''<div class="kick">Ütemterv</div><h2>November 9. – december 24.</h2>
<div class="cal">{''.join(cal)}</div>
<div class="cols" style="margin-top:9mm"><div><h4>Hírlevél</h4><p class="small">● alap levél, ○ választható – a tárgysorok a 16. oldalon.</p></div>
<div><h4>Fontos napok</h4><p class="small">nov. 27. Black Friday (ingyenes szállítás) · nov. 29. advent 1. vasárnapja · dec. 3. 12:00 Mikulás-határidő · dec. 6. Mikulás · dec. 18. 12:00 karácsonyi határidő</p></div></div>''', 6))

# 7. Webshop + hírlevél
tb = ''.join(f'<tr><td>{a}</td><td>{b}</td></tr>' for a, b in TOPBAR)
em = ''.join(f'<tr><td class="ch">{e[0]}{"" if e[1] == "alap" else "<br><i>választható</i>"}</td><td><b>{e[2]}</b><br><span class="small">{e[5]}</span></td></tr>' for e in EMAILS)
pages.append(page(f'''<div class="kick">Csatornák · 1</div><h2>Webshop és <em>hírlevél</em>.</h2>
<h3>A webshopban</h3><div class="cols"><ul class="clean">
<li><b>Felső sáv</b> visszaszámlálóval és <b>főoldali kampánybanner</b> – fázisonként (a téma kampány-funkciójával, dátumra magától vált).</li>
<li><b>Jelvények</b> a termékeken: „1+1” a füstölőkön, „−20%” a Black Weekben.</li>
<li><b>Céloldalak:</b> Füstölők (1+1), Black Week ajánlatok, ajándékötletek (ajándékválasztó), ajándékcsomag, ajándékutalvány.</li></ul>
<ul class="clean"><li><b>Kosár-üzenetek:</b> „Tegyél mellé még egy füstölőt – az egyik ingyen van.” · „Még X Ft, és egy füstölőt mi teszünk a csomagba.” · „Még X Ft az ingyenes szállításig.”</li>
<li><b>Ami magától dolgozik:</b> két kosár-emlékeztető levél, és a Black Weekben az „Olcsóbb lett, amit néztél” értesítő a feliratkozóknak.</li></ul></div>
<h3 style="margin-top:6mm">Hírlevél (MailerLite)</h3>
<p style="font-size:9pt">7 alap levél és 2 választható. A feliratkozó-doboz „havonta kétszer” ígér: most több mehet, mert mindegyik konkrét ajánlat – ezt ti döntitek el; ha kevesebbet szeretnétek, a választhatók maradnak el. A tárgysorok A/B változattal a 16. oldalon.</p>
<table class="copy"><tr><th>Mikor</th><th>Tárgy</th><th>Tartalom</th></tr>{''.join(f'<tr><td class="ch">{e[0]}{"" if e[1] == "alap" else "<br><i>választható</i>"}</td><td style="width:52mm"><b>{e[2]}</b></td><td><span class="small">{e[5]}</span></td></tr>' for e in EMAILS)}</table>''', 7))

# 8. Hirdetések, közösségi, bemutatóterem
pages.append(page(f'''<div class="kick">Csatornák · 2</div><h2>Hirdetések, közösségi oldalak, <em>bemutatóterem</em>.</h2>
<div class="cols">
<div><h3>Meta (Facebook, Instagram)</h3><ul class="clean">
<li><b>Új vásárlók</b> (a mostani Advantage+ kampány): fázisonként új képek és szövegek, ugyanabban a kampányban – a tanulás megmarad.</li>
<li><b>Remarketing</b> (katalógus, 30 nap): aki megnézett vagy kosárba tett valamit, a saját termékét látja a kampány szövegével. A Black Weekben ennek a kerete a legnagyobb.</li>
<li><b>Formátumok:</b> 4:5 hírfolyam, 9:16 story és reels, 1:1 – mind elkészült (10–13. oldal).</li>
<li><b>Előfeltétel:</b> a vásárlás-mérés rendbetétele november 12-ig.</li></ul>
<h3 style="margin-top:6mm">Google</h3><ul class="clean">
<li><b>Performance Max:</b> új „Karácsony” eszközcsoport a kampány képeivel és szövegeivel; akció-bővítmény (1+1, Black Week −20%).</li>
<li><b>Merchant Center akciók:</b> a Shopping-hirdetésen „Akció” címke – ingyenes, és sokat dob az átkattintáson.</li>
<li><b>Keresés:</b> új hirdetéscsoport a füstölőkre és a karácsonyi ajándékra; visszaszámláló a hirdetésszövegben a határidőkig.</li></ul></div>
<div><h3>Közösségi oldalak (organikus)</h3>
<p style="font-size:9pt">Heti 3 poszt és story, a Black Weekben naponta story. Nem csak akció: illat-ajánló, hogyan szólal meg a hangtál, apró ajándékok, a csapat kedvencei – valódi fotókkal a bemutatóteremből.</p>
<ul class="clean">{''.join(f'<li><b>{b}</b> <span class="small">({a})</span></li>' for a, b, c in SOCIAL)}</ul><p class="small">A posztok szövege a 15. oldalon.</p>
<h3 style="margin-top:6mm">Bemutatóterem (Bakáts u. 6.)</h3>
<p style="font-size:9pt">Ha a bemutatóteremben is van kiskereskedelmi vásárlás, ott is ugyanazok az ajánlatok érvényesek – így egy a történet. Elkészült a novemberi és a decemberi A4-es plakát QR-kóddal (a hírlevél-feliratkozáshoz); a pultnál ugyanez A6-os kártyán is mehet. A webshopos rendelés személyes átvétellel december 24-ig is megoldás.</p></div></div>''', 8))

# 9. Keret + célok
pages.append(page(f'''<div class="kick">Keret és célok</div><h2>Mennyiből, <em>mennyit</em>.</h2>
<div class="cols">
<div><h3>November: 600 000 Ft</h3><table>
<tr><th>Időszak</th><th class="n">Nap</th><th class="n">Ft/nap</th><th class="n">Összesen</th></tr>
<tr><td>nov. 1–12. (fut, a mostani keretekkel)</td><td class="n">12</td><td class="n">≈ 16 700</td><td class="n">≈ 200 000</td></tr>
<tr><td>Füstölő-hét · nov. 13–22.<br><span class="small">Google 9 000 · Meta 7 000 (új 5 000, remarketing 2 000)</span></td><td class="n">10</td><td class="n">16 000</td><td class="n">160 000</td></tr>
<tr><td>Black Week · nov. 23–30.<br><span class="small">Google 17 000 · Meta 13 000 (új 8 000, remarketing 5 000)</span></td><td class="n">8</td><td class="n">30 000</td><td class="n">240 000</td></tr>
<tr><td><b>Összesen</b></td><td></td><td></td><td class="n"><b>600 000</b></td></tr></table>
<h3 style="margin-top:7mm">December: javaslat 700 000 Ft</h3>
<p style="font-size:9pt">December 1–18. a karácsonyi vásárlás csúcsa: napi kb. 36 000 Ft (≈ 650 000 Ft), december 19–24. csak az utalvány és a remarketing (≈ 50 000 Ft). Ha marad a 600 000 Ft, december 1–18. napi kb. 31 000 Ft – a terv ugyanígy működik, kisebb elérésen.</p>
<p class="small">A keret a hirdetési platformoknak (Google, Meta) fizetett összeg.</p></div>
<div><h3>Célok</h3><table>
<tr><th>Mérőszám</th><th class="n">Okt. (tény)</th><th class="n">Nov.</th><th class="n">BW (8 nap)</th><th class="n">Dec. 1–18.</th></tr>
<tr><td>Webes rendelés</td><td class="n">88</td><td class="n">130</td><td class="n">55</td><td class="n">120</td></tr>
<tr><td>Bruttó forgalom</td><td class="n">1,78 M</td><td class="n">2,5 M</td><td class="n">1,1 M</td><td class="n">2,6 M</td></tr>
<tr><td>Átlagos kosár (Ft)</td><td class="n">≈ 20 250</td><td class="n">≥ 19 000</td><td class="n">≥ 20 000</td><td class="n">≥ 22 000</td></tr>
<tr><td>Google ROAS</td><td class="n">4,7*</td><td class="n">≥ 4</td><td class="n">≥ 5</td><td class="n">≥ 4,5</td></tr>
<tr><td>Új hírlevél-feliratkozó</td><td class="n">–</td><td class="n">+150</td><td class="n">+60</td><td class="n">+100</td></tr></table>
<p class="small" style="margin-top:2mm">* Performance Max, okt. 27. – nov. 2. A célok becslések, a november eleji napi 3 rendelésből és a Black Week szokásos 2–3-szoros forgalmából. A novemberi kosár azért lehet kisebb, mert a Füstölő-hét olcsó belépő.</p>
<h3 style="margin-top:6mm">Így mérjük</h3><ul class="clean">
<li>Hétfőnként rövid riport: rendelés, forgalom, kosárérték, ROAS, feliratkozók, a hét tanulsága.</li>
<li>A Black Week alatt naponta ránézünk, és ha kell, a keretet a jobban hozó csatornára tesszük.</li>
<li>Mérés a webshop saját mérésével (GA4, Meta pixel), sütik elfogadása után.</li></ul></div></div>''', 9))

# 10. Vizuális irány
pages.append(page(f'''<div class="kick">Vizuális irány</div><h2>Gyertyafény, füst, <em>kézzel írt</em> címke.</h2>
<div class="cols">
<div><p class="lead" style="font-size:11pt">Nem a szokásos piros-zöld, villogó akció. A Mandala vásárlója nyugalmat keres – a kampány is ilyen: esti fény, valódi termékek, egy szál füst, és egy kézzel írt ajándékcímke, amin az ajánlat áll.</p>
<ul class="clean">
<li><b>Valódi termékek:</b> minden képen a webshop saját terméke (a termékfotóból készült jelenet), kellékként csak fenyőág, szárított narancs, fahéj, len, kraft papír, gyertya.</li>
<li><b>A két füstcsík</b> az 1+1 képe: két füstölő, két füst, egy ár.</li>
<li><b>Az ajándékcímke</b> az aláírás: minden méreten ugyanott viszi az ajánlatot (1+1, −20%, 0 Ft, dec. 18.).</li>
<li><b>Betűk:</b> Cormorant Garamond (cím) és Inter (szöveg) – a webshop saját betűi.</li>
<li><b>Nem lesz:</b> Mikulás-figura, hópehely-clipart, csupa nagybetűs „MEGA AKCIÓ”, neon.</li></ul>
<div class="sw"><div style="background:#16120F"><span style="color:#F7F4EE">Éjszaka<br>#16120F</span></div><div style="background:#F7F4EE;border:1px solid #DDD3C3"><span>Papír<br>#F7F4EE</span></div><div style="background:#A9581A"><span style="color:#F7F4EE">Sáfrány<br>#A9581A</span></div><div style="background:#6B2A2A"><span style="color:#F7F4EE">Bordó<br>#6B2A2A</span></div><div style="background:#D9C29C"><span>Kraft<br>#D9C29C</span></div></div></div>
<div class="gal" style="grid-template-columns:1fr 1fr"><div><img src="../out/web/fust-feed.jpg" alt=""><div class="cap">Hírfolyam 4:5</div></div><div><img src="../out/web/bw-feed.jpg" alt=""><div class="cap">Black Week – sáfrány címke éjszakai háttéren</div></div></div></div>''', 10))

# 11–13. Kreatívok
def gal_page(n, title, items, cols='1fr 1fr 1fr'):
    cells = ''.join(f'<div><img src="../out/web/{f}.jpg" alt=""><div class="cap">{c}</div></div>' for f, c in items)
    return page(f'<div class="kick">Kreatívok</div><h2>{title}</h2><div class="gal" style="grid-template-columns:{cols}">{cells}</div>', n)

pages.append(gal_page(11, 'Füstölő-hét és <em>Black Week</em>', [
    ('fust-story', 'Story / reels 9:16'), ('fust-feed', 'Hírfolyam 4:5'), ('fust-square', 'Négyzet 1:1'),
    ('bw-story', 'Story / reels 9:16'), ('bw-feed', 'Hírfolyam 4:5'), ('bw-square', 'Négyzet 1:1')], '0.72fr 1fr 1fr'))
pages.append(gal_page(12, 'Black Friday, Mikulás, <em>karácsony</em>', [
    ('bf-story', 'Black Friday · story'), ('bf-feed', 'Black Friday · hírfolyam'), ('mikulas-feed', 'Mikulás · hírfolyam'),
    ('mikulas-story', 'Mikulás · story'), ('karacsony-feed', 'Karácsony · hírfolyam'), ('karacsony-square', 'Karácsony · négyzet')], '0.72fr 1fr 1fr'))
pages.append(page(f'''<div class="kick">Kreatívok</div><h2>Fekvő formátum, hírlevél, <em>utalvány</em>.</h2>
<div class="gal" style="grid-template-columns:1fr 1fr">
<div><img src="../out/web/fust-land.jpg" alt=""><div class="cap">Google Display / Performance Max 1200×628 – Füstölő-hét</div></div>
<div><img src="../out/web/bw-land.jpg" alt=""><div class="cap">1200×628 – Black Week</div></div>
<div><img src="../out/web/mikulas-email.jpg" alt=""><div class="cap">Hírlevél-fejléc 1200×600 – Mikulás</div></div>
<div><img src="../out/web/karacsony-email.jpg" alt=""><div class="cap">Hírlevél-fejléc 1200×600 – Karácsony</div></div>
</div>
<div class="gal" style="grid-template-columns:0.62fr 1fr 1fr;margin-top:5mm">
<div><img src="../out/web/utalvany-story.jpg" alt=""><div class="cap">Utalvány · story</div></div>
<div><img src="../out/web/utalvany-feed.jpg" alt=""><div class="cap">Utalvány · hírfolyam</div></div>
<div><img src="../out/web/plakat-A4-november.jpg" alt=""><div class="cap">Bemutatóterem · A4 plakát (november; a decemberi a csomagban)</div></div></div>''', 13))

# 14. Szövegek
def meta_rows():
    out = []
    names = {'fust': 'Füstölő-hét', 'bw': 'Black Week', 'bf': 'Black Friday', 'mikulas': 'Mikulás', 'karacsony': 'Karácsony', 'utalvany': 'Utalvány'}
    for k, m in META.items():
        out.append(f'<tr><td><b>{names[k]}</b></td><td>{"<br>".join("„" + x + "”" for x in m["primary"][:2])}<br><span class="small">Cím: {" · ".join(m["headline"])} — Leírás: {m["desc"][0]}</span></td></tr>')
    return ''.join(out)

pages.append(page(f'''<div class="kick">Szövegek</div><h2>Hirdetésszövegek.</h2>
<h3>Meta – elsődleges szöveg, cím, leírás</h3><table class="copy">{meta_rows()}</table>
<div class="cols" style="margin-top:6mm"><div><h3>Google – címek (≤ 30 karakter)</h3><ul class="clean" style="columns:2;column-gap:5mm;font-size:8.6pt">{''.join(f'<li>{h} <span class="small">{len(h)}</span></li>' for h in GOOGLE_HEAD)}</ul></div>
<div><h3>Google – leírások (≤ 90 karakter)</h3><ul class="clean" style="font-size:8.6pt">{''.join(f'<li>{h} <span class="small">{len(h)}</span></li>' for h in GOOGLE_DESC)}</ul>
</div></div>''', 14))


ban = ''.join(f'<tr><td>{a}</td><td><b>{b}</b><br>{c}<br><span class="small">Gomb: {d}</span></td></tr>' for a, b, c, d in BANNER)
cart = ''.join(f'<tr><td>{a}</td><td>„{b}”</td></tr>' for a, b in CART)
mails = ''.join(f'<tr><td class="ch">{e[0]}</td><td><b>A:</b> {e[2]}<br><b>B:</b> {e[3]}<br><span class="small">Előfejléc: {e[4]}</span></td></tr>' for e in EMAILS)
soc = ''.join(f'<tr><td>{a}</td><td><b>{b}</b><br><span class="small">{c}</span></td></tr>' for a, b, c in SOCIAL)
pages.append(page(f'''<div class="kick">Szövegek</div><h2>A webshop szövegei.</h2>
<div class="cols"><div><h3>Felső sáv</h3><table class="copy">{tb}</table>
<h3 style="margin-top:6mm">Kosár-üzenetek</h3><table class="copy">{cart}</table></div>
<div><h3>Főoldali banner</h3><table class="copy">{ban}</table></div></div>''', 15))
pages.append(page(f'''<div class="kick">Szövegek</div><h2>Hírlevél és <em>posztok</em>.</h2>
<table class="copy tight"><tr><th>Mikor</th><th>Tárgy (A / B)</th><th>Előfejléc</th></tr>{''.join(f'<tr><td class="ch">{e[0]}</td><td style="width:70mm"><b>A:</b> {e[2]}<br><b>B:</b> {e[3]}</td><td><span class="small">{e[4]}</span></td></tr>' for e in EMAILS)}</table>
<h3 style="margin-top:4mm">Közösségi posztok</h3><table class="copy tight">{soc}</table>
<p class="small" style="margin-top:2mm">A szövegek kiindulópontok: a saját hangotokon írjátok át nyugodtan. A termékárak 2026. november 6-iak.</p>''', 16))

# 17. Teendők + zárás
pages.append(page(f'''<div class="kick">Teendők</div><h2>Ki, mit, <em>mikorra</em>.</h2>
<table class="tight"><tr><th>Mit</th><th>Ki</th><th>Határidő</th></tr>{''.join(f'<tr><td>{a}</td><td>{b}</td><td class="ch">{c}</td></tr>' for a, b, c in TASKS)}</table>
<div class="cols" style="margin-top:6mm"><div class="box"><h4>Ha jóváhagyjátok</h4><p style="margin:0">November 12-ig minden beállítás elkészül a webshopban és a hirdetésekben, és november 13-án, pénteken reggel indul a Füstölő-hét. Ha a mértékeken vagy a dátumokon változtatnátok, szóljatok, és átírjuk a szövegeket és a képeket.</p></div>
<div><h4>A csomagban</h4><p class="small" style="margin:0">Ez a terv (PDF); minden hirdetési kép a négy méretben (PNG); a két A4-es plakát nyomtatható PDF-ben; a szövegek külön fájlban, másolható formában (hirdetések, felső sáv, banner, kosár-üzenetek, hírlevelek, posztok).</p></div></div>
<div class="sig">Áron</div><p class="small">HelloProVision · Design · Build · Grow</p>''', 17))

html = f'<!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Mandala – karácsonyi kampányterv 2026</title><style>{CSS}</style></head><body>{"".join(pages)}</body></html>'
open(os.path.join(B, 'strategy.html'), 'w').write(html)
json.dump([{'html': 'strategy.html', 'pdf': '../out/Mandala-karacsonyi-kampanyterv-2026.pdf', 'w': 794, 'h': 1123}], open(os.path.join(B, 'jobs-pdf.json'), 'w'))
print(len(pages), 'oldal')
