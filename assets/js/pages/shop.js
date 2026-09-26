// Kínálat oldal: iu-woocommerce/filter + [products class="mainquery"].
// A szűrők állapota az URL-ben van (megosztható, vissza gomb működik).
import { initPage, productCard, $, $$, esc, icon, params, setMeta, refreshReveal } from '../ui.js';
import { CATEGORIES, INTENTS, ORIGINS } from '../data.js';
import { categoryBySlug, fmt, loadProducts, subLabel, norm } from '../store.js';

const q0 = params();
initPage({ active: q0.get('orderby') === 'date' ? '' : 'shop' });

const PER_PAGE = 9;
const products = await loadProducts();
const priceCeil = Math.ceil(Math.max(...products.map((p) => p.price)) / 5000) * 5000;
const state = {
  cat: q0.get('cat') || '', sub: q0.get('sub') || '', intent: q0.get('intent') || '',
  origin: q0.get('origin') ? q0.get('origin').split(',') : [], q: q0.get('q') || '',
  orderby: q0.get('orderby') || 'menu_order', max: Number(q0.get('max')) || priceCeil,
  sale: q0.get('sale') === '1', instock: q0.get('instock') === '1', shown: PER_PAGE,
};
if (state.sub && !state.cat) state.cat = CATEGORIES.find((c) => c.subs.some(([s]) => s === state.sub))?.slug || '';

function matches(p, except = '') {
  if (except !== 'cat' && state.cat && p.cat !== state.cat) return false;
  if (except !== 'cat' && state.sub && p.sub !== state.sub) return false;
  if (except !== 'intent' && state.intent && !p.intents.includes(state.intent)) return false;
  if (except !== 'origin' && state.origin.length && !state.origin.includes(p.origin)) return false;
  if (state.q && !norm(`${p.name} ${p.sku} ${p.short} ${subLabel(p.cat, p.sub)}`).includes(norm(state.q))) return false;
  if (p.price > state.max) return false;
  if (state.sale && !(p.compare > p.price)) return false;
  if (except !== 'instock' && state.instock && p.stock === 'out') return false;
  return true;
}
const SORT = {
  menu_order: (a, b) => (a.stock === 'out') - (b.stock === 'out') || (b.featured ? 1 : 0) - (a.featured ? 1 : 0),
  date: (a, b) => (b.isNew ? 1 : 0) - (a.isNew ? 1 : 0) || b.id - a.id,
  popularity: (a, b) => (b.featured ? 1 : 0) - (a.featured ? 1 : 0) || a.price - b.price,
  price: (a, b) => a.price - b.price,
  'price-desc': (a, b) => b.price - a.price,
};

function syncUrl() {
  const u = new URLSearchParams();
  ['cat', 'sub', 'intent', 'q'].forEach((k) => state[k] && u.set(k, state[k]));
  if (state.origin.length) u.set('origin', state.origin.join(','));
  if (state.orderby !== 'menu_order') u.set('orderby', state.orderby);
  if (state.max < priceCeil) u.set('max', state.max);
  if (state.sale) u.set('sale', '1');
  if (state.instock) u.set('instock', '1');
  const qs = u.toString();
  history.replaceState(null, '', `${location.pathname}${qs ? `?${qs}` : ''}`);
}

function heading() {
  const cat = categoryBySlug(state.cat);
  const intent = INTENTS.find((i) => i.id === state.intent);
  let title = 'Teljes kínálat';
  let lead = 'Hangtálak, füstölők, szobrok, textilek és ajándékok – Nepál és India műhelyeiből.';
  if (state.q) { title = `Keresés: „${state.q}”`; lead = ''; }
  else if (state.sub && cat) { title = subLabel(cat.slug, state.sub); lead = cat.text; }
  else if (cat) { title = cat.label; lead = cat.text; }
  else if (intent) { title = intent.label; lead = intent.text; }
  else if (state.orderby === 'date') { title = 'Újdonságok'; lead = 'Frissen érkezett darabok Nepálból és Indiából.'; }
  else if (state.sale) { title = 'Akciók'; lead = 'Kedvezményes darabok, amíg a készlet tart.'; }
  $('[data-title]').textContent = title;
  $('[data-crumb]').textContent = title;
  $('[data-lead]').textContent = lead;
  $('[data-lead]').hidden = !lead;
  $('[data-subnav]').innerHTML = cat ? [['', `Minden ${cat.label.toLowerCase()}`], ...cat.subs].map(([s, l]) => {
    const n = products.filter((p) => p.cat === cat.slug && (!s || p.sub === s)).length;
    return n ? `<button type="button" class="chip" data-cat="${cat.slug}" ${s ? `data-sub="${s}"` : ''} aria-pressed="${state.sub === s}">${esc(l)} <span class="text-muted">${n}</span></button>` : '';
  }).join('') : '';
  setMeta({ title, description: lead });
}

