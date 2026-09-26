# Mandala – child téma (iu_theme)

A mandala.hu webáruház WordPress child témája az Infinite Unity (`iu_theme`) keretrendszerre.
A jóváhagyott prototípus (repó gyökere) WordPress-megvalósítása.

## Követelmények

| | |
|---|---|
| WordPress | 6.5+ (a téma ES modulokat tölt: `wp_enqueue_script_module`) |
| PHP | 8.1+ |
| Szülő téma | `iu_theme` (a téma `Template: iu_theme`) |
| mu-pluginek | `iu_custom_blocks` (saját blokkok), `iu_woocommerce`, `iu_settings` |
| WooCommerce | 9.x–11.x, **klasszikus** (shortcode-os) kosár és pénztár |
| Szállítás | GLS bővítmény: a GLS módok, díjak és a pontválasztó onnan jönnek |
| Fizetés | Teya bővítmény / fizetőoldal (kártya, Apple Pay, Google Pay); előre utalás és utánvét a WooCommerce-ből |
| Számlázás | Számlázz.hu bővítmény |
| Viszonteladók | WooCommerce Wholesale Prices (a mandala.hu-n már fut) |
| Nyelv | `hu_HU` nyelvi csomag a WordPresshez és a WooCommerce-hez (a pénztár mezőcímkéit a téma nélküle is magyarul adja) |

## Telepítés

1. **Megjelenés → Témák → Új hozzáadása → Téma feltöltése:** `mandala-tema.zip`, majd *Bekapcsolás*.
   Lépésről lépésre, élő bolthoz: [`docs/TELEPITES.md`](../../docs/TELEPITES.md) (a `dist/mandala-telepito-csomag.zip` is tartalmazza).
2. Üres boltban az első admin-betöltéskor lefut a telepítő (**Megjelenés → Mandala telepítő**). **Élő boltban
   (vannak termékek) nem fut magától:** lépésenként megmutatja, mit állít be, és csak a kijelölteket futtatja.
   Minden felülírt beállítás eredeti értékét elmenti (visszaállítás gombbal), és egy későbbi újrafuttatás nem írja
   felül, amit azóta kézzel módosítottak. Helykitöltő bankszámlaszámot csak a bemutató tartalom kap.
   ÁFA (27%, bruttó árak), forint formátum, Magyarország szállítási zóna személyes átvétellel (a GLS módokat a GLS bővítmény adja),
   előre utalás és utánvét, szűrő attribútumok (`pa_*`), kategóriák, oldalak, menük.
   - Meglévő tartalmat nem ír felül: ha egy oldalt kézzel szerkesztettek, figyelmeztet (md5 manifest).
   - Élő boltban a termék- és kategória-URL-ek nem változnak (a magyar `termek/`, `kategoria/` alap csak üres boltban áll be).
   - A WooCommerce angol alapoldalai (blokkos kosár/pénztár) helyére a klasszikus, magyar oldalak kerülnek.
3. Bemutató tartalom (mintatermékek, magazincikkek, `MANDALA10` és `UDVOZLO` kupon) csak kérésre:
   a telepítő oldalán gombbal, vagy `wp mandala demo`. Törlés: `wp mandala demo_delete`.
4. **Blokk-markup kanonizálása (kötelező, egyszer):** a sablonfájlok statikus iu blokkjai (section, row,
   column, group, button, form, accordion) generált vázlatok. Egy fejlesztői példányon, a valódi
   `iu_theme`-mel futtasd: `node wp-theme/dev/canon.mjs` (lásd a fájl fejlécét), amíg minden fájlnál
   `"invalid": []` nem lesz, majd csomagold újra a témát. A saját `mandala/*` blokkok dinamikusak,
   ezeket nem érinti.

### WP-CLI

```bash
cd /tmp && wp --path=/var/www/html mandala setup          # függő lépések
cd /tmp && wp --path=/var/www/html mandala setup --force  # minden lépés újra
cd /tmp && wp --path=/var/www/html mandala demo           # bemutató tartalom
cd /tmp && wp --path=/var/www/html mandala status
cd /tmp && wp --path=/var/www/html mandala reindex        # szűrő termékindex újraépítése
cd /tmp && wp --path=/var/www/html mandala eu-shipping    # szállítás az EU-ba (--off: vissza csak belföld)
cd /tmp && wp --path=/var/www/html mandala ai-migrate --dry-run --limit=20   # Claude próbafuttatás
cd /tmp && wp --path=/var/www/html mandala ai-migrate     # teljes migráció (a háttérfeladatok nélkül)
cd /tmp && wp --path=/var/www/html mandala ai-undo <futtatás>
```

