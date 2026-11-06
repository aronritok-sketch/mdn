# Mandala partnerlevél (viszonteladóknak), 2026. október vége – „Személyes vásárlás −10% a nagyker árakból” – generátor.
# Kimenet: /home/user/mdn/docs/hirlevel/2026-10/partner-nov-hirlevel.html + .txt
# Az októberi partnerlevél (partner-hirlevel.html) felépítését, tokenjeit, megszólítását és láblécét viszi tovább,
# a keddi levél generátorának (gen_keddi.py) építőelemeivel (VML-gomb, nem törhető számok, sötét mód).
# Tények: facts-2026-10-26.json + élő Store API (2026-10-26). Áron döntései (2026-10-26): a 10% a NAGYKER árakból,
# a küszöb NETTÓ 100 000 Ft egy személyes vásárlásra, 2026-11-10-ig (kedd) bezárólag, nem vonható össze a
# kedvezményszinttel és más kedvezménnyel.
import html
import re

OUT = '/home/user/mdn/docs/hirlevel/2026-10/'
NAME = 'partner-nov-hirlevel'
CAMPAIGN = '2026-11-partner'
UTM = 'utm_source=newsletter&utm_medium=email&utm_campaign=' + CAMPAIGN
SITE = 'https://mandala.hu'
IMG = SITE + '/wp-content/uploads/'
ADS = SITE + '/wp-content/themes/mandala/assets/ads/'
FONTS = SITE + '/wp-content/themes/mandala/assets/fonts/'

SERIF = "'Cormorant Garamond',Georgia,'Times New Roman',serif"
SANS = "Inter,-apple-system,'Segoe UI',Helvetica,Arial,sans-serif"

# tokenek – ugyanazok, mint az októberi leveleknél (wp-theme/mandala/theme.json)
PAPER, WHITE, SAND, LINE = '#F7F4EE', '#FFFFFF', '#EEE7DB', '#DDD3C3'
INK, MUTED, ACCENT, DEEP, NIGHT = '#1C1916', '#6E6357', '#A9581A', '#8A4512', '#16120F'
NIGHT_TEXT, NIGHT_HI, PILL = '#D9D1C6', '#E2B77A', '#C9A27C'

PHONE, PHONE_HREF = '+36 30 892 8385', 'tel:+36308928385'
EMAIL = 'info@mandala.hu'
ADDRESS = '1093 Budapest, Bakáts u. 6.'
# Nagyker nyitvatartás: ÁSZF, „Nagykereskedés adatai” (H 9–17, K–P 9–15, hétvégén zárva). A Kapcsolat oldal H–P 9–17-et ír;
# a levél a szűkebbet, a viszonteladóknak szólót adja meg (aki ebben jön, mindkét forrás szerint nyitva találja).
HOURS = 'Hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva.'
ARRIVALS_P = ('Ízelítő a webshopba legutóbb feltett darabokból – az áraikat belépve, nagyker áron látod. '
              'Hogy mi minden jött még, kérdezd meg telefonon, vagy gyere be, és megmutatjuk.')

TITLE = 'Személyes vásárlásnál 10% a nagyker árakból'
PREHEADER = 'Nettó 100 000 Ft felett, november 10-ig, a Bakáts utcában. Előtte hívj: +36 30 892 8385.'

# linkek
LOGIN = '/fiokom/nagyker/'
LOST = '/fiokom/lost-password/'
NEWEST = '/termekek/?orderby=date'
KAPCSOLAT = '/kapcsolat/'
ADATKEZELES = '/adatkezelesi-tajekoztato/'

