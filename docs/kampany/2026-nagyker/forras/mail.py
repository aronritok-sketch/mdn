# Mandala partnerlevél, 2026. november 11. – „Nyitott raktár” (viszonteladóknak, MailerLite „Viszonteladók” csoport).
# A korábbi partnerlevelek generátorára épül (_mailbase.py = gen_partner_nov.py): ugyanaz a fej, lábléc, gomb, sötét mód,
# nem törhető számok; a tartalmi szakaszokat itt cseréljük. Kimenet: ../out/levelek/partnerlevel-nov11.html + .txt
# A fejléckép a téma hirdetéskép-mappájából töltődik (assets/ads/) – a következő téma-kiadással kerül ki.
import os
import _mailbase as g
from texts import MAIL1, SUBJECTS, RULES, DOTS, STOCK, STOCK_DATE, STOCK_TOTAL, PHONE, HOURS

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'out', 'levelek') + '/'
g.OUT, g.NAME, g.CAMPAIGN = OUT, 'partnerlevel-nov11', '2026-11-nyitott-raktar'
g.UTM = 'utm_source=newsletter&utm_medium=email&utm_campaign=' + g.CAMPAIGN
g.TITLE = SUBJECTS[0][1]
g.PREHEADER = SUBJECTS[0][2]
HERO = g.ADS + 'mandala-nyitott-raktar-1200x600.jpg'
P, H2, INK, MUTED, DEEP, LINE, SAND, WHITE, PILL = g.P, g.H2, g.INK, g.MUTED, g.DEEP, g.LINE, g.SAND, g.WHITE, g.PILL
esc, btn, section, eyebrow, TABLE, h = g.esc, g.btn, g.section, g.eyebrow, g.TABLE, g.h
TOTAL = f'{STOCK_TOTAL:,}'.replace(',', ' ')


def intro():
    img = (f'<tr><td class="bg-paper" style="padding:0;background-color:{g.PAPER};"><a href="{h("/viszonteladoknak/", "fejlec")}" style="text-decoration:none;">'
           f'<img src="{HERO}" width="600" height="300" alt="Nyitott raktár: szobrok, hangtálak és füstölők a polcon, mellettük a zöld −10%, sárga −30% és piros −50% pötty" '
           f'style="display:block;width:100%;max-width:600px;height:auto;border:0;background-color:{SAND};color:{MUTED};font-family:Arial,sans-serif;font-size:14px;"></a></td></tr>')
    body = (
        eyebrow('Viszonteladóknak · november 11. – december 18.', margin='0 0 14px')
        + f'<h1 class="h1 t-ink" style="margin:0 0 20px;font-family:{g.SERIF};font-size:40px;line-height:1.1;font-weight:500;letter-spacing:-0.015em;color:{INK};">'
          f'Nyitott raktár: <em class="t-accent" style="font-style:italic;color:{DEEP};">a készlet 80%-a pöttyöt kapott</em></h1>'
        + f'<p class="t-ink" style="{P}">Kedves {{$name|default(\'Partnerünk\')}}!</p>'
        + f'<p class="t-ink" style="{P}">{esc(MAIL1["intro"][0])}</p>'
        + f'<p class="t-ink" style="{P}margin-bottom:28px;">{esc(MAIL1["intro"][1])}</p>'
        + btn('Hívj minket: ' + PHONE, g.PHONE_HREF, 310, aria='Hívás: ' + PHONE)
        + f'<p class="t-muted" style="margin:16px 0 0;{g.SMALL}font-size:14px;color:{MUTED};">Inkább írnál? '
          f'<a class="link t-link" href="mailto:{g.EMAIL}" style="{g.LINK}">{g.EMAIL}</a></p>'
    )
    return img + section(body, pad='36px 40px 40px')


