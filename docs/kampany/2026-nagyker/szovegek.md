# Mandala – „Nyitott raktár” nagyker kampány: minden szöveg

2026. november 11. – december 18. – A készlet 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Csak személyesen, a Bakáts utcai bemutatóteremben. Webes rendelésre nem érvényes.

A dátum, a félretétel (3 munkanap), a kedvezményszint-szabály és a minimum **javaslat** – a kampányterv 18. oldalán kell dönteni róluk. Ha valamelyik változik, a `forras/texts.py`-ban kell átírni, és onnan minden újragyártható (PDF, képek, levél, ez a fájl).

Hangnem: a levelek tegezők (mint a korábbi partnerlevelek), a telefonos forgatókönyvek és az új partnereknek szóló levelek magázók (mint a Partner CRM alap forgatókönyvei). Minden a Mandala nevében megy.

## 1. Az ajánlat – egy helyen

- **Zöld pötty:** −10% a nagyker árból
- **Sárga pötty:** −30% a nagyker árból
- **Piros pötty:** −50% a nagyker árból
- Pötty nélkül: a megszokott nagyker ár (újdonságok, a legkeresettebb tételek).

| | Feltétel |
|---|---|
| **Kinek** | Viszonteladóknak: jóváhagyott viszonteladói fiókkal, vagy adószámmal, a helyszínen jelentkezve. |
| **Mennyi** | Tételenként 10, 30 vagy 50% a nagyker árból – a polcon a pötty színe mutatja. |
| **Hol** | Csak személyes vásárlásnál, a bemutatótermünkben: 1093 Budapest, Bakáts u. 6. Webshopos rendelésre – személyes átvétellel sem – nem érvényes. |
| **Telefonon** | Érdeklődhetsz, és félretesszük, amit kiválasztottál: 3 munkanapig vár rád. |
| **Meddig** | 2026. november 11. – december 18., amíg a készlet tart. A pöttyös tételekből sokszor csak pár darab van. |
| **Összevonás** | A kedvezményszinteddel nem adódik össze: mindig a kedvezőbb jár. |

## 2. Telefon

### Forgatókönyv: Nyitott raktár – régi partner

Partner CRM → Új partnerek → Hideghívás → Forgatókönyvek → Új. Meglévő partnereknek: **igen**.

**1. Nyitás**

```text
[Ha tegeződtök, tegezz.]
Jó napot kívánok, {nev} vagyok a Mandalától, a Bakáts utcából. {kapcsolattarto|A bolt vezetőjével} beszélek? Van két perce?

Azért hívom, mert régen járt nálunk, és most olyan ajánlatunk van, amit személyesen szerettem volna elmondani.
```

**2. Ajánlat**

```text
November 11-től december 18-ig kinyitottuk a raktárt: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. A polcon egy színes pötty mutatja, melyik tétel mennyivel: zöld 10, sárga 30, piros 50%.

A kedvezmény személyes vásárlásnál jár, a bemutatóteremben – a webshopban a megszokott árak maradnak.
```

**3. Kérdések**

```text
Mi fogyott a legjobban abból, amit legutóbb vitt?
Mi hiányzik most a polcról karácsonyra?
Volt valami, ami miatt nem rendelt mostanában – szállítás, ár, minőség?
[Írd fel a kartonra – ebből tanulunk.]
```

**4. Félretétel**

```text
[Előtte nézd meg a Készletben, mi van pöttyön a kategóriáiból.]
Abból, amit szokott vinni, most ez és ez van pöttyön. Ha szeretné, félreteszem: 3 munkanapig várja.
```

**5. Időpont**

```text
Mikor tudna bejönni? Hétfőn 9–17, keddtől péntekig 9–15 óráig vagyunk nyitva.
[Eredmény: „Bemutatóterem”, időponttal – a naptárba kerül.]
```

**6. Zárás**

```text
Küldök egy rövid levelet a részletekkel és a címmel. Várjuk szeretettel!
```

**Ha ezt mondja**

