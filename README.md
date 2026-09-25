# Mandala – új webshop frontend

A [mandala.hu](https://mandala.hu) új, letisztult frontendje. Nepálból és Indiából importált hangtálak, füstölők, szakrális tárgyak, lakberendezési darabok, ruhák és ajándékok webshopja.

Az üzlet és a jelenlegi oldal elemzése: [`docs/ELEMZES.md`](docs/ELEMZES.md).

## Futtatás

Nincs build lépés és nincs függőség. Bármilyen statikus szerver elég, mert az oldal ES modulokat használ (`file://`-ról nem fut):

```bash
python3 -m http.server 8000
# → http://localhost:8000
```

## Oldalak

| Fájl | Tartalom |
|---|---|
| `index.html` | Kezdőlap: hős, szándék szerinti belépés, kategóriák, újdonságok, eredet (Katmandu → Budapest), hangtál-kalauz, kedvencek, vélemények, magazin |
| `termekek.html` | Kínálat: szűrés kategória, alkategória, szándék, eredet és ár szerint; rendezés; keresés (`?q=`). A szűrők az URL-ben is megmaradnak, így megoszthatók |
| `termek.html?p=<slug>` | Termékoldal: eredetkártya, műszaki adatok (pl. hangtálaknál Hz, hang, csakra), használat, szállítás, hasonló termékek, Product JSON-LD |
| `kosar.html` | Kosár és pénztár: szállítási és fizetési mód, ingyenes szállításig hátralévő összeg, űrlap-ellenőrzés, visszaigazolás |
| `rolunk.html` | Eredetünk: Nepál és India, a beszerzés folyamata |
| `viszonteladoknak.html` | B2B jelentkezés (adószám-ellenőrzéssel) |
| `magazin.html`, `cikk.html?a=<slug>` | Magazin és cikkoldal |
| `kapcsolat.html`, `informaciok.html` | Kapcsolat, szállítás, fizetés, visszaküldés, jogi szövegek helye |

Közös elemek minden oldalon: megamenü, mobilmenü, élő kereső (`/` billentyűvel is nyílik), kosárfiók, hírlevél, lábléc.

## Felépítés

```
assets/
  css/styles.css      design tokenek és minden stílus
  img/                optimalizált WebP fotók (800 px és teljes méret)
  js/data.js          konfiguráció, kategóriák, szándékok, mintatermékek, cikkek
  js/store.js         termékbetöltés (minta vagy WooCommerce), kosár (localStorage)
  js/app.js           fejléc, lábléc, kereső, kosárfiók, termékkártya
  js/art.js           vonalas termékillusztrációk (amíg nincs termékfotó)
  js/blocks.js        cikk- és véleménykártya, útvonaltérkép
  js/pages/*.js       oldalankénti logika
```

## Bekötés a meglévő WooCommerce-hez

1. `assets/js/data.js` → `CONFIG.woocommerce = 'https://mandala.hu'`. Ekkor a termékeket a WooCommerce Store API-ból (`/wp-json/wc/store/v1/products`) töltjük be. A kategória-slugok megegyeznek a mostani oldaléval. Az eredetet egy `Eredet` nevű termékattribútumból olvassuk (pl. „Nepál – Patan”). **Élő áruházzal még nem teszteltük.** Ha a frontend más domainen fut, CORS-beállítás is kell.
2. A pénztár jelenleg bemutató: a rendelés helyben „teljesül”. Élesben a `checkout.js` beküldéskor a Store API `/cart` és `/checkout` végpontját kell hívni (a helye jelölve van).
3. A hírlevél-, kapcsolat- és viszonteladói űrlapok kliensoldalon ellenőriznek, de még nem küldenek adatot. A bekötés helye a `forms.js` és az `app.js` (`initNewsletter`).

## Ami még a tulajdonostól kell

- **Termékfotók.** A kártyák 1000×1128-as álló képekre vannak méretezve, ez megegyezik a mostani feltöltésekkel. Amíg nincs fotó, vonalas illusztráció jelenik meg. A `product.image` mezőt kitöltve a fotó automatikusan átveszi a helyét.
- A mintatermékek egy része (pl. Full Moon hangtál, masala chai, ajándékcsomag) és a hozzájuk tartozó adatok csak bemutató célúak.
- Beszerzési helyszínek pontosítása az „Eredetünk” oldalon és a termékeknél.
- Kapcsolati adatok, szállítási díjak, ÁSZF, adatkezelési tájékoztató, impresszum (`CONFIG` és `informaciok.html`). A mostani értékek helykitöltők.
- Angol nyelvű változat (a mostani oldal WPML-lel kétnyelvű).
