# Mandala téma – telepítési útmutató

A csomag tartalma:

| Fájl | Mi ez |
|---|---|
| `mandala-tema.zip` | A WordPress child téma – ezt kell feltölteni (Megjelenés → Témák → Új hozzáadása → Téma feltöltése). |
| `TELEPITES.md` | Ez az útmutató. |
| `UJ-TERMEKEK.md` | Munkafolyamat a webért felelős munkatársnak: új JUTA-termékek élesítése, Claude-os kategória-migráció. |
| `wp-config-kiegeszites.php` | Sorok a szerver `wp-config.php` fájljába (Claude API-kulcs, memória, ütemezés). |

> **Mindig először tesztszerveren** (az éles bolt másolatán, „staging”) telepítsd és próbáld ki. Az éles bolton
> csak akkor, ha ott minden rendben volt – előtte teljes mentéssel (fájlok + adatbázis).

---

## 1. Előfeltételek

**Szerver:** PHP 8.1 vagy újabb (8.2–8.4 ajánlott), WordPress 6.5+, WooCommerce 9–11, legalább 256 MB PHP memória,
a PHP `zip` kiterjesztése (a viszonteladói fotócsomaghoz).

**Keretrendszer (Infinite Unity):** a `mandala` téma child téma, a szülője az **`iu_theme`**. Kell még az
`iu_custom_blocks`, `iu_woocommerce` és `iu_settings` mu-plugin. Ha ezek nincsenek fent, a téma nem fog megjelenni
helyesen – a keretrendszert a fejlesztő / az iu_theme szállítója adja.

**Bővítmények:**

| Bővítmény | Mire kell | Állapot a mandala.hu-n |
|---|---|---|
| WooCommerce | a bolt | fent van |
| GLS bővítmény | szállítási módok, díjak, csomagpont-választó | telepítendő (a GLS módokat a „Magyarország” zónához kell adni) |
| Teya | bankkártyás fizetés, Apple Pay, Google Pay | telepítendő / beállítandó |
| Számlázz.hu | számlázás | telepítendő / beállítandó |
| WooCommerce Wholesale Prices | viszonteladói árak, `wholesale_customer` szerep | fent van |
| Yoast SEO (Premium) | SEO, átirányítások | fent van |
| WPML + WooCommerce Multilingual | angol nyelv | fent van |
| GTM4WP | Google Tag Manager, mérés | fent van |
| SMTP-bővítmény (pl. WP Mail SMTP) | megbízható levélküldés (visszaigazolás, emlékeztetők, értesítők) | **erősen ajánlott** |

A téma telepítő oldala (Megjelenés → Mandala telepítő) mutatja, melyik bővítmény aktív.

---

## 2. Telepítés (tesztszerveren)

1. **Mentés** a tesztszerver adatbázisáról.
2. **Megjelenés → Témák → Új hozzáadása → Téma feltöltése** → `mandala-tema.zip` → **Bekapcsolás**.
   (Ha az `iu_theme` még nincs fent, előbb azt.)
3. **Megjelenés → Mandala telepítő.** Mivel a boltban már vannak termékek, a telepítő **nem fut le magától**:
   lépésenként megmutatja, mit állítana be, és csak a kijelölt lépéseket futtatja. Minden felülírt beállítás
   eredeti értékét elmenti – a „Eredeti beállítások visszaállítása” gombbal bármikor visszaáll.
   Javaslat a mandala.hu-ra:

   | Lépés | Javaslat |
   |---|---|
   | `site` | futtasd (időzóna, dátumformátum; a meglévő URL-ekhez nem nyúl) |
   | `woocommerce` | nézd át: az országot Magyarországra, a levelek feladóját és színeit a Mandaláéra állítja. Ha most is csak belföldre szállítotok: futtasd. |
   | `tax` | futtasd, ha bruttó árakkal és 27% ÁFÁ-val dolgoztok (a mandala.hu így működik) |
   | `shipping` | futtasd (a meglévő zónákhoz nem nyúl, csak ha nincs magyar zóna) |
   | `payments` | futtasd (előre utalás, utánvét) |
   | `attributes`, `categories` | **futtasd** – ezek az új szűrők és az új kategóriafa (a régiekhez nem nyúl) |
   | `pages` | futtasd – az új oldalak; a kezdőlap az új Mandala kezdőlap lesz |
   | `menus` | futtasd – csak üres menühelyre tesz menüt |

   A bemutató tartalmat (mintatermékek, cikkek, kuponok) élő boltban **ne** importáld.
