# Partnerlevél a Partner CRM-ből: személyes vásárlás −10% és új áru (2026. október vége)

Ugyanaz a levél, mint a `partner-nov-hirlevel.html`, csak a Partner CRM **Partnerlevelek** formájában, **Hírlevél** fajtával. A felépítés ugyanaz, mint az októberi `partner-hirlevel-crm.md`-ben. Saját HTML-t itt nem lehet beilleszteni: a CRM a saját sablonjával rakja össze a levelet. Mi ezeket adjuk meg: tárgy, előnézeti szöveg, bevezető, tartalom-blokkok, elköszönés, célcsoport.

**Termék ne legyen benne.** A Hírlevél fajta csak akkor tesz terméktáblát a levélbe, ha terméket választasz, és az a táblázat nagyker árat írna ki. Ezért az újdonságok itt csak szöveges linkként szerepelnek, ár nélkül.

## Mielőtt elindítod

1. **Jogalap.** A levél csak annak megy ki, akinél van partnerlevél-hozzájárulás (a webshopban, az összekötővel; telefonon, személyesen, írásban vagy importtal rögzítve), vagy akinek a céges címét admin jóváhagyta (Partnerlevelek → Céges címek átnézése). A heti új érkezésekről szóló pipa ehhez nem elég. Hogy hány partnernek van jogalapja, a CRM jobb oldalán látod.
2. **Az összekötő.** Október 16-án még nem élt. Mielőtt erre építesz, nézd meg egy partner kartonján, hogy látszik-e már a webshopból érkezett hozzájárulás.
3. **Próbalevél.** A webshop saját levelei (például a jelszó-visszaállítás) nem mentek ki (`docs/FOLYTATAS-PROMPT.md`). A CRM a saját levelezésével küld, de előbb nyomd meg a „Próbalevél nekem” gombot, és nézd meg, megérkezik-e.
4. **Idő.** A kedvezmény november 10-ig él. A CRM munkanapokon 8 és 17 óra között küld, naponta legfeljebb 80 levelet. 80 partner fölött a kiküldés több napig tart, ezért indítsd minél előbb.

Ha ezek közül valami nincs meg, a MailerLite-os változat (`partner-nov-hirlevel.html`) mehet a „Viszonteladók” csoportnak. Ugyanannak a partnernek ne menjen ki mindkettő.

## Az adatcsomag

| Mező | Érték |
|---|---|
| Fajta (`kind`) | `newsletter`, vagyis Hírlevél. A fejlécben a „Hírlevél” címke és a tárgy áll. Saját gombja nincs, a gombok a blokkokban vannak. |
| A levél neve (`name`, csak a CRM-ben látszik) | Partnerlevél 2026-11 – személyes vásárlás −10% és új áru |
| Tárgy (`subject`) | Személyes vásárlásnál 10% a nagyker árakból |
| Előnézeti szöveg (`data.preheader`) | Nettó 100 000 Ft felett, november 10-ig, a Bakáts utcában. Előtte hívj: +36 30 892 8385. |
| Bevezető (`intro`) | lent |
| Tartalom-blokkok (`data.sections`) | 3 blokk, lent |
| Termékek (`data.skus`) | üres, ne válassz terméket |
| Elköszönés (`data.signoff`) | Szeretettel: |
| Célcsoport (`segment`) | szakasz: Aktív partner, Elmaradó, Elveszett, Érdeklődik. A többi szűrő üres. Az Érdeklődik szakaszt lásd lent. |

### Bevezető (tegezve)

A megszólítást a CRM teszi elé: „Kedves Anna,”, ha pedig nincs név, „Kedves Partnerünk,”. Ezért a bevezető **kisbetűvel kezdődik**.

```text
rengeteg új áru érkezett hozzánk, és a legjobb, ha élőben nézed meg. Ezért november 10-ig 10%-ot engedünk a nagyker árakból, ha személyesen vásárolsz a budapesti bemutatótermünkben, és a vásárlásod nettó értéke meghaladja a 100 000 Ft-ot. Mielőtt elindulsz, beszéljük meg telefonon (+36 30 892 8385), mikor jössz.
```

### Tartalom-blokkok

Az üres sor új bekezdést kezd, a sima sortörés megmarad, és a http(s) címekből magától link lesz. A Kép mező üres.

- A gomb címe csak http(s) lehet, `tel:` nem. Ezért nincs „Hívj minket” gomb, a telefonszám a szövegben van. A legtöbb telefonos levelező magától hívható linket csinál belőle.
- Képet egy blokkba legfeljebb egyet lehet tenni, teljes szélességben (548 px). Ha az újdonságokhoz mégis kell kép, a 2. blokkba ez illik: `https://mandala.hu/wp-content/uploads/2026/10/9990000041121-600x600.webp` (Táncoló Shiva). Négyzetes, ezért hosszú lesz tőle a levél.

**1. blokk**
- Cím: `A kedvezmény feltételei`
- Gomb: nincs

