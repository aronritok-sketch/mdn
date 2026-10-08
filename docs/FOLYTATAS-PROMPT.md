# Folytatás új chatben – Mandala (mandala.hu)

Másold be egy új Claude Code chatbe (ugyanazzal a repóval: aronritok-sketch/mdn).

---

Szia! A mandala.hu webshopon dolgozunk tovább (HelloProVision ügyfél). Magyarul kommunikálj; a vásárlóknak szóló
szövegekben tegezünk. Előbb olvasd el a HelloProVision skillt (DONTESEK.md) és az iu-theme skillt, ha elérhető.

**Repó és ág:** `aronritok-sketch/mdn`, ág: `claude/mandala-website-frontend-elrvx4` (csak ide commitolj és pusholj,
PR-t ne nyiss). Commit-üzenet magyarul, a végén a szokásos Co-Authored-By / Claude-Session sorokkal.

**Mi ez:**
- `wp-theme/mandala/` – a Mandala child téma (az Infinite Unity `iu_theme` alá). Funkciók: `inc/features/*.php`
  (automatikusan betöltődnek), WooCommerce: `inc/shop.php`, pénztár: `woocommerce/checkout/`, JS: `assets/js/`.
- Forrásból generált részek – **ne a generált fájlt szerkeszd**: CSS → `assets/css/site.css` és `assets/css/shop.css`
  (a repó gyökerében); alapadatok → `assets/js/data.js` (→ `setup/data/config.json`); jogi oldalak, sablonok →
  `tools/build-theme.py` (→ `setup/content/`, `templates/`).
- `wp-plugin/mandala-telepito` (telepítő varázsló), `wp-plugin/mandala-koltozes` (régi bolt költöztető segéd).
- Build: `python3 tools/build-theme.py --content` → `dist/mandala-tema.zip` (verzió: 1.1.ÉÉÉÉHHNNÓÓPP), varázsló,
  telepítő csomag. A `dist/laci/` nincs a repóban (adatbázis-jelszós JUTA-szkriptek).
- Tesztek (helyi WordPress + Playwright): `tests/wp-e2e.mjs`, `wp-features.mjs`, `wp-marketing.mjs`,
  `wp-oldsettings.mjs`, `wp-olddata.mjs`. Utolsó eredmény: e2e 77/0, feat 291/0, mkt 68/0, olds 24/0, oldd 34/0.
  Új konténerben a tesztkörnyezetet újra kell építeni (PHP + WordPress + WooCommerce + iu_theme stub:
  `wp-theme/dev/`), lásd `docs/TELEPITES.md` és a tesztek fejlécét.
- Dokumentáció: `docs/` (ATALLAS, JUTA, ATADAS, TELEPITES, LOCAL-CLAUDE-PROMPT-1/2/3).

**Az élő oldal:** https://mandala.hu (ELIN.hu tárhely, nginx, Redis, HPOS, `md_` táblaelőtag). A téma utolsó
kiadott verziója: 1.1.202610041800 – kérdezd meg, fent van-e már.
- Az adminhoz **nincs hozzáférésed** (a `claude` alkalmazásjelszót visszavonták). Az ELIN a mi IP-nkről érkező
  POST-kéréseket sokszor 403-mal tiltja; GET működik (REST: `https://mandala.hu/?rest_route=/…`, Store API is).
  Admin-teendőt a tulajdonos vagy a „helyi Claude” (böngészős) végez – nekik promptot írunk (`docs/LOCAL-CLAUDE-PROMPT-*.md`).
- Admin-adatot csak a téma egyszeri javításaival tudunk módosítani: `inc/features/sitefix.php` (feltöltés után
  egyszer lefut, revízióval; eredmény a `mandala_sitefix` opcióban és a readiness végponton).
- **Soha:** jelszót, kulcsot ne írj ki és ne tegyél a repóba; a `/juta/*.php` címeket ne nyisd meg (minden megnyitás
  importot / rendelésbeküldést futtat); éles rendelést ne adj le engedély nélkül (valódi számla + JUTA-bizonylat);
  TLS-ellenőrzést ne kapcsolj ki.

