# Mandala nagyker kampány 2026 – „Nyitott raktár”: minden szöveg egy helyen (PDF, képek, szovegek.md ebből készül).
# Ellenőrzés: python3 texts.py  (hosszak: Google-hirdetés, tárgysor, előnézeti szöveg, SMS)
# Tények: Áron, 2026-11-06 („A készlet 80%-ára 10-30-50% kedvezmény van. Érdeklődni személyesen az üzletbe vagy telefonon,
# a webes rendeléseknél pedig ez nem, csak személyesen.”). A dátum, a félretétel, a kedvezményszint és a minimum JAVASLAT.

NBSP = ' '
PHONE = '+36 30 892 8385'
PHONE_HREF = 'tel:+36308928385'
EMAIL = 'info@mandala.hu'
ADDRESS = '1093 Budapest, Bakáts u. 6.'
HOURS = 'Hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva.'
HOURS_SHORT = 'H 9–17 · K–P 9–15'
REG_URL = 'mandala.hu/viszonteladoknak'
START, END = 'november 11.', 'december 18.'
PERIOD = '2026. november 11. – december 18.'

NAME = 'Nyitott raktár'
LINE = 'A készlet 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból.'
ONLY = 'Csak személyesen, a Bakáts utcai bemutatóteremben. Webes rendelésre nem érvényes.'

DOTS = [  # a pötty-rendszer (a színek az imazászló színei)
    dict(key='zold', pct='10', color='#2F7A4D', ink='#FFFFFF', name='Zöld pötty', text='−10% a nagyker árból'),
    dict(key='sarga', pct='30', color='#E3B23C', ink='#1C1916', name='Sárga pötty', text='−30% a nagyker árból'),
    dict(key='piros', pct='50', color='#B3322B', ink='#FFFFFF', name='Piros pötty', text='−50% a nagyker árból'),
]
NODOT = 'Pötty nélkül: a megszokott nagyker ár (újdonságok, a legkeresettebb tételek).'

RULES = [  # a feltételek – ugyanez a plakáton, a partnerlapon, a levélben és a pultnál
    ('Kinek', 'Viszonteladóknak: jóváhagyott viszonteladói fiókkal, vagy adószámmal, a helyszínen jelentkezve.'),
    ('Mennyi', 'Tételenként 10, 30 vagy 50% a nagyker árból – a polcon a pötty színe mutatja.'),
    ('Hol', 'Csak személyes vásárlásnál, a bemutatótermünkben: ' + ADDRESS + ' Webshopos rendelésre – személyes átvétellel sem – nem érvényes.'),
    ('Telefonon', 'Érdeklődhetsz, és félretesszük, amit kiválasztottál: 3 munkanapig vár rád.'),
    ('Meddig', PERIOD + ', amíg a készlet tart. A pöttyös tételekből sokszor csak pár darab van.'),
    ('Összevonás', 'A kedvezményszinteddel nem adódik össze: mindig a kedvezőbb jár.'),
]

PHASES = [
    dict(key='elo', name='Előkészítés', short='nov. 9–10.', start='2026-11-09', end='2026-11-10',
         what='Pöttyözés a készlet alapján, plakátok és polccímkék, pult-eligazítás, hívólisták és levelek a Partner CRM-ben.'),
    dict(key='h1', name='1. hullám: Nyitott raktár', short='nov. 11–27.', start='2026-11-11', end='2026-11-27',
         what='Partnerlevél nov. 11-én, utána hívás minden régi partnernek (elveszett és elmaradó előre), új partnerek keresése, Google-keresés, posztok.'),
    dict(key='h2', name='2. hullám: Utolsó kör karácsony előtt', short='nov. 30. – dec. 18.', start='2026-11-30', end='2026-12-18',
         what='Emlékeztető levél nov. 30-án annak, aki még nem jött, visszahívások, a szezon utolsó hete dec. 14–18.'),
]