# A webshopba legutóbb felkerült, raktáron lévő termékek (Store API, 2026-10-26). Ár NINCS a levélben.
# Készlet: Guru Rinpoche 1, Táncoló Shiva 1, Shiva 3 + 2, Lakshmi 1, karkötők 81 / 89, mandalás tálak 1-1.
# A legfrissebb termékek közzététele 2026-10-02 (wp/v2/product). Méretek a Méret attribútumból (Guru: „Szobor magassága: 25cm”).
# A mandalás tál képe a típusfotó belső nézete (hangtal-s2: 7946, 7935, 7911, 7904 második képe), a mandala látszik rajta.
TILES = [
    dict(cat='Buddhista szobrok', name='Guru Rinpoche réz szobor',
         text='Padmaszambhava, a tibeti buddhizmus nagy mestere. 25&nbsp;cm magas.',
         url='/termek/guru-rinpoche-rez-szobor-9990000041169/', link='A szobor', key='guru-rinpoche',
         img=IMG + '2026/10/9990000041169-600x600.webp',
         alt='Guru Rinpoche aranyló réz szobra ülő alakban, lótuszos talapzaton'),
    dict(cat='Hindu szobrok', name='Táncoló Shiva szobor',
         text='Natarádzsa, Shiva kozmikus tánca, lángkoszorúban. Mérete 21&nbsp;cm.',
         url='/termek/tancolo-shiva-szobor-m-9990000041121/', link='A szobor', key='tancolo-shiva',
         img=IMG + '2026/10/9990000041121-600x600.webp',
         alt='Táncoló Shiva (Natarádzsa) réz szobra lángkoszorúban, szögletes talapzaton'),
    dict(cat='Hindu szobrok', name='Shiva réz szobor',
         text='Ülő, négykarú Shiva, kezében háromágú szigonnyal és dobbal. Két változatban, 13&nbsp;cm.',
         url='/kategoria/szakralis-targyak/hindu-szobrok/', link='A hindu szobrok', key='shiva',
         img=IMG + '2026/10/9990000041138-600x600.webp',
         alt='Ülő, négykarú Shiva réz szobra festett arccal, kezében háromágú szigonnyal és dobbal'),
    dict(cat='Hindu szobrok', name='Lakshmi réz szobor',
         text='A bőség és a jólét istennője, lótuszon ülve. Mérete 8&nbsp;cm.',
         url='/termek/lakshmi-rez-szobor-9990000041152/', link='A szobor', key='lakshmi',
         img=IMG + '2026/10/9990000041152-600x600.webp',
         alt='Lakshmi ülő réz szobra lótuszon, piros festésű részletekkel'),
    dict(cat='Karkötők', name='Szerencsekarkötők',
         text='Piros zsinóron, kínai szerencsepénzzel, kétféle. Kis ajándék a kassza mellé.',
         url='/termek/szerencsekarkoto-kinai-penz-9990000049424/', link='A karkötő', key='szerencsekarkoto',
         img=IMG + '2026/10/9990000049424-600x600.webp',
         alt='Piros zsinóros karkötő apró, felfűzött kínai szerencsepénzekkel'),
    dict(cat='Hangtálak', name='Mandalás hangtálak',
         text='Egyedi darabok, mindegyiknél megadjuk a súlyát, a frekvenciáját és a csakráját. A fotó a típust mutatja.',
         url='/kategoria/szakralis-targyak/hangtalak/mandalas-healing-hangtal/', link='A mandalás tálak', key='mandalas-hangtal',
         img=IMG + '2026/09/hangtal-s2-600x600.webp',
         alt='Hangtál felülről, a belsejében vésett mandalamintával'),
]

# (címke, érték HTML-ben); a .txt a címkék nélküli szöveget kapja
CONDITIONS = [
    ('Mikor jár', 'Ha egy vásárlásod <strong>nettó értéke meghaladja a 100 000 Ft-ot</strong>.'),
    ('Hol', 'Személyesen, a bemutatótermünkben: <strong>' + ADDRESS + '</strong>'),
    ('Meddig', '<strong>2026. november 10-ig (kedd)</strong>, aznap&nbsp;is.'),
    ('Fontos', 'Nem vonható össze a kedvezményszinteddel és más kedvezménnyel.'),
]


def plain(s: str) -> str:
    return html.unescape(re.sub(r'<[^>]+>', '', s)).replace('\xa0', ' ')


# ---------------------------------------------------------------- segédek

def u(url: str, content: str) -> str:
    """Abszolút URL + UTM (nyers, a .txt-hez)."""
    if url.startswith('/'):
        url = SITE + url
    return url + ('&' if '?' in url else '?') + UTM + '&utm_content=' + content


def h(url: str, content: str) -> str:
    return html.escape(u(url, content), quote=True)


def esc(s: str) -> str:
    return html.escape(s, quote=False)


def attr(s: str) -> str:
    return html.escape(s, quote=True)


UNITS = r'(Ft|cm|ml|g|kg|Hz|munkanapon)\b'


def nb_text(t: str) -> str:
    """Szám és mértékegység, ezres csoportok ne törjenek el (100 000 Ft, 25 cm); a telefonszám se."""
    t = t.replace('+36 30 892 8385', '+36&nbsp;30&nbsp;892&nbsp;8385')
    t = re.sub(r'(\d) (?=\d{3}(?!\d))', '\\1&nbsp;', t)
    t = re.sub(r'(\d) ' + UNITS, '\\1&nbsp;\\2', t)
    t = re.sub(r'(\d+–\d+(?:&nbsp;[^\s<]+)?)', '<span style="white-space:nowrap;">\\1</span>', t)
    return t