function filtersHtml(count) {
  const n = (fn, except) => products.filter((p) => matches(p, except) && fn(p)).length;
  const btn = (attrs, label, c, on, cls = '') => `<li><button type="button" class="${cls}" ${attrs} aria-pressed="${on}"><span>${esc(label)}</span><span class="count">${c}</span></button></li>`;
  return `<div class="filters-head"><strong>Szűrők</strong><button type="button" class="icon-button" data-filters-close aria-label="Szűrők bezárása">${icon('close')}</button></div>
    <div class="filter-group"><h2>Kategória</h2><ul class="filter-list">
      ${btn('data-cat=""', 'Minden termék', n(() => true, 'cat'), !state.cat)}
      ${CATEGORIES.map((c) => btn(`data-cat="${c.slug}"`, c.label, n((p) => p.cat === c.slug, 'cat'), state.cat === c.slug && !state.sub)
        + (state.cat === c.slug ? c.subs.map(([s, l]) => ({ s, l, k: n((p) => p.cat === c.slug && p.sub === s, 'cat') })).filter((x) => x.k || state.sub === x.s)
          .map(({ s, l, k }) => btn(`data-cat="${c.slug}" data-sub="${s}"`, l, k, state.sub === s, 'is-sub')).join('') : '')).join('')}
    </ul></div>
    <div class="filter-group"><h2>Szándék</h2><ul class="filter-list">
      ${INTENTS.map((i) => btn(`data-intent="${i.id}"`, i.label, n((p) => p.intents.includes(i.id), 'intent'), state.intent === i.id)).join('')}
    </ul></div>
    <div class="filter-group"><h2>Eredet</h2><ul class="filter-list">
      ${Object.entries(ORIGINS).map(([k, o]) => `<li><label><span><input type="checkbox" data-origin="${k}" ${state.origin.includes(k) ? 'checked' : ''}><span class="origin origin-${k}" style="text-transform:none;letter-spacing:0;font-size:.9375rem;color:var(--c-ink)">${o.label}</span></span><span class="count">${n((p) => p.origin === k, 'origin')}</span></label></li>`).join('')}
    </ul></div>
    <div class="filter-group"><h2>Ár</h2><div class="price-filter">
      <label for="max" class="sr-only">Legmagasabb ár</label>
      <input id="max" type="range" min="0" max="${priceCeil}" step="1000" value="${state.max}" data-max aria-valuetext="${fmt(state.max)}">
      <output for="max">Legfeljebb <strong data-max-label>${fmt(state.max)}</strong></output>
    </div></div>
    <div class="filter-group"><h2>Elérhetőség</h2><ul class="filter-list">
      <li><label><span><input type="checkbox" data-instock ${state.instock ? 'checked' : ''}>Csak raktáron lévők</span><span class="count">${n((p) => p.stock !== 'out', 'instock')}</span></label></li>
    </ul></div>
    <div class="filters-apply"><button type="button" class="iu-button iu-button-large" data-filters-close>${count} termék mutatása</button></div>`;
}

function chipsHtml() {
  const chips = [];
  if (state.q) chips.push(['q', `„${state.q}”`]);
  if (state.cat) chips.push(['cat', state.sub ? subLabel(state.cat, state.sub) : categoryBySlug(state.cat)?.label]);
  if (state.intent) chips.push(['intent', INTENTS.find((i) => i.id === state.intent)?.label]);
  state.origin.forEach((o) => chips.push([`origin:${o}`, ORIGINS[o]?.label]));
  if (state.max < priceCeil) chips.push(['max', `max. ${fmt(state.max)}`]);
  if (state.sale) chips.push(['sale', 'Akciós']);
  if (state.instock) chips.push(['instock', 'Raktáron']);
  $('[data-filter-count]').textContent = chips.length ? `(${chips.length})` : '';
  if (!chips.length) return '';
  return chips.map(([k, l]) => `<button type="button" class="chip" data-clear="${k}" aria-label="Szűrő törlése: ${esc(l)}">${esc(l)} ${icon('close', 'ico ico-s')}</button>`).join('')
    + '<button type="button" class="iu-button iu-button-link" data-clear="all">Összes törlése</button>';
}

