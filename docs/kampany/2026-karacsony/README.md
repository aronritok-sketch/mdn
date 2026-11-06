# Mandala – karácsonyi kampány 2026

Kiskereskedelmi kampány a webshopra és a bemutatóterembe, nov. 13. – dec. 24. A tervet Áron küldi ki, és a csapat jóváhagyására vár.

- `Mandala-karacsonyi-kampanyterv-2026.pdf` – a kiküldhető terv (17 oldal): ajánlatok, ütemterv, csatornák, keret, célok, kreatívok, szövegek, teendők.
- `szovegek.md` – minden szöveg másolható formában: felső sáv, banner, kosár-üzenetek, Meta, Google, hírlevél, posztok.
- `kepek/` – hirdetési képek fázisonként (1–6) öt méretben, és két A4-es plakát (JPG). Nyomtatható plakát: `plakat-A4-bemutatoterem.pdf`.
- `forras/` – az újragyártáshoz kell:
  - `gen.py` – AI-jelenetek a termékfotókból, a Hirdetéskezelő `mb_image` lépésével (Ads 0.15.7+). A kész jelenetek a `forras/ai/` mappában vannak.
  - `ads.py` – hirdetés-HTML méretenként.
  - `strategy.py` – a PDF.
  - `texts.py` – minden szöveg, hossz-ellenőrzéssel: `python3 texts.py`.
  - `deck.py` – a `szovegek.md`.
  - `render.cjs` – Playwright, PNG / PDF.
  - A szkriptek ezt a mappaszerkezetet várják: `build/` = `forras/`, mellette `ai/` és `out/`.

A szakaszok és az ajánlatok:
1. **Füstölő-hét** (nov. 13–22.): egyet fizetsz, kettőt viszel, minden füstölőre.
2. **Black Week** (nov. 23–30.): szinte mindenre −20%, a hangtálakra −10%. Black Fridayn ingyenes szállítás.
3. **Mikulás** (dec. 1–6., határidő dec. 3. 12:00): díszcsomagolás és kártya ajándékba, 15 000 Ft felett ajándék füstölő.
4. **Karácsony** (dec. 7–18. 12:00, utána utalvány dec. 24-ig): ajándék, határidő, utalvány.

Decemberben nincs százalékos akció. Ennek oka az árszabály: a Black Friday-ár 30 napig viszonyítási ár marad.

**A webshopban még meg kell csinálni (jóváhagyás után):**
- 1+1 szabály a füstölőkre.
- Black Week kedvezmény: 20 / 10%, kategóriánként.
- Black Friday: ingyenes szállítás aznap.
- Ingyenes díszcsomagolás.
- Ajándékutalvány és ajándékcsomag élesítése.

A kampány-sávot és a bannert a téma meglévő funkciója (`campaigns.php`) adja.