def nb_body(doc: str) -> str:
    """Csak a <body> szövegcsomópontjain (a CSS-hez és az attribútumokhoz nem nyúl)."""
    head, body = doc.split('<body', 1)
    return head + '<body' + re.sub(r'>([^<]+)<', lambda m: '>' + nb_text(m.group(1)) + '<', body)


EYEBROW = (f"margin:0 0 12px;font-family:{SANS};font-size:12px;line-height:1.4;font-weight:600;letter-spacing:.14em;"
           f"text-transform:uppercase;color:{DEEP};")
H2 = f"margin:0 0 12px;font-family:{SERIF};font-size:30px;line-height:1.15;font-weight:500;letter-spacing:-0.01em;color:{INK};"
P = f"margin:0 0 16px;font-family:{SANS};font-size:16px;line-height:1.6;color:{INK};"
SMALL = f"font-family:{SANS};font-size:15px;line-height:1.6;"
LINK = f"color:{DEEP};text-decoration:underline;"
TABLE = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'


def eyebrow(text, cls='t-accent', color=DEEP, margin='0 0 12px'):
    return f'<p class="{cls}" style="{EYEBROW.replace(DEEP, color).replace("margin:0 0 12px", "margin:" + margin)}">{esc(text)}</p>'


def btn(label, href, width, dark=True, aria=''):
    """Kerek gomb, 50 px magas (Outlookban VML). dark=True: tinta alapon fehér; False: papír alapon tinta (sötét szalagon)."""
    bg, fg = (INK, '#FFFFFF') if dark else (PAPER, INK)
    cls = 'btn-ink' if dark else 'btn-paper'
    aria_attr = f' aria-label="{attr(aria)}"' if aria else ''
    return (
        '<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn-wrap"><tr><td>'
        f'<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{href}" '
        f'style="height:50px;v-text-anchor:middle;width:{width}px;" arcsize="50%" stroke="f" fillcolor="{bg}"><w:anchorlock/>'
        f'<center style="color:{fg};font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">{esc(label)}</center></v:roundrect><![endif]-->'
        f'<!--[if !mso]><!--><a class="{cls} btn-full" href="{href}"{aria_attr} style="display:inline-block;background:{bg};color:{fg};'
        f'font-family:{SANS};font-size:16px;line-height:20px;font-weight:600;text-decoration:none;padding:15px 30px;border-radius:999px;">{esc(label)}</a><!--<![endif]-->'
        '</td></tr></table>'
    )


def section(inner, bg=WHITE, cls='bg-white', pad='44px 40px 40px'):
    return f'<tr><td class="px pt {cls}" style="padding:{pad};background-color:{bg};">{inner}</td></tr>'


# ---------------------------------------------------------------- szakaszok

def header():
    return f'''<tr><td class="px-logo bg-paper" style="padding:28px 40px 22px 26px;background-color:{PAPER};">
  {TABLE}<tr>
    <td valign="middle" style="padding:0;">
      <a href="{h('/', 'logo')}" style="text-decoration:none;">
        <img class="logo-light" src="{ADS}mandala-logo-szeles-1200x300.png" width="200" height="50" alt="Mandala" style="display:block;width:200px;height:50px;border:0;font-family:{SERIF};font-size:28px;color:{INK};">
        <!--[if !mso]><!--><div class="logo-dark" style="display:none;max-height:0;overflow:hidden;mso-hide:all;"><img src="{ADS}mandala-logo-szeles-feher-1200x300.png" width="200" height="50" alt="Mandala" style="display:block;width:200px;height:50px;border:0;"></div><!--<![endif]-->
      </a>
    </td>
    <td valign="middle" align="right" class="t-accent" style="padding:0;font-family:{SANS};font-size:12px;line-height:1.4;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:{DEEP};">Partnerlevél</td>
  </tr></table>
</td></tr>'''


