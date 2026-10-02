# Prompt a helyi Claude-nak – 2. kör (a vásárlói végigtesztelés után)

> Előtte: töltsd fel az új témát (mandala-tema.zip, Megjelenés → Témák → Új hozzáadása → Feltöltés →
> **Csere a feltöltöttre**). A téma ezeket már magától javítja: a pénztár országa alapból Magyarország, az
> ingyenes szállítás csak belföldre jár, a GLS csomagpont-választó a szállítási mód alá kerül, a „Fizetés
> helyszínen” csak személyes átvételnél választható, és a hangtál-választó jól kezeli a csakrákat és a keretet.
> Az alábbiak admin-beállítások, ezeket a téma nem tudja megcsinálni.

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

5. **Impresszum.** Oldalak → Impresszum: a „Hatályos: 2026. október 1-től.” utáni mondatot
   („A szöveg helykitöltő – a végleges változat jogászi átnézés után kerül fel.”) töröld, ha az adatok rendben
   vannak (cég, székhely, cégjegyzékszám, adószám, ELIN). Mentés.

6. **Mintaoldal.** Oldalak → „Ez egy minta oldal” (a WordPress alapértelmezett mintaoldala, közzétéve,
   https://mandala.hu/ez-egy-minta-oldal/): helyezd a lomtárba.

7. **Bemutatóterem címe.** WooCommerce → Mandala bolt adatai: a bemutatóterem / átvevőhely pontos címe most
   üres – a kapcsolat oldalon csak „Budapest” látszik, a térkép helyén „A térkép a pontos cím megadása után
   jelenik meg”, és a személyes átvételnél is csak „Budapest” áll. **Kérdezd meg tőlem a pontos címet**
   (a cég székhelye 1093 Budapest, Bakáts u. 6. – de ne írd be, amíg nem erősítem meg, hogy a bemutatóterem is ott
   van), utána írd be, mentés, és nézd meg a https://mandala.hu/kapcsolat/ oldalt.

8. **Jogi szövegek – csak jelezd, ne írd át.**
   - ÁSZF: még az OTP Mobil Kft.-t / SimplePay-t említi adatfeldolgozóként.
   - „A bankkártyás fizetésről”: végig a CIB Bankról szól.
   - A kártyás fizetés ma **Teya** – ezeket jogásznak / a tulajdonosnak kell átírnia. Írd ki nekem pontosan,
     melyik bekezdésekben szerepel az OTP / SimplePay / CIB.
   - Akadálymentességi nyilatkozat: két „kitöltendő” rész van (a hatóság neve és elérhetősége; a legutóbbi
     ellenőrzés dátuma) – listázd ki őket.

9. **Ellenőrzés.** Nyisd meg privát ablakban a https://mandala.hu/ oldalt, tegyél egy terméket a kosárba, menj a
   pénztárig (NE rendelj):
   - az Ország mezőben magától **Magyarország** áll, és rögtön látszik a 4 szállítási mód (GLS házhoz, MPL,
     Személyes átvétel, GLS csomagpont);
   - GLS csomagpontot választva közvetlenül alatta megjelenik a „Válassz GLS csomagpont átvevőhelyet” rész;
   - GLS házhoznál a fizetési módok: Utánvét (+490 Ft), Teya – a „Fizetés helyszínen” NINCS ott; Személyes
     átvételnél ott van;
   - az országot Ausztriára állítva a szállítás 5 000 Ft, és 25 000 Ft feletti kosárnál sem lesz ingyenes;
   - Horvátországra állítva van szállítási mód.

**Jelentés a végén** táblázatban: pontonként mi volt, mire állítottad, sikerült-e, és mire vársz tőlem választ
(országlista, Google Maps kulcs, bemutatóterem címe, jogi szövegek).
