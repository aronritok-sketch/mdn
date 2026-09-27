// Terheléses teszt: valószerű látogatói forgalom + vásárlási roham egy telepített Mandala boltra.
// Csak tesztkörnyezetben / stagingen futtasd (valódi rendeléseket ad le utánvéttel)!
//   BASE=http://localhost:8091 VUS=30 DURATION=60 BUYERS=3 node tests/load.mjs
// VUS: párhuzamos böngésző látogató (gondolkodási idő nélkül – ez a legrosszabb eset),
// BUYERS: ebből ennyi folyamatosan vásárol (kosár → pénztár → rendelés → készletcsökkenés),
// BOTS: ennyi keresőrobot járja a termékoldalakat. PROF=1: X-Prof fejléc (a tesztkörnyezet lekérdezés-naplójához). A kimenet lépésenként: db, hiba, p50/p95/p99, max.
const BASE = (process.env.BASE || 'http://localhost:8091').replace(/\/$/, '');
const VUS = Number(process.env.VUS || 20);
const BUYERS = Number(process.env.BUYERS || 2);
const BOTS = Number(process.env.BOTS || 2);
const DURATION = Number(process.env.DURATION || 60) * 1000;
const TIMEOUT = Number(process.env.TIMEOUT || 30) * 1000;

const stats = new Map();
const timeline = [];
const t0 = Date.now();
const rec = (step, ms, ok, code) => {
  const s = stats.get(step) || { n: 0, err: 0, ms: [], codes: {} };
  s.n++; s.ms.push(ms); if (!ok) s.err++; s.codes[code] = (s.codes[code] || 0) + 1;
  stats.set(step, s);
  timeline.push([Date.now() - t0, ms, ok]);
};

class Client {
  constructor() { this.jar = new Map(); }
  cookie() { return [...this.jar].map(([k, v]) => `${k}=${v}`).join('; '); }
  store(res) {
    for (const c of res.headers.getSetCookie?.() || []) {
      const [kv] = c.split(';'); const i = kv.indexOf('=');
      const k = kv.slice(0, i).trim(); const v = kv.slice(i + 1);
      if (/expires=Thu, 01[- ]Jan[- ]1970/i.test(c) || v === 'deleted') this.jar.delete(k); else this.jar.set(k, v);
    }
  }
  async req(step, path, { method = 'GET', body, headers = {}, expect = [200], json = false } = {}) {
    const t = Date.now();
    try {
      const res = await fetch(BASE + path, {
        method, body, redirect: 'manual', signal: AbortSignal.timeout(TIMEOUT),
        headers: { 'User-Agent': 'MandalaLoad/1.0', 'Accept-Encoding': 'gzip', Cookie: this.cookie(), ...(process.env.PROF ? { 'X-Prof': '1' } : {}), ...headers },
      });
      this.store(res);
      const text = await res.text();
      const ok = expect.includes(res.status);
      rec(step, Date.now() - t, ok, res.status);
      return { ok, status: res.status, text, data: json && ok ? JSON.parse(text) : null, location: res.headers.get('location') };
    } catch (e) {
      rec(step, Date.now() - t, false, e.name === 'TimeoutError' ? 'timeout' : 'neterr');
      return { ok: false, status: 0, text: '' };
    }
  }
}

const pick = (a) => a[Math.floor(Math.random() * a.length)];
const chance = (p) => Math.random() < p;
const running = () => Date.now() - t0 < DURATION;