def voucher():
    dots = ''
    for d in DOTS:
        dots += (f'<tr><td width="64" valign="middle" style="width:64px;padding:0 16px 14px 0;">'
                 f'<div style="width:56px;height:56px;line-height:56px;border-radius:28px;background-color:{d["color"]};color:{d["ink"]};text-align:center;'
                 f'font-family:{g.SANS};font-size:19px;font-weight:700;letter-spacing:-0.03em;">−{d["pct"]}%</div></td>'
                 f'<td valign="middle" style="padding:0 0 14px;"><p class="t-ink" style="margin:0;font-family:{g.SANS};font-size:16px;line-height:1.45;color:{INK};">'
                 f'<strong>{esc(d["name"])}:</strong> {esc(d["text"])}</p></td></tr>')
    dots += (f'<tr><td colspan="2" style="padding:2px 0 0;"><p class="t-muted" style="margin:0;font-family:{g.SANS};font-size:14px;line-height:1.5;color:{MUTED};">'
             'Pötty nélkül: a megszokott nagyker ár – az újdonságok és a legkeresettebb tételek.</p></td></tr>')
    rows = ''
    for i, (label, value) in enumerate(RULES):
        last = i == len(RULES) - 1
        border = '' if i == 0 else f'border-top:1px solid {LINE};'
        rows += (f'<tr><td class="bd-line" style="padding:{"0" if i == 0 else "14px"} 0 {"0" if last else "14px"};{border}">'
                 f'<p class="t-muted" style="margin:0 0 3px;font-family:{g.SANS};font-size:12px;line-height:1.4;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:{MUTED};">{esc(label)}</p>'
                 f'<p class="t-ink" style="margin:0;font-family:{g.SANS};font-size:16px;line-height:1.5;color:{INK};">{esc(value)}</p></td></tr>')
    card = (f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-white bd-card" '
            f'style="background-color:{WHITE};border:1.5px dashed {PILL};border-radius:6px;border-collapse:separate;">'
            f'<tr><td class="card-pad" style="padding:26px 28px 18px;">{TABLE}{dots}</table></td></tr>'
            f'<tr><td style="padding:0 28px;" class="card-pad-x"><div class="bd-dash" style="height:0;line-height:0;font-size:0;border-top:1.5px dashed {PILL};">&nbsp;</div></td></tr>'
            f'<tr><td class="card-pad" style="padding:20px 28px 24px;">{TABLE}{rows}</table></td></tr></table>')
    body = eyebrow('A pöttyök és a feltételek') + f'<h2 class="h2 t-ink" style="{H2}margin-bottom:22px;">A pötty színe a kedvezmény</h2>' + card
    return section(body, bg=SAND, cls='bg-sand', pad='40px 40px 44px')


def arrivals():
    li = ''.join(f'<tr><td class="bd-line t-ink" style="padding:9px 0;border-top:1px solid {LINE};font-family:{g.SANS};font-size:15px;line-height:1.4;color:{INK};">{esc(k)}</td>'
                 f'<td class="bd-line t-ink" align="right" style="padding:9px 0;border-top:1px solid {LINE};font-family:{g.SANS};font-size:15px;line-height:1.4;color:{INK};">{v}</td></tr>' for k, v in STOCK)
    body = (
        eyebrow('Karácsony előtt')
        + f'<h2 class="h2 t-ink" style="{H2}">Ami most a polcodra kerül, <em class="t-accent" style="font-style:italic;color:{DEEP};">az még elkel</em></h2>'
        + f'<p class="t-ink" style="{P}">{esc(MAIL1["blocks"][2][1][0])}</p>'
        + f'<p class="t-ink" style="{P}margin-bottom:18px;">A webshopban {STOCK_DATE} {TOTAL} termék volt raktáron. '
          + 'Belépve előre megnézheted, mit keresel, a nagyker árakkal – a pötty szerinti kedvezményt a bemutatóteremben adjuk.</p>'
        + f'{TABLE}{li}</table>'
        + '<div style="height:26px;line-height:26px;font-size:0;">&nbsp;</div>'
        + btn('Belépek, és előre körülnézek', h(g.LOGIN, 'belepes-gomb'), 320)
        + f'<p class="t-muted" style="margin:16px 0 0;{g.SMALL}font-size:14px;color:{MUTED};">Elfelejtetted a jelszavad? '
          f'<a class="link t-link" href="{h(g.LOST, "jelszo")}" style="{g.LINK}">Itt kérhetsz újat.</a> Ha nem érkezik meg a levél, hívj minket.</p>'
    )
    return section(body, pad='44px 40px 40px')


