# Mandala webshop – átadási kézikönyv

Ez a dokumentum a kész rendszer átadásához készült: mi van benne, hogyan kell élesíteni, mit kell
rendszeresen csinálni, és hogyan kell használni a marketingeszközöket. A telepítés részletes lépései:
[TELEPITES.md](TELEPITES.md), az új termékek és a Claude migráció: [UJ-TERMEKEK.md](UJ-TERMEKEK.md).

---

## 1. Mit tartalmaz az átadás

| Elem | Hol | Mire való |
|---|---|---|
| **Mandala téma** | `dist/mandala-tema.zip` | A teljes bolt: megjelenés, vásárlás, marketing, admin eszközök (gyerektéma az iu_theme keretrendszerre) |
| **Telepítő varázsló** | `dist/mandala-telepito-varazslo.zip` | Egy kattintásos telepítés: oldalak, menük, képek, kategóriák, beállítások, önellenőrzés |
| **Teljes csomag** | `dist/mandala-telepito-csomag.zip` | A kettő együtt |
| **Forráskód** | `wp-theme/mandala/`, `tools/`, `assets/` | Fejlesztéshez; a zip a `tools/build-theme.py`-val készül |
| **Tesztek** | `tests/wp-e2e.mjs`, `tests/wp-features.mjs`, `tests/wp-marketing.mjs` | Végponttól végpontig tesztek (vásárlás, funkciók, marketing) |
| **Dokumentáció** | `docs/` | Telepítés, új termékek, szűrő, terheléses teszt, blokktérkép, ez a kézikönyv |

Követelmény: WordPress 6.7+, WooCommerce 9+, PHP 8.1+, az iu_theme szülőtéma. Fizetős bővítmény nem kell;
a Claude (AI) funkciókhoz Anthropic API-kulcs.

---

## 2. Élesítés – röviden

1. Szülőtéma (iu_theme) + WooCommerce telepítve, bekapcsolva.
2. **Megjelenés → Témák → Új hozzáadása → Téma feltöltése** → `mandala-tema.zip` → bekapcsolás
   (frissítésnél: **Csere a feltöltöttre**). Az első admin betöltéskor a nem szerkesztett oldalak maguktól frissülnek.
3. **Megjelenés → Mandala telepítő** (vagy a varázsló bővítmény): lépésenként végigmegy, a végén önellenőrzés.
4. **A régi bolt beállításainak átvétele** (WooCommerce → Régi bolt beállításai): az oldalon lévő szöveget
   a régi bolt adminjában a böngészőkonzolba másolva letöltődik egy fájl (fizetési módok a kulcsokkal,
   szállítási zónák és díjak, adók, WooCommerce-, levél- és fiókbeállítások, bővítménylista); ezt feltöltve
   előnézet, majd egy kattintással átvétel. A jogi oldalakat (ÁSZF, adatvédelmi tájékoztató, „A bankkártyás
   fizetésről”) is átveszi a régi szöveggel, oldalépítő-jelölések nélkül; a Fogyasztóbarát ÁSZF-beágyazás
   `[mandala_fogyasztobarat]` rövidkódként megmarad. Az utánvét „csak ezeknél a szállítási módoknál” listáját
   az új szállítási módokra fordítja, a régi címben szereplő utánvéti díjat („Utánvétes fizetés (390 Ft)”)
   pedig a téma díjbeállításába teszi (a régi díjbővítmény nem kell). Előbb telepítsd a régi bolt fizetési / szállítási / számlázó
   bővítményeit (az oldal listázza, melyik hiányzik), mert azok beállításai csak így jönnek át. Visszavonható.
   A fájlt utána töröld a gépedről (fizetési kulcsok vannak benne).