(Semleges mappából: az iu_theme relatív `require_once` hibája miatt.)

## Felépítés

```
style.css, vars.css, theme.json   design (tokenek, paletta, iu változók)
functions.php                     csak betöltő
inc/theme.php                     stílusok, ES modulok, menühelyek, globális rétegek (kereső, minikosár)
inc/blocks.php                    blokk-betöltő (iucb_add_block; tartalék: register_block_type)
inc/blocks/*/block.php            saját blokkok (lásd lent)
inc/catalog.php                   szűrő termékindex (transient), „Mandala adatok” termékmezők, színkódok
inc/shop.php                      WooCommerce: pénztár mezők, adószám, szállítás/utánvét, fragmentek, sablonok
inc/forms.php                     iu/form feldolgozás (iu_form_submit_{formId}), napló, hírlevél CSV
inc/wishlist.php                  kedvencek (süti + felhasználói meta)
inc/rest.php                      mandala/v1: products, posts, stock-notify, wishlist (URL: rest_url())
inc/setup.php, inc/cli.php        telepítő és WP-CLI
inc/features/*.php                funkciómodulok (lásd lent) – mindegyik önálló, a functions.php betölti
wpml-config.xml                   WPML: fordítható / másolandó egyedi mezők és bejegyzéstípusok
templates/*.html                  sablonfájlok (header, footer, oldal, termék, kínálat, blog, keresés, 404)
woocommerce/                      klasszikus pénztár (5 lépés), összesítő, fizetés, köszönő oldal
setup/content/, setup/data/       oldaltartalmak és adatok a telepítőhöz
assets/js/                        site.js (közös), filter.js + facets.js (szűrő), product.js, checkout.js,
                                  finder.js, gift.js, b2b.js, track.js (mérés), validate.js
src/wp.css, src/features.css      WordPress-specifikus és funkciómodul stílusok (a build a style.css végére fűzi)
```

A `style.css`, `vars.css`, `assets/css/shop.css`, `assets/js/facets.js`, `assets/js/filter.js`, a
`setup/` és a `templates/` a prototípusból generálódik: `python3 tools/build-theme.py` (a blokk-markup
csak `--content` kapcsolóval). Kézzel a `src/wp.css`-t, az `inc/`, a `woocommerce/` és a többi JS-t szerkeszd.

## Saját blokkok (`mandala/*`)

| Blokk | Hol |
|---|---|
| `header`, `notice`, `logo`, `menu`, `contact`, `footer-bottom` | fejléc, lábléc |
| `picture`, `icon`, `hero-mandala`, `hero-pick`, `intents`, `category-tile`, `products`, `route-map`, `testimonials` | főoldal és tartalmi oldalak |
| `shop-head`, `filter`, `product-results` | kínálat, kategória, címke archívum |
| `product-gallery`, `product-summary`, `product-tabs` | termékoldal |
| `checkout-progress`, `wishlist`, `search-results`, `post-meta`, `post-hero`, `page-lead`, `map` | kosár/pénztár, kedvencek, keresés, cikk, oldalak |
| `bowl-finder`, `gift-builder`, `review-form`, `product-reviews` | hangtál-választó, ajándékcsomag, értékelés |
| `workshops`, `workshop-facts`, `events`, `event-ticket` | műhelyek, események |

Belső linkek a tartalomban: `[mandala_url page=kapcsolat]`, `[mandala_url cat=hangtalak]`,
`[mandala_url post=hangtal-valasztas]`, `[mandala_url sku=MND-UTALVANY]` – telepítésenként (és WPML-lel
nyelvenként) eltérő URL-ek mellett is jók.

## Funkciómodulok (`inc/features/`)

