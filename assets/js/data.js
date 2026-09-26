// Mandala – minta adatok és konfiguráció.
// A termékek a jelenlegi mandala.hu kínálatából (név, ár, kategória) és bemutató
// célú kiegészítésekből állnak. Éles üzemben a store.js WooCommerce Store API-n
// keresztül tölti be a valódi kínálatot (lásd CONFIG.woocommerce).

export const CONFIG = {
  brand: 'Mandala',
  currency: 'Ft',
  vatRate: 27,                // magyar B2C: bruttó árak (woocommerce_prices_include_tax = yes)
  freeShippingFrom: 25000,
  // Ha be van állítva (pl. 'https://mandala.hu'), a termékek a WooCommerce Store API-ból jönnek.
  woocommerce: null,
  // Helykitöltő cégadatok – élesítés előtt az ügyféltől (lásd docs/IU-BLOKKTERKEP.md).
  contact: {
    email: 'info@mandala.hu',
    phone: '+36 1 234 5678',
    address: 'Budapest – bemutatóterem és átvevőhely',
    hours: 'H–P 10:00–18:00, Szo 10:00–14:00',
    facebook: 'https://www.facebook.com/mandalawebaruhaz',
    instagram: '#',
  },
  bank: { name: '[Bank neve]', holder: 'Mandala [cégnév]', account: '12345678-12345678-12345678', iban: 'HU00 1234 5678 1234 5678 1234 5678', swift: 'XXXXHUHB' },
  // WooCommerce szállítási módok (Magyarország zóna). Az árak bruttók.
  shipping: [
    { id: 'gls', wc: 'flat_rate:1', label: 'GLS futárszolgálat', note: 'Házhoz szállítás 1–2 munkanap alatt', price: 1990, free: true, icon: 'truck' },
    { id: 'foxpost', wc: 'foxpost:2', label: 'Foxpost csomagautomata', note: 'Átvétel 0–24, országszerte 1000+ ponton', price: 1290, free: true, icon: 'locker' },
    { id: 'pickup', wc: 'local_pickup:3', label: 'Személyes átvétel', note: 'Budapesti bemutatótermünkben, értesítés után', price: 0, free: true, icon: 'store' },
  ],
  // Fizetési módok. Az utánvét a személyes átvételnél „Fizetés átvételkor” díj nélkül.
  payment: [
    { id: 'barion', label: 'Bankkártya, Apple Pay, Google Pay', note: 'Biztonságos fizetés a Barion rendszerén keresztül', marks: ['VISA', 'Mastercard', 'Apple Pay', 'Google Pay'] },
    { id: 'bacs', label: 'Előre utalás', note: 'A banki adatokat a visszaigazolásban küldjük; a csomag a jóváírás után indul.' },
    { id: 'cod', label: 'Utánvét', note: 'Fizetés készpénzzel vagy kártyával a futárnak / az automatánál.', fee: 490, pickupLabel: 'Fizetés átvételkor', pickupNote: 'Készpénzzel vagy bankkártyával a bemutatóteremben.' },
  ],
  coupons: {
    MANDALA10: { type: 'percent', amount: 10, label: '10% kedvezmény' },
    UDVOZLO: { type: 'fixed', amount: 1500, min: 10000, label: '1 500 Ft kedvezmény 10 000 Ft felett' },
  },
  // Foxpost automaták – minta, élesben a szállítási bővítmény csomagpont-választója adja.
  lockers: [
    { id: 'fx-1', name: 'Budapest, Allee bevásárlóközpont', address: '1117 Budapest, Október huszonharmadika u. 8–10.', hours: '0–24' },
    { id: 'fx-2', name: 'Budapest, Nyugati tér', address: '1062 Budapest, Váci út 1–3.', hours: '0–24' },
    { id: 'fx-3', name: 'Budapest, Árkád Örs vezér tere', address: '1106 Budapest, Örs vezér tere 25.', hours: '6–22' },
    { id: 'fx-4', name: 'Debrecen, Fórum', address: '4025 Debrecen, Csapó u. 30.', hours: '0–24' },
    { id: 'fx-5', name: 'Szeged, Árkád', address: '6724 Szeged, Londoni krt. 3.', hours: '0–24' },
    { id: 'fx-6', name: 'Győr, Árkád', address: '9027 Győr, Budai út 1.', hours: '0–24' },
    { id: 'fx-7', name: 'Pécs, Árkád', address: '7622 Pécs, Bajcsy-Zsilinszky u. 11.', hours: '0–24' },
  ],
};

export const ORIGINS = {
  nepal: { label: 'Nepál', long: 'Nepál – Katmandu-völgy', tone: '#7A2E2E' },
  india: { label: 'India', long: 'India', tone: '#B5651D' },
};

export const INTENTS = [
  { id: 'csend', label: 'Elcsendesülés', text: 'Hangtálak, füstölők és mala láncok a befelé figyelés pillanataihoz.', art: 'bowl' },
  { id: 'otthon', label: 'Otthoni harmónia', text: 'Szobrok, szélcsengők és textilek, amelyek nyugalmat adnak a térnek.', art: 'chime' },
  { id: 'onkifejezes', label: 'Önkifejezés', text: 'Könnyű indiai textilek és ékszerek szabadabb, személyesebb stílushoz.', art: 'ring' },
  { id: 'ajandek', label: 'Figyelmes ajándék', text: 'Tárgyak, amelyekkel nemcsak ajándékot, hanem jelentést is adsz.', art: 'gift' },
];

