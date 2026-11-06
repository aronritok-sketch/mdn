# Mandala – „Nyitott raktár” nagyker kampány 2026

Viszonteladói kampány a bemutatóterembe, 2026. november 11. – december 18. (javaslat).

**A cél:**
- a régi, elmaradt partnerek visszahozása;
- új viszonteladók szerzése.

**Az ajánlat (Áron, 2026-11-06):**
- A készlet 80%-a 10, 30 vagy 50%-kal olcsóbb a nagyker árból.
- Érdeklődni személyesen vagy telefonon lehet.
- A kedvezmény csak személyes vásárlásnál jár. Webes rendelésre nem érvényes.

**A kampány jele a színes pötty a terméken vagy a polcon:**
- zöld: −10%;
- sárga: −30%;
- piros: −50%.

A színek az imazászló színei.

A tervet Áron küldi ki; a csapat jóváhagyására vár.

## Mi hol van

| Fájl | Mire |
|---|---|
| `Mandala-nagyker-kampanyterv-2026.pdf` | A kiküldhető terv, 18 oldal. Benne: ajánlat és pötty-rendszer, célcsoportok, ütemterv, csatornák, bemutatóterem, keret és célok, vizuális irány, kreatívok, szövegek, teendők, döntések. |
| `Mandala-partnerajanlat-nyitott-raktar.pdf` | Kétoldalas partnerlap. A partnereknek szól: a hívás utáni levél melléklete, és nyomtatva a bemutatóterembe. Benne: ajánlat, „így működik”, árrés-táblázat, feltételek, készlet, jelentkezés QR-kóddal. |
| `szovegek.md` | Minden szöveg másolható formában. A két telefonos forgatókönyv a Partner CRM formátumában (JSON-ban is). A hívólisták. A hívás utáni levelek, az SMS. A partnerlevél (MailerLite, és a Partner CRM Hírlevél-adatcsomagja). Az emlékeztető, a levélsor az új partnereknek. A Google-hirdetés, a posztok, a Cégprofil, a story. A plakát, a polccímke és a pultkártya szövege. |
| `levelek/` | A partnerlevél (nov. 11.) kész HTML-je és szöveges változata, előnézet, fejléckép (`mandala-nyitott-raktar-1200x600.jpg`). |
| `kepek/` | 20 hirdetési kép, 4 üzenet × 5 méret (részletek lent). Mellette a levélfejléc, és a plakát, a polccímkék és a pultkártya előnézete. |
| `nyomtatni/` | Plakát (A4). Polccímkék (4 × A6 egy A4-en). Pötty-ív (3 oldal 40 mm-es, 1 oldal 20 mm-es pötty). Pultkártya (A5). |
| `forras/` | Az újragyártáshoz (részletek lent). |

**A 20 hirdetési kép:**
- Üzenetek:
  1. Nyitott raktár.
  2. Rég jártál nálunk.
  3. Van egy polcod? Nekünk raktárunk.
  4. Még belefér a karácsonyba.
- Méretek: 4:5, 9:16, 1:1, 1200 × 628, 1200 × 600.

**A `forras/` mappa:**
- `texts.py`: minden szöveg, a hosszak ellenőrzésével.
- `gen.py`: AI-jelenetek a valódi termékfotókból (Hirdetéskezelő `mb_image`, ~0,19 USD/kép).
- `ads.py`: a hirdetések.
- `prints.py`: a nyomtatnivalók és a partnerlap.
- `mail.py` + `_mailbase.py`: a partnerlevél.
- `strategy.py`: a terv.
- `deck.py`: a `szovegek.md`.
- `render.cjs`: Playwright, JPG / PDF.

A mappaszerkezet: `build/` = `forras/`, mellette `ai/`, `out/`, és `pv/`. A `pv/` a partnerlap oldalképeihez kell, `pdftoppm`-mel készül.

## Döntés kell (nov. 9-ig)

A dátum, a félretétel (3 munkanap), a kedvezményszint és a minimum javaslat. Ha változik, a `forras/texts.py`-ban kell átírni, és minden újragyártható. A döntendő kérdések:
1. **Dátumok:** nov. 11. – dec. 18. A mostani −10%-os személyes akció nov. 10-ig fut.
2. **Mi kap pöttyöt:** a Partner CRM Készlet nézete alapján.
   - piros: „nem fogy”;
   - sárga: lassan fogy;
   - zöld: a többi;
   - pötty nélkül: az új érkezés és a „fogy” lista.
3. **Hangtálak:** kapnak-e pöttyöt.
4. **Kedvezményszint:** nem adódik össze, a kedvezőbb jár.
5. **Minimum:** nincs külön minimum.
6. **Félretétel:** 3 munkanap.
7. **Vidéki partner:** csak személyesen (a cége munkatársa is jöhet), futárral nem jár.
8. **Új partner:** a helyszíni, adószámos jelentkezéssel azonnal megkapja.

## Jóváhagyás után kell

**Mandala:**
- Pöttyözés.
- Nyomtatás.
- Pult-eligazítás. A számla-megjegyzés: „NYITOTT RAKTÁR”.

**HelloProVision:**
- **Partner CRM:**
  - a hívólisták és a két forgatókönyv;
  - a hívás utáni levelek;
  - a partnerlevél (MailerLite vagy CRM, próbalevél, nov. 11. 9:00).
- **Téma-kiadás nov. 10-ig:**
  - Benne a levél fejléce. A kép már a témában van: `wp-theme/mandala/assets/ads/mandala-nyitott-raktar-1200x600.jpg`.
  - Kampánysáv csak a Viszonteladóknak oldalon és a viszonteladói felületen. A nyilvános felső sávba nem kerül, mert a vásárló nem kaphatja meg.
- **Google-keresés:** nagyker szavakra, napi 3 000 Ft, hívás-bővítménnyel.
- **Heti riport.**

A fizetett Meta-hirdetést nem javasoljuk: a közönség vásárló, és ő nem kaphatja meg a kedvezményt.