SEGMENTS = [  # kit, mivel, a CRM-ben hol
    dict(name='Elveszett partnerek', crm='Partnerek → Elveszett (180+ napja nem rendelt)', n='39',
         msg='„Rég jártál nálunk” – most van miért bejönni.', how='Hívás az 1. héten, előtte vagy utána partnerlevél.'),
    dict(name='Elmaradó partnerek', crm='Partnerek → Elmaradó (60–180 napja)', n='21',
         msg='Karácsony előtt feltölteni a polcot, olcsóbban, mint valaha.', how='Hívás az 1. héten, partnerlevél.'),
    dict(name='Érdeklődők', crm='Partnerek → Érdeklődik (fiók van, rendelés nincs)', n='–',
         msg='Az első vásárláshoz most a legjobb alkalom: gyere be, nézd meg élőben.', how='Partnerlevél, hívás a 2. héten.'),
    dict(name='Aktív partnerek', crm='Partnerek → Aktív (60 napon belül rendelt)', n='10',
         msg='Nagyobb kosár a szezonra: a pöttyös tételekből a polc karácsonyra.', how='Partnerlevél, rövid hívás a felelőstől.'),
    dict(name='Új partnerek', crm='Új partnerek → Megkeresés (talált cégek) és hívólista CSV-ből', n='~150',
         msg='„Van egy polcod? Nekünk raktárunk.” – jelentkezés után ugyanez az ajánlat.', how='Levélsor (3 levél) és hideghívás a 2. héttől.'),
]

# ── hirdetések, képek (koncepció → formátumok) ────────────────────────────────
ADS = {
    'raktar': dict(img='raktar', eyebrow='Mandala nagyker · <i>nov. 11. – dec. 18.</i>', h1='Nyitott<br><em>raktár.</em>',
                   sub='A készlet 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Viszonteladóknak, csak személyesen.'),
    'regi': dict(img='ajto', eyebrow='Viszonteladóknak · <i>Bakáts u. 6.</i>', h1='Rég jártál<br><em>nálunk.</em>',
                 sub='Most van miért bejönnöd: a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból.'),
    'uj': dict(img='polc', eyebrow='Boltoknak, stúdióknak · <i>nagyker</i>', h1='Van egy polcod?<br><em>Nekünk raktárunk.</em>',
               sub='Füstölő, hangtál, szobor, mala Nepálból és Indiából. Most a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb.'),
    'utolso': dict(img='karton', eyebrow='Utolsó kör · <i>december 18-ig</i>', h1='Még belefér<br>a <em>karácsonyba.</em>',
                   sub='Amit ma a polcodra teszel, azt még karácsony előtt eladhatod. A pöttyök december 18-ig maradnak.'),
}
AD_CTA = 'Hívj: ' + PHONE.replace(' ', NBSP)
AD_FOOT = 'Bakáts u. 6. · ' + HOURS_SHORT

# ── Google keresési hirdetés (nagyker szándékú keresésekre) ───────────────────
GOOGLE_KW = ['füstölő nagyker', 'füstölő nagykereskedés', 'hangtál nagyker', 'ezoterikus nagyker',
             'ezoterikus termékek nagykereskedés', 'buddha szobor nagyker', 'indiai termékek nagyker',
             'nepáli termékek nagyker', 'jóga kellékek nagyker', 'mala nagyker', 'spirituális termékek nagyker',
             'ajándéktárgy nagyker budapest', 'tibeti termékek nagyker']
GOOGLE_NEG = ['állás', 'munka', 'ingyen', 'diy', 'házilag', 'jelentése', 'wikipedia']
GOOGLE_HEAD = ['Füstölő és hangtál nagyker', 'Nyitott raktár Budapesten', '−10, −30, −50% a nagyker árból',
               'Közvetlen import Nepálból', 'Viszonteladóknak, adószámmal', 'Bakáts utca 6., IX. kerület',
               'A készlet 80%-a kedvezményes', 'Hívj: +36 30 892 8385', 'Szobrok, malák, füstölők',
               'Csak személyes vásárlásnál', 'Karácsonyi feltöltés', 'Mandala nagykereskedés']
