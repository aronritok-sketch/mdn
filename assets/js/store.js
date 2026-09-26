// Adatréteg: termékek, kosár, kedvencek, kupon, összesítés, rendelések.
// A böngészős tárolás csak a prototípushoz kell: élesben a WooCommerce session
// és a Store API (/cart, /checkout) veszi át a szerepét.
import { CONFIG, PRODUCTS, CATEGORIES } from './data.js';

const KEYS = { cart: 'mandala.cart.v2', wish: 'mandala.wish.v1', coupon: 'mandala.coupon.v1', orders: 'mandala.orders.v1', draft: 'mandala.checkout.v1', cookie: 'mandala.cookie.v1' };

// ---------- Tárolás (localStorage, ha nem elérhető: memória) ----------
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
export { KEYS };

// ---------- Formázás ----------
// Ezres tagolás 4 jegyű számoknál is (3 940 Ft), ahogy a jelenlegi mandala.hu-n.
export const fmtNum = (n) => String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
export const fmt = (n) => `${fmtNum(n)} ${CONFIG.currency}`;
/** WooCommerce árképzés markupja (woocommerce-Price-amount). */
export const priceHtml = (n) => `<span class="woocommerce-Price-amount amount"><bdi>${fmtNum(n)}&nbsp;<span class="woocommerce-Price-currencySymbol">${CONFIG.currency}</span></bdi></span>`;
export const vatOf = (gross) => Math.round((gross * CONFIG.vatRate) / (100 + CONFIG.vatRate));
export const norm = (s = '') => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

// ---------- Termékek ----------
let productsPromise;
export function loadProducts() {
  productsPromise ??= CONFIG.woocommerce
    ? fetchWooProducts(CONFIG.woocommerce).catch((err) => { console.warn('WooCommerce betöltés sikertelen, mintaadatok.', err); return PRODUCTS; })
    : Promise.resolve(PRODUCTS);
  return productsPromise;
}

async function fetchWooProducts(base) {
  const all = [];
  for (let page = 1; page < 20; page++) {
    // Megjegyzés: az iu_theme egyedi REST prefixet használ – élesben rest_url()-ből kell venni.
    const res = await fetch(`${base}/wp-json/wc/store/v1/products?per_page=100&page=${page}`);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const batch = await res.json();
    all.push(...batch.map(mapWooProduct));
    if (batch.length < 100) break;
  }
  return all;
}

const mainSlugs = new Set(CATEGORIES.map((c) => c.slug));
const subSlugs = new Map(CATEGORIES.flatMap((c) => c.subs.map(([s]) => [s, c.slug])));
const stripTags = (h = '') => h.replace(/<[^>]+>/g, '').trim();
function mapWooProduct(p) {
  const minor = p.prices?.currency_minor_unit ?? 0;
  const num = (v) => Number(v) / 10 ** minor;
  const cats = (p.categories || []).map((c) => c.slug);
  const sub = cats.find((s) => subSlugs.has(s));
  const attr = Object.fromEntries((p.attributes || []).map((a) => [a.name, a.terms?.map((t) => t.name).join(', ')]));
  const origin = (attr.Eredet || '').toLowerCase();
  return {
    id: p.id, slug: p.slug, name: p.name, sku: p.sku,
    cat: cats.find((s) => mainSlugs.has(s)) || subSlugs.get(sub) || CATEGORIES[0].slug, sub,
    price: num(p.prices?.price ?? 0), compare: p.on_sale ? num(p.prices?.regular_price) : undefined,
    origin: origin.includes('nep') ? 'nepal' : 'india', place: attr.Eredet || '',
    image: p.images?.[0]?.src, images: [], art: 'bowl', tone: 'sand',
    stock: p.is_in_stock ? (p.low_stock_remaining ? 'low' : 'in') : 'out', stockQty: p.low_stock_remaining ?? 12,
    intents: [], specs: attr, short: stripTags(p.short_description), description: stripTags(p.description),
  };
}

export const findProduct = (list, slug) => list.find((p) => p.slug === slug);
export const categoryBySlug = (slug) => CATEGORIES.find((c) => c.slug === slug);
export const subLabel = (catSlug, sub) => categoryBySlug(catSlug)?.subs.find(([s]) => s === sub)?.[1] || '';
export const maxQty = (p) => (p.stock === 'out' ? 0 : Math.min(99, p.stockQty || 99));

// ---------- Esemény ----------
const emit = (name, detail) => document.dispatchEvent(new CustomEvent(name, { detail }));
window.addEventListener('storage', (e) => {
  if (e.key === KEYS.cart) emit('cart:change');
  if (e.key === KEYS.wish) emit('wish:change');
});

