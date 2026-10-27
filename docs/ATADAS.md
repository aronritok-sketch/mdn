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
   **A régi bolt adatai** (WooCommerce → Régi bolt adatai): ugyanígy, egy másik konzolszkripttel – vásárlók
   (a munkatársak nélkül; akik már itt vannak, e-mail alapján összekapcsolódnak), rendelések jegyzetekkel,
   visszatérítésekkel és a **régi rendelésszámmal** (a chat, a csomagkövetés és az admin keresés is ezt ismeri),
   kuponok, termékértékelések, blogbejegyzések képekkel (letöltve a régi boltból), a hiányzó oldalak tervezetként,
   és a **YITH ajándékkártyák** a téma ajándékutalványaiként – a régi kódjukkal beválthatók, a maradék egyenleggel.
   Átvétel közben **nem megy ki levél**, és nem fut készletlevonás, pontjóváírás, számlázás vagy automata levél.
   A fájlt a böngésző olvassa be és adagokban küldi (nincs feltöltési korlát, nem marad a szerveren).
   **Újrafuttatható:** próbaként most, majd az élesítés napján (a régi bolt lezárása után) még egyszer – az addigi
   új rendeléseket, az állapotváltozásokat és a kártyák egyenlegét hozza át, duplikáció nélkül. Próba után az
   „Átvett adatok törlése” mindent visszavon. A jelszavak a régi boltba feltett **költöztető segéd**
   bővítménnyel jönnek át (dist/mandala-koltozes-segito.zip – csak adminnak, csak olvas, utána törlendő); nélküle a régi
   jelszóval belépő vásárló magyarázatot és „Kérj új jelszót” linket kap. Az új rendelések száma a régiek fölött
   folytatódik. Utána: WooCommerce → Analytics → „Előzmények importálása”.
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

**Hozzájárulások (Grt. 6. §, GDPR 7. cikk):** reklámlevelet csak az kap, aki kifejezetten kérte – minden célnak
külön, alapból üres pipája van, a vásárlás egyiktől sem függ:

| Cél | Hol adja meg | Hol kapcsolja ki |
|---|---|---|
| Hírlevél | lábléc űrlapja, pénztár, felugró ablak, Fiókom → Hírlevél, regisztráció | Fiókom → Hírlevél, a levél alján egy kattintás |
| Partnerlevél (viszonteladó) | viszonteladói jelentkezés, viszonteladói felület, a Partner CRM-ben rögzített telefonos hozzájárulás | viszonteladói felület, a CRM, a levél alján |
| Heti új érkezések (viszonteladó) | viszonteladói felület | viszonteladói felület, a levél alján (csak erről a levélről) |
| Születésnapi kupon | a születésnap megadása (Fiókom → Fiókadatok, pénztár – „Születésnapodon kedvezménykupont küldünk”) | a születésnap törlése, a levél alján |

**A vásárlás nem hozzájárulás** (nincs „soft opt-in”): az emlékeztetők és ajánlók (elhagyott kosár, értékelés kérése,
újrarendelés, vásárlás utáni ajánló, kedvencek, megnézett / olcsóbb lett termékek, üdvözlő kupon, a reklámnak jelölt
saját levelek) is reklámlevelek, ezért **csak hírlevél-feliratkozónak** mennek – a többi címre a levélnaplóban
„nincs hozzájárulás – nem ment” áll. Az elhagyott kosarat a pénztár is csak feliratkozónál rögzíti. Partnerlevél-
hozzájárulás (B2B) nem elég a webshop B2C leveleihez. Ha ezeket a feliratkozás nélküli vásárlóknak is küldeni akarjátok,
ahhoz külön, alapból üres pipa kell („Kérek személyre szabott emlékeztetőket…”) – ez a `mandala_marketing_consent`
szűrővel köthető be.

Minden megadás és visszavonás a hozzájárulás-naplóba kerül: időpont, forrás, a pipa **pontos szövege** és változata,
IP-cím, név (ha ismert) – ez a bizonyíték (Grt. 6. § (5)). Letöltés: **Eszközök → Mandala űrlapok** → hírlevél-
feliratkozók (a bizonyítékkal), partnerlevél-hozzájárulások, teljes napló (CSV). A visszavont hozzájárulás adatai 5 évig
maradnak meg (addig kell tudni bizonyítani, hogy a korábbi levelekhez volt hozzájárulás), utána maguktól törlődnek.