5. Beállítások az adminban: [TELEPITES.md 4. pont](TELEPITES.md) – táblázat, sorrendben.
   **Ellenőrzés távolról:** a `/wp-json/mandala/v1/readiness` cím (csak adminnak / boltkezelőnek) egyben
   megmutatja az élesítés állapotát: varázslólépések, önellenőrzés, bővítmények, fizetési és szállítási módok,
   adók, termékek (kép / ár nélküliek, jóváhagyási sor), jogi oldalak kitöltése, levélküldés, kulcsok megléte
   (az értékük nélkül), háttérfeladatok, 404 napló. `?deep=1` a teljes önellenőrzéssel (oldalbetöltések is).
   Egy ideiglenes felhasználó alkalmazásjelszavával (Felhasználók → Profil → Alkalmazásjelszavak) a fejlesztő
   belépés nélkül, csak olvasva átnézheti; utána a jelszót vagy a felhasználót töröld.
6. Próbarendelések: [TELEPITES.md 6. pont](TELEPITES.md).
7. Élesítés napja: [TELEPITES.md 7. pont](TELEPITES.md) (301-es átirányítások, feedek, kereső-konzol).

---

## 3. Rendszeres teendők

| Mikor | Mit | Hol |
|---|---|---|
| **Naponta** | Új rendelések feldolgozása, csomagfeladás (a feladási levél magától megy a csomagszámmal) | WooCommerce → Rendelések |
| **Naponta** | Új JUTA-termékek jóváhagyása (az „ellenőrizendő” sor) | Termékek → Új termékek |
| **Hetente** | A hétfői **tulajdonosi kivonat** levél átolvasása (bevétel az előző héthez, konverzió, tölcsér, mi hozta a bevételt, kifogyó termékek, keresések) | e-mail |
| **Bármikor** | Részletes számok 7 / 30 / 90 / 365 napra, az előző időszakhoz mérve | **Kivonat** (bal oldali menü, a Vezérlőpult alatt) |
| **Hetente** | Értékelések jóváhagyása (fotósok a főoldalra is kikerülnek) | Értékelések |
| **Hetente** | Visszaküldések kezelése | WooCommerce → Visszaküldések |
| **Havonta** | „Nincs találat” keresések → szinonimák | WooCommerce → Mandala kereső |
| **Havonta** | Partner jutalékok kifizetése, rögzítése | WooCommerce → Partnerek |
| **Havonta** | Levélnapló, leiratkozások átnézése | WooCommerce → Mandala levelek → Napló |
| **Kampány előtt 2 héttel** | Kampány beállítása sablonból, kupon létrehozása | WooCommerce → Kampányok |
| **Baj esetén** | Az Őrszem levelet küld (6 óránként legfeljebb egyszer ugyanarról) | Vezérlőpult → Mandala őrszem |

---

## 3a. Tulajdonosi kivonat (mérés)

Az admin **Kivonat** menüje egy képernyőn mutatja a bolt állapotát, a hétfői levél ugyanezt küldi 7 napra.

- **Bevétel, rendelés, átlagos kosárérték** – az előző ugyanilyen hosszú időszakhoz mérve (▲/▼ %).
- **Látogató, konverzió, új / visszatérő vásárló** – a látogatót a bolt maga számolja: süti nélkül, naponta
  változó, visszafejthetetlen azonosítóval; a bejelentkezett munkatársak és a robotok nem számítanak.
- **Vásárlási tölcsér** – látogató → terméket nézett → kosárba tett → pénztárba lépett → rendelt.
- **Mi hozta a bevételt** – kuponforrások (üdvözlő, születésnapi, ajánlási, partner, kampány), automata levél
  utáni rendelések (7 napon belül kapott levél), csomagkedvezmény, ajándék, előfizetés, partnerek.
- **Röviden** – aktív előfizetések és havi ismétlődő bevétel, AI tanácsadó (beszélgetés, költség), kereső
  (eredménytelen arány), kiküldött levelek, értékelések, partnerjutalék, szerveroldali betöltési idő, háttérfeladatok.