- „Nem tudok bejönni Budapestre.” – Értem. A kedvezmény most csak a személyes vásárlásra szól – így tudjuk megmutatni, mi van a polcon. Ha a cégétől más jön Ön helyett, az is személyes vásárlás. [Ha nem megy: a webshopban a megszokott nagyker áron rendelhet.]
- „A webshopban nem lehet megkapni?” – A webshopban a megszokott nagyker árak és a kedvezményszintje maradnak; a pöttyös kedvezmény csak a bemutatóteremben jár. Telefonon viszont elmondom, mi van pöttyön, és félreteszem.
- „Mi van 50%-on?” – Tételenként változik, és sokszor csak pár darab van belőle. Mondja meg, mi érdekli, és most megnézem.
- „Van kedvezményszintem.” – A kettő nem adódik össze: mindig a kedvezőbbet kapja.
- „Most nincs keretem.” – Értem. December 18-ig tart; ha januárra töltené fel a polcot, most olcsóbban teheti meg. Felhívhatom jövő héten?

<details><summary>Ugyanez JSON-ban (a forgatókönyv lépései és kifogásai)</summary>

```json
{
  "name": "Nyitott raktár – régi partner",
  "for_existing": true,
  "steps": [
    {
      "key": "s1",
      "title": "Nyitás",
      "text": "[Ha tegeződtök, tegezz.]\nJó napot kívánok, {nev} vagyok a Mandalától, a Bakáts utcából. {kapcsolattarto|A bolt vezetőjével} beszélek? Van két perce?\n\nAzért hívom, mert régen járt nálunk, és most olyan ajánlatunk van, amit személyesen szerettem volna elmondani."
    },
    {
      "key": "s2",
      "title": "Ajánlat",
      "text": "November 11-től december 18-ig kinyitottuk a raktárt: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. A polcon egy színes pötty mutatja, melyik tétel mennyivel: zöld 10, sárga 30, piros 50%.\n\nA kedvezmény személyes vásárlásnál jár, a bemutatóteremben – a webshopban a megszokott árak maradnak."
    },
    {
      "key": "s3",
      "title": "Kérdések",
      "text": "Mi fogyott a legjobban abból, amit legutóbb vitt?\nMi hiányzik most a polcról karácsonyra?\nVolt valami, ami miatt nem rendelt mostanában – szállítás, ár, minőség?\n[Írd fel a kartonra – ebből tanulunk.]"
    },
    {
      "key": "s4",
      "title": "Félretétel",
      "text": "[Előtte nézd meg a Készletben, mi van pöttyön a kategóriáiból.]\nAbból, amit szokott vinni, most ez és ez van pöttyön. Ha szeretné, félreteszem: 3 munkanapig várja."
    },
    {
      "key": "s5",
      "title": "Időpont",
      "text": "Mikor tudna bejönni? Hétfőn 9–17, keddtől péntekig 9–15 óráig vagyunk nyitva.\n[Eredmény: „Bemutatóterem”, időponttal – a naptárba kerül.]"
    },
    {
      "key": "s6",
      "title": "Zárás",
      "text": "Küldök egy rövid levelet a részletekkel és a címmel. Várjuk szeretettel!"
    }
  ],
  "objections": [
    {
      "q": "Nem tudok bejönni Budapestre.",
      "a": "Értem. A kedvezmény most csak a személyes vásárlásra szól – így tudjuk megmutatni, mi van a polcon. Ha a cégétől más jön Ön helyett, az is személyes vásárlás. [Ha nem megy: a webshopban a megszokott nagyker áron rendelhet.]"
    },
    {
      "q": "A webshopban nem lehet megkapni?",
      "a": "A webshopban a megszokott nagyker árak és a kedvezményszintje maradnak; a pöttyös kedvezmény csak a bemutatóteremben jár. Telefonon viszont elmondom, mi van pöttyön, és félreteszem."
    },
    {
      "q": "Mi van 50%-on?",
      "a": "Tételenként változik, és sokszor csak pár darab van belőle. Mondja meg, mi érdekli, és most megnézem."
    },
    {
      "q": "Van kedvezményszintem.",
      "a": "A kettő nem adódik össze: mindig a kedvezőbbet kapja."
    },
    {
      "q": "Most nincs keretem.",
      "a": "Értem. December 18-ig tart; ha januárra töltené fel a polcot, most olcsóbban teheti meg. Felhívhatom jövő héten?"
    }
  ]
}
```

</details>

### Forgatókönyv: Nyitott raktár – új partner

Partner CRM → Új partnerek → Hideghívás → Forgatókönyvek → Új. Meglévő partnereknek: **nem**.

**1. Nyitás**

