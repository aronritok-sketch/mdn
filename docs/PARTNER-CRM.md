# Mandala Partner CRM – a webshop oldala

A viszonteladó partnereket a csapat a **Mandala Partner CRM**-ben kezeli: a HelloProVision CRM-jének egy külön,
a Mandalára szabott példánya (javasolt cím: `partner.mandala.hu`). A CRM leírása, a szakaszok, az import és a
telepítés: `aronritok-sketch/hlprv` → `docs/partner/README.md`. Ez a fájl a webshop (a `mandala` téma) oldalát írja le.

## Mit küld a webshop a CRM-nek

| Esemény | Mikor | Honnan |
|---|---|---|
| Viszonteladói jelentkezés | a „Viszonteladóknak” oldal űrlapja (cégnév, adószám, tevékenység, település, weboldal, kapcsolattartó, várható rendelés, érdeklődés) | `iu_form_submit_viszontelado` – azonnal (háttérfeladat) |
| Viszonteladói fiók | regisztráció, szerepváltás (`wholesale_customer`), cím- és adatváltozás | `user_register`, `set_user_role`, `add_user_role`, `profile_update` |
| Rendelés | viszonteladó rendelése: létrejötte és minden állapotváltozása, tételekkel (cikkszám, név, db, nettó, kategória); `offer_token` (ha ajánlat-linkből jött), `payment_method`, `payment_days`, `payment_due` (halasztott átutalásnál) | `woocommerce_checkout_order_processed`, `woocommerce_order_status_changed`; a rendelés `_mandala_wholesale` jelölőt kap |
| Aktivitás | belépés, árlista CSV, termékfotók ZIP, gyorsrendelő, új érkezések levele be (`arrivals`) / ki (`arrivals_off` – a felületen, a levél egy kattintásos leiratkozó linkjével, a CRM `consent: no` parancsával vagy általános leiratkozással; `meta.source`) | `wp_login`, `mandala_b2b_activity`, `mandala_b2b_arrivals_set()` (b2b.php) |
| Ajánlat (`offer_open`, `offer_order`) | `offer_open { token, at, user_id, email }` – az ajánlat-link első érvényes megnyitásakor (tokenenként egyszer, belépés előtt is); `offer_order { token, order_id, number, user_id, email, status, net, gross, at }` – az ajánlatból lett rendelés (az `order` esemény `offer_token` mezője mellett) | `crm-partner.php` |
| Készlet | változás 10 percenként (ujjlenyomat; a JUTA közvetlen DB-írása után is), teljes lista naponta | `wc_product_meta_lookup`, nagyker ár, `_mandala_incoming`, 90 napos eladás |
| Üzenet (`message`) | Fiókom → **Üzenet a Mandalának** (csak viszonteladónak): `{ user_id, email, name, company, subject, body, at }` – a CRM-ben teendő a felelősnek, a válasz e-mailben megy. Ráta-korlát: óránként 5, egymás után 20 mp. Összekötés nélkül levél a bolt címére. | `crm-partner.php` – azonnal (háttérfeladat) |
| Webes forgalom (`web_stats`) | naponta (03:20) az aktuális és az előző hónap, csatornánként: `{ month: 'YYYY-MM', channel: 'egyeni' \| 'kereskedo', orders, net, gross, items, shipping_net, computed_at }` – minden rendelés (teljesített, feldolgozás alatti; a lemondott / visszatérített / függő nem, a visszatérített részösszeg levonva); csatorna: a vásárló viszonteladói szerepe vagy a rendelés jelölője; `net` = termékek + díjak nettója (szállítás és ÁFA nélkül). Az első teljes küldés 13 hónapot küld. | `wc_get_orders` (HPOS), 200-as oldalak, 10 percenként legfeljebb 2 hónap |

**Időpontok:** minden időpont ISO 8601 alakban, zónával megy (`'c'`, pl. `2026-10-16T09:40:00+02:00` – rendelés
`created`, fiók `registered` / `last_active`, aktivitás és üzenet `at`, hozzájárulás, `web_stats.computed_at`,
ajánlat-események), a CRM a saját zónájára váltja (`hpv_pt_parse_shop_time`). A régi, zóna nélküli helyi időt a CRM
2026-10-16 óta a webhely zónájában értelmezi (addig UTC-nek vette – 1–2 órás csúszás).

