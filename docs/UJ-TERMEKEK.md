# Új termékek és kategória-migráció – munkafolyamat

A webért felelős munkatársnak. Hol: **WordPress admin → Termékek → Új termékek**.

## 1. Új termék érkezik a JUTA-ból

1. A JUTA felveszi a terméket a nevével, cikkszámával, árával és készletével. A webshopban **még nem látszik**
   (piszkozat), és bekerül az **„Új, élesítésre vár”** fülre.
2. 15 percen belül összesítő e-mail jön az új termékekről (a címzettek a *Beállítások* fülön állíthatók).
   A menüben a „Új termékek” mellett a darabszám látszik; ha valami 2 napnál régebben vár, reggel emlékeztető jön.
3. Ha a Claude be van kapcsolva, pár percen belül javaslatot ad a kategóriára és a szűrőadatokra. Ha biztos
   benne, ezeket be is írja (a termék ettől még nem lesz élő); ha nem, a sorban „Claude: … · 62%” jelzés és a
   termék szerkesztőjében a javaslat látszik – egy kattintással alkalmazható, utána érdemes ellenőrizni.
4. A sorban minden terméknél látszik, mi hiányzik még (piros: kötelező, szürke: ajánlott):
   - **Kategória** (fő- és alkategória),
   - **Fő termékkép**,
   - **Leírás** (alapból legalább 150 karakter),
   - **Ár** és **cikkszám** (a JUTA-ból jön),
   - **Szűrőadatok** – mindenhol: szándék, eredet; hangtálnál: hang, frekvencia, súly, csakra, készítés;
     füstölőnél: illat, típus; ruházatnál: méret, szín,
   - ajánlott: további képek, rövid leírás, hangtálnál hangminta.
5. **Szerkesztés** → pótold a hiányzókat (a szerkesztő jobb oldalán az ellenőrzőlista is látszik) → **Élesítés**
   a sorban, vagy **Közzététel** a szerkesztőben. Hiányos terméket a rendszer nem enged élesre: piszkozat marad,
   és kiírja, mi hiányzik.

A JUTA ár- és készletfrissítése nem változtat ezen: a még nem élesített termék akkor is piszkozat marad.

## 2. Meglévő termékek átsorolása az új szűrőkre (egyszeri migráció, Claude)

Hol: **Új termékek → Claude migráció** fül. Előfeltétel: Anthropic API-kulcs (a fejlesztő a `wp-config.php`-ba
teszi: `define('MANDALA_ANTHROPIC_API_KEY', '…');`).

1. **Először tesztszerveren**, friss adatbázis-mentés után.
2. **Próbafuttatás** 20–50 termékkel: semmit nem ír át, csak megmutatja, mit javasolna a Claude (régi kategória →
   új kategória, szűrőértékek, megbízhatóság, döntés), és becslést ad a teljes futtatásra (tokenek, idő; ha az
   egységárakat megadod, költség is).
3. Nézd át a próba eredményét. Ha sok a jó javaslat „ellenőrizendő”, a küszöb lejjebb vehető (pl. 0,8); ha hibás
   javaslat lett „automatikus”, feljebb. A modell is váltható (az alap a legpontosabb).
4. **Teljes migráció indítása**: a termékeket kötegenként, a háttérben dolgozza fel (az oldal 10 mp-enként
   frissül, a futtatás leállítható). Ahol a Claude biztos, és minden kötelező szűrő megvan, a javaslat érvénybe
   lép; ahol nem, a termék az **„Élő, ellenőrizendő”** fülre kerül – **élő marad**, csak az adatait kell átnézni.
5. Az „Élő, ellenőrizendő” fülön: a termék szerkesztőjében a javaslat → *Javaslat alkalmazása* vagy kézi
   javítás → a sorban **Kész**. Több terméknél egyszerre: jelöld be őket → *Claude javaslat alkalmazása* vagy
   *Késznek jelöl*.
6. Ha valami rosszul sült el: a futtatás sorában **Visszavonás** – minden, amit az a futtatás módosított, visszaáll.

A régi kategóriák a migráció után is a termékeken maradnak, hogy a régi linkek működjenek. Ha az új kategóriafa
bevált, a régieket a fejlesztő bontja le, átirányításokkal.

## Mit kap meg a Claude?

Csak termékadatot: név, cikkszám, ár, régi kategória és tulajdonságok, címkék, rövid és hosszú leírás, súly.
Vásárlói vagy rendelési adatot nem. A javaslatból csak a meglévő kategóriák és szűrőértékek kerülhetnek be
(a nyitott listás szűrőknél – illat, típus, anyag, szín – új érték is, ez a beállításoknál kikapcsolható).
