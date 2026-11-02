// Mérés a téma saját, AJAX-os felületeihez: szűrt terméklista (view_item_list / select_item), kosárba tétel
// (termékoldal, kártya, ajándékcsomag, viszonteladói gyorsrendelő: add_to_cart), élő keresés (search), termékoldal
// (view_item). Saját méréskor (analytics.php, M.track.own) a GA4-nek közvetlenül (window.mandalaGtag) és a Meta pixelnek
// is (window.mandalaPx: AddToCart, ViewCategory, Search – csak marketing-hozzájárulással); különben GTM-formában a
// dataLayer-be, a címkéket a GTM futtatja.
import { $, $$, loadProducts } from './env.js';

const M = window.MANDALA || {};
const T = M.track || {};
window.dataLayer = window.dataLayer || [];

function push(event, data) {
  if (T.own && typeof window.mandalaGtag === 'function') {
    const { ecommerce, ...rest } = data;
    window.mandalaGtag('event', event, { ...(ecommerce || {}), ...rest });
    return;
  }
  window.dataLayer.push({ ecommerce: null });
  window.dataLayer.push({ event, ...data });
}
/** Meta pixel esemény (saját mérés; a mandalaPx csak hozzájárulással küld). */
const meta = (type, name, params, opts) => { try { return !!(T.own && window.mandalaPx && window.mandalaPx.track(type, name, params, opts)); } catch { return false; } };
const eid = (p) => `${p}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
/** Egy termék / változat bruttó ára: a termékindexből, változatnál a termékoldal változat-adataiból. */
function priceOf(id, map) {
  const p = map.get(id);
  if (p) return Number(p.price) || 0;
  try {
    for (const f of document.querySelectorAll('form.variations_form[data-product_variations]')) {
      const v = (JSON.parse(f.dataset.product_variations) || []).find((x) => Number(x.variation_id) === id);
      if (v) return Number(v.display_price) || 0;
    }
  } catch { /* nincs változat-adat */ }
  return 0;
}
const item = (p, i = 0, qty = 1, list = '') => ({
  item_id: p.sku || String(p.id), item_name: p.name, item_category: p.catLabel || p.cat || '', item_category2: p.sub || '',
  item_brand: M.siteName || '', price: Number(p.price) || 0, quantity: qty, index: i, ...(list ? { item_list_name: list } : {}),
});
const byId = async () => new Map((await loadProducts()).map((p) => [p.id, p]));

// Kosárba (termékoldal, ajándékcsomag, viszonteladói gyorsrendelő)
document.addEventListener('mandala:cart-add', async (e) => {
  const map = await byId();
  const lines = e.detail.items.filter(([id, qty]) => id && qty > 0);
  // Meta AddToCart: a katalógus azonosítójával (termék / változat), a változatot is beleértve, ami nincs az indexben.
  if (lines.length) {
    meta('track', 'AddToCart', {
      content_ids: lines.map(([id]) => String(id)), content_type: 'product', contents: lines.map(([id, qty]) => ({ id: String(id), quantity: qty })),
      value: Math.round(lines.reduce((s, [id, qty]) => s + priceOf(id, map) * qty, 0) * 100) / 100, currency: M.currency || 'HUF',
    }, { eventID: eid('atc') });
  }
  const items = e.detail.items.map(([id, qty], i) => map.get(id) && item(map.get(id), i, qty)).filter(Boolean);
  if (!items.length) return;
  push('add_to_cart', { ecommerce: { currency: M.currency || 'HUF', value: items.reduce((s, x) => s + x.price * x.quantity, 0), items }, mandala_source: e.detail.source });
});

// Szűrt terméklista: megjelenés és kattintás
const results = $('[data-results]');
if (results) {
  let timer; let last = '';
  const listName = () => document.querySelector('h1')?.textContent.trim() || 'Kínálat';
  const report = async () => {
    const ids = $$('li.product[data-id]', results).slice(0, 24).map((li) => Number(li.dataset.id));
    const key = ids.join(',') + location.search;
    if (!ids.length || key === last) return;
    last = key;
    const map = await byId();
    push('view_item_list', { ecommerce: { item_list_name: listName(), items: ids.map((id, i) => map.get(id) && item(map.get(id), i, 1, listName())).filter(Boolean) } });
    meta('trackCustom', 'ViewCategory', { content_ids: ids.slice(0, 10).map(String), content_type: 'product', content_category: listName() });
  };
  new MutationObserver(() => { clearTimeout(timer); timer = setTimeout(report, 800); }).observe(results, { childList: true, subtree: false });
  timer = setTimeout(report, 800);
  results.addEventListener('click', async (e) => {
    const li = e.target.closest('li.product[data-id]');
    if (!li || !e.target.closest('a') || e.target.closest('.add_to_cart_button')) return;
    const p = (await byId()).get(Number(li.dataset.id));
    if (p) push('select_item', { ecommerce: { item_list_name: listName(), items: [item(p, $$('li.product', results).indexOf(li), 1, listName())] } });
  });
}

// Élő keresés
const search = $('#search-input');
if (search) {
  let t;
  search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { const q = search.value.trim(); if (q.length >= 3) { push('search', { search_term: q }); meta('track', 'Search', { search_string: q.slice(0, 100) }); } }, 1200); });
}

// Termékoldal (ha a GTM4WP nem méri)
const productId = Number($('[data-cart-form]')?.dataset.productId || $('[data-product-view]')?.dataset.productView || 0);
if (T.viewItem && productId) {
  byId().then((map) => { const p = map.get(productId); if (p) push('view_item', { ecommerce: { currency: M.currency || 'HUF', value: Number(p.price) || 0, items: [item(p)] } }); });
}