def intro():
    body = (
        eyebrow('Viszonteladóknak · november 10-ig', margin='0 0 14px')
        + f'<h1 class="h1 t-ink" style="margin:0 0 20px;font-family:{SERIF};font-size:40px;line-height:1.1;font-weight:500;letter-spacing:-0.015em;color:{INK};">'
          f'Gyere be személyesen: <em class="t-accent" style="font-style:italic;color:{DEEP};">10% a nagyker árakból</em></h1>'
        + f'<p class="t-ink" style="{P}">Kedves {{$name|default(\'Partnerünk\')}}!</p>'
        + f'<p class="t-ink" style="{P}">Rengeteg új áru érkezett hozzánk, és a legjobb, ha élőben nézed meg. Ezért november 10-ig '
          '10%-ot engedünk a nagyker árakból, ha személyesen vásárolsz a budapesti bemutatótermünkben, és a vásárlásod nettó értéke '
          'meghaladja a 100 000 Ft-ot.</p>'
        + f'<p class="t-ink" style="{P}margin-bottom:28px;">Mielőtt elindulsz, beszéljük meg telefonon, mikor jössz.</p>'
        + btn('Hívj minket: ' + PHONE, PHONE_HREF, 310, aria='Hívás: ' + PHONE)
        + f'<p class="t-muted" style="margin:16px 0 0;{SMALL}font-size:14px;color:{MUTED};">Inkább írnál? '
          f'<a class="link t-link" href="mailto:{EMAIL}" style="{LINK}">{EMAIL}</a></p>'
    )
    return section(body, pad='40px 40px 40px')


def voucher():
    rows = ''
    for i, (label, value) in enumerate(CONDITIONS):
        last = i == len(CONDITIONS) - 1
        border = '' if i == 0 else f'border-top:1px solid {LINE};'
        rows += (
            f'<tr><td class="bd-line" style="padding:{"0" if i == 0 else "14px"} 0 {"0" if last else "14px"};{border}">'
            f'<p class="t-muted" style="margin:0 0 3px;font-family:{SANS};font-size:12px;line-height:1.4;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:{MUTED};">{esc(label)}</p>'
            f'<p class="t-ink" style="margin:0;font-family:{SANS};font-size:16px;line-height:1.5;color:{INK};">{value}</p>'
            '</td></tr>'
        )
    card = (
        f'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="bg-white bd-card" '
        f'style="background-color:{WHITE};border:1.5px dashed {PILL};border-radius:6px;border-collapse:separate;">'
        # fej: nagy szám + mire jár
        f'<tr><td class="card-pad" style="padding:26px 28px 22px;">{TABLE}<tr>'
        f'<td class="big serif t-accent" valign="middle" width="150" style="width:150px;padding:0 14px 0 0;font-family:{SERIF};font-size:84px;line-height:0.9;font-weight:500;letter-spacing:-0.03em;color:{DEEP};white-space:nowrap;">10%</td>'
        f'<td valign="middle" style="padding:0;">'
        f'<p class="t-ink serif big-sub" style="margin:0 0 4px;font-family:{SERIF};font-size:27px;line-height:1.1;font-weight:500;color:{INK};">a nagyker áraidból</p>'
        f'<p class="t-muted" style="margin:0;font-family:{SANS};font-size:14px;line-height:1.45;color:{MUTED};">személyes vásárlásnál,<br>nettó 100 000 Ft felett</p>'
        '</td></tr></table></td></tr>'
        # tépőél
        f'<tr><td style="padding:0 28px;" class="card-pad-x"><div class="bd-dash" style="height:0;line-height:0;font-size:0;border-top:1.5px dashed {PILL};">&nbsp;</div></td></tr>'
        f'<tr><td class="card-pad" style="padding:20px 28px 24px;">{TABLE}{rows}</table></td></tr>'
        '</table>'
    )
    body = (
        eyebrow('A kedvezmény feltételei')
        + f'<h2 class="h2 t-ink" style="{H2}margin-bottom:22px;">Röviden, egy helyen</h2>'
        + card
    )
    return section(body, bg=SAND, cls='bg-sand', pad='40px 40px 44px')