// A kategória-slugok megegyeznek a mandala.hu WooCommerce slugjaival.
export const CATEGORIES = [
  {
    slug: 'szakralis-targyak', label: 'Szakrális tárgyak', art: 'bowl',
    text: 'Hangtálak, füstölők, mala láncok és rituálé eszközök a csendesebb pillanatokhoz.',
    subs: [
      ['hangtalak', 'Hangtálak'], ['fustolok', 'Füstölők'], ['fustolotartok', 'Füstölőtartók'],
      ['mala-lancok', 'Mala láncok'], ['rituale-eszkozok', 'Rituálé eszközök'],
      ['oltar-kiegeszitok', 'Oltár kiegészítők'], ['illoolajok', 'Illóolajok'],
    ],
  },
  {
    slug: 'lakberendezes', label: 'Lakberendezés', art: 'buddha',
    text: 'Buddha szobrok, szélcsengők, meditációs párnák és dekorok harmonikus terekhez.',
    subs: [
      ['spiritualis-dekor', 'Spirituális dekor'], ['szobrok', 'Szobrok'],
      ['alomfogok-szelcsengok', 'Álomfogók és szélcsengők'], ['lakastextil', 'Lakástextil'],
      ['fali-dekoracio', 'Fali dekoráció'], ['keramia', 'Kerámia'],
    ],
  },
  {
    slug: 'ruhazat-es-kiegeszitok', label: 'Ruházat és ékszer', art: 'scarf',
    text: 'Könnyű pamut és viszkóz ruhák, sálak és ékszerek keleti ihletéssel.',
    subs: [
      ['ekszerek', 'Ékszerek'], ['nadragok', 'Nadrágok'], ['ruhak', 'Ruhák'],
      ['kiegeszitok', 'Sálak és kiegészítők'],
    ],
  },
  {
    slug: 'ajandektargyak', label: 'Ajándéktárgyak', art: 'copper',
    text: 'Réz kulacsok, teák és apró kincsek, ha valami személyeset adnál.',
    subs: [['rez-kulacsok', 'Réz kulacsok'], ['teak', 'Teák'], ['bogrek', 'Bögrék']],
  },
];

const P = (o) => ({ stock: 'in', stockQty: 12, intents: [], specs: {}, images: [], ...o });

