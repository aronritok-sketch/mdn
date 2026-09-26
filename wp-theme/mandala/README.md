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
| Fizetés / szállítás | Barion és Foxpost bővítmény (külön telepítendő; lásd lent) |
| Nyelv | `hu_HU` nyelvi csomag a WordPresshez és a WooCommerce-hez (a pénztár mezőcímkéit a téma nélküle is magyarul adja) |

## Telepítés

1. **Megjelenés → Témák → Új hozzáadása → Téma feltöltése:** `mandala-tema.zip`, majd *Bekapcsolás*.
2. Az első admin-betöltéskor lefut a telepítő (**Megjelenés → Mandala telepítő**):
   ÁFA (27%, bruttó árak), forint formátum, Magyarország szállítási zóna (GLS, Foxpost, személyes átvétel),
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
templates/*.html                  sablonfájlok (header, footer, oldal, termék, kínálat, blog, keresés, 404)
woocommerce/                      klasszikus pénztár (5 lépés), összesítő, fizetés, köszönő oldal
setup/content/, setup/data/       oldaltartalmak és adatok a telepítőhöz
assets/js/                        site.js (közös), filter.js + facets.js (szűrő), product.js, checkout.js
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

Belső linkek a tartalomban: `[mandala_url page=kapcsolat]`, `[mandala_url cat=hangtalak]`,
`[mandala_url post=hangtal-valasztas]` – telepítésenként eltérő URL-ek mellett is jók.

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
- Barion és Foxpost bővítmény: a telepítő amíg nincs Foxpost bővítmény, fix díjas „Foxpost csomagautomata”
  módot hoz létre; a bővítmény után a zóna módját cserélni kell (a téma a csomagpont-választót a módhoz jeleníti meg).
- A JUTA-Soft szinkron csak árat és készletet ír: a szűrő indexe a készlet- és árváltozásra magától frissül.
