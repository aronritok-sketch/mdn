import { initPage, productCard, $, $$, esc, icon, params, setMeta } from '../app.js';
import { CATEGORIES, INTENTS, ORIGINS } from '../data.js';
import { categoryBySlug, fmt, loadProducts, subLabel } from '../store.js';

initPage({ active: params().get('sort') === 'new' ? 'new' : 'shop' });

const products = await loadProducts();
const priceCeil = Math.ceil(Math.max(...products.map((p) => p.price)) / 5000) * 5000;

const p0 = params();
const state = {
  cat: p0.get('cat') || '',
  sub: p0.get('sub') || '',
  intent: p0.get('intent') || '',
  origin: p0.get('origin') ? p0.get('origin').split(',') : [],
  q: p0.get('q') || '',
  sort: p0.get('sort') || 'featured',
  max: Number(p0.get('max')) || priceCeil,
  sale: p0.get('sale') === '1',
};
// Ha csak alkategória érkezik, a főkategóriát kikövetkeztetjük.
if (state.sub && !state.cat) state.cat = CATEGORIES.find((c) => c.subs.some(([s]) => s === state.sub))?.slug || '';

const norm = (s = '') => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

function matches(p, except = '') {
  if (except !== 'cat' && state.cat && p.cat !== state.cat) return false;
  if (except !== 'cat' && state.sub && p.sub !== state.sub) return false;
  if (except !== 'intent' && state.intent && !p.intents.includes(state.intent)) return false;
  if (except !== 'origin' && state.origin.length && !state.origin.includes(p.origin)) return false;
  if (state.q && !norm(`${p.name} ${p.short} ${subLabel(p.cat, p.sub)}`).includes(norm(state.q))) return false;
  if (p.price > state.max) return false;
  if (state.sale && !(p.compare > p.price)) return false;
  return true;
}

const sorters = {
  featured: (a, b) => (b.featured ? 1 : 0) - (a.featured ? 1 : 0),
  new: (a, b) => (b.isNew ? 1 : 0) - (a.isNew ? 1 : 0) || b.id - a.id,
  'price-asc': (a, b) => a.price - b.price,
  'price-desc': (a, b) => b.price - a.price,
  name: (a, b) => a.name.localeCompare(b.name, 'hu'),
};

function syncUrl() {
  const u = new URLSearchParams();
  if (state.cat) u.set('cat', state.cat);
  if (state.sub) u.set('sub', state.sub);
  if (state.intent) u.set('intent', state.intent);
  if (state.origin.length) u.set('origin', state.origin.join(','));
  if (state.q) u.set('q', state.q);
  if (state.sort !== 'featured') u.set('sort', state.sort);
  if (state.max < priceCeil) u.set('max', state.max);
  if (state.sale) u.set('sale', '1');
  history.replaceState(null, '', `${location.pathname}${u.toString() ? `?${u}` : ''}`);
}

function heading() {
  const cat = categoryBySlug(state.cat);
  const intent = INTENTS.find((i) => i.id === state.intent);
  let title = 'Teljes kínálat';
  let text = 'Hangtálak, füstölők, szobrok, textilek és ajándékok – Nepál és India műhelyeiből.';
  if (state.q) { title = `Keresés: „${state.q}”`; text = ''; }
  else if (state.sub && cat) { title = subLabel(cat.slug, state.sub); text = cat.text; }
  else if (cat) { title = cat.label; text = cat.text; }
  else if (intent) { title = intent.label; text = intent.text; }
  else if (state.sort === 'new') { title = 'Újdonságok'; text = 'Frissen érkezett darabok Nepálból és Indiából.'; }
  else if (state.sale) { title = 'Akciók'; text = ''; }
  $('[data-title]').textContent = title;
  $('[data-lead]').textContent = text;
  $('[data-lead]').hidden = !text;
  $('[data-crumb]').textContent = title;
  setMeta({ title, description: text });
}

function filtersHtml(count) {
  const countCat = (slug, sub) => products.filter((p) => matches(p, 'cat') && p.cat === slug && (!sub || p.sub === sub)).length;
  const countIntent = (id) => products.filter((p) => matches(p, 'intent') && p.intents.includes(id)).length;
  const countOrigin = (o) => products.filter((p) => matches(p, 'origin') && p.origin === o).length;
  const btn = (attrs, label, count, pressed, cls = '') => `<li><button type="button" class="${cls}" ${attrs} aria-pressed="${pressed}"><span>${esc(label)}</span><span class="count">${count}</span></button></li>`;

  return `
    <div class="filters-head" style="display:none;justify-content:space-between;align-items:center">
      <strong>Szűrők</strong><button class="icon-btn" type="button" data-filters-close aria-label="Szűrők bezárása">${icon('close')}</button>
    </div>
    <section><h2>Kategória</h2><ul class="filter-list">
      ${btn('data-cat=""', 'Minden termék', products.filter((p) => matches(p, 'cat')).length, !state.cat)}
      ${CATEGORIES.map((c) => btn(`data-cat="${c.slug}"`, c.label, countCat(c.slug), state.cat === c.slug && !state.sub)
        + (state.cat === c.slug ? c.subs.map(([s, l]) => ({ s, l, n: countCat(c.slug, s) })).filter((x) => x.n || state.sub === x.s)
          .map(({ s, l, n }) => btn(`data-cat="${c.slug}" data-sub="${s}"`, l, n, state.sub === s, 'sub')).join('') : '')).join('')}
    </ul></section>
    <section><h2>Szándék</h2><ul class="filter-list">
      ${INTENTS.map((i) => btn(`data-intent="${i.id}"`, i.label, countIntent(i.id), state.intent === i.id)).join('')}
    </ul></section>
    <section><h2>Eredet</h2><ul class="filter-list">
      ${Object.entries(ORIGINS).map(([k, o]) => `<li><label><span><input type="checkbox" data-origin="${k}" ${state.origin.includes(k) ? 'checked' : ''}><span class="origin-dot" style="--dot:${o.tone}"></span>${o.label}</span><span class="count">${countOrigin(k)}</span></label></li>`).join('')}
    </ul></section>
    <section><h2>Ár</h2><div class="range">
      <label for="max" class="sr-only">Legmagasabb ár</label>
      <input id="max" type="range" min="0" max="${priceCeil}" step="1000" value="${state.max}" data-max>
      <span>Legfeljebb <strong data-max-label>${fmt(state.max)}</strong></span>
    </div></section>
    <button class="btn btn-primary btn-block filters-apply" type="button" data-filters-close>${count} termék mutatása</button>`;
}

