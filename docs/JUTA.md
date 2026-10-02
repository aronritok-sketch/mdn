# JUTA – termékimport és rendelésbeküldés

A JUTA-Soft (raktár/készlet) és a webshop közti kapcsolat **nem a téma része**, hanem a tárhelyen futó saját szkriptek:

- `/juta/` mappa a web gyökerében (a WordPress mellett),
- `/juta/raw_sync.php` – termékek importja és frissítése a JUTA alapján (név, cikkszám, ár, „Akciós ár”, készlet),
- `/juta/elad.php` – a webshop rendeléseinek beküldése a JUTA felé,
- mindkettőt a tárhely **cronja** hívja megfelelő időközönként; a mappában további segédfájlok, -mappák is vannak.

**A szkripteken nem kell változtatni.** A JUTA-szkriptek a sebesség miatt nem töltik be a WordPresst, közvetlenül az
adatbázisba írnak – ez így marad. A téma ezt magától kezeli (`inc/features/juta.php`, „figyelő”): 10 percenként
összeveti a termékek ár-, készlet-, cikkszám- és állapotadatainak lenyomatát az előzővel (egy lekérdezés, ~3000
terméknél kb. 0,2 mp), és a megváltozott termékeknél

- törli az objektum-gyorsítótárat (**Redis**) – különben a közvetlenül átírt ár / készlet a boltban a régi maradhat,
- újraszámoltatja a kereső- és szűrőindexet, az új termékeket a jóváhagyási sorba teszi ([UJ-TERMEKEK.md](UJ-TERMEKEK.md):
  az új termék piszkozat lesz; az „Akciós ár” a nagyker ár),
- feljegyzi a szinkron idejét – az őrszem szól, ha 26 óránál régebben volt változás.

Nem kötelező, de ha valaha WordPress-betöltéssel futnak a szkriptek, azonnali jelzést is adhatnak: `define('MANDALA_JUTA_SYNC',
true)` a `wp-load.php` előtt, `do_action('mandala_juta_sync_done')` a végén, `mandala_juta_orders_to_send()` /
`do_action('mandala_juta_orders_sent', $ids)` a rendeléseknél. Részletes útmutató Lacinak: a „JUTA beállítása az új
mandala.hu-n” dokumentum.

**Ami a szkriptek oldalán számít** (ezek adatbázis-szinten is igazak): a rendelések helye (HPOS, 2. pont) és hogy a régi,
átköltöztetett rendeléseket ne küldje újra (3. pont).

## A két szkript az új boltban (átnézve: 2026-10-02)

**`raw_sync.php`** – a `keszlet.csv` alapján cikkszám (`barcode`) szerint frissíti a `_regular_price`, `_price` (ha nincs
akció), `_stock`, `_stock_status`, `_manage_stock` és a nagyker ár (`wholesale_customer_wholesale_price` – a téma
viszonteladói ára ugyanezt olvassa) mezőket, és a `wc_product_meta_lookup` táblát; az ismeretlen cikkszámból piszkozat
termék lesz. Az új bolttal **változtatás nélkül működik**; a téma figyelője (fent) gondoskodik a gyorsítótárról, a
keresőről és a jóváhagyási sorról.

**`elad.php`** – a **HPOS-táblákat** olvassa (`md_wc_orders`, `md_wc_orders_meta`, `md_wc_order_addresses`), így a
WooCommerce kompatibilitási szinkronja nem kell hozzá. A „teljesített” (`wc-completed`) rendelések közül azt írja ki
(`elad/eladNNNN.txt`), amelyiken nincs `_elad_exported_file` jelölő, és az állapotfájl (`elad-export-state.json`) kezdő
időpontja után módosult; utána ráteszi a jelölőt.

- **Átköltöztetett rendelések:** az átvétel a régi rendeléseket új sorba írja, a módosítási idejük az átvétel napja. Hogy
  az `elad.php` ne küldje be őket újra, a téma **minden átvett rendelésre `_elad_exported_file = regi-bolt` jelölőt tesz**
  (az átvételkor, és a már átvetteket a téma frissítése után magától, kötegekben pótolja). Ellenőrizni: az `elad/`
  mappában nincs-e 2026-10-01 óta tömegesen keletkezett fájl (ha az új témánál korábban már lefutott volna).
- **Teya:** a kártyás fizetés azonosítója `borgun` – ez nincs a szkript kártyás listájában
  (`['stripe', 'barion', 'simplepay', 'paypal']`), ezért a Teyás rendelés `fizmod=utalas`-ként megy át. Javasolt:
  `'borgun'` hozzáadása a listához.
- `cheque` = a „Fizetés helyszínen készpénzzel, vagy bankkártyával” (személyes átvétel) → `fizmod=csekk`: ha a JUTA-ban
  ez mást jelent, érdemes átnevezni a leképezést.
- A szkript csak a termék tételeket küldi (`line_item`); a szállítási díj és az utánvét díja nem kerül a JUTA-bizonylatra –
  ha eddig is így volt, rendben.

## Költözéskor / élesítéskor ellenőrizni

1. **A `/juta` mappa az új web gyökerében is ott van**, a beállításai (adatbázis-kapcsolat, táblaelőtag, elérési utak)
   az új WordPressre mutatnak, és a **cron** az új helyen futó fájlokat hívja.
2. **Rendelések tárolása (HPOS):** az `elad.php` közvetlenül a HPOS-táblákat olvassa – a kompatibilitási szinkron
   ehhez nem kell (ha be van kapcsolva, nem árt, csak lassít egy kicsit).
3. **Átköltöztetett régi rendelések:** a téma `_elad_exported_file = regi-bolt` jelölőt tesz rájuk (lásd fent), így az
   `elad.php` nem küldi őket újra.
4. A rendelésszám: a téma a régi rendeléseknél a régi számot mutatja; az új rendelések a régiek fölött folytatódnak.
5. A `/juta/*.php` fájlokat **ne nyisd meg böngészőből** tesztelésként: minden megnyitás lefuttatja őket (import /
   beküldés).
