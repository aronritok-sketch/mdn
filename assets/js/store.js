// Termékadatok betöltése és kosárkezelés.
import { CONFIG, PRODUCTS, CATEGORIES } from './data.js';

const CART_KEY = 'mandala.cart.v1';

export const fmt = (n) => `${Math.round(n).toLocaleString('hu-HU').replace(/ /g, ' ')} ${CONFIG.currency}`;

// ---------- Termékek ----------

let productsPromise;

/** Az összes termék. WooCommerce beállítás esetén a Store API-ból, különben a mintaadatokból. */
export function loadProducts() {
  if (!productsPromise) {
    productsPromise = CONFIG.woocommerce
      ? fetchWooProducts(CONFIG.woocommerce).catch((err) => {
        console.warn('WooCommerce betöltés sikertelen, mintaadatok használata.', err);
        return PRODUCTS;
      })
      : Promise.resolve(PRODUCTS);
  }
  return productsPromise;
}

async function fetchWooProducts(base) {
  const all = [];
  for (let page = 1; page < 20; page++) {
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
  const toNum = (v) => Number(v) / 10 ** minor;
  const cats = (p.categories || []).map((c) => c.slug);
  const sub = cats.find((s) => subSlugs.has(s));
  const attr = Object.fromEntries((p.attributes || []).map((a) => [a.name, a.terms?.map((t) => t.name).join(', ')]));
  const originText = (attr['Eredet'] || attr['Származás'] || '').toLowerCase();
  return {
    id: p.id,
    slug: p.slug,
    name: p.name,
    cat: cats.find((s) => mainSlugs.has(s)) || subSlugs.get(sub) || CATEGORIES[0].slug,
    sub,
    price: toNum(p.prices?.price ?? 0),
    compare: p.on_sale ? toNum(p.prices?.regular_price) : undefined,
    origin: originText.includes('nep') ? 'nepal' : 'india',
    place: attr['Eredet'] || '',
    image: p.images?.[0]?.src,
    images: (p.images || []).map((i) => i.src),
    art: 'bowl',
    tone: 'sand',
    stock: p.is_in_stock ? 'in' : 'out',
    intents: [],
    specs: attr,
    short: stripTags(p.short_description),
    description: stripTags(p.description),
  };
}

export const findProduct = (list, slug) => list.find((p) => p.slug === slug);
export const categoryBySlug = (slug) => CATEGORIES.find((c) => c.slug === slug);
export const subLabel = (catSlug, sub) => categoryBySlug(catSlug)?.subs.find(([s]) => s === sub)?.[1] || '';

// ---------- Kosár ----------

function read() {
  try {
    const v = JSON.parse(localStorage.getItem(CART_KEY) || '[]');
    return Array.isArray(v) ? v.filter((l) => l && l.id && l.qty > 0) : [];
  } catch {
    return [];
  }
}

function write(lines) {
  try { localStorage.setItem(CART_KEY, JSON.stringify(lines)); } catch { /* privát mód: memóriában marad */ }
  memory = lines;
  document.dispatchEvent(new CustomEvent('cart:change', { detail: lines }));
}

let memory = read();

export const cart = {
  lines: () => memory.map((l) => ({ ...l })),
  count: () => memory.reduce((s, l) => s + l.qty, 0),
  add(id, qty = 1) {
    const lines = cart.lines();
    const line = lines.find((l) => l.id === id);
    if (line) line.qty = Math.min(99, line.qty + qty);
    else lines.push({ id, qty });
    write(lines);
  },
  set(id, qty) {
    write(cart.lines().map((l) => (l.id === id ? { ...l, qty: Math.max(0, Math.min(99, qty)) } : l)).filter((l) => l.qty > 0));
  },
  remove(id) { write(cart.lines().filter((l) => l.id !== id)); },
  clear() { write([]); },
};

// Másik fülön történt módosítás szinkronizálása.
window.addEventListener('storage', (e) => {
  if (e.key === CART_KEY) { memory = read(); document.dispatchEvent(new CustomEvent('cart:change', { detail: memory })); }
});

/** Kosársorok termékadatokkal és összesítővel. */
export async function cartDetails() {
  const products = await loadProducts();
  const items = cart.lines()
    .map((l) => ({ ...l, product: products.find((p) => p.id === l.id) }))
    .filter((l) => l.product);
  const subtotal = items.reduce((s, l) => s + l.product.price * l.qty, 0);
  const remaining = Math.max(0, CONFIG.freeShippingFrom - subtotal);
  return { items, subtotal, remaining, freeShipping: subtotal > 0 && remaining === 0 };
}
