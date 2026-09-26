// Kínálat oldal – mandala/filter (saját szűrőblokk) + [products class="mainquery"].
// Az állapot az URL-ben él: megosztható, a vissza gomb visszalépteti a szűrést.
import { initPage, productCard, $, $$, esc, icon, params, setMeta, refreshReveal } from '../ui.js';
import { CATEGORIES, INTENTS } from '../data.js';
import { categoryBySlug, fmt, fmtNum, loadProducts, subLabel, storage } from '../store.js';
import { FACETS, facetByKey, parseState, serialize, filterProducts, facetOptions, rangeInfo, categoryCounts, isVisible, relaxSuggestions, PRESETS, presetActive, togglePreset, activeCount, CHAKRAS } from '../facets.js';

initPage({ active: params().get('orderby') === 'date' ? '' : 'shop' });

const PER_PAGE = 9;
const OPEN_KEY = 'mandala.filter.open.v1';
const products = await loadProducts();
let state = parseState(params());
if (state.sub && !state.cat) state.cat = CATEGORIES.find((c) => c.subs.some(([s]) => s === state.sub))?.slug || '';
let shown = PER_PAGE;
const openGroups = new Set(storage.get(OPEN_KEY, ['kategoria', 'szandek', 'ar', 'hang', 'suly', 'csakra', 'illat', 'meret']));

const SORT = {
  menu_order: (a, b) => (a.stock === 'out') - (b.stock === 'out') || (b.featured ? 1 : 0) - (a.featured ? 1 : 0),
  date: (a, b) => (b.isNew ? 1 : 0) - (a.isNew ? 1 : 0) || b.id - a.id,
  popularity: (a, b) => (b.featured ? 1 : 0) - (a.featured ? 1 : 0) || a.price - b.price,
  price: (a, b) => a.price - b.price,
  'price-desc': (a, b) => b.price - a.price,
  'weight-asc': (a, b) => (a.attrs.suly || 1e9) - (b.attrs.suly || 1e9),
};
const unitFmt = (f, v) => (f.unit === 'Ft' ? fmt(v) : `${fmtNum(v)} ${f.unit}`);

// ---------- Fej, gyors szűrések, chipek ----------
function heading() {
  const cat = categoryBySlug(state.cat);
  const intent = state.sel.szandek?.length === 1 && !cat ? INTENTS.find((i) => i.id === state.sel.szandek[0]) : null;
  let title = 'Teljes kínálat';
  let lead = 'Hangtálak, füstölők, szobrok, textilek és ajándékok – Nepál és India műhelyeiből.';
  if (state.q && !cat) { title = `Keresés: „${state.q}”`; lead = ''; }
  else if (state.sub && cat) { title = subLabel(cat.slug, state.sub); lead = cat.text; }
  else if (cat) { title = cat.label; lead = cat.text; }
  else if (intent) { title = intent.label; lead = intent.text; }
  else if (state.orderby === 'date') { title = 'Újdonságok'; lead = 'Frissen érkezett darabok Nepálból és Indiából.'; }
  else if (state.sel.allapot?.includes('akcios')) { title = 'Akciók'; lead = 'Kedvezményes darabok, amíg a készlet tart.'; }
  $('[data-title]').textContent = title;
  $('[data-crumb]').textContent = title;
  $('[data-lead]').textContent = lead;
  $('[data-lead]').hidden = !lead;
  const counts = categoryCounts(products, state);
  $('[data-subnav]').innerHTML = cat ? [['', `Minden ${cat.label.toLowerCase()}`], ...cat.subs].map(([s, l]) => {
    const n = s ? counts.sub(cat.slug, s) : counts.cat(cat.slug);
    return n || state.sub === s ? `<button type="button" class="chip" data-cat="${cat.slug}" ${s ? `data-sub="${s}"` : ''} aria-pressed="${state.sub === s}">${esc(l)} <span class="text-muted">${n}</span></button>` : '';
  }).join('') : '';
  setMeta({ title, description: lead });
}

function quickHtml() {
  // Csak a releváns és találatot adó gyors szűrések jelennek meg (nincs zsákutca).
  const list = PRESETS.map((p) => {
    const on = presetActive(state, p);
    return { p, on, n: on ? null : filterProducts(products, togglePreset(state, p)).length };
  }).filter(({ p, on, n }) => on || (p.when(state) && n > 0));
  if (!list.length) return '';
  return `<span class="label">Gyors szűrés</span>${list.map(({ p, on, n }) => `<button type="button" class="chip" data-preset="${p.id}" aria-pressed="${on}">${on ? icon('check', 'ico ico-s') : ''}${esc(p.label)}${on ? '' : ` <span class="text-muted">${n}</span>`}</button>`).join('')}`;
}

