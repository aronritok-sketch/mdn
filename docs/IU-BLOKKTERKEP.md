# Mandala – iu_theme blokktérkép és átadási terv

A HTML-prototípus 1:1-ben az **Infinite Unity (iu_theme)** keretrendszer szerkezetére épül, hogy jóváhagyás után közvetlenül `mandala` child témává fordítható legyen. Ez a dokumentum megmutatja, melyik prototípus-elem melyik iu-blokkra, sablonfájlra vagy WooCommerce-beállításra képeződik le.

**Folyamat (a vasskisgep mintaprojekt szerint):** HTML-prototípus → ügyfél-jóváhagyás → child téma. A blokk-markupot nem kézzel írjuk: generátor (`dev/content/pages.py`) → valódi blokkszerkesztős kanonizálás (`dev/canon.sh`, Playwright) → ellenőrzés (`"invalid":[]`).

## 1. Fájlok leképezése

| Prototípus | Child téma (`themes/mandala/`) | Megjegyzés |
|---|---|---|
| `theme/theme.json` | `theme.json` | 12 színes paletta, 2 betűcsalád; a szerkesztő csak ezekből enged választani |
| `assets/css/vars.css` | `vars.css` | `--iu-*` felülírások + tokenek (térköz, lekerekítés, árnyék) |
| `assets/css/site.css` | `style.css` (a `Template: iu_theme` fejléc alá) | vizuális réteg az iu osztályokra |
| `assets/css/shop.css` | `assets/shop.css` | klasszikus WooCommerce markup osztályaira írva, változtatás nélkül átvihető |
| `assets/css/iu.css` | **nem kell** | csak a prototípusban pótolja az iu_theme szerkezeti CSS-ét |
| `assets/js/*.js` | `assets/shop.js` + saját blokkok `view_script`-jei | a prototípus adat- és tárolórétege (store.js) élesben a WooCommerce |

## 2. Globális sablonok

| Zóna | Fájl | Blokkok |
|---|---|---|
| Fejléc | `templates/global_header.html` | `iu/section` (közlemény, `bgColor: night`, `lightText`) → `iu/section` (masthead) › `iu/row 1-1` › `iu/column` › `iu/group` (space-between): logó `iu/image` + `iu/menu` (collapse) + `iu-woocommerce/search`, `iu-woocommerce/account-menu`, **`mandala/wishlist-count`**, `iu-woocommerce/mini-cart`, WPML nyelvváltó (`core/shortcode`) |
| Megamenü | `iu/menu` + menüpont CSS osztály `mega` | a „Kínálat” alá a WP menü 2 szintje; a kiemelt kártya a menüpont leírásából. Ha a `wp_nav_menu` kimenete nem elég: **`mandala/mega-menu`** saját blokk |
| Pénztár fejléc | `body.woocommerce-checkout` CSS | a közlemény, a menü és a nem szükséges ikonok elrejtve – nincs külön sablon |
| Lábléc | `templates/global_footer.html` | hírlevél `iu/section` (`iu/form` formId `hirlevel`) + `iu/row 1-4\|1-4\|1-4\|1-4` (logó, `iu/menu` vertical ×2, elérhetőség) + alsó sor: `© [iu_year]`, jogi `iu/menu`, fizetési jelek |
| Cookie sáv | `core/html` a láblécben vagy cookie-plugin (pl. Complianz) | a prototípus 3 kategóriás sávja (szükséges / statisztika / marketing) |

## 3. Oldalak és szekciók