// Kiinduló adatok: kategóriák (a főoldal linkjeiből) és termékek (a kereső indexéből).
const boot = new Client();
const home = await boot.req('előkészítés', '/');
const cats = [...new Set([...home.text.matchAll(/href="[^"]*?(\/kategoria\/[^"?#]+)"/g)].map((m) => m[1]))];
// A kereső indexe ott, ahonnan a böngésző is tölti (statikus fájl vagy REST).
const productsUrl = (home.text.match(/"products":"([^"]+)"/)?.[1] || '/wp-json/mandala/v1/products').replace(/\\\//g, '/');
const productsPath = productsUrl.startsWith('http') ? new URL(productsUrl).pathname + new URL(productsUrl).search : productsUrl;
const index = (await boot.req('előkészítés', productsPath, { json: true })).data || [];
const products = index.map((p) => ({ id: p.id, path: new URL(p.url).pathname, buyable: p.buyable && !p.voucher && !p.workshop && (p.stockQty ?? 1) > 0 }));
const buyable = products.filter((p) => p.buyable);
const words = ['füstölő', 'hangtál', 'mala', 'buddha', 'szantál', 'nag champa', 'lótusz', 'ajándék', 'kezdő hangtál', 'réz', 'csakra', 'sál', 'tömjén', 'xyzqw'];
const filters = ['?szandek=csend', '?eredet=nepal', '?ar_max=10000', '?rendezes=ar', '?szandek=ajandek&eredet=india', '?anyag=rez', '?oldal=2'];
if (!cats.length || !products.length) { console.error('Nincs kategória vagy termék – fut a bolt?', BASE); process.exit(2); }
stats.clear(); timeline.length = 0;

async function visitor() {
  while (running()) {
    const c = new Client();
    await c.req('főoldal', '/');
    const cat = pick(cats);
    await c.req('kategória', cat);
    if (chance(0.5)) await c.req('kategória szűrővel', cat + pick(filters));
    // 301: a rendezvényjegy termékoldala az esemény oldalára irányít (szándékos).
    for (let i = 0; i < 1 + Math.floor(Math.random() * 3); i++) await c.req('termékoldal', pick(products).path, { expect: [200, 301] });
    if (chance(0.35)) await c.req('kereső (index)', productsPath);
    if (chance(0.25)) await c.req('keresés oldal', `/?s=${encodeURIComponent(pick(words))}&post_type=product`);
    if (chance(0.2)) {
      const p = pick(buyable);
      await c.req('kosárba (ajax)', '/?wc-ajax=add_to_cart', { method: 'POST', body: new URLSearchParams({ product_id: p.id, quantity: 1 }), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      await c.req('kosár', '/kosar/');
    }
    if (chance(0.05)) await c.req('gyűjtőoldal', '/gyujtemeny/');
  }
}

async function buyer() {
  let n = 0;
  while (running()) {
    const c = new Client();
    const p = pick(buyable);
    await c.req('termékoldal', p.path, { expect: [200, 301] });
    const add = await c.req('kosárba (ajax)', '/?wc-ajax=add_to_cart', { method: 'POST', body: new URLSearchParams({ product_id: p.id, quantity: 1 }), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
    if (!add.ok) continue;
    const co = await c.req('pénztár', '/penztar/');
    const nonce = co.text.match(/name="woocommerce-process-checkout-nonce" value="([^"]+)"/)?.[1];
    const ship = co.text.match(/name="shipping_method\[0\]"[^>]*value="([^"]+)"/)?.[1] || '';
    if (!nonce) { rec('rendelés leadása', 0, false, 'nincs nonce'); continue; }
    n++;
    const form = new URLSearchParams({
      billing_last_name: 'Terhelés', billing_first_name: `Vevő${n}`, billing_country: 'HU', billing_postcode: '1111', billing_city: 'Budapest',
      billing_address_1: 'Teszt utca 1.', billing_phone: '+36301234567', billing_email: `load${Date.now()}${n}@example.com`,
      'shipping_method[0]': ship, payment_method: 'cod', terms: 'on', 'terms-field': '1',
      'woocommerce-process-checkout-nonce': nonce, _wp_http_referer: '/?wc-ajax=update_order_review',
    });
    const r = await c.req('rendelés leadása', '/?wc-ajax=checkout', { method: 'POST', body: form, headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, json: true });
    if (r.data && r.data.result !== 'success') { const s = stats.get('rendelés leadása'); s.err++; s.codes['wc hiba'] = (s.codes['wc hiba'] || 0) + 1; if (!globalThis.shown) { globalThis.shown = 1; console.error('Pénztár hiba:', String(r.data.messages || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 300)); } }
    else if (r.data?.redirect) await c.req('köszönőoldal', new URL(r.data.redirect, BASE).pathname + new URL(r.data.redirect, BASE).search);
  }
}

async function bot() {
  const c = new Client();
  while (running()) await c.req('robot: termékoldal', pick(products).path, { expect: [200, 301] });
}

console.log(`Terhelés: ${BASE} · ${VUS} látogató + ${BUYERS} vásárló + ${BOTS} robot · ${DURATION / 1000} s · ${products.length} termék, ${cats.length} kategória`);
await Promise.all([...Array(VUS)].map(visitor).concat([...Array(BUYERS)].map(buyer), [...Array(BOTS)].map(bot)));

const pct = (a, p) => a[Math.min(a.length - 1, Math.floor(a.length * p))];
const secs = (Date.now() - t0) / 1000;
let total = 0, errs = 0;
console.log(`\n${'lépés'.padEnd(22)} ${'db'.padStart(6)} ${'hiba'.padStart(5)} ${'p50'.padStart(6)} ${'p95'.padStart(6)} ${'p99'.padStart(6)} ${'max'.padStart(6)}  kódok`);
for (const [step, s] of stats) {
  const a = s.ms.sort((x, y) => x - y); total += s.n; errs += s.err;
  console.log(`${step.padEnd(22)} ${String(s.n).padStart(6)} ${String(s.err).padStart(5)} ${String(pct(a, 0.5)).padStart(6)} ${String(pct(a, 0.95)).padStart(6)} ${String(pct(a, 0.99)).padStart(6)} ${String(a[a.length - 1]).padStart(6)}  ${Object.entries(s.codes).map(([k, v]) => `${k}:${v}`).join(' ')}`);
}
const all = timeline.map((t) => t[1]).sort((x, y) => x - y);
console.log(`\nÖsszesen ${total} kérés, ${(total / secs).toFixed(1)} kérés/s, hiba ${errs} (${((errs / total) * 100).toFixed(2)}%), p50 ${pct(all, 0.5)} ms, p95 ${pct(all, 0.95)} ms, p99 ${pct(all, 0.99)} ms`);
// Idősor 10 mp-es szeletekben: a gyorsítótár-újraépítések kiugrásai itt látszanak.
const buckets = [];
for (const [at, ms, ok] of timeline) { const b = (buckets[Math.floor(at / 10000)] ||= { ms: [], err: 0 }); b.ms.push(ms); if (!ok) b.err++; }
console.log('Idősor (10 s):', buckets.map((b, i) => b ? `${i * 10}s: ${b.ms.length} kérés p95 ${pct(b.ms.sort((x, y) => x - y), 0.95)} ms${b.err ? ` hiba ${b.err}` : ''}` : '').filter(Boolean).join(' | '));
process.exit(errs / total > 0.01 ? 1 : 0);