A levél címzettje és az AI „Mire figyelj” bekezdés: **WooCommerce → Mandala levelek → Beállítások → Heti
tulajdonosi kivonat** (ugyanitt „küldés most” próbához). A mérés az élesítés napjától gyűjt; az első teljes
összevetés 2× annyi nap után látszik.

---

## 3b. Bemutató mód (dev / tesztoldalra)

**Kivonat → Bemutató mód → Bemutató mód bekapcsolása**: egy kattintással minden marketingeszköz fut a valós
termékekkel, hogy látszódjon, milyen a bolt „teljes gőzzel”:

- futó kampány („Csendes hét”) felső sávval, visszaszámlálóval, főoldali bannerrel és automatikus 10%-os kuponnal;
- 8 akciós termék (-15%), ajándék 20 000 Ft felett (egy füstölő), 3 csomagkedvezmény (-10%), füstölő-előfizetés;
- üdvözlő / kilépési ablak, születésnapi kupon, ajánlás; bemutató partner (kód: `JOGA10`, link: `?partner=JOGA10`);
- 8 értékelés (fotósok a főoldali vásárlói fotók között), 2 esemény jeggyel, 1 érkező szállítmány;
- a Kivonat és a heti levél élethű **demóadatokat** mutat (a Kivonatban egy kattintással átváltható a valósra).

**Biztonság:** amíg be van kapcsolva, **minden kimenő levél** (a rendelési levelek is) a megadott címre megy –
a teszt oldalon lévő valós vásárlói címekre semmi nem jut ki. A felső admin sávban „BEMUTATÓ MÓD” jelzés látszik.

**Kikapcsolás és visszaállítás:** ugyanitt. A beállítások a bekapcsolás előtti állapotra állnak vissza, a
bemutató elemek (kupon, értékelések, események, partner) törlődnek, az árak és a készletjelzők az eredetiek.
Parancssorból: `wp mandala showcase on --mailto=cim@example.com` / `wp mandala showcase off`.
Éles boltba ne kapcsold be.

---

## 4. Marketingeszközök – kézikönyv

Minden automata levél szövege, be/ki kapcsolása és előnézete: **WooCommerce → Mandala levelek**.
A leiratkozott vásárló marketing levelet nem kap; a rendelési (tranzakciós) levelek mindig mennek.

### 4.1 Önjáró eszközök (beállítás után nincs vele munka)

| Eszköz | Mit csinál | Beállítás | Ajánlott kezdés |
|---|---|---|---|
| **Feliratkozó ablak** | Első vásárlási kupon feliratkozásért; időzítve és/vagy kilépési szándékra (mobilon is) | Mandala kuponok | 10%, 14 nap, „időzítve + kilépéskor”, 25 mp |
| **Ajándék értékhatár felett** | A határ felett ingyen ajándék kerül a kosárba; sáv mutatja, mennyi hiányzik | Mandala kuponok | 15 000 Ft, egy olcsó füstölő cikkszáma |
| **Ingyenes szállítás sáv** | A kosárban és a minikosárban | Mandala bolt adatai | 25 000 Ft |
| **Csomagkedvezmény** | „Csomagban olcsóbb” a termékoldalon, a kosárban automatikus kedvezmény | Mandala csomagok | 3–5 csomag (hangtál + ütő + párna; füstölő + tartó), 10% |
| **Előfizetés** | Fogyóeszközök ismétlődő rendelése kedvezménnyel, emlékeztetővel | Előfizetések | 10%, havonta / kéthavonta, füstölők, illóolajok, teák |
| **Elhagyott kosár** | Levél a kosárban hagyott termékekkel | Mandala levelek | 3 óra |
| **Elhagyott böngészés** | A megnézett termékek levélben (feliratkozóknak) | Mandala levelek | 24 óra |
| **Árcsökkenés-értesítő** | A megnézett termék legalább 5%-kal olcsóbb lett | Mandala levelek | bekapcsolva |
| **Kedvenc akciós / fogyóban** | Kedvencekre | Mandala levelek | bekapcsolva |
| **Újra raktáron** | Az értesítést kérőknek | automatikus | – |
| **Újrarendelés** | Fogyóeszközök után X nappal | Mandala levelek | 40 nap |
| **Vásárlás utáni ajánló** | 3 odaillő termék a valódi együttes vásárlásokból | Mandala levelek | 14 nap |
| **Értékeléskérés** | Fotós értékelés, bejelentkezés nélkül | Mandala levelek | 10 nap |
| **Születésnapi kupon** | A vásárló születésnapján reggel | Mandala kuponok | 15%, 14 nap |
| **Hűségpontok** | Pontgyűjtés és beváltás | Ajándék és hűség | alapbeállítás |
| **Ajánlási program** | Vásárló ajánl vásárlót (kupon mindkettőnek) | Mandala kuponok | 10% / 2 000 Ft |