Minden reklámlevél alján: „Reklámlevél – feladó: Mandala – Asita Cult Kft., 1093 Budapest, Bakáts u. 6.”, hogy miért
kapja, és egy kattintásos leiratkozó link (belépés nélkül); a fejlécben `List-Unsubscribe` és `List-Unsubscribe-Post`
(a Gmail / Apple Mail „Leiratkozás” gombja is működik). Az általános leiratkozás minden reklámlevelet leállít
(hírlevél, emlékeztetők, születésnapi kupon, partnerlevél, heti levél – a Partner CRM és a MailerLite is megkapja; a
megnézett termékek adata és a születésnap törlődik). Új, kifejezett
hozzájárulás (pl. újra feliratkozik) leveszi a leiratkozottak listájáról. A hírlevél-feliratkozás egylépcsős (nincs
megerősítő levél); hozzájárulást kérő levelet nem küldünk (az maga is reklám). Tranzakciós levélbe (rendelés, szállítás,
utalvány, visszaküldés) nem teszünk reklámot vagy feliratkozásra hívást.

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
  Élesben is előfordul – lásd 6a.

---

## 6a. ELIN 403 – mit lát a vásárló, mit csinál a téma

Az ELIN tárhely nginx-tűzfala **IP-cím szerint** elutasít POST kéréseket („403 Forbidden” oldal), a GET-eket mindig
átengedi (2026-10-27-i vizsgálat: amerikai felhős címeink ~70%-a tiltva; a magyar vásárlók többsége átmegy, de nem mind).

| Hol | Mit látott a vásárló (javítás előtt) | Mit csinál most a téma |
|---|---|---|
| Kártya „Kosárba” (kategória, kereső, kedvencek, gyors kosárba) | semmi nem történt, üzenet sem | ugyanaz GET-tel (`?wc-ajax=add_to_cart&…&mfb=1`), a fejléc kosara frissül, „Kosárba tettük” üzenet; ha az sem megy, oldalbetöltés `?add-to-cart=…&mfb=1`, utána tiszta cím (frissítésre nem kerül be újra) |
| Termékoldal (előfizethető, utalvány, változatos, csoportos) | fehér „403 Forbidden” oldal | AJAX-szal megy (a mezőkkel: előfizetés, utalvány, változat), tiltásnál ugyanaz GET-tel – az előfizetés és az utalvány adatai is megmaradnak; egyszerre egy beküldés (dupla kattintásra sem kerül be kétszer) |
| Pénztár – szállítás / fizetés frissítése | nem jöttek a szállítási módok, szürke takarás örökre, a Teya-váltás „beragadt” | a takarás lekerül, üzenet a szállítási módok helyén: „Most nem értük el a pénztárt… Újrapróbálom”, másik internet (wifi helyett mobilnet), telefon, e-mail. Ha épp a rendelés megy (a vásárló a fizetési mód váltása után rögtön a gombra kattintott), a gombhoz nem nyúl, az üzenet a rendelés hibája után jön |
| Pénztár – rendelés leadása | a WooCommerce általános hibája | tiltásnál: „A rendelést most nem tudtuk elküldeni. Még nem jött létre…”; ha nem jött válasz (5xx, megszakadt mobilnet): a téma GET-tel megnézi, létrejött-e (`wc-ajax=mandala_order_state`) – ha igen, „A rendelésed megérkezett – ne add le újra”, és a visszaigazoló oldalra visz; az „Újrapróbálom” is előbb ellenőriz, és csak akkor küldi újra, ha biztosan nem jött létre (dupla rendelés ellen) |
| Pénztár – sokáig nyitva hagyott oldal | „Most nem értük el…” (újrapróbálás nem segített) | a WordPress lejárt ellenőrző kódja („-1”): „Ez az oldal már régóta nyitva van, ezért frissíteni kell” – „Frissítem az oldalt” gomb |
| Minikosár mennyiség, ajándékcsomag, viszonteladói gyorsrendelő, pénztár e-mail mentése (elhagyott kosár) | csendes hiba | ugyanaz GET-tel (nonce-szal); ha az sem megy, üzenet telefonnal, e-maillel |
| Kupon a pénztárban | csendes hiba | ugyanaz GET-tel (a „GET → POST híd” a WooCommerce kupon-kezelőjének adja át; az e-mail-cím nélkül – a kupon e-mail-korlátozását a rendelés úgyis nézi); ha az sem megy: „A kupont most nem tudtuk beváltani…”, másik internet, telefon, e-mail |

