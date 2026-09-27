# Terheléses teszt – eredmények és tanulságok

Mit csinál a bolt, ha egyszerre sokan böngésznek és vásárolnak (kampány, akció, hírlevél)? A mérés
éles méretű adatokon készült, a hibákat javítottuk, utána újramértünk.

## Környezet

| | |
|---|---|
| Szerver | 4 vCPU, PHP 8.4 (OPcache, 8 PHP folyamat – egy közepes VPS / tárhely megfelelője), MariaDB 10.11 |
| Adatok | **3034 termék** (képekkel, attribútumokkal), **5000 korábbi rendelés** (18 hónap), WooCommerce 11 |
| Gyorsítótár | **nincs** oldal- és objektum-gyorsítótár – a legrosszabb eset: minden kérés PHP-n megy át |
| Forgalom | [`tests/load.mjs`](../tests/load.mjs): valószerű látogatók (főoldal → kategória → szűrő → termékek → keresés → kosár), folyamatosan vásárlók (kosár → pénztár → utánvétes rendelés → készletcsökkenés), keresőrobotok. Gondolkodási idő nélkül – egy virtuális látogató kb. 5–10 valódi látogatónak felel meg. |

## Eredmény

20 látogató + 2 vásárló + 2 robot, 90 másodperc:

| | Előtte | Utána |
|---|---:|---:|
| Kiszolgált kérés / mp | 1,7 | **30,5** |
| Hibás kérés (30 mp-es időtúllépés) | **23 %** | **0 %** |
| Válaszidő – medián | 1,2 mp | 0,6 mp |
| Válaszidő – p95 | 30 mp (időtúllépés) | 1,2 mp |
| Leadott rendelés | 3 | 59 |

Vásárlási roham (30 látogató + 10 egyszerre vásárló, 60 mp): **111 rendelés egy perc alatt**
(~6600 / óra), 0,1 % hiba (egy kiürült kosár), nincs adatbázis-holtpont, a készlet a keresőben
magától követte az eladásokat. 40 látogatónál a 4 mag telítődik (~31 kérés/mp), a kérések sorba
állnak, de hiba nincs – efölött oldal-gyorsítótár kell (lásd lent).

## Amit a teszt kiderített, és a javítás

