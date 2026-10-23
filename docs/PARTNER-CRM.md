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
  (`resubscribe`). A heti új érkezések levele nem kapcsol be (azt a partner maga kéri a felületen). A webshop B2C
  reklámleveleihez (hírlevél, emlékeztetők) ez nem hozzájárulás – azokhoz hírlevél-feliratkozás kell.
- A MailerLite Viszonteladók csoportja a viszonteladói szerepet is követi: a jóváhagyáskor (új fiók vagy szerep) a
  partnerlevél-hozzájárulással rendelkező partner bekerül, a szerep elvételekor kikerül.
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

## Átvitel: aláírt GET (protokoll v2) – az ELIN miatt

Mindkét oldal az ELIN-en van, és az ELIN tűzfala a szerverek közti POST-ot gyakran 403-mal tiltja (kis törzsnél is
nagyjából minden második kérést, 1 MB fölött mindig), a GET-et 7500 karakteres lekérdezésig átengedi. 2026-10-19-én a
webshop egy HTTP 400 után elakadt (85 esemény ment át, a készlet semmi), ezért a téma 1.1.202610230442-tól GET-tel küld
(a Dropship ugyanezért GET-es: hlprv `docs/dropship/README.md`).

- **Kérés:** `GET {CRM-cím}?hpvz=<Z>&t=<ts>&n=<nonce>&s=<aláírás>` (ha a CRM-címben már van `?` – pl. `?rest_route=` –,
  akkor `&`-tel). `BODY` = ugyanaz a JSON, mint a POST-nál (`{"events":[…],"acks":[…]}`), `Z` = base64url (kitöltés
  nélkül) a `gzdeflate(BODY, 9)` nyers DEFLATE-ből. Az aláírás változatlan: `hpv-partner-v1` HMAC a `BODY`-n; az
  egyszeri nonce és az 5 perces ablak is.
- **Méret:** a webshop mohón annyi eseményt tesz egy kérésbe, amennyi tömörítve belefér (`Z` ≤ 5500 karakter, mérve). A
  CRM a 16 000 karakternél hosszabb `Z`-t és az 1 MB-nál nagyobb kibontott csomagot 400-zal elutasítja.
- **Válasz:** ugyanaz a JSON (`ok, accepted, acked, errors, commands`) + `"transport":"get"`, gyorsítótár nélkül. A
  webshop csak ezt fogadja el sikernek (2xx, JSON, `ok`, GET-nél `transport: get`): egy köztes szerver 200-as `{}`-ja
  nem viszi le a sort (*Utolsó hiba*: „HTTP 200 (…), nem a CRM válasza”).
- **Adatvédelem:** a csomag a címben megy, így a tárhely (ELIN, nginx) access logjában tömörítve, de visszafejthetően
  megmarad (e-mail, adószám, rendelés). A naplózott cím 5 perc után nem játszható vissza; a naplók megőrzéséről a
  tárhely dönt.
- **Készlet:** eseményenként 40 tétel, és a viszonteladók teljes küldésétől függetlenül megy (eddig a teljes küldés
  végét várta). A sorban maradt régi, 400 tételes készlet-eseményt a webshop a helyén kisebbekre vágja (a teljes lista
  `full_start` / `full_end` jelölője az első / utolsó darabon marad).
- **Tartalék POST:** egyetlen, a GET-be be nem férő (nem készlet-) esemény a régi POST-tal megy (hibánál ugyanúgy
  újrapróba és elérhetőség-próba, mint a többinél); ha a CRM még a régi (a GET-re `rest_no_route`-tal válaszol),
  ugyanaz a csomag POST-tal. A CRM a POST-ot továbbra is elfogadja.
- **Futás:** 10 percenként (Action Scheduler), legfeljebb ~15 mp vagy 80 kérés, a kérések közt 150 ms. Előbb a már
  sorban álló események mennek (fél időkerettel), csak utána jön a teljes küldés adagja, a készlet és a webes forgalom,
  végül a küldés a maradék időben – így egy később elhaló lépés (időkorlát, memória) sem akasztja meg a küldést; a
  végzetes PHP-hiba a lépés nevével a *Háttérhiba* sorba kerül, a zár felszabadul (oldalbetöltés végén a PHP ezt már
  nem tudja beírni: ott a következő futás eleje jelzi, hogy az előző melyik lépésnél szakadt meg). A nyugták csak
  sikeres válasz után törlődnek. Hibánál egy gyors újrapróba csak a sor elejével, aztán egy üres kérés:
  ha arra a CRM válaszol, a sor eleje a hibás – **3 futás után félreteszi** (`mandala_crm_dead`, az utolsó 50, okkal),
  és a sor megy tovább. A 3 hiba közt legalább 8-8 perc kell, és csak az ütemezett / oldalbetöltéses futás számít (a
  fontos esemény utáni gyors küldés és az admin gombjai nem – két gyors kattintás nem tesz félre semmit); ha a CRM nem
  válaszol, semmi nem kerül félre. A sor írása (új esemény, levétel) rövid MySQL-zárral megy (`GET_LOCK`), hogy a
  futás és egy látogató egyszerre írása ne vesszen el.
