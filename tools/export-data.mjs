// A prototípus adatait (data.js, icons.js, art.js) a child téma számára exportálja.
// Hívja: tools/build-theme.py  →  node tools/export-data.mjs <célmappa>
import { writeFileSync, mkdirSync } from 'fs';
import { join } from 'path';
import * as data from '../assets/js/data.js';
import { ICON_NAMES, icon } from '../assets/js/icons.js';
import { art, TONES, logoMark, mandala } from '../assets/js/art.js';

const out = process.argv[2];
mkdirSync(join(out, 'setup/data'), { recursive: true });
mkdirSync(join(out, 'assets/art'), { recursive: true });
const json = (name, value) => writeFileSync(join(out, 'setup/data', `${name}.json`), JSON.stringify(value, null, 1));

// Ikonok: csak a <svg> belseje, a PHP (mandala_icon) teszi köré a keretet.
json('icons', Object.fromEntries(ICON_NAMES.map((n) => [n, icon(n).replace(/^<svg[^>]*>/, '').replace(/<\/svg>$/, '')])));

// Illusztrációk: minden (rajz, tónus) pár, amit termék, kategória vagy szándék használ.
const pairs = new Set(['bowl|sand']);
data.PRODUCTS.forEach((p) => pairs.add(`${p.art}|${p.tone}`));
data.CATEGORIES.forEach((c) => pairs.add(`${c.art}|sand`));
data.INTENTS.forEach((i) => Object.keys(TONES).forEach((t) => pairs.add(`${i.art}|${t}`)));
for (const pair of pairs) {
  const [key, tone] = pair.split('|');
  // Önálló fájlban a presentation attribútum nem ért var()-t: a háttérszín konkrét értéket kap.
  const svg = art(key, tone).replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg"').replace('fill="var(--art-bg)"', `fill="${(TONES[tone] || TONES.sand).bg}"`);
  writeFileSync(join(out, 'assets/art', `${key}-${tone}.svg`), svg);
}
writeFileSync(join(out, 'assets/art/logo-mark.svg'), logoMark.replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg"'));
writeFileSync(join(out, 'assets/art/mandala.svg'), mandala({ petals: 24, rings: 5 }).replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg"'));

// Alkategória → alapértelmezett rajz (ha egy terméknek nincs képe és _mandala_art mezője).
const artBySub = {};
data.PRODUCTS.forEach((p) => { if (p.sub && !artBySub[p.sub]) artBySub[p.sub] = p.art; });

const { CONFIG } = data;
json('config', {
  vatRate: CONFIG.vatRate,
  freeShippingFrom: CONFIG.freeShippingFrom,
  contact: CONFIG.contact,
  bank: CONFIG.bank,
  shipping: CONFIG.shipping,
  payment: CONFIG.payment,
  coupons: CONFIG.coupons,
  artBySub,
});
json('catalog', {
  categories: data.CATEGORIES,
  intents: data.INTENTS,
  origins: data.ORIGINS,
  testimonials: data.TESTIMONIALS,
});
json('products', data.PRODUCTS.map((p) => ({ ...p })));
json('articles', { categories: data.ARTICLE_CATEGORIES, articles: data.ARTICLES });
console.log(`adatok: ${data.PRODUCTS.length} termék, ${data.ARTICLES.length} cikk, ${pairs.size} illusztráció, ${ICON_NAMES.length} ikon`);
