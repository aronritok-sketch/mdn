// Mandala szűrőrendszer – konfiguráció és motor (saját blokk: mandala/filter).
// Logika: csoporton belül VAGY (kivéve „and” módú csoport), csoportok között ÉS.
// Egy csoport darabszámai a többi csoport szűrésével, a saját csoport nélkül számolódnak
// (diszjunktív facet), így mindig azt mutatják, hány találat lesz a kattintás után.
import { INTENTS } from './data.js';
import { norm } from './store.js';

export const CHAKRAS = [
  ['gyoker', 'Gyökér', '#B03A2E'], ['szakralis', 'Szakrális', '#D9751E'], ['napfonat', 'Napfonat', '#D8A106'],
  ['sziv', 'Szív', '#3E8E4F'], ['torok', 'Torok', '#3A78B5'], ['homlok', 'Homlok', '#4B3F9E'],
  ['korona', 'Korona', '#8E4FA8'], ['het', 'Mind a hét', 'conic-gradient(#B03A2E, #D9751E, #D8A106, #3E8E4F, #3A78B5, #4B3F9E, #8E4FA8, #B03A2E)'],
];
const COLORS = {
  arany: '#C9A24A', ezüst: '#BFC3C7', bronz: '#9C6B30', réz: '#B8693D', fehér: '#FFFFFF', fekete: '#1C1916', bordó: '#6B2A2A',
  terrakotta: '#C2663F', indigó: '#34506A', zöld: '#4B5C43', természetes: '#D9C9A8',
  többszínű: 'conic-gradient(#B03A2E, #D8A106, #3E8E4F, #3A78B5, #8E4FA8, #B03A2E)',
};
const NOTES = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'Egy méret'];
const BOWLS = { subs: ['hangtalak'] };
const one = (v) => (v ? [v] : []);

/**
 * Szűrőcsoportok. type: check | chips | chakra | swatch | range.
 * scope: csak akkor jelenik meg, ha az aktuális kategória/alkategória illeszkedik (vagy ha aktív).
 * options: fix sorrend; ha nincs, a termékekből gyűlik (darabszám szerint).
 */
export const FACETS = [
  { key: 'szandek', label: 'Szándék', type: 'check', values: (p) => p.intents, options: INTENTS.map((i) => [i.id, i.label]) },
  { key: 'ar', label: 'Ár', type: 'range', unit: 'Ft', step: 500, value: (p) => p.price },
  { key: 'hang', label: 'Hang', type: 'chips', scope: BOWLS, values: (p) => one(p.attrs.hang), options: NOTES.map((n) => [n, n]), hint: 'A tál mért alaphangja' },
  { key: 'hz', label: 'Frekvencia', type: 'range', unit: 'Hz', step: 1, scope: BOWLS, value: (p) => p.attrs.hz },
  { key: 'suly', label: 'Súly', type: 'range', unit: 'g', step: 10, scope: BOWLS, value: (p) => p.attrs.suly, hint: 'Könnyebb tál: tisztább, magasabb hang; nehezebb: mélyebb zengés' },
  { key: 'csakra', label: 'Csakra', type: 'chakra', scope: { subs: ['hangtalak', 'mala-lancok', 'ekszerek'] }, values: (p) => p.attrs.csakra || [], options: CHAKRAS.map(([id, l]) => [id, l]) },
  { key: 'keszites', label: 'Készítés', type: 'check', scope: BOWLS, values: (p) => one(p.attrs.keszites), options: [['kovacsolt', 'Kézzel kovácsolt'], ['ontott', 'Öntött'], ['gepi', 'Gépi']] },
  { key: 'illat', label: 'Illat', type: 'check', scope: { subs: ['fustolok'] }, values: (p) => p.attrs.illat || [] },
  { key: 'forma', label: 'Füstölő típusa', type: 'check', scope: { subs: ['fustolok'] }, values: (p) => one(p.attrs.forma) },
  { key: 'meret', label: 'Méret', type: 'chips', scope: { cats: ['ruhazat-es-kiegeszitok'] }, values: (p) => p.attrs.meret || [], options: SIZES.map((s) => [s, s]) },
  { key: 'eredet', label: 'Eredet', type: 'check', values: (p) => one(p.origin), options: [['nepal', 'Nepál'], ['india', 'India']] },
  { key: 'regio', label: 'Műhely, régió', type: 'check', values: (p) => one(p.region) },
  { key: 'anyag', label: 'Anyag', type: 'check', values: (p) => p.attrs.anyag || [] },
  { key: 'szin', label: 'Szín', type: 'swatch', values: (p) => p.attrs.szin || [], colors: COLORS },
  { key: 'allapot', label: 'Elérhetőség és ajánlat', type: 'check', mode: 'and',
    values: (p) => [p.stock !== 'out' && 'raktaron', p.compare > p.price && 'akcios', p.isNew && 'uj'].filter(Boolean),
    options: [['raktaron', 'Raktáron'], ['akcios', 'Akciós'], ['uj', 'Újdonság']] },
];
export const facetByKey = Object.fromEntries(FACETS.map((f) => [f.key, f]));

