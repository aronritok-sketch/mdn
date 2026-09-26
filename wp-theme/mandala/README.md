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
2. Az első admin-betöltéskor lefut a telepítő (**Megjelenés → Mandala telepítő**):
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
| `mailer.php`, `automations.php` | Elhagyott kosár (egy emlékeztető, visszaállító link), használati útmutató, újrarendelés-emlékeztető fogyóeszközöknél, értékelés kérése; leiratkozás, `List-Unsubscribe` | WooCommerce → Mandala automatizmusok |
| `reviews.php` | Saját értékelések: személyes link a teljesített rendelés után (ellenőrzött vásárlás), csillag, szöveg, fotó; moderálás; csillagok a kártyán és a termékoldalon, `aggregateRating` | Termékek → Értékelések |
| `gifts.php` | Ajándékcsomag-összeállító (`/ajandekcsomag/`: termékek + csomagolás + kártya szövege egy csomagként); ajándékutalvány egyenleggel, e-mailben és nyomtatható formában | Termék → Mandala adatok: Ajándékutalvány / Ajándékcsomagolás; WooCommerce → Ajándékutalványok; WooCommerce → Ajándék és hűség |
| `loyalty.php` | Hűségpontok: gyűjtés teljesítéskor, beváltás a pénztárban, egyenleg és napló a Fiókomban, kézi jóváírás a felhasználó profilján | WooCommerce → Ajándék és hűség |
| `b2b.php` | Viszonteladói felület (Fiókom → Viszonteladói felület): gyorsrendelő nagyker árakkal, árlista CSV, termékfotók ZIP-ben, heti levél az új érkezésekről | a `wholesale_customer` szerep (`mandala_wholesale_roles` szűrő) |
| `wpml.php` | WPML + WooCommerce Multilingual támogatás (bővítmény nélkül hatástalan) | `wpml-config.xml` |
| `eu.php` | Szállítás az EU-ba, országfüggő pénztári ellenőrzés, közösségi adószám | `wp mandala eu-shipping` |
| `seo.php` | GYIK (FAQPage) az oldalak harmonika blokkjaiból, szűrt kínálat-URL-ek `noindex, follow`, egy BreadcrumbList a Yoast mellett, `countryOfOrigin` | – |
| `analytics.php` | Consent Mode v2 alapállapot, GA4 események a saját felületekhez, Meta Conversions API | WooCommerce → Mandala mérés |
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

- Cégadatok, bankszámla, bemutatóterem címe, nyitvatartás: `setup/data/config.json` (vagy a
  WooCommerce → Fizetés → Előre utalás beállítás) – jelenleg helykitöltők.
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

## Tesztek

Egy teszt WordPressen (SQLite is elég, lásd `wp-theme/dev/README.md`), friss `wp mandala setup --demo` után:

```bash
BASE=http://localhost:8080 node tests/wp-e2e.mjs                        # kínálat, szűrő, kosár, pénztár, űrlapok
BASE=http://localhost:8080 WP="wp --path=…" node tests/wp-features.mjs   # funkciómodulok (ajándék, utalvány,
                                                                        # pontok, EU, viszonteladó, mérés)
```

A `wp-theme/dev/test-mu-plugin.php` a teszthez kell (levelek fájlba, GLS helyettesítő módok, Meta CAPI
helyettesítő végpont) – éles oldalra nem kerülhet.
