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

**Jóváhagyva (Áron, 2026-11-06), a webshopban megcsinálva (téma, `inc/features/promos.php`):**
- A hat kampány (Füstölő-hét, Black Week, Black Friday, Mikulás, Karácsony, utolsó napok) dátumra magától indul és áll le, felső sávval, bannerrel és ajánlattal. Az élesítés utáni első kérésnél a téma egyszer beírja őket (`sitefix.php` → `kampany-karacsony-2026`). Szerkeszteni a WooCommerce → Kampányok → „Ajánlat” részben lehet; ugyanott alul van az áttekintés, hogy hány termék kedvezményes és miért marad ki a többi.
- **1+1 a füstölőkre** (nov. 13. – nov. 30.): a kosárban minden pár olcsóbbik darabja 0 Ft. A kártyákon „1+1” jelvény, a termékoldalon egy mondat, a kosárban „Tegyél mellé még egyet”.
- **Black Week** (nov. 23. – nov. 30.): −20%, a hangtálakra −10%, magában az árban, áthúzott eredeti árral (termékoldal, kártya, kosár, Google / Meta feed). Nem jár: a már akciós termékre, az utalványra, a viszonteladóknak, kuponnal együtt, és arra a termékre, amelyik a kampány előtti 30 napban olcsóbb volt (árcsökkentési szabály; a téma ehhez 30 napos ár-naplót vezet, a korábbi rendelésekből pótolva).
- **Black Friday** (nov. 27.): ingyenes szállítás bármekkora rendelésnél.
- **Díszcsomagolás és kézzel írt kártya ajándékba** (dec. 1. – dec. 24.): a pénztárban kérhető. A rendelésben, az admin rendelés-oldalán és a bolt levelében látszik a kártya szövegével.
- **15 000 Ft felett ajándék füstölő** (dec. 1. – dec. 24.): a Satya Nag Champa (cikkszám 8904234400037) magától a kosárba kerül.
- Az ajándékutalvány már élt. Az ajándékcsomag-összeállítóhoz egy csomagolás-termék kellene: ezt a JUTA miatt nem hoztuk létre (lásd a teendőket).

**Közösségi posztok:** `kozossegi/naptar.md` (15 poszt időponttal, szöveggel, képpel, linkkel; Google Cégprofil-ajánlatok), a lapozós posztok képei: `kozossegi/kepek/`.

**Hirdetések:** `forras/ads_phase.py`. A szakaszhatárokon (nov. 13., 23., 27., 28., dec. 1., dec. 3. 12:00, dec. 18. 12:00, dec. 24. 12:00) a Meta v4 kampány A1 és A3 hirdetése új kreatívot kap, és a napi keretek a terv szerint változnak. Ha a remarketing nem tud költeni, a keretét a v4 kapja.

A kampány-sávot és a bannert a téma meglévő funkciója (`campaigns.php`) adja.