GOOGLE_DESC = ['A készlet 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Csak személyesen.',
               'Füstölők, hangtálak, szobrok, malák Nepálból, Indiából. Viszonteladóknak, ÁFÁ-s számla.',
               'Hívj, és elmondjuk, mi van pöttyön. Félretesszük, amíg beérsz a Bakáts utcába.',
               'Új partner vagy? 3 lépés a jelentkezés, 1–2 munkanap a jóváhagyás. Utána gyere be.']
GOOGLE_URL = 'https://mandala.hu/viszonteladoknak/'

# ── közösségi oldalak és Cégprofil (organikus, a viszonteladóknak címezve) ──
SOCIAL = [
    ('Facebook + Instagram · nov. 11.', 'raktar', 'Boltod, jógastúdiód vagy webshopod van? Nyitott raktár a Bakáts utcában.\n\n'
     'November 11-től december 18-ig a nagyker készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Hogy melyik tétel mennyivel, '
     'azt a polcon egy színes pötty mutatja: zöld 10%, sárga 30%, piros 50%.\n\n'
     'A kedvezmény viszonteladóknak szól, és csak személyes vásárlásnál jár – a webshopban a megszokott árak maradnak.\n\n'
     'Hívj, és elmondjuk, mi van pöttyön: ' + PHONE + '\nMég nem vagy a partnerünk? A jelentkezés pár perc: ' + REG_URL),
    ('Facebook + Instagram · nov. 18.', 'saját fotó', '[Saját fotó: egy kolléga keze, ahogy pöttyöt ragaszt egy szoborra vagy egy füstölős kartonra.]\n\n'
     'Pöttyözünk. A raktárban minden második tétel kap egy színes pöttyöt – zöld, sárga vagy piros –, és ez a pötty a kedvezmény: '
     '10, 30 vagy 50% a nagyker árból, december 18-ig.\n\nViszonteladóknak, személyesen a Bakáts utcában. Előtte hívj: ' + PHONE),
    ('Facebook + Instagram · dec. 1.', 'utolso', 'Utolsó kör karácsony előtt.\n\nA nyitott raktár december 18-ig tart: a pöttyös tételek 10, 30 vagy 50%-kal olcsóbbak '
     'a nagyker árból. Amit ma a polcodra teszel, azt még karácsony előtt eladhatod.\n\n'
     'Viszonteladóknak, csak személyesen: ' + ADDRESS + ', ' + HOURS_SHORT.replace(' · ', ', ') + '.\nHívj, és félretesszük: ' + PHONE),
    ('Google Cégprofil · Ajánlat (nov. 11. – dec. 18.)', 'raktar', 'Cím: Nyitott raktár viszonteladóknak\n\n'
     'A nagyker készletünk 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból: füstölők, hangtálak, szobrok, malák, lakberendezés. '
     'Csak személyes vásárlásnál, a bemutatóteremben, viszonteladói fiókkal vagy adószámmal. Hívj előtte, és félretesszük, amit kiválasztottál.\n\n'
     'Gomb: Hívás'),
]
STORY = [  # 3 kocka, sorrendben
    ('1', 'Nyitott raktár.', 'A készlet 80%-a pöttyöt kapott.'),
    ('2', 'Zöld −10 · sárga −30 · piros −50', 'A nagyker árból, viszonteladóknak.'),
    ('3', 'Csak személyesen.', 'Bakáts u. 6. · Hívj: ' + PHONE),
]

# ── levelek ──────────────────────────────────────────────────────────────────
SUBJECTS = [  # partnerlevél nov. 11. (tárgy, előnézeti szöveg)
    ('A', 'Nyitott raktár: a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb',
     'November 11. – december 18., a nagyker árból, csak személyesen a Bakáts utcában. Hívj: ' + PHONE + '.'),
    ('B', 'Kinyitottuk a raktárt – gyere be, nézd a pöttyöket',
     'Zöld 10%, sárga 30%, piros 50% a nagyker árból, december 18-ig, személyes vásárlásnál.'),
    ('C', 'Rég jártál nálunk? Most érdemes bejönni',
     'A készlet 80%-a pöttyöt kapott: 10, 30 vagy 50% a nagyker árból, december 18-ig.'),
]
SUBJECTS2 = [  # emlékeztető nov. 30.
    ('A', 'Utolsó kör karácsony előtt: december 18-ig nyitva a raktár',
     'A pöttyös tételek 10, 30 vagy 50%-kal olcsóbbak a nagyker árból. Előtte hívj, és félretesszük.'),
]