function chipsHtml() {
  const chips = [];
  if (state.q) chips.push(['q', '', `Keresés: „${state.q}”`]);
  if (state.cat) chips.push(['cat', '', state.sub ? subLabel(state.cat, state.sub) : categoryBySlug(state.cat)?.label]);
  for (const f of FACETS) {
    const v = state.sel[f.key];
    if (!v?.length) continue;
    if (f.type === 'range') chips.push([f.key, '', `${f.label}: ${unitFmt(f, v[0])} – ${unitFmt(f, v[1])}`]);
    else {
      const labels = Object.fromEntries(facetOptions(products, state, f).map((o) => [o.id, o.label]));
      v.forEach((id) => chips.push([f.key, id, `${f.type === 'check' && f.key !== 'allapot' ? `${f.label}: ` : ''}${labels[id] || id}`]));
    }
  }
  const n = activeCount(state) + (state.cat ? 1 : 0);
  $('[data-filter-count]').textContent = n ? `(${n})` : '';
  if (!chips.length) return '';
  return chips.map(([k, v, l]) => `<button type="button" class="chip" data-clear="${k}" data-val="${esc(v)}" aria-label="Szűrő törlése: ${esc(l)}">${esc(l)} ${icon('close', 'ico ico-s')}</button>`).join('')
    + '<button type="button" class="iu-button iu-button-link" data-clear="all">Összes törlése</button>';
}

