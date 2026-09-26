// Mérés (GA4 e-kereskedelmi események a dataLayer-be) a téma saját, AJAX-os felületeihez, amelyeket
// a GTM4WP nem lát: szűrt terméklista (view_item_list / select_item), termékoldali és
// ajándékcsomag / viszonteladói kosárba tétel (add_to_cart), élő keresés (search), és – ha a
// GTM4WP nem aktív – a termékoldal megtekintése (view_item). A címkéket a GTM a hozzájárulás
// (Consent Mode v2) szerint futtatja; a témát ez nem befolyásolja.
import { $, $$, loadProducts } from './env.js';

const M = window.MANDALA || {};
const T = M.track || {};
window.dataLayer = window.dataLayer || [];

function push(event, data) {
  window.dataLayer.push({ ecommerce: null });
  window.dataLayer.push({ event, ...data });
}
const item = (p, i = 0, qty = 1, list = '') => ({
  item_id: p.sku || String(p.id), item_name: p.name, item_category: p.catLabel || p.cat || '', item_category2: p.sub || '',
  item_brand: M.siteName || '', price: Number(p.price) || 0, quantity: qty, index: i, ...(list ? { item_list_name: list } : {}),
});
const byId = async () => new Map((await loadProducts()).map((p) => [p.id, p]));

// Kosárba (termékoldal, ajándékcsomag, viszonteladói gyorsrendelő)
document.addEventListener('mandala:cart-add', async (e) => {
  const map = await byId();
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
  search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { const q = search.value.trim(); if (q.length >= 3) push('search', { search_term: q }); }, 1200); });
}

// Termékoldal (ha a GTM4WP nem méri)
const productId = Number($('[data-cart-form]')?.dataset.productId || $('[data-product-view]')?.dataset.productView || 0);
if (T.viewItem && productId) {
  byId().then((map) => { const p = map.get(productId); if (p) push('view_item', { ecommerce: { currency: M.currency || 'HUF', value: Number(p.price) || 0, items: [item(p)] } }); });
}
