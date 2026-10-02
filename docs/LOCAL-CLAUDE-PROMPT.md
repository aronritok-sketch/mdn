# Prompt a helyi Claude-nak – a mandala.hu admin beállításai

> Másold be az alábbi szöveget a böngészőt kezelő (helyi) Claude-nak. A mandala.hu adminjába előtte lépj be
> a saját fiókoddal (a belépési cím a WPS Hide Login miatt egyedi), és tartsd megnyitva a böngészőben.
> Mellé töltsd le a `mandala-jogi-oldalak.json` és a legújabb `mandala-tema.zip` fájlt (a Letöltések mappába).

---

Szia! A mandala.hu WordPress + WooCommerce webáruház adminjában kell beállításokat elvégezned. Én (a tulajdonos
oldaláról) be vagyok lépve, a böngésző nyitva van. Magyarul dolgozz, és ezeket a szabályokat tartsd be:

**Szabályok**
- Rendelést, vásárlót, terméket, kupont NE törölj, és ne módosíts rendelést.
- Fizetési kulcsokhoz, jelszavakhoz, API-kulcsokhoz ne nyúlj, és ne írd ki őket.
- Minden lépés előtt nézd meg a mostani értéket, és írd le, mit változtatsz (régi → új). Mentés után ellenőrizd,
  hogy megmaradt.
- Ha valami nem úgy néz ki, ahogy itt leírtam, vagy döntés kell, ne találgass: állj meg, és kérdezz.
- Bővítményt csak ott kapcsolj ki/be, ahol a feladat kéri.
- A `/juta/` kezdetű címeket ne nyisd meg (minden megnyitás lefuttat egy importot vagy rendelésbeküldést).

**Feladatok, sorrendben**

1. **Téma frissítése.** Megjelenés → Témák → Új hozzáadása → Téma feltöltése → `mandala-tema.zip` (Letöltések) →
   „Csere a feltöltöttre”. Utána a Megjelenés → Témák oldalon a Mandala téma verziója 1.1.2026… legyen, és újabb,
   mint 1.1.202610021409. Írd le a verziót.

2. **Szállítási zónák.** WooCommerce → Beállítások → Szállítás.
   - „Magyarország” zóna: a „Zóna régiói” mezőbe add hozzá: **Magyarország**. (Most üres, ezért minden külföldi
     cím is a hazai díjat kapja.)
   - „Románia, Szlovákia, Csehország, Horvátország” zóna régiói: Románia, Szlovákia, Csehország, **Horvátország**
     legyenek; **Ausztriát vedd ki** (az a „Szlovénia, Ausztria” zónába tartozik).
   - A „Szlovénia, Ausztria” zóna: Szlovénia és Ausztria – maradjon.
   - A zónák sorrendje: Magyarország, Románia…, Szlovénia…, utolsó a „minden más terület”.

3. **GLS csomagpont.** A régi boltban volt „GLS csomagpont” szállítási mód (Pont shipping for WooCommerce
   bővítmény), most egyik zónában sincs. A „Magyarország” zónában: Szállítási mód hozzáadása → a Pont shipping
   bővítmény csomagpont módja (vagy a „GLS szállítás WooCommerce-hez” bővítmény csomagpont / csomagautomata
   módja – amelyik a GLS-t adja). Cím: „GLS csomagpont”, díj: kérdezd meg tőlem (a régi bolt a bővítmény árát
   használta). Ha nem egyértelmű, melyik bővítmény mód a jó, állj meg és kérdezz.

4. **Utánvét.** WooCommerce → Beállítások → Fizetés → Utánvétes fizetés → Kezelés.
   - Cím: **Utánvétes fizetés** (most „Utánvétes fizetés (390 Ft)” – a díjat a téma külön mutatja, 490 Ft).
   - „Engedélyezés szállítási módokhoz”: most régi, nem létező azonosítók vannak benne (pl. flat_rate:10).
     Válaszd ki újra: **GLS házhozszállítás**, **MPL házhozszállítás**, **Ingyenes kiszállítás**, és a 3. pontban
     felvett **GLS csomagpont**. A Személyes átvételt NE (ott helyszíni fizetés van).
   - Mentés.

5. **Teya visszatérési címek.** WooCommerce → Beállítások → Fizetés → Teya – Bankkártyás fizetés → Kezelés.
   Csak ezt a három mezőt írd át (a kulcsokhoz ne nyúlj):
   - Success URL: `https://mandala.hu/penztar/order-received/`
   - Cancel URL: `https://mandala.hu/penztar/`
   - Error URL: `https://mandala.hu/penztar/`

6. **Rendelések a JUTA-nak (HPOS kompatibilitás).** WooCommerce → Beállítások → Speciális → Funkciók.
   A „Rendelési adatok tárolása” részben kapcsold be: **„Kompatibilitási mód engedélyezése (szinkronizálja a
   rendeléseket a bejegyzések táblával)”**. Mentés. (A JUTA rendelésbeküldő szkriptje enélkül nem látja az új
   rendeléseket.) A szinkron első futása pár percig tarthat – várd meg, és írd le, mit mutat.

7. **Bolt adatai.** WooCommerce → Mandala bolt adatai.
   - Telefon: `+36 30 892 8385` → Mentés.
   - Nyitvatartás: kérdezd meg tőlem a mostanit. (A régi ÁSZF szerint: Hétfő 9–17, Kedd–Péntek 9–15,
     Szombat–Vasárnap zárva.) Ugyanezt állítsd be lent a „Bemutatóterem (Google)” részben is (külön gombbal ment).
   - Utánvét díja: **490** – ellenőrizd, hogy ennyi.

