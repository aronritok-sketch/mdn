// WordPress környezet a prototípusból átvett modulokhoz (facets.js, filter.js):
// ugyanazokat a neveket adja, mint a prototípus data.js / store.js / ui.js, de az
// adatok a WordPressből jönnek (window.MANDALA, REST termékindex).
const M = window.MANDALA || {};

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
export const esc = (s = '') => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
export const params = () => new URLSearchParams(location.search);
export const norm = (s = '') => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
export const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;

export function icon(name, cls = 'ico') {
  const body = M.icons?.[name];
  return body ? `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">${body}</svg>` : '';
}

// ---------- Tárolás ----------
const mem = {};
export const storage = {
  get(key, fallback) {
    try { const v = localStorage.getItem(key); return v === null ? fallback : JSON.parse(v); } catch { return key in mem ? mem[key] : fallback; }
  },
  set(key, value) {
    mem[key] = value;
    try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* privát mód */ }
  },
};

// ---------- Formázás ----------
export const fmtNum = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
export const fmt = (n) => `${fmtNum(n)} Ft`;
const priceHtml = (n) => `<span class="woocommerce-Price-amount amount"><bdi>${fmtNum(n)}&nbsp;<span class="woocommerce-Price-currencySymbol">Ft</span></bdi></span>`;

// ---------- Katalógus ----------
export const CATEGORIES = (M.categories || []).map((c) => ({ ...c, subs: c.subs.map(([slug, label]) => [slug, label]) }));
const URLS = Object.fromEntries((M.categories || []).flatMap((c) => [[c.slug, c.url], ...c.subs.map(([s, , url]) => [s, url])]));
export const INTENTS = M.intents || [];
export const COLORS = M.colors || {};
export const categoryBySlug = (slug) => CATEGORIES.find((c) => c.slug === slug);
export const subLabel = (cat, sub) => categoryBySlug(cat)?.subs.find(([s]) => s === sub)?.[1] || '';