// ---------- Kosár ----------
const readCart = () => storage.get(KEYS.cart, []).filter((l) => l && l.id && l.qty > 0);
export const cart = {
  lines: () => readCart(),
  count: () => readCart().reduce((s, l) => s + l.qty, 0),
  qtyOf: (id) => readCart().find((l) => l.id === id)?.qty || 0,
  add(id, qty, limit = 99) {
    const lines = readCart();
    const line = lines.find((l) => l.id === id);
    const next = Math.min(limit, (line?.qty || 0) + qty);
    if (line) line.qty = next; else lines.push({ id, qty: next });
    storage.set(KEYS.cart, lines);
    emit('cart:change');
    return next;
  },
  set(id, qty) {
    storage.set(KEYS.cart, readCart().map((l) => (l.id === id ? { ...l, qty } : l)).filter((l) => l.qty > 0));
    emit('cart:change');
  },
  remove(id) { storage.set(KEYS.cart, readCart().filter((l) => l.id !== id)); emit('cart:change'); },
  clear() { storage.set(KEYS.cart, []); storage.set(KEYS.coupon, null); emit('cart:change'); },
};

// ---------- Kedvencek ----------
export const wishlist = {
  ids: () => storage.get(KEYS.wish, []),
  has: (id) => storage.get(KEYS.wish, []).includes(id),
  toggle(id) {
    const ids = storage.get(KEYS.wish, []);
    const on = !ids.includes(id);
    storage.set(KEYS.wish, on ? [...ids, id] : ids.filter((x) => x !== id));
    emit('wish:change');
    return on;
  },
};

// ---------- Kupon ----------
export const coupon = {
  code: () => storage.get(KEYS.coupon, null),
  /** @returns {{ok: boolean, message: string}} */
  apply(raw, subtotal) {
    const code = String(raw || '').trim().toUpperCase();
    if (!code) return { ok: false, message: 'Add meg a kuponkódot.' };
    const c = CONFIG.coupons[code];
    if (!c) return { ok: false, message: `A(z) „${code}” kupon nem létezik vagy lejárt.` };
    if (c.min && subtotal < c.min) return { ok: false, message: `Ez a kupon ${fmt(c.min)} feletti rendelésre érvényes.` };
    storage.set(KEYS.coupon, code);
    emit('cart:change');
    return { ok: true, message: `Kupon beváltva: ${c.label}.` };
  },
  remove() { storage.set(KEYS.coupon, null); emit('cart:change'); },
};

// ---------- Összesítés ----------
/**
 * Kosár összesítő. Minden összeg bruttó; az ÁFA a végösszeg részeként külön sorban jelenik meg
 * (WooCommerce: „tartalmaz X Ft ÁFA-t”).
 */
export async function totals({ shipping = null, payment = null } = {}) {
  const products = await loadProducts();
  const items = cart.lines().map((l) => ({ ...l, product: products.find((p) => p.id === l.id) })).filter((l) => l.product);
  const subtotal = items.reduce((s, l) => s + l.product.price * l.qty, 0);
  const count = items.reduce((s, l) => s + l.qty, 0);

  let code = coupon.code();
  let discount = 0;
  const c = code && CONFIG.coupons[code];
  if (c && !(c.min && subtotal < c.min)) {
    discount = c.type === 'percent' ? Math.round((subtotal * c.amount) / 100) : Math.min(c.amount, subtotal);
  } else code = null;

  const afterDiscount = subtotal - discount;
  const remaining = Math.max(0, CONFIG.freeShippingFrom - afterDiscount);
  const freeShipping = afterDiscount > 0 && remaining === 0;
  const ship = CONFIG.shipping.find((s) => s.id === shipping);
  const shippingCost = !ship ? null : (freeShipping && ship.free ? 0 : ship.price);
  const pay = CONFIG.payment.find((p) => p.id === payment);
  const fee = pay?.fee && shipping !== 'pickup' ? pay.fee : 0;
  const total = afterDiscount + (shippingCost || 0) + fee;
  return { items, count, subtotal, code, discount, remaining, freeShipping, shippingCost, fee, total, vat: vatOf(total) };
}

// ---------- Rendelések (köszönő oldalhoz) ----------
export const orders = {
  save(order) { const all = storage.get(KEYS.orders, []); all.unshift(order); storage.set(KEYS.orders, all.slice(0, 10)); },
  get: (number) => storage.get(KEYS.orders, []).find((o) => o.number === number),
  last: () => storage.get(KEYS.orders, [])[0],
};
