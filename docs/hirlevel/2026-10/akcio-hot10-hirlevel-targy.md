# Vásárlói hírlevél #3 (2026. október vége): hot10 – tárgysor, előfejléc, küldés előtt

Téma: hónap végi kedvezmény. A **hot10** kóddal 10% jár mindenre, kivéve a hangtálakat, 2026. október 31-én (szombaton) éjfélig, csak a webshopban.

A levél a Mandala hírlevelére feliratkozottaknak megy MailerLite-ból, ugyanannak a listának, mint az október 18-i és a 20-i.
- HTML: `akcio-hot10-hirlevel.html` (63 KB; a Gmail 102 KB fölött vágja le a levelet).
- Szöveges változat: `akcio-hot10-hirlevel.txt`, ugyanazokkal a linkekkel.
- Kampánycímke: `utm_campaign=2026-10-hot10`.
- A bolti linkek végén ott a `?kupon=hot10` is: a téma (`campaigns.php`) ettől magától a kosárba teszi a kódot – de csak akkor, ha a kupon a kattintás pillanatában már létezik. **A kupont a küldés előtt hozd létre és tedd közzé.**

| | Tárgy | Előfejléc (preheader) |
|---|---|---|
| **A** | hot10: 10% mindenre, ami nem hangtál | Füstölők, illóolajok, szobrok, malák, sálak és teák – a kód október 31-ig, szombat éjfélig él. |
| **B** | Október végéig 10% kedvezmény a hot10 kóddal | A hangtálakra nem érvényes. A levélből átkattintva a kód magától a kosárba kerül. |
| **C** | Egy kód a hónap végére: hot10 | 10% kedvezmény füstölőkre, illóolajokra, szobrokra, malákra, sálakra és teákra – szombat éjfélig. |

**Ezt javaslom: A.** Benne van a kód, a kedvezmény mértéke és a kivétel is, és ugyanazt mondja, mint a levél főcíme. A „mindenre” csak akkor igaz, ha a hangtálakon kívül semmit nem zársz ki a kuponból (sem az akciós termékeket, sem az ütőket, párnákat; lásd lent). Ha bármelyiket kizárod, a **B**-t használd – a generátor ilyenkor a főcímet is „10% kedvezmény, szinte mindenre”-re írja át.

**A/B teszt:** az A-t mérd a B-vel. A győztest a kattintások alapján válaszd. A várakozás legyen rövid (2–4 óra), mert a kód csak szombatig él. Az előfejléc mindkét változatnál az A-é marad, mert ez van beégetve a HTML-be.

**Ha a B vagy a C előfejlécét használod:** írd át a HTML elején a rejtett `<div>` szövegét. A MailerLite automatikus előfejlécét kapcsold ki, különben kétszer jelenik meg.

A tárgysorokban nincs felkiáltójel, csupa nagybetű, „ingyenes” vagy „akció”. A hosszuk 36, 44 és 29 karakter.

**Küldés:** minél előbb, legjobb kedden (okt. 27.) délelőtt, hogy maradjon idő a vásárlásra. Emlékeztető levél ne menjen; ha kell egy utolsó lökés, arra a webshop kampánysávja való (lásd lent).

## Küldés előtt

### 1. Gyakoriság – döntés kell (Áron vagy Laci)

A feliratkozó-doboz ezt ígéri: „Csendes levelek, havonta kétszer”. Októberben már ment kettő (okt. 18. és okt. 20.), és a keddi levél jegyzete szerint októberben több nem megy. Ez lenne a harmadik.

A levél nem hivatkozik a gyakoriságra, el lehet küldeni. A döntés a tiétek: kivételesen kimehet, vagy a novemberi keretből számoljuk.

### 2. A WooCommerce kupon (Marketing → Kuponok) – ezt nézd meg, mielőtt a levél kimegy

A kupont nem tudtuk élesben kipróbálni: a próba munkamenetet írt volna az élő oldalra.

**Általános fül**