### 4.2 Eszközök, amikkel dolgozni kell

**Kampányok** (WooCommerce → Kampányok). Válassz sablont (Black Friday, Karácsony, Valentin, Nőnap, Anyák
napja, Újév) → a dátum és a szöveg kitöltve → igazítsd → mentés. A kampány magától indul és áll le:
felső sáv visszaszámlálóval, főoldali banner. Kupon: előbb hozd létre a Marketing → Kuponok alatt
(érvényességgel), majd a kampánynál add meg; „automatikus” = minden kosárra jár a kampány alatt,
egyébként a sáv és a banner linkje viszi be.

**?kupon= link:** bármely oldal címéhez fűzhető (`https://mandala.hu/termekek/?kupon=HIRLEVEL10`) – hírlevélben,
Facebook-hirdetésben a kupon magától a kosárba kerül, üres kosárnál is megjegyzi.

**Partnerprogram** (WooCommerce → Partnerek). Jógatanárok, hangterapeuták, stúdiók: e-mail + kód (pl. `JOGAANNA`)
→ a partner üdvözlő levelet kap a linkjével. Akik a linken érkeznek vagy a kódot beírják, kedvezményt kapnak;
a partner a teljesített rendelések nettó értékéből jutalékot. A partner a Fiókom → Partnerprogram oldalon látja
a kattintásokat, rendeléseket, egyenleget. Kifizetés után a „Kifizetve” gombbal rögzítsd.

**Kiemelt termékek:** a Termékek listában a csillag. Ezek kerülnek a főoldal „Legnépszerűbb darabok” elejére
és a „A hét hangtála” kártyára (ha nincs kiemelt, az eladásszám dönt).

### 4.3 Hirdetési és összehasonlító csatornák bekötése

| Csatorna | Teendő | Hol van a téma oldali rész |
|---|---|---|
| **Google Shopping** (ingyenes megjelenés + Performance Max) | merchants.google.com → fiók, domain igazolása → Adatforrás → „Fájl URL-je” = Google feed címe; Google Ads-hez kapcsolás | Mandala feedek |
| **Meta (Facebook / Instagram)** katalógus + dinamikus remarketing | Commerce Manager → Katalógus → Adatforrás = Meta feed címe; Events Manager → Pixel ID + Conversions API token | Mandala feedek, Mandala mérés |
| **Pinterest** | business.pinterest.com → Katalógusok → Adatforrás URL = Pinterest feed címe | Mandala feedek |
| **Árukereső** | Partnerfelület → termékfeed URL; **Megbízható Bolt**: WebAPI kulcs + jelvény kód | Mandala feedek (lap alja) |
| **Árgép** | Boltfelület → terméklista XML URL | Mandala feedek |
| **Google Cégprofil** (bemutatóterem) | business.google.com → cég, cím, nyitvatartás; a link a témába | Mandala bolt adatai → Bemutatóterem |
| **Google Analytics 4** | Consent Mode, e-kereskedelmi események | Mandala mérés |
| **MailerLite** | API-kulcs, csoportok, meglévő lista átküldése | Mandala levelek → MailerLite |