- **Dupla kosárba tétel ellen:** tiltásnál (403) a kérés el sem érte a WordPresst. Szerverhibánál (5xx) vagy hálózati
  hibánál a téma előbb GET-tel lekéri a kosarat (fragmentek), és ha a kosár lenyomata változott, nem teszi be újra. Ha a
  GET ismétlésre sem jön válasz, oldalbetöltés előtt újra megnézi a kosarat. Ha a kosár két próbával sem kérdezhető le,
  nem próbálja újra: „Nem tudjuk biztosan, bekerült-e a kosárba… nézd meg a kosarad” (Kosár gomb).
- **Személyes adat nem kerül a címbe:** az utalvány címzettje, e-mailje, üzenete, az ajándékkártya szövege és a pénztár
  e-mail-mentése a GET tartaléknál egy rövid életű sütiben megy (`mandala_fbf`, 10 perc, a kérés `mfs` jelével
  párosítva, a szerver egyszer olvassa és törli) – így nem kerül a tárhely naplójába és a mérőkódok oldalmegtekintésébe.
  (Ha nem férne a sütibe – ~3,8 KB felett –, a címben megy: a kosárba tétel fontosabb.)
- **Mérés:** minden tiltásnál a böngésző egy kis GET kérést küld (`mandala/v1/blocked`, oldalanként végpontonként
  egyszer). **WooCommerce → Mandala állapot**: az utolsó 14 nap naponta – látogatók, tiltott kérések végpontonként,
  különböző hálózatok (/24, pontos IP nélkül), és hány kosárba tételt mentett meg a GET tartalék. Alatta a
  leggyakrabban tiltott hálózatok listája – ezt lehet elküldeni az ELIN-nek. Az őrszem figyelmeztet, ha egy nap
  legalább 5 kosárba tétel / pénztár-kérés tiltva volt. A hálózatot a kapcsolat címéből számoljuk (`REMOTE_ADDR`; a
  továbbított fejléceket csak helyi proxy mögött, a lánc jobb széléről), így kitalált címekkel nem lehet elhasználni a
  napi keretet (hálózatonként 200, naponta 300 hálózat). Napi opcióban tárolódik (`mandala_blk_nets_ÉÉÉÉHHNN`), 14 napig.
- **Látogatószám:** a saját látogatás-számláló GET-tel megy (eddig POST volt, így a tiltott látogatók nem számítottak).
- **Teya (kártya) – ezen a téma nem tud segíteni, csak az ELIN:** a Teya a fizetés után a vásárlót **böngészős
  POST-tal** küldi vissza a köszönőoldalra (`/penztar/order-received/…`), a Teya bővítménye ebből igazolja a fizetést.
  Ha a vásárló címét tiltja a tűzfal, **a sikeres fizetés után fehér „403 Forbidden” oldalt lát** (a pénz levonva, a
  rendelés „Függőben”). A Teya szerverének értesítése (`/wc-api/WC_Gateway_Borgun/`, szintén POST) igazolhatja
  helyette – ha a Teya szerverét nem tiltja a tűzfal; akkor a vásárló a visszaigazoló levelet is megkapja.
  Amit a téma tesz: a **Mandala állapot** oldal listázza az utolsó 30 nap „Függőben” kártyás rendeléseit, amelyeknél a
  vásárló nem tért vissza (megszakította, vagy a visszatérését is tiltotta a tűzfal) – nézd meg őket a Teya felületén,
  és ha sikeres, állítsd „Feldolgozás alatt”-ra. Ha a vásárló később GET-tel nyitja meg a köszönőoldalt, és a rendelés
  még „Függőben”: rendelés-jegyzet, őrszem-figyelmeztetés (30 napig), a WooCommerce nem törli fizetetlenként, és a
  mérés (dataLayer purchase, HelloProVision Ads hub, Meta CAPI, a PixelYourSite helyett Meta / GA4 purchase)
  rendelésenként egyszer lefut.
- **A csomagpont-térkép** (`window.initOpenMap is not a function`): a függvényt a Pont bővítmény a sikeres frissítéssel
  hozza; ha a frissítés elmaradt, a téma csendes helyőrzőt tesz a helyére (a sikeres újrapróbáláskor a valódi jön).