```text
10% a nagyker áraidból, személyes vásárlásnál.

– Mikor jár: ha egy vásárlásod nettó értéke meghaladja a 100 000 Ft-ot.
– Hol: személyesen, a bemutatótermünkben: 1093 Budapest, Bakáts u. 6.
– Meddig: 2026. november 10-ig (kedd), aznap is.
– Fontos: nem vonható össze a kedvezményszinteddel és más kedvezménnyel.
```

**2. blokk**
- Cím: `Új áru a polcon – nézd meg élőben`
- Gomb felirata: `Belépek, és nagyker áron nézem` · Gomb címe: `https://mandala.hu/fiokom/nagyker/`

```text
Ízelítő a webshopba legutóbb feltett darabokból – az áraikat belépve, nagyker áron látod. Hogy mi minden jött még, kérdezd meg telefonon, vagy gyere be, és megmutatjuk.

– Guru Rinpoche réz szobor, 25 cm: https://mandala.hu/termek/guru-rinpoche-rez-szobor-9990000041169/
– Táncoló Shiva szobor (Natarádzsa): https://mandala.hu/termek/tancolo-shiva-szobor-m-9990000041121/
– Shiva és Lakshmi réz szobrok: https://mandala.hu/kategoria/szakralis-targyak/hindu-szobrok/
– Szerencsekarkötők kínai szerencsepénzzel, kétféle: https://mandala.hu/termek/szerencsekarkoto-kinai-penz-9990000049424/
– Mandalás hangtálak, egyedi darabok: https://mandala.hu/kategoria/szakralis-targyak/hangtalak/mandalas-healing-hangtal/

A szobrokból csak 1–3 darab van.

Elfelejtetted a jelszavad? Itt kérhetsz újat: https://mandala.hu/fiokom/lost-password/
Ha nem érkezik meg a levél, hívj minket.
```

**3. blokk**
- Cím: `Hívj, telefonon mindent elmondunk`
- Gomb felirata: `Térkép a Kapcsolat oldalon` · Gomb címe: `https://mandala.hu/kapcsolat/`

```text
Telefon: +36 30 892 8385
E-mail: info@mandala.hu

Elmondjuk, mi érkezett és miből mennyi van, átbeszéljük a kedvezményt, és ha keresel valamit, félretesszük, amíg beérsz.

Bemutatóterem és átvevőhely: 1093 Budapest, Bakáts u. 6. A hangtálakat előzetes egyeztetéssel meg is hallgathatod.
Nyitvatartás: hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva.
```

### Ugyanez programból

`POST /wp-json/ucrm/v1/partner/mk/campaigns`, munkatársi belépéssel. Piszkozatot hoz létre, nem küld ki semmit.

```json
{
  "kind": "newsletter",
  "name": "Partnerlevél 2026-11 – személyes vásárlás −10% és új áru",
  "subject": "Személyes vásárlásnál 10% a nagyker árakból",
  "intro": "rengeteg új áru érkezett hozzánk, és a legjobb, ha élőben nézed meg. Ezért november 10-ig 10%-ot engedünk a nagyker árakból, ha személyesen vásárolsz a budapesti bemutatótermünkben, és a vásárlásod nettó értéke meghaladja a 100 000 Ft-ot. Mielőtt elindulsz, beszéljük meg telefonon (+36 30 892 8385), mikor jössz.",
  "data": {
    "skus": [],
    "preheader": "Nettó 100 000 Ft felett, november 10-ig, a Bakáts utcában. Előtte hívj: +36 30 892 8385.",
    "signoff": "Szeretettel:",
    "sections": [
      {
        "title": "A kedvezmény feltételei",
        "text": "10% a nagyker áraidból, személyes vásárlásnál.\n\n– Mikor jár: ha egy vásárlásod nettó értéke meghaladja a 100 000 Ft-ot.\n– Hol: személyesen, a bemutatótermünkben: 1093 Budapest, Bakáts u. 6.\n– Meddig: 2026. november 10-ig (kedd), aznap is.\n– Fontos: nem vonható össze a kedvezményszinteddel és más kedvezménnyel.",
        "image": "",
        "button": "",
        "url": ""
      },
      {
        "title": "Új áru a polcon – nézd meg élőben",
        "text": "Ízelítő a webshopba legutóbb feltett darabokból – az áraikat belépve, nagyker áron látod. Hogy mi minden jött még, kérdezd meg telefonon, vagy gyere be, és megmutatjuk.\n\n– Guru Rinpoche réz szobor, 25 cm: https://mandala.hu/termek/guru-rinpoche-rez-szobor-9990000041169/\n– Táncoló Shiva szobor (Natarádzsa): https://mandala.hu/termek/tancolo-shiva-szobor-m-9990000041121/\n– Shiva és Lakshmi réz szobrok: https://mandala.hu/kategoria/szakralis-targyak/hindu-szobrok/\n– Szerencsekarkötők kínai szerencsepénzzel, kétféle: https://mandala.hu/termek/szerencsekarkoto-kinai-penz-9990000049424/\n– Mandalás hangtálak, egyedi darabok: https://mandala.hu/kategoria/szakralis-targyak/hangtalak/mandalas-healing-hangtal/\n\nA szobrokból csak 1–3 darab van.\n\nElfelejtetted a jelszavad? Itt kérhetsz újat: https://mandala.hu/fiokom/lost-password/\nHa nem érkezik meg a levél, hívj minket.",
        "image": "",
        "button": "Belépek, és nagyker áron nézem",
        "url": "https://mandala.hu/fiokom/nagyker/"
      },
      {
        "title": "Hívj, telefonon mindent elmondunk",
        "text": "Telefon: +36 30 892 8385\nE-mail: info@mandala.hu\n\nElmondjuk, mi érkezett és miből mennyi van, átbeszéljük a kedvezményt, és ha keresel valamit, félretesszük, amíg beérsz.\n\nBemutatóterem és átvevőhely: 1093 Budapest, Bakáts u. 6. A hangtálakat előzetes egyeztetéssel meg is hallgathatod.\nNyitvatartás: hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva.",
        "image": "",
        "button": "Térkép a Kapcsolat oldalon",
        "url": "https://mandala.hu/kapcsolat/"
      }
    ]
  },
  "segment": {
    "stages": [
      "aktiv",
      "elmarado",
      "elveszett",
      "erdeklodik"
    ],
    "types": [],
    "cities": [],
    "categories": [],
    "owner": 0,
    "min_rev": 0,
    "max_rev": 0
  }
}
```