```text
[Kit keress: {kapcsolattarto|a bolt vezetőjét vagy a beszerzőt}.]
Jó napot kívánok, {nev} vagyok a Mandalától, Budapestről. A bolt vezetőjével beszélek?

Nagykereskedés vagyunk, saját importból hozunk füstölőt, hangtálat, szobrot, malát Nepálból és Indiából.
```

**2. Kérdések**

```text
Van keleti, spirituális polcuk vagy sarkuk?
Mi fogy most a legjobban, és mire készülnek karácsonyra?
Honnan szereznek be most?
```

**3. Ajánlat**

```text
December 18-ig nyitott raktárt tartunk: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból, személyes vásárlásnál, a Bakáts utcai bemutatóteremben. Elsőre is jó alkalom: élőben látja, mit kap.
```

**4. Jelentkezés**

```text
A viszonteladói jelentkezés a webshopban három lépés, 1–2 munkanap a jóváhagyás. Ha adószámmal bejön, a helyszínen is elintézzük.
```

**5. Zárás**

```text
Elküldöm levélben a jelentkezés linkjét és a címet. Mikor jönne be szívesen? [Eredmény: „Bemutatóterem” vagy „Regisztrál”.]
```

**Ha ezt mondja**

- „Küldjön árlistát.” – Szívesen: a jelentkezés után a webshopban minden terméknél a nagyker árat látja, és az árlista letölthető. Elküldöm a jelentkezés linkjét.
- „Messze vagyunk Budapesttől.” – Értem. A pöttyös kedvezmény csak személyesen jár, de a jelentkezés után a webshopból nagyker áron, futárral is rendelhet.
- „Már van beszállítónk.” – Nem is akarom lecserélni. Sok egyedi darabunk van, amit máshol nem talál – érdemes egyszer megnézni élőben.
- „Nem érdekel.” – Rendben, köszönöm az idejét. [Eredmény: „Nem érdekli” – fél év múlva újra felhozza a CRM.]

<details><summary>Ugyanez JSON-ban (a forgatókönyv lépései és kifogásai)</summary>

```json
{
  "name": "Nyitott raktár – új partner",
  "for_existing": false,
  "steps": [
    {
      "key": "s1",
      "title": "Nyitás",
      "text": "[Kit keress: {kapcsolattarto|a bolt vezetőjét vagy a beszerzőt}.]\nJó napot kívánok, {nev} vagyok a Mandalától, Budapestről. A bolt vezetőjével beszélek?\n\nNagykereskedés vagyunk, saját importból hozunk füstölőt, hangtálat, szobrot, malát Nepálból és Indiából."
    },
    {
      "key": "s2",
      "title": "Kérdések",
      "text": "Van keleti, spirituális polcuk vagy sarkuk?\nMi fogy most a legjobban, és mire készülnek karácsonyra?\nHonnan szereznek be most?"
    },
    {
      "key": "s3",
      "title": "Ajánlat",
      "text": "December 18-ig nyitott raktárt tartunk: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból, személyes vásárlásnál, a Bakáts utcai bemutatóteremben. Elsőre is jó alkalom: élőben látja, mit kap."
    },
    {
      "key": "s4",
      "title": "Jelentkezés",
      "text": "A viszonteladói jelentkezés a webshopban három lépés, 1–2 munkanap a jóváhagyás. Ha adószámmal bejön, a helyszínen is elintézzük."
    },
    {
      "key": "s5",
      "title": "Zárás",
      "text": "Elküldöm levélben a jelentkezés linkjét és a címet. Mikor jönne be szívesen? [Eredmény: „Bemutatóterem” vagy „Regisztrál”.]"
    }
  ],
  "objections": [
    {
      "q": "Küldjön árlistát.",
      "a": "Szívesen: a jelentkezés után a webshopban minden terméknél a nagyker árat látja, és az árlista letölthető. Elküldöm a jelentkezés linkjét."
    },
    {
      "q": "Messze vagyunk Budapesttől.",
      "a": "Értem. A pöttyös kedvezmény csak személyesen jár, de a jelentkezés után a webshopból nagyker áron, futárral is rendelhet."
    },
    {
      "q": "Már van beszállítónk.",
      "a": "Nem is akarom lecserélni. Sok egyedi darabunk van, amit máshol nem talál – érdemes egyszer megnézni élőben."
    },
    {
      "q": "Nem érdekel.",
      "a": "Rendben, köszönöm az idejét. [Eredmény: „Nem érdekli” – fél év múlva újra felhozza a CRM.]"
    }
  ]
}
```