// ---------- Szűrőpanel ----------
function group(key, label, body, selCount = 0, visibleKey = key) {
  const open = openGroups.has(visibleKey) || selCount > 0;
  return `<details class="filter-group" data-group="${key}" ${open ? 'open' : ''}><summary>${esc(label)}${selCount ? `<span class="sel-count" aria-label="${selCount} kiválasztva">${selCount}</span>` : ''}${icon('chevron', 'ico ico-s')}</summary><div class="filter-group-body">${body}</div></details>`;
}
function categoryGroup() {
  const counts = categoryCounts(products, state);
  const btn = (attrs, label, n, on, cls = '') => `<li><button type="button" class="${cls}" ${attrs} aria-pressed="${on}" ${!n && !on ? 'disabled' : ''}><span>${esc(label)}</span><span class="count">${n}</span></button></li>`;
  return group('kategoria', 'Kategória', `<ul class="filter-list">
    ${btn('data-cat=""', 'Minden termék', counts.all, !state.cat)}
    ${CATEGORIES.map((c) => btn(`data-cat="${c.slug}"`, c.label, counts.cat(c.slug), state.cat === c.slug && !state.sub)
      + (state.cat === c.slug ? c.subs.map(([s, l]) => ({ s, l, n: counts.sub(c.slug, s) })).filter((x) => x.n || state.sub === x.s)
        .map(({ s, l, n }) => btn(`data-cat="${c.slug}" data-sub="${s}"`, l, n, state.sub === s, 'is-sub')).join('') : '')).join('')}
  </ul>`, state.cat ? 1 : 0);
}
function checkBody(f, opts) {
  const LIMIT = 6;
  const expanded = openGroups.has(`${f.key}:more`);
  const findable = opts.length > 8;
  const list = opts.map((o, i) => `<li ${!expanded && i >= LIMIT && !o.selected ? 'hidden data-extra' : ''}><label ${!o.count && !o.selected ? 'aria-disabled="true"' : ''}><span><input type="checkbox" data-facet="${f.key}" value="${esc(o.id)}" ${o.selected ? 'checked' : ''} ${!o.count && !o.selected ? 'disabled' : ''}>${f.key === 'eredet' ? `<span class="origin origin-${o.id}" style="text-transform:none;letter-spacing:0;font-size:.9375rem;color:inherit">${esc(o.label)}</span>` : esc(o.label)}</span><span class="count">${o.count}</span></label></li>`).join('');
  return `${findable ? `<label class="sr-only" for="find-${f.key}">${esc(f.label)} keresése</label><input class="filter-find" id="find-${f.key}" type="search" placeholder="${esc(f.label)} keresése…" data-find="${f.key}">` : ''}
    <ul class="filter-list">${list}</ul>
    ${opts.length > LIMIT && !expanded ? `<button type="button" class="filter-more" data-more="${f.key}">Több mutatása (${opts.length - LIMIT})</button>` : ''}`;
}
function facetGroup(f) {
  const sel = state.sel[f.key] || [];
  const clear = sel.length ? `<button type="button" class="filter-clear" data-clear="${f.key}">Törlés</button>` : '';
  const hint = f.hint ? `<p class="filter-hint">${esc(f.hint)}</p>` : '';
  if (f.type === 'range') {
    const r = rangeInfo(products, state, f);
    if (!r || r.lo === r.hi) return '';
    const maxH = Math.max(...r.hist, 1);
    const pct = (v) => ((v - r.lo) / (r.hi - r.lo)) * 100;
    const body = `${hint}<div class="facet-range" data-range="${f.key}" data-lo="${r.lo}" data-hi="${r.hi}">
      <div class="facet-hist" aria-hidden="true">${r.hist.map((h, i) => { const a = r.lo + i * r.width; return `<span class="${a + r.width > r.min && a < r.max ? 'is-in' : ''}" style="height:${Math.max(4, (h / maxH) * 100)}%" title="${h} termék"></span>`; }).join('')}</div>
      <div class="dual-range"><div class="track"><div class="fill" style="left:${pct(r.min)}%;right:${100 - pct(r.max)}%"></div></div>
        <input type="range" min="${r.lo}" max="${r.hi}" step="${f.step}" value="${r.min}" data-end="min" aria-label="${esc(f.label)} – minimum" aria-valuetext="${unitFmt(f, r.min)}">
        <input type="range" min="${r.lo}" max="${r.hi}" step="${f.step}" value="${r.max}" data-end="max" aria-label="${esc(f.label)} – maximum" aria-valuetext="${unitFmt(f, r.max)}"></div>
      <div class="range-inputs"><label><span class="sr-only">${esc(f.label)} ettől</span><input type="number" inputmode="numeric" min="${r.lo}" max="${r.hi}" step="${f.step}" value="${r.min}" data-num="min"><span class="unit">${f.unit}</span></label><span aria-hidden="true">–</span>
        <label><span class="sr-only">${esc(f.label)} eddig</span><input type="number" inputmode="numeric" min="${r.lo}" max="${r.hi}" step="${f.step}" value="${r.max}" data-num="max"><span class="unit">${f.unit}</span></label></div>
      ${clear}</div>`;
    return group(f.key, f.label, body, r.active ? 1 : 0);
  }
  const opts = facetOptions(products, state, f);
  if (!opts.length) return '';
  let body;
  if (f.type === 'chips') {
    body = `<div class="facet-chips ${f.key === 'meret' ? 'is-wide' : ''}" role="group" aria-label="${esc(f.label)}">${opts.map((o) => `<button type="button" class="facet-chip" data-facet="${f.key}" data-val="${esc(o.id)}" aria-pressed="${o.selected}" ${!o.count && !o.selected ? 'disabled' : ''} aria-label="${esc(o.label)}, ${o.count} termék">${esc(o.label)}<small>${o.count}</small></button>`).join('')}</div>`;
  } else if (f.type === 'chakra') {
    const color = Object.fromEntries(CHAKRAS.map(([id, , c]) => [id, c]));
    body = `<div class="facet-dots" role="group" aria-label="${esc(f.label)}">${opts.map((o) => `<button type="button" class="facet-dot" data-facet="${f.key}" data-val="${esc(o.id)}" aria-pressed="${o.selected}" ${!o.count && !o.selected ? 'disabled' : ''}><span class="dot" style="background:${color[o.id]}"></span>${esc(o.label)}<span class="count">${o.count}</span></button>`).join('')}</div>`;
  } else if (f.type === 'swatch') {
    body = `<div class="facet-swatches" role="group" aria-label="${esc(f.label)}">${opts.map((o) => `<button type="button" class="facet-swatch" data-facet="${f.key}" data-val="${esc(o.id)}" aria-pressed="${o.selected}" ${!o.count && !o.selected ? 'disabled' : ''} aria-label="${esc(o.label)}, ${o.count} termék"><span class="dot" style="background:${o.color}"></span>${esc(o.label)}</button>`).join('')}</div>`;
  } else body = checkBody(f, opts);
  return group(f.key, f.label, `${hint}${body}${clear}`, sel.length);
}