**E-mail:** a fiók-, a rendelés- és az üzenet-eseményben az `email` a fiók e-mail-címe (`user_email`; a számlázási e-mail
külön `billing_email`-ként megy) – a CRM a webshop-fiókra (`user_id`) párosít, e-mailre csak akkor, ha a kartonon még
nincs fiók.

A **fiók-esemény** (`customer`) a partnerlevél-hozzájárulást is viszi: `marketing_consent` (bool), `marketing_consent_at`
(a módosítás ideje), `marketing_consent_source` (`nagyker` | `jelentkezes` | `crm[:forrás]` | `leiratkozo-link` |
`mailerlite`), `marketing_consent_text` (a pipa pontos szövege a megadáskor), `b2b_arrivals` (bool – kéri-e a heti új
érkezések levelét), `unsubscribed` (bool – minden reklámlevélről leiratkozott), valamint a webshopban érvényes feltételeket (`terms`: `discount_level`, `discount_pct`,
`payment_days`, `blocked`, `block_reason`). A hozzájárulás a „Viszonteladóknak” oldal jelentkezésében egy pipa
(„Kérek partnerleveleket (új érkezések, akciók)” – a téma az űrlapba teszi, az oldal tartalmához nem kell nyúlni; a
jelentkezés-esemény is viszi), a viszonteladói felületen kapcsoló. Ha a jelentkezőnek még nincs fiókja, a fiók
létrejöttekor (jóváhagyás) kerül rá.

Az óránkénti `hello` esemény `features` mezője megmondja, mit tud a téma (`approve,resync,terms,message,web_stats,consent,offer,consent_cmd`)
– a CRM ebből láthatja, hogy a webshopban fent van-e már a 2. hullámos téma (a régi a `terms` parancsra „ismeretlen parancs” nyugtát ad),
illetve a `consent` parancsot értő téma (`consent_cmd`; a régebbi „ismeretlen parancs” nyugtát ad – a CRM ilyenkor várjon / próbálja újra).

A CRM válaszában jöhet parancs: **approve** (a CRM-ben jóváhagyott jelentkező viszonteladói szerepet kap – hozzáadva, a
fiók többi szerepe megmarad, csak a sima `customer` megy le; ha nincs
fiókja, a WooCommerce nyit egyet, és elküldi a fiók-levelet – **ehhez a levélküldésnek működnie kell**, lásd a nyitott
levél-hibát), **terms** (lent), **consent** (lent) és **resync** (teljes újraküldés; `what: 'web_stats'` → csak a webes
forgalom, 13 hónap). Az eredményt a következő kérésben nyugtázza. A parancsok mindig a webshop saját, aláírt kérésére
(`hpv-partner-v1`) adott válaszban jönnek a beállított https CRM-címről – kívülről parancsot küldeni nem lehet, külön
aláírás a parancson nincs (ugyanaz, mint az approve / terms / resync).

### `terms` – partner-feltételek

`{ email, user_id?, discount_level?, discount_pct?, payment_days?, block?: { on: bool, reason?: string } }` – csak a
megadott kulcsok változnak (üres szint / 0 nap = törlés). A fiók: `user_id`, ha az e-mail egyezik, különben az e-mail
szerint; ha nincs ilyen fiók, a nyugta `ok: false` („nincs ilyen fiók a webshopban”). Nyugta (`result`): `{ user_id,
discount_level, discount_pct, payment_days, blocked, block_reason }`.

- **Kedvezményszint** (`_mandala_discount_level`): a viszonteladói felületen látszik. Ha jár hozzá százalék (a parancs
  `discount_pct`-je, vagy WooCommerce → Partner CRM → **Kedvezményszintek**: `Kiemelt = 5`; a szint NEVÉBŐL nem olvas
  százalékot – a CRM is csak a beállított szintek közül ad),
  a kosárban a nagyker árból ennyivel kevesebb (a tétel ára csökken – a JUTA is így kapja), a rendelés tételén
  „Kedvezmény: −5%”.