export const PRODUCTS = [
  // — Szakrális tárgyak —
  P({
    id: 26234, sku: 'MND-HT-0490', stockQty: 2, stock: 'low', images: ['hangtalak-studio'], slug: 'mintas-hangtal-490g-405hz', name: 'Hét fémből öntött mintás hangtál – G#, torokcsakra',
    cat: 'szakralis-targyak', sub: 'hangtalak', price: 37340, origin: 'nepal', place: 'Patan, Katmandu-völgy',
    art: 'bowl', tone: 'saffron', isNew: true, featured: true, intents: ['csend', 'ajandek'],
    specs: { 'Súly': '490 g', 'Frekvencia': '405 Hz', 'Hang': 'G#', 'Csakra': 'Torok (Vishuddha)', 'Anyag': '7 fémes ötvözet', 'Díszítés': 'Vésett minta' },
    short: 'Tiszta, hosszan lecsengő hang, kézzel vésett díszítéssel. Ütővel és alátéttel.',
    description: 'A nepáli műhelyekben hagyományosan hét fém ötvözetéből készülő hangtálak mély, rétegzett felhangokat adnak. Ez a darab a G# hangra, a torokcsakrához kapcsolt 405 Hz körüli alaphangra szól, ezért kedvelt a hangfürdőkben és a hangképzéssel kapcsolatos gyakorlásban.',
    ritual: 'Tartsd a tálat nyitott tenyéren, és egyenletes nyomással, lassan köröztesd az ütőt a peremén. Ha a hang megszólalt, ne gyorsíts – a tál „énekelni” kezd.',
  }),
  P({
    id: 27101, sku: 'MND-HT-0560', images: ['hangtalak-studio'], slug: 'mintas-hangtal-560g-390hz', name: 'Hét fémből öntött mintás hangtál – G, torokcsakra',
    cat: 'szakralis-targyak', sub: 'hangtalak', price: 42672, origin: 'nepal', place: 'Patan, Katmandu-völgy',
    art: 'bowl', tone: 'saffron', isNew: true, intents: ['csend'],
    specs: { 'Súly': '560 g', 'Frekvencia': '390 Hz', 'Hang': 'G', 'Csakra': 'Torok (Vishuddha)', 'Anyag': '7 fémes ötvözet', 'Díszítés': 'Vésett minta' },
    short: 'Testesebb, mélyebb alaphang. Meditációhoz és hangfürdőhöz ajánljuk.',
    description: 'Nagyobb tömege miatt lassabban cseng le és gazdagabb alsó felhangokat ad, mint a kisebb tálak. Egyéni gyakorláshoz és kisebb csoportos hangfürdőhöz egyaránt jó választás.',
    ritual: 'Ütővel egyet megütve a peremen, a hang lecsengéséig figyeld a légzésed. Ez már önmagában egy teljes, rövid meditáció.',
  }),
  P({
    id: 30101, sku: 'MND-HT-0780', stock: 'out', stockQty: 0, images: ['hangtalak-studio', 'hangtalak-gyertyafeny'], slug: 'kezi-kovacsolt-hangtal-full-moon', name: 'Kézzel kovácsolt Full Moon hangtál',
    cat: 'szakralis-targyak', sub: 'hangtalak', price: 58900, origin: 'nepal', place: 'Katmandu-völgy',
    art: 'bowl', tone: 'saffron', featured: true, intents: ['csend'],
    specs: { 'Súly': '780 g', 'Frekvencia': '~136 Hz', 'Hang': 'C#', 'Anyag': 'Kézzel kovácsolt bronz', 'Készítés': 'Teliholdas öntés hagyománya' },
    short: 'A hagyomány szerint teliholdkor készülő, mély zengésű kovácsolt tál.',
    description: 'A kovácsolt tálak felületén a kalapács nyomai láthatók: minden darab egyedi, a hangja is. A Full Moon tálakat a nepáli hagyomány szerint teliholdkor kezdik el formázni.',
    ritual: 'Helyezd párnára, és puha, filcborítású ütővel üsd meg. A mély zengés a padlón és a testen keresztül is érezhető.',
  }),
  P({
    id: 4490, sku: 'MND-ML-0085', slug: 'chakra-mala-8-5mm', name: '7 csakra mala, 8,5 mm-es gyöngyökből',
    cat: 'szakralis-targyak', sub: 'mala-lancok', price: 16510, origin: 'nepal', place: 'Katmandu',
    art: 'mala', tone: 'maroon', featured: true, intents: ['csend', 'ajandek', 'onkifejezes'],
    specs: { 'Gyöngyök': '108 + guru gyöngy', 'Méret': '8,5 mm', 'Kövek': 'Hét csakrakő', 'Bojt': 'Pamut' },
    short: '108 szemes mala a hét csakra köveivel, kézzel csomózva.',
    description: 'A mala a mantrák és a légzés számolásának hagyományos eszköze. A hét csakra színeit követő kövek kézzel csomózott selyemfonalon futnak, a guru gyöngy alatt pamutbojttal.',
    ritual: 'A jobb kezed hüvelyk- és középujjával haladj gyöngyről gyöngyre, minden szemnél egy mantrával vagy légzéssel. A guru gyöngyöt ne lépd át – ott fordítsd meg a malát.',
  }),
  P({
    id: 4688, sku: 'MND-CS-0018', slug: 'buddhista-csengo-l', name: 'Tibeti csengő és dordzse (L)',
    cat: 'szakralis-targyak', sub: 'rituale-eszkozok', price: 21090, origin: 'nepal', place: 'Patan',
    art: 'bell', tone: 'saffron', featured: true, intents: ['csend'],
    specs: { 'Magasság': '18 cm', 'Anyag': 'Bronzötvözet', 'Tartozék': 'Dordzse (vadzsra)' },
    short: 'A ghanta és a dordzse a bölcsesség és az együttérzés egységét jelképezi.',
    description: 'A tibeti szertartások két elválaszthatatlan eszköze: a bal kézben tartott csengő (ghanta) a bölcsességet, a jobb kézben tartott dordzse az együttérzést jelképezi. Tiszta, magas, hosszan kitartott hang.',
    ritual: 'A csengőt a nyelével függőlegesen tartva lendítsd meg egyszer, és hagyd teljesen lecsengeni a hangot, mielőtt újra megszólaltatod.',
  }),
  P({
    id: 30102, sku: 'MND-TS-0065', slug: 'tingsha-nyolc-szerencsejel', name: 'Tingsha – nyolc szerencsejellel',
    cat: 'szakralis-targyak', sub: 'rituale-eszkozok', price: 9890, origin: 'nepal', place: 'Katmandu',
    art: 'tingsha', tone: 'saffron', isNew: true, intents: ['csend', 'ajandek'],
    specs: { 'Átmérő': '6,5 cm', 'Anyag': 'Bronzötvözet', 'Zsinór': 'Bőr' },
    short: 'Két kis cintányér bőrszíjon – tiszta jelzőhang a gyakorlás elején és végén.',
    description: 'A tingsha éles, hosszan csengő hangja jelzésre szolgál: kijelöli a meditáció kezdetét és végét. Felületén a buddhizmus nyolc szerencsehozó jelképe látható.',
    ritual: 'Fogd meg a két tányért a zsinórnál fogva, és a peremükkel üsd össze őket könnyedén, függőlegesen tartva.',
  }),
  P({
    id: 30103, sku: 'MND-FT-0030', slug: 'tibeti-fustolo-lotus', name: 'Tibeti gyógynövényes füstölő – Lótusz',
    cat: 'szakralis-targyak', sub: 'fustolok', price: 2490, origin: 'nepal', place: 'Katmandu',
    art: 'incense', tone: 'maroon', intents: ['csend', 'otthon'],
    specs: { 'Tartalom': '30 szál', 'Égési idő': '~40 perc / szál', 'Összetevők': 'Himalájai gyógynövények, szantál' },
    short: 'Pálca nélküli, kézzel sodort tibeti füstölő, lágy földes illattal.',
    description: 'A tibeti füstölők bambuszpálca nélkül, gyógynövényporból sodort rudak. Illatuk visszafogottabb és földesebb, mint az indiai füstölőké – a kolostori használatból erednek.',
    ritual: 'Gyújtsd meg a végét, néhány másodperc múlva fújd el a lángot, és tedd homokba vagy tibeti füstölőtartóba.',
  }),
  P({
    id: 30104, sku: 'MND-FI-0015', images: ['fustolok-csakra'], slug: 'nag-champa-fustolo', name: 'Nag Champa kézzel sodort füstölő',
    cat: 'szakralis-targyak', sub: 'fustolok', price: 1690, origin: 'india', place: 'Bengaluru',
    art: 'incense', tone: 'saffron', isNew: true, intents: ['csend', 'otthon'],
    specs: { 'Tartalom': '15 g (~12 szál)', 'Égési idő': '~45 perc / szál', 'Összetevők': 'Champa virág, halmaddi, szantál' },
    short: 'A klasszikus indiai templomillat: édes, virágos, meleg.',
    description: 'A dél-indiai Bengaluru környéki családi műhelyek kézzel sodort füstölői. A Nag Champa a legismertebb indiai illatkeverék: champa virág és halmaddi gyanta.',
    ritual: 'A füstölőt ferdén, pálcikás füstölőtartóba állítva gyújtsd meg, a lángot fújd el. Szellőztetett térben használd.',
  }),
  P({
    id: 4652, sku: 'MND-FTT-0009', slug: 'keleties-fustolo-keszlet-feher', name: 'Kúp és por füstölőtartó – fehér',
    cat: 'szakralis-targyak', sub: 'fustolotartok', price: 13450, origin: 'india', place: 'Moradabad',
    art: 'holder', tone: 'sand', featured: true, intents: ['otthon', 'ajandek'],
    specs: { 'Méret': '9 × 9 cm', 'Anyag': 'Festett fém', 'Használat': 'Kúp és por füstölőhöz' },
    short: 'Áttört mintás, fedeles tartó kúp- és porfüstölőhöz.',
    description: 'A fedél áttört mintáján át szűrődik a füst, ezért a tartó akkor is díszes marad, amikor épp nem használod. Kúp és por füstölőhöz is alkalmas.',
    ritual: 'A kúpot a hegyénél gyújtsd meg, fújd el a lángot, majd helyezd a tartó közepére és tedd vissza a fedelet.',
  }),
  P({
    id: 30105, sku: 'MND-IZ-0010', slug: 'imazaszlo-lung-ta', name: 'Imazászló – Lung-ta, 10 zászló',
    cat: 'szakralis-targyak', sub: 'oltar-kiegeszitok', price: 3490, origin: 'nepal', place: 'Katmandu',
    art: 'flag', tone: 'sky', intents: ['otthon', 'ajandek'],
    specs: { 'Hossz': '~2 m', 'Zászlók': '10 db, 5 szín', 'Anyag': 'Pamutvászon, fanyomat' },
    short: 'Öt szín, öt elem – a szél viszi tovább a rájuk nyomtatott áldásokat.',
    description: 'A kék, fehér, piros, zöld és sárga zászlók az öt elemet jelképezik. A hagyomány szerint a szél viszi tovább a rájuk nyomtatott mantrákat és áldásokat.',
    ritual: 'Szép időben, magasra, szabad légáramlatba feszítsd ki. A régi zászlót nem dobjuk ki: elégetjük vagy újak mellé akasztjuk.',
  }),

  // — Lakberendezés —
  P({
    id: 30201, sku: 'MND-BS-0020', stock: 'low', stockQty: 1, slug: 'meditacios-buddha-szobor-rez', name: 'Meditáló Buddha szobor, réz – 20 cm',
    cat: 'lakberendezes', sub: 'szobrok', price: 24900, origin: 'nepal', place: 'Patan',
    art: 'buddha', tone: 'saffron', featured: true, intents: ['otthon', 'ajandek', 'csend'],
    specs: { 'Magasság': '20 cm', 'Anyag': 'Réz, öntött', 'Kézjel': 'Dhjána mudra (meditáció)' },
    short: 'Patani öntőműhely munkája, kézzel patinázva.',
    description: 'A Katmandu-völgyi Patan évszázadok óta a fémszobrászat központja. A dhjána mudra – az ölben egymásra helyezett kezek – a meditáció és az elmélyülés kézjele.',
    ritual: 'Szemmagasságban vagy afölött, tiszta, rendezett helyen érdemes elhelyezni – ne a padlón és ne a fürdőszobában.',
  }),
  P({
    id: 30202, sku: 'MND-SZ-0005', slug: 'szelcsengo-ot-csoves', name: 'Öt csöves réz szélcsengő',
    cat: 'lakberendezes', sub: 'alomfogok-szelcsengok', price: 8990, origin: 'india', place: 'Moradabad',
    art: 'chime', tone: 'sage', isNew: true, intents: ['otthon', 'ajandek'],
    specs: { 'Hossz': '48 cm', 'Anyag': 'Réz, fa', 'Hangolás': 'Pentaton' },
    short: 'Pentaton hangolású csövek – bármilyen szél mellett harmonikus hangzás.',
    description: 'A Moradabadi fémműves hagyományból érkező szélcsengő csövei pentaton skálára hangoltak, így a hangok bármilyen sorrendben harmonikusan szólnak.',
    ritual: 'Bejárat, erkély vagy nyitott ablak közelébe akaszd, ahol a légmozgás gyengéden megszólaltatja.',
  }),
  P({
    id: 30203, sku: 'MND-MP-0033', slug: 'meditacios-parna-zafu', name: 'Meditációs párna (zafu), hajdinahéj töltettel',
    cat: 'lakberendezes', sub: 'lakastextil', price: 14900, origin: 'india', place: 'Jaipur',
    art: 'cushion', tone: 'maroon', intents: ['csend', 'otthon'],
    specs: { 'Átmérő': '33 cm', 'Magasság': '15 cm', 'Huzat': '100% pamut, levehető', 'Töltet': 'Hajdinahéj' },
    short: 'Stabil, formatartó ülőpárna, mosható pamuthuzattal.',
    description: 'A hajdinahéj töltet felveszi a test formáját, mégis stabil marad, így a medence kissé megemelkedik, és a gerinc könnyebben egyenesedik ki ülés közben.',
    ritual: 'Ülj a párna első harmadára, hogy a térdeid a talajra érkezhessenek.',
  }),
  P({
    id: 30204, sku: 'MND-MT-0010', slug: 'mecsestarto-lotusz-rez', name: 'Lótusz mécsestartó, réz',
    cat: 'lakberendezes', sub: 'spiritualis-dekor', price: 5990, origin: 'india', place: 'Moradabad',
    art: 'candle', tone: 'sand', intents: ['otthon', 'ajandek'],
    specs: { 'Átmérő': '10 cm', 'Anyag': 'Réz', 'Mécses': 'Standard teamécses' },
    short: 'Nyíló lótusz formájú tartó – esti fényrituálékhoz.',
    description: 'A lótusz a sárból tisztán kiemelkedő virág – a tisztaság és az ébredés jelképe. Réz szirmai meleg fénnyel verik vissza a mécses lángját.',
    ritual: 'Gyújtsd meg este, a nap lezárásaként, és néhány lélegzetnyi ideig csak figyeld a lángot.',
  }),
  P({
    id: 30205, sku: 'MND-AF-0020', slug: 'alomfogo-makrame', name: 'Makramé álomfogó tollakkal',
    cat: 'lakberendezes', sub: 'alomfogok-szelcsengok', price: 6490, origin: 'india', place: 'Pushkar',
    art: 'dreamcatcher', tone: 'sand', intents: ['otthon', 'ajandek'],
    specs: { 'Átmérő': '20 cm', 'Hossz': '55 cm', 'Anyag': 'Pamutfonal, fa, toll' },
    short: 'Kézzel csomózott pamut háló, természetes színekben.',
    description: 'Kézzel csomózott makramé háló, puha pamutfonalból. Hálószobába, gyerekszobába vagy olvasósarokba.',
    ritual: 'Az ágy fölé vagy ablak közelébe akaszd, ahol a reggeli fény éri.',
  }),

  // — Ruházat és ékszer —
  P({
    id: 25843, sku: '9990000043316', slug: 'csakras-gyuru-arany-szinu', name: 'Csakrás gyűrű, arany színű',
    cat: 'ruhazat-es-kiegeszitok', sub: 'ekszerek', price: 3940, origin: 'india', place: 'Jaipur',
    art: 'ring', tone: 'saffron', isNew: true, intents: ['onkifejezes', 'ajandek'],
    specs: { 'Méret': 'Állítható', 'Anyag': 'Aranyszínű fém, kövek' },
    short: 'Hét apró kő a hét csakra színeiben, állítható méretben.',
    description: 'Finom, mindennap hordható gyűrű a hét csakra színeit követő kövekkel. Állítható, így ajándéknak is biztonságos választás.',
    ritual: 'Tárold száraz helyen, és kerüld a parfümmel, vízzel való érintkezést.',
  }),
  P({
    id: 25854, sku: '9990000043309', slug: 'csakras-gyuru-ezust-szinu', name: 'Csakrás gyűrű, ezüst színű',
    cat: 'ruhazat-es-kiegeszitok', sub: 'ekszerek', price: 3940, origin: 'india', place: 'Jaipur',
    art: 'ring', tone: 'sky', isNew: true, intents: ['onkifejezes', 'ajandek'],
    specs: { 'Méret': 'Állítható', 'Anyag': 'Ezüstszínű fém, kövek' },
    short: 'Hét apró kő a hét csakra színeiben, állítható méretben.',
    description: 'Finom, mindennap hordható gyűrű a hét csakra színeit követő kövekkel. Állítható, így ajándéknak is biztonságos választás.',
    ritual: 'Tárold száraz helyen, és kerüld a parfümmel, vízzel való érintkezést.',
  }),
  P({
    id: 25721, sku: '9990000043330', slug: 'gyuru-41-2', name: 'Ezüstözött gyűrű kővel – 41-2',
    cat: 'ruhazat-es-kiegeszitok', sub: 'ekszerek', price: 6350, origin: 'india', place: 'Jaipur',
    art: 'ring', tone: 'sage', isNew: true, intents: ['onkifejezes', 'ajandek'],
    specs: { 'Anyag': 'Ezüstözött fém, féldrágakő' },
    short: 'Kézműves foglalatú gyűrű egyetlen, nagyobb féldrágakővel.',
    description: 'A jaipuri ékszerkészítő hagyomány ihlette gyűrű. Minden kő egyedi rajzolatú, ezért a darabok kismértékben eltérhetnek a fotótól.',
    ritual: 'Puha, száraz kendővel tisztítsd.',
  }),
  P({
    id: 25949, sku: '9990000043170', images: ['ruhazat-to'], slug: 'viragos-indas-sal-1', name: 'Virágos-indás viszkóz sál – terrakotta',
    cat: 'ruhazat-es-kiegeszitok', sub: 'kiegeszitok', price: 8750, origin: 'india', place: 'Jaipur',
    art: 'scarf', tone: 'saffron', isNew: true, intents: ['onkifejezes', 'ajandek'],
    specs: { 'Méret': '180 × 90 cm', 'Anyag': '100% viszkóz', 'Minta': 'Blokknyomat ihlette virágos inda' },
    short: 'Könnyű, omló sál a jaipuri blokknyomás motívumaival.',
    description: 'A rádzsasztáni blokknyomás virág- és indamotívumai könnyű, jól omló viszkózon. Nyáron vállkendőnek, télen sálnak.',
    ritual: 'Kézzel, langyos vízben mosd, és árnyékban szárítsd.',
  }),
  P({
    id: 25950, sku: '9990000043200', slug: 'viragos-indas-sal-4', name: 'Virágos-indás viszkóz sál – indigó',
    cat: 'ruhazat-es-kiegeszitok', sub: 'kiegeszitok', price: 8750, origin: 'india', place: 'Jaipur',
    art: 'scarf', tone: 'sky', intents: ['onkifejezes', 'ajandek'],
    specs: { 'Méret': '180 × 90 cm', 'Anyag': '100% viszkóz', 'Minta': 'Blokknyomat ihlette virágos inda' },
    short: 'Könnyű, omló sál a jaipuri blokknyomás motívumaival.',
    description: 'A rádzsasztáni blokknyomás virág- és indamotívumai könnyű, jól omló viszkózon. Nyáron vállkendőnek, télen sálnak.',
    ritual: 'Kézzel, langyos vízben mosd, és árnyékban szárítsd.',
  }),
  P({
    id: 30301, sku: 'MND-HN-0001', images: ['ruhazat-to'], slug: 'harem-nadrag-pamut', name: 'Pamut hárem nadrág mandala mintával',
    cat: 'ruhazat-es-kiegeszitok', sub: 'nadragok', price: 9990, origin: 'india', place: 'Jaipur',
    art: 'pants', tone: 'maroon', intents: ['onkifejezes', 'csend'],
    specs: { 'Méret': 'Egy méret (S–L)', 'Anyag': '100% pamut', 'Derék': 'Gumírozott, zsinóros' },
    short: 'Laza, szellős szabás – jógához, utazáshoz, otthonra.',
    description: 'Könnyű pamutvászon, gumírozott derék és bokánál szűkülő szár. Jógához, meditációhoz és a nyári hétköznapokhoz egyaránt kényelmes.',
    ritual: '30 °C-on, kifordítva mosd; az első mosásnál festéket engedhet.',
  }),

  // — Ajándéktárgyak —
  P({
    id: 30401, sku: 'MND-RK-0900', slug: 'rez-kulacs-kalapalt', name: 'Kalapált réz kulacs – 900 ml',
    cat: 'ajandektargyak', sub: 'rez-kulacsok', price: 11900, origin: 'india', place: 'Moradabad',
    art: 'copper', tone: 'saffron', featured: true, isNew: true, intents: ['ajandek', 'otthon'],
    specs: { 'Űrtartalom': '900 ml', 'Anyag': '100% réz, belül bevonat nélkül', 'Zárás': 'Csavaros kupak' },
    short: 'Az ájurvédikus hagyomány szerint a rézedényben tárolt víz frissítő.',
    description: 'Kézzel kalapált tiszta réz kulacs. Az ájurvéda szerint a rézedényben állni hagyott víz kiegyensúlyozó hatású. Csak vizet tölts bele – savas italokat ne.',
    ritual: 'Este töltsd meg, és reggel, éhgyomorra idd meg. Citromos vízzel és sóval időnként tisztítsd.',
  }),
  P({
    id: 30402, sku: 'MND-TEA-0100', slug: 'himalajai-masala-chai', name: 'Himalájai masala chai teakeverék',
    cat: 'ajandektargyak', sub: 'teak', price: 3290, origin: 'india', place: 'Darjeeling',
    art: 'tea', tone: 'sage', isNew: true, intents: ['ajandek', 'otthon'],
    specs: { 'Tömeg': '100 g', 'Összetevők': 'Fekete tea, fahéj, kardamom, gyömbér, szegfűszeg' },
    short: 'Fűszeres fekete tea – tejjel, mézzel, ahogy Indiában isszák.',
    description: 'Darjeelingi fekete tea egész fűszerekkel. Tejjel felforralva, mézzel édesítve a legjobb.',
    ritual: 'Két teáskanálnyit forralj fel 2 dl vízzel és 1 dl tejjel, 4 percig főzd, majd szűrd le.',
  }),
  P({
    id: 30403, sku: 'MND-AJ-0001', slug: 'ajandekcsomag-elcsendesules', name: 'Ajándékcsomag – Elcsendesülés',
    cat: 'ajandektargyak', sub: 'teak', price: 19900, compare: 22570, origin: 'nepal', place: 'Nepál és India',
    art: 'gift', tone: 'maroon', featured: true, intents: ['ajandek', 'csend'],
    specs: { 'Tartalom': 'Tingsha, tibeti füstölő, füstölőtartó, masala chai', 'Csomagolás': 'Újrahasznosított dobozban, kézzel írt kártyával' },
    short: 'Összeállított csomag, díszdobozban – kézzel írt kártyával.',
    description: 'Kis rituálé egy dobozban: tingsha a kezdéshez és a lezáráshoz, tibeti füstölő, egyszerű tartó és egy doboz fűszeres tea. Kérésre a kártyára a te üzenetedet írjuk.',
    ritual: 'A megjegyzés rovatban add meg, mit írjunk a kártyára.',
  }),
];