function panelHtml(count) {
  return `<div class="filters-head"><strong>Szűrők</strong><button type="button" class="icon-button" data-filters-close aria-label="Szűrők bezárása">${icon('close')}</button></div>
    <div class="filter-search">${icon('search', 'ico ico-s')}<label class="sr-only" for="filter-q">Keresés a kínálatban</label><input id="filter-q" type="search" placeholder="Keresés a kínálatban…" value="${esc(state.q)}" autocomplete="off"></div>
    ${categoryGroup()}
    ${FACETS.filter((f) => isVisible(f, state)).map(facetGroup).join('')}
    <div class="filters-apply"><button type="button" class="iu-button iu-button-outline" data-clear="all">Törlés</button><button type="button" class="iu-button iu-button-large" data-filters-close>${count} termék mutatása</button></div>`;
}

// ---------- Találatok ----------
function resultsHtml(list) {
  if (list.length) return list.slice(0, shown).map(productCard).join('');
  const tips = relaxSuggestions(products, state);
  return `<li style="grid-column:1/-1"><div class="empty-state">${icon('search', 'ico ico-xl')}<h2 style="font-size:var(--fs-h3)">Nincs ilyen termék</h2>
    ${tips.length ? `<p>Ha lazítasz egy szűrőn, lesz találat:</p><div class="relax">${tips.map((t, i) => `<button type="button" class="chip" data-relax="${i}">A(z) <strong>${esc(t.label)}</strong> nélkül: ${t.n} termék</button>`).join('')}</div>` : '<p>Próbálj kevesebb szűrőt, vagy nézd meg a teljes kínálatot.</p>'}
    <div class="iu-button-group iu-button-group-center"><button type="button" class="iu-button" data-clear="all">Összes szűrő törlése</button><a class="iu-button iu-button-outline" href="kapcsolat.html">Kérdezz tőlünk</a></div></div></li>`;
}

let relaxCache = [];
/** Az aktív elem újrarajzolás utáni megtalálásához stabil szelektor. */
function selectorOf(el) {
  if (!el || el === document.body || !el.closest('main')) return null;
  const range = el.closest('[data-range]')?.dataset.range;
  if (el.id) return `#${CSS.escape(el.id)}`;
  if (range && el.dataset.num) return `[data-range="${range}"] input[data-num="${el.dataset.num}"]`;
  if (range && el.dataset.end) return `[data-range="${range}"] input[data-end="${el.dataset.end}"]`;
  if (el.dataset.facet) return el.matches('input') ? `input[data-facet="${el.dataset.facet}"][value="${CSS.escape(el.value)}"]` : `[data-facet="${el.dataset.facet}"][data-val="${CSS.escape(el.dataset.val)}"]`;
  if (el.dataset.cat !== undefined) return `${el.closest('[data-filters]') ? '[data-filters]' : '[data-subnav]'} [data-cat="${el.dataset.cat}"]${el.dataset.sub ? `[data-sub="${el.dataset.sub}"]` : ':not([data-sub])'}`;
  if (el.dataset.preset) return `[data-preset="${el.dataset.preset}"]`;
  if (el.matches('summary')) return `[data-group="${el.parentElement.dataset.group}"] > summary`;
  if (el.dataset.sort !== undefined) return '[data-sort]';
  return null;
}
function render({ focus = null, history: mode = 'push' } = {}) {
  const keep = focus ?? selectorOf(document.activeElement);
  const list = filterProducts(products, state).sort(SORT[state.orderby] || SORT.menu_order);
  const n = Math.min(shown, list.length);
  heading();
  $('[data-filters]').innerHTML = panelHtml(list.length);
  $('[data-quick]').innerHTML = quickHtml();
  $('[data-chips]').innerHTML = chipsHtml();
  $('[data-sort]').value = state.orderby;
  $('[data-count]').textContent = list.length ? `${list.length} termék${list.length > n ? ` · ${n} látható` : ''}` : 'Nincs találat';
  relaxCache = list.length ? [] : relaxSuggestions(products, state);
  $('[data-results]').innerHTML = resultsHtml(list);
  $('[data-more]').innerHTML = n < list.length ? `<div class="load-more"><p>${n} / ${list.length} termék</p><div class="meter" aria-hidden="true"><span style="width:${(n / list.length) * 100}%"></span></div><button type="button" class="iu-button iu-button-outline" data-more-btn>Több termék betöltése</button></div>` : '';
  const qs = serialize(state);
  const url = `${location.pathname}${qs ? `?${qs}` : ''}`;
  if (mode === 'push' && url !== `${location.pathname}${location.search}`) history.pushState(null, '', url);
  else if (mode !== 'none') history.replaceState(null, '', url);
  bindRanges();
  refreshReveal();
  if (keep) $(keep)?.focus();
}
const update = (next, opts = {}) => { state = next; shown = PER_PAGE; render(opts); };
const sel = (k) => state.sel[k] || [];
function setSel(k, v) { const s = { ...state, sel: { ...state.sel } }; if (v && v.length) s.sel[k] = v; else delete s.sel[k]; return s; }

