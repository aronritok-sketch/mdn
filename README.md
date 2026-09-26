# Mandala – webshop prototípus (iu_theme szerkezetben)

A [mandala.hu](https://mandala.hu) új frontendjének HTML-prototípusa. Nepálból és Indiából importált hangtálak, füstölők, szakrális tárgyak, lakberendezési darabok, ruhák és ajándékok webáruháza.

A prototípus az **Infinite Unity (iu_theme)** WordPress-keretrendszer szerkezetére épül (`iu/section › iu/row › iu/column`, `--iu-*` változók, theme.json paletta, klasszikus WooCommerce markup). Így jóváhagyás után közvetlenül `mandala` child témává fordítható.

- **Üzleti elemzés:** [`docs/ELEMZES.md`](docs/ELEMZES.md)
- **Blokktérkép és átadási terv:** [`docs/IU-BLOKKTERKEP.md`](docs/IU-BLOKKTERKEP.md)
- **WordPress child téma (telepíthető):** [`wp-theme/mandala`](wp-theme/mandala/README.md) → `dist/mandala-tema.zip`
- **Telepítő csomag (téma + útmutató + wp-config kiegészítés):** `dist/mandala-telepito-csomag.zip` – útmutató: [`docs/TELEPITES.md`](docs/TELEPITES.md)
- **Design system (élő):** `stilus.html`

## WordPress téma

A prototípusból készült, telepíthető `mandala` child téma az `iu_theme` keretrendszerre: `dist/mandala-tema.zip`
(Megjelenés → Témák → Téma feltöltése). Saját blokkok `iucb_add_block`-kal, sablonfájlok, klasszikus 5 lépéses
WooCommerce pénztár, telepítő (ÁFA, szállítás, fizetés, attribútumok, oldalak, menük), WP-CLI.
Funkciómodulok: hangminta és egyedi darabok, műhelyek QR-es kísérőkártyával, előrendelés, hangtál-választó,
események jegyértékesítéssel, e-mail automatizmusok, fotós értékelések, ajándékcsomag, ajándékutalvány,
hűségpontok, viszonteladói felület, WPML, EU-s szállítás, SEO, mérés (Consent Mode, GA4, Meta CAPI), akadálymentesség.
Részletek, követelmények és élesítési teendők: [`wp-theme/mandala/README.md`](wp-theme/mandala/README.md).

```bash
python3 tools/build-theme.py             # téma frissítése a prototípusból + zip
python3 tools/build-theme.py --content   # a blokk-markup újragenerálása is (utána: wp-theme/dev/canon.mjs)
BASE=http://localhost:8080 node tests/wp-e2e.mjs   # végponttól végpontig teszt egy telepített WordPressen
BASE=http://localhost:8080 WP="wp --path=…" node tests/wp-features.mjs   # a funkciómodulok tesztje
node tests/search.mjs                               # a keresőmotor egységtesztje (böngésző nélkül)
```

## Megtekintés

**Egy fájlban (elküldhető):** `dist/mandala-elonezet.html`. Dupla kattintással megnyílik, a CSS, a JS és a képek is benne vannak.

**Fejlesztéshez:** bármilyen statikus szerver (ES modulok, `file://`-ról nem fut):

```bash
python3 -m http.server 8000      # → http://localhost:8000
```

**Újragenerálás** a forrás módosítása után:

```bash
python3 tools/pages.py           # HTML oldalak a közös <head>-del és az iu szerkezettel
python3 tools/build-single.py    # dist/mandala-elonezet.html
```

## Oldalak

| Oldal | Tartalom |
|---|---|
| `index.html` | Hős (a hét hangtála), bizalmi sáv, szándék szerinti belépés, kategóriák, újdonság-karusszel, eredet (Katmandu → Budapest), hangtál-kalauz, kedvencek, vélemények, magazin |
| `termekek.html` | Saját szűrőrendszer ([`docs/SZURO.md`](docs/SZURO.md)): kategóriafa, szándék, ár (hisztogramos kettős csúszka), hangtálaknál hang / frekvencia / súly / csakra / készítés, füstölőknél illat és típus, ruháknál méret, eredet, régió, anyag, szín, elérhetőség; élő darabszámok, gyors szűrések, lazítási javaslat, URL-állapot, vissza gomb; rendezés, „több betöltése” |
| `termek.html?p=…` | Galéria, cikkszám, bruttó ár + ÁFA, készletállapot (raktáron / utolsó darabok / elfogyott + értesítő), mennyiség készletkorláttal, kedvencek, eredetkártya, adatlap, fülek (leírás, használat, szállítás, kérdés űrlap), ragadós kosárba sáv, Product JSON-LD |
| `kosar.html` | WooCommerce kosártábla, kupon, ingyenes szállítás mérő, összesítő, „ehhez illik” |
| `penztar.html` | Klasszikus pénztár 5 lépésben (lásd lent) |
| `koszonjuk.html` | Rendelés-áttekintés, utalási adatok másolás gombbal, „Mi történik most?” idővonal, rendelés részletei, címek |
| `fiok.html`, `kedvencek.html` | Belépés / regisztráció, kedvencek listája |
| `kereses.html`, `404.html` | Termék- és cikktalálatok kiemeléssel, üres találat; 404 keresővel |
| `magazin.html`, `cikk.html?a=…` | Kategóriaszűrő + lapozás; cikk minden szerkesztői elemmel (H2–H4, lista, idézet, kép, táblázat, gomb) |
| `rolunk.html`, `viszonteladoknak.html`, `kapcsolat.html`, `informaciok.html`, `jogi.html?d=…` | Eredetünk, háromlépéses B2B jelentkezés (adószám-ellenőrzés), kapcsolat, GYIK, ÁSZF / adatkezelés / impresszum |
| `stilus.html` | Design system: paletta kontrasztértékekkel, szövegstílusok, térköz, rács, ikonok, komponensek és állapotok |

Közös elemek: megamenü, mobilmenü, élő kereső (`/` billentyű, nyilas navigáció), minikosár visszavonható törléssel, cookie sáv beállításokkal, hírlevél űrlap.

## A pénztár

1. **Elérhetőség:** e-mail (elírás-javaslat, pl. gmial.com → gmail.com), telefon (+36 formázás).
2. **Szállítási mód:** GLS futár, GLS CsomagPont / csomagautomata (pontválasztó kereséssel), személyes átvétel. Ingyenes 25 000 Ft felett.
3. **Cím:** irányítószámból település, céges vásárlás adószámmal (formátum + ellenőrző számjegy), szállítás másik címre.
4. **Fizetés:** bankkártya (Teya), előre utalás, utánvét (+490 Ft; személyes átvételnél „Fizetés átvételkor”, díj nélkül).
5. **Megrendelés:** megjegyzés vagy ajándékkártya szövege, fiók létrehozása, hírlevél, ÁSZF elfogadása; „Fizetési kötelezettséggel járó megrendelés” gomb a végösszeggel.

További jellemzők:
- mezőnkénti azonnali validáció, beküldéskor hibaösszesítő, amelynek linkjei a hibás mezőre ugranak;
- a módválasztások csak a függő részeket frissítik, így gépelés közben nem ugrik a fókusz;
- mentett piszkozat (az ÁSZF-et újra el kell fogadni);
- mobilon lenyitható összesítő;
- minden ár bruttó, az ÁFA-tartalom külön sorban.

Kipróbálható kuponok: `MANDALA10` (10%), `UDVOZLO` (1 500 Ft, 10 000 Ft felett).

## Felépítés

```
theme/theme.json         paletta és betűk (child téma theme.json)
assets/css/vars.css      tokenek, --iu-* felülírások (child téma vars.css)
assets/css/site.css      vizuális réteg az iu osztályokra (child téma style.css)
assets/css/shop.css      WooCommerce klasszikus markup (child téma assets/shop.css)
assets/css/iu.css        CSAK prototípus: az iu_theme szerkezeti CSS-ét pótolja
assets/js/data.js        konfiguráció (ÁFA, szállítás, fizetés, kuponok), termékek, cikkek
assets/js/facets.js      szűrőmotor és -konfiguráció (mandala/filter)
assets/js/store.js       kosár, kedvencek, kupon, összesítés, rendelések (élesben: WooCommerce)
assets/js/ui.js          fejléc, lábléc, minikosár, kereső, cookie, termékkártya, validáció
assets/js/blocks.js      iu/accordion, iu/tabs, karusszel, bejegyzéskártya, térkép
assets/js/pages/*.js     oldalankénti logika
tools/pages.py           oldalgenerátor
tools/build-single.py    egyfájlos előnézet
tests/*.mjs              Playwright tesztek (wp-e2e.mjs: WordPress + WooCommerce)
tools/build-theme.py     WordPress child téma összeállítása (wp-theme/mandala, dist/mandala-tema.zip)
wp-theme/mandala         a child téma
wp-theme/dev             kanonizáló szkript és iu_theme teszt-helyettesítő (nem része a témának)
```

## Tesztek

```bash
python3 -m http.server 8000 &
node tests/smoke.mjs      # minden oldal asztalon és mobilon: JS-hiba, H1, túlcsordulás, érintési méret
node tests/checkout.mjs   # vásárlás végig: készletkorlát, kupon, hibák, adószám, GLS pont, utánvét, piszkozat, köszönő oldal
node tests/filter.mjs     # szűrő: darabszámok, VAGY/ÉS logika, tartományok, gyors szűrések, üres állapot, URL, vissza gomb, mobil
```

(Playwright kell hozzá: `npm i -D playwright`, vagy a `PWPATH` környezeti változóban megadott telepítés.)

## Ami még nem éles

- **Termékfotók:** a kártyákon SVG illusztrációk. Fotó esetén a `product.image` mező kitöltésével automatikusan a helyükre kerülnek.
- **Helykitöltő adatok:** cégadatok, bankszámla, bemutatóterem címe, szállítási díjak, jogi szövegek (`CONFIG` és `jogi.html`).
- **Szimulált háttérfolyamatok:** a rendelés, a fizetés, a fiók és az űrlapok beküldése csak szimulált; élesben ezeket a WooCommerce és az iu/form végzi.
- **Nyitott tételek:** a teljes lista a [`docs/IU-BLOKKTERKEP.md`](docs/IU-BLOKKTERKEP.md) 7. pontjában van.