### Kezdőlap (`templates/front_page_content.html` + oldal tartalma)
| Szekció | Blokkok |
|---|---|
| Hős | `iu/section` (`bgImage`, `lightText`, `fullWidth`) › `iu/row 2-3\|1-3` › heading h1 + paragraph + `iu/button-group`; jobb oszlop: **`mandala/featured-product`** (a hét hangtála) |
| Bizalmi sáv | `iu/section` › `iu/row 1-4\|1-4\|1-4\|1-4` › `iu/icon-group` + paragraph |
| Szándék szerint | `iu/row 1-4 ×4` › `iu/card` (link a szándék szűrőre) – kép: SVG illusztráció |
| Kategóriák | `iu/row 2-3\|1-3` (bal: kategória `iu/card` háttérképpel; jobb: `iu/group column` 2 kártyával) + `iu/row 1-1` ajándék sáv |
| Újdonságok | `iu-woocommerce/product-carousel` (a legújabb 12) |
| Eredet | `iu/section` (`bgColor: night`, `lightText`) › `iu/row 1-2\|1-2`; térkép: **`mandala/origin-map`** vagy `core/html` SVG |
| Hangtál-kalauz | `iu/row 1-2\|1-2` › `iu/image` + heading + `core/list` (spec-rács CSS osztállyal) + `iu/button-group` |
| Kedvenceink | **`mandala/product-selection`** (kézi válogatás – a product-carousel csak a legújabbakat adja) |
| Filozófia | `iu/row 1-2\|1-2` › `iu/image` + szöveg |
| Vélemények | `iu/row 1-3 ×3` (vagy `iu/content-slider`) – statikus szöveg, forrás: a mostani oldal |
| Magazin | `iu/query` (`post_type: post`, 3 oszlop, sablon: kép + kategória + dátum + cím + kivonat) |

### Kínálat (`templates/archive_product_content.html`, `tax_product_cat_content.html`)
`iu/breadcrumbs` + `iu/title` (h1) + alkategória chipek (**`mandala/subcategory-chips`** vagy `iu/terms` product_cat) › `iu/row 1-4\|3-4`: `iu-woocommerce/filter` | `[products … class="mainquery"]` (Loop Product minta). Szűrők: kategória, **`pa_szandek`** és **`pa_eredet`** attribútum, ár, „csak raktáron”. Rendezés: WooCommerce `orderby`. „Több betöltése”: **`mandala/load-more`** vagy WooCommerce lapozás. Üres állapot: `woocommerce_no_products_found` hook.

### Loop Product minta (`iu_pattern` „Loop Product”)
`iu/group column` › `loop-product-image` (+ `product-badges`, **`mandala/wishlist-button`**, gyors kosárba gomb) › `product-attributes` (csak Eredet) › `loop-product-title` › `iu/group space-between`: `loop-product-pirce` + `loop-product-button`.

### Termékoldal (`templates/single_product_content.html`)
`iu/breadcrumbs` › `iu/row 1-2\|1-2`: `images` | `product-attributes` (eredet, cikkszám) + `iu/title` h1 + `price` (ÁFA-tartalom szöveggel) + `short-description` + `stock` + `add-to-cart` + **`mandala/wishlist-button`** + eredetkártya (`iu/group`) + `product-attributes` (táblázat) + `core/list` (előnyök). Alatta `iu/tabs`: Leírás (`iu/content`) · Használat (termék meta mező → **`mandala/product-meta`**) · Szállítás (`iu/pattern`, közös) · Kérdésed van? (`iu/form` formId `termekkerdes`). Hasonló termékek: WooCommerce related → karusszel. Elfogyott terméknél: `iu/form` formId `keszlet-ertesito`. Ragadós kosárba sáv: `assets/shop.js`. A sablon nem futtatja a loopot: `wp` hookon `$GLOBALS['product']` beállítása (skill szerint).

### Kosár, pénztár, köszönő oldal, fiók
Shortcode-os oldalak (`[woocommerce_cart]`, `[woocommerce_checkout]`, `[woocommerce_my_account]`), **klasszikus pénztár**. A markup a WooCommerce saját osztályneveit használja; a `shop.css` ezekre épül.