// ---------- Tartomány csúszkák ----------
function bindRanges() {
  $$('[data-range]').forEach((box) => {
    const f = facetByKey[box.dataset.range];
    const lo = Number(box.dataset.lo); const hi = Number(box.dataset.hi);
    const [rMin, rMax] = $$('input[type="range"]', box);
    const [nMin, nMax] = $$('input[type="number"]', box);
    const fill = $('.fill', box);
    const bars = $$('.facet-hist span', box);
    const width = (hi - lo) / bars.length;
    const paint = (a, b) => {
      fill.style.left = `${((a - lo) / (hi - lo)) * 100}%`;
      fill.style.right = `${100 - ((b - lo) / (hi - lo)) * 100}%`;
      bars.forEach((s, i) => s.classList.toggle('is-in', lo + (i + 1) * width > a && lo + i * width < b));
      rMin.setAttribute('aria-valuetext', unitFmt(f, a)); rMax.setAttribute('aria-valuetext', unitFmt(f, b));
    };
    const read = (src) => {
      let a = Number(rMin.value); let b = Number(rMax.value);
      if (a > b) { if (src === rMin) a = b; else b = a; }
      rMin.value = a; rMax.value = b; nMin.value = a; nMax.value = b;
      paint(a, b);
      return [a, b];
    };
    const commit = (a, b, focusSel) => update(setSel(f.key, a <= lo && b >= hi ? null : [a, b]), { focus: focusSel, history: 'push' });
    [rMin, rMax].forEach((r) => {
      r.addEventListener('input', () => read(r));
      r.addEventListener('change', () => { const [a, b] = read(r); commit(a, b, `[data-range="${f.key}"] input[data-end="${r.dataset.end}"]`); });
    });
    // Számmezők: Enterre azonnal, mező elhagyásakor a következő fókuszált elem megtartásával szűrnek.
    const fromInputs = () => {
      let a = Math.max(lo, Math.min(hi, Number(nMin.value) || lo));
      let b = Math.max(lo, Math.min(hi, Number(nMax.value) || hi));
      if (a > b) [a, b] = [b, a];
      return [a, b];
    };
    [nMin, nMax].forEach((inp) => {
      inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); const [a, b] = fromInputs(); commit(a, b, `[data-range="${f.key}"] input[data-num="${inp.dataset.num}"]`); } });
      inp.addEventListener('change', () => setTimeout(() => { if (!inp.isConnected) return; const [a, b] = fromInputs(); commit(a, b, null); }, 0));
    });
  });
}

// ---------- Mobil panel ----------
function setPanel(open) {
  const panel = $('[data-filters]');
  panel.classList.toggle('is-open', open);
  document.body.classList.toggle('is-locked', open);
  if (open) setTimeout(() => $('button, input', panel)?.focus(), 50); else $('[data-filters-open]')?.focus();
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('[data-filters]').classList.contains('is-open')) setPanel(false); });
document.addEventListener('click', (e) => {
  const panel = $('[data-filters]');
  if (panel.classList.contains('is-open') && !panel.contains(e.target) && !e.target.closest('[data-filters-open]')) setPanel(false);
});

// ---------- Események ----------
document.addEventListener('toggle', (e) => {
  const d = e.target;
  if (!d.matches?.('details[data-group]')) return;
  if (d.open) openGroups.add(d.dataset.group); else openGroups.delete(d.dataset.group);
  storage.set(OPEN_KEY, [...openGroups].filter((k) => !k.includes(':')));
}, true);

