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
  `wp-oldsettings.mjs`, `wp-olddata.mjs`. Utolsó eredmény: e2e 77/0, feat 306/0 (2026-10-12), mkt 68/0, olds 24/0, oldd 34/0; feat 337/0 (2026-10-16, Partner CRM 2. hullám, MariaDB-s teszt-WP).
  A tesztkörnyezet előfeltételei (friss `wp mandala setup --demo` után): a GLS-helyettesítő módok a magyar zónában,
  `viszontelado` / `b2b` felhasználó `b2b@example.com` címmel és 29000-es nagyker árral a MND-HT-0490-en,
  `WP_ENVIRONMENT_TYPE=local` (alkalmazásjelszó http-n), a `wp-plugin/*` belinkelve, a WooCommerce bevezető kihagyva.
  Új konténerben a tesztkörnyezetet újra kell építeni (PHP + WordPress + WooCommerce + iu_theme stub:
  `wp-theme/dev/`), lásd `docs/TELEPITES.md` és a tesztek fejlécét.
- Dokumentáció: `docs/` (ATALLAS, JUTA, ATADAS, TELEPITES, PARTNER-CRM, LOCAL-CLAUDE-PROMPT-1/2/3).
- **Mandala Partner CRM** (2026-10-12): a viszonteladókat a csapat egy külön CRM-példányon kezeli (a HelloProVision CRM-je
  + `helloprovision-partner` bővítmény a `aronritok-sketch/hlprv` repóban, leírás: hlprv `docs/partner/README.md`). A
  webshop oldala: `inc/features/crm-connect.php` (protokoll, események, parancsok; WooCommerce → Partner CRM, összekötő
  kód) és `inc/features/crm-partner.php` (a viszonteladó oldala: feltételek, üzenet, hozzájárulás, ajánlat-link) –
  `docs/PARTNER-CRM.md` (az ajánlat-link közös tesztvektorával).

**Az élő oldal:** https://mandala.hu (ELIN.hu tárhely, nginx, Redis, HPOS, `md_` táblaelőtag). A téma utolsó
kiadott verziója: 1.1.202610160024 (Partner CRM 2. hullám: `terms` parancs – kedvezményszint, fizetési határidő → „Átutalás
számla alapján” fizetési mód + Számlázz.hu-határidő, rendelés-tiltás → csak előre utalás; Fiókom → Üzenet a
Mandalának; partnerlevél-hozzájárulás; webes forgalom havonta a CRM-nek; egy kattintásos rendelés aláírt
ajánlat-linkből – `inc/features/crm-partner.php`, `docs/PARTNER-CRM.md`) – kérdezd meg, fent van-e már (előtte
1.1.202610151525 rendeléses levelek tilthatók a Dropshiphoz, 1.1.202610121823 Partner CRM összekötő, 1.1.202610081138).
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

10. **Dropship – Ananda (anandashop.hu) rendelései a Mandalából (2026-10-15)**: az Ananda (ugyanez a téma fut rajta)
   rendelését a Mandala csomagolja és adja fel az Ananda GLS-címkéjével. Bővítmény mindkét bolton: hlprv
   `plugins/helloprovision-dropship/` (leírás: hlprv `docs/dropship/README.md`); a Mandalán WooCommerce → Partnerrendelések
   (raktár mód, felvételi cím, Ananda partner + nagyker fiók → összekötő kód), az Anandán WooCommerce → Dropship (kód,
   MyGLS-belépés). A partnerrendelés a Mandalában az Ananda nagyker fiókjára kerül, „Feladva” után teljesített → JUTA.

11. **Partner CRM – 2. hullám a webshopban (2026-10-16, téma 1.1.202610160024)**: a CRM `terms` parancsa, Üzenet a Mandalának,
   partnerlevél-pipa, webes forgalom (`web_stats`), ajánlat-link (`docs/PARTNER-CRM.md`). Élesítéskor: WooCommerce →
   Partner CRM → Kedvezményszintek (ha a szintekhez % jár, pl. `Kiemelt = 5`); WooCommerce → Fizetés → „Átutalás számla
   alapján (viszonteladók)” (alapból be, csak a CRM-ben határidőt kapott partner látja); a Számlázz.hu-számla határidejét
   a téma állítja (élesben egy próbaszámlán ellenőrizni). A CRM-modulok (hlprv) a webshop eseményeit és parancsait ezzel
   a szerződéssel használják.

9. **Mandala Partner CRM – 1. szakasz kész (2026-10-12)**: telepítésre vár (új WordPress, javasolt cím partner.mandala.hu,
   hlprv `docs/partner/README.md` → Telepítés), utána az összekötő kód a mandala.hu-ra (WooCommerce → Partner CRM).
   A jóváhagyott viszonteladó fiók-levele csak működő levélküldéssel jut el (1. pont).

Kezdd azzal, hogy megkérdezed: fent van-e az 1.1.202610121823-as téma, megjött-e a helyi Claude 3. körös jelentése
(levelek), áll-e már a Partner CRM példánya, és mit mondott Hanni / Laci a fenti pontokra.