def tile(t, left):
    pad = 'padding:0 10px 30px 0;' if left else 'padding:0 0 30px 10px;'
    gap = 'colgap-l' if left else 'colgap-r'
    href = h(t['url'], t['key'])
    return (
        f'<td class="{gap}" width="50%" valign="top" style="width:50%;{pad}">'
        f'<a href="{href}" style="text-decoration:none;"><img src="{t["img"]}" width="250" height="250" alt="{attr(t["alt"])}" '
        f'style="display:block;width:100%;max-width:250px;height:auto;border:0;border-radius:4px;background-color:{SAND};color:{MUTED};font-family:Arial,sans-serif;font-size:13px;"></a>'
        f'<p class="t-muted tile-cat" style="margin:14px 0 4px;font-family:{SANS};font-size:11px;line-height:1.4;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:{MUTED};">{esc(t["cat"])}</p>'
        f'<h3 class="tile-h t-ink" style="margin:0 0 6px;font-family:{SERIF};font-size:21px;line-height:1.2;font-weight:600;color:{INK};">{esc(t["name"])}</h3>'
        f'<p class="tile-p t-ink" style="margin:0 0 8px;font-family:{SANS};font-size:15px;line-height:1.55;color:{INK};">{t["text"]}</p>'
        f'<a class="link t-link" href="{href}" style="font-family:{SANS};font-size:15px;line-height:1.5;color:{DEEP};text-decoration:underline;">{esc(t["link"])}</a>'
        '</td>'
    )


def arrivals():
    grid = ''
    for i in range(0, len(TILES), 2):
        grid += '<tr>' + tile(TILES[i], True) + tile(TILES[i + 1], False) + '</tr>'
    body = (
        eyebrow('Megérkezett')
        + f'<h2 class="h2 t-ink" style="{H2}">Új áru a polcon&nbsp;– <em class="t-accent" style="font-style:italic;color:{DEEP};">nézd meg élőben</em></h2>'
        + f'<p class="t-ink" style="{P}margin-bottom:26px;">{ARRIVALS_P}</p>'
        + f'{TABLE}{grid}</table>'
        + f'<p class="t-muted" style="margin:0 0 26px;{SMALL}color:{MUTED};">A szobrokból csak 1–3 darab van.</p>'
        + btn('Belépek, és nagyker áron nézem', h(LOGIN, 'belepes-gomb'), 330)
        + f'<p class="t-muted" style="margin:18px 0 0;{SMALL}font-size:14px;color:{MUTED};">'
          f'<a class="link t-link" href="{h(NEWEST, "ujdonsagok")}" style="{LINK}">Az összes újdonság a webshopban</a></p>'
          f'<p class="t-muted" style="margin:10px 0 0;{SMALL}font-size:14px;color:{MUTED};">Elfelejtetted a jelszavad? <a class="link t-link" href="{h(LOST, "jelszo")}" style="{LINK}">Itt kérhetsz újat.</a> Ha nem érkezik meg a levél, hívj minket.</p>'
    )
    return section(body, pad='44px 40px 40px')


def phone_cta():
    body = (
        eyebrow('Hívj minket', cls='', color=NIGHT_HI)
        + f'<h2 class="h2" style="{H2.replace(INK, PAPER)}margin-bottom:18px;">Telefonon <em style="font-style:italic;color:{NIGHT_HI};">mindent elmondunk</em></h2>'
        + f'<p style="margin:0 0 18px;"><a class="phone-big serif" href="{PHONE_HREF}" aria-label="Hívás: {PHONE}" '
          f'style="font-family:{SERIF};font-size:46px;line-height:1.05;font-weight:500;letter-spacing:-0.01em;color:{PAPER};text-decoration:none;white-space:nowrap;">{PHONE}</a></p>'
        + f'<p style="margin:0 0 28px;font-family:{SANS};font-size:16px;line-height:1.6;color:{NIGHT_TEXT};">Elmondjuk, mi érkezett és miből mennyi van, '
          'átbeszéljük a kedvezményt, és ha keresel valamit, félretesszük, amíg beérsz.</p>'
        + btn('Felhívom a Mandalát', PHONE_HREF, 250, dark=False, aria='Hívás: ' + PHONE)
        + f'<p style="margin:20px 0 0;font-family:{SANS};font-size:15px;line-height:1.6;color:{NIGHT_TEXT};">Inkább írnál? '
          f'<a href="mailto:{EMAIL}" style="color:{NIGHT_HI};text-decoration:underline;">{EMAIL}</a> – általában egy munkanapon belül válaszolunk.</p>'
    )
    return section(body, bg=NIGHT, cls='bg-night', pad='44px 40px 44px')