Az Árukereső Megbízható Bolt, a GA4 és a Meta miatt az **adatkezelési tájékoztatót** ki kell egészíteni
(adatátadás az Árukeresőnek hozzájárulással, mérés sütihozzájárulással, AI tanácsadó: Anthropic).

### 4.4 Marketingnaptár (javaslat)

| Időszak | Kampány | Ötlet |
|---|---|---|
| jan. 1–15. | Újév | „Kezdd csendesebben az évet” – meditációs kellékek |
| febr. 1–14. | Valentin-nap | Ajándék párban, közös hangfürdő-jegy |
| márc. 1–8. | Nőnap | Illóolajok, ékszerek, kendők |
| ápr. vége – máj. 1. vasárnap | Anyák napja | Ajándékcsomag kézzel írt kártyával |
| nov. utolsó péntek – hétfő | Black Friday / Cyber Monday | Akciós darabok, korlátozott készlet |
| dec. 1–19. | Karácsony | Ajándékválasztó, utalvány; „dec. 19-ig garantáltan megérkezik” |

---

## 5. Költségek

| Tétel | Mikor | Költség |
|---|---|---|
| Téma, varázsló, minden funkció | egyszeri | – (nincs fizetős bővítmény) |
| Claude kategória-migráció (~2000 termék) | egyszer | kb. 19–23 $ (Sonnet 5) |
| Új termék besorolása | termékenként | kb. 0,01 $ |
| AI leírás fotóból | termékenként | kb. 0,01–0,02 $ |
| AI tanácsadó chat | üzenetenként | kb. 0,01 $ (napi plafon állítható) |

---

## 6. Hibakeresés

- **Őrszem** (Vezérlőpult → Mandala őrszem): háttérfeladatok, hibák, rendelések, fizetések, levélküldés, feedek, tárhely, SSL.
- **Levélnapló**: minden kiküldött levél, hibával együtt.
- **Háttérfeladatok**: Eszközök → Ütemezett műveletek (a „mandala” csoport).
- **PHP hibák**: `wp-content/debug.log` (ha a `WP_DEBUG_LOG` be van kapcsolva).
- A tárhely tűzfala ne tiltsa a `wc-ajax`, `/wp-json/` és `admin-ajax.php` POST kéréseit (a tesztszerveren ez előfordult).

---

## 7. Tesztek futtatása (fejlesztőnek)

Helyi WordPress + WooCommerce + a téma (`wp mandala setup --demo`), majd:

```
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-e2e.mjs        # vásárlás végig
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-features.mjs   # funkciómodulok
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-marketing.mjs  # marketingeszközök
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-oldsettings.mjs # régi bolt beállításainak átvétele
```

Témafrissítés készítése: `python3 tools/build-theme.py --content` → `dist/`.
Mandala-grafikák újragenerálása: `python3 tools/gen-mandalas.py`.

---

## 8. Átadás előtti ellenőrzőlista

- [ ] Claude migráció lefutott, az „ellenőrizendő” termékek átnézve
- [ ] Legfrissebb téma feltöltve, önellenőrzés zöld
- [ ] Kiemelt termékek megjelölve (4–8)
- [ ] Bolt adatai, bemutatóterem, bankszámla, szállítási módok beállítva
- [ ] Jogi szövegek (ÁSZF, adatkezelés, impresszum, akadálymentesség) véglegesítve
- [ ] MailerLite, GA4, Meta, Google Merchant, Árukereső, Árgép bekötve
- [ ] Próbarendelések (kártya, utánvét, utalás) rendben, levelek megérkeznek
- [ ] Automata levelek szövege átolvasva, bekapcsolva
- [ ] Ajándék értékhatár felett: ajándék termék kiválasztva
- [ ] 3–5 termékcsomag létrehozva
- [ ] Első kampány (a következő ünnep) beállítva
- [ ] Végleges SVG logó a témában
- [ ] Élesítés: 301-es átirányítások ellenőrizve, Search Console, sitemap
