# Mandala nagyker 2026 („Nyitott raktár”) – minden szöveg másolható formában: ../out/szovegek.md
import json, os
from texts import (NAME, LINE, ONLY, PERIOD, DOTS, NODOT, RULES, ADS, AD_CTA, AD_FOOT, GOOGLE_KW, GOOGLE_NEG, GOOGLE_HEAD, GOOGLE_DESC,
                   GOOGLE_URL, SOCIAL, STORY, SUBJECTS, SUBJECTS2, MAIL1, MAIL2, MAIL_CALL_OLD, MAIL_CALL_NEW, SEQUENCE, SMS, SCRIPT_OLD,
                   SCRIPT_NEW, COUNTER, POSTER, SHELF, PHONE, ADDRESS, HOURS, EMAIL, QUESTIONS)

B = os.path.dirname(os.path.abspath(__file__))
strip = lambda s: s.replace('<br>', ' ').replace('<em>', '').replace('</em>', '').replace('<i>', '').replace('</i>', '').replace(' ', ' ')
L = []
a = L.append

a('# Mandala – „Nyitott raktár” nagyker kampány: minden szöveg')
a('')
a(f'{PERIOD} – {LINE} {ONLY}')
a('')
a('A dátum, a félretétel (3 munkanap), a kedvezményszint-szabály és a minimum **javaslat** – a kampányterv 18. oldalán kell dönteni róluk. '
  'Ha valamelyik változik, a `forras/texts.py`-ban kell átírni, és onnan minden újragyártható (PDF, képek, levél, ez a fájl).')
a('')
a('Hangnem: a levelek tegezők (mint a korábbi partnerlevelek), a telefonos forgatókönyvek és az új partnereknek szóló levelek magázók (mint a Partner CRM alap forgatókönyvei). '
  'Minden a Mandala nevében megy.')
a('')

a('## 1. Az ajánlat – egy helyen')
a('')
for d in DOTS:
    a(f'- **{d["name"]}:** {d["text"]}')
a(f'- {NODOT}')
a('')
a('| | Feltétel |')
a('|---|---|')
for k, v in RULES:
    a(f'| **{k}** | {v} |')
a('')

a('## 2. Telefon')
a('')
for sc in (SCRIPT_OLD, SCRIPT_NEW):
    a(f'### Forgatókönyv: {sc["name"]}')
    a('')
    a(f'Partner CRM → Új partnerek → Hideghívás → Forgatókönyvek → Új. Meglévő partnereknek: **{"igen" if sc["for_existing"] else "nem"}**.')
    a('')
    for i, (t, x) in enumerate(sc['steps'], 1):
        a(f'**{i}. {t}**')
        a('')
        a('```text')
        a(x)
        a('```')
        a('')
    a('**Ha ezt mondja**')
    a('')
    for q, ans in sc['objections']:
        a(f'- „{q}” – {ans}')
    a('')
    a('<details><summary>Ugyanez JSON-ban (a forgatókönyv lépései és kifogásai)</summary>')
    a('')
    a('```json')
    a(json.dumps({'name': sc['name'], 'for_existing': sc['for_existing'],
                  'steps': [{'key': f's{i}', 'title': t, 'text': x} for i, (t, x) in enumerate(sc['steps'], 1)],
                  'objections': [{'q': q, 'a': ans} for q, ans in sc['objections']]}, ensure_ascii=False, indent=2))
    a('```')
    a('')
    a('</details>')
    a('')
a('### Hívólisták (Hideghívás → Hívólisták → Új, szűrőből)')
a('')
a('| Lista | Szűrő | Forgatókönyv | Napi adag |')
a('|---|---|---|---|')
a('| Nyitott raktár – elveszett | Szakasz: Elveszett | régi partner | 20 |')
a('| Nyitott raktár – elmaradó | Szakasz: Elmaradó | régi partner | 20 |')
a('| Nyitott raktár – érdeklődő | Szakasz: Érdeklődik | régi partner | 15 |')
a('| Nyitott raktár – aktív | Szakasz: Aktív partner | régi partner (rövid) | 10 |')
a('| Nyitott raktár – új | CSV vagy forrás: Megkeresés | új partner | 15 |')
a('')
a('A hívás eredménye: **Bemutatóterem** (időponttal – a naptárba kerül), **Regisztrál**, **Visszahívás**, **Nem érdekli**. A félretett tételt a kartonra írd, a polcon a „Félretéve” kártyára.')
a('')
a('A Partner CRM hívás utáni levelében ezek a helyőrzők működnek: `{keresztnev}`, `{kapcsolattarto}`, `{partner}`, `{nev}` (a hívó kolléga), `{idopont}`, `{bemutatoterem}`, `{regisztracio_url}`, `{cim}` – `{x|tartalék}` alakban is. A szögletes zárójeles sort küldés előtt írd át vagy töröld.')
a('')
a('### Hívás utáni levél – régi partnernek (tegezve)')
a('')
a(f'Tárgy: **{MAIL_CALL_OLD["subject"]}**')
a('')
a('```text')
a(MAIL_CALL_OLD['body'])
a('```')
a('')
a('### Hívás utáni levél – új partnernek (magázva)')
a('')
a(f'Tárgy: **{MAIL_CALL_NEW["subject"]}**')
a('')
a('```text')
a(MAIL_CALL_NEW['body'])
a('```')
a('')
a('### SMS / Viber – csak annak, akivel telefonon beszéltél, és kérte')
a('')
a('```text')
a(SMS)
a('```')
a('')