def closing():
    lab = f"margin:0 0 2px;font-family:{SANS};font-size:12px;line-height:1.4;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:{MUTED};"
    body = (
        eyebrow('Bemutatóterem és átvevőhely')
        + f'<h2 class="h2 t-ink" style="{H2}margin-bottom:20px;">Itt találsz minket</h2>'
        + f'{TABLE}'
        + f'<tr><td class="bd-line" style="padding:14px 0;border-top:1px solid {LINE};">'
          f'<p class="t-muted" style="{lab}">Cím</p>'
          f'<p class="t-ink" style="margin:0 0 4px;font-family:{SANS};font-size:17px;line-height:1.5;font-weight:600;color:{INK};">{ADDRESS}</p>'
          f'<p class="t-ink" style="margin:0;{SMALL}color:{INK};">A hangtálakat előzetes egyeztetéssel meg is hallgathatod.</p></td></tr>'
        + f'<tr><td class="bd-line" style="padding:14px 0;border-top:1px solid {LINE};border-bottom:1px solid {LINE};">'
          f'<p class="t-muted" style="{lab}">Nyitvatartás</p>'
          f'<p class="t-ink" style="margin:0 0 4px;{SMALL}color:{INK};">{HOURS}</p>'
          f'<p class="t-ink" style="margin:0;{SMALL}color:{INK};">Térkép a <a class="link t-link" href="{h(KAPCSOLAT, "kapcsolat")}" style="{LINK}">Kapcsolat oldalon</a>.</p></td></tr>'
        + '</table>'
        + f'<p class="t-ink" style="margin:30px 0 0;font-family:{SANS};font-size:16px;line-height:1.5;color:{INK};">Szeretettel:</p>'
        + f'<p class="t-ink serif" style="margin:2px 0 0;font-family:{SERIF};font-size:24px;line-height:1.3;font-style:italic;font-weight:500;color:{INK};">a Mandala csapata</p>'
    )
    return section(body, pad='44px 40px 40px')


def footer():
    # az októberi partnerlevél lábléce (jogalap-sor, leiratkozás), csak a kampány neve más
    s = f"font-family:{SANS};font-size:14px;line-height:1.6;color:{MUTED};"
    a = f"color:{MUTED};text-decoration:underline;"
    return f'''<tr><td class="px bg-paper" style="padding:30px 40px 40px;background-color:{PAPER};">
  <p class="t-muted" style="margin:0 0 12px;{s}">Mandala – hangtálak, füstölők és szakrális tárgyak Nepálból és Indiából</p>
  <p class="t-muted" style="margin:0 0 12px;{s}">Asita Cult Kft. · 1093 Budapest, Bakáts u. 6. · Adószám: 12587128-2-43<br><a class="t-muted" href="mailto:{EMAIL}" style="{a}">{EMAIL}</a> · <a class="t-muted" href="{PHONE_HREF}" style="{a}white-space:nowrap;">{PHONE}</a> · <a class="t-muted" href="{h('/', 'lablec')}" style="{a}">mandala.hu</a></p>
  <p class="t-muted" style="margin:0 0 12px;{s}">Azért kapod ezt a levelet, mert viszonteladói fiókod van a mandala.hu-n, és feliratkoztál a hírlevelünkre.</p>
  <p class="t-muted" style="margin:0;font-family:{SANS};font-size:14px;line-height:1.9;color:{MUTED};"><a class="t-muted" href="{{$unsubscribe}}" style="{a}">Leiratkozás</a> · <a class="t-muted" href="{{$url}}" style="{a}">Megnyitás böngészőben</a> · <a class="t-muted" href="{h(ADATKEZELES, 'adatkezeles')}" style="{a}">Adatkezelési tájékoztató</a></p>
</td></tr>'''


# ---------------------------------------------------------------- dokumentum

FONT_CSS = f"""@font-face{{font-family:'Cormorant Garamond';font-style:normal;font-weight:400 600;src:url({FONTS}cormorant-normal-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+1E00-1E9F,U+20A0-20AB,U+20AD-20C0,U+2C60-2C7F,U+A720-A7FF;}}
@font-face{{font-family:'Cormorant Garamond';font-style:normal;font-weight:400 600;src:url({FONTS}cormorant-normal-latin.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2212;}}
@font-face{{font-family:'Cormorant Garamond';font-style:italic;font-weight:400 600;src:url({FONTS}cormorant-italic-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+1E00-1E9F,U+20A0-20AB,U+20AD-20C0,U+2C60-2C7F,U+A720-A7FF;}}
@font-face{{font-family:'Cormorant Garamond';font-style:italic;font-weight:400 600;src:url({FONTS}cormorant-italic-latin.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2212;}}
@font-face{{font-family:'Inter';font-style:normal;font-weight:400 600;src:url({FONTS}inter-normal-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+1E00-1E9F,U+20A0-20AB,U+20AD-20C0,U+2C60-2C7F,U+A720-A7FF;}}
@font-face{{font-family:'Inter';font-style:normal;font-weight:400 600;src:url({FONTS}inter-normal-latin.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2212;}}"""