def phone_cta():
    body = (
        eyebrow('Mielőtt elindulsz', cls='', color=g.NIGHT_HI)
        + f'<h2 class="h2" style="{H2.replace(INK, g.PAPER)}margin-bottom:18px;">Hívj, és <em style="font-style:italic;color:{g.NIGHT_HI};">félretesszük</em></h2>'
        + f'<p style="margin:0 0 18px;"><a class="phone-big serif" href="{g.PHONE_HREF}" aria-label="Hívás: {PHONE}" '
          f'style="font-family:{g.SERIF};font-size:46px;line-height:1.05;font-weight:500;letter-spacing:-0.01em;color:{g.PAPER};text-decoration:none;white-space:nowrap;">{PHONE}</a></p>'
        + f'<p style="margin:0 0 28px;font-family:{g.SANS};font-size:16px;line-height:1.6;color:{g.NIGHT_TEXT};">Elmondjuk, mi van pöttyön abból, amit keresel, '
          'és félretesszük, amit kiválasztottál: 3 munkanapig vár rád.</p>'
        + btn('Felhívom a Mandalát', g.PHONE_HREF, 250, dark=False, aria='Hívás: ' + PHONE)
    )
    return section(body, bg=g.NIGHT, cls='bg-night', pad='44px 40px 44px')


g.intro, g.voucher, g.arrivals, g.phone_cta = intro, voucher, arrivals, phone_cta


def text_version():
    R = '-' * 60
    u = g.u
    L = ['Mandala · Partnerlevél', 'Viszonteladóknak · november 11. – december 18.', '', 'Nyitott raktár: a készlet 80%-a pöttyöt kapott', '',
         "Kedves {$name|default('Partnerünk')}!", '', MAIL1['intro'][0], '', MAIL1['intro'][1], '', f'Hívj minket: {PHONE}', f'Inkább írnál? {g.EMAIL}', '', '',
         R, 'A pötty színe a kedvezmény', R, '']
    L += [f'{d["name"]}: {d["text"]}' for d in DOTS] + ['Pötty nélkül: a megszokott nagyker ár – az újdonságok és a legkeresettebb tételek.', '']
    L += [f'{k}: {v}' for k, v in RULES]
    L += ['', '', R, 'Karácsony előtt: ami most a polcodra kerül, az még elkel', R, '', MAIL1['blocks'][2][1][0], '',
          f'A webshopban {STOCK_DATE} {TOTAL} termék volt raktáron.' + ' Belépve előre megnézheted, mit keresel, a nagyker árakkal – '
          'a pötty szerinti kedvezményt a bemutatóteremben adjuk.', '']
    L += [f'{k}: {v}' for k, v in STOCK]
    L += ['', 'Belépek, és előre körülnézek:', u(g.LOGIN, 'belepes-gomb'), '', 'Elfelejtetted a jelszavad? Itt kérhetsz újat:', u(g.LOST, 'jelszo'),
          f'Ha nem érkezik meg a levél, hívj minket: {PHONE}.', '', '', R, 'Mielőtt elindulsz: hívj, és félretesszük', R, '', f'Telefon: {PHONE}', '',
          'Elmondjuk, mi van pöttyön abból, amit keresel, és félretesszük, amit kiválasztottál: 3 munkanapig vár rád.', '', '',
          R, 'Bemutatóterem és átvevőhely – itt találsz minket', R, '', f'Cím: {g.ADDRESS}', 'A hangtálakat előzetes egyeztetéssel meg is hallgathatod.', '',
          'Nyitvatartás: ' + HOURS[0].lower() + HOURS[1:], '', 'Térkép a Kapcsolat oldalon:', u(g.KAPCSOLAT, 'kapcsolat'), '', 'Szeretettel:', 'a Mandala csapata', '', '',
          R, 'Mandala – hangtálak, füstölők és szakrális tárgyak Nepálból és Indiából', 'Asita Cult Kft. · 1093 Budapest, Bakáts u. 6. · Adószám: 12587128-2-43',
          f'{g.EMAIL} · {PHONE} · {u("/", "lablec")}', '', 'Azért kapod ezt a levelet, mert viszonteladói fiókod van a mandala.hu-n, és feliratkoztál a hírlevelünkre.', '',
          'Leiratkozás: {$unsubscribe}', 'Megnyitás böngészőben: {$url}', 'Adatkezelési tájékoztató: ' + u(g.ADATKEZELES, 'adatkezeles'), '']
    return '\n'.join(L)


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    doc = g.document()
    open(OUT + g.NAME + '.html', 'w', encoding='utf-8').write(doc)
    open(OUT + g.NAME + '.txt', 'w', encoding='utf-8').write(text_version())
    print(g.NAME + '.html', len(doc.encode('utf-8')), 'bytes')