/** Egységes érték-azonosító az URL-hez (a „G#” hangjegy változatlan marad). */
export const slug = (v) => (/^[A-G]#?$/.test(v) || /^[A-Z]{1,2}$/.test(v) ? v : norm(String(v)).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''));
const valuesOf = (f, p) => (f.type === 'range' ? [] : f.values(p).map(slug));

// ---------- Állapot ----------
export function emptyState() {
  return { cat: '', sub: '', q: '', orderby: 'menu_order', sel: {} };
}
/** URL → állapot. A régi paraméterneveket (intent, origin, max, sale, instock) is elfogadja. */
export function parseState(params) {
  const s = emptyState();
  s.cat = params.get('cat') || '';
  s.sub = params.get('sub') || '';
  s.q = params.get('q') || '';
  s.orderby = params.get('orderby') || 'menu_order';
  for (const f of FACETS) {
    const raw = params.get(f.key);
    if (!raw) continue;
    if (f.type === 'range') {
      const [a, b] = raw.split('-').map(Number);
      if (Number.isFinite(a) && Number.isFinite(b)) s.sel[f.key] = [a, b];
    } else s.sel[f.key] = raw.split(',').filter(Boolean);
  }
  const add = (k, v) => { s.sel[k] = [...new Set([...(s.sel[k] || []), v])]; };
  if (params.get('intent')) add('szandek', params.get('intent'));
  if (params.get('origin')) params.get('origin').split(',').forEach((o) => add('eredet', o));
  if (params.get('max')) s.sel.ar = [0, Number(params.get('max'))];
  if (params.get('sale') === '1') add('allapot', 'akcios');
  if (params.get('instock') === '1') add('allapot', 'raktaron');
  return s;
}
export function serialize(s) {
  const u = new URLSearchParams();
  if (s.cat) u.set('cat', s.cat);
  if (s.sub) u.set('sub', s.sub);
  if (s.q) u.set('q', s.q);
  for (const f of FACETS) {
    const v = s.sel[f.key];
    if (!v || (Array.isArray(v) && !v.length)) continue;
    u.set(f.key, f.type === 'range' ? `${v[0]}-${v[1]}` : v.join(','));
  }
  if (s.orderby !== 'menu_order') u.set('orderby', s.orderby);
  return u.toString();
}
export const activeCount = (s) => Object.values(s.sel).reduce((n, v) => n + (Array.isArray(v[0]) ? 0 : (typeof v[0] === 'number' ? 1 : v.length)), 0) + (s.q ? 1 : 0);

// ---------- Szűrés ----------
function matchBase(p, s, { skipCat = false } = {}) {
  if (!skipCat && s.cat && p.cat !== s.cat) return false;
  if (!skipCat && s.sub && p.sub !== s.sub) return false;
  if (s.q) {
    const hay = norm(`${p.name} ${p.sku} ${p.short} ${Object.values(p.specs || {}).join(' ')} ${(p.attrs.anyag || []).join(' ')}`);
    if (!norm(s.q).split(/\s+/).every((w) => hay.includes(w))) return false;
  }
  return true;
}
function matchFacet(p, f, sel) {
  if (!sel || !sel.length) return true;
  if (f.type === 'range') { const v = f.value(p); return v != null && v >= sel[0] && v <= sel[1]; }
  const vals = valuesOf(f, p);
  return f.mode === 'and' ? sel.every((x) => vals.includes(x)) : sel.some((x) => vals.includes(x));
}
/** Találatok; `except` csoport kihagyásával (a darabszámokhoz). */
export function filterProducts(products, s, except = '') {
  return products.filter((p) => matchBase(p, s, { skipCat: except === 'cat' }) && FACETS.every((f) => f.key === except || matchFacet(p, f, s.sel[f.key])));
}

/** Látható-e a csoport az adott kontextusban. */
export function isVisible(f, s) {
  if (s.sel[f.key]?.length) return true;
  if (!f.scope) return true;
  return (f.scope.subs || []).includes(s.sub) || (!!s.cat && (f.scope.cats || []).includes(s.cat));
}

/** Egy csoport opciói darabszámmal: [{ id, label, count, selected }]. */
export function facetOptions(products, s, f) {
  const base = filterProducts(products, s, f.key);
  const ctx = products.filter((p) => matchBase(p, s));
  const counts = new Map();
  for (const p of base) for (const v of new Set(valuesOf(f, p))) counts.set(v, (counts.get(v) || 0) + 1);
  let opts;
  if (f.options) opts = f.options.map(([id, label]) => ({ id: slug(id), label }));
  else {
    const labels = new Map();
    for (const p of ctx) for (const v of f.values(p)) labels.set(slug(v), v);
    opts = [...labels].map(([id, label]) => ({ id, label: label.charAt(0).toUpperCase() + label.slice(1) }));
  }
  // Csak azok az opciók, amelyek a kontextusban (kategória + keresés) léteznek, vagy ki vannak jelölve
  const inCtx = new Set(ctx.flatMap((p) => valuesOf(f, p)));
  const sel = s.sel[f.key] || [];
  opts = opts.filter((o) => inCtx.has(o.id) || sel.includes(o.id)).map((o) => ({ ...o, count: counts.get(o.id) || 0, selected: sel.includes(o.id) }));
  if (!f.options) opts.sort((a, b) => b.count - a.count || a.label.localeCompare(b.label, 'hu'));
  if (f.colors) opts.forEach((o) => { o.color = f.colors[o.label.toLowerCase()] || '#ccc'; });
  return opts;
}

/** Tartomány: határok (a kategória-kontextusból, hogy a csúszka ne ugráljon) és hisztogram. */
export function rangeInfo(products, s, f, buckets = 12) {
  const ctxVals = products.filter((p) => matchBase(p, s)).map(f.value).filter((v) => v != null);
  if (!ctxVals.length) return null;
  const lo = Math.floor(Math.min(...ctxVals) / f.step) * f.step;
  const hi = Math.ceil(Math.max(...ctxVals) / f.step) * f.step;
  const baseVals = filterProducts(products, s, f.key).map(f.value).filter((v) => v != null);
  const width = (hi - lo) / buckets || 1;
  const hist = Array(buckets).fill(0);
  baseVals.forEach((v) => { hist[Math.min(buckets - 1, Math.floor((v - lo) / width))]++; });
  const sel = s.sel[f.key];
  return { lo, hi, min: sel ? Math.max(lo, sel[0]) : lo, max: sel ? Math.min(hi, sel[1]) : hi, hist, width, active: !!sel };
}

/** Kategóriák darabszámmal (a kategóriaszűrő nélkül számolva). */
export function categoryCounts(products, s) {
  // Kategóriaváltáskor a célkategóriában nem értelmezett (scope-os) szűrők törlődnek,
  // ezért a darabszámot is azok nélkül számoljuk – így nincs félrevezető 0.
  const stateFor = (cat, sub) => {
    const next = { ...s, cat, sub, sel: { ...s.sel } };
    FACETS.forEach((f) => { if (f.scope && !isVisible(f, { ...next, sel: {} })) delete next.sel[f.key]; });
    return next;
  };
  const n = (cat, sub) => filterProducts(products, stateFor(cat, sub)).length;
  return { all: n('', ''), cat: (c) => n(c, ''), sub: (c, sb) => n(c, sb) };
}

/** Üres találatnál: melyik szűrő elhagyásával lesz a legtöbb találat. */
export function relaxSuggestions(products, s) {
  const out = [];
  const tryState = (label, next) => { const n = filterProducts(products, next).length; if (n) out.push({ label, next, n }); };
  for (const [k, v] of Object.entries(s.sel)) {
    if (!v?.length) continue;
    const f = facetByKey[k];
    const next = { ...s, sel: { ...s.sel } };
    delete next.sel[k];
    tryState(f.type === 'range' ? `${f.label} tartomány` : `${f.label}: ${v.length} érték`, next);
  }
  if (s.q) tryState(`keresés: „${s.q}”`, { ...s, q: '' });
  if (s.sub) tryState('alkategória', { ...s, sub: '' });
  return out.sort((a, b) => b.n - a.n).slice(0, 3);
}

/** Gyors szűrések (kontextusfüggő). */
export const PRESETS = [
  { id: 'kezdo', label: 'Első hangtálnak (300–600 g)', when: (s) => s.sub === 'hangtalak', sel: { suly: [300, 600] } },
  { id: 'mely', label: 'Mély zengés (700 g felett)', when: (s) => s.sub === 'hangtalak', sel: { suly: [700, 2000] } },
  { id: 'kovacsolt', label: 'Kézzel kovácsolt', when: (s) => s.sub === 'hangtalak', sel: { keszites: ['kovacsolt'] } },
  { id: 'torok', label: 'Torokcsakra', when: (s) => s.sub === 'hangtalak', sel: { csakra: ['torok'] } },
  { id: 'fold', label: 'Földes, tibeti illat', when: (s) => s.sub === 'fustolok', sel: { illat: ['foldes'] } },
  { id: 'ajandek10', label: 'Ajándék 10 000 Ft alatt', when: () => true, sel: { szandek: ['ajandek'], ar: [0, 10000] } },
  { id: 'nepal', label: 'Nepáli kézműves', when: (s) => !s.sel.eredet, sel: { eredet: ['nepal'] } },
  { id: 'akcio', label: 'Akciós', when: () => true, sel: { allapot: ['akcios'] } },
  { id: 'raktar', label: 'Csak raktáron', when: () => true, sel: { allapot: ['raktaron'] } },
];
export const presetActive = (s, pr) => Object.entries(pr.sel).every(([k, v]) => {
  const cur = s.sel[k];
  if (!cur) return false;
  return typeof v[0] === 'number' ? cur[0] === v[0] && cur[1] === v[1] : v.every((x) => cur.includes(x));
});
export function togglePreset(s, pr) {
  const next = { ...s, sel: { ...s.sel } };
  const on = presetActive(s, pr);
  for (const [k, v] of Object.entries(pr.sel)) {
    if (typeof v[0] === 'number') { if (on) delete next.sel[k]; else next.sel[k] = [...v]; }
    else {
      const cur = new Set(next.sel[k] || []);
      v.forEach((x) => (on ? cur.delete(x) : cur.add(x)));
      if (cur.size) next.sel[k] = [...cur]; else delete next.sel[k];
    }
  }
  return next;
}