BASE_CSS = f"""body{{margin:0!important;padding:0!important;width:100%!important;}}
table,td{{border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;}}
img{{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}}
a[x-apple-data-detectors]{{color:inherit!important;text-decoration:none!important;}}
#MessageViewBody a{{color:inherit;text-decoration:none;}}
h1,h2,h3{{text-wrap:balance;}}
.serif,.big{{font-variant-numeric:lining-nums;font-feature-settings:"lnum" 1;}}
.link:hover{{color:{ACCENT}!important;}}
.btn-ink:hover{{background:{DEEP}!important;}}
.btn-paper:hover{{background:{NIGHT_HI}!important;}}
@media screen and (max-width:599px){{
.px{{padding-left:20px!important;padding-right:20px!important;}}
.pt{{padding-top:34px!important;}}
.px-logo{{padding-left:6px!important;padding-right:20px!important;}}
.h1{{font-size:32px!important;line-height:1.12!important;}}
.h2{{font-size:27px!important;}}
.card-pad{{padding-left:18px!important;padding-right:18px!important;}}
.card-pad-x{{padding-left:18px!important;padding-right:18px!important;}}
.big{{font-size:64px!important;width:108px!important;padding-right:10px!important;}}
.big-sub{{font-size:23px!important;}}
.colgap-l{{padding-right:7px!important;}}
.colgap-r{{padding-left:7px!important;}}
.tile-h{{font-size:18px!important;}}
.tile-p{{font-size:14px!important;}}
.tile-cat{{font-size:10px!important;letter-spacing:.08em!important;}}
.phone-big{{font-size:38px!important;}}
.btn-wrap{{width:100%!important;}}
.btn-full{{display:block!important;text-align:center!important;padding-left:16px!important;padding-right:16px!important;}}
}}"""

DARK_CSS = f"""@media (prefers-color-scheme:dark){{
body,.bg-paper{{background-color:#120F0C!important;}}
.bg-white{{background-color:#1E1915!important;}}
.bg-sand{{background-color:#2A231D!important;}}
.bg-night{{background-color:#0B0907!important;}}
.t-ink{{color:#F2EDE4!important;}}
.t-muted{{color:#BDB3A6!important;}}
.t-accent,.t-link{{color:{NIGHT_HI}!important;}}
.bd-line{{border-color:#3A322B!important;}}
.bd-card,.bd-dash{{border-color:#8A6A44!important;}}
.btn-ink{{background:#F2EDE4!important;color:#16120F!important;}}
.logo-light{{display:none!important;}}
.logo-dark{{display:block!important;max-height:none!important;overflow:visible!important;}}
}}"""

OGSC_CSS = f"""[data-ogsc] .t-ink{{color:#F2EDE4!important;}}
[data-ogsc] .t-muted{{color:#BDB3A6!important;}}
[data-ogsc] .t-accent,[data-ogsc] .t-link{{color:{NIGHT_HI}!important;}}
[data-ogsb] .bg-paper{{background-color:#120F0C!important;}}
[data-ogsb] .bg-white{{background-color:#1E1915!important;}}
[data-ogsb] .bg-sand{{background-color:#2A231D!important;}}
[data-ogsb] .bg-night{{background-color:#0B0907!important;}}"""


def document():
    rows = header() + intro() + voucher() + arrivals() + phone_cta() + closing() + '<!--FOOTER-->'
    spacer = '&#847;&zwnj;&nbsp;' * 60
    doc = f'''<!doctype html>
<html lang="hu" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>{esc(TITLE)}</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
<style>
  body, table, td, p, a, span, li {{ font-family: Arial, Helvetica, sans-serif !important; }}
  h1, h2, h3, .serif {{ font-family: Georgia, 'Times New Roman', serif !important; }}
</style>
<![endif]-->
<style>
{BASE_CSS}
</style>
<style>
{DARK_CSS}
</style>
<style>
{OGSC_CSS}
</style>
<!--[if !mso]><!--><style>
{FONT_CSS}
</style><!--<![endif]-->
</head>
<body class="bg-paper" style="margin:0;padding:0;width:100%;background-color:{PAPER};-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;color:{PAPER};">{esc(PREHEADER)}{spacer}</div>
<div role="article" aria-roledescription="email" aria-label="{attr(TITLE)}" lang="hu">
<table role="presentation" class="bg-paper" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{PAPER};">
<tr><td align="center" style="padding:0;">
<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;margin:0 auto;">
{rows}
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</div>
</body>
</html>
'''
    return nb_body(doc).replace('<!--FOOTER-->', '\n<!-- Lábléc -->\n' + footer())


