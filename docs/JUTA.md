# JUTA – termékimport és rendelésbeküldés

A JUTA-Soft (raktár/készlet) és a webshop közti kapcsolat **nem a téma része**, hanem a tárhelyen futó saját szkriptek:

- `/juta/` mappa a web gyökerében (a WordPress mellett),
- `/juta/raw_sync.php` – termékek importja és frissítése a JUTA alapján (név, cikkszám, ár, „Akciós ár”, készlet),
- `/juta/elad.php` – a webshop rendeléseinek beküldése a JUTA felé,
- mindkettőt a tárhely **cronja** hívja megfelelő időközönként; a mappában további segédfájlok, -mappák is vannak.

A téma ezekhez nem nyúl. Amit a téma a JUTA-ból érkező termékekkel csinál: [UJ-TERMEKEK.md](UJ-TERMEKEK.md) (az új termék
piszkozat lesz és a jóváhagyási sorba kerül – a közvetlenül adatbázisba írt termékeket is óránként megtalálja; az
„Akciós ár” a nagyker ár).

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
