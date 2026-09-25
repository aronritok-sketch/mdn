# Mandala – tevékenység- és oldalelemzés

Forrás: a jelenlegi `mandala.hu` kezdőlap mentett HTML-je (WordPress 7 + WooCommerce 10.7 + Elementor 4 + WPML, 2026. szeptember).

## 1. Mivel foglalkozik a Mandala?

| Terület | Amit az oldalból tudunk |
|---|---|
| **Üzleti modell** | Magyar nyelvű webáruház (WooCommerce) + személyes átvevőhely; külön **viszonteladói (B2B)** ág nagykereskedelmi árakkal (WooCommerce Wholesale Prices bővítmény). |
| **Beszerzés** | Keleti import – elsősorban **India és Nepál**. A jelenlegi oldal ezt sehol nem mondja ki, pedig ez a legerősebb megkülönböztető előny. |
| **Kínálat** | 4 fő kategória, ~100 alkategória: **Szakrális tárgyak** (hangtálak, füstölők, mala, csengők, imazászlók, rituálé eszközök), **Lakberendezés** (Buddha szobrok, mandala képek, szélcsengők, csobogók, meditációs párnák), **Ruházat és kiegészítők** (hárem/pillangó nadrágok, maxi ruhák, sálak, ékszerek), **Ajándéktárgyak** (réz kulacsok, teák, bambusz termékek). |
| **Árszint** | 3 940 Ft (csakrás gyűrű) – 42 672 Ft (7 fémes hangtál). Közepes, ajándékozható sáv. Ingyenes szállítás 25 000 Ft felett. |
| **Tartalom** | Rendszeres, színvonalas magazin (hetente új cikk: hangszerek szerepe, buddhista oltár, Buddha-ábrázolások jelentése). |
| **Közösség** | Hírlevél, Facebook; a vélemények a személyes kiszolgálást és a szakértelmet emelik ki. |
| **Egyéb** | Kétnyelvű (HU/EN), Booking Calendar bővítmény telepítve (időpontfoglalás – pl. hangtálas alkalmak / bemutatóterem). |

### Célcsoportok
1. **Gyakorló** – jógázik, meditál, hangtálazik. Szakmai adatot keres: frekvencia, hangnem, csakra, súly, anyag, eredet.
2. **Otthonteremtő** – hangulatot, nyugalmat keres a lakásba (szobrok, textil, szélcsengő).
3. **Ajándékozó** – „jelentéssel bíró” ajándékot keres, gyakran ár és alkalom alapján.
4. **Viszonteladó** – jógastúdió, ajándékbolt, terapeuta: nagyobb tétel, számla, gyors ügyintézés.

## 2. A jelenlegi oldal problémái

**Szerkezet és navigáció**
- A megamenü ~110 elemből áll, 4 szinten – mobilon gyakorlatilag használhatatlan.
- A kezdőlapon nincs szándék vagy eredet szerinti belépési pont, csak kategória.
- Hiányzik a keresési javaslat, a szűrés eredet, szándék vagy ár szerint.

**Márka és bizalom**
- Az **India–Nepál eredet egyszer sem szerepel**. Nincs „honnan jön, ki készítette” történet.
- Általános, cserélhető szlogenek („Találd meg a harmóniát”, „A tudatos választás”) – nincs konkrétum.
- A „Hamarosan minden a helyére kerül – Termékeinket folyamatosan töltjük” popup befejezetlen benyomást kelt.
- Elírás a hírlevélnél („hírlelvelünkre”).

**Termékadatok**
- Semmitmondó terméknevek: „Gyűrű 41-2”, „Virágos indás sál 6” – nem kereshető, nem ad okot vásárlásra.
- A hangtálak adatai (súly, Hz, hang, csakra) csak a névbe vannak zsúfolva, nem szűrhető attribútumként.

**Technika, akadálymentesség, SEO**
- A címsorok **betűnként külön elemekbe** vannak törve („K a t e g ó r i á k”, „Ú j t e r m é k e i n k”) – a képernyőolvasó betűzi, a Google nem érti.
- Ugyanaz a címsor többször duplikálva szerepel (slider klónok).
- ~1,1 MB HTML a kezdőlapon, 20+ bővítmény szkriptje (Elementor, WPML, Booking, Site Kit, WPCode, Yoast…).

## 3. Az új frontend alapelvei

| Elv | Megvalósítás |
|---|---|
| **Spirituális, de profi** | Meleg papír-tónusú háttér, sáfrány-réz kiemelőszín, szerzetesi bordó, Cormorant Garamond + Inter betűpár (a márka már most is Cormorantot használ). Díszítés csak finom vonalas mandalamotívummal. |
| **Az eredet a főszereplő** | Minden terméknél eredet-címke (Nepál / India + hely), külön „Eredetünk” oldal, útvonal Katmandutól Budapestig. |
| **Szándék szerinti vásárlás** | Elcsendesülés · Otthoni harmónia · Önkifejezés · Figyelmes ajándék – a meglévő szövegből („válassz aszerint, mire van szükséged”) valódi szűrő lett. |
| **Letisztult navigáció** | 4 fő kategória, 2 szintű megamenü, mobilon lenyíló fiók, élő kereső. |
| **Szakmai adatok** | Hangtáloknál súly, frekvencia, hang, csakra, fémösszetétel – táblázatban és szűrőként. |
| **Működő vásárlás** | Kosár (oldalfiók + kosároldal), ingyenes szállításig hátralévő összeg, szállítási és fizetési mód, ellenőrzött pénztár-űrlap. |
| **B2B** | Önálló viszonteladói oldal jelentkezési űrlappal. |
| **Akadálymentes, gyors** | Szemantikus HTML, egész szavas címsorok, billentyűzettel kezelhető menük, látható fókusz, `prefers-reduced-motion`; nincs keretrendszer, nincs build lépés. |

## 4. Nyitott kérdések a tulajdonos felé
- Pontos beszerzési helyszínek (pl. Katmandu / Patan / Moradabad / Jaipur) – az „Eredetünk” oldal szövege ezek pontosítására vár.
- Átvevőhely címe, nyitvatartás, telefonszám, cégadatok (impresszum).
- Tényleges szállítási díjak és szolgáltatók (a mintában becsült értékek).
- Használják-e a Booking Calendart (hangtálas alkalmak)? Ha igen, érdemes „Alkalmak” menüpontot kapnia.
- Termékfotók: az új kártyák 1000×1128-as álló képekre vannak méretezve, ez megegyezik a jelenlegi feltöltésekkel.