document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-cat],[data-facet]:not(input),[data-clear],[data-preset],[data-relax],[data-more],[data-more-btn],[data-filters-open],[data-filters-close]');
  if (!t) return;
  const inPanel = !!t.closest('[data-filters]');
  if (t.hasAttribute('data-filters-open')) { setPanel(true); return; }
  if (t.hasAttribute('data-filters-close') && !t.dataset.clear) { setPanel(false); return; }
  if (t.hasAttribute('data-more-btn')) {
    const before = shown; shown += PER_PAGE; render({ history: 'none' });
    $$('[data-results] li.product')[before]?.querySelector('.loop-product-title a')?.focus();
    return;
  }
  if (t.dataset.more) { openGroups.add(`${t.dataset.more}:more`); render({ focus: `[data-group="${t.dataset.more}"] li[data-extra] input, [data-group="${t.dataset.more}"] li:nth-child(7) input`, history: 'none' }); return; }
  if (t.dataset.cat !== undefined) {
    // Kategóriaváltáskor a csak ott értelmezhető szűrők (pl. Hang) törlődnek
    const next = { ...state, cat: t.dataset.cat, sub: t.dataset.sub || '', sel: { ...state.sel } };
    FACETS.forEach((f) => { if (f.scope && !isVisible(f, { ...next, sel: {} })) delete next.sel[f.key]; });
    const where = inPanel ? '[data-filters]' : '[data-subnav]';
    update(next, { focus: `${where} [data-cat="${t.dataset.cat}"]${t.dataset.sub ? `[data-sub="${t.dataset.sub}"]` : ':not([data-sub])'}` });
    return;
  }
  if (t.dataset.facet) {
    const k = t.dataset.facet; const v = t.dataset.val;
    const cur = new Set(sel(k));
    if (cur.has(v)) cur.delete(v); else cur.add(v);
    update(setSel(k, [...cur]), { focus: `[data-facet="${k}"][data-val="${CSS.escape(v)}"]` });
    return;
  }
  if (t.dataset.preset) { update(togglePreset(state, PRESETS.find((p) => p.id === t.dataset.preset)), { focus: `[data-preset="${t.dataset.preset}"]` }); return; }
  if (t.dataset.relax) { update(relaxCache[Number(t.dataset.relax)].next, { focus: '[data-results] a, [data-count]' }); return; }
  if (t.dataset.clear) {
    const k = t.dataset.clear; const v = t.dataset.val;
    let next;
    if (k === 'all') next = { ...state, cat: '', sub: '', q: '', sel: {} };
    else if (k === 'cat') next = { ...state, cat: '', sub: '' };
    else if (k === 'q') next = { ...state, q: '' };
    else if (v) next = setSel(k, sel(k).filter((x) => x !== v));
    else next = setSel(k, null);
    update(next, { focus: inPanel ? `[data-group="${k}"] summary, #filter-q` : '[data-chips] button, [data-sort]' });
  }
});

document.addEventListener('change', (e) => {
  const t = e.target;
  if (t.matches('input[type="checkbox"][data-facet]')) {
    const k = t.dataset.facet;
    const cur = new Set(sel(k));
    if (t.checked) cur.add(t.value); else cur.delete(t.value);
    update(setSel(k, [...cur]), { focus: `input[data-facet="${k}"][value="${CSS.escape(t.value)}"]` });
  } else if (t.matches('[data-sort]')) { state = { ...state, orderby: t.value }; render({ focus: '[data-sort]', history: 'replace' }); }
});

let qTimer;
document.addEventListener('input', (e) => {
  const t = e.target;
  if (t.id === 'filter-q') {
    clearTimeout(qTimer);
    qTimer = setTimeout(() => { const pos = t.selectionStart; update({ ...state, q: t.value.trim() }, { focus: '#filter-q', history: 'replace' }); const q = $('#filter-q'); q.setSelectionRange(pos, pos); }, 300);
  } else if (t.dataset.find) {
    const term = t.value.trim().toLowerCase();
    $$(`[data-group="${t.dataset.find}"] .filter-list li`).forEach((li) => { li.hidden = term ? !li.textContent.toLowerCase().includes(term) : li.hasAttribute('data-extra'); });
  }
});

addEventListener('popstate', () => { state = parseState(params()); shown = PER_PAGE; render({ history: 'none' }); });

render({ history: 'replace' });
