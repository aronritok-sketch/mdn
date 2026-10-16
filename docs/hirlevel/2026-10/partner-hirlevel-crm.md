# Partnerlevél a Partner CRM-ből (2026. október)

Ugyanaz a történet, mint a `partner-hirlevel.html`-ben, csak a Partner CRM **Partnerlevelek** formájában, **Hírlevél** fajtával. Ide nem lehet saját HTML-t beilleszteni: a levelet a CRM rakja össze a kötött sablonjából. Mi a tárgyat, az előnézeti szöveget, a bevezetőt, a tartalom-blokkokat, az elköszönést és a célcsoportot adjuk meg.

**Miért Hírlevél, és miért nem Új érkezések?** Az Új érkezések fajta terméktáblát tesz a levélbe, és abban minden terméknél kiírja a nagyker árat. Ez nem kapcsolható ki, és nem veszi figyelembe a partnerek kedvezményszintjét. A Hírlevél fajtában csak akkor van terméktábla, ha terméket választasz hozzá. Ezért ebbe a levélbe **ne tegyél terméket**: így nem kerül bele ár, és a webshop készletére sincs szükség.

## Mielőtt elindítod

1. **Kell a Partner CRM példány, benne a partnerekkel.** A partnerlistát importtal is be lehet tölteni, ehhez nem kell a webshop összekötője.
2. **Jogalap kell.** A Hírlevél csak annak megy, akinél van jogalap:
   - partnerlevél-hozzájárulás: a webshopban (ehhez kell a CRM-összekötő, élőben még nincs fent), vagy telefonon, személyesen, írásban, illetve hozzájárulás-importtal rögzítve;
   - vagy jóváhagyott céges cím (jogi személy általános címe, például info@…): ezt csak admin hagyhatja jóvá, a Partnerlevelek → Céges címek átnézése oldalon.

   Aki csak a webshop „Heti levél az új érkezésekről” pipáját kapcsolta be, ezt a levelet nem kapja meg: az csak az Új érkezések levélre jogalap. Hozzájárulást csak akkor rögzíts kézzel, ha bizonyítani is tudod.
3. **A levélküldést ki kell próbálni.** A webshopban nyitott hiba, hogy nem mennek ki a levelek (`docs/FOLYTATAS-PROMPT.md`). A CRM a saját levelezésével küld, de előbb a „Próbalevél nekem” gombbal nézd meg, hogy megérkezik-e.

Amíg ezek nincsenek meg, a MailerLite-os változat (`partner-hirlevel.html`) mehet ki a „Viszonteladók” csoportnak.

## Az adatcsomag

| Mező | Érték |
|---|---|
| Fajta (`kind`) | `newsletter`, vagyis Hírlevél. A fejléc címkéje „Hírlevél”, a nagy cím a tárgy. Saját gomb nincs, a gombok a blokkokban vannak. |
| A levél neve (`name`, csak a CRM-ben látszik) | Partnerlevél 2026-10 – új felület és újdonságok |
| Tárgy (`subject`) | Új viszonteladói felület: gyorsrendelő, árlista, termékfotók |
| Előnézeti szöveg (`data.preheader`) | Belépve a nagyker árakat látod – és megérkeztek az őszi újdonságok. |
| Bevezető (`intro`) | lent |
| Tartalom-blokkok (`data.sections`) | 5 blokk, lent |
| Termékek (`data.skus`) | üres, ne válassz terméket |
| Elköszönés (`data.signoff`) | Szeretettel: |
| Célcsoport (`segment`) | szakasz: Aktív partner, Elmaradó, Elveszett, Érdeklődik. A többi szűrő üres. |

### Bevezető (tegezve)

A CRM magától elé teszi a megszólítást: „Kedves Anna,” vagy, ha nincs név, „Kedves Partnerünk,”. Ezért a szöveg **kisbetűvel kezdődik**.

```text
megújult a mandala.hu, és elkészült a viszonteladói felületed is. Belépés után a webshopban a nagyker árakat látod, a gyorsrendelő, az árlista és a termékfotók pedig egy helyen várnak. Lent összeszedtük, mit találsz a felületen, mi érkezett mostanában, és mire érdemes figyelni karácsony előtt.
```

### Tartalom-blokkok

Az üres sor új bekezdés, a sima sortörés sortörés marad, a http(s) címekből magától link lesz. Kép nincs bennük.

**1. blokk**
- Cím: `Ezt találod belépés után`
- Gomb felirata: `Belépek a viszonteladói felületre` · Gomb címe: `https://mandala.hu/fiokom/nagyker/`