</details>

### Hívólisták (Hideghívás → Hívólisták → Új, szűrőből)

| Lista | Szűrő | Forgatókönyv | Napi adag |
|---|---|---|---|
| Nyitott raktár – elveszett | Szakasz: Elveszett | régi partner | 20 |
| Nyitott raktár – elmaradó | Szakasz: Elmaradó | régi partner | 20 |
| Nyitott raktár – érdeklődő | Szakasz: Érdeklődik | régi partner | 15 |
| Nyitott raktár – aktív | Szakasz: Aktív partner | régi partner (rövid) | 10 |
| Nyitott raktár – új | CSV vagy forrás: Megkeresés | új partner | 15 |

A hívás eredménye: **Bemutatóterem** (időponttal – a naptárba kerül), **Regisztrál**, **Visszahívás**, **Nem érdekli**. A félretett tételt a kartonra írd, a polcon a „Félretéve” kártyára.

A Partner CRM hívás utáni levelében ezek a helyőrzők működnek: `{keresztnev}`, `{kapcsolattarto}`, `{partner}`, `{nev}` (a hívó kolléga), `{idopont}`, `{bemutatoterem}`, `{regisztracio_url}`, `{cim}` – `{x|tartalék}` alakban is. A szögletes zárójeles sort küldés előtt írd át vagy töröld.

### Hívás utáni levél – régi partnernek (tegezve)

Tárgy: **Ahogy beszéltük: nyitott raktár december 18-ig**

```text
Kedves {keresztnev|Partnerünk}!

Köszönöm a beszélgetést. Ahogy telefonon mondtam: december 18-ig a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból – a polcon a pötty színe mutatja, melyik mennyivel. A kedvezmény személyes vásárlásnál jár, a bemutatótermünkben.

[Ha félretettél valamit: Félretettem neked: … – 3 munkanapig vár rád.]
[Ha időpontot is egyeztettetek: Várlak {idopont|a megbeszélt időpontban}.]

Cím: 1093 Budapest, Bakáts u. 6.
Nyitvatartás: Hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva.
Ha közbejön valami, hívj: +36 30 892 8385

Szeretettel:
{nev}, Mandala
```

### Hívás utáni levél – új partnernek (magázva)

Tárgy: **A Mandala nagyker ajánlata – ahogy telefonon beszéltük**

```text
Kedves {kapcsolattarto|Partnerünk}!

Köszönöm, hogy beszélhettünk. Ahogy mondtam, saját importból hozunk füstölőket, hangtálakat, szobrokat és malákat Nepálból és Indiából, és december 18-ig a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból – személyes vásárlásnál, a budapesti bemutatótermünkben.

A viszonteladói jelentkezés három lépés: {regisztracio_url} – 1–2 munkanapon belül jóváhagyjuk, de ha adószámmal bejön, a helyszínen is elintézzük.

Cím: 1093 Budapest, Bakáts u. 6.
Nyitvatartás: Hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva.
Telefon: +36 30 892 8385

Üdvözlettel:
{nev}, Mandala
```

### SMS / Viber – csak annak, akivel telefonon beszéltél, és kérte

```text
Mandala: ahogy beszéltük, dec. 18-ig a készlet 80%-a −10/30/50% a nagyker árból, csak személyesen: Bakáts u. 6., H 9–17, K–P 9–15. Hívj: 06308928385
```

## 3. Partnerlevél – november 11.

**MailerLite, „Viszonteladók” csoport:** kész HTML és szöveges változat: `levelek/partnerlevel-nov11.html`, `.txt`. A fejléckép a téma `assets/ads/mandala-nyitott-raktar-1200x600.jpg` fájlja – a nov. 10-i téma-kiadással kerül ki; ha addig nincs kiadás, töltsd fel a MailerLite fájlkezelőjébe (`levelek/mandala-nyitott-raktar-1200x600.jpg`), és cseréld a képcímet. Az előnézeti szöveg a HTML elején, rejtve benne van – a MailerLite saját előnézeti mezője maradjon üres.

