# Átállás: az új bolt költözik a mandala.hu-ra

Döntés: az új bolt (most: mandala.hu/new/) megy át a mandala.hu címre, a régi bolt adatait áthozzuk.
A lépések sorrendben; a ☐ az, amit ki kell pipálni. Ahol eszköz van rá, a menüpontot írjuk.

## A) Előkészítés a devben (napokkal előtte)

1. ☐ **Új téma feltöltése** (mandala-tema.zip): Megjelenés → Témák → Új hozzáadása → Téma feltöltése →
   **Csere a feltöltöttre** (a WordPress mutatja: telepítve 1.0.0 → feltöltött 1.1.…). Ellenőrzés: a WooCommerce
   menüben megjelenik a „Régi bolt adatai”. (Vagy: az új varázsló-zip feltöltése bővítményként, cserével, és a
   „Téma frissítése most” gomb.)
2. ☐ **Régi bolt beállításai újra** (WooCommerce → Régi bolt beállításai): export a régi admin konzoljából, feltöltés,
   átvétel. Most már hozza az utánvét szállítási módjait (MPL-lel is), a 390 Ft utánvéti díjat (a téma számolja,
   a címből kikerül), a jogi oldalakat, és a Teya visszatérési címeit az új pénztárra (/penztar/) írja.
3. ☐ **Szállítási zónák** (mindkét boltban hibás, a régiben is): a „Magyarország” zónához add hozzá Magyarországot
   (különben minden külföldi a hazai díjat fizeti), a „…Horvátország” zónában cseréld Ausztriát Horvátországra.
4. ☐ **Jogi szövegek:** „A bankkártyás fizetésről” a CIB Bankot, az ÁSZF SimplePay/OTP-t említi – a kártyás fizetés
   Teya. A tulajdonos / jogász frissítse (a Teya előírja). Impresszum: a kitöltendő részek.
5. ☐ **Mandala bolt adatai:** valódi telefonszám (most +36 1 234 5678).
6. ☐ **Levélküldés:** a WP Mail SMTP nem éri el a szervert. A régi FluentSMTP beállításai (vagy 587-es port TLS-sel,
   vagy API-s küldő), utána próbalevél. A küldő domainnél SPF és DKIM rendben legyen.
7. ☐ **GLS csomagpont:** a régiben a „Pont shipping for Woocommerce” adja – azt vagy a GLS bővítmény csomagpont
   módját beállítani, és az utánvéthez engedélyezni.
8. ☐ **Bővítmények:**
   - kell: oldal-gyorsítótár (WP Rocket, mint a régin) + Redis; GTM4WP ugyanazzal a konténerrel; WPS Hide Login;
   - NEM kell: Payment Gateway Based Fees (a téma adja az utánvéti díjat), YITH Gift Cards (a kártyák a téma
     utalványaiként jönnek át), Elementor/Kitty, feed-bővítmények (a téma adja), MailerLite-Woo (a téma adja);
   - dönteni: Yoast (a téma SEO-ja nélküle is megy, a blog SEO-címei átjönnek), WPML (kell-e több nyelv);
   - átnézni: a régi WPCode Lite kódrészletei – mit csinálnak (ezt csak az adminban látni).
9. ☐ **Kulcsok:** Árukereső Megbízható Bolt, Meta pixel + CAPI (a Claude-kulcs megvan).
10. ☐ **Termékek:** 102 termék Claude-besorolása; a jóváhagyási sor átnézése.
11. ☐ **Költöztető segéd a RÉGI boltba** (mandala-koltozes-segito.zip → régi admin: Bővítmények → Új → Feltöltés →
    Bekapcsolás). Csak adminnak, csak olvas: a jelszavak lenyomatát adja az exportnak, így a vásárlók a **régi
    jelszavukkal** lépnek be az új boltba. A költözés után töröld.
12. ☐ **Próbaátvétel** (WooCommerce → Régi bolt adatai): export a régi admin konzoljából, feltöltés, átvétel.
    Vásárlók, rendelések (régi rendelésszámmal), kuponok, értékelések, blog képekkel, hiányzó oldalak tervezetként,
    ajándékkártyák. Levél nem megy ki. Nézz át néhány rendelést, vásárlót, blogcikket; a tervezet oldalakból
    tedd közzé, ami kell. (Ha valami nem stimmel: „Átvett adatok törlése”, javítás, újra.)
13. ☐ **A telepítő varázsló 8 próbája** (Próbarendelések lépés): kártyás (Teya) és utánvétes rendelés GLS CsomagPontra,
    átutalás (ha bekapcsolod), Számlázz.hu számla, GLS csomagszám + „Feladtuk” levél, mobilos vásárlás, hírlevél-
    feliratkozás, AI tanácsadó. Pluszban: egy átvett régi YITH ajándékkártya kódjának beváltása a pénztárban.

## B) A váltás napja

1. ☐ **Régi bolt lezárása:** karbantartás / „hamarosan” mód, hogy ne jöjjön új rendelés.
2. ☐ **Utolsó adatátvétel:** a régi admin konzoljából újra export → az új boltban újra átvétel. Csak a különbséget
   hozza (új rendelések, állapotok, jegyzetek, kártyaegyenlegek), duplikáció nélkül.
3. ☐ **Készlet:** friss készlet a JUTA-importtal (vagy a régi bolt termékexportjából).
4. ☐ **Költöztetés a mandala.hu-ra** (tárhely / fejlesztő): fájlok + adatbázis másolása, a címek cseréje
   (`wp search-replace 'https://mandala.hu/new' 'https://mandala.hu'` (vagy a dev címéről, ha onnan költözik) – sorosított adatokkal is),
   SSL, DNS. A régi bolt maradjon elérhető egy alcímen (pl. regi.mandala.hu) jelszóval, archívumnak.
5. ☐ **Keresők engedélyezése** (Beállítások → Olvasás), Search Console: webhelytérkép beküldése.
6. ☐ **Ellenőrzés élesben:**
   - kis összegű valódi kártyás fizetés Teyával → visszaérkezés a köszönőoldalra → visszatérítés;
   - utánvétes rendelés, levelek megérkeznek (vásárló + bolt);
   - régi linkek: /checkout/…, /cart/, /my-account/… és a régi termék- / kategóriacímek átirányítanak
     (a 404 naplót nézd: Eszközök → Átirányítások);
   - régi vásárló belépése: magyarázatot kap, új jelszót kér, a régi rendelései látszanak;
   - a varázsló önellenőrzése zöld.
7. ☐ **Statisztika:** WooCommerce → Analytics → Beállítások → „Előzmények importálása”.
8. ☐ **Takarítás:** a letöltött JSON-fájlok törlése a gépről (személyes adatok, jelszó-lenyomatok, fizetési kulcsok);
   a költöztető segéd törlése a régi boltból; a
   `claude-ellenorzes` felhasználó törlése mindkét boltban.

## C) Utána (1–2 hét)

- Naponta: 404 napló (Eszközök → Átirányítások), őrszem-riasztások, a heti tulajdonosi kivonat.
- Opcionális: levél a régi vásárlóknak („új webáruház, kérj új jelszót”) – a levélközpontból, egyszer.

## Amit a régi boltból nem lehet áthozni

- **Jelszavak – csak a költöztető segéddel jönnek át** (A/11). Nélküle a régi jelszóval belépő vásárló magyarázatot és
  új jelszó linket kap.
- **Munkatársi fiókok:** szándékosan kimaradnak – az új boltban kell létrehozni őket.
- **Régi bővítmények saját adatai** (pl. Booking Calendar foglalásai, WPCode kódrészletek): külön kell átnézni.