```text
Mindegyikhez a viszonteladói fiókoddal kell belépned. A felületet a Fiókom menüben, a Viszonteladói felület pontban találod.

– Nagyker árak: böngészés közben a termékeknél a nagyker árad látod, mellette áthúzva a bolti árat.
– Gyorsrendelő: keress név vagy cikkszám szerint, szűrj kategóriára vagy csak a raktáron lévőkre, írd be a darabszámokat, és egy gombbal minden a kosárba kerül.
– Árlista letöltése: cikkszám, kategória, bolti és nagyker ár, készlet, eredet, termékoldal- és képlink, mindig a pillanatnyi állapot szerint. CSV-fájl, Excelben is megnyílik.
– Termékfotók kategóriánként: egy kategória összes termékfotója egy ZIP-fájlban, cikkszám szerint elnevezve.
– Heti levél az új érkezésekről: ha bekapcsolod a felületen, hétfő reggel megírjuk, mi érkezett az előző héten, cikkszámmal és nagyker árral.

Elfelejtetted a jelszavad? Itt kérhetsz újat: https://mandala.hu/fiokom/lost-password/
Ha nem érkezik meg a levél, hívj minket: +36 30 892 8385.
```

**2. blokk**
- Cím: `A megújult webshopban – ezekhez nem kell belépni`
- Gomb felirata: `A hangtálak szűrővel` · Gomb címe: `https://mandala.hu/kategoria/szakralis-targyak/hangtalak/`

```text
Hangtál-szűrő és hangminta: a hangtálakat hang, frekvencia, súly, csakra és készítés szerint is szűrheted. Ahol van hangminta, a termékoldalon meghallgathatod a felvételt – az egyedi daraboknál pontosan azt a tálat kapod, amelyiket hallod.

Kérdezz a termékről: minden termékoldalon kérhetsz hangfelvételt, pontos méretet vagy további fotót – egy munkanapon belül válaszolunk e-mailben. A termékoldalon azt is látod, honnan érkezik a darab.

Hol a csomagom? A rendelésszámmal és az e-mail-címeddel bármikor megnézheted, hol tart a csomagod: https://mandala.hu/csomagkovetes/
```

**3. blokk**
- Cím: `Frissen érkezett`
- Gomb felirata: `Az összes újdonság` · Gomb címe: `https://mandala.hu/termekek/?orderby=date`

```text
Az áraikat belépve, nagyker áron látod.

– Füstölők kartonban: Sandesh indiai füstölők, most kartonban is, 6 doboz egy kartonban. Tíz illat, köztük Holy Amber, Palo Santo és szantálfa: https://mandala.hu/?s=sandesh+karton
– Szerencsekarkötők: piros zsinóron, kínai szerencsepénzzel, állítható méretben. Kis ajándék, ami jól fér a kassza mellé: https://mandala.hu/termek/szerencsekarkoto-kinai-penz-9990000049424/
– Új réz szobrok: Lakshmi, Shiva és a táncoló Shiva, mindegyikből csak 1–3 darab van: https://mandala.hu/kategoria/szakralis-targyak/hindu-szobrok/
– Hangtálak Nepálból: új Full Moon, mintás és mandalás tálak Katmanduból, mindegyikből csak egy darab van: https://mandala.hu/kategoria/szakralis-targyak/hangtalak/
```

**4. blokk**
- Cím: `Ha karácsonyra töltenéd fel a polcot, most érdemes rendelni`
- Gomb felirata: `Rendelek a gyorsrendelőből` · Gomb címe: `https://mandala.hu/fiokom/nagyker/`

```text
A hangtálakból többnyire csak egy darab van raktáron, a réz szobrokból is csak néhány. A raktáron lévő tételeket 1–2 munkanapon belül feladjuk, a GLS általában a feladás utáni munkanapon kézbesít. A rendelésedet a budapesti bemutatóteremben (1093 Budapest, Bakáts u. 6.) ingyen át is veheted.

Ha egy termékből több kellene, mint amennyi raktáron van, hívj vagy írj, és megnézzük, mit tehetünk.
```

**5. blokk**
- Cím: `Kérdésed van? Szólj nyugodtan.`
- Gomb: nincs

```text
Telefon: +36 30 892 8385
E-mail: info@mandala.hu
Bemutatóterem és átvevőhely: 1093 Budapest, Bakáts u. 6. – nézd meg élőben a darabokat, a hangtálakat előzetes egyeztetéssel meg is hallgathatod.

Nyitvatartás, térkép és üzenetküldés a Kapcsolat oldalon: https://mandala.hu/kapcsolat/
Általában egy munkanapon belül válaszolunk.
```

