# Partnerlevél, 2026. október vége (személyes vásárlás −10%): tárgy és előnézeti szöveg

A levél tegező, és ugyanúgy szólít meg, mint az októberi partnerlevél („Kedves {$name|default('Partnerünk')}!”, „hívj minket”, „Szeretettel: a Mandala csapata”). A tárgysorokban nincs csupa nagybetű, felkiáltójel, „akció”, „ingyen” vagy „utolsó esély”.

| | Tárgy | Előnézeti szöveg (preheader) |
|---|---|---|
| **A** | Személyes vásárlásnál 10% a nagyker árakból (43 karakter) | Nettó 100 000 Ft felett, november 10-ig, a Bakáts utcában. Előtte hívj: +36 30 892 8385. (88) |
| B | Új áru jött: gyere be, és 10%-ot engedünk (41) | A nagyker árakból, nettó 100 000 Ft felett, november 10-ig. Előtte hívj, és előkészítjük, amit keresel. (103) |
| C | Gyere be a Bakáts utcába: új áru és kedvezmény (46) | 10% a nagyker árakból személyes vásárlásnál, november 10-ig. Hívj: +36 30 892 8385. (83) |

## Melyik menjen

**Az A.** A tárgy maga az ajánlat és a feltétele is: 10%, nagyker ár, személyes vásárlás. A viszonteladó ebből már a postaládában látja, hogy neki szól. A levél fő üzenete és első gombja (a telefonhívás) ugyanerre épül.

- Az októberi partnerlevél tárgyában nem volt százalék. Itt viszont a kedvezmény maga a hír, ezért benne hagytuk. Ha mégis óvatosabbak lennétek, a C-ben nincs szám.
- A „Viszonteladók” csoport várhatóan 200 címnél kisebb. Ekkora csoportnál ne A/B teszteljetek, küldjétek az A-t.
- Az A előnézeti szövege már benne van a `partner-nov-hirlevel.html` elején, egy rejtett `div`-ben. Ha B-t vagy C-t választotok, ott is cseréljétek ki. A MailerLite kampány saját előnézeti szöveg mezőjét hagyjátok üresen, különben két előnézeti szöveg lesz.
- Csak a webshopból szinkronizált „Viszonteladók” csoportnak küldjétek: ebben az van, aki feliratkozott a hírlevélre, és viszonteladói fiókja van. A levél alján ez az indoklás áll. Kézzel importált címet ne tegyetek bele.
- A kedvezmény november 10-ig (kedd) él, ezért a levél minél előbb menjen ki, munkanapon, délelőtt.
- A CRM-es változat a `partner-nov-hirlevel-crm.md`-ben van: ugyanez a tárgy, más felépítés. Ugyanannak a partnernek ne menjen ki mindkettő.

## Küldés előtt: ezeket tisztázzátok Lacival

1. **Mi érkezett pontosan?** A webshop legfrissebb termékei október 2-án kerültek fel (réz szobrok, szerencsekarkötők), azóta nem jött új. A szobrok és a karkötők nagy részét az október 16-i partnerlevél már újdonságként mutatta. A levél ezért nem állítja, hogy az új áru még nincs fent: „Ízelítő a webshopba legutóbb feltett darabokból”, és „hogy mi minden jött még, kérdezd meg telefonon, vagy gyere be”.
   - Ha küldés előtt felkerülnek az új tételek, cseréljük ki rájuk a hat csempét (`gen_partner_nov.py`, `TILES`).
2. **A küszöb.** A levélben ez áll: „ha egy vásárlásod nettó értéke meghaladja a 100 000 Ft-ot”. Tisztázni kell:
   - Pontosan 100 000 Ft-nál jár-e már.
   - A kedvezmény előtti nagyker értéket nézzük-e (a levél ezt sugallja).
   - Egy vásárlás egy számlát jelent-e.
3. **Hangtálak.** Áron nem zárta ki őket, és a levél sem ír róluk, így az olvasó úgy érti, hogy rájuk is jár a kedvezmény. Ha mégsem, ezt bele kell írni.
4. **Webshopos rendelés, személyes átvétellel.** A levél szerint a kedvezmény csak a személyes, bemutatótermi vásárlásra jár. Ha a webshopban leadott és a Bakáts utcában átvett rendelésre is, ezt bele kell írni.
5. **Kedvezményszint vagy 10%.** A levél csak annyit mond, hogy a kettő nem vonható össze. Ha egy partner szintje 10%-nál nagyobb, ő melyiket kapja? Ha az a szabály, hogy mindig a kedvezőbbet, érdemes beírni a feltételek közé.
6. **A pultnál is tudják.** A bolti csapat ismerje a szabályt (nettó 100 000 Ft, a nagyker árból, november 10-ig, nem vonható össze), és tudja, hogyan adja meg a JUTA-ban és a számlán. A webshopban ehhez nincs semmi beállítva.
7. **Nyitvatartás.** A Kapcsolat oldalon és a láblécben H–P 9–17 áll, az ÁSZF „Nagykereskedés adatai” részében viszont hétfőn 9–17, kedden–pénteken 9–15. A levél a viszonteladóknak szólót, az ÁSZF-est adja meg („Hétfőn 9–17, keddtől péntekig 9–15 óráig, hétvégén zárva”): aki ebben az időben jön, mindkét forrás szerint nyitva talál. A bevezetőben ott áll, hogy előtte beszéljük meg telefonon, mikor jön. Ha a nagyker is 17-ig van nyitva, írjuk át; az oldalon is egységesíteni kellene. Ne feledjétek: november 10. kedd, aznap a levél szerint 15 óráig lehet jönni.
8. **Készlet, közvetlenül küldés előtt.** Guru Rinpoche 1, Táncoló Shiva 1, Lakshmi 1, Shiva 3 + 2 darab (2026-10-26). Ha valamelyik elfogyott, cseréljük ki a csempéjét, és nézzük meg az „1–3 darab” mondatot.
9. **Jelszó-visszaállítás.** A webshop saját levelei nem mentek ki (`docs/FOLYTATAS-PROMPT.md`). Ha még mindig nem mennek, az „Elfelejtetted a jelszavad?” link zsákutca. Mellette ezért ott áll, hogy „Ha nem érkezik meg a levél, hívj minket”. Küldés előtt próbáljátok ki egy viszonteladói fiókkal.
10. **E-mail-cím.** A levél az info@mandala.hu címet adja meg, ahogy az októberi is. Az ÁSZF nagykereskedelmi része a mandalanagyker@gmail.com-ot írja. Ha a viszonteladói levelek oda mennek, cseréljük.
