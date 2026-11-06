# Mandala karácsony 2026 – a kampány hírlevelei (MailerLite, vásárlói lista). Kimenet: ../hirlevelek/<n>-<kulcs>.html + .txt
# A levelek a bevált levélalapra épülnek (_mailbase.py: fej, gomb, sötét mód, nem törhető számok); itt a tartalom.
# A fejlécképek a téma hirdetéskép-mappájából töltődnek (wp-theme/mandala/assets/ads/mandala-xmas2026-*.jpg).
# Termékadatok: az élő Google-feedből (név, link, kép) a cikkszám alapján, a generáláskor. A hangtálak egyedi darabok:
# ha elfogytak, a generálás jelzi – cseréld egy másikra a LETTERS listában, és generáld újra.
import html, os, re, sys, urllib.request
import xml.etree.ElementTree as ET
import _mailbase as g

B = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(B, '..', 'hirlevelek') + '/'
P, H2, INK, MUTED, DEEP, LINE, SAND, WHITE = g.P, g.H2, g.INK, g.MUTED, g.DEEP, g.LINE, g.SAND, g.WHITE
esc, btn, section, eyebrow, TABLE = g.esc, g.btn, g.section, g.eyebrow, g.TABLE
UTMC = '2026-karacsony'

# ---- termékek a feedből
_feed = {}


def feed():
    if not _feed:
        ns = {'g': 'http://base.google.com/ns/1.0'}
        with urllib.request.urlopen('https://mandala.hu/?mandala_feed=google', timeout=120) as r:
            root = ET.fromstring(r.read())
        for it in root.findall('.//item'):
            _feed[it.findtext('g:id', namespaces=ns)] = dict(name=it.findtext('title'), url=it.findtext('link'), img=it.findtext('g:image_link', namespaces=ns),
                                                             cat=(it.findtext('g:product_type', namespaces=ns) or '').split(' > ')[-1],
                                                             price=int(float((it.findtext('g:price', namespaces=ns) or '0').split()[0])),
                                                             stock=it.findtext('g:availability', namespaces=ns))
    return _feed


def thumb(url):
    t = re.sub(r'(\.\w+)$', r'-600x600\1', url)
    try:
        req = urllib.request.Request(t, method='HEAD')
        with urllib.request.urlopen(req, timeout=20) as r:
            return t if r.status == 200 else url
    except Exception:
        return url


def tile(sku, text, key, price=False, cat=None):
    f = feed().get(sku)
    if not f:
        sys.exit(f'Nincs a feedben: {sku}')
    if f['stock'] != 'in_stock':
        print('FIGYELEM, elfogyott:', sku, f['name'])
    price_html = ('<br><strong>' + f'{f["price"]:,}'.replace(',', '&nbsp;') + '&nbsp;Ft</strong>') if price else ''
    return dict(cat=cat or f['cat'], name=f['name'], text=esc(text) + price_html,
                url=f['url'].replace('https://mandala.hu', ''), link='Megnézem', key=key, img=thumb(f['img']), alt=f['name'])


# ---- a levelek
def L(**k):
    return k