| # | Probléma | Hatás | Javítás |
|---|---|---|---|
| 1 | **A termékindex minden eladás (készletváltozás) után teljesen törlődött**, és az első látogató kérésében épült újra: 3000 terméknél 29 mp és 54 595 adatbázis-lekérdezés – egyszerre több folyamatban is („csorda”). | Minden rendelés után ~30 mp-re megállt a bolt; élesben (30 mp-es PHP korlát) az index soha nem épült volna fel. | Termékenkénti frissítés: az eladás csak a termék sorát jelöli, a háttérfeladat pár mp múlva csak azt számolja újra; addig a korábbi index szolgál ki. Adatbázis-zár az első építésre. Az építés előtöltött gyorsítótárral: **5,7 mp, 3162 lekérdezés**. |
| 2 | Az index tranziensben volt (3,9 MB). | Memcached objektum-gyorsítótárnál (1 MB-os korlát) minden kérés újraépítette volna. | Opcióban tárolva (nem jár le, adatbázis a tartalék), naponta teljes újraépítés az „új” / „érkezik” jelölés miatt. |
| 3 | **A kártyák „Kosárba” linkje annak az oldalnak a címét kapta, ahol az index épp épült** (pl. egy másik termékoldal). | JavaScript nélkül / hibánál rossz oldalra vitt. | A link a termék saját címére mutat. |
| 4 | A kereső és a szűrő a 3,3 MB-os termékindexet minden látogatónak PHP-ból adta ki. | Kérésenként 47 MB memória és ~120 ms CPU. | Statikus JSON fájl verzióval (`uploads/mandala-index/`) – a webszerver szolgálja ki, PHP nélkül. Viszonteladónak továbbra is a REST (nagyker árak). |
| 5 | A termékoldal az ajánlóhoz a teljes indexet betöltötte. | 40 MB memória termékoldalanként. | Karcsú, külön tárolt ajánló-készlet: **14 MB**. |
| 6 | A találati oldal minden keresésnél 3000 terméket szótövezett újra. | ~300 ms keresésenként. | Előre kiszámolt szótövek az index változatához: **30–60 ms**; az oldal 500 → ~220 ms. |
| 7 | Termékkártyánként ~8 külön lekérdezés (attribútumok, meta, kategórianév). | Főoldal: 306 lekérdezés. | Csomagos előtöltés + gyorsítótárazott attribútum- és kategórianév-olvasás: **188**. |
| 8 | Minden oldalbetöltés 13 háttérfeladat-ellenőrzést és ~30 külön beállítás-lekérdezést futtatott. | +40 lekérdezés minden oldalon. | Óránként egy ellenőrzés; a beállítások egy lekérdezéssel. Egyszerű oldal: 108 → **~90**. |
| 9 | **Oldal-gyorsítótár mellett a beégetett biztonsági kód (nonce) 12–24 óra után lejár.** | Gyorsítótárazott oldalon elromlott volna a minikosár mennyiség-váltója, az ajándékcsomag-összeállító, az elhagyott kosár rögzítése és a csomagkövetés („nincs ilyen rendelés”). | Érvényes kód **vagy** a saját oldalunkról jövő kérés (Origin / Referer) – a más oldalról indított kérés továbbra is tiltott. |
| 10 | A kedvencek oldal a látogató sütijéből szerveroldalon készül. | Gyorsítótárban az első látogató kedvencei jelentek volna meg mindenkinek. | A kedvencek, a csomagkövetés és az értékelés oldala magától kimarad a gyorsítótárból. |
| 11 | Ajánló (éjszakai): minden rendelés teljes objektumként töltődött be. | 5000 rendelésnél 265 MB – nagyobb forgalomnál memóriahiba. | Közvetlen lekérdezés a tételekre: **0,16 mp, 0,3 MB**. |
| 12 | Heti riport: rendelésenként külön keresés, hogy új-e a vásárló. | 3,2 mp; több tízezer rendelésnél percekig terheli az adatbázist. | Egy összesítő lekérdezés: **0,29 mp**. |
| 13 | Feedek: óránként teljes újragyártás, termékenként 2 lekérdezés, helyben felülírt fájl. | 6 mp CPU óránként; a letöltő félkész fájlt kaphatott. | Előtöltés (4 mp), csak ha változott valami (különben naponta), atomi fájlcsere. |
| 14 | Az ajándékutalvány és a vélemény mentésének hibáját nem kezelte a kód (`wp_insert_post` 0-t ad vissza, nem hibát). | Utalványnál végzetes hiba a fizetés utáni lépésben. | Hibakezelés; az utalványnál rendelési jegyzet és újrapróbálható. |
| 15 | A varázsló 5 percenkénti cront ajánlott. | Eladás után akár 5 percig régi készlet a keresőben. | Percenkénti cron ajánlás (varázsló, wp-config minta, útmutató). |

## Ami a szerveren kell (élesítés előtt)

1. **Oldal-gyorsítótár** (LiteSpeed Cache / WP Super Cache) – ez viszi el a nagy forgalmat.
2. **Valódi cron percenként** (`DISABLE_WP_CRON` + tárhely cron) – a varázsló „Környezet” lépése jelzi.
3. **gzip / brotli az `application/json` típusra** – a kereső indexe 3 MB helyett ~250 KB-ként menjen ki.
4. Ha van: **Redis objektum-gyorsítótár** a kosárhoz és a pénztárhoz.

Részletek: [`TELEPITES.md`](TELEPITES.md) 9. fejezet.

## A teszt megismétlése (csak tesztszerveren!)

```sh
BASE=https://teszt.mandala.hu VUS=20 BUYERS=2 BOTS=2 DURATION=90 node tests/load.mjs
```

Valódi utánvétes rendeléseket ad le és csökkenti a készletet – éles boltban soha. A kimenet
lépésenként mutatja a darabszámot, a hibákat, a p50 / p95 / p99 időket és egy 10 mp-es idősort (ebben
látszanak az esetleges gyorsítótár-újraépítési kiugrások). A tesztszerver (PHP beépített szerver) a
statikus fájlokat lassan adja; a „kereső (index)” sor ideje élesben (nginx / LiteSpeed) ezredmásodperces.