# ---------------------------------------------------------------- szöveges változat

def text_version():
    R = '-' * 60
    L = [
        'Mandala · Partnerlevél',
        'Viszonteladóknak · november 10-ig',
        '',
        'Gyere be személyesen: 10% a nagyker árakból',
        '',
        "Kedves {$name|default('Partnerünk')}!",
        '',
        'Rengeteg új áru érkezett hozzánk, és a legjobb, ha élőben nézed meg. Ezért november 10-ig 10%-ot engedünk a '
        'nagyker árakból, ha személyesen vásárolsz a budapesti bemutatótermünkben, és a vásárlásod nettó értéke '
        'meghaladja a 100 000 Ft-ot.',
        '',
        'Mielőtt elindulsz, beszéljük meg telefonon, mikor jössz.',
        '',
        f'Hívj minket: {PHONE}',
        f'Inkább írnál? {EMAIL}',
        '',
        '',
        R,
        'A kedvezmény feltételei – röviden, egy helyen',
        R,
        '',
        '10% a nagyker áraidból – személyes vásárlásnál, nettó 100 000 Ft felett',
        '',
    ]
    for label, value in CONDITIONS:
        v = plain(value)
        L.append(f'{label}: {v[0].lower() + v[1:] if not v[0].isdigit() else v}')
    L += [
        '',
        '',
        R,
        'Megérkezett: új áru a polcon – nézd meg élőben',
        R,
        '',
        ARRIVALS_P,
    ]
    for t in TILES:
        L += ['', f'{t["name"]} ({t["cat"]})', html.unescape(t['text']), u(t['url'], t['key'])]
    L += [
        '',
        'A szobrokból csak 1–3 darab van.',
        '',
        'Belépek, és nagyker áron nézem:',
        u(LOGIN, 'belepes-gomb'),
        '',
        'Az összes újdonság a webshopban:',
        u(NEWEST, 'ujdonsagok'),
        '',
        'Elfelejtetted a jelszavad? Itt kérhetsz újat:',
        u(LOST, 'jelszo'),
        f'Ha nem érkezik meg a levél, hívj minket: {PHONE}.',
        '',
        '',
        R,
        'Hívj minket – telefonon mindent elmondunk',
        R,
        '',
        f'Telefon: {PHONE}',
        '',
        'Elmondjuk, mi érkezett és miből mennyi van, átbeszéljük a kedvezményt, és ha keresel valamit, félretesszük, amíg beérsz.',
        '',
        f'Inkább írnál? {EMAIL} – általában egy munkanapon belül válaszolunk.',
        '',
        '',
        R,
        'Bemutatóterem és átvevőhely – itt találsz minket',
        R,
        '',
        f'Cím: {ADDRESS}',
        'A hangtálakat előzetes egyeztetéssel meg is hallgathatod.',
        '',
        'Nyitvatartás: ' + HOURS[0].lower() + HOURS[1:],
        '',
        'Térkép a Kapcsolat oldalon:',
        u(KAPCSOLAT, 'kapcsolat'),
        '',
        'Szeretettel:',
        'a Mandala csapata',
        '',
        '',
        R,
        'Mandala – hangtálak, füstölők és szakrális tárgyak Nepálból és Indiából',
        'Asita Cult Kft. · 1093 Budapest, Bakáts u. 6. · Adószám: 12587128-2-43',
        f'{EMAIL} · {PHONE} · {u("/", "lablec")}',
        '',
        'Azért kapod ezt a levelet, mert viszonteladói fiókod van a mandala.hu-n, és feliratkoztál a hírlevelünkre.',
        '',
        'Leiratkozás: {$unsubscribe}',
        'Megnyitás böngészőben: {$url}',
        'Adatkezelési tájékoztató: ' + u(ADATKEZELES, 'adatkezeles'),
        '',
    ]
    return '\n'.join(L)


if __name__ == '__main__':
    doc = document()
    with open(OUT + NAME + '.html', 'w', encoding='utf-8') as f:
        f.write(doc)
    with open(OUT + NAME + '.txt', 'w', encoding='utf-8') as f:
        f.write(text_version())
    print(NAME + '.html', len(doc.encode('utf-8')), 'bytes')