## Kézzel, a felületen

1. Partner CRM → Új partnerek → **Partnerlevelek** → Új levél: **Hírlevél**.
2. Másold be a fentiekből a levél nevét, a **tárgyat**, az **előnézeti szöveget** és a **bevezetőt (tegezve)**.
3. **Tartalom-blokkok:** a 3 blokk címe, szövege és gombja a fentiek szerint. A Kép mező maradjon üres.
4. **Elköszönés:** `Szeretettel:`
5. **Termékek:** ha megjelenik ez a rész, ne válassz semmit.
6. **Kinek megy** → Szakasz: Aktív partner, Elmaradó, Elveszett, Érdeklődik. Jobb oldalt látod, hányan kapják meg, és hányan maradnak ki jogalap nélkül.
7. Nézd meg az előnézetet, aztán nyomd meg a **Próbalevél nekem** gombot. A próbalevél annak megy, aki megnyomta.
8. **Kiküldés…** → most, vagy időzítve. A CRM munkanapokon 8 és 17 óra között küld, naponta legfeljebb 80 levelet, partnerenként hetente legfeljebb kettőt.

## Mit tesz bele magától a CRM

- A fejlécbe a feladó nevét (Beállítások → Partnerlevelek). A blokkok és az elköszönés után a partner felelősének nevét és telefonszámát, és ha van, a beállított aláírást.
- A leiratkozó sort a jogalap szerint. Hozzájárulásnál: „…mert a viszonteladói fiókodban kérted a partnerleveleket.” vagy „…mert kérted, hogy küldjünk partnerleveleket.” Céges címnél: „Ezt a levelet a Mandala üzleti partnereként, céges címedre küldjük.” Utána jön a „Leiratkozás egy kattintással”, a reklám-jelölés a feladó cégnevével és a `List-Unsubscribe` fejléc.
- A cégadatokat a Cégadatokból. Ott ennek kell állnia: Asita Cult Kft., 1093 Budapest, Bakáts u. 6., adószám 12587128-2-43, info@mandala.hu, +36 30 892 8385, mandala.hu.
- Minden linket a saját átirányítóján vezet át, és méri a megnyitást, a kattintást, a 7 napon belüli rendelést és a leiratkozást. Ezért nincs a linkekben UTM. Ha a GA4-ben is külön akarjátok látni, tegyétek a címek végére: `?utm_source=partnerlevel&utm_medium=email&utm_campaign=2026-11-partner` (ahol már van `?`, ott `&`-tel kezdve).
- A CRM a levél utáni 7 napban csak a webshopos rendeléseket méri, a bemutatótermi vásárlást nem. Hogy a levél hozott-e vásárlót a bemutatóterembe, azt a pultnál érdemes felírni.

## Az Érdeklődik szakasz

Az Érdeklődik szakaszban olyan partner is lehet, akinek még nincs jóváhagyott viszonteladói fiókja, így nagyker árat sem lát. Nekik a „10% a nagyker áraidból” félreérthető. Két megoldás van:
- kihagyod őket a célcsoportból;
- vagy bent hagyod őket, mert a személyes látogatás jó alkalom a megismerkedésre. Ilyenkor a pultnál tudni kell, milyen áron vásárolhatnak.

Döntsétek el Lacival.