LETTERS = [
    L(n=1, key='fustolo-het', when='nov. 13. (péntek) 8:00', subj='Egyet fizetsz, kettőt viszel – minden füstölőre', pre='Nag Champa, Palo Santo, fehér zsálya és még több mint 400 illat. November 22-ig.',
      hero='fustolo-het', kick='Füstölő-hét · november 22-ig', title='Egyet fizetsz, <em>kettőt viszel</em>',
      body=['Egy héten át minden füstölőre: két csomagból az olcsóbbat mi álljuk. Vegyes illatokkal is – a kosárban magától levonódik, kód nélkül.',
            'Ha nem tudod, melyiket válaszd, itt van négy kedvencünk, és hogy mire jók.'],
      cta=('A füstölők', '/kategoria/szakralis-targyak/fustolok/'),
      tiles=[('8904234400037', 'Az elcsendesedéshez: édes, púderes, meleg – a jógaórák klasszikusa.'), ('8904245400989', 'A lakás frissítéséhez: fás, citrusos, tiszta.'),
             ('8904245400415', 'Az új kezdethez: gyógynövényes, friss.'), ('8904245400163', 'Az esti nyugalomhoz: krémes, fás, megnyugtató.')],
      tiles_title='Négy illat, négy pillanat',
      info=('Így működik', ['Tegyél a kosárba legalább két füstölőt (lehet különböző).', 'Minden párból az olcsóbb 0 Ft – négy csomagból kettő, hatból három.', 'Kóddal nem adódik össze, és november 22-én éjfélig tart.'])),
    L(n=2, key='fustolo-het-vege', when='nov. 20. (péntek) 17:00 – választható', subj='Vasárnap éjfélig tart az 1+1', pre='Az egyet fizetsz, kettőt viszel vasárnap véget ér. Hétfőn indul a Black Week.',
      hero='fustolo-het', kick='Még egy hétvége', title='Vasárnap éjfélig <em>1+1</em>',
      body=['Az egyet fizetsz, kettőt viszel minden füstölőre vasárnap éjfélig tart.',
            'Hétfőn pedig indul a Black Week: szinte mindenre 20% kedvezmény, a hangtálakra 10% – november 30-ig. Hétfő reggel írunk.'],
      cta=('A füstölők', '/kategoria/szakralis-targyak/fustolok/'), tiles=[], info=None),
    L(n=3, key='black-week', when='nov. 23. (hétfő) 8:00', subj='Black Week: szinte mindenre 20%', pre='A hangtálakra 10%, a füstölőkre marad az egyet fizetsz, kettőt viszel. November 30-ig.',
      hero='black-week', kick='Black Week · november 30-ig', title='Szinte mindenre <em>20%</em>',
      body=['Indul a Black Week: november 30-ig szinte mindenre 20% kedvezmény, a hangtálakra 10%, a füstölőkre marad az egyet fizetsz, kettőt viszel.',
            'Az ár a termékoldalon már a kedvezményes, áthúzva mellette az eredeti – kód nem kell. Ha karácsonyra keresel valami különlegeset, most érdemes.'],
      cta=('Black Week ajánlatok', '/termekek/?allapot=akcios'),
      tiles=[('9990000049080', 'Hét fémből készült tál, mért hanggal és frekvenciával – a hangtálakra most 10%.'), ('9990000050628', 'Hét csakrakő, rózsakvarc gyöngyök.'), ('9990000024919', 'Shakyamuni Buddha, öntött réz.'),
             ('9990000005796', 'Lótuszvirág, arany színben – a hamu szépen összegyűlik benne.'), ('9990000043224', 'Indás virágminta, puha, meleg sál.'), ('8904234407777', 'Szantálfa olaj, pár csepp a párologtatóba.')],
      tiles_title='Karácsonyra is gondolj',
      info=('Tudnivalók', ['A kedvezmény magától levonódik, kóddal nem adódik össze.', 'Nem jár az ajándékutalványra és a már akciós termékekre.', 'Pénteken, november 27-én a szállítás is ingyenes.'])),
    L(n=4, key='black-friday', when='nov. 27. (péntek) 8:00', subj='Ma a szállítás ránk jön', pre='Black Friday: ma éjfélig bármekkora rendelésnél ingyenes a szállítás, a Black Week kedvezményei mellé.',
      hero='black-friday', kick='Black Friday · csak ma', title='Ma a szállítás <em>ránk jön</em>',
      body=['Csak ma éjfélig: bármekkora rendelésnél ingyenes a szállítás – a Black Week kedvezményei mellé.',
            'Szinte mindenre 20%, a hangtálakra 10%, a füstölőkre egyet fizetsz, kettőt viszel.'],
      cta=('Black Week ajánlatok', '/termekek/?allapot=akcios'), tiles=[], info=None),
    L(n=5, key='black-week-utolso-nap', when='nov. 30. (hétfő) 14:00', subj='Ma éjfélig tart a Black Week', pre='Éjfél után visszaállnak az árak – decemberben nem lesz százalékos akció.',
      hero='black-week', kick='Utolsó nap', title='Ma éjfélig <em>Black Week</em>',
      body=['Ma éjfélig tart a Black Week: szinte mindenre 20%, a hangtálakra 10%, a füstölőkre egyet fizetsz, kettőt viszel.',
            'Éjfél után visszaállnak az árak, és decemberben már nem lesz százalékos akció. Ha karácsonyra már tudod, mit vennél, ma érdemes.'],
      cta=('Black Week ajánlatok', '/termekek/?allapot=akcios'), tiles=[], info=None),
    L(n=6, key='mikulas', when='dec. 2. (szerda) 8:00', subj='Mikulásra csütörtök délig rendelj', pre='Díszcsomagolásban, kézzel írt kártyával küldjük – ez most nem kerül semmibe.',
      hero='mikulas', kick='Mikulás · rendelj december 3-án 12 óráig', title='Ajándék <em>a csizmába</em>',
      body=['Mikulásra holnap, csütörtökön 12 óráig rendelj: díszcsomagolásban, kézzel írt kártyával küldjük – ez most nem kerül semmibe. A pénztárban kérheted, és ott írhatod meg a kártya szövegét is.',
            '15 000 Ft felett egy füstölőt is teszünk a csomagba. Íme öt apró ajándék 5 000 Ft alatt:'],
      cta=('Apró ajándékok', '/termekek/?szandek=ajandek'),
      tiles=[('9990000050864', 'Aki most kezd jógázni: hét csakrakő, a szív köve középen.'), ('9990000050857', 'Illatos szantálfa gyöngyök, meditációhoz és mindennapra.'),
             ('8902276202916', 'Aki mindent megkapott már: pár csepp, és a szoba illata megváltozik.'), ('9990000013128', 'Aki minden este gyújt egyet: faragott lótuszvirág.'),
             ('9990000049424', 'A kassza mellé, a csizmába: piros zsinór, kínai szerencsepénz.')],
      tiles_title='5 apró ajándék 5 000 Ft alatt', price=True,
      info=('Határidő', ['Mikulásra: december 3. (csütörtök) 12:00-ig beérkezett rendelés.', 'Személyesen is átveheted a bemutatótermünkben: 1093 Budapest, Bakáts u. 6.'])),
    L(n=7, key='karacsony-kinek-mit', when='dec. 9. (szerda) 8:00', subj='Ajándék, ami jelent is valamit', pre='Kinek mit? Ötletek anyának, barátnak, jógázónak – díszcsomagolással, kézzel írt kártyával.',
      hero='karacsony', kick='Karácsony · rendelj december 18-án 12 óráig', title='Ajándék, ami <em>jelent is valamit</em>',
      body=['Kinek mit? Összegyűjtöttük, mit adnánk mi – mindet díszcsomagolásban, kézzel írt kártyával, ajándékba. 15 000 Ft felett egy füstölő is a csomagba kerül.',
            'Ami december 18-án, pénteken 12 óráig beérkezik, karácsonyig odaér.'],
      cta=('Ajándékötletek', '/termekek/?szandek=ajandek'),
      tiles=[('9990000050864', 'Anyának: rózsakvarc, a szeretet köve.', 'Anyának'), ('9990000042289', 'Anyának: tiszta vörösréz, egy pohár víz reggel.', 'Anyának'),
             ('8902276202916', 'Barátnak: Palo Santo, a „szent fa” illata.', 'Barátnak'), ('9990000013128', 'Barátnak: faragott lótusz, minden estére.', 'Barátnak'),
             ('9990000050857', 'Jógázónak: szantálfa mala, 108 gyöngy helyett kézre.', 'Jógázónak'), ('9990000049110', 'Jógázónak: egy tál, egy hang, egy perc csend.', 'Jógázónak')],
      tiles_title='Kinek mit?',
      info=('Aki mindent megkapott már', ['Az ajándékutalvány e-mailben érkezik, kinyomtatható, és ő választ a teljes kínálatból. 5 000 Ft-tól 30 000 Ft-ig, egy évig érvényes.'])),
    L(n=8, key='karacsony-hatarido', when='dec. 16. (szerda) 8:00', subj='Karácsonyra péntek délig rendelj', pre='Ami december 18-án 12 óráig beérkezik, karácsonyig nálad van.',
      hero='karacsony', kick='Még két nap', title='Péntek délig, és <em>odaér</em>',
      body=['Ami december 18-án, pénteken 12 óráig beérkezik, karácsonyig nálad van. A díszcsomagolás és a kézzel írt kártya ajándék, 15 000 Ft felett egy füstölő is.',
            'Ha nem tudod, mit válassz: az ajándékutalvány e-mailben érkezik, és ő választ.'],
      cta=('Ajándékötletek', '/termekek/?szandek=ajandek'), tiles=[], info=None),
    L(n=9, key='utalvany', when='dec. 20. (vasárnap) 10:00 – választható', subj='Ha már nincs idő: ajándékutalvány', pre='E-mailben érkezik, nyomtatható is – ő választ a teljes kínálatból.',
      hero='utalvany', kick='Az utolsó napokban', title='Utolsó pillanat? <em>Utalvány.</em>',
      body=['Az ajándékutalvány e-mailben érkezik, kinyomtatható, és ő választ a teljes kínálatból. 5 000 Ft-tól 30 000 Ft-ig, egy évig érvényes, saját üzenettel.',
            'Vagy rendelj, és vedd át személyesen a bemutatótermünkben: 1093 Budapest, Bakáts u. 6. (hétfőn 9–17, keddtől péntekig 9–15).'],
      cta=('Ajándékutalvány', '/ajandekcsomag/'), tiles=[], info=None),
]


