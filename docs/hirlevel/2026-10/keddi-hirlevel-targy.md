# Vásárlói hírlevél #2 (2026. október 20., kedd): tárgysor és előfejléc

Téma: őszi elcsendesülés otthon (esti rituálé: füstölő → tea → hangtál, meleg illatok, előfizetés −10%, választók). A levél a Mandala hírlevelére feliratkozottaknak megy MailerLite-ból, ugyanannak a listának, mint a vasárnapi. A HTML: `keddi-hirlevel.html`, a szöveges változat: `keddi-hirlevel.txt`, a linkek kampánycímkéje: `utm_campaign=2026-10-kedd`.

| | Tárgy | Előfejléc (preheader) |
|---|---|---|
| **A** | Korábban sötétedik – legyen ez a te időd | Esti rituálé három lépésben: egy szál füstölő, fűszeres tea és pár perc a hangtál mellett. |
| **B** | Egy szál füstölő, egy bögre tea, egy hangtál | Esti rituálé a hűvös estékre – és 10% kedvezmény, ha rendszeresen kéred. |
| **C** | Hangolódj rá az őszi estékre | Füstölők, illóolajok, masala tea és egy egyedi hangtál, amelyet meg is hallgathatsz. |

**Ezt javaslom: A.** Ugyanazt mondja, mint a levél főcíme, és nem hasonlít a vasárnapi tárgysorra (ott az új oldal volt a hír).

**A/B teszt:** az A-t mérd a B-vel. Az előfejléc mindkettőnél maradjon az A-é, mert a HTML-ben ez van beégetve. A győztest a kattintások alapján válaszd, ne a megnyitások alapján.

**Ha a B vagy a C előfejlécét használod:** írd át a HTML elején a rejtett `<div>` szövegét. Ha a MailerLite automatikus előfejléce be van kapcsolva, az egyiket kapcsold ki, különben kétszer jelenik meg.

A tárgysorokban nincs felkiáltójel, csupa nagybetű, „ingyenes” vagy „akció” szó. Mindegyik 50 karakternél rövidebb (40 / 44 / 28).

## Küldés előtt

- **Gyakoriság:** a feliratkozó-dobozban ez áll: „Csendes levelek, havonta kétszer”. Ez az októberi második levél, és csak két nappal jön a vasárnapi után. Októberben ne menjen több. Az esti témához a kedd esti küldés illik a legjobban.
- **Kedd reggel nézd meg a készletet és az árakat** (2026-10-18-i adat; a lista lent). Szűk: a hangtál (1 db, egyedi darab), a masala tea (6 db), a zöld hangtálpárna (9 db). A többiből 16 vagy több van.
  - Ha a masala tea elfogy: a `gen_keddi.py`-ban a `STEPS[1]` termékét cseréld a Nepáli gyömbér (ginger) teára (5 310 Ft, előfizetéssel 4 779 Ft, `/termek/nepali-gyomber-ginger-tea/`, kép: `2026/09/9990000024681-600x600.webp`), és a `SUB_EXAMPLES` harmadik sorát is.
  - Ha a hangtál elkel: válassz helyette másik szívcsakrás tálat, amelynek saját fotója és hangmintája van (pl. `s-carving-mintas-hangtal-14-054kg`, 0,54 kg, 380 Hz, F#, 49 380 Ft – előtte nézd meg a termékoldalt). A (13)-as és a (43)-as tálat ne, mert azoknál a kép nem az adott darabot mutatja. Ha a levél kiment, és utána kel el, a termékoldal „Elkelt” jelzéssel a testvérdarabokat mutatja – ezt ígéri a levél is.
  - Ha a zöld párna elfogy: a sötétkék (30 db) a harmadik szem csakráé, ezért inkább hagyd ki a „Hozzá” sorból.
- **Outlook (Windows, asztali):** a termékképek WebP-k (a mandala.hu csak így tárolja őket), ezeket a régi Outlook nem jeleníti meg – ott az alt-szöveg és a homokszínű háttér látszik. A vasárnapi levél ugyanígy ment ki.
- **Javítanivaló az oldalon (a levél nem függ tőle):**
  - „Cinammon spice illatviasz” → Cinnamon. A levélben ezért „Fahéj illatviasz” szerepel.
  - „Meditációs párna cédusfával töltve” → cédrusfával.
  - „Lótusvirág füstölőtartó – M” → Lótuszvirág. A levélben „Lótuszvirág füstölőtartó, M” áll.
  - A 372 Hz-es szívcsakra-tál leírásában „A 432 Hz jelentősége” áll, a képe alt-szövegében pedig „F hangon” (a cím szerint F#).
  - A masala tea képének alt-szövege „zacskóban” mondja, a képen fadoboz van.

### Termékek a kedd reggeli ellenőrzéshez (slug · ár · előfizetéssel · készlet 10-18-án)

| Slug | Ár | Előfizetéssel | Készlet |
|---|---|---|---|
| `satya-nag-champa-agarbatti-fustolo` | 630 Ft | 567 Ft | 1373 |
| `nepali-masala-tea` | 5 310 Ft | 4 779 Ft | 6 |
| `nepali-gyomber-ginger-tea` (szövegben link) | 5 310 Ft | 4 779 Ft | 10 |
| `s-carving-hangtal-32-054kg` | 49 380 Ft | – | 1 (egyedi) |
| `hangtal-uto-xl` | 4 200 Ft | – | 44 |
| `csakras-hangtal-parna-zold` | 1 910 Ft | – | 9 |
| `mandala-vanilla-vanilia-fustolo` | 750 Ft | 675 Ft | 687 |
| `lotusvirag-fustolotarto-m` | 1 400 Ft | – | 38 |
| `goloka-cedrus-illoolaj` | 1 190 Ft | 1 071 Ft | 211 |
| `antikolt-keramia-parologtato` | 7 300 Ft | – | 29 |
| `green-tree-dream-spirit-illoolaj` | 1 200 Ft | 1 080 Ft | 310 |
| `cinammon-spice-illatviasz` | 2 030 Ft | – | 16 |
| `meditacios-parna-cedusfaval-toltve` | 17 530 Ft | – | 18 |
| `green-tree-levendula-gyertya-ametiszt-asvanykovekkel` | 4 800 Ft | – | 46 |

Gyors ellenőrzés: `curl -s "https://mandala.hu/?rest_route=/wc/store/v1/products&slug=<slug>"` (ár: `prices.price`, készlet: `stock_availability.text`).