- **Fizetési határidő** (`_mandala_payment_days`): a pénztárban megjelenik az **Átutalás számla alapján** fizetési mód
  (WooCommerce → Fizetés; csak a határidős, nem tiltott viszonteladónak). A rendelés azonnal feldolgozás alá kerül,
  `_mandala_payment_days` / `_mandala_payment_due` meta; a **Számlázz.hu-számla** fizetési határideje a számla kelte + N
  nap (a bővítmény `wc_szamlazz_xml` szűrőjén; kézi, egyedi határidős kiállításnál az marad). A JUTA `elad.php` az
  ismeretlen fizetési módot átutalásként adja át – ez itt helyes.
- **Rendelés-tiltás** (`_mandala_order_block`): a pénztárban csak **előre utalás** (ha az ki van kapcsolva: ami előre
  fizetés, pl. kártya; utánvét, helyszíni és halasztott fizetés nem), a fiókban és a pénztárban rövid tájékoztató:
  „Most előre utalással tudsz rendelni.” + a `reason` (a partnernek szóló rövid szöveg a CRM beállításából; ha üres,
  egy alapmondat). Az adminban is látszik (Felhasználók → a partner adatlapja → Partner CRM).
- Hibás parancsnál (pl. nincs ilyen fiók) a nyugta `{ id, ok: false, result: { error } }`.

### `consent` – partnerlevél-hozzájárulás a CRM-ből

`{ id, type: 'consent', data: { email, user_id?, partner_mail: 'yes' | 'no', source?, wording?, at? } }`

- **`partner_mail: 'no'`** – a partner a CRM-ben leiratkozott, vagy a csapat visszavonta: a webshopban a partnerlevél
  (`_mandala_partner_mail = no`) **és** a heti új érkezések levele (`_mandala_b2b_arrivals = no`) is kikapcsol; a
  MailerLite-ban kikerül a Viszonteladók csoportból (ha más hozzájárulása nincs: leiratkozott). A hírlevél-feliratkozás
  (B2C, külön cél) nem változik. Az `arrivals_off` aktivitás-esemény megjön.
- **`partner_mail: 'yes'`** – a CRM-ben rögzített hozzájárulás (pl. telefonon vagy személyesen kérte – Grt. 6. § (8)):
  a partnerlevél bekapcsol, a webshop leveszi a címet a leiratkozottak listájáról, a MailerLite újra aktiválja
  (`resubscribe`). A heti új érkezések levele nem kapcsol be (azt a partner maga kéri a felületen).
- `source` (nem kötelező, pl. `telefon`, `szemelyesen`, `leiratkozas`) → a webshopban `crm:<source>` forrás;
  `wording` (`yes`-nél): a hozzájárulás pontos szövege, ahogy a CRM-ben rögzítették (pl. „Telefonon kérte a
  partnerleveleket (Kovács Judit, 2026-10-16)”) – üresen „Telefonon vagy személyesen kérte a partnerleveleket (a Partner
  CRM-ben rögzítve)”; `at`: ISO 8601 időpont zónával (üresen / a jövőben: most). IP-cím nincs (a webshop nem látja).
- A fiók: `user_id`, ha az e-mail egyezik, különben az e-mail szerint (mint a `terms`). **Fiók nélkül** (érvényes
  e-maillel) függő hozzájárulás: a fiók létrejöttekor (jóváhagyás) kerül rá; a nyugta `{ user_id: 0, pending: true,
  partner_mail }`.
- Nyugta (`result`): `{ user_id, partner_mail: 'yes' | 'no', b2b_arrivals: bool, at }` (`at`: a hozzájárulás ideje,
  ISO). Hibás `partner_mail` (nem `yes` / `no`) vagy fiók és e-mail nélkül: `{ id, ok: false, result: { error } }`.
- A webshop a hozzájárulás-naplójába írja (consent.php: cél `partner_mail` / `b2b_arrivals`, forrás, szöveg, időpont), a
  `customer` eseményt nem küldi vissza (a CRM-ben már az az állapot).