def build(l):
    g.NAME, g.CAMPAIGN = f'{l["n"]}-{l["key"]}', UTMC
    g.UTM = 'utm_source=newsletter&utm_medium=email&utm_campaign=' + UTMC
    g.TITLE, g.PREHEADER = l['subj'], l['pre']
    hero = g.ADS + f'mandala-xmas2026-{l["hero"]}-1200x600.jpg'
    tiles = []
    for t in l['tiles']:
        sku, text = t[0], t[1]
        tiles.append(tile(sku, text, f'{l["key"]}-{sku}', price=l.get('price', False), cat=t[2] if len(t) > 2 else None))

    def header():
        return g.__dict__['_orig_header']().replace('>Partnerlevél<', '>Hírlevél<')

    def intro():
        img = (f'<tr><td class="bg-paper" style="padding:0;background-color:{g.PAPER};"><a href="{g.h(l["cta"][1], "fejlec")}" style="text-decoration:none;">'
               f'<img src="{hero}" width="600" height="300" alt="{g.attr(re.sub("<[^>]+>", "", l["title"]))}" style="display:block;width:100%;max-width:600px;height:auto;border:0;background-color:{SAND};color:{MUTED};font-family:Arial,sans-serif;font-size:14px;"></a></td></tr>')
        title = l['title'].replace('<em>', f'<em class="t-accent" style="font-style:italic;color:{DEEP};">')
        body = (eyebrow(l['kick'], margin='0 0 14px')
                + f'<h1 class="h1 t-ink" style="margin:0 0 20px;font-family:{g.SERIF};font-size:40px;line-height:1.1;font-weight:500;letter-spacing:-0.015em;color:{INK};">{title}</h1>'
                + ''.join(f'<p class="t-ink" style="{P}">{esc(p)}</p>' for p in l['body'])
                + '<div style="height:12px;line-height:12px;font-size:0;">&nbsp;</div>' + btn(l['cta'][0], g.h(l['cta'][1], 'gomb'), 300))
        return img + section(body, pad='36px 40px 40px')

    def voucher():
        if not l['info']:
            return ''
        t, items = l['info']
        rows = ''.join(f'<tr><td class="bd-line" style="padding:12px 0;border-top:1px solid {LINE};"><p class="t-ink" style="margin:0;font-family:{g.SANS};font-size:16px;line-height:1.5;color:{INK};">{esc(x)}</p></td></tr>' for x in items)
        return section(eyebrow(t) + f'{TABLE}{rows}</table>', bg=SAND, cls='bg-sand', pad='36px 40px 36px')

    def arrivals():
        if not tiles:
            return ''
        grid = ''
        for i in range(0, len(tiles), 2):
            a = g.tile(tiles[i], True)
            b = g.tile(tiles[i + 1], False) if i + 1 < len(tiles) else '<td class="colgap-r" width="50%" style="width:50%;"></td>'
            grid += '<tr>' + a + b + '</tr>'
        body = (eyebrow(l['kick'].split(' · ')[0]) + f'<h2 class="h2 t-ink" style="{H2}margin-bottom:22px;">{esc(l["tiles_title"])}</h2>' + f'{TABLE}{grid}</table>'
                + btn(l['cta'][0], g.h(l['cta'][1], 'gomb-2'), 300))
        return section(body, pad='44px 40px 40px')

    def phone_cta():
        return ''

    def footer():
        return g.__dict__['_orig_footer']().replace('Azért kapod ezt a levelet, mert viszonteladói fiókod van a mandala.hu-n, és feliratkoztál a hírlevelünkre.',
                                                    'Azért kapod ezt a levelet, mert feliratkoztál a Mandala hírlevelére.')

    g.header, g.intro, g.voucher, g.arrivals, g.phone_cta, g.footer = header, intro, voucher, arrivals, phone_cta, footer
    doc = g.document()
    txt = [g.TITLE, '', re.sub('<[^>]+>', '', l['title']), '', *l['body'], '', f'{l["cta"][0]}: {g.u(l["cta"][1], "gomb")}', '']
    for t in tiles:
        txt += [f'{t["name"]} – {html.unescape(re.sub("<[^>]+>", " ", t["text"])).strip()}', g.u(t['url'], t['key']), '']
    if l['info']:
        txt += [l['info'][0], *[f'– {x}' for x in l['info'][1]], '']
    txt += ['Szeretettel:', 'a Mandala csapata', '', 'Mandala – 1093 Budapest, Bakáts u. 6. · info@mandala.hu · +36 30 892 8385', '',
            'Azért kapod ezt a levelet, mert feliratkoztál a Mandala hírlevelére.', 'Leiratkozás: {$unsubscribe}', 'Megnyitás böngészőben: {$url}']
    return doc, '\n'.join(txt)