| Elem | Megvalósítás |
|---|---|
| Lépésjelző (Kosár → Pénztár → Visszaigazolás) | `core/html` az oldal tartalmában, vagy `woocommerce_before_checkout_form` hook |
| Elérhetőség előre (e-mail, telefon) | `woocommerce_checkout_fields` szűrő: `priority` értékek |
| Szállítási mód a bal oszlopban | `inc/shop.php`: a szállítási módok kiírása a `woocommerce_checkout_before_customer_details` hookon (a review táblázatban csak az összeg marad) |
| Fizetés a bal oszlopban | `remove_action('woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20)` + `add_action('woocommerce_checkout_after_customer_details', 'woocommerce_checkout_payment')` |
| Céges vásárlás | checkbox + `billing_company` + **`billing_tax_number`** saját mező (vasskisgep: `inc/shop.php`), szerveroldali adószám-ellenőrzés (8-1-2, CDV) |
| Irányítószám → település | `assets/shop.js` (KSH lista) |
| Foxpost automata | Foxpost / szállítási bővítmény csomagpont-választója (klasszikus pénztárral működőt kell választani) |
| Személyes átvétel | `local_pickup` (a „Pickup location” csak blokkos pénztárral megy – skill) |
| Utánvét díj | COD díj (`woocommerce_cart_calculate_fees`), személyes átvételnél 0 és „Fizetés átvételkor” felirat |
| Kupon az összesítőben | AJAX `apply_coupon` (a checkout form nem tartalmazhat beágyazott formot) |
| Gomb felirata | „Fizetési kötelezettséggel járó megrendelés” (`woocommerce_order_button_text`) – fogyasztóvédelmi előírás |
| Hibaösszesítő linkekkel | WooCommerce `woocommerce-NoticeGroup-checkout` + `assets/shop.js` (fókusz, mezőre ugrás) |
| Köszönő oldal | `woocommerce/checkout/thankyou.php` felülírás (vagy `woocommerce_thankyou` hookok): áttekintés, utalási adatok másolás gombbal, „Mi történik most?” idővonal |
| ÁFA | `woocommerce_prices_include_tax = yes`, 27% kulcs; összesítőben „Tartalmaz X Ft ÁFA-t” |

### Blog, keresés, 404, jogi, egyéb
| Oldal | Sablon | Blokkok |
|---|---|---|
| Magazin | `blog_page_content.html` | `iu/title` + `iu/terms` (category, buttons) + `iu/query` (main_query, paging) |
| Cikk | `single_post_content.html` | `iu/breadcrumbs`, `iu/title`, meta (`[iu_post_category]`, `[iu_post_date]`), `iu/featured-image`, `iu/content`, szerzői doboz (`iu/group`), `iu/post-navigation`, kapcsolódó termékek (**`mandala/product-selection`**), `iu/query` |
| Keresés | `search_content.html` | `iu/title` („Keresés erre: …”) + termék- és cikktalálatok; üres állapot. A téma a keresést bejegyzésekre szűkítheti (`pre_get_posts`) – a termékkeresést külön kell engedni |
| 404 | `404_content.html` | nagy „404”, `iu/search`, `iu/button-group` |
| Jogi | `single_page_content.html` (saját H1 miatt) | `iu/row 1-4\|3-4`: tartalomjegyzék (`core/list` horgonyokkal) + `iu/content` |
| Eredetünk | oldal tartalom | `iu/section` blokkok, `iu/row 1-4 ×4` lépések |
| Viszonteladóknak | oldal tartalom | `iu/form` + `iu/form-steps` / `iu/form-step` (3 lépés), adószám mező |
| Kapcsolat | oldal tartalom | `iu/form` formId `kapcsolat` + elérhetőségek + térkép (`core/html` Google Maps) |
| Kedvencek | oldal + **`mandala/wishlist`** | süti alapú lista (vasskisgep: `inc/lists.php`) |

## 4. Saját blokkok (`inc/blocks/{név}/block.php`, `iucb_add_block`)

| Blokk | Feladat |
|---|---|
| `mandala/product-selection` | kézzel választott termékek rácsban vagy karusszelben (ID lista attribútum) |
| `mandala/featured-product` | egy kiemelt termék kártya (hős) |
| `mandala/wishlist-button`, `mandala/wishlist`, `mandala/wishlist-count` | kedvencek (süti: `mandala_wishlist`) |
| `mandala/origin-map` | eredet-térkép SVG, szerkeszthető helyszínekkel |
| `mandala/subcategory-chips` | aktuális kategória alkategóriái chipként, darabszámmal |
| `mandala/product-meta` | termék egyedi mezője (pl. „Használat és gondozás”) |
| `mandala/load-more` | „Több betöltése” a terméklistán (opcionális, lapozás is jó) |
| `mandala/mega-menu` | csak ha a `wp_nav_menu` kimenete nem elég a megamenühöz |