function chipsHtml() {
  const chips = [];
  if (state.q) chips.push(['q', `„${state.q}”`]);
  if (state.cat) chips.push(['cat', state.sub ? subLabel(state.cat, state.sub) : categoryBySlug(state.cat)?.label]);
  if (state.intent) chips.push(['intent', INTENTS.find((i) => i.id === state.intent)?.label]);
  state.origin.forEach((o) => chips.push([`origin:${o}`, ORIGINS[o]?.label]));
  if (state.max < priceCeil) chips.push(['max', `max. ${fmt(state.max)}`]);
  if (state.sale) chips.push(['sale', 'Akciós']);
  if (!chips.length) return '';
  return chips.map(([k, l]) => `<button type="button" class="chip" data-clear="${k}" aria-label="Szűrő törlése: ${esc(l)}">${esc(l)} ${icon('close')}</button>`).join('')
    + '<button type="button" class="btn-link small" data-clear="all">Összes törlése</button>';
}

function render() {
  const list = products.filter((p) => matches(p)).sort(sorters[state.sort] || sorters.featured);
  heading();
  $('[data-filters]').innerHTML = filtersHtml(list.length);
  $('[data-chips]').innerHTML = chipsHtml();
  $('[data-count]').textContent = `${list.length} termék`;
  $('[data-sort]').value = state.sort;
  $('[data-results]').innerHTML = list.length
    ? list.map(productCard).join('')
    : `<div class="empty" style="grid-column:1/-1"><p>Nincs a szűrésnek megfelelő termék.</p><button class="btn btn-ghost" type="button" data-clear="all">Szűrők törlése</button></div>`;
  syncUrl();
}

// Mobilon a szűrőpanel oldalfiókként nyílik.
function setFilters(open) {
  const panel = $('[data-filters]');
  panel.classList.toggle('open', open);
  document.body.classList.toggle('locked', open);
  if (open) $('button', panel)?.focus();
  else $('[data-filters-open]')?.focus();
}
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && $('[data-filters]').classList.contains('open')) setFilters(false); });
document.addEventListener('click', (e) => {
  const panel = $('[data-filters]');
  if (panel.classList.contains('open') && !panel.contains(e.target) && !e.target.closest('[data-filters-open]')) setFilters(false);
});

document.addEventListener('click', (e) => {
  const t = e.target.closest('[data-cat],[data-intent],[data-clear],[data-filters-open],[data-filters-close]');
  if (!t) return;
  if (t.hasAttribute('data-filters-open')) { setFilters(true); return; }
  if (t.hasAttribute('data-filters-close')) { setFilters(false); return; }
  if (t.dataset.cat !== undefined) { state.cat = t.dataset.cat; state.sub = t.dataset.sub || ''; }
  else if (t.dataset.intent) state.intent = state.intent === t.dataset.intent ? '' : t.dataset.intent;
  else if (t.dataset.clear) {
    const k = t.dataset.clear;
    if (k === 'all') Object.assign(state, { cat: '', sub: '', intent: '', origin: [], q: '', max: priceCeil, sale: false });
    else if (k === 'cat') { state.cat = ''; state.sub = ''; }
    else if (k.startsWith('origin:')) state.origin = state.origin.filter((o) => o !== k.slice(7));
    else if (k === 'max') state.max = priceCeil;
    else if (k === 'sale') state.sale = false;
    else state[k] = '';
  }
  // A kattintott szűrőgomb az újrarajzolás után is megtartja a fókuszt.
  const sel = t.closest('[data-filters]') && (t.dataset.cat !== undefined
    ? `[data-filters] [data-cat="${t.dataset.cat}"]${t.dataset.sub ? `[data-sub="${t.dataset.sub}"]` : ':not([data-sub])'}`
    : t.dataset.intent ? `[data-filters] [data-intent="${t.dataset.intent}"]` : '');
  render();
  if (sel) $(sel)?.focus();
});
document.addEventListener('change', (e) => {
  if (e.target.matches('[data-origin]')) {
    state.origin = $$('[data-origin]').filter((i) => i.checked).map((i) => i.dataset.origin);
    render();
  } else if (e.target.matches('[data-sort]')) { state.sort = e.target.value; render(); }
  else if (e.target.matches('[data-max]')) { state.max = Number(e.target.value); render(); }
});
document.addEventListener('input', (e) => {
  if (e.target.matches('[data-max]')) $('[data-max-label]').textContent = fmt(e.target.value);
});

render();