8. **Jogi oldalak.** WooCommerce → Régi bolt beállításai → fájl feltöltése: `mandala-jogi-oldalak.json`
   (Letöltések) → az előnézetben csak a „Jogi oldalak” rész jelenik meg → Átvétel. Utána nyisd meg és nézd át:
   https://mandala.hu/aszf/ , https://mandala.hu/adatvedelmi-tajekoztato/ , https://mandala.hu/a-bankkartyas-fizetesrol/
   – egyikben se maradjon „kitöltendő” szöveg.
   - Megjegyzés nekem: a régi szövegek a CIB Bankot / SimplePay-t említik, a kártyás fizetés ma Teya – ezt jelezd,
     de NE írd át magadtól (jogi szöveg).

9. **Impresszum.** Oldalak → Impresszum → szerkesztés. A „[…kitöltendő]” részek helyére (a régi ÁSZF adatai):
   - Üzemeltető: Asita Cult Kft., székhely: 1093 Budapest, Bakáts u. 6., cégjegyzékszám: 01-09-693769
     (nyilvántartást vezeti a régi ÁSZF szerint: „IM Cégnyilvántartási és Céginformációs Sz.”), statisztikai
     számjel: 12587128-5147-113-01, adószám: 12587128-2-43
   - Kapcsolat: e-mail és telefon – a 7. pont adatai (e-mail: kérdezd meg, melyik legyen: info@mandala.hu vagy
     mandalanagyker@gmail.com)
   - Tárhelyszolgáltató: ELIN.hu – a pontos cégnév, cím, elérhetőség az elin.hu saját oldaláról (impresszum /
     kapcsolat); nyisd meg és onnan másold, ne írj be emlékezetből.
   - Az **Akadálymentességi nyilatkozat** oldalon is vannak „kitöltendő” részek: ezeket listázd ki nekem, ne töltsd ki.

10. **Levelek.**
    - WP Mail SMTP → Eszközök → Próbalevél: küldj egyet a(z) [SAJÁT CÍM] címre, írd le az eredményt.
    - WooCommerce → Mandala levelek → Saját levelek → Új levél: név „Regisztrációs kupon”, indító **„Új vásárlói
      fiók regisztrációjakor”**, címzett: vásárló, kupon: **százalék, 10**, érvényesség 30 nap, tárgy:
      „Üdv a Mandalában – 10% az első rendelésedre”, szöveg: rövid köszöntő + `{kupon_doboz}` + `{gomb_bolt}`.
      Bekapcsolva → Mentés → előnézet.
    - Mandala varázsló → levelek lépés: a MailerLite csoport legördülőből válaszd ki a hírlevél csoportot (ha nem
      egyértelmű, melyik, kérdezz).

11. **Felhasználók és biztonság.**
    - Felhasználók: a **claude-ellenorzes** felhasználót töröld (tartalmát rendeld hozzá a saját fiókomhoz).
      A többi adminhoz (Fejleszto, Hanni, claude) ne nyúlj, csak listázd őket.
    - Bővítmények: a **Wordfence** most ki van kapcsolva. Kapcsold vissza, majd Wordfence → Login Security →
      Settings: ha van „Disable WordPress application passwords” pipa, legyen KIKAPCSOLVA; Wordfence → Firewall →
      All Firewall Options → „Allowlisted IP addresses”: add hozzá: `160.79.106.128`. Mentés. Utána ellenőrizd,
      hogy a Felhasználók → claude profilban az „Alkalmazásjelszavak” rész látszik.
    - Beállítások → Beszélgetés: „Az új bejegyzésekhez engedélyezett a hozzászólás” – kapcsold KI (spam).

12. **Ellenőrzés a végén.**
    - Mandala varázsló → „Önellenőrzés” gomb: írd le, mi piros / sárga.
    - Nyisd meg privát ablakban a https://mandala.hu/ oldalt, tegyél egy terméket a kosárba, menj a pénztárig
      (NE rendelj): a szállítási módoknál legyen GLS házhoz, GLS csomagpont, MPL, Személyes átvétel; utánvétet
      válaszd GLS-sel és MPL-lel is – mindkettőnél elérhető, és +490 Ft díj jelenik meg.

**Jelentés a végén** (táblázatban): minden pontnál – mi volt, mire állítottad, sikerült-e, és ami nyitva maradt
vagy döntés kell hozzá. Utána írd le külön, mire várnak tőlem válasz (nyitvatartás, GLS csomagpont díja, e-mail
cím, MailerLite csoport, akadálymentességi nyilatkozat).

---

Nem a prompt része (csak neked): ami nem admin-beállítás, és a fenti listában nincs:
- **SSL tanúsítvány**: 22 nap múlva lejár (2026-10-24 körül) – kérdezd meg az ELIN-t, hogy automatikusan megújul-e.
- **JUTA**: Lacinak a „JUTA beállítása az új mandala.hu-n” dokumentum; nézzétek meg a JUTA-ban, hogy a
  2026-10-01 óta jött rendelések (#27201, #27204, #27212, #27232) bekerültek-e.
- **Search Console**: a https://mandala.hu/wp-sitemap.xml oldaltérkép beküldése (a régi sitemap_index.xml már oda irányít).
- **Próbarendelések**: a varázsló 8 próbája (Mandala varázsló → Próbarendelések).