| | Tárgy | Előnézeti szöveg |
|---|---|---|
| **A** | Nyitott raktár: a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb (59) | November 11. – december 18., a nagyker árból, csak személyesen a Bakáts utcában. Hívj: +36 30 892 8385. (103) |
| **B** | Kinyitottuk a raktárt – gyere be, nézd a pöttyöket (50) | Zöld 10%, sárga 30%, piros 50% a nagyker árból, december 18-ig, személyes vásárlásnál. (86) |
| **C** | Rég jártál nálunk? Most érdemes bejönni (39) | A készlet 80%-a pöttyöt kapott: 10, 30 vagy 50% a nagyker árból, december 18-ig. (80) |

**Az A menjen:** benne van az ajánlat és a mérték. A csoport 200 címnél kisebb, A/B tesztnek nincs értelme. Küldés: szerda, nov. 11., 9:00.

### Ugyanez a Partner CRM-ből (Partnerlevelek → Új levél: Hírlevél)

A megszólítást a CRM teszi elé („Kedves Anna,”), ezért a bevezető kisbetűvel kezdődik. Termék ne legyen benne (a terméktábla nagyker árat írna ki). Ugyanannak a partnernek csak az egyik menjen (MailerLite vagy CRM). A gomb címe csak http(s) lehet, ezért a telefonszám a szövegben van.

```json
{
  "kind": "newsletter",
  "name": "Partnerlevél 2026-11-11 – Nyitott raktár",
  "subject": "Nyitott raktár: a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb",
  "intro": "kinyitottuk a raktárt. November 11-től december 18-ig a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Hogy melyik tétel mennyivel, azt a polcon egy színes pötty mutatja. A kedvezmény csak személyes vásárlásnál jár, a budapesti bemutatótermünkben. Ha nem tudod, mi érdekelne, hívj: elmondjuk, mi van pöttyön, és félretesszük, amíg beérsz.",
  "data": {
    "skus": [],
    "preheader": "November 11. – december 18., a nagyker árból, csak személyesen a Bakáts utcában. Hívj: +36 30 892 8385.",
    "signoff": "Szeretettel:",
    "sections": [
      {
        "title": "A pötty színe a kedvezmény",
        "text": "– Zöld pötty: −10% a nagyker árból\n– Sárga pötty: −30% a nagyker árból\n– Piros pötty: −50% a nagyker árból\n– Pötty nélkül: a megszokott nagyker ár (újdonságok, a legkeresettebb tételek).\n\n– Kinek: Viszonteladóknak: jóváhagyott viszonteladói fiókkal, vagy adószámmal, a helyszínen jelentkezve.\n– Mennyi: Tételenként 10, 30 vagy 50% a nagyker árból – a polcon a pötty színe mutatja.\n– Hol: Csak személyes vásárlásnál, a bemutatótermünkben: 1093 Budapest, Bakáts u. 6. Webshopos rendelésre – személyes átvétellel sem – nem érvényes.\n– Telefonon: Érdeklődhetsz, és félretesszük, amit kiválasztottál: 3 munkanapig vár rád.\n– Meddig: 2026. november 11. – december 18., amíg a készlet tart. A pöttyös tételekből sokszor csak pár darab van.\n– Összevonás: A kedvezményszinteddel nem adódik össze: mindig a kedvezőbb jár.",
        "image": "",
        "button": "",
        "url": ""
      },
      {
        "title": "Karácsony előtt",
        "text": "Amit most a polcodra teszel, azt még a karácsonyi szezonban eladhatod. A pöttyös tételekből sokszor csak pár darab van – aki előbb jön, többől választ.\n\nBelépve előre megnézheted, mit keresel, a nagyker árakkal – a pötty szerinti kedvezményt a bemutatóteremben adjuk.",
        "image": "",
        "button": "Belépek, és előre körülnézek",
        "url": "https://mandala.hu/fiokom/nagyker/"
      },
      {
        "title": "Hívj, és félretesszük",
        "text": "Telefon: +36 30 892 8385\nE-mail: info@mandala.hu\n\nElmondjuk, mi van pöttyön abból, amit keresel, és félretesszük, amit kiválasztottál: 3 munkanapig vár rád.\n\nBemutatóterem: 1093 Budapest, Bakáts u. 6.\nNyitvatartás: Hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva.",
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

Programból: `POST /wp-json/ucrm/v1/partner/mk/campaigns` (munkatársi belépéssel) – piszkozatot hoz létre, nem küld ki semmit. Előtte „Próbalevél nekem”.

## 4. Emlékeztető – november 30.

Csak az Elmaradó, Elveszett és Érdeklődik szakasznak: aki nov. 11. óta vásárolt, azt a számla-import addigra Aktívvá tette.

- Tárgy: **Utolsó kör karácsony előtt: december 18-ig nyitva a raktár** · Előnézet: A pöttyös tételek 10, 30 vagy 50%-kal olcsóbbak a nagyker árból. Előtte hívj, és félretesszük.

```text
Kedves {$name|default('Partnerünk')}!

