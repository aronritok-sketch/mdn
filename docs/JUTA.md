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

## Költözéskor / élesítéskor ellenőrizni

1. **A `/juta` mappa az új web gyökerében is ott van**, a beállításai (adatbázis-kapcsolat, táblaelőtag, elérési utak)
   az új WordPressre mutatnak, és a **cron** az új helyen futó fájlokat hívja.
2. **Rendelések tárolása (HPOS):** ha a WooCommerce az új „nagy teljesítményű rendeléstárolást” (HPOS) használja, egy
   régi, a `wp_posts` táblát olvasó szkript **nem látja az új rendeléseket**. Ilyenkor: WooCommerce → Beállítások →
   Speciális → Funkciók → *Kompatibilitási mód (szinkronizálás a bejegyzés-táblákkal)* bekapcsolva – vagy az
   `elad.php` HPOS-képes legyen. Az élesítési állapot (`mandala/v1/readiness` → `juta`) mutatja, látják-e.
3. **Átköltöztetett régi rendelések:** a „Régi bolt adatai” átvétel a régi rendeléseket ÚJ azonosítóval hozza (a régi
   rendelésszám megmarad és látszik). Ha az `elad.php` a „legutóbb beküldött azonosító” vagy egy jelölő alapján
   választ, nem szabad, hogy a régi, már beküldött rendeléseket újra elküldje. **A `elad.php` kiválasztási feltételét
   az első futás előtt nézd át** (az átvétel a rendelés régi metaadatait – pl. egy „beküldve” jelölőt – átveszi).
4. A rendelésszám: a téma a régi rendeléseknél a régi számot mutatja; az új rendelések a régiek fölött folytatódnak.
5. A `/juta/*.php` fájlokat **ne nyisd meg böngészőből** tesztelésként: minden megnyitás lefuttatja őket (import /
   beküldés).
