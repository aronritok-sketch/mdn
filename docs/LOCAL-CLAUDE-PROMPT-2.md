# Prompt a helyi Claude-nak – 2. kör (a vásárlói végigtesztelés után)

> Előtte: töltsd fel az új témát (mandala-tema.zip, Megjelenés → Témák → Új hozzáadása → Feltöltés →
> **Csere a feltöltöttre**). A téma ezeket már magától javítja: a pénztár országa alapból Magyarország, az
> ingyenes szállítás csak belföldre jár, a GLS csomagpont-választó a szállítási mód alá kerül, a „Fizetés
> helyszínen” csak személyes átvételnél választható, és a hangtál-választó jól kezeli a csakrákat és a keretet.
> A jogi oldalakat, az Impresszumot, a mintaoldalt és a bemutatóterem címét is a téma javítja (5. pont). Az alábbiak
> admin-beállítások, ezeket a téma nem tudja megcsinálni.

---

Szia! A mandala.hu WordPress + WooCommerce adminjában kell néhány beállítást elvégezned. Be vagyok lépve,
a böngésző nyitva van. Magyarul dolgozz. Szabályok: rendelést, vásárlót, terméket ne törölj; fizetési kulcsokhoz,
jelszavakhoz ne nyúlj, és ne írd ki őket; minden lépés előtt írd le a mostani értéket (régi → új), mentés után
ellenőrizd; ha valami nem egyezik a leírással, vagy döntés kell, állj meg és kérdezz. A `/juta/` kezdetű címeket
ne nyisd meg.

1. **Téma verziója.** Megjelenés → Témák → Mandala: írd le a verziót (legalább 1.1.20261002…, az új zipből).

2. **Horvátország.** WooCommerce → Beállítások → Szállítás → a „Románia, Szlovákia, Csehország, Horvátország”
   zóna: a „Zóna régiói” között most nincs ott **Horvátország** (a horvát vevő „nincs elérhető szállítási mód”
   üzenetet kap). Add hozzá, mentés.

3. **Mely országokba adunk el.** WooCommerce → Beállítások → Általános → „Értékesítés helye(i)” és „Szállítás
   helye(i)”: most olyan országokat is enged (pl. Németország, USA), ahová egyetlen szállítási zóna sincs – ott a
   vevő kitölti a pénztárat, aztán elakad. Írd le a mostani beállítást, és **kérdezd meg tőlem**: csak azok az
   országok maradjanak, ahová van zóna (Magyarország, Ausztria, Szlovénia, Szlovákia, Csehország, Románia,
   Horvátország), vagy legyen egy „Európa többi része” zóna saját díjjal?

4. **GLS csomagpont térkép – Google Maps kulcs.** A pénztárban a csomagpont-térkép most „Ez az oldal nem tudja
   megfelelően betölteni a Google Térképet” hibát és „For development purposes only” vízjelet mutat, mert a Pont
   bővítményben nincs Google Maps API-kulcs. Keresd meg a bővítmény beállításait (WooCommerce → Beállítások →
   Szállítás → „Pont” / „GLS csomagpont”, vagy Beállítások → Pont), és nézd meg, van-e „Google Maps API key”
   mező. Ha üres: **állj meg, és szólj** – a kulcsot a tulajdonos Google-fiókjában kell létrehozni (Google Cloud
   Console → Maps JavaScript API, a kulcs a mandala.hu-ra korlátozva). Ha a bővítmény tud térkép nélküli módot
   (csak legördülő lista), írd le, hol kapcsolható.

5. **A téma automatikus javításai – csak ellenőrizd.** Az új téma az első oldalbetöltéskor egyszer (revízióval) elvégzi:
   Impresszum helykitöltő mondata törölve; Akadálymentességi nyilatkozat kitöltve (elérhetőség, hatóság, dátum);
   ÁSZF: Teya az OTP / SimplePay helyett + a mai fizetési módok és szállítási díjak; „A bankkártyás fizetésről”: a CIB
   helyett Teya; a WordPress mintaoldala a lomtárban; a bemutatóterem címe 1093 Budapest, Bakáts u. 6., nyitvatartás
   H 9–17, K–P 9–15. Nyisd meg és nézd át: https://mandala.hu/impresszum/ , https://mandala.hu/akadalymentesseg/ ,
   https://mandala.hu/aszf/ , https://mandala.hu/a-bankkartyas-fizetesrol/ , https://mandala.hu/kapcsolat/ (a térkép
   gombra kattintva a Bakáts utca jelenik meg). Ha valamelyik nem változott, szólj (ne írd át kézzel).

6. **Ingyenes kiszállítás (free_shipping:2) és utánvét.** A „Magyarország” zóna „Ingyenes kiszállítás” módja ki van
   kapcsolva (ideiglenesen, 2026-10-02) – így maradjon: 25 000 Ft felett a téma minden módot 0 Ft-ra tesz, és az új téma
   akkor is visszahozza a GLS házhoz / MPL / GLS csomagpont módokat, ha egy kód (pl. a „Woocommerce – Add required
   woocommerce customizations” mu-plugin) elrejtené őket az ingyenes mód miatt. Ha a tulajdonos a módot **végleg
   törli**: Fizetés → Utánvét → „Engedélyezés szállítási módokhoz” listából is vedd ki az „Ingyenes kiszállítás”-t,
   mentés. (A mu-plugin forrását az admin nem mutatja – azt tárhely-hozzáféréssel kell megnézni, lásd lent.)

7. **Ellenőrzés.** Nyisd meg privát ablakban a https://mandala.hu/ oldalt, tegyél egy terméket a kosárba, menj a
   pénztárig (NE rendelj):
   - az Ország mezőben magától **Magyarország** áll, és rögtön látszik a 4 szállítási mód (GLS házhoz, MPL,
     Személyes átvétel, GLS csomagpont);
   - GLS csomagpontot választva közvetlenül alatta megjelenik a „Válassz GLS csomagpont átvevőhelyet” rész;
   - GLS házhoznál a fizetési módok: Utánvét (+490 Ft), Teya – a „Fizetés helyszínen” NINCS ott; Személyes
     átvételnél ott van;
   - az országot Ausztriára állítva a szállítás 5 000 Ft, és 25 000 Ft feletti kosárnál sem lesz ingyenes;
   - Horvátországra állítva van szállítási mód;
   - 25 000 Ft feletti kosárnál (Magyarország) mind a 4 mód látszik, 0 Ft-tal (a személyes átvétel ingyenes).

**Jelentés a végén** táblázatban: pontonként mi volt, mire állítottad, sikerült-e, és mire vársz tőlem választ
(országlista, Google Maps kulcs).

---

**Nem a prompt része – a mu-pluginhoz (tárhely-hozzáféréssel, pl. Laci vagy az ELIN fájlkezelője):**
`wp-content/mu-plugins/` mappában keresd azt a fájlt, amelyikben a `woocommerce_package_rates` szűrő és a
`free_shipping` szó együtt szerepel (valószínűleg az `iu_woocommerce` betöltője). Ha ott egy „hide shipping when free
is available” jellegű függvény van, amely a `free_shipping` mellett minden mást kivesz, az okozta a hibát. Javítás:
az `add_filter('woocommerce_package_rates', …)` sort tedd megjegyzésbe – vagy ha az Infinite Unity frissítése
felülírná, nem kell hozzányúlni: az új téma a végén visszateszi a kivett módokat (kapcsoló:
`add_filter('mandala_keep_paid_rates_with_free', '__return_false')` kikapcsolja ezt a védelmet).