**Fontos tények:**
- Cég: Asita Cult Kft., 1093 Budapest, Bakáts u. 6. (bemutatóterem is), tel. +36 30 892 8385, info@mandala.hu.
  Nyitvatartás az oldalon és a Google-ön: H 9–17, K–P 9–15, Szo–V zárva (Hanni szerint „furi” – a helyeset várjuk).
- Fizetés: utánvét (+490 Ft, a téma számolja), „Fizetés helyszínen” (csak személyes átvételnél – a téma szűri),
  Teya bankkártya (azonosító: `borgun`). Szállítás HU: GLS házhoz 1 417 Ft, GLS csomagpont 1 417 Ft (Pont bővítmény),
  MPL 1 567 Ft, személyes átvétel ingyenes; 25 000 Ft felett belföldön ingyenes (a téma nullázza; a fizetős módokat
  akkor is visszateszi, ha egy szűrő – gyanú: az IU WooCommerce mu-plugin – elrejtené). Külföld: AT/SI 5 000 Ft,
  SK/CZ/RO 7 087 Ft (HR zóna-régió hiányzott, DE/USA zóna nincs).
- JUTA: `/juta/raw_sync.php` (termék, közvetlen DB-írás, WP nélkül) és `/juta/elad.php` (csak `wc-completed`
  rendelések, HPOS-táblákból, `_elad_exported_file` jelölő). A téma 10 percenként figyeli a közvetlen írásokat
  (Redis-ürítés, index), és az átvett régi rendelésekre `regi-bolt` jelölőt tesz. Lacinak javított `elad.php`
  (a `borgun` a kártyás listában) ment ki. Részletek: `docs/JUTA.md`.

**Nyitott ügyek (2026-10-04-i hibalista, Laci a CRM-ben kapta meg feladatként):**
1. **A vásárlók nem kapnak levelet** (rendelés, regisztráció, „átvehető”, kártyás rendelés) – a téma semmit nem kapcsol
   ki; valószínűleg SMTP / tárhely. Diagnózis: `docs/LOCAL-CLAUDE-PROMPT-3.md` (Levélközpont állapotdoboz, napló,
   WooCommerce e-mailek, SMTP-teszt, Bemutató mód). Eredményre várunk.
2. **Viszonteladó nem tud belépni** – valószínűleg új jelszót kér, a levél nem jön. Ideiglenes kézi jelszó; a téma
   visszaadja a viszonteladói szerepet a sima vásárlóként átjött régi viszonteladóknak.
3. **JUTA: két napnyi hiányzó szállítólevél**, Fanni nem tudja elfogadni a rendeléseket – Laci + JUTA-egyeztetés.
4. **Black Friday a viszonteladóknál „minden terméket betesz”** – nem azonosított; link / képernyőkép kell.
5. **Facebook-poszt (kapucnis pulóver)** – a régi kategóriacímek most a Felsők → Kapucnis pulóverek kategóriára
   irányítanak; a poszt pontos linkjét még ellenőrizni kell.
6. **Nyitvatartás** – a helyes időpontokat várjuk; módosítás: WooCommerce → Mandala bolt adatai (+ Bemutatóterem).
8. **Adószám a Számlázz.hu számlán – javítva (2026-10-08, téma 1.1.202610081138):** a téma az adószámot eddig csak a
   `_billing_tax_number` kulcsba mentette, a Számlázz.hu bővítmény a `_billing_wc_szamlazz_adoszam`-ot olvassa, ezért a
   számlákra nem került adószám. Most mindkét kulcsba ír (és a vásárló fiókjába is), a régi céges rendeléseket az
   egyszeri javítás (`szamlazz-adoszam-2026-10`) pótolja a téma feltöltése után. Élesben ellenőrizni: egy céges
   rendelés számláján ott az adószám; WooCommerce → Számlázz.hu → Adószám mező maradhat kikapcsolva.
7. Korábbról: Google Maps API-kulcs a csomagpont-térképhez (Pont bővítmény), Horvátország a zónába, eladási országlista,
   SSL-megújítás (ELIN), Search Console sitemap.

Kezdd azzal, hogy megkérdezed: fent van-e az 1.1.202610041800-es téma, megjött-e a helyi Claude 3. körös jelentése
(levelek), és mit mondott Hanni / Laci a fenti pontokra.