export const ARTICLE_CATEGORIES = ['Hangszerek', 'Otthon és oltár', 'Szimbólumok', 'Rituálé'];

// Cikkek. A „content” tömb a bejegyzés-szerkesztő elemeit modellezi (iu/content → core blokkok).
export const ARTICLES = [
  {
    slug: 'csengo-tingsha-hangtal', date: '2026-09-18', art: 'tingsha', tone: 'saffron', image: 'hangtalak-studio',
    category: 'Hangszerek', minutes: 6, author: 'A Mandala csapata',
    title: 'Csengő, tingsha, hangtál: három hang, három különböző szerep',
    excerpt: 'Egyes buddhista hangszerek szertartást kísérnek, mások jelzést adnak – és akad olyan is, amelyhez téves eredettörténet társul.',
    content: [
      ['p', 'A buddhista hagyományban a hang nem háttérzaj, hanem a gyakorlás része. Mégsem mindegy, melyik hangszer mikor szól – és mit várhatunk tőle.'],
      ['h2', 'A csengő és a dordzse'],
      ['p', 'A **tibeti csengő (ghanta)** mindig párban jár a **dordzsével (vadzsra)**. A bal kézben tartott csengő a bölcsességet, a jobb kézben tartott dordzse az együttérzést jelképezi; a kettő együtt a megvilágosodás egységét fejezi ki.'],
      ['quote', 'A hang csak akkor tanít, ha hagyjuk teljesen elhalni.', 'Kolostori mondás'],
      ['h2', 'A tingsha: jelzés, nem zene'],
      ['p', 'A tingsha két kisméretű, zsinórral összekötött cintányér. Éles, tiszta hangja **jelzés**: kijelöli a meditáció kezdetét és végét, vagy visszahívja a figyelmet.'],
      ['ul', ['A kezdéskor: egyetlen megszólaltatás, majd csend.', 'A végén: kettő, hogy a figyelem visszatérjen a térbe.', 'Csoportban: a vezető jelzése a szakaszok között.']],
      ['figure', 'hangtalak-studio', 'Kézzel kovácsolt és öntött nepáli hangtálak filcborítású ütőkkel.'],
      ['h2', 'A hangtál – és egy téves eredettörténet'],
      ['p', 'A hangtál ma a hangfürdők ikonikus eszköze. A „tibeti hangtál” mint ősi szertartási hangszer története azonban nagyrészt a 20. század második felében született; a tálakat a Himalája térségében használati edényként is ismerték. Ettől még kiváló eszköz a figyelem összpontosítására.'],
      ['h3', 'Mit érdemes megnézni vásárláskor?'],
      ['table', ['Szempont', 'Mit jelent', 'Mire figyelj'], [
        ['Súly', 'A tál tömege grammban', 'Nehezebb tál mélyebb, hosszabb hangot ad'],
        ['Frekvencia', 'A mért alaphang (Hz)', 'Kérj mért értéket, ne becslést'],
        ['Lecsengés', 'Meddig hallható a hang', 'Jó tálnál 30–60 másodperc'],
      ]],
      ['h4', 'Röviden'],
      ['p', 'Csengő a szertartáshoz, tingsha a jelzéshez, hangtál az elmélyüléshez. Mindháromból tartunk nepáli műhelyekből származó darabokat.'],
      ['button', 'Hangtálak böngészése', 'termekek.html?cat=szakralis-targyak&sub=hangtalak'],
    ],
  },
  {
    slug: 'buddhista-oltar', date: '2026-09-11', art: 'buddha', tone: 'maroon', image: 'fustolok-csakra',
    category: 'Otthon és oltár', minutes: 5, author: 'A Mandala csapata',
    title: 'Mi kerül egy buddhista oltárra – és miért nem mindegy, hová?',
    excerpt: 'Szobrok, szent szövegek, víztálkák és füstölők: így áll össze egy hagyományos otthoni oltár rendje.',
    content: [
      ['p', 'Egy otthoni oltár nem díszlet, hanem emlékeztető: egy hely, ahová a figyelem újra és újra visszatérhet.'],
      ['h2', 'A középpont: test, beszéd, elme'],
      ['p', 'Középen Buddha-szobor áll, a szobor jobbján (a nézőtől balra) egy szent szöveg, a bal oldalán (a nézőtől jobbra) egy sztúpa vagy annak képe.'],
      ['h2', 'A felajánlások'],
      ['ul', ['Hét víztálka: reggel feltöltve, este kiürítve.', 'Mécses vagy gyertya – a bölcsesség fénye.', 'Füstölő és virág – a mulandóság emlékeztetői.']],
      ['h2', 'A hely'],
      ['p', 'Legyen tiszta és emelt: szemmagasságban vagy afölött, ne a padlón, ne a fürdőszobában. Nem kell nagynak lennie – egy polc is elég.'],
    ],
  },
  {
    slug: 'buddha-arca', date: '2026-09-04', art: 'buddha', tone: 'saffron',
    category: 'Szimbólumok', minutes: 7, author: 'A Mandala csapata',
    title: 'Miért ilyen Buddha arca? – 7 jelentéssel bíró apróság',
    excerpt: 'Miért hosszú Buddha füle, mi a homlokpont, és mit jelentenek a csigás hajfürtök? Hét részlet, amely segít eligazodni az ábrázolásokon.',
    content: [
      ['p', 'A Buddha-ábrázolások nem portrék, hanem jelképrendszerek. Minden részletnek jelentése van.'],
      ['ol', [
        '**Hosszú fülcimpák** – Sziddhártha herceg nehéz fülbevalókat viselt; lemondásakor levette őket.',
        '**Urna (homlokpont)** – a bölcsesség fénye a szemöldökök között.',
        '**Usnísa (koponyadudor)** – a megvilágosodott elme kiterjedésének jele.',
        '**Csigás hajfürtök** – a legenda szerint csigák védték a meditáló Buddha fejét a naptól.',
        '**Félig lehunyt szem** – egyszerre befelé és kifelé figyel.',
        '**Három vonal a nyakon** – a csengő hang és a tanítás jele.',
        '**Szelíd mosoly** – a belső nyugalom, amely nem függ a külső körülményektől.',
      ]],
    ],
  },
  {
    slug: 'hangtal-valasztas', date: '2026-08-28', art: 'bowl', tone: 'sage',
    category: 'Hangszerek', minutes: 5, author: 'A Mandala csapata',
    title: 'Hogyan válassz hangtálat? Súly, hang és felhangok',
    excerpt: 'Kisebb tál tisztább, magasabb hanggal – nagyobb tál mélyebb zengéssel. Egy rövid útmutató az első hangtál kiválasztásához.',
    content: [
      ['p', 'Az első hangtálnál a legfontosabb kérdés nem a csakra, hanem az, hogy mire használnád: egyéni meditációhoz, jógaóra végéhez vagy hangfürdőhöz.'],
      ['h2', 'Méret és súly'],
      ['p', 'A 400–600 grammos tálak jól kézben tarthatók, tiszta, közepes hangot adnak. A 700 gramm feletti tálakat inkább párnán szólaltatjuk meg; hangjuk mélyebb, a rezgés a testen is érezhető.'],
      ['h2', 'Hallgasd meg'],
      ['p', 'Minden tálnál mért frekvenciát adunk meg, és szívesen küldünk hangfelvételt is – írj nekünk a termékoldalon.'],
    ],
  },
  {
    slug: 'tibeti-vagy-indiai-fustolo', date: '2026-08-21', art: 'incense', tone: 'maroon',
    category: 'Rituálé', minutes: 4, author: 'A Mandala csapata',
    title: 'Tibeti vagy indiai füstölő? Két hagyomány, két illat',
    excerpt: 'Pálcával vagy anélkül, virágos vagy földes: miben különbözik a két leggyakoribb füstölőtípus, és melyik mikor jó választás.',
    content: [
      ['p', 'Az indiai füstölők többnyire bambuszpálcára sodort, édesebb, virágos illatkeverékek. A tibeti füstölők pálca nélküli, gyógynövényporból sodort rudak, visszafogottabb, földesebb illattal.'],
      ['h2', 'Mikor melyiket?'],
      ['ul', ['Esti elcsendesedéshez: tibeti, gyógynövényes.', 'Hangulatteremtéshez, vendégvárásra: indiai, virágos (pl. Nag Champa).', 'Kis térben: rövidebb égési idő, szellőztetés mellett.']],
    ],
  },
  {
    slug: 'rez-kulacs', date: '2026-08-14', art: 'copper', tone: 'saffron',
    category: 'Otthon és oltár', minutes: 3, author: 'A Mandala csapata',
    title: 'Réz kulacs a mindennapokban: mit érdemes tudni?',
    excerpt: 'Mit mond az ájurvéda a rézedényben tárolt vízről, és hogyan tisztítsd, hogy sokáig szép maradjon?',
    content: [
      ['p', 'Az ájurvédikus hagyományban a rézedényben állni hagyott víz kiegyensúlyozó hatású. A kulacsba csak vizet tölts – savas italt, tejet, forró folyadékot ne.'],
      ['h2', 'Tisztítás'],
      ['p', 'Hetente egyszer citromlével és kevés sóval dörzsöld át, majd öblítsd le. A természetes patina nem hiba, hanem a réz jellemzője.'],
    ],
  },
];

export const TESTIMONIALS = [
  {
    name: 'Kovács Nóra',
    text: 'Weboldaluk és átvevőhelyük egy kincses ládika. Rengeteg apró és hatalmas értéket rejt, de mégis a legnagyobb kincs a hozzáállásuk, szakértelmük, kedvességük. Ha kell, hagynak csendben válogatni, ha kell, segítenek rálelni arra, amit még magam sem tudtam szavakba önteni.',
  },
  {
    name: 'Edina',
    text: 'Gyors, megbízható szállítás, igényes és könnyen kezelhető webshop felület, a rendelt termék pedig kiváló minőségű, és még ajándékot is kaptam mellé. Köszönöm!',
  },
  {
    name: 'Nagy Erika',
    text: 'Korrekt, rugalmas bolt. Az eladók kedvesek, a rendelt réz csengők gyönyörűek. Köszönjük szépen.',
  },
];