**Utólag beírható mondat** (csak ha belépett partnerrel kipróbáltad, hogy a `https://mandala.hu/fiokom/uzenet/` él; most 404-et ad). Az 5. blokk elejére:

```text
Rendelésről vagy termékről a fiókodból is írhatsz nekünk: Fiókom → Üzenet a Mandalának.
```

### Ugyanez programból

`POST /wp-json/ucrm/v1/partner/mk/campaigns` munkatársi belépéssel. Piszkozatot hoz létre, nem küld ki semmit.

```json
{
  "kind": "newsletter",
  "name": "Partnerlevél 2026-10 – új felület és újdonságok",
  "subject": "Új viszonteladói felület: gyorsrendelő, árlista, termékfotók",
  "intro": "megújult a mandala.hu, és elkészült a viszonteladói felületed is. Belépés után a webshopban a nagyker árakat látod, a gyorsrendelő, az árlista és a termékfotók pedig egy helyen várnak. Lent összeszedtük, mit találsz a felületen, mi érkezett mostanában, és mire érdemes figyelni karácsony előtt.",
  "data": {
    "skus": [],
    "preheader": "Belépve a nagyker árakat látod – és megérkeztek az őszi újdonságok.",
    "signoff": "Szeretettel:",
    "sections": [
      {
        "title": "Ezt találod belépés után",
        "text": "Mindegyikhez a viszonteladói fiókoddal kell belépned. A felületet a Fiókom menüben, a Viszonteladói felület pontban találod.\n\n– Nagyker árak: böngészés közben a termékeknél a nagyker árad látod, mellette áthúzva a bolti árat.\n– Gyorsrendelő: keress név vagy cikkszám szerint, szűrj kategóriára vagy csak a raktáron lévőkre, írd be a darabszámokat, és egy gombbal minden a kosárba kerül.\n– Árlista letöltése: cikkszám, kategória, bolti és nagyker ár, készlet, eredet, termékoldal- és képlink, mindig a pillanatnyi állapot szerint. CSV-fájl, Excelben is megnyílik.\n– Termékfotók kategóriánként: egy kategória összes termékfotója egy ZIP-fájlban, cikkszám szerint elnevezve.\n– Heti levél az új érkezésekről: ha bekapcsolod a felületen, hétfő reggel megírjuk, mi érkezett az előző héten, cikkszámmal és nagyker árral.\n\nElfelejtetted a jelszavad? Itt kérhetsz újat: https://mandala.hu/fiokom/lost-password/\nHa nem érkezik meg a levél, hívj minket: +36 30 892 8385.",
        "image": "",
        "button": "Belépek a viszonteladói felületre",
        "url": "https://mandala.hu/fiokom/nagyker/"
      },
      {
        "title": "A megújult webshopban – ezekhez nem kell belépni",
        "text": "Hangtál-szűrő és hangminta: a hangtálakat hang, frekvencia, súly, csakra és készítés szerint is szűrheted. Ahol van hangminta, a termékoldalon meghallgathatod a felvételt – az egyedi daraboknál pontosan azt a tálat kapod, amelyiket hallod.\n\nKérdezz a termékről: minden termékoldalon kérhetsz hangfelvételt, pontos méretet vagy további fotót – egy munkanapon belül válaszolunk e-mailben. A termékoldalon azt is látod, honnan érkezik a darab.\n\nHol a csomagom? A rendelésszámmal és az e-mail-címeddel bármikor megnézheted, hol tart a csomagod: https://mandala.hu/csomagkovetes/",
        "image": "",
        "button": "A hangtálak szűrővel",
        "url": "https://mandala.hu/kategoria/szakralis-targyak/hangtalak/"
      },
      {
        "title": "Frissen érkezett",
        "text": "Az áraikat belépve, nagyker áron látod.\n\n– Füstölők kartonban: Sandesh indiai füstölők, most kartonban is, 6 doboz egy kartonban. Tíz illat, köztük Holy Amber, Palo Santo és szantálfa: https://mandala.hu/?s=sandesh+karton\n– Szerencsekarkötők: piros zsinóron, kínai szerencsepénzzel, állítható méretben. Kis ajándék, ami jól fér a kassza mellé: https://mandala.hu/termek/szerencsekarkoto-kinai-penz-9990000049424/\n– Új réz szobrok: Lakshmi, Shiva és a táncoló Shiva, mindegyikből csak 1–3 darab van: https://mandala.hu/kategoria/szakralis-targyak/hindu-szobrok/\n– Hangtálak Nepálból: új Full Moon, mintás és mandalás tálak Katmanduból, mindegyikből csak egy darab van: https://mandala.hu/kategoria/szakralis-targyak/hangtalak/",
        "image": "",
        "button": "Az összes újdonság",
        "url": "https://mandala.hu/termekek/?orderby=date"
      },
      {
        "title": "Ha karácsonyra töltenéd fel a polcot, most érdemes rendelni",
        "text": "A hangtálakból többnyire csak egy darab van raktáron, a réz szobrokból is csak néhány. A raktáron lévő tételeket 1–2 munkanapon belül feladjuk, a GLS általában a feladás utáni munkanapon kézbesít. A rendelésedet a budapesti bemutatóteremben (1093 Budapest, Bakáts u. 6.) ingyen át is veheted.\n\nHa egy termékből több kellene, mint amennyi raktáron van, hívj vagy írj, és megnézzük, mit tehetünk.",
        "image": "",
        "button": "Rendelek a gyorsrendelőből",
        "url": "https://mandala.hu/fiokom/nagyker/"
      },
      {
        "title": "Kérdésed van? Szólj nyugodtan.",
        "text": "Telefon: +36 30 892 8385\nE-mail: info@mandala.hu\nBemutatóterem és átvevőhely: 1093 Budapest, Bakáts u. 6. – nézd meg élőben a darabokat, a hangtálakat előzetes egyeztetéssel meg is hallgathatod.\n\nNyitvatartás, térkép és üzenetküldés a Kapcsolat oldalon: https://mandala.hu/kapcsolat/\nÁltalában egy munkanapon belül válaszolunk.",
        "image": "",
        "button": "",
        "url": ""
      }
    ]
  },
  "segment": {
    "stages": ["aktiv", "elmarado", "elveszett", "erdeklodik"],
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
2. A levél neve, **Tárgy**, **Előnézeti szöveg**, **Bevezető (tegezve)**: másold be a fentieket.
3. **Tartalom-blokkok**: az 5 blokk címe, szövege, gombja a fentiek szerint. A Kép mező maradjon üres.
4. **Elköszönés**: `Szeretettel:`
5. **Termékek**: ha megjelenik ez a rész, ne válassz semmit (a terméktábla nagyker árat írna ki).
6. **Kinek megy** → Szakasz: Aktív partner, Elmaradó, Elveszett, Érdeklődik. Jobb oldalt látod, hány partner kapja meg, és hányan maradnak ki jogalap nélkül.
7. Nézd meg az előnézetet, aztán **Próbalevél nekem**. A próbalevél annak megy, aki megnyomta.
8. **Kiküldés…** → most vagy időzítve. A CRM munkanapokon 8 és 17 óra között küld, naponta legfeljebb 80 levelet, partnerenként hetente legfeljebb kettőt.

## Mit tesz bele magától a CRM

- A fejlécben a feladó neve áll (Beállítások → Partnerlevelek), logó nincs. Jobbra „Partnerlevél”, alatta a „Hírlevél” címke és a tárgy nagy címként.
- Utána jön a megszólítás, a bevezető, a blokkok, az elköszönés, majd a partner felelősének neve és telefonszáma, és ha van, a beállított aláírás.
- A leiratkozó sor a jogalap szerint:
  - hozzájárulásnál: „Azért kapod ezt a levelet, mert a viszonteladói fiókodban kérted a partnerleveleket.” (webshopból), vagy „…mert kérted, hogy küldjünk partnerleveleket.” (telefon, személyesen, írásban, import);
  - jóváhagyott céges címnél: „Ezt a levelet a Mandala üzleti partnereként, céges címedre küldjük.” Az első ilyen levél elején külön tájékoztatás áll a tiltakozás jogáról.
  - Mindegyik után: „Leiratkozás egy kattintással”, és hogy a levél reklámot tartalmaz, a feladó cégnevével. Mellé `List-Unsubscribe` fejléc kerül.
- Végül a cégadatok a Cégadatokból. Nézd meg, hogy ott ez áll: Asita Cult Kft., 1093 Budapest, Bakáts u. 6., adószám 12587128-2-43, info@mandala.hu, +36 30 892 8385, mandala.hu.
- Minden linket a saját átirányítóján vezet át, és méri a megnyitást, a kattintást, a 7 napon belüli rendelést és a leiratkozást. Ezért a linkekben nincs UTM. Ha a GA4-ben is külön akarjátok látni, a címek végére ezt tegyétek: `?utm_source=partnerlevel&utm_medium=email&utm_campaign=2026-10-partner` (ahol már van `?`, ott `&`-tel).
- A bemutatótermet a szöveg teljes címmel írja ki. A `{bemutatoterem_cim}` változó alapértéke csak „Budapest, IX. kerület, Bakáts utca”, a `{gyorsrendelo_link}` pedig összekötő nélkül üres lehet, ezért egyiket sem használtam.