MAIL1 = dict(  # partnerlevél nov. 11. – tegező (mint a korábbi partnerlevelek); MailerLite „Viszonteladók” vagy Partner CRM Hírlevél
    kicker='Mandala · Partnerlevél', sub='Viszonteladóknak · ' + START + ' – ' + END,
    title='Nyitott raktár: a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb',
    intro=['Kinyitottuk a raktárt. November 11-től december 18-ig a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. '
           'Hogy melyik tétel mennyivel, azt a polcon egy színes pötty mutatja.',
           'A kedvezmény csak személyes vásárlásnál jár, a budapesti bemutatótermünkben. Ha nem tudod, mi érdekelne, hívj: '
           'elmondjuk, mi van pöttyön, és félretesszük, amíg beérsz.'],
    blocks=[
        ('A pöttyök', ['Zöld pötty: −10% a nagyker árból.', 'Sárga pötty: −30% a nagyker árból.', 'Piros pötty: −50% a nagyker árból.',
                       'Pötty nélkül: a megszokott nagyker ár (az újdonságok és a legkeresettebb tételek).']),
        ('A feltételek – röviden', [f'{k}: {v}' for k, v in RULES]),
        ('Karácsony előtt', ['Amit most a polcodra teszel, azt még a karácsonyi szezonban eladhatod. A pöttyös tételekből sokszor csak '
                             'pár darab van – aki előbb jön, többől választ.']),
        ('Mielőtt elindulsz', ['Telefon: ' + PHONE + ' – elmondjuk, mi van pöttyön, és félretesszük, amit kiválasztottál.',
                               'Cím: ' + ADDRESS + '. Nyitvatartás: ' + HOURS,
                               'Inkább írnál? ' + EMAIL + ' – általában egy munkanapon belül válaszolunk.']),
    ],
    signoff='Szeretettel:\na Mandala csapata',
)
MAIL2 = dict(  # emlékeztető nov. 30. – annak, aki még nem vásárolt (Elmaradó, Elveszett, Érdeklődik)
    title='Utolsó kör karácsony előtt',
    intro=['a nyitott raktár december 18-ig tart. A pöttyös tételek most is 10, 30 vagy 50%-kal olcsóbbak a nagyker árból, '
           'és amit most a polcodra teszel, azt még karácsony előtt eladhatod.',
           'A kedvezmény csak személyes vásárlásnál jár, a Bakáts utcában. Hívj előtte (' + PHONE + '), és félretesszük, amit kiválasztottál.',
           'Az utolsó hét december 14–18.: hétfőn 17, keddtől péntekig 15 óráig vagyunk nyitva.'],
)
MAIL_CALL_OLD = dict(  # hívás utáni levél – meglévő partnernek (Partner CRM, a hívás eredménye ablakban), tegező
    subject='Ahogy beszéltük: nyitott raktár december 18-ig',
    body='Kedves {keresztnev|Partnerünk}!\n\nKöszönöm a beszélgetést. Ahogy telefonon mondtam: december 18-ig a készletünk 80%-a '
         '10, 30 vagy 50%-kal olcsóbb a nagyker árból – a polcon a pötty színe mutatja, melyik mennyivel. A kedvezmény személyes '
         'vásárlásnál jár, a bemutatótermünkben.\n\n[Ha félretettél valamit: Félretettem neked: … – 3 munkanapig vár rád.]\n[Ha időpontot is egyeztettetek: Várlak {idopont|a megbeszélt időpontban}.]\n\n'
         'Cím: ' + ADDRESS + '\nNyitvatartás: ' + HOURS + '\nHa közbejön valami, hívj: ' + PHONE + '\n\nSzeretettel:\n{nev}, Mandala',
)
MAIL_CALL_NEW = dict(  # hívás utáni levél – új partnernek, magázva
    subject='A Mandala nagyker ajánlata – ahogy telefonon beszéltük',
    body='Kedves {kapcsolattarto|Partnerünk}!\n\nKöszönöm, hogy beszélhettünk. Ahogy mondtam, saját importból hozunk füstölőket, hangtálakat, szobrokat és malákat '
         'Nepálból és Indiából, és december 18-ig a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból – személyes '
         'vásárlásnál, a budapesti bemutatótermünkben.\n\nA viszonteladói jelentkezés három lépés: {regisztracio_url} – 1–2 munkanapon belül '
         'jóváhagyjuk, de ha adószámmal bejön, a helyszínen is elintézzük.\n\nCím: ' + ADDRESS + '\nNyitvatartás: ' + HOURS +
         '\nTelefon: ' + PHONE + '\n\nÜdvözlettel:\n{nev}, Mandala',
)
SEQUENCE = [  # levélsor új partnereknek (Partner CRM → Megkeresés), magázva; nap = az előző levél óta
    (0, 'Nyitott raktár viszonteladóknak – {cegnev}',
     '{megszolitas}\n\n{felado} vagyok a Mandalától. Budapesten nagykereskedéssel foglalkozunk: saját importból hozunk füstölőket, '
     'hangtálakat, szobrokat, malákat és lakberendezési tárgyakat Nepálból és Indiából – boltoknak, stúdióknak, webshopoknak.\n\n'
     'December 18-ig nyitott raktárt tartunk: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból, személyes vásárlásnál, '
     'a budapesti bemutatótermünkben ({bemutatoterem_cim}).\n\nHa érdekli, a viszonteladói jelentkezés három lépés: {regisztracio_link}. '
     'Ha kérdése van, hívjon: ' + PHONE + '.'),
    (4, 'Egy kérdés a {cegnev} karácsonyi polcáról',
     '{megszolitas}\n\nRövid kérdésem lenne: mi fogy most Önöknél a legjobban a karácsonyi szezonban?\n\nA füstölőkből, füstölőtartókból, '
     'malákból és a kisebb szobrokból sok van most pöttyön – vagyis 10, 30 vagy 50%-kal olcsóbb a nagyker árból, december 18-ig. '
     'Ha megírja, mi érdekli, megnézem, mennyi van belőle, és félreteszem.'),
    (7, 'December 18-ig tart – utoljára jelentkezem',
     '{megszolitas}\n\nNem szeretném terhelni a postafiókját, ezért ez az utolsó levelem a nyitott raktárról: december 18-ig tart.\n\n'
     'Ha most nem aktuális, semmi gond. Ha később kíváncsi lenne a kínálatunkra, itt regisztrálhat viszonteladóként: {regisztracio_link}.'),
]
SMS = ('Mandala: ahogy beszéltük, dec. 18-ig a készlet 80%-a −10/30/50% a nagyker árból, csak személyesen: Bakáts u. 6., '
       'H 9–17, K–P 9–15. Hívj: 06308928385')