## 5. Űrlapok (`iu/form`)

| formId | Hol | Mezők |
|---|---|---|
| `hirlevel` | lábléc | email, accept |
| `kapcsolat` | Kapcsolat | name, email, phone, topic (select), message, accept |
| `termekkerdes` | termékoldal fül | name, email, message, product (rejtett cikkszám), accept |
| `keszlet-ertesito` | elfogyott termék | email, accept |
| `viszontelado` | Viszonteladóknak | 3 lépés: cég (company, tax_number, shop_type, city, website) · kapcsolattartó · érdeklődés + accept |

A skill ismert keretrendszer-hibái miatt: az `email` attribútum üres, a levelet az `iu_form_submit_{formId}` szűrő küldi a mezőkkel és válaszcímmel (`inc/forms.php`); az `iu/form-accept`-et szerveroldalon is ellenőrizni kell; hibánál `['errors' => [], 'error' => '…']`. A validáció formátuma a prototípusban is `szabály|hibaüzenet` (required, email + saját: phone, zip, taxno – ezeket a szűrőben kell ellenőrizni).

## 6. WooCommerce beállítások (telepítő lépésként, `inc/setup.php`)

- Árak bruttóval, 27% ÁFA, ÁFA-tartalom a végösszeg alatt.
- Szállítás (Magyarország zóna): GLS futár 1 990 Ft, Foxpost 1 290 Ft, személyes átvétel (`local_pickup`) 0 Ft; mindkét futáros mód ingyenes 25 000 Ft felett.
- Fizetés: Barion (bővítmény), Előre utalás (bankadatok), Utánvét (+490 Ft díj; személyes átvételnél „Fizetés átvételkor”).
- Termékattribútumok: `pa_eredet` (Nepál, India + hely), `pa_szandek` (Elcsendesülés, Otthoni harmónia, Önkifejezés, Figyelmes ajándék), hangtálaknál Súly, Frekvencia, Hang, Csakra, Anyag.
- Kuponok (minta): `MANDALA10` (10%), `UDVOZLO` (1 500 Ft, 10 000 Ft felett).
- Oldalak: ÁSZF, Adatkezelés, Impresszum, Vásárlási információk; ÁSZF oldal a pénztárhoz.

## 7. Élesítés előtti adatok az ügyféltől

1. Cégadatok (impresszum, ÁSZF), bankszámla és IBAN, Barion POSKey.
2. Bemutatóterem címe, nyitvatartása, telefonszám (a prototípusban helykitöltő).
3. Termékfotók (1000 × 1128, álló) – most SVG illusztrációk a helyükön.
4. Pontos beszerzési helyszínek (Patan, Moradabad, Jaipur, Bengaluru – ellenőrizendő).
5. Szállítási díjak és a futárcég végleges választása; Foxpost szerződés.
6. Angol változat (WPML) szövegei.
7. Számlázó (Számlázz.hu / Billingo), SMTP, Google Analytics / Meta pixel (a cookie sáv kategóriáihoz).

## 8. Tesztlista (a skill ellenőrzőlistája alapján)

A prototípuson lefuttatva (`tests/`): minden oldal JS-hiba nélkül; oldalanként egy H1; 390 px-en nincs vízszintes görgetés; teljes rendelés (kosár → pénztár → köszönő oldal) GLS / Foxpost / személyes átvétel és Barion / utalás / utánvét kombinációkkal; hibás és helyes adószám; kupon; piszkozat megmarad; egyfájlos előnézet file://-ról. Élesben ugyanez + e-mailek, Barion teszt mód, fiók, űrlap-levelek, `"invalid":[]` a szerkesztőben.