A nyitott raktár december 18-ig tart. A pöttyös tételek most is 10, 30 vagy 50%-kal olcsóbbak a nagyker árból, és amit most a polcodra teszel, azt még karácsony előtt eladhatod.

A kedvezmény csak személyes vásárlásnál jár, a Bakáts utcában. Hívj előtte (+36 30 892 8385), és félretesszük, amit kiválasztottál.

Az utolsó hét december 14–18.: hétfőn 17, keddtől péntekig 15 óráig vagyunk nyitva.

Cím: 1093 Budapest, Bakáts u. 6.
Telefon: +36 30 892 8385

Szeretettel:
a Mandala csapata
```

## 5. Új partnerek – levélsor (Partner CRM → Megkeresés → Levélsorok)

Magázva, csak jóváhagyott céges címre vagy hozzájárulással; a sor végén hívás (call_after: 2 nap). A helyőrzőket a CRM tölti ki.

### 0. nap – Nyitott raktár viszonteladóknak – {cegnev}

```text
{megszolitas}

{felado} vagyok a Mandalától. Budapesten nagykereskedéssel foglalkozunk: saját importból hozunk füstölőket, hangtálakat, szobrokat, malákat és lakberendezési tárgyakat Nepálból és Indiából – boltoknak, stúdióknak, webshopoknak.

December 18-ig nyitott raktárt tartunk: a készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból, személyes vásárlásnál, a budapesti bemutatótermünkben ({bemutatoterem_cim}).

Ha érdekli, a viszonteladói jelentkezés három lépés: {regisztracio_link}. Ha kérdése van, hívjon: +36 30 892 8385.
```

### +4 nap – Egy kérdés a {cegnev} karácsonyi polcáról

```text
{megszolitas}

Rövid kérdésem lenne: mi fogy most Önöknél a legjobban a karácsonyi szezonban?

A füstölőkből, füstölőtartókból, malákból és a kisebb szobrokból sok van most pöttyön – vagyis 10, 30 vagy 50%-kal olcsóbb a nagyker árból, december 18-ig. Ha megírja, mi érdekli, megnézem, mennyi van belőle, és félreteszem.
```

### +7 nap – December 18-ig tart – utoljára jelentkezem

```text
{megszolitas}

Nem szeretném terhelni a postafiókját, ezért ez az utolsó levelem a nyitott raktárról: december 18-ig tart.