4. **Blokkok ellenőrzése (fejlesztő, egyszer):** a sablonok iu blokkjait a valódi keretrendszerben egyszer el
   kell menteni (`wp-theme/dev/canon.mjs` a forráskódban). A látogató oldalon enélkül is jó, de a
   szerkesztő különben „érvénytelen blokk” jelzést mutathat.
5. **wp-config.php:** a `wp-config-kiegeszites.php` sorai (lásd 3.).
6. **Gyorsítótár:** az oldal-gyorsítótárból zárd ki a `/kosar/`, `/penztar/`, `/fiokom/` oldalakat és a belépett
   felhasználókat (a viszonteladók személyre szabott árat látnak). Utána ürítsd a gyorsítótárat.

---

## 3. Szerver beállítások (`wp-config.php`, ütemezés, levelek)

- **Ütemezett feladatok** (e-mail emlékeztetők, értékelés kérése, új termékek értesítője, Claude migráció,
  heti viszonteladói levél): a WooCommerce Action Scheduler futtatja. Kis forgalomnál a WordPress beépített
  időzítője késhet, ezért **valódi cron** ajánlott: `DISABLE_WP_CRON` a wp-config-ba, és a tárhelyen
  5 percenként: `wget -q -O - https://mandala.hu/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
  (vagy WP-CLI-vel: `wp cron event run --due-now`).
- **Claude (kategória-migráció, új termékek javaslata):** `MANDALA_ANTHROPIC_API_KEY` a wp-config-ba
  (az Anthropic Console-ban létrehozott kulcs). Kulcs nélkül a funkció egyszerűen nem aktív.
- **Levelek:** SMTP-bővítménnyel (pl. a tárhely vagy egy levélküldő szolgáltatás SMTP adataival), hogy a
  levelek ne spambe menjenek. Próba: rendelés a tesztszerveren, és nézd meg, megérkezik-e a visszaigazolás.

---

## 4. Beállítások az adminban (sorrendben)

| Hol | Mit |
|---|---|
| WooCommerce → **Mandala bolt adatai** | e-mail, telefon, cím, nyitvatartás, Facebook / Instagram, ingyenes szállítás határa, utánvét díja |
| WooCommerce → Beállítások → Fizetés → **Előre utalás** | bankszámlaszám (a köszönőoldal és a levél innen veszi) |
| WooCommerce → Beállítások → **Szállítás** | a GLS bővítmény módjai a „Magyarország” zónában (a személyes átvétel a lista végén) |
| **Teya**, **Számlázz.hu** bővítmény | a saját beállításaik; Számlázz.hu: az adószám a rendelésben `_billing_tax_number` |
| WooCommerce → **Mandala automatizmusok** | elhagyott kosár, használati útmutató, újrarendelés, értékelés kérése – mind kikapcsolható, időzíthető; az értékelések moderátorának e-mail-címe |
| WooCommerce → **Ajándék és hűség** | utalvány összegek és érvényesség, ajándékcsomag, hűségpontok (gyűjtés, beváltás) |
| WooCommerce → **Mandala mérés** | Consent Mode alapállapot, saját felületek eseményei, Meta Conversions API (Pixel ID, token) |
| WooCommerce → **Mandala kereső** | szinonimák (pl. a vásárlók szavai a termékekre), népszerű keresések; havonta érdemes megnézni a „Nincs találat” listát |
| Termékek → **Új termékek → Beállítások** | kiket értesítsen az új JUTA-termékekről, minimális leírás hossza |
| Termékek → **Új termékek → Claude migráció** | modell, küszöb, próbafuttatás (lásd `UJ-TERMEKEK.md`) |

---

## 5. Tartalom, amit nektek kell elkészíteni

- **Jogi szövegek:** ÁSZF, adatkezelési tájékoztató, impresszum, **akadálymentességi nyilatkozat** – a telepítő
  helykitöltőkkel hozza létre őket (`[kitöltendő]` részek), jogászi átnézés kell.
- **Ajándékutalvány termék:** új termék „Mandala ajándékutalvány” néven, **cikkszám: `MND-UTALVANY`** (erre mutat az
  ajándékcsomag oldal linkje), ár: a legkisebb összeg, kategória: Ajándéktárgyak, **Mandala adatok →
  Ajándékutalvány** bejelölve. Adóosztályt nem kell állítani (a téma ÁFA-mentesnek kezeli).
- **Csomagolások az ajándékcsomaghoz:** 1–3 rejtett termék (Katalógus láthatóság: rejtett), **Mandala adatok →
  Ajándékcsomagolás** bejelölve (pl. lokta papír, díszdoboz).
- **Ajándékcsomag kínálata:** a termékeknél a „Szándék” tulajdonságban az „Ajándék” érték – ezek jelennek meg az
  összeállítóban.
- **Műhelyek, események:** Műhelyek és Események menü az adminban; a termékeknél Mandala adatok → Műhely.
- **Menük:** ha a menühelyeken már van menü, a telepítő nem nyúl hozzájuk – vegyétek fel kézzel: „Ajándékcsomag és
  utalvány”, „Hangtál-választó”, „Műhelyeink”, „Események”, a lábléc jogi menüjébe „Akadálymentesség”.
- **Hangminták, egyedi darabok:** termékenként, Mandala adatok fül.

---

## 6. Próbák a tesztszerveren (élesítés előtt)

- [ ] Rendelés **Teya** teszt kártyával, **utánvéttel**, **előre utalással** – visszaigazoló levél megérkezik.
- [ ] **GLS:** futár és csomagpont; a pontválasztó a szállítási mód alatt; címke / csomagkövetés a bővítményből.
- [ ] **Számlázz.hu:** magánszemély és cég (adószámmal) – a számlán az adószám szerepel.
- [ ] **Ajándékutalvány:** vásárlás → a kód levélben (teljesítés / kártyás fizetés után) → beváltás egy másik
  rendelésnél a kuponmezőben. **A számlát a könyvelő nézze meg** (az utalvány fizetőeszköz, nem kedvezmény).
- [ ] **Viszonteladó:** belépés `wholesale_customer` felhasználóval → Fiókom → Viszonteladói felület: nagyker árak,
  gyorsrendelés, árlista, fotók.
- [ ] **JUTA:** egy új termék importja → a termék piszkozat, megjelenik a Termékek → Új termékek listában, értesítő
  levél megy; egy ár/készlet frissítés nem teszi élővé.
- [ ] **WPML:** az angol oldalak, a nyelvválasztó, egy angol termék szűrője.
- [ ] **GTM:** előnézeti módban a `view_item_list`, `add_to_cart`, `purchase` események; a süti sáv választása a
  Consent Mode-ot frissíti. Meta pixel címkében `eventID` = `order_` + tranzakció azonosító.
- [ ] **Claude:** próbafuttatás 20–50 termékkel; a javaslatok átnézése.
- [ ] Mobilon: kínálat szűrővel, termékoldal, kosár, pénztár.

---

## 7. Élesítés napja

1. Teljes mentés az éles boltról (fájlok + adatbázis).
2. A tesztszerveren bevált sorrendben: bővítmények → téma feltöltése és bekapcsolása → Mandala telepítő (ugyanazok a
   lépések, mint a teszten) → Mandala bolt adatai és a többi beállítás → gyorsítótár ürítése.
3. **Szólj a csapatnak:** a téma bekapcsolásától az importból érkező új termékek **nem kerülnek ki automatikusan** a
   boltba – a Termékek → Új termékek listában kell őket élesíteni.
4. Ellenőrzés: kezdőlap, kínálat, egy termék, kosár, egy valódi rendelés (utána sztornó), levelek.
5. A Claude migrációt élesben is próbafuttatással kezdd; a teljes futtatás után az „Élő, ellenőrizendő” lista.

**Visszaút, ha baj van:** Megjelenés → Témák → az előző téma bekapcsolása; Mandala telepítő → „Eredeti beállítások
visszaállítása”; a Claude futtatás a Claude migráció fülön visszavonható; végső esetben a mentés visszatöltése.

---

## 8. Későbbi frissítés

Új `mandala-tema.zip` → Megjelenés → Témák → Új hozzáadása → Téma feltöltése → „Jelenlegi lecserélése a
feltöltöttre”. A telepítő csak az új lépéseket futtatja, és amit kézzel módosítottatok (beállítás, oldal), azt nem
írja felül – kiírja, hogy kihagyta. A „Mandala bolt adatai” oldalon mentett adatokat a frissítés nem érinti.