Visszafelé: ha a partner a **webshopban** iratkozik le (a levél alján, a felületen, vagy a MailerLite-ban – webhook),
a CRM `customer` eseményt kap `marketing_consent: false`-szal (és `b2b_arrivals`, `unsubscribed`), a heti levélről
leiratkozásnál `activity` / `arrivals_off`-ot. A CRM-nek az `arrivals_off`-ot is a hozzájárulás visszavonásaként kell
kezelnie, ha a hozzájárulást korábban az `arrivals` eseményből vezette le (a CRM-oldali `hpv_pt_mk_consent`).

### Egy kattintásos rendelés ajánlatból

`https://mandala.hu/?mandala_offer=<base64url(json)>.<hex HMAC-SHA256(json, az összekötő titka)>`, ahol
`json = { "t": token, "e": partner e-mail, "i": [[cikkszám, db], …], "x": lejárat, "p"?: [[cikkszám, kedvezményes nettó
egységár Ft], …] }`. Az `x` az érvényesség utolsó napja 23:59:59 helyi idő (unix). Az aláírás a base64url-dekódolt JSON
bájtsoráé (kitöltés nélküli base64url); a webshop előbb az aláírást (`hash_equals`), utána a lejáratot ellenőrzi. A token:
`A–Z a–z 0–9 _ . : -`, legfeljebb 64 karakter; legfeljebb 200 tétel.

Ha egy tételnek van `p` ára, a kosárban azon az áron megy (nettó + a termék ÁFÁ-ja, a bolt bruttó áraihoz igazítva) –
de csak ha nem drágább a partner szokott áránál (nagyker ár a kedvezményszinttel; ha az kisebb, az marad), és **legfeljebb
az ajánlott darabszámig**: ha a kosárban többet tesz be, a többi darab a szokott áron megy (a tétel egységára a kettő
súlyozott átlaga; a kosárban: „N db ajánlati áron, a többi a szokott áron”). A **lejárat a kosárban is számít**: lejárt
ajánlatnál a tétel a szokott áron marad (a kosár szól), és a rendelés nem kap ajánlat-azonosítót. A rendelés tételén
`_mandala_offer_price` (a nettó ajánlati ár). `p` nélkül a tétel nagyker áron (és a partner kedvezményszintjével) megy.
A kosártétel adatai: `mandala_offer` (token), `mandala_offer_price`, `mandala_offer_qty` (az ajánlott db),
`mandala_offer_x` (lejárat).

- Nincs belépve → a belépő oldalra visz (süti, legfeljebb 1 nap), belépés után folytatja; a belépett fiók e-mailje
  (`user_email` – a számlázási e-mail nem, azt a vásárló maga írja át) egyezzen az `e`-vel, és viszonteladói fiók
  legyen – különben barátságos hiba.
- A tételek a kosárba (nagyker áron, a Wholesale Prices bővítmény szerint; készlet fölött annyi, amennyi van; ami nem
  rendelhető, azt felsorolja). Ugyanaz az ajánlat másodszor nem kerül be (kosárban van / már megrendelte). **Újra
  használható**, ha belőle csak lemondott, sikertelen vagy visszatérített rendelés lett; sikeres (függő, várakozó,
  feldolgozás alatti, teljesített, szállított …) rendelés után nem – a pénztárban is ellenőrizzük (a fizetésre váró,
  újrapróbált rendelés nem számít).
- A rendelés `_mandala_offer_token` metát kap (a tétel `_mandala_offer`-t és – ha volt – `_mandala_offer_price`-t), az
  `order` esemény `offer_token` mezőt; ezen felül `offer_open` és `offer_order` esemény (lásd fent). Lejárt vagy hamis
  link: barátságos hibaüzenet a kosár oldalon.

**Közös tesztvektor** (a CRM tesztje is ezt ellenőrzi):