| Mező | Érték |
|---|---|
| Kuponkód | `hot10`. A WooCommerce kisbetűsen tárolja, ezért a HOT10 és a hot10 ugyanaz. A pénztárban „HOT10”-ként jelenik meg. |
| Kedvezmény típusa | Százalékos kedvezmény |
| Kupon összege | 10 |
| Ingyenes szállítás engedélyezése | ne legyen bepipálva. A 25 000 Ft-os határ a témában külön működik, és a kedvezmény utáni összeget nézi. |
| Kupon lejárati dátuma | **2026-11-01**. A WooCommerce a megadott nap 00:00-kor zárja le a kupont. Ha 2026-10-31-et írsz be, a kód már péntek éjfélkor megszűnik, holott a levél szombat éjfélt ígér. Az időzóna legyen Budapest (Beállítások → Általános). |

**Használati korlátozás fül**

| Mező | Érték |
|---|---|
| Minimális / maximális költés | üres (a levél nem ír minimumot) |
| Egyedi használat | **Döntés.** A levél azt írja: „Más kuponnal együtt nem használható”. Ezt javaslom, hogy ne adódjon össze az üdvözlő UDV-kuponnal vagy a partnerkuponnal. Ha nem pipálod be, a `gen_hot10.py`-ban állítsd `INDIVIDUAL_USE = False`-ra, és generáld újra a levelet. |
| Akciós termékek kizárása | **Döntés.** A Store API szerint most 19 termék „akciós”, egyik sem hangtál: 17 valódi leárazás, és 2 teáskészlet (2264, 2266), amelynél az „akciós” ár (8 540 Ft) magasabb a rendes árnál (6 860 Ft) – ez adathiba, lásd lent. A levél most úgy szól, hogy nincs kizárva („mindenre, ami nem hangtál”). Ha bepipálod: `EXCLUDE_SALE = True`, generáld újra a levelet, és a tárgy a **B** legyen. |
| Termékek / Kizárt termékek | üres |
| Termékkategóriák | üres |
| Kizárt kategóriák | **Hangtálak** (id 52). A szülő kizárásával a WooCommerce az alkategóriákat is kizárja (Full Moon, Mintás, Mandalás healing, Gépi, Antik, Sun). Egy Full Moon tálon ellenőrizd. |
| Engedélyezett e-mailek | üres |

**Használati korlátok fül**

- Kupononként és felhasználónként: üres (javaslat). A kód közös, nem egyszer használatos.

**További döntések**

- **Hangtál-kiegészítők – döntés.**
  - Ha csak a Hangtálak kategória van kizárva, a párnákra, ütőkre és alátétekre jár a 10%. A levél erről hallgat, és egyik sincs a kiemelt termékek között.
  - Egy kivétel van: a „Piros hangtál, gong ütő M” (id 4346) a Hangtálak kategóriában is benne van, ezért arra nem jár a kedvezmény.
  - Ha a kiegészítőkre se járjon: vedd fel a kizárt kategóriák közé a Hangtál & gong ütők és a Hangtál alátétek kategóriát (a párnák is ebben vannak), állítsd `EXCLUDE_ACCESSORIES = True`-ra, és generáld újra a levelet. Ilyenkor a „mindenre, ami nem hangtál” már nem igaz: a generátor átírja a főcímet, a tárgy a **B** legyen.
- **Viszonteladók – döntés.**
  - A WooCommerce szerepkör szerint nem tud kupont tiltani.
  - A téma sem tud (a `b2b.php` nem nézi a kupont).
  - Így viszonteladói fiókkal a nagyker árból is lejön a 10%. Ha ez nem járhat nekik, küldés előtt a témában kell módosítani. Ha belefér, maradhat így.

### 3. Próba saját böngészőben (rendelést nem kell leadni)

1. Nyisd meg ezt: `https://mandala.hu/termekek/?kupon=hot10`. Tegyél a kosárba egy füstölőt és egy hangtálat. A pénztárban ennek kell állnia: „Beváltva: HOT10”. A „Kedvezmény” sor csak a füstölő 10%-a legyen.
2. A levél pénztári példájával ugyanez: Ayurvedic 7 csakra szett + piros pashmina sál + mintás cipzáras pulóver (a webshopban „Cipzáras Mintás kabát”).
   - Részösszeg: 34 050 Ft.
   - Kedvezmény: −3 405 Ft.
   - Szállítás: Ingyenes.
   - Fizetendő: 30 645 Ft.
   - Ha bármelyik sor 1 Ft-tal eltér (ÁFA-kerekítés), szólj, és a példát a valódi pénztárhoz igazítjuk.
