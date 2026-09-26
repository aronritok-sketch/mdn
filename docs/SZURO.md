# Mandala szűrőrendszer (`mandala/filter`)

Saját, egyedi termékszűrő a webshop saját termékadataira (WooCommerce kategóriák, attribútumok, meta mezők). A prototípusban az `assets/js/facets.js` (motor és konfiguráció) és az `assets/js/pages/shop.js` (felület) valósítja meg. A child témában `mandala/filter` saját blokk lesz az `iu-woocommerce/filter` helyett.

## Logika

- **Csoporton belül VAGY, csoportok között ÉS.** Például Hang: G vagy G#, ÉS Csakra: torok. Kivétel az „Elérhetőség és ajánlat” csoport, ahol ÉS a kapcsolat (raktáron ÉS akciós).
- **Diszjunktív darabszámok:** egy csoport opcióinál a szám azt mutatja, hány termék lesz a kattintás után. A többi csoport szűrése benne van, a saját csoporté nincs.
- **Nincs zsákutca:** a 0 találatot adó opciók le vannak tiltva, a 0 találatú gyors szűrések el sem jelennek. Ha egy URL-ből mégis üres a lista, a szűrő megmutatja, melyik szűrő elhagyásával lesz a legtöbb találat, egy kattintással.
- **Kontextus:** a kategóriafüggő csoportok (hangtál, füstölő, ruha) csak ott jelennek meg, ahol értelmezhetők. Kategóriaváltáskor törlődnek, és a kategóriák darabszámai is már e törlés után számolódnak.
- **Tartományok:** a határok a kategóriából jönnek, így a csúszka nem ugrál; a hisztogram a többi szűrő szerinti eloszlást mutatja. Húzás közben csak a felirat frissül, a szűrés elengedéskor, Enterre vagy a mező elhagyásakor történik.

## Csoportok

| Kulcs (URL) | Csoport | Típus | Hol látszik | WooCommerce forrás |
|---|---|---|---|---|
| `cat`, `sub` | Kategória | fa | mindig | `product_cat` |
| `szandek` | Szándék | jelölőnégyzet | mindig | `pa_szandek` |
| `ar` | Ár | tartomány + hisztogram | mindig | `_price` (bruttó) |
| `hang` | Hang (C … B) | chip | hangtálak | `pa_hang` |
| `hz` | Frekvencia | tartomány | hangtálak | meta `_mandala_hz` (szám) |
| `suly` | Súly | tartomány | hangtálak | meta `_mandala_suly` (g, szám) – a WooCommerce `_weight` is lehet |
| `csakra` | Csakra | színes lista | hangtálak, mala, ékszer | `pa_csakra` |
| `keszites` | Készítés | jelölőnégyzet | hangtálak | `pa_keszites` |
| `illat` | Illat | jelölőnégyzet | füstölők | `pa_illat` |
| `forma` | Füstölő típusa | jelölőnégyzet | füstölők | `pa_forma` |
| `meret` | Méret | chip | ruházat | `pa_meret` |
| `eredet` | Eredet | jelölőnégyzet | mindig | `pa_eredet` |
| `regio` | Műhely, régió | jelölőnégyzet | mindig | `pa_regio` |
| `anyag` | Anyag | jelölőnégyzet + keresés, „Több mutatása” | mindig | `pa_anyag` |
| `szin` | Szín | színminta | mindig | `pa_szin` (a színkód a kifejezés meta mezőjében) |
| `allapot` | Elérhetőség és ajánlat | jelölőnégyzet (ÉS) | mindig | készlet, akciós ár, „Új” címke |
| `q` | Keresés a kínálatban | szöveg | mindig | név, cikkszám, leírás, adatlap |
| `orderby` | Rendezés | – | – | WooCommerce `orderby` |

Az értékek vesszővel, a tartományok kötőjellel szerepelnek, pl. `termekek.html?cat=szakralis-targyak&sub=hangtalak&hang=G,G%23&suly=300-600`. A régi linkek is működnek: `intent`, `origin`, `max`, `sale=1`, `instock=1`.

**Gyors szűrések** (kontextusfüggő): Első hangtálnak (300–600 g), Mély zengés (700 g felett), Kézzel kovácsolt, Torokcsakra, Földes tibeti illat, Ajándék 10 000 Ft alatt, Nepáli kézműves, Akciós, Csak raktáron. A lista a `facets.js` `PRESETS` tömbjében bővíthető.

## Felület

- Lenyíló csoportok; a nyitott/csukott állapot megmarad, a kijelöltek száma a fejlécben látszik.
- Aktív szűrők chipként, egyenként és együtt is törölhetők.
- Az állapot az URL-ben él: a szűrés megosztható, a vissza gomb visszalépteti.
- Újrarajzolás után a fókusz a helyén marad, a darabszám `aria-live`-val hangzik el, minden vezérlő billentyűzettel kezelhető.
- Mobilon oldalpanel, alul élő „N termék mutatása” és „Törlés” gombbal.
- „Több termék betöltése” lapozás.

## WordPress megvalósítás (child téma)

1. **Adatmodell:** a fenti `pa_*` attribútumok és a két numerikus meta mező; felvételük telepítő lépésként.
2. **Blokk:** `inc/blocks/filter/block.php` (`iucb_add_block('mandala/filter', …)`), `view_script`: a `facets.js` + `shop.js` logikája.
3. **Adatforrás:** saját REST végpont a téma `rest_url()`-jével, a skill szerint egyedi prefixszel. Termékenként kompakt JSON (id, ár, készlet, kategória, attribútumok), transientben gyorsítótárazva, termékmentéskor és készletváltozáskor ürítve. A darabszámokat és a szűrést a böngésző számolja, így nincs újratöltés. Nagyon nagy kínálatnál (több ezer termék) szerveroldali indextábla javasolt.
4. **Lista:** a `[products class="mainquery"]` első oldala szerveroldalon renderelődik (SEO, JS nélkül is működik); a szűrés után a kártyák a „Loop Product” minta szerint rajzolódnak.