# ── telefonos forgatókönyvek (a Partner CRM forgatókönyv-formátumában: lépések + kifogások) ─────────
SCRIPT_OLD = dict(
    name='Nyitott raktár – régi partner', for_existing=True,
    steps=[
        ('Nyitás', '[Ha tegeződtök, tegezz.]\nJó napot kívánok, {nev} vagyok a Mandalától, a Bakáts utcából. {kapcsolattarto|A bolt vezetőjével} beszélek? '
                   'Van két perce?\n\nAzért hívom, mert régen járt nálunk, és most olyan ajánlatunk van, amit személyesen szerettem volna elmondani.'),
        ('Ajánlat', 'November 11-től december 18-ig kinyitottuk a raktárt: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. '
                    'A polcon egy színes pötty mutatja, melyik tétel mennyivel: zöld 10, sárga 30, piros 50%.\n\n'
                    'A kedvezmény személyes vásárlásnál jár, a bemutatóteremben – a webshopban a megszokott árak maradnak.'),
        ('Kérdések', 'Mi fogyott a legjobban abból, amit legutóbb vitt?\nMi hiányzik most a polcról karácsonyra?\nVolt valami, ami miatt nem rendelt '
                     'mostanában – szállítás, ár, minőség?\n[Írd fel a kartonra – ebből tanulunk.]'),
        ('Félretétel', '[Előtte nézd meg a Készletben, mi van pöttyön a kategóriáiból.]\nAbból, amit szokott vinni, most ez és ez van pöttyön. '
                       'Ha szeretné, félreteszem: 3 munkanapig várja.'),
        ('Időpont', 'Mikor tudna bejönni? Hétfőn 9–17, keddtől péntekig 9–15 óráig vagyunk nyitva.\n[Eredmény: „Bemutatóterem”, időponttal – a naptárba kerül.]'),
        ('Zárás', 'Küldök egy rövid levelet a részletekkel és a címmel. Várjuk szeretettel!'),
    ],
    objections=[
        ('Nem tudok bejönni Budapestre.', 'Értem. A kedvezmény most csak a személyes vásárlásra szól – így tudjuk megmutatni, mi van a polcon. '
         'Ha a cégétől más jön Ön helyett, az is személyes vásárlás. [Ha nem megy: a webshopban a megszokott nagyker áron rendelhet.]'),
        ('A webshopban nem lehet megkapni?', 'A webshopban a megszokott nagyker árak és a kedvezményszintje maradnak; a pöttyös kedvezmény csak a '
         'bemutatóteremben jár. Telefonon viszont elmondom, mi van pöttyön, és félreteszem.'),
        ('Mi van 50%-on?', 'Tételenként változik, és sokszor csak pár darab van belőle. Mondja meg, mi érdekli, és most megnézem.'),
        ('Van kedvezményszintem.', 'A kettő nem adódik össze: mindig a kedvezőbbet kapja.'),
        ('Most nincs keretem.', 'Értem. December 18-ig tart; ha januárra töltené fel a polcot, most olcsóbban teheti meg. Felhívhatom jövő héten?'),
    ],
)
SCRIPT_NEW = dict(
    name='Nyitott raktár – új partner', for_existing=False,
    steps=[
        ('Nyitás', '[Kit keress: {kapcsolattarto|a bolt vezetőjét vagy a beszerzőt}.]\nJó napot kívánok, {nev} vagyok a Mandalától, Budapestről. '
                   'A bolt vezetőjével beszélek?\n\nNagykereskedés vagyunk, saját importból hozunk füstölőt, hangtálat, szobrot, malát Nepálból és Indiából.'),
        ('Kérdések', 'Van keleti, spirituális polcuk vagy sarkuk?\nMi fogy most a legjobban, és mire készülnek karácsonyra?\nHonnan szereznek be most?'),
        ('Ajánlat', 'December 18-ig nyitott raktárt tartunk: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból, személyes vásárlásnál, '
                    'a Bakáts utcai bemutatóteremben. Elsőre is jó alkalom: élőben látja, mit kap.'),
        ('Jelentkezés', 'A viszonteladói jelentkezés a webshopban három lépés, 1–2 munkanap a jóváhagyás. Ha adószámmal bejön, a helyszínen is elintézzük.'),
        ('Zárás', 'Elküldöm levélben a jelentkezés linkjét és a címet. Mikor jönne be szívesen? [Eredmény: „Bemutatóterem” vagy „Regisztrál”.]'),
    ],
    objections=[
        ('Küldjön árlistát.', 'Szívesen: a jelentkezés után a webshopban minden terméknél a nagyker árat látja, és az árlista letölthető. '
         'Elküldöm a jelentkezés linkjét.'),
        ('Messze vagyunk Budapesttől.', 'Értem. A pöttyös kedvezmény csak személyesen jár, de a jelentkezés után a webshopból nagyker áron, '
         'futárral is rendelhet.'),
        ('Már van beszállítónk.', 'Nem is akarom lecserélni. Sok egyedi darabunk van, amit máshol nem talál – érdemes egyszer megnézni élőben.'),
        ('Nem érdekel.', 'Rendben, köszönöm az idejét. [Eredmény: „Nem érdekli” – fél év múlva újra felhozza a CRM.]'),
    ],
)