3. **Csak hangtál a kosárban, a linkről érkezve.**
   - A téma kódja alapján itt gond lehet: a kuponlinkes automatikus beváltás a WooCommerce hibaüzenetét nem nyomja el, ezért minden oldalbetöltésnél jöhet egy „nem alkalmazható” üzenet.
   - Ugyanez jöhet a lejárat után: aki a levélből kattintott, annak a munkamenetében (vendégnél kb. 2 napig) megmarad a kód, és november 1–2-án minden kosár-frissítésnél „lejárt kupon” üzenetet kaphat.
   - Ha jön, két út van. Vagy a témában javítjuk (a link-kupon beváltása csak akkor fusson, ha a kupon érvényes a kosárra, és hiba esetén törölje a munkamenetből), vagy a `gen_hot10.py`-ban `LINK_COUPON = False`, és a linkek kupon nélkül mennek. Ilyenkor a kódot be kell írni, a levél szövege ehhez igazodik.
4. Próbáld ki a kódot kézzel beírva is, kisbetűvel, kosárlink nélkül.

### 4. A webshop saját levelei

A jelszó-visszaállító levelek nem mentek ki (`docs/FOLYTATAS-PROMPT.md`, SMTP-gyanú). Egy akciós levél után sok rendelés jöhet, ezért előbb nézd meg, hogy kimegy-e a rendelés-visszaigazoló és a személyes átvételnél az „összekészítettük” értesítő. A levél ígéri, hogy „e-mailben szólunk, amikor összekészítettük”.

### 5. Kampánysáv a webshopban (nem kötelező)

A WooCommerce → Kampányok alatt beállítható egy felső sáv visszaszámlálóval.
- Kupon: hot10.
- Beváltás: csak linkkel, vagy automatikusan minden kosárra. Az automatikus mindenkinek odaadja, ez döntés kérdése.
- Vége: 2026-10-31 23:59.

Ez a hét végén emlékeztet, és nem kell hozzá újabb levél.

### 6. Kedd reggel nézd meg a készletet és az árakat

A lenti adatok 2026-10-26-iak, 10:25-kor (budapesti idő) újra ellenőrizve: ár, készlet, akció változatlan. Egyik termék sem akciós, az árak mind 10-zel oszthatók, ezért a kóddal kapott ár kerekítés nélkül pontos.

Szűk a készlet a mintás pulóverből (10 db) és a gyömbérteából (10 db). Ha valamelyik elfogy, cseréld a `gen_hot10.py` `GROUPS` listájában az alábbi tartalékok egyikére. Ha a pulóver fogy el, a `RECEIPT_IDS` pénztári példát is igazítsd: a végösszeg maradjon 25 000 Ft felett.

| ID | Termék (slug) | Ár | Kóddal | Készlet |
|---|---|---|---|---|
| 1068 | `ayurvedic-7chakra-fustolo-szett` | 9 210 Ft | 8 289 Ft | 61 |
| 6589 | `palo-santo-fa-100g` | 6 350 Ft | 5 715 Ft | 98 |
| 1054 | `satya-sandalwood-olaj` | 3 900 Ft | 3 510 Ft | 217 |
| 4514 | `nepali-gyomber-ginger-tea` | 5 310 Ft | 4 779 Ft | 10 |
| 1895 | `gyogyito-buddha-szobor-m` | 6 100 Ft | 5 490 Ft | 50 |
| 1761 | `buddha-mecsestarto` | 6 990 Ft | 6 291 Ft | 85 |
| 1444 | `rozsakvarc-karmala` | 2 920 Ft | 2 628 Ft | 60 |
| 3276 | `csakras-medal-rez` | 6 600 Ft | 5 940 Ft | 67 |
| 6057 | `kashmir-piros-viragos-sal` | 12 440 Ft | 11 196 Ft | 19 |
| 6949 | `cipzaras-mintas-kabat` | 12 400 Ft | 11 160 Ft | 10 |