| Modul | Mit ad | Hol állítható |
|---|---|---|
| `sound.php` | Hangminta lejátszó (kártyán és termékoldalon), „Egyedi darab” jelölés, testvérdarabok (ugyanaz a forma más hangon) | Termék → Mandala adatok: Hangminta, Egyedi darab, Testvérdarabok csoportja |
| `workshops.php` | Műhelyek (egyedi bejegyzéstípus, `/muhelyek/`), a termék eredetkártyája a műhely történetére mutat; nyomtatható kísérőkártya QR-kóddal a csomagba | Műhelyek menü; Termék → Mandala adatok: Műhely; rendelés oldalsáv → „Kísérőkártya nyomtatása” |
| `incoming.php` | Érkező szállítmány / előrendelés: elfogyott terméknél a várható érkezéssel előrendelhető (WooCommerce utánrendelés), készletre érkezéskor magától kikapcsol | Termék → Mandala adatok: Várható érkezés, Honnan |
| `events.php` | Események (hangfürdő, workshop) jegyértékesítéssel: az eseményhez rejtett virtuális jegytermék tartozik, a helyek száma a készlet; Event strukturált adat | Események menü |
| `mailer.php`, `automations.php` | **Levélközpont:** a téma minden automata levele egy helyen (elhagyott kosár, használati útmutató, értékelés kérése, újrarendelés, újra raktáron, ajándékutalvány, feladás, átvehető, heti B2B) – szerkeszthető tárgy / címsor / szöveg helyőrzőkkel (`{keresztnev}`, `{rendeles}`…) és tartalomblokkokkal (`{termekek}`, `{gomb}`…), be/ki és időzítés, élő előnézet mintaadatokkal, tesztlevél, alaphelyzet, aláírás, válaszcím; napló 180 napig (keresés e-mailre / rendelésre, a rendelés oldalán is); a WooCommerce saját levelei linkelve; leiratkozás, `List-Unsubscribe`. WPML: a saját szövegek fordítható stringek (`mandala-mail`) | WooCommerce → Mandala levelek |
| `tracking.php` | **Csomagkövetés:** saját „Csomagkövetés” oldal (rendelésszám + e-mail, vagy aláírt link a levelekből) idővonallal – megrendelve, fizetve / utalásra vár, csomagoljuk (előrendelésnél várható érkezés), feladva GLS csomagszámmal és élő követés gombbal / átvehető; ugyanez a Fiókom → rendelés oldalon és a köszönőoldalon, a WooCommerce vásárlói leveleiben követő doboz. A csomagszám a GLS bővítmény rendelés-mezőjéből (a mezőnevek állíthatók), a Shipment Tracking bővítményből vagy kézzel a rendelés oldalán; megjelenésekor „Feladtuk” levél (egyszer). Személyes átvételnél „Átvehető” rendelés művelet és levél | Rendelés oldal → Csomagkövetés és levelek; Mandala levelek → Beállítások |
| `reviews.php` | Saját értékelések: személyes link a teljesített rendelés után (ellenőrzött vásárlás), csillag, szöveg, fotó; moderálás; csillagok a kártyán és a termékoldalon, `aggregateRating` | Termékek → Értékelések |
| `gifts.php` | Ajándékcsomag-összeállító (`/ajandekcsomag/`: termékek + csomagolás + kártya szövege egy csomagként); ajándékutalvány egyenleggel, e-mailben és nyomtatható formában | Termék → Mandala adatok: Ajándékutalvány / Ajándékcsomagolás; WooCommerce → Ajándékutalványok; WooCommerce → Ajándék és hűség |
| `loyalty.php` | Hűségpontok: gyűjtés teljesítéskor, beváltás a pénztárban, egyenleg és napló a Fiókomban, kézi jóváírás a felhasználó profilján | WooCommerce → Ajándék és hűség |
| `b2b.php` | Viszonteladói felület (Fiókom → Viszonteladói felület): gyorsrendelő nagyker árakkal, árlista CSV, termékfotók ZIP-ben, heti levél az új érkezésekről | a `wholesale_customer` szerep (`mandala_wholesale_roles` szűrő) |
| `wpml.php` | WPML + WooCommerce Multilingual támogatás (bővítmény nélkül hatástalan) | `wpml-config.xml` |
| `eu.php` | Szállítás az EU-ba, országfüggő pénztári ellenőrzés, közösségi adószám | `wp mandala eu-shipping` |
| `seo.php` | GYIK (FAQPage) az oldalak harmonika blokkjaiból, szűrt kínálat-URL-ek `noindex, follow`, egy BreadcrumbList a Yoast mellett, `countryOfOrigin` | – |
| `analytics.php` | Consent Mode v2 alapállapot, GA4 események a saját felületekhez, Meta Conversions API | WooCommerce → Mandala mérés |
| `onboarding.php` | Új termékek jóváhagyási sora: az importból (JUTA) érkező termék piszkozat, ellenőrzőlista (kategória, kép, leírás, ár, cikkszám, kötelező szűrők), élesítés csak teljes adatokkal; értesítő és napi emlékeztető levél, jelvény | Termékek → Új termékek (Beállítások fül) |
| `catalog-schema.php` | A szűrők adatleírása (mely szűrő hol kötelező) – az ellenőrzőlista és a Claude közös forrása | `mandala_filter_schema` szűrő |
| `ai-catalog.php` | Claude-alapú kategorizálás: a meglévő termékek migrálása az új kategóriafára és szűrőkre (próbafuttatás, becslés, visszavonás), javaslat az új termékekhez | Termékek → Új termékek → Claude migráció; `wp mandala ai-migrate` |
| `store-settings.php` | Bolt adatai adminból (elérhetőség, nyitvatartás, ingyenes szállítás, utánvét díja) | WooCommerce → Mandala bolt adatai |
| `search.php` + `assets/js/search-engine.js` | Kereső: ragozás („hangtálakat”), összetett szavak („tál” → hangtál), elírás-tűrés („hantál”, „Erre gondoltál?”), szinonimák, a kérdés értelmezése szűrőként („hangtál 500 g alatt”, „432 Hz”, „G#”, „10 000 Ft alatt”, „akciós”), cikkszám-részlet, súlyozott rangsor – az élő keresőben, a találati oldalon és a kínálat keresőjében egyformán; keresési statisztika (legtöbbet keresett, nulla találat → szinonima egy kattintással) | WooCommerce → Mandala kereső |
| `claude.php` + `chat.php` + `assets/js/chat.js` | AI tanácsadó (Claude): lebegő „Kérdezz tőlünk” gomb és a termékoldalon „Kérdésem van erről a termékről”. Ajánl a kínálatból (a téma keresőjével, ár / kategória / készlet szűrővel), válaszol a termék adataiból (leírás, jellemzők, gondozás, értékelések), a szállításról, fizetésről, visszaküldésről; csak valós, linkelt terméket ajánl (termékkártyával), egészségügyi hatást nem ígér, bizonytalanságnál az ügyfélszolgálatra irányít. Költségvédelem: IP-nkénti és napi összesített korlát; beszélgetések 30 napig, értékeléssel | WooCommerce → Mandala tanácsadó |
| `a11y.php` | Címke–mező összekapcsolás az iu/form mezőkön, fókuszálható táblázatok; az akadálymentességi nyilatkozat oldal a telepítőből | – |

### Ajándékutalvány és ÁFA

Az utalvány **többcélú utalvány**: eladásakor nincs ÁFA (a termék adóosztálya „nincs”), az ÁFA a beváltáskor, a
megvásárolt termékek után keletkezik. Ezért a beváltás **nem kupon/kedvezmény** (nem csökkenti a termékek
ÁFA-alapját), hanem fizetőeszköz: a pénztárban a kuponmezőbe írt `MND-XXXX-XXXX` kód a fizetendő végösszeget
csökkenti, a rendelésben és a levelekben külön sorban látszik, a rendelés jegyzete szerint fizetési módként.
A **Számlázz.hu számlán** ennek fizetési módként / előlegként kell megjelennie – a bővítmény beállítását a
könyvelővel egyeztetve a tesztszerveren ellenőrizni kell. Lemondáskor az egyenleg visszaíródik.

### Mérés (GTM4WP mellett)

- A WooCommerce alap eseményeit (termékoldal, pénztár lépései, vásárlás) a GTM4WP adja; a téma csak a saját,
  AJAX-os felületeit méri: `view_item_list`, `select_item` (szűrt lista), `add_to_cart` (termékoldal,
  ajándékcsomag, viszonteladói gyorsrendelő – `mandala_source` paraméterrel), `search`, `mandala_finder_result`.
  GTM4WP nélkül a `view_item`-et is.
- A Consent Mode alapállapotát a téma állítja be a GTM előtt. Ha a GTM4WP saját Consent Mode beállítása be
  van kapcsolva, a témáét kapcsold ki (WooCommerce → Mandala mérés).
- Meta CAPI: a GTM-es Meta pixel címkében az `eventID` legyen `order_` + `transaction_id` – így a böngészős és a
  szerveroldali Purchase esemény nem duplázódik. A CAPI csak a pénztárban adott marketing-hozzájárulással küld.

## Szállítás, fizetés, számla, viszonteladók

- **GLS:** minden a GLS bővítményből jön (módok, díjak, pontválasztó). A téma a módokat a pénztár 2. lépésében
  mutatja, és az azonosítójuk/címkéjük alapján típust rendel hozzájuk (futár, CsomagPont/automata, átvétel):
  pontnál nincs szállítási cím, az utánvét szövege és a köszönő oldal idővonala ehhez igazodik.
- **Ingyenes szállítás** 25 000 Ft felett (kedvezmény után, bruttó) – a téma szabálya, a közlemény sáv és a
  kosár mérője is ebből dolgozik. Módosítás: `mandala_freeShippingFrom` opció; `0` = kikapcsolva (ha a GLS
  bővítményben állítjátok be).
- **Teya:** a fizetési módot a Teya bővítmény adja; a téma a kártya ikont és a köszönő oldali „Sikeres fizetés”
  üzenetet teszi hozzá.
- **Számlázz.hu:** a pénztár adószám mezője a rendelésben `_billing_tax_number`. Ha a Számlázz.hu bővítmény
  más meta kulcsból olvassa: `add_filter('mandala_tax_number_meta_keys', fn() => ['<kulcs>']);` – a tesztszerveren
  ellenőrizendő.
- **Viszonteladói ár:** a Wholesale Prices bővítmény „Wholesale Price” mezője (termékfelvételkor ide kerül a JUTA
  „Akciós ár”-a), szerep: `wholesale_customer`. A pénztári és kosárárakat a bővítmény számolja; a téma a
  terméklistán, a szűrőben és a keresőben is a nagyker árat mutatja „Nagyker ár” jelvénnyel. A közös
  (gyorsítótárazott) termékindex soha nem tartalmaz nagyker árat: viszonteladónak a REST végpont személyre
  szabottan (`Cache-Control: private`) adja. Oldalgyorsítótár esetén a belépett felhasználókat ki kell hagyni.

## Keretrendszer-hibák és kerülőutak (child témában, a keretrendszer érintetlen)

- **iu/form e-mail:** csak a fix `message` szöveget küldené, `From` nélkül → az űrlapok `email` attribútuma üres,
  a levelet az `iu_form_submit_{formId}` szűrő küldi a mezőkkel, `Reply-To` a kitöltő címe. Az adatkezelési
  jelölőnégyzetet a szerver is ellenőrzi. Hibaválasz: `['errors' => [...], 'error' => '…']`.
- **Űrlapmező neve:** `name` nem lehet (WordPress lekérdezési változó, POST-ból is olvassa → az oldal helyett
  bejegyzést keres). A név mező neve `nev`.
- **REST prefix:** egyedi → a kliens az URL-t `rest_url()`-ből kapja (`window.MANDALA.rest`).
- **WooCommerce sablonok:** a bolt, a kategória/címke/márka archívum és a termékoldal az iu_theme `index.php`-jára
  irányul (`template_include`), a WooCommerce alap CSS-e ki van kapcsolva (a `shop.css` váltja).
- **Loop nélküli sablon:** a `$product` globált a `wp` hookon állítjuk.
- **Pénztár:** klasszikus shortcode (`[woocommerce_checkout]`), a „Pickup location” helyett `local_pickup`.

## Élesítés előtt kitöltendő

- Elérhetőség, nyitvatartás, közösségi linkek, ingyenes szállítás határa, utánvét díja: **WooCommerce → Mandala bolt
  adatai** (a témafrissítés nem írja felül; alapértékek: `setup/data/config.json`). Bankszámla: WooCommerce →
  Fizetés → Előre utalás.
- Jogi szövegek (ÁSZF, adatkezelés, impresszum): helykitöltők, jogászi átnézés kell.
- Bővítmények: GLS, Teya, Számlázz.hu, Wholesale Prices. A telepítő oldala (Megjelenés → Mandala telepítő)
  mutatja, melyik aktív, és keresőlinket ad a hiányzókhoz. A GLS módokat a bővítmény telepítése után a
  Magyarország zónához kell adni; a személyes átvétel a lista végére kerül, így alapból GLS van kiválasztva.
- A JUTA-Soft szinkron csak árat és készletet ír: a szűrő indexe a készlet- és árváltozásra magától frissül.
- Akadálymentességi nyilatkozat (`/akadalymentesseg/`): a helykitöltőket (elérhetőség, hatóság, ellenőrzés
  dátuma) ki kell tölteni. Meglévő menüket a telepítő nem ír felül: a lábléc „Jogi linkek” menüjébe kézzel kell
  felvenni; ugyanígy az „Ajándékcsomag és utalvány” oldalt a „Lábléc – Kínálat” menübe.
- Ajándékutalvány számlázása (fent), EU-s szállításnál az OSS ÁFA-kulcsok – könyvelővel egyeztetve.
- GTM: a fenti események címkéi (GA4, Google Ads, Meta) és a Consent Mode beállítás a GTM-ben.

### Új termékek (JUTA) és Claude migráció

Részletes munkafolyamat a webért felelős munkatársnak: [`docs/UJ-TERMEKEK.md`](../../docs/UJ-TERMEKEK.md).

- **Érkezés:** ami nem a termékszerkesztőből jön létre (JUTA REST, CSV import, WP-CLI), az piszkozat és „új” lesz;
  ha a JUTA közvetlenül az adatbázisba írna, az óránkénti ellenőrzés akkor is sorba teszi. A JUTA ár- és
  készletfrissítése nem élesít. Élesítés: a sorban (egyenként vagy csoportosan) vagy a szerkesztőben közzététellel –
  mindkettő csak teljes ellenőrzőlistával.
- **Claude:** API-kulcs a `wp-config.php`-ban (`MANDALA_ANTHROPIC_API_KEY`). A kérés csak termékadatot visz
  (név, cikkszám, régi kategória és tulajdonságok, leírás) – személyes adatot nem. Alapmodell: `claude-opus-5`
  (állítható). A kategória és a szűrőértékek felsorolt listából jönnek (strukturált kimenet), a szerver még
  egyszer ellenőrzi őket; a biztos javaslat érvénybe lép, a bizonytalan az „Élő, ellenőrizendő” fülre kerül.
- **AI tanácsadó (chat):** ugyanazzal a kulccsal. A Claude eszközökkel dolgozik (`search_products`,
  `get_product`, `contact_human`), a bolti tudnivalók (szállítás, fizetés, elérhetőség, kategóriák) a
  gyorsítótárazott rendszerpromptban vannak, így egy kérdés jellemzően néhány ezer bemeneti token, nagyrészt
  gyorsítótárból. Gyors mód (`effort: low`), állítható modell; elutasításnál (`stop_reason: refusal`) barátságos
  válasz és elérhetőség. A pénztárban nem jelenik meg.
- **Élesítés előtt, tesztszerveren:** adatbázis-mentés → próbafuttatás 20–50 termékkel → a javaslatok átnézése,
  küszöb / modell hangolása → teljes migráció → az ellenőrizendők átnézése. Minden éles futtatás visszavonható.
- **A régi kategóriák** a termékek mellett maradnak (URL-ek, SEO). Ha az új kategóriafa bevált, a régieket
  külön lépésben kell lebontani, 301-es átirányítással (Yoast Premium átirányítások) – ezt a téma nem csinálja
  meg magától.

## Tesztek

Egy teszt WordPressen (SQLite is elég, lásd `wp-theme/dev/README.md`), friss `wp mandala setup --demo` után:

```bash
BASE=http://localhost:8080 node tests/wp-e2e.mjs                        # kínálat, szűrő, kosár, pénztár, űrlapok
BASE=http://localhost:8080 WP="wp --path=…" node tests/wp-features.mjs   # funkciómodulok (ajándék, utalvány,
                                                                        # pontok, EU, viszonteladó, mérés,
                                                                        # új termékek sora, Claude migráció)
```

A `wp-theme/dev/test-mu-plugin.php` a teszthez kell (levelek fájlba, GLS helyettesítő módok, Meta CAPI és
Anthropic API helyettesítő végpont) – éles oldalra nem kerülhet.