Ha most nem aktuális, semmi gond. Ha később kíváncsi lenne a kínálatunkra, itt regisztrálhat viszonteladóként: {regisztracio_link}.
```

## 6. Hirdetések

### Képek (`kepek/`) – a képen lévő szöveg

| Kép | Felirat | Cím | Alcím |
|---|---|---|---|
| `1-raktar-*` | Mandala nagyker · nov. 11. – dec. 18. | Nyitott raktár. | A készlet 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Viszonteladóknak, csak személyesen. |
| `2-regi-*` | Viszonteladóknak · Bakáts u. 6. | Rég jártál nálunk. | Most van miért bejönnöd: a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. |
| `3-uj-*` | Boltoknak, stúdióknak · nagyker | Van egy polcod? Nekünk raktárunk. | Füstölő, hangtál, szobor, mala Nepálból és Indiából. Most a készlet 80%-a 10, 30 vagy 50%-kal olcsóbb. |
| `4-utolso-*` | Utolsó kör · december 18-ig | Még belefér a karácsonyba. | Amit ma a polcodra teszel, azt még karácsony előtt eladhatod. A pöttyök december 18-ig maradnak. |

Mindegyiken: a pötty-csoport (−10 / −30 / −50%, nagyker árból), gomb „Hívj: +36 30 892 8385”, alatta „Bakáts u. 6. · H 9–17 · K–P 9–15”. Méretek: `feed` 1080 × 1350, `story` 1080 × 1920, `square` 1080 × 1080, `land` 1200 × 628, `email` 1200 × 600; levélfejléc szöveg nélkül: `level-fejlec-1200x600.jpg`.

### Google – reszponzív keresési hirdetés

Cél-oldal: https://mandala.hu/viszonteladoknak/ · napi 3 000 Ft · hívás-bővítmény: +36 30 892 8385 · hely-bővítmény a Cégprofilból.

Kulcsszavak (kifejezés-egyezés): "füstölő nagyker", "füstölő nagykereskedés", "hangtál nagyker", "ezoterikus nagyker", "ezoterikus termékek nagykereskedés", "buddha szobor nagyker", "indiai termékek nagyker", "nepáli termékek nagyker", "jóga kellékek nagyker", "mala nagyker", "spirituális termékek nagyker", "ajándéktárgy nagyker budapest", "tibeti termékek nagyker"

Kizárás: állás, munka, ingyen, diy, házilag, jelentése, wikipedia, és a kiskereskedelmi karácsonyi kampány szavai.

| Cím (≤ 30) | Hossz |
|---|---|
| Füstölő és hangtál nagyker | 26 |
| Nyitott raktár Budapesten | 25 |
| −10, −30, −50% a nagyker árból | 30 |
| Közvetlen import Nepálból | 25 |
| Viszonteladóknak, adószámmal | 28 |
| Bakáts utca 6., IX. kerület | 27 |
| A készlet 80%-a kedvezményes | 28 |
| Hívj: +36 30 892 8385 | 21 |
| Szobrok, malák, füstölők | 24 |
| Csak személyes vásárlásnál | 26 |
| Karácsonyi feltöltés | 20 |
| Mandala nagykereskedés | 22 |

| Leírás (≤ 90) | Hossz |
|---|---|
| A készlet 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Csak személyesen. | 83 |
| Füstölők, hangtálak, szobrok, malák Nepálból, Indiából. Viszonteladóknak, ÁFÁ-s számla. | 87 |
| Hívj, és elmondjuk, mi van pöttyön. Félretesszük, amíg beérsz a Bakáts utcába. | 78 |
| Új partner vagy? 3 lépés a jelentkezés, 1–2 munkanap a jóváhagyás. Utána gyere be. | 82 |

**Meta (Facebook, Instagram) fizetett hirdetés: nem javasoljuk** – a közönségünk vásárló, a „−50%” nekik szólna, és nem kaphatják meg. Ha mégis: csak cégoldal-kezelőknek, 40 000 Ft-os próba, a `kepek/` 4:5 és 9:16 képeivel.

## 7. Közösségi oldalak és Cégprofil (organikus)

### Facebook + Instagram · nov. 11.

Kép: `kepek/1-raktar-feed.jpg`

```text
Boltod, jógastúdiód vagy webshopod van? Nyitott raktár a Bakáts utcában.

November 11-től december 18-ig a nagyker készletünk 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból. Hogy melyik tétel mennyivel, azt a polcon egy színes pötty mutatja: zöld 10%, sárga 30%, piros 50%.

A kedvezmény viszonteladóknak szól, és csak személyes vásárlásnál jár – a webshopban a megszokott árak maradnak.

Hívj, és elmondjuk, mi van pöttyön: +36 30 892 8385
Még nem vagy a partnerünk? A jelentkezés pár perc: mandala.hu/viszonteladoknak
```

### Facebook + Instagram · nov. 18.

Kép: saját fotó

```text
[Saját fotó: egy kolléga keze, ahogy pöttyöt ragaszt egy szoborra vagy egy füstölős kartonra.]

Pöttyözünk. A raktárban minden második tétel kap egy színes pöttyöt – zöld, sárga vagy piros –, és ez a pötty a kedvezmény: 10, 30 vagy 50% a nagyker árból, december 18-ig.

Viszonteladóknak, személyesen a Bakáts utcában. Előtte hívj: +36 30 892 8385
```

### Facebook + Instagram · dec. 1.

Kép: `kepek/4-utolso-feed.jpg`

```text
Utolsó kör karácsony előtt.

A nyitott raktár december 18-ig tart: a pöttyös tételek 10, 30 vagy 50%-kal olcsóbbak a nagyker árból. Amit ma a polcodra teszel, azt még karácsony előtt eladhatod.

Viszonteladóknak, csak személyesen: 1093 Budapest, Bakáts u. 6., H 9–17, K–P 9–15.
Hívj, és félretesszük: +36 30 892 8385
```

### Google Cégprofil · Ajánlat (nov. 11. – dec. 18.)

Kép: `kepek/1-raktar-feed.jpg`

```text
Cím: Nyitott raktár viszonteladóknak

