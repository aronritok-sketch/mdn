// Mandala kereső motor – DOM és függőség nélkül; a prototípus és a WordPress téma közös forrása
// (a téma build-je másolja: tools/build-theme.py). A kliens oldali termékindexen fut.
//
//  - magyar normalizálás (ékezet, kis-nagybetű) és könnyű szótövezés (hangtálak → hangtal),
//  - összetett szavak (a „tál” megtalálja a „hangtál”-t), gépelés közbeni előtag-egyezés,
//  - elírás-tűrés (Damerau–Levenshtein) a kínálat szókészletén, „Erre gondoltál?” javaslat,
//  - szinonimák (egyenértékű csoport: „a, b, c”; egyirányú: „a => b”),
//  - a kérdés értelmezése szűrőként: ár (10 000 Ft alatt), súly (500 g alatt), frekvencia (432 Hz),
//    hang (G#), akciós, raktáron – törölhető címkeként megjelenítve,
//  - súlyozott rangsor: cikkszám > név > kategória > tulajdonságok > leírás; raktáron lévő előre.

/* ---------------------------------------------------------------- szöveg */

const MARKS = /[̀-ͯ]/g;
export const normalize = (s = '') => String(s).toLowerCase().normalize('NFD').replace(MARKS, '');
const clean = (s) => normalize(s).replace(/[^a-z0-9#]+/g, ' ').trim();

const STOP = new Set(['a', 'az', 'es', 'egy', 'vagy', 'is', 'meg', 'de', 'the', 'and', 'for', 'with', 'of', 'to', 'in', 'on']);
// Leggyakoribb toldalékok (ékezet nélkül), a hosszabbak előbb. A tő legalább 3 betű marad.
const SUFFIXES = ['jaikat', 'jeiket', 'akkal', 'ekkel', 'okkal', 'okban', 'ekben', 'akban', 'okbol', 'ekbol', 'akbol', 'oknak', 'eknek', 'aknak',
  'okhoz', 'ekhez', 'akhoz', 'okrol', 'ekrol', 'akrol', 'okra', 'ekre', 'akra', 'okat', 'eket', 'akat', 'jait', 'jeit', 'kent', 'ait', 'eit',
  'ban', 'ben', 'bol', 'rol', 'tol', 'hoz', 'hez', 'nak', 'nek', 'val', 'vel', 'ert', 'ba', 'be', 'ra', 're', 'ig', 'at', 'et', 'ot', 'ok', 'ek', 'ak', 'ai', 'ei'];
export function stem(word) {
  if (word.length < 5 || /\d/.test(word)) return word;
  for (const s of SUFFIXES) {
    if (word.endsWith(s) && word.length - s.length >= 3) return word.slice(0, -s.length);
  }
  return word;
}
const tokens = (s) => clean(s).split(' ').filter(Boolean);

/** Optimális illesztésű Damerau–Levenshtein távolság, `max` fölött korán kilép. */
export function distance(a, b, max = 2) {
  if (Math.abs(a.length - b.length) > max) return max + 1;
  const d = Array.from({ length: a.length + 1 }, (_, i) => [i, ...Array(b.length).fill(0)]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++) {
    let best = Infinity;
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
      if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
      best = Math.min(best, d[i][j]);
    }
    if (best > max) return max + 1;
  }
  return d[a.length][b.length];
}
const maxTypos = (len) => (len < 4 ? 0 : len < 8 ? 1 : 2);

/* ---------------------------------------------------------------- szinonimák */

/** Alapértelmezett szinonimák (a WordPressben az admin felülírhatja / bővítheti). */
export const DEFAULT_SYNONYMS = `hangtál, tibeti tál, singing bowl, éneklő tál, hangterápiás tál, meditációs tál, bowl
füstölő, incense, füstölőpálcika, illatpálca, tömjén
füstölőtartó, füstölő tartó, incense holder
mala, japa mala, imafüzér, mala lánc, mala nyaklánc
szélcsengő, szélharang, wind chime
buddha, budha, buddha szobor
ganésa, ganesha, ganesa
imazászló, prayer flag, lung-ta, lungta
dordzse, dorje, vadzsra, vajra
tingsha, cintányér
réz kulacs, rézkulacs, réz palack, copper bottle
illóolaj, esszenciális olaj, aromaolaj, essential oil
csakra, chakra
nag champa, nagchampa
szantál, szantálfa, sandalwood
álomfogó, dreamcatcher
meditációs párna, zafu, meditation cushion
gyűrű, ring
sál, stóla, kendő, scarf
nadrág, pants
ruha, dress
tea, chai
hangfürdő => hangtál
jóga => meditációs párna`;

export function parseSynonyms(text = DEFAULT_SYNONYMS) {
  const rules = [];
  for (const raw of String(text).split('\n')) {
    const line = raw.trim();
    if (!line || line.startsWith('#')) continue;
    if (line.includes('=>')) {
      const [from, to] = line.split('=>').map((x) => x.split(',').map(clean).filter(Boolean));
      from.forEach((f) => rules.push({ from: f, to }));
    } else {
      const group = line.split(',').map(clean).filter(Boolean);
      group.forEach((f) => rules.push({ from: f, to: group.filter((g) => g !== f) }));
    }
  }
  return rules;
}

/* ---------------------------------------------------------------- kérdés értelmezése */

const NUM = (s) => Number(String(s).replace(/[\s.]/g, '').replace(',', '.'));
const fmtNum = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
const NOTES = { c: 'C', d: 'D', e: 'E', f: 'F', g: 'G', a: 'A', b: 'B', h: 'B' };

/**
 * A kérdésből kiemeli a szűrőként érthető részeket. Visszaad: { text (a maradék), filters[] }.
 * filter: { key, label, remove (az eredeti szövegrész), test(p) }
 */
export function interpret(q) {
  let text = ` ${q} `;
  const filters = [];
  const take = (re, fn) => {
    text = text.replace(re, (...m) => { const f = fn(...m); if (!f) return m[0]; filters.push({ ...f, remove: m[0].trim() }); return ' '; });
  };
  const lower = (s) => normalize(s);
  // Ár: „10 000 Ft alatt”, „5000-ig”, „max 8000 Ft”, „3000–6000 Ft”
  take(/\s(\d[\d\s.]*\d|\d+)\s*[-–]\s*(\d[\d\s.]*\d|\d+)\s*(?:ft|forint|huf)\b/gi, (_m, a, b) => ({ key: 'ar', label: `Ár: ${fmtNum(NUM(a))}–${fmtNum(NUM(b))} Ft`, test: (p) => p.price >= NUM(a) && p.price <= NUM(b) }));
  take(/\s(?:max(?:imum)?|legfeljebb)\s*(\d[\d\s.]*\d|\d+)\s*(?:ft|forint|huf)?(?=\s)/gi, (_m, a) => ({ key: 'ar', label: `Ár ≤ ${fmtNum(NUM(a))} Ft`, test: (p) => p.price <= NUM(a) }));
  take(/\s(\d[\d\s.]*\d|\d+)\s*(ft|forint|huf)?\s*[-–]?\s*(alatt|alatti|ig|-ig|ig\b)(?=\s)/gi, (_m, a, unit) => (unit || NUM(a) >= 1000 ? { key: 'ar', label: `Ár ≤ ${fmtNum(NUM(a))} Ft`, test: (p) => p.price <= NUM(a) } : null));
  take(/\s(\d[\d\s.]*\d|\d+)\s*(ft|forint|huf)\s*(felett|fölött|tól|től|-tól|-től)(?=\s)/gi, (_m, a) => ({ key: 'ar', label: `Ár ≥ ${fmtNum(NUM(a))} Ft`, test: (p) => p.price >= NUM(a) }));
  // Súly: „500 g alatt”, „1 kg felett”, „600 grammos”
  take(/\s(\d+(?:[.,]\d+)?)\s*(kg|g|gr|gramm|grammos|g-os|g-os)\s*(alatt|alatti|ig|-ig|felett|fölött|tól|től|körül|koruli)?(?=\s)/gi, (_m, a, unit, rel) => {
    const g = NUM(a) * (lower(unit) === 'kg' ? 1000 : 1);
    const r = lower(rel || '');
    if (/alatt|ig/.test(r)) return { key: 'suly', label: `Súly ≤ ${fmtNum(g)} g`, test: (p) => p.attrs?.suly != null && p.attrs.suly <= g };
    if (/felett|folott|tol/.test(r)) return { key: 'suly', label: `Súly ≥ ${fmtNum(g)} g`, test: (p) => p.attrs?.suly != null && p.attrs.suly >= g };
    return { key: 'suly', label: `Súly ≈ ${fmtNum(g)} g`, test: (p) => p.attrs?.suly != null && Math.abs(p.attrs.suly - g) <= Math.max(40, g * 0.15) };
  });
  // Frekvencia: „432 Hz”, „400 hz alatt”
  take(/\s(\d{2,4})\s*hz\s*(alatt|ig|felett|fölött|körül)?(?=\s)/gi, (_m, a, rel) => {
    const hz = NUM(a);
    const r = lower(rel || '');
    if (/alatt|ig/.test(r)) return { key: 'hz', label: `Frekvencia ≤ ${hz} Hz`, test: (p) => p.attrs?.hz != null && p.attrs.hz <= hz };
    if (/felett|folott/.test(r)) return { key: 'hz', label: `Frekvencia ≥ ${hz} Hz`, test: (p) => p.attrs?.hz != null && p.attrs.hz >= hz };
    return { key: 'hz', label: `Frekvencia ≈ ${hz} Hz`, test: (p) => p.attrs?.hz != null && Math.abs(p.attrs.hz - hz) <= 15 };
  });
  // Hang: „G#”, „C hang”, „Fisz” – nagybetűvel vagy a „hang” szóval (a névelő „a” ne legyen hang)
  take(/\s([A-Ha-h])(#|isz|is|-sharp)?(?:\s+(hang|hangu|hangú|hangra|hangon))?(?=\s)/g, (m, n, sharp, word) => {
    if (!(sharp || word || /[A-H]/.test(n))) return null;
    if (!sharp && !word && /^\s[A]$/.test(m) && /\s[a-zá-ű]/.test(text)) return null; // „A hangtál” névelő
    const note = NOTES[n.toLowerCase()] + (sharp ? '#' : '');
    const notesOf = (p) => [].concat(p.attrs?.hang ?? p.specs?.Hang ?? []).map((v) => String(v).toUpperCase().replace('-SHARP', '#'));
    return { key: 'hang', label: `Hang: ${note}`, test: (p) => notesOf(p).includes(note) };
  });
  take(/\s(akcios|akciós|leárazott|learazott|kedvezményes|kedvezmenyes)(?=\s)/gi, () => ({ key: 'akcio', label: 'Akciós', test: (p) => p.compare > p.price && !p.wholesale }));
  take(/\s(raktáron|raktaron|készleten|keszleten)(?=\s)/gi, () => ({ key: 'raktar', label: 'Raktáron', test: (p) => ['in', 'low'].includes(p.stock) }));
  return { text: text.replace(/\s+/g, ' ').trim(), filters };
}

/* ---------------------------------------------------------------- index */

const FIELDS = { name: 10, cat: 6, attrs: 4, intents: 3, short: 2 };

function docOf(p, cfg) {
  const catText = [p.catLabel, cfg.labels?.[p.cat], cfg.labels?.[p.sub]].filter(Boolean).join(' ');
  const attrText = [
    ...Object.entries(p.specs || {}).map(([, v]) => v),
    ...Object.values(p.attrs || {}).flat().filter((v) => typeof v === 'string'),
    p.originLabel || cfg.origins?.[p.origin] || '', p.region || '', p.place || '',
  ].join(' ');
  const intentText = (p.intents || []).map((i) => cfg.intents?.[i] || i).join(' ');
  const fields = {
    name: tokens(p.name).map(stem),
    cat: tokens(catText).map(stem),
    attrs: tokens(attrText).map(stem),
    intents: tokens(intentText).map(stem),
    short: tokens(p.short || '').map(stem),
  };
  const sku = normalize(p.sku || '').replace(/[^a-z0-9]/g, '');
  return { p, fields, sku, name: clean(p.name) };
}

/** Egy kifejezés (tő) illeszkedése egy mező tokenjeire: 0–1. */
function quality(term, list) {
  let best = 0;
  for (const t of list) {
    if (t === term) return 1;
    // előtag: minél nagyobb részét fedi a szónak, annál jobb (a „füstölő” előbb, mint a „füstölőtartó”)
    if (term.length >= 2 && t.startsWith(term)) best = Math.max(best, 0.85 * (0.8 + (0.2 * term.length) / t.length));
    else if (t.length >= 4 && term.startsWith(t)) best = Math.max(best, 0.7);
    else if (term.length >= 3 && t.includes(term)) best = Math.max(best, 0.5);
  }
  return best;
}

/* ---------------------------------------------------------------- motor */

export function createSearch(products, cfg = {}) {
  const docs = products.map((p) => docOf(p, cfg));
  const byId = new Map(docs.map((d) => [d.p.id, d]));
  const vocab = new Set();
  docs.forEach((d) => Object.values(d.fields).forEach((list) => list.forEach((t) => { if (t.length >= 3 && !/^\d+$/.test(t)) vocab.add(t); })));
  const vocabList = [...vocab];
  // Megjelenítéshez: tő → az eredeti (ékezetes) szó, ahogy a termékneveken / kategóriákon szerepel.
  const display = new Map();
  const addDisplay = (text) => String(text || '').toLowerCase().split(/[^\p{L}\p{N}#]+/u).forEach((w) => {
    const key = stem(clean(w));
    if (w && key && !display.has(key)) display.set(key, w);
  });
  products.forEach((p) => { addDisplay(p.name); addDisplay(p.catLabel); });
  Object.values(cfg.labels || {}).forEach(addDisplay);
  const shown = (t) => display.get(t) || t;
  const rules = parseSynonyms(cfg.synonyms ?? DEFAULT_SYNONYMS);
  const cats = (cfg.categories || []).flatMap((c) => [{ slug: c.slug, label: c.label, url: c.url, main: true }, ...(c.subs || []).map(([slug, label, url]) => ({ slug, label, url, parent: c.slug }))]);
  const cache = new Map();

  const known = (term) => vocab.has(term) || vocabList.some((v) => v.startsWith(term) || (term.length >= 3 && v.includes(term)));
  function correct(term) {
    const max = maxTypos(term.length);
    if (!max) return null;
    let best = null;
    for (const v of vocabList) {
      const dist = distance(term, v, max);
      if (dist <= max && (!best || dist < best.dist || (dist === best.dist && v.length < best.word.length))) best = { word: v, dist };
    }
    return best?.word || null;
  }

  /** A kérdés kifejezései alternatívákkal: [[{t, w}], …] */
  function plan(text) {
    const norm = clean(text);
    let words = norm.split(' ').filter(Boolean);
    if (words.some((w) => !STOP.has(w))) words = words.filter((w) => !STOP.has(w));
    const groups = words.map((w) => [{ t: stem(w), w: 1, raw: w }]);
    // Szinonimák: kifejezés-szinten (pl. „tibeti tál” → hangtál); az érintett szavak alternatívát kapnak.
    for (const r of rules) {
      const fromWords = r.from.split(' ');
      for (let i = 0; i + fromWords.length <= words.length; i++) {
        if (fromWords.every((fw, k) => words[i + k] === fw || stem(words[i + k]) === stem(fw))) {
          for (const to of r.to) {
            const toStems = to.split(' ').map(stem);
            // az első érintett szó kapja a teljes alternatívát, a többi „elnyelhető”
            const rid = `${r.from}>${to}@${i}`;
            groups[i].push({ t: toStems, w: 0.9, syn: true, rid });
            for (let k = 1; k < fromWords.length; k++) groups[i + k].push({ t: null, w: 1, absorbed: true, rid });
          }
        }
      }
    }
    // Elírás: ami sehol nem fordul elő, annak a legközelebbi szava is alternatíva.
    const corrections = [];
    groups.forEach((g) => {
      const base = g[0];
      if (base.t.length >= 4 && !/\d/.test(base.t) && !g.some((a) => a.syn) && !known(base.t)) {
        const fix = correct(base.t);
        if (fix) { g.push({ t: fix, w: 0.6, typo: true }); corrections.push([base.raw, shown(fix)]); }
      }
    });
    return { groups, corrections, words };
  }

  function scoreDoc(d, groups, phrase) {
    let score = 0;
    let matched = 0;
    const hitRules = new Set();
    for (const g of groups) {
      let best = 0;
      for (const alt of g) {
        // Többszavas szinonima további szavai: csak ha a termék a szinonimára illeszkedett.
        if (alt.absorbed) { if (hitRules.has(alt.rid)) best = Math.max(best, 0.001); continue; }
        const list = Array.isArray(alt.t) ? alt.t : [alt.t];
        let s = 0;
        for (const [field, weight] of Object.entries(FIELDS)) {
          const q = Math.min(...list.map((t) => quality(t, d.fields[field])));
          s = Math.max(s, q * weight);
        }
        // Cikkszám (kötőjel nélkül): részletre is.
        const raw = normalize(alt.raw || '').replace(/[^a-z0-9]/g, '');
        if (raw.length >= 3 && d.sku.includes(raw)) s = Math.max(s, d.sku === raw ? 50 : 14);
        if (alt.rid && s > 0) hitRules.add(alt.rid);
        best = Math.max(best, s * alt.w);
      }
      if (best > 0) matched++;
      score += best;
    }
    if (phrase.length > 3 && d.name.includes(phrase)) score += 8;
    if (phrase.length > 1 && d.name.startsWith(phrase)) score += 3;
    if (d.p.stock === 'out') score *= 0.7;
    return { score, matched };
  }

  /**
   * Keresés. Visszaad: { items (termékek relevancia szerint), scores (Map id→pont), total, partial,
   * filters (értelmezett szűrők), corrections [[eredeti, javított]], didYouMean, cats (javasolt kategóriák) }.
   */
  function search(q, { useFilters = true } = {}) {
    const key = `${q}|${useFilters}`;
    if (cache.has(key)) return cache.get(key);
    const { text, filters } = useFilters ? interpret(q) : { text: q, filters: [] };
    const { groups, corrections } = plan(text);
    const phrase = clean(text);
    const pool = filters.length ? docs.filter((d) => filters.every((f) => f.test(d.p))) : docs;
    let ranked = [];
    let partial = false;
    if (!groups.length) {
      ranked = filters.length ? pool.map((d) => ({ d, score: 1 })) : [];
    } else {
      const scored = pool.map((d) => ({ d, ...scoreDoc(d, groups, phrase) }));
      ranked = scored.filter((x) => x.matched === groups.length && x.score > 0);
      if (!ranked.length && groups.length > 1) {
        partial = true;
        const need = Math.ceil(groups.length / 2);
        ranked = scored.filter((x) => x.matched >= need && x.score > 0);
      }
    }
    // Ha a szűrők mindent kizártak, szűrők nélkül próbáljuk (és jelezzük).
    if (!ranked.length && filters.length && groups.length) {
      const loose = search(q, { useFilters: false });
      const out = { ...loose, filters: [], droppedFilters: filters };
      cache.set(key, out);
      return out;
    }
    ranked.sort((a, b) => b.score - a.score || (a.d.p.stock === 'out') - (b.d.p.stock === 'out'));
    // Ha van erős (névbeli) találat, a csak a leírásban gyengén illeszkedők kimaradnak.
    const topScore = ranked[0]?.score || 0;
    if (topScore >= 10) ranked = ranked.filter((x) => x.score >= topScore * 0.15);
    const items = ranked.map((x) => x.d.p);
    const scores = new Map(ranked.map((x) => [x.d.p.id, x.score]));
    // „Erre gondoltál?”: nincs (teljes) találat, de van javítás
    let didYouMean = null;
    if ((!items.length || partial) && groups.length) {
      const fixed = clean(text).split(' ').map((w) => {
        const s = stem(w);
        if (known(s)) return w;
        const fix = correct(s);
        return fix ? shown(fix) : w;
      }).join(' ');
      if (clean(fixed) !== clean(text)) didYouMean = fixed;
    }
    // Kategória javaslat: a találatok leggyakoribb alkategóriái + a nevükben illeszkedők
    const count = new Map();
    items.slice(0, 40).forEach((p) => { if (p.sub) count.set(p.sub, (count.get(p.sub) || 0) + 1); });
    const catHits = cats.filter((c) => groups.length && groups.every((g) => g.some((alt) => !alt.absorbed && (Array.isArray(alt.t) ? alt.t : [alt.t]).every((t) => quality(t, tokens(c.label).map(stem)) > 0))));
    const top40 = Math.min(items.length, 40);
    const frequent = [...count.entries()].filter(([, n]) => n >= 2 || n / top40 >= 0.25).sort((a, b) => b[1] - a[1]);
    const suggested = [...new Map([...catHits.map((c) => [c.slug, c]), ...frequent.map(([slug]) => [slug, cats.find((c) => c.slug === slug)]).filter(([, c]) => c)]).values()].slice(0, 4)
      .map((c) => ({ ...c, count: items.filter((p) => p.sub === c.slug || p.cat === c.slug).length }));
    const out = { items, scores, total: items.length, partial, filters, corrections, didYouMean, cats: suggested, terms: groups.flatMap((g) => g.filter((a) => !a.absorbed).flatMap((a) => (Array.isArray(a.t) ? a.t : [a.t]))) };
    if (cache.size > 200) cache.clear();
    cache.set(key, out);
    return out;
  }

  /** Kiemelés: a találatban szereplő tövek <mark>-kal (HTML-t ad, a szöveget escape-eli). */
  function highlight(text, q) {
    const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const terms = (typeof q === 'string' ? search(q).terms : q).filter((t) => t && t.length >= 2);
    if (!terms.length) return esc(text);
    // Normalizált szöveg karakterenkénti visszaképezéssel.
    let n = '';
    const map = [];
    [...text].forEach((ch, i) => { const c = normalize(ch); for (let k = 0; k < c.length; k++) { n += c[k]; map.push(i); } });
    const chars = [...text];
    const on = new Array(chars.length).fill(false);
    for (const t of terms) {
      let from = 0;
      let at;
      while ((at = n.indexOf(t, from)) >= 0) { for (let k = at; k < at + t.length; k++) on[map[k]] = true; from = at + t.length; }
    }
    let out = '';
    let open = false;
    chars.forEach((ch, i) => {
      if (on[i] && !open) { out += '<mark>'; open = true; }
      if (!on[i] && open) { out += '</mark>'; open = false; }
      out += esc(ch);
    });
    return open ? `${out}</mark>` : out;
  }

  return { search, highlight, byId, size: docs.length };
}

/* ---------------------------------------------------------------- közös példány */

// A termékindex betöltésekor egyszer épül fel (loadProducts → setCorpus); a szűrő és a
// kereső ugyanazt használja.
let corpus = null;
let corpusCfg = {};
export function setCorpus(products, cfg = corpusCfg) {
  corpusCfg = cfg;
  corpus = createSearch(products, cfg);
  return corpus;
}
export const getCorpus = () => corpus;
/** A szűrő kereséséhez: id → pontszám (üres Map, ha nincs korpusz). */
export function queryScores(q) {
  return corpus ? corpus.search(q).scores : new Map();
}