Tartalékok (raktáron, nem akciósak, a kép 200-at ad):
- Fehér zsálya tisztító köteg (1819): 4 580 → 4 122 Ft.
- Chenrezig nepáli füstölő (1125): 3 050 → 2 745 Ft.
- Tibeti fa mala (4718): 8 700 → 7 830 Ft, 11 db.
- Ganésha mécsestartó (2885): 12 450 → 11 205 Ft.
- Elefántos párologtató (1381): 4 070 → 3 663 Ft.
- Meditációs párna, világoskék (2652): 12 070 → 10 863 Ft.

Gyors ellenőrzés: `curl -s "https://mandala.hu/?rest_route=/wc/store/v1/products&include=1068,6589,1054,4514,1895,1761,1444,3276,6057,6949"`. Az ár a `prices.price`, a készlet a `stock_availability.text`.

### 7. Amit a levél szándékosan nem ír

- **Nyitvatartás.** A Kapcsolat oldal és a lábléc szerint H–P 9–17, az ÁSZF nagyker része szerint K–P csak 15-ig. A levél a Kapcsolat oldalra visz.
- **Bolti vásárlás.** A levél csak a webshopot ígéri (a személyes átvétel webshoprendelés, arra jár a kód). Ha a Bakáts utcai boltban is adjátok a 10%-ot, szóljatok, és beírjuk.
- **Ajándékutalvány.** Élő utalvány-termék nincs (a Store API keresése 0 találat; az `/ajandekcsomag/` oldal „Utalványt választok” gombja a teljes kínálatra visz, mert `MND-UTALVANY` cikkszámú termék nincs), ezért nem említjük, és kizárni sem kell. A pénztár kuponsorának felirata „Kuponkód vagy ajándékutalvány” – a levél ezt csak szó szerint idézi. Ha az akció alatt mégis felkerül az utalvány-termék, vedd fel a kupon Kizárt termékei közé, különben 10%-kal olcsóbban vehető utalvány.
- **Áthúzott ár.** Nincs a levélben. A termékek a mai árat és a kóddal fizetendőt mutatják, mert a kedvezmény a kosárban jön, a termék ára nem változik.
- **Új termékek.** Október közepe óta nem került új termék a webshopba, ezért a levél nem ír újdonságról.

### 8. Technika

- **Outlook (Windows, asztali gép):** a termékképek WebP-k, a régi Outlook ezeket nem mutatja. Ott az alt-szöveg és a homokszínű háttér látszik, ahogy a korábbi októberi leveleknél is.
- **Próba a küldés előtt:** küldj tesztlevelet magadnak a MailerLite-ból, és nézd meg telefonon is.
- **Gmail:** a MailerLite követő linkjeivel a HTML pár KB-tal nő, így is jóval 102 KB alatt marad.

### Javítanivaló az oldalon (a levél nem függ tőle)

- „Kashmír piros virágos sál”: a rövid leírás szerint pashmina. A levélben ezért „Piros virágos pashmina sál” áll.
- „Satya: Sandalwood olaj”: az Illóolajok kategóriában van, de a címkéjén Fragrance Oil áll. A levélben ezért „illatolaj” szerepel.
- „Cipzáras Mintás kabát”: a leírás és a kategória (Kapucnis pulóverek) szerint kapucnis pulóver. A levélben ezért „Mintás cipzáras pulóver” áll.
- „Rózsakvarc karmala”: a leírásban „Szemek száma: 22, 8mm”, a tulajdonságoknál „Méret: 108 szem”, a szövegben „klasszikus 108 szemes mala”. A levél a 22 szemet írja (karmala, csuklóra).
- „Tradicionális kerámia teáskészlet” (2266) és „Kerámia teáskészlet – hullámos” (2264): az „akciós” ár 8 540 Ft, a rendes 6 860 Ft, a vásárló most a magasabbat fizeti. Valószínűleg fordítva van rögzítve – nézzétek meg, melyik a jó ár.