function render({ keepShown = false } = {}) {
  if (!keepShown) state.shown = PER_PAGE;
  const list = products.filter((p) => matches(p)).sort(SORT[state.orderby] || SORT.menu_order);
  heading();
  $('[data-filters]').innerHTML = filtersHtml(list.length);
  $('[data-chips]').innerHTML = chipsHtml();
  $('[data-sort]').value = state.orderby;
  const shown = Math.min(state.shown, list.length);
  $('[data-count]').textContent = list.length ? `${list.length} termékből 1–${shown} látható` : 'Nincs találat';
  $('[data-results]').innerHTML = list.length ? list.slice(0, shown).map(productCard).join('')
    : `<li style="grid-column:1/-1"><div class="empty-state">${icon('search', 'ico ico-xl')}<h2 class="h3" style="font-size:var(--fs-h3)">Nincs a szűrésnek megfelelő termék</h2><p>Próbálj kevesebb szűrőt, vagy nézd meg a teljes kínálatot.</p><div class="iu-button-group iu-button-group-center"><button type="button" class="iu-button" data-clear="all">Szűrők törlése</button><a class="iu-button iu-button-outline" href="kapcsolat.html">Kérdezz tőlünk</a></div></div></li>`;
  $('[data-more]').innerHTML = shown < list.length ? `<div class="load-more"><p>${shown} / ${list.length} termék</p><div class="meter" aria-hidden="true"><span style="width:${(shown / list.length) * 100}%"></span></div><button type="button" class="iu-button iu-button-outline" data-more-btn>Több termék betöltése</button></div>` : '';
  syncUrl();
  refreshReveal();
}

// Mobil szűrőpanel
function setFilters(open) {
  const panel = $('[data-filters]');
  panel.classList.toggle('is-open', open);
  document.body.classList.toggle('is-locked', open);
  if (open) setTimeout(() => $('button', panel)?.focus(), 50); else $('[data-filters-open]')?.focus();
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('[data-filters]').classList.contains('is-open')) setFilters(false); });
document.addEventListener('click', (e) => {
  const panel = $('[data-filters]');
  if (panel.classList.contains('is-open') && !panel.contains(e.target) && !e.target.closest('[data-filters-open]')) setFilters(false);
});

document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-cat],[data-intent],[data-clear],[data-filters-open],[data-filters-close],[data-more-btn]');
  if (!t) return;
  if (t.hasAttribute('data-filters-open')) { setFilters(true); return; }
  if (t.hasAttribute('data-filters-close')) { setFilters(false); return; }
  if (t.hasAttribute('data-more-btn')) {
    const before = state.shown;
    state.shown += PER_PAGE;
    render({ keepShown: true });
    $$('[data-results] li.product')[before]?.querySelector('.loop-product-title a')?.focus();
    return;
  }
  if (t.dataset.cat !== undefined) { state.cat = t.dataset.cat; state.sub = t.dataset.sub || ''; }
  else if (t.dataset.intent) state.intent = state.intent === t.dataset.intent ? '' : t.dataset.intent;
  else if (t.dataset.clear) {
    const k = t.dataset.clear;
    if (k === 'all') Object.assign(state, { cat: '', sub: '', intent: '', origin: [], q: '', max: priceCeil, sale: false, instock: false });
    else if (k === 'cat') { state.cat = ''; state.sub = ''; }
    else if (k.startsWith('origin:')) state.origin = state.origin.filter((o) => o !== k.slice(7));
    else if (k === 'max') state.max = priceCeil;
    else if (k === 'sale' || k === 'instock') state[k] = false;
    else state[k] = '';
  }
  // Fókusz megtartása az újrarajzolás után
  const inPanel = t.closest('[data-filters]');
  const sel = t.dataset.cat !== undefined ? `[data-cat="${t.dataset.cat}"]${t.dataset.sub ? `[data-sub="${t.dataset.sub}"]` : ':not([data-sub])'}` : t.dataset.intent ? `[data-intent="${t.dataset.intent}"]` : '';
  render();
  if (sel) $(`${inPanel ? '[data-filters]' : '[data-subnav]'} ${sel}`)?.focus();
  else if (t.dataset.clear) $('[data-chips] button, [data-sort]')?.focus();
});
document.addEventListener('change', (e) => {
  if (e.target.matches('[data-origin]')) { state.origin = $$('[data-origin]').filter((i) => i.checked).map((i) => i.dataset.origin); render(); $(`[data-origin="${e.target.dataset.origin}"]`)?.focus(); }
  else if (e.target.matches('[data-instock]')) { state.instock = e.target.checked; render(); $('[data-instock]')?.focus(); }
  else if (e.target.matches('[data-sort]')) { state.orderby = e.target.value; render(); $('[data-sort]').focus(); }
  else if (e.target.matches('[data-max]')) { state.max = Number(e.target.value); render(); $('[data-max]')?.focus(); }
});
document.addEventListener('input', (e) => { if (e.target.matches('[data-max]')) $('[data-max-label]').textContent = fmt(e.target.value); });

render();