| | |
|---|---|
| titok | `kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk` (32 × `k`) |
| json | `{"t":"a1b2c3d4e5f60718293a4b5c6d7e8f90","e":"bolt@example.hu","i":[["MA-1001",3],["HT-0420",1]],"x":1790000000}` |
| paraméter | `eyJ0IjoiYTFiMmMzZDRlNWY2MDcxODI5M2E0YjVjNmQ3ZThmOTAiLCJlIjoiYm9sdEBleGFtcGxlLmh1IiwiaSI6W1siTUEtMTAwMSIsM10sWyJIVC0wNDIwIiwxXV0sIngiOjE3OTAwMDAwMDB9.e37e65a73aba7cfe2ca6efe453bc7b36c6446c5ec64193c77081d48fcc548b4b` |
| `p`-vel: json | ugyanez + `,"p":[["MA-1001",1791]]` a végén (`…,"x":1790000000,"p":[["MA-1001",1791]]}`) |
| `p`-vel: aláírás | `e4d135b8d2b9c23f2077e7362084fa4144210f184b2655840eb0ab64a75cce03` |

PHP: `hash_hmac('sha256', $json, $secret)`; a link: `rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . '.' . $sig`
(a téma `mandala_offer_param()` függvénye).

## Beállítás

1. A CRM-ben: Partnerek → Beállítások → **Összekötő kód** → másolás.
2. A webshopban: **WooCommerce → Partner CRM** → a kód beillesztése → Mentés és kapcsolódás.
   (Vagy a wp-config.php-ban: `MANDALA_CRM_URL` = a CRM `…/wp-json/hpv/v1/partner/connect` címe, `MANDALA_CRM_SECRET`.)
3. Az első kapcsolódás után a webshop 10 percenként adagonként (40 viszonteladó a rendeléseivel) átküld mindent,
   a végén a teljes készletet. Az állapot (utolsó kapcsolat, sor, hiba) ugyanott, és az őrszemben („Partner CRM
   összekötő”).

A téma 1.1.202610121823-tól van benne; a terms parancs, az üzenet, a webes forgalom, a hozzájárulás és az ajánlat-link
a 2026. októberi 2. hullámtól (1.1.202610160024); az időpontok zónával, a `user_email`-párosítás, az ajánlat
darabszám-korlátja, a kosárbeli lejárat és az újrahasználat szabálya, a szint százaléka csak a listából és a jóváhagyás
szerep-hozzáadása az 1.1.202610160526-tól; a `consent` parancs, a hozzájárulás-napló (forrás, szöveg, IP), az
`arrivals_off` esemény és a fiók-esemény új hozzájárulás-mezői az 1.1.202610162144-tól. Összekötés nélkül a modul semmit nem gyűjt és nem küld. A titok csak az admin felületen / wp-config.php-ban van, a
repóban soha.

## Teszt

`tests/wp-features.mjs` → „Partner CRM összekötő”: közös aláírási tesztvektor (a CRM tesztjében is), https-kötelező kód,
összekötés nélkül nincs gyűjtés, aláírt kérés (a JS-ben újraszámolt HMAC), viszonteladói rendelés tételekkel, belépés,
árlista, jelentkezés (spam-csapda nélkül), teljes küldés (fiókok, készlet eleje / vége, nagyker ár), jóváhagyás → új
fiók a cégadatokkal, nyugta, admin oldal, őrszem. „Partner CRM: feltételek, üzenet, …”: az ajánlat-link tesztvektora
és ellenőrzése, terms → nyugta, tiltásnál csak előre utalás, halasztott átutalás, üzenet (ráta-korlát), hozzájárulás,
web_stats csatornánként, offer_token, Számlázz.hu határidő, a `p` ár (csak olcsóbb); böngészőben: ajánlat-link
belépéssel → kosár (ajánlati ár, hiányzó tétel, másodszor nem, megrendelt, lejárt, offer_open / offer_order), Üzenet a
Mandalának, tiltás a fiókban (a CRM szövegével) és a pénztárban, rendelés halasztott átutalással, hozzájárulás a fiókban
és a jelentkezésben. „Hozzájárulások és leiratkozás”: heti új érkezések levele reklámlevélként (feladó, saját
lábléc, List-Unsubscribe + List-Unsubscribe-Post, egy kattintásos POST-leiratkozás → `arrivals_off`, leiratkozott címre
nem megy), általános leiratkozás → `marketing_consent: false`, partnerlevél-hozzájárulás forrással, szöveggel, IP-vel
(CSV), MailerLite Viszonteladók csoport és webhook, `consent` parancs (`no`, `yes` telefonon, fiók nélkül függő, hibás
érték, `consent_cmd`).