- **Kérés az ELIN felé:** a POST-szűrés kikapcsolása a boltra – vagy legalább ezeken a címeken, a fenti lista
  hálózataival:
  - `/?wc-ajax=…` (kosárba tétel, pénztár frissítése, **rendelés leadása**, kupon);
  - `/penztar/` és `/penztar/order-received/…` (**a Teya ide küldi vissza POST-tal a fizető vásárlót**);
  - `/wc-api/WC_Gateway_Borgun/` (a Teya szerverének értesítése a fizetésről);
  - `/87sdtzugas76fgcw8atedw/…` – **az élő oldal REST-címe** (nem `/wp-json/`; a 10-27-i vizsgálatban a
    `pys-facebook/v1/event` és a `mandala/v1/hit` is 403-at kapott itt);
  - `/termek/…` (termékoldalak), `/kosar/` (kosár frissítése), `/fiokom/` (belépés), `/wp-admin/admin-ajax.php`.

**Hozzájárulás (PixelYourSite):** a Google Consent Mode a választásig „denied” (a PixelYourSite magától „granted”-et
küldött), a „Csak a szükségesek” után is az marad; a Meta (TikTok, Pinterest, Bing, Reddit, OpenAI) pixel csak
marketing-hozzájárulással töltődik be (előtte PageView sem megy). A tiltó jelzők csak olvashatók: ha valaki a
PixelYourSite-ban bekapcsolja az AJAX-os GDPR-lekérést, a szerver válasza sem kapcsolja vissza a Metát. Visszavonáskor
(később „Csak a szükségesek”) a már betöltött Meta pixel azon az oldalon is leáll (`fbq('consent','revoke')`).
Kikapcsolható: WooCommerce → Mandala mérés → „PixelYourSite a süti sáv szerint”.
**A Google címkéi** (a PixelYourSite GA4 / Google Ads, a Google for WooCommerce `AW-17657807841` címkéje) a választás
előtt is betöltődnek, „denied” állapotban: sütit nem írnak, csak sütimentes jelzést küldenek (gcs=G100 – a Google
Consent Mode „haladó” módja, a Google szerint megengedett, és a konverziómodellezéshez kell). Ha a választás előtt
egyáltalán nem futhat Google címke, azt a PixelYourSite és a Google for WooCommerce beállításában kell kérni („alap” mód).
A téma `dataLayer` purchase-e a GTM-nek szól – az élő oldalon ma nincs GTM, ott csak a jövőbeni bekötéshez kell.
A visszatért, „Függőben” maradt kártyás rendelésnél a téma csak akkor küld Meta / GA4 vásárlást, ha a PixelYourSite
azon az oldalon maga nem küld (a PixelYourSite **WooCommerce → Purchase** státusz-beállításától függ) – kétszer így sem
számol. A Meta eseményazonosítója a szerveroldali páré: a téma Conversions API-jával `order_<rendelés>`, nélküle a
HelloProVision Ads hubé, `wc-<rendelés>` – a Meta összevonja őket. A téma CAPI-ja és a hub Meta-küldése egyszerre ne
legyen bekapcsolva (két szerveres vásárlás lenne).

**Kuponlink** (`?kupon=hot10`): a landing oldalon rövid üzenet – „A hot10 kód a kosaradba került: −10%”, vagy
semleges üzenet, ha lejárt / elfogyott / nem létezik. Ha a kosárra most még nem vonható le (termék, minimum összeg),
a WooCommerce oka is látszik, és a kód a munkamenetben marad: amint teljesül a feltétele, csendben levonódik (a „nem
alkalmazható” hiba nem jön minden kosár-újraszámolásnál). Ismeretlen kódot nem írunk vissza (a link szövege bármi
lehet), és csak kódszerű, legfeljebb 40 karakteres szöveget fogadunk el.

---

## 7. Tesztek futtatása (fejlesztőnek)

Helyi WordPress + WooCommerce + a téma (`wp mandala setup --demo`), majd:

```
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-e2e.mjs        # vásárlás végig
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-features.mjs   # funkciómodulok
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-marketing.mjs  # marketingeszközök
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-oldsettings.mjs # régi bolt beállításainak átvétele
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-olddata.mjs     # régi bolt adatainak átvétele
BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-resilience.mjs  # ELIN 403, hozzájárulás, kuponlink, Teya-visszatérés
node tests/resilience.mjs                                                             # ugyanez egységtesztként, WordPress nélkül
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