if __name__ == '__main__':
    g.__dict__['_orig_header'] = g.header
    g.__dict__['_orig_footer'] = g.footer
    os.makedirs(OUT, exist_ok=True)
    idx = ['# Hírlevelek – karácsonyi kampány 2026 (MailerLite, vásárlói lista)', '',
           'A HTML-t a MailerLite „Saját HTML” szerkesztőjébe kell bemásolni. Az előnézeti szöveg a levél elején, rejtve benne van,',
           'ezért a MailerLite saját előnézeti mezője maradjon üres. Előbb próbalevél magadnak, aztán időzítés.',
           'A termékek nevei és képei az élő webshopból jönnek (generálás: `forras/xmas_mail.py`).',
           'Közvetlenül küldés előtt nézd meg, hogy a termékek raktáron vannak-e.', '',
           '| # | Mikor | Tárgy | Előnézeti szöveg | Fájl |', '|---|---|---|---|---|']
    for l in LETTERS:
        doc, txt = build(l)
        name = f'{l["n"]}-{l["key"]}'
        open(OUT + name + '.html', 'w', encoding='utf-8').write(doc)
        open(OUT + name + '.txt', 'w', encoding='utf-8').write(txt)
        idx.append(f'| {l["n"]} | {l["when"]} | {l["subj"]} | {l["pre"]} | `{name}.html` |')
        print(name, len(doc.encode()), 'bytes')
    open(OUT + 'README.md', 'w', encoding='utf-8').write('\n'.join(idx) + '\n')
