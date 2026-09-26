// A keresőmotor (assets/js/search-engine.js) egységtesztje a bemutató termékadatokkal.
// Futtatás: node tests/search.mjs
import { readFileSync } from 'fs';
import { createSearch, interpret, stem, distance, parseSynonyms } from '../assets/js/search-engine.js';

const catalog = JSON.parse(readFileSync(new URL('../wp-theme/mandala/setup/data/catalog.json', import.meta.url)));
const products = JSON.parse(readFileSync(new URL('../wp-theme/mandala/setup/data/products.json', import.meta.url)));
const labels = {};
catalog.categories.forEach((c) => { labels[c.slug] = c.label; c.subs.forEach(([s, l]) => { labels[s] = l; }); });
const rows = products.map((p) => ({ ...p, catLabel: labels[p.sub] || labels[p.cat] }));
const engine = createSearch(rows, {
  labels, intents: Object.fromEntries(catalog.intents.map((i) => [i.id, i.label])),
  origins: Object.fromEntries(Object.entries(catalog.origins).map(([k, v]) => [k, v.label])),
  categories: catalog.categories.map((c) => ({ slug: c.slug, label: c.label, url: '#', subs: c.subs.map(([s, l]) => [s, l, '#']) })),
});

let fails = 0;
const ok = (cond, label, extra = '') => { console.log(`${cond ? '✓' : '✗'} ${label}${extra ? ` – ${extra}` : ''}`); if (!cond) fails++; };
const top = (q, n = 3) => engine.search(q).items.slice(0, n).map((p) => p.sku);
const all = (q) => engine.search(q).items;
const subs = (q) => new Set(all(q).map((p) => p.sub));

ok(stem('hangtalak') === 'hangtal' && stem('fustolok') === 'fustol' && stem('hangtalat') === 'hangtal', 'szótő: hangtálak / füstölők / hangtálat');
ok(distance('hantal', 'hangtal') === 1 && distance('budha', 'buddha') === 1 && distance('abcd', 'wxyz', 2) > 2, 'elírás-távolság');
ok(parseSynonyms('a, b\nc => d').length === 3, 'szinonima szabályok (csoport és egyirányú)');

ok(all('hangtál').length === 8 && subs('hangtál').size === 1, 'hangtál: mind a 8 hangtál, csak hangtál', String(all('hangtál').length));
ok(all('hangtálak').length === 8 && all('hangtálat').length === 8, 'ragozott alak: hangtálak, hangtálat');
ok(all('füstölők').some((p) => p.sub === 'fustolok') && all('füstölő').length === all('füstölők').length, 'füstölő = füstölők');
ok(subs('tál').has('hangtalak'), 'összetett szó: „tál” → hangtál');
ok(top('hantál', 1)[0]?.startsWith('MND-HT') && engine.search('hantál').corrections.length === 1, 'elírás: „hantál” → hangtál, javítás jelezve');
ok(all('budha szobor')[0]?.sku === 'MND-BS-0020', 'elírás: „budha szobor” → Buddha szobor');
ok(subs('singing bowl').has('hangtalak') && subs('tibeti tál').has('hangtalak'), 'szinonima: singing bowl, tibeti tál → hangtál');
ok(!all('tibeti tál').some((p) => p.sku === 'MND-CS-0018'), 'szinonima kifejezés: a „Tibeti csengő” nem találat a „tibeti tál”-ra');
ok(all('tömjén').some((p) => p.sub === 'fustolok'), 'egyirányú / csoport szinonima: tömjén → füstölő');
ok(top('MND-HT-0490', 1)[0] === 'MND-HT-0490', 'pontos cikkszám elsőként');
ok(top('0490', 1)[0] === 'MND-HT-0490' && top('ht0560', 1)[0] === 'MND-HT-0560', 'cikkszám részlet (0490, ht0560)');
ok(all('szívcsakra').some((p) => p.sku === 'MND-HT-0650'), 'szívcsakra → a szívcsakrás hangtál');
ok(all('réz').length >= 3 && all('réz').every((p) => /réz|Réz/i.test(`${p.name} ${JSON.stringify(p.specs)} ${JSON.stringify(p.attrs)}`)), 'réz: csak réz termékek');

let r = engine.search('hangtál 500 g alatt');
ok(r.filters[0]?.label === 'Súly ≤ 500 g' && r.items.length > 0 && r.items.every((p) => p.attrs.suly <= 500 && p.sub === 'hangtalak'), 'értelmezés: „hangtál 500 g alatt”', r.items.map((p) => p.attrs.suly).join(','));
r = engine.search('432 hz');
ok(r.filters[0]?.label === 'Frekvencia ≈ 432 Hz' && r.items.every((p) => Math.abs(p.attrs.hz - 432) <= 15) && r.items.length >= 1, 'értelmezés: „432 Hz”', r.items.map((p) => p.attrs.hz).join(','));
r = engine.search('G# hangtál');
ok(r.filters[0]?.label === 'Hang: G#' && r.items.length === 1 && r.items[0].sku === 'MND-HT-0490', 'értelmezés: „G# hangtál”');
r = engine.search('ajándék 10 000 Ft alatt');
ok(r.filters[0]?.label === 'Ár ≤ 10 000 Ft' && r.items.length > 0 && r.items.every((p) => p.price <= 10000), 'értelmezés: „ajándék 10 000 Ft alatt”', String(r.items.length));
ok(interpret('A hangtál').filters.length === 0, 'értelmezés: a névelő „A” nem hang');
r = engine.search('hangtál 50 g alatt');
ok(r.items.length > 0 && r.droppedFilters?.length === 1, 'ha a szűrő mindent kizár: szűrő nélküli találatok, jelezve');
r = engine.search('xqzv');
ok(r.items.length === 0 && r.didYouMean === null, 'értelmetlen szó: nincs találat, nincs hamis javaslat');
r = engine.search('hangtál sál');
ok(r.partial && r.items.length > 0, 'nincs mindkettőt tartalmazó termék → részleges találatok, jelezve');
ok(engine.search('hangtál füstölő').items[0]?.sku === 'MND-FT-0030', 'mindkét szó egy termékben (a leírásban) → teljes találat');
r = engine.search('hantál');
ok(r.cats.some((c) => c.slug === 'hangtalak'), 'kategória javaslat: Hangtálak');
ok(engine.highlight('Hét fémből öntött mintás hangtál', 'hangtálak') === 'Hét fémből öntött mintás <mark>hangtál</mark>', 'kiemelés ékezetes szövegben', engine.highlight('Hét fémből öntött mintás hangtál', 'hangtálak'));
const t0 = performance.now();
const big = createSearch(Array.from({ length: 100 }, (_, k) => rows.map((p) => ({ ...p, id: p.id * 1000 + k }))).flat());
const t1 = performance.now();
['hangtál', 'hantál 500 g alatt', 'budha', 'mnd-ht', 'füstölő lótusz'].forEach((q) => big.search(q));
const t2 = performance.now();
ok(t1 - t0 < 1500 && (t2 - t1) / 5 < 250, `3300 termék: index ${Math.round(t1 - t0)} ms, keresés átlag ${Math.round((t2 - t1) / 5)} ms`);

console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