- **Ha a WP-cron / Action Scheduler nem fut** (az ELIN a loopback POST-ot is tilthatja): egy admin- vagy látogatói
  oldalbetöltés végén – a válasz elküldése után (`fastcgi_finish_request`) – lefut, ha 9 percnél régebben futott; ha a
  válasz nem zárható le előre, csak a bolt kezelőjének admin-oldalán (5 mp-es kerettel; látogató nem vár rá); zár
  véd a kettős futás ellen. A napi webes forgalom (előző + aktuális hónap) is a szinkronból megy, ha a hajnali
  ütemezett feladat nem futott. A teljes küldés fiókonként halad (egy hibás fiók / rendelés kimarad, a hiba látszik; ha egy
  fióknál a futás megszakad, a következő átugorja), és vár, amíg a sorban 600-nál több esemény áll.

**Amit az admin lát** (WooCommerce → Partner CRM): *Utolsó háttérfuttatás* (mikor, ütemező / oldalbetöltés; ha nem
fejeződött be, melyik lépésnél), *Háttérhiba*, *Átvitel* (GET – ha a legutóbbi kérés POST-tal ment, azt is jelzi),
*Teljes készletlista* (mikor került sorba), *Félretett események* (darab, ok, **Vissza a sorba** gomb), *Utolsó hiba*
a válaszoló szerverrel és a válasz elejével (pl. `HTTP 403 (nginx): 403 Forbidden …` – így látszik, hogy a tárhely
tűzfala vagy a CRM válaszolt). A **Kapcsolat próbája most** is az új átvitellel küld. Az őrszem figyelmeztet, ha fél
órája nem futott a háttérküldés, ha a legutóbbi félbeszakadt (melyik lépésnél, milyen hibával), ha egy órája nem
volt sikeres kapcsolat, vagy ha van félretett esemény.

## Beállítás

1. A CRM-ben: Partnerek → Beállítások → **Összekötő kód** → másolás.
2. A webshopban: **WooCommerce → Partner CRM** → a kód beillesztése → Mentés és kapcsolódás.
   (Vagy a wp-config.php-ban: `MANDALA_CRM_URL` = a CRM `…/wp-json/ucrm/v1/partner/connect` címe, `MANDALA_CRM_SECRET`.)
3. Az első kapcsolódás után a webshop 10 percenként adagonként (legfeljebb 40 viszonteladó a rendeléseivel) átküld
   mindent; a teljes készletet már az első futásban (40 tételes eseményekben). Az állapot (utolsó futás, kapcsolat,
   sor, félretett események, hiba) ugyanott, és az őrszemben („Partner CRM összekötő”).

A téma 1.1.202610121823-tól van benne; a terms parancs, az üzenet, a webes forgalom, a hozzájárulás és az ajánlat-link
a 2026. októberi 2. hullámtól (1.1.202610160024); az időpontok zónával, a `user_email`-párosítás, az ajánlat
darabszám-korlátja, a kosárbeli lejárat és az újrahasználat szabálya, a szint százaléka csak a listából és a jóváhagyás
szerep-hozzáadása az 1.1.202610160526-tól; a `consent` parancs, a hozzájárulás-napló (forrás, szöveg, IP), az
`arrivals_off` esemény és a fiók-esemény új hozzájárulás-mezői az 1.1.202610162144-tól; a MailerLite Viszonteladók
csoportjának szerep-követése (jóváhagyáskor be) az 1.1.202610162322-től. Összekötés nélkül a modul semmit nem gyűjt és nem küld. A titok csak az admin felületen / wp-config.php-ban van, a
repóban soha.

## Teszt

`php tests/crm-connect-transport.php` (WordPress nélkül, helyettesítőkkel): a `Z` oda-vissza (a CRM kibontásával és
aláírás-ellenőrzésével), a mohó csomagolás és az 5500-as határ, a készlet szétvágása a jelölőkkel, a tartalék POST, a
nyugták (csak siker után törlődnek; a nem-CRM 2xx JSON nem siker), a mérgezett esemény (3 futás, egymástól 8 percre →
félre; leállt CRM-nél, gyors futásnál, admin gombnál nem; a túl nagy esemény is újrapróbával), a hibaszöveg, a zár és a
sor MySQL-zárja, a 40-es készlet-adagok a teljes küldéstől függetlenül, a teljes küldés hibatűrése, a futás sorrendje
és kérés-kerete, a félbeszakadt előző futás jelzése, a napi webes forgalom a szinkronból, a tartalék ütemező, az őrszem. `HLPRV=/útvonal/hlprv php tests/crm-connect-transport.php`: a GET kérést a CRM valódi
`core.php`-ja (`hpv_pt_connect_decode_get`, `hpv_pt_verify`) is ellenőrzi.

`tests/wp-features.mjs` → „Partner CRM összekötő”: közös aláírási tesztvektor (a CRM tesztjében is), https-kötelező kód,
összekötés nélkül nincs gyűjtés, aláírt GET kérés (a JS-ben újraszámolt HMAC, a `hpvz` a JS zlib-jével kibontva,
≤ 5500 karakter), viszonteladói rendelés tételekkel, belépés,
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