# ── bemutatóterem: pult, plakát, polccímke ───────────────────────────────────
COUNTER = [  # a pultnál – így adjuk a kedvezményt
    'Viszonteladó? Webshopos viszonteladói fiók vagy a Partner CRM kartonja. Ha nincs: jelentkezés a helyszínen (' + REG_URL + ', adószámmal), és a CRM-ben azonnal jóváhagyható (Pénzügy jog vagy admin).',
    'A kedvezmény tételenként a nagyker árból jár, a pötty színe szerint: zöld 10, sárga 30, piros 50%.',
    'Kedvezményszint: nem adódik össze – ha a partner szintje nagyobb, azt kapja.',
    'Számla: a megjegyzésbe „NYITOTT RAKTÁR” – ebből mérjük a kampányt a Számlázz.hu-exportban.',
    'Félretéve: a „Félretéve” polcon, a partner nevével és a dátummal. 3 munkanap után vissza a polcra.',
    'Webshopos rendelésre (személyes átvétellel sem) nem jár. Ha kéri, mondd el, hogy bejöhet, és a pultnál megkapja.',
    'Látogatás után a Partner CRM-ben: hívás / látogatás rögzítése a kartonon (miről volt szó, mit vitt).',
    'Ha vásárló (nem viszonteladó) kérdezi a pöttyöt: ez a viszonteladói kedvezmény; ő a bolti áron vásárolhat, és a webshop akcióit használhatja.',
]
POSTER = dict(kick='Viszonteladóknak · ' + PERIOD, title='Nyitott raktár.',
              lead='A készlet 80%-a pöttyöt kapott. A pötty színe a kedvezmény a nagyker árból.')