a('## 3. Partnerlevél – november 11.')
a('')
a('**MailerLite, „Viszonteladók” csoport:** kész HTML és szöveges változat: `levelek/partnerlevel-nov11.html`, `.txt`. A fejléckép a téma '
  '`assets/ads/mandala-nyitott-raktar-1200x600.jpg` fájlja – a nov. 10-i téma-kiadással kerül ki; ha addig nincs kiadás, töltsd fel a '
  'MailerLite fájlkezelőjébe (`levelek/mandala-nyitott-raktar-1200x600.jpg`), és cseréld a képcímet. Az előnézeti szöveg a HTML elején, rejtve '
  'benne van – a MailerLite saját előnézeti mezője maradjon üres.')
a('')
a('| | Tárgy | Előnézeti szöveg |')
a('|---|---|---|')
for k, s, p in SUBJECTS:
    a(f'| **{k}** | {s} ({len(s)}) | {p} ({len(p)}) |')
a('')
a('**Az A menjen:** benne van az ajánlat és a mérték. A csoport 200 címnél kisebb, A/B tesztnek nincs értelme. Küldés: szerda, nov. 11., 9:00.')
a('')
a('### Ugyanez a Partner CRM-ből (Partnerlevelek → Új levél: Hírlevél)')
a('')
a('A megszólítást a CRM teszi elé („Kedves Anna,”), ezért a bevezető kisbetűvel kezdődik. Termék ne legyen benne (a terméktábla nagyker árat írna ki). '
  'Ugyanannak a partnernek csak az egyik menjen (MailerLite vagy CRM). A gomb címe csak http(s) lehet, ezért a telefonszám a szövegben van.')
a('')
intro_crm = MAIL1['intro'][0][0].lower() + MAIL1['intro'][0][1:] + ' ' + MAIL1['intro'][1]
crm = {
    'kind': 'newsletter', 'name': 'Partnerlevél 2026-11-11 – Nyitott raktár', 'subject': SUBJECTS[0][1], 'intro': intro_crm,
    'data': {'skus': [], 'preheader': SUBJECTS[0][2], 'signoff': 'Szeretettel:', 'sections': [
        {'title': 'A pötty színe a kedvezmény', 'text': '\n'.join(f'– {d["name"]}: {d["text"]}' for d in DOTS) + '\n– ' + NODOT + '\n\n' + '\n'.join(f'– {k}: {v}' for k, v in RULES),
         'image': '', 'button': '', 'url': ''},
        {'title': 'Karácsony előtt', 'text': MAIL1['blocks'][2][1][0] + '\n\nBelépve előre megnézheted, mit keresel, a nagyker árakkal – a pötty szerinti kedvezményt a bemutatóteremben adjuk.',
         'image': '', 'button': 'Belépek, és előre körülnézek', 'url': 'https://mandala.hu/fiokom/nagyker/'},
        {'title': 'Hívj, és félretesszük', 'text': f'Telefon: {PHONE}\nE-mail: {EMAIL}\n\nElmondjuk, mi van pöttyön abból, amit keresel, és félretesszük, amit kiválasztottál: 3 munkanapig vár rád.\n\n'
         f'Bemutatóterem: {ADDRESS}\nNyitvatartás: {HOURS}', 'image': '', 'button': 'Térkép a Kapcsolat oldalon', 'url': 'https://mandala.hu/kapcsolat/'},
    ]},
    'segment': {'stages': ['aktiv', 'elmarado', 'elveszett', 'erdeklodik'], 'types': [], 'cities': [], 'categories': [], 'owner': 0, 'min_rev': 0, 'max_rev': 0},
}
a('```json')
a(json.dumps(crm, ensure_ascii=False, indent=2))
a('```')
a('')
a('Programból: `POST /wp-json/ucrm/v1/partner/mk/campaigns` (munkatársi belépéssel) – piszkozatot hoz létre, nem küld ki semmit. Előtte „Próbalevél nekem”.')
a('')

a('## 4. Emlékeztető – november 30.')
a('')
a('Csak az Elmaradó, Elveszett és Érdeklődik szakasznak: aki nov. 11. óta vásárolt, azt a számla-import addigra Aktívvá tette.')
a('')
for k, s, p in SUBJECTS2:
    a(f'- Tárgy: **{s}** · Előnézet: {p}')