A nagyker készletünk 80%-a most 10, 30 vagy 50%-kal olcsóbb a nagyker árból: füstölők, hangtálak, szobrok, malák, lakberendezés. Csak személyes vásárlásnál, a bemutatóteremben, viszonteladói fiókkal vagy adószámmal. Hívj előtte, és félretesszük, amit kiválasztottál.

Gomb: Hívás
```

### Story – 3 kocka (9:16, a `kepek/*-story.jpg` képekre, vagy saját fotóra)

1. **Nyitott raktár.** – A készlet 80%-a pöttyöt kapott.
2. **Zöld −10 · sárga −30 · piros −50** – A nagyker árból, viszonteladóknak.
3. **Csak személyesen.** – Bakáts u. 6. · Hívj: +36 30 892 8385

## 8. Bemutatóterem

**Plakát (A4):** Viszonteladóknak · 2026. november 11. – december 18. · „Nyitott raktár.” · A készlet 80%-a pöttyöt kapott. A pötty színe a kedvezmény a nagyker árból. · a pötty-jelmagyarázat, a feltételek, QR a jelentkezéshez, telefon, cím, nyitvatartás. Alul: „A pöttyös kedvezmény viszonteladóknak szól – vásárlóként a bolti áron vásárolhatsz.”

**Polccímkék (A6):**

- Ezen a polcon a zöld pöttyös tételek 10%-kal olcsóbbak a nagyker árból.
- Ezen a polcon a sárga pöttyös tételek 30%-kal olcsóbbak a nagyker árból.
- Ezen a polcon a piros pöttyös tételek 50%-kal olcsóbbak a nagyker árból.
- Félretéve: Kinek (cég, név) · Telefon · Mit · Eddig vár (3 munkanap) · Ki tette félre

**Pultkártya (A5) – így adjuk a kedvezményt:**

1. Viszonteladó? Webshopos viszonteladói fiók vagy a Partner CRM kartonja. Ha nincs: jelentkezés a helyszínen (mandala.hu/viszonteladoknak, adószámmal), és a CRM-ben azonnal jóváhagyható (Pénzügy jog vagy admin).
2. A kedvezmény tételenként a nagyker árból jár, a pötty színe szerint: zöld 10, sárga 30, piros 50%.
3. Kedvezményszint: nem adódik össze – ha a partner szintje nagyobb, azt kapja.
4. Számla: a megjegyzésbe „NYITOTT RAKTÁR” – ebből mérjük a kampányt a Számlázz.hu-exportban.
5. Félretéve: a „Félretéve” polcon, a partner nevével és a dátummal. 3 munkanap után vissza a polcra.
6. Webshopos rendelésre (személyes átvétellel sem) nem jár. Ha kéri, mondd el, hogy bejöhet, és a pultnál megkapja.
7. Látogatás után a Partner CRM-ben: hívás / látogatás rögzítése a kartonon (miről volt szó, mit vitt).
8. Ha vásárló (nem viszonteladó) kérdezi a pöttyöt: ez a viszonteladói kedvezmény; ő a bolti áron vásárolhat, és a webshop akcióit használhatja.

Számla-megjegyzés minden pöttyös vásárlásnál: **NYITOTT RAKTÁR**.

## 9. Döntés kell (nov. 9-ig)

1. **Dátum:** Nov. 11. – dec. 18. jó? A mostani −10%-os személyes akció nov. 10-ig fut, utána indulna.
2. **Mi kap pöttyöt:** Javaslat a Partner CRM Készletéből: piros = „nem fogy” (90 napja nem kelt el), sárga = lassan fogy, zöld = a többi; pötty nélkül = új érkezés (60 nap) és a „fogy” lista.
3. **Hangtálak:** Az egyedi, mért tálak is kaphatnak pöttyöt, vagy kimaradnak?
4. **Kedvezményszint:** Nem adódik össze, a kedvezőbb jár – így legyen?
5. **Minimum:** Nincs külön minimum (az új partnernek így könnyebb) – jó?
6. **Félretétel:** Telefonos félretétel 3 munkanapig – jó?
7. **Vidék:** Csak személyesen (a partner vagy a cége munkatársa); futárral nem jár. Így marad?
8. **Új partner:** A helyszínen, adószámmal jelentkező új partner azonnal megkapja?