SHELF = [  # polccímke (A6): a pötty és egy mondat
    ('zold', 'Ezen a polcon a zöld pöttyös tételek 10%-kal olcsóbbak a nagyker árból.'),
    ('sarga', 'Ezen a polcon a sárga pöttyös tételek 30%-kal olcsóbbak a nagyker árból.'),
    ('piros', 'Ezen a polcon a piros pöttyös tételek 50%-kal olcsóbbak a nagyker árból.'),
]

# ── árrés-példa (a partnerlapon): nagyker 10 000 Ft, nálad a polcon 20 000 Ft ──
MARGIN = dict(w=10000, r=20000)

# ── teendők és nyitott kérdések ──────────────────────────────────────────────
TASKS = [
    ('Jóváhagyás: dátumok, feltételek, a nyitott kérdések (lent)', 'Áron, Mandala', 'nov. 9.'),
    ('Pöttyözés: a tételek kijelölése a Partner CRM Készlet nézetéből (javaslat lent), pötty a terméken vagy a polcon', 'Mandala (bolt)', 'nov. 9–10.'),
    ('Nyomtatás: pötty-ívek, plakát, polccímkék, pultkártya, 100 partnerlap; „Félretéve” polc', 'Mandala (bolt)', 'nov. 10.'),
    ('Pult-eligazítás: szabályok, számla-megjegyzés, kedvezményszint, új partner jelentkezése a helyszínen', 'Mandala', 'nov. 10.'),
    ('Partner CRM: hívólisták (Elveszett, Elmaradó, Érdeklődik, Aktív), két forgatókönyv, hívás utáni levelek', 'HelloProVision', 'nov. 10.'),
    ('Partnerlevél: MailerLite (Viszonteladók) vagy Partner CRM (Hírlevél), próbalevél, időzítés nov. 11. 9:00', 'HelloProVision', 'nov. 10.'),
    ('Webshop: kampánysáv csak a Viszonteladóknak oldalon és a viszonteladói felületen (a nyilvános felső sávba nem)', 'HelloProVision', 'nov. 11.'),
    ('Google Ads: keresési kampány nagyker szavakra, hívás-bővítmény, napi 3 000 Ft', 'HelloProVision', 'nov. 11.'),
    ('Hívások: napi 20, az 1. héten az elveszett és elmaradó partnerek', 'Mandala (értékesítő)', 'nov. 11-től'),
    ('Posztok: Facebook, Instagram, Cégprofil-ajánlat; nov. 18-ra saját fotó a pöttyözésről', 'Mandala', 'nov. 11., 18., dec. 1.'),
    ('Új partnerek: keresés a Megkeresésben (típus + város), levélsor, hideghívás', 'HelloProVision + Mandala', 'nov. 16-tól'),
    ('Emlékeztető levél annak, aki még nem vásárolt', 'HelloProVision', 'nov. 30.'),
    ('Heti riport: hívások, látogatások, visszatért és új partnerek, „NYITOTT RAKTÁR” számlák', 'HelloProVision', 'hétfőnként'),
]
QUESTIONS = [
    ('Dátum', 'Nov. 11. – dec. 18. jó? A mostani −10%-os személyes akció nov. 10-ig fut, utána indulna.'),
    ('Mi kap pöttyöt', 'Javaslat a Partner CRM Készletéből: piros = „nem fogy” (90 napja nem kelt el), sárga = lassan fogy, zöld = a többi; pötty nélkül = új érkezés (60 nap) és a „fogy” lista.'),
    ('Hangtálak', 'Az egyedi, mért tálak is kaphatnak pöttyöt, vagy kimaradnak?'),
    ('Kedvezményszint', 'Nem adódik össze, a kedvezőbb jár – így legyen?'),
    ('Minimum', 'Nincs külön minimum (az új partnernek így könnyebb) – jó?'),
    ('Félretétel', 'Telefonos félretétel 3 munkanapig – jó?'),
    ('Vidék', 'Csak személyesen (a partner vagy a cége munkatársa); futárral nem jár. Így marad?'),
    ('Új partner', 'A helyszínen, adószámmal jelentkező új partner azonnal megkapja?'),
]