a('')
a('```text')
a("Kedves {$name|default('Partnerünk')}!\n\n" + MAIL2['intro'][0][0].upper() + MAIL2['intro'][0][1:] + '\n\n' + '\n\n'.join(MAIL2['intro'][1:]) +
  f'\n\nCím: {ADDRESS}\nTelefon: {PHONE}\n\nSzeretettel:\na Mandala csapata')
a('```')
a('')

a('## 5. Új partnerek – levélsor (Partner CRM → Megkeresés → Levélsorok)')
a('')
a('Magázva, csak jóváhagyott céges címre vagy hozzájárulással; a sor végén hívás (call_after: 2 nap). A helyőrzőket a CRM tölti ki.')
a('')
for d, s, b in SEQUENCE:
    a(f'### {"0. nap" if d == 0 else f"+{d} nap"} – {s}')
    a('')
    a('```text')
    a(b)
    a('```')
    a('')

a('## 6. Hirdetések')
a('')
a('### Képek (`kepek/`) – a képen lévő szöveg')
a('')
a('| Kép | Felirat | Cím | Alcím |')
a('|---|---|---|---|')
for i, (k, v) in enumerate(ADS.items(), 1):
    a(f'| `{i}-{k}-*` | {strip(v["eyebrow"])} | {strip(v["h1"])} | {v["sub"]} |')
a('')
a(f'Mindegyiken: a pötty-csoport (−10 / −30 / −50%, nagyker árból), gomb „{strip(AD_CTA)}”, alatta „{AD_FOOT}”. Méretek: `feed` 1080 × 1350, `story` 1080 × 1920, `square` 1080 × 1080, `land` 1200 × 628, `email` 1200 × 600; levélfejléc szöveg nélkül: `level-fejlec-1200x600.jpg`.')
a('')
a('### Google – reszponzív keresési hirdetés')
a('')
a(f'Cél-oldal: {GOOGLE_URL} · napi 3 000 Ft · hívás-bővítmény: {PHONE} · hely-bővítmény a Cégprofilból.')
a('')
a('Kulcsszavak (kifejezés-egyezés): ' + ', '.join(f'"{k}"' for k in GOOGLE_KW))
a('')
a('Kizárás: ' + ', '.join(GOOGLE_NEG) + ', és a kiskereskedelmi karácsonyi kampány szavai.')
a('')
a('| Cím (≤ 30) | Hossz |')
a('|---|---|')
for h in GOOGLE_HEAD:
    a(f'| {h} | {len(h)} |')
a('')
a('| Leírás (≤ 90) | Hossz |')
a('|---|---|')
for d in GOOGLE_DESC:
    a(f'| {d} | {len(d)} |')
a('')
a('**Meta (Facebook, Instagram) fizetett hirdetés: nem javasoljuk** – a közönségünk vásárló, a „−50%” nekik szólna, és nem kaphatják meg. Ha mégis: csak cégoldal-kezelőknek, 40 000 Ft-os próba, a `kepek/` 4:5 és 9:16 képeivel.')
a('')

a('## 7. Közösségi oldalak és Cégprofil (organikus)')
a('')
for t, img, x in SOCIAL:
    a(f'### {t}')
    a('')
    a(f'Kép: {"`kepek/" + str(list(ADS).index(img) + 1) + "-" + img + "-feed.jpg`" if img in ADS else img}')
    a('')
    a('```text')
    a(x)
    a('```')
    a('')
a('### Story – 3 kocka (9:16, a `kepek/*-story.jpg` képekre, vagy saját fotóra)')
a('')
for k, t, x in STORY:
    a(f'{k}. **{t}** – {x}')
a('')

a('## 8. Bemutatóterem')
a('')
a(f'**Plakát (A4):** {POSTER["kick"]} · „{POSTER["title"]}” · {POSTER["lead"]} · a pötty-jelmagyarázat, a feltételek, QR a jelentkezéshez, telefon, cím, nyitvatartás. '
  'Alul: „A pöttyös kedvezmény viszonteladóknak szól – vásárlóként a bolti áron vásárolhatsz.”')
a('')
a('**Polccímkék (A6):**')
a('')
for k, t in SHELF:
    a(f'- {t}')
a('- Félretéve: Kinek (cég, név) · Telefon · Mit · Eddig vár (3 munkanap) · Ki tette félre')
a('')
a('**Pultkártya (A5) – így adjuk a kedvezményt:**')
a('')
for i, x in enumerate(COUNTER, 1):
    a(f'{i}. {x}')
a('')
a('Számla-megjegyzés minden pöttyös vásárlásnál: **NYITOTT RAKTÁR**.')
a('')

a('## 9. Döntés kell (nov. 9-ig)')
a('')
for i, (k, v) in enumerate(QUESTIONS, 1):
    a(f'{i}. **{k}:** {v}')
a('')

open(os.path.join(B, '..', 'out', 'szovegek.md'), 'w').write('\n'.join(L))
print('szovegek.md', len('\n'.join(L)), 'karakter')