let productsPromise;
/** A teljes kínálat indexe (mandala/v1/products, gyorsítótárazott REST végpont). */
export function loadProducts() {
  // A nonce azonosítja a belépett vásárlót: viszonteladónak a nagyker árakkal jön az index.
  productsPromise ??= fetch(`${M.rest}products`, { credentials: 'same-origin', headers: M.loggedIn ? { 'X-WP-Nonce': M.nonce } : {} })
    .then((r) => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
    .then((list) => list.map((p) => ({ ...p, attrs: p.attrs || {}, specs: p.specs || {}, intents: p.intents || [] })))
    .catch((err) => { console.warn('Mandala: a termékindex nem tölthető be.', err); return []; });
  return productsPromise;
}

// ---------- Kedvencek (süti) ----------
const WISH = 'mandala_wishlist';
export const wishlist = {
  ids() {
    const m = document.cookie.match(new RegExp(`(?:^|; )${WISH}=([^;]*)`));
    return m ? decodeURIComponent(m[1]).split('.').map(Number).filter(Boolean) : [];
  },
  has(id) { return this.ids().includes(Number(id)); },
  toggle(id) {
    id = Number(id);
    const ids = this.ids();
    const on = !ids.includes(id);
    const next = on ? [id, ...ids].slice(0, 100) : ids.filter((x) => x !== id);
    document.cookie = `${WISH}=${next.join('.')}; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;
    if (document.body.classList.contains('logged-in')) {
      fetch(`${M.rest}wishlist`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': M.nonce }, body: JSON.stringify({ ids: next }) }).catch(() => {});
    }
    document.dispatchEvent(new CustomEvent('wish:change', { detail: { id, on } }));
    return on;
  },
};

// ---------- Termékkártya (a PHP mandala_card() JS párja) ----------
const saleOf = (p) => (p.compare > p.price ? Math.round((1 - p.price / p.compare) * 100) : 0);
function media(p) {
  return p.img
    ? `<img src="${esc(p.img)}" alt="${esc(p.name)}" loading="lazy" decoding="async">`
    : `<img class="art" src="${esc(p.art)}" alt="${esc(p.name)}" loading="lazy" decoding="async" width="400" height="451">`;
}
export function productCard(p) {
  const out = p.stock === 'out';
  const badges = [
    out ? '<span class="badge badge-dark">Elfogyott</span>' : p.wholesale ? '<span class="badge badge-sale">Nagyker ár</span>' : saleOf(p) ? `<span class="badge badge-sale">−${saleOf(p)}%</span>` : '',
    !out && p.isNew ? '<span class="badge">Új</span>' : '',
  ].join('');
  const add = (cls, inner, label) => `<a href="${esc(p.addUrl)}" data-quantity="1" data-product_id="${p.id}" data-product_sku="${esc(p.sku || '')}" rel="nofollow" class="${cls} add_to_cart_button ajax_add_to_cart"${label ? ` aria-label="${esc(label)}"` : ''}>${inner}</a>`;
  const price = p.compare > p.price ? `<del aria-label="${p.wholesale ? 'Bolti ár' : 'Eredeti ár'}">${priceHtml(p.compare)}</del> <ins aria-label="${p.wholesale ? 'Nagyker ár' : 'Akciós ár'}">${priceHtml(p.price)}</ins>` : priceHtml(p.price);
  return `<li class="product type-product ${out ? 'outofstock' : 'instock'}" data-id="${p.id}">
    <div class="product-card">
      <div class="loop-product-image">
        <a href="${esc(p.url)}" tabindex="-1" aria-hidden="true">${media(p)}</a>
        <div class="product-badges">${badges}</div>
        <button type="button" class="wishlist-toggle" data-wish="${p.id}" aria-pressed="${wishlist.has(p.id)}" aria-label="Kedvencekhez: ${esc(p.name)}">${icon('heart')}</button>
        ${p.buyable ? `<div class="loop-quick">${add('iu-button', `${icon('plus', 'ico ico-s')} Kosárba`)}</div>` : ''}
      </div>
      <div class="loop-product-meta"><span class="origin origin-${esc(p.origin)}">${esc(p.originLabel || '')}</span><span class="text-muted" style="font-size:var(--fs-xs)">${esc(p.catLabel || '')}</span></div>
      <h3 class="loop-product-title"><a href="${esc(p.url)}">${esc(p.name)}</a></h3>
      <div class="loop-product-foot">
        <div class="loop-product-pirce"><span class="price">${price}</span></div>
        <div class="loop-product-button">${p.buyable
          ? add('icon-button', icon('bag'), `Kosárba: ${p.name}`)
          : `<a class="icon-button" href="${esc(p.url)}" aria-label="${out ? 'Elfogyott – részletek' : `Részletek: ${esc(p.name)}`}">${icon(out ? 'close' : 'arrow')}</a>`}</div>
      </div>
    </div>
  </li>`;
}

// ---------- Oldal ----------
export function setMeta({ title, description }) {
  if (title) document.title = `${title} – ${M.siteName || 'Mandala'}`;
  if (description) $('meta[name="description"]')?.setAttribute('content', description);
}
export function refreshReveal() {
  const els = $$('.reveal:not(.is-in)');
  if (!('IntersectionObserver' in window) || reducedMotion()) { els.forEach((e) => e.classList.add('is-in')); return; }
  const io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -6% 0px' });
  els.forEach((e) => io.observe(e));
}
/** A prototípus initPage()-e: WordPressben a fejlécet a szerver rajzolja, itt nincs teendő. */
export const initPage = () => {};

// ---------- Szűrő URL-kezelés ----------
const context = (() => { try { return JSON.parse($('[data-filters]')?.dataset.context || '{}'); } catch { return {}; } })();
/** Archívumon (pl. /kategoria/hangtalak/) a kategória az útvonalból jön. */
export function contextState(state) {
  if (!state.cat && !state.sub && context.cat && !params().has('cat') && location.pathname === new URL(URLS[context.sub || context.cat] || location.href, location.href).pathname) {
    return { ...state, cat: context.cat, sub: context.sub || '' };
  }
  return state;
}
/** Kategória esetén a kategória saját (SEO) címe, különben a bolt oldala; a többi szűrő paraméterben. */
export function shopUrl(state, qs) {
  const q = new URLSearchParams(qs);
  const target = (state.sub && URLS[state.sub]) || (state.cat && URLS[state.cat]) || M.shop || location.pathname;
  if (URLS[state.sub] || (!state.sub && URLS[state.cat])) { q.delete('cat'); q.delete('sub'); }
  const path = new URL(target, location.href).pathname;
  const s = q.toString();
  return `${path}${s ? `?${s}` : ''}`;
}
export const contactUrl = () => M.contactPage || M.home || '/';