def check():
    errs = []
    for h in GOOGLE_HEAD:
        if len(h) > 30:
            errs.append(f'Google cím > 30: {h} ({len(h)})')
    for d in GOOGLE_DESC:
        if len(d) > 90:
            errs.append(f'Google leírás > 90: {d} ({len(d)})')
    for _, s, p in SUBJECTS + SUBJECTS2:
        if len(s) > 65:
            errs.append(f'tárgy > 65: {s} ({len(s)})')
        if len(p) > 110:
            errs.append(f'előnézet > 110: {p} ({len(p)})')
    if len(SMS) > 160:
        errs.append(f'SMS > 160 ({len(SMS)})')
    return errs


if __name__ == '__main__':
    e = check()
    print('\n'.join(e) if e else 'minden hossz rendben')
    print('SMS', len(SMS), '| tárgyak', [len(s) for _, s, _ in SUBJECTS], '| leírások', [len(d) for d in GOOGLE_DESC])

# ── készlet a webshop szerint (élő Google-feed, 2026-11-06: 2 105 raktáron lévő termék) ──
STOCK_DATE = 'november 6-án'
STOCK = [('Füstölő', 457), ('Ruházat', 442), ('Lakberendezés', 409), ('Ékszer és mala', 323),
         ('Szobor, rituális tárgy', 145), ('Hangtál', 144), ('Füstölőtartó', 128), ('Ajándék, réz, tea', 57)]
STOCK_TOTAL = 2105
WHO = 'Ajándék- és lakberendezési boltoknak, jógastúdióknak, masszázs- és terápiás helyeknek, hangterapeutáknak és webáruházaknak.'
STEPS = [('Hívj, vagy gyere be', 'Telefonon elmondjuk, mi van pöttyön, és félretesszük, amit kiválasztottál.'),
         ('Nézd a pöttyöket', 'A polcon a pötty színe a kedvezmény: zöld 10, sárga 30, piros 50% a nagyker árból.'),
         ('Fizess a pultnál', 'ÁFÁ-s számlával, és viszed is magaddal – nem kell futárra várnod.')]
