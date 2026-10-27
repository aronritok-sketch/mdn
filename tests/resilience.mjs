// ELIN 403 tűrőképesség – egységteszt WordPress nélkül (docs/ATADAS.md: „ELIN 403 – mit lát a vásárló, mit csinál a téma”).
//   node tests/resilience.mjs
// 1. rész: assets/js/net.js Node-ban, csonkolt fetch-csel (felismerés, GET-es cím, tartalék-döntés, jelzés, GET ismétlés).
// 2. rész: a valódi site.js / checkout.js böngészőben (Playwright), csonkolt jQuery-vel és kérésekkel: a WooCommerce
//    kártya-kosárba hibája → GET ismétlés / oldalbetöltés; a pénztár hibája → takarás le, üzenet, újrapróbálás, gomb.
import { createRequire } from 'module';
import { readFileSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const JS = path.join(ROOT, 'wp-theme/mandala/assets/js');

let fails = 0;
const ok = (cond, label, extra = '') => { console.log(`${cond ? '✓' : '✗'} ${label}${extra ? ` – ${extra}` : ''}`); if (!cond) fails++; };
const NGINX_403 = '<html>\r\n<head><title>403 Forbidden</title></head>\r\n<body>\r\n<center><h1>403 Forbidden</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n';

// ---------------------------------------------------------------------------------------------------------------
// 1. net.js Node-ban
// ---------------------------------------------------------------------------------------------------------------
globalThis.window = { MANDALA: { rest: 'https://mandala.test/87sdtz/mandala/v1/', wcAjax: '/?wc-ajax=%%endpoint%%', contact: { phone: '+36 30 892 8385', email: 'info@mandala.hu' } } };
globalThis.location = { href: 'https://mandala.test/kategoria/fustolok/?orderby=price', origin: 'https://mandala.test' };
globalThis.document = { cookie: 'foo=1; woocommerce_cart_hash=h1; woocommerce_items_in_cart=1' };
const net = await import(path.join(JS, 'net.js'));

ok(net.isElinBlock(403, 'text/html', NGINX_403), 'felismerés: az nginx 403-as oldala tiltás');
ok(!net.isElinBlock(403, 'application/json; charset=UTF-8', '{"code":"rest_cookie_invalid_nonce"}'), 'felismerés: a WordPress JSON 403-ja nem tiltás');
ok(!net.isElinBlock(403, 'text/html', '-1'), 'felismerés: a WooCommerce nonce-hibája („-1”) nem tiltás');
ok(!net.isElinBlock(500, 'text/html', NGINX_403) && !net.isElinBlock(200, 'text/html', ''), 'felismerés: más állapotkód nem tiltás');
ok(net.isFailure(0) && net.isFailure(403) && net.isFailure(502) && !net.isFailure(200) && !net.isFailure(404), 'hiba: 0 / 403 / 5xx igen, 200 / 404 nem');
ok(net.endpointOf('https://mandala.test/?wc-ajax=update_order_review') === 'update_order_review' && net.endpointOf('/?foo=1&wc-ajax=checkout') === 'checkout'
  && net.endpointOf('/wp-json/pys-facebook/v1/event') === 'rest' && net.endpointOf('/kosar/') === '', 'végpont a címből');
ok(net.cartHash() === 'h1' && net.cartHash({ cookie: '' }) === '', 'kosár-lenyomat a sütiből');

{
  const fields = new URLSearchParams({ 'add-to-cart': '12', quantity: '9', variation_id: '77', attribute_pa_szin: 'vörös', mandala_sub: '1', security: 'x', empty: '' });
  const u = new URL(net.addToCartUrl({ id: 12, qty: 2, fields, base: 'https://mandala.test/termekek/?orderby=price&add-to-cart=5&mfb=1&attribute_pa_meret=L#top' }));
  const q = u.searchParams;
  ok(q.get('add-to-cart') === '12' && q.get('quantity') === '2' && q.get('mfb') === '1' && q.getAll('add-to-cart').length === 1, 'GET cím: termék, mennyiség, mfb jel (a régi add-to-cart nélkül)');
  ok(q.get('orderby') === 'price' && q.get('variation_id') === '77' && q.get('attribute_pa_szin') === 'vörös' && q.get('mandala_sub') === '1' && !q.has('attribute_pa_meret'), 'GET cím: az oldal szűrői maradnak, az űrlap mezői (változat, előfizetés) mennek', u.search);
  ok(!q.has('security') && !q.has('empty') && u.hash === '', 'GET cím: nonce, üres mező és horgony nélkül');
}

{
  // Személyes adat (utalvány címzettje, üzenet) nem a címben: rövid életű sütiben, a kérés jelével (mfs).
  const doc = { cookie: '' };
  globalThis.document = doc;
  const fields = new URLSearchParams({ mandala_voucher_amount: '10000', mandala_voucher_to_name: 'Kiss Éva', mandala_voucher_to_email: 'eva@example.com', mandala_voucher_message: 'Boldog szülinapot!' });
  const u = new URL(net.addToCartUrl({ id: 99, qty: 1, fields, base: 'https://mandala.test/termek/utalvany/' }));
  const m = doc.cookie.match(/^mandala_fbf=([^;]+);.*Path=\/;.*Max-Age=600/);
  const stash = m ? JSON.parse(decodeURIComponent(m[1])) : null;
  ok(u.searchParams.get('mandala_voucher_amount') === '10000' && !/eva@example|Kiss|szülinap|szulinap/i.test(decodeURIComponent(u.href)), 'GET cím: az utalvány címzettje, e-mailje, üzenete nincs a címben', u.search);
  ok(stash && stash.t === u.searchParams.get('mfs') && stash.f.mandala_voucher_to_email === 'eva@example.com' && stash.f.mandala_voucher_message === 'Boldog szülinapot!' && !('mandala_voucher_amount' in stash.f), 'GET cím: a személyes mezők sütiben, a jel (mfs) párosítja', doc.cookie.slice(0, 60));
  const big = net.stashPrivate(new URLSearchParams({ mandala_voucher_message: 'x'.repeat(5000) }), { doc: { cookie: '' } });
  ok(!big.token && big.pub.some(([k]) => k === 'mandala_voucher_message'), 'GET cím: ha nem fér a sütibe, a mező a címben marad (a kosárba tétel fontosabb)');
  // Csoportos termék: a mennyiségek termékenként (quantity[ID]) – nincs mellettük egyetlen „quantity”, ami felülírná őket.
  const g = new URL(net.addToCartUrl({ id: 20, qty: 1, fields: new URLSearchParams({ 'add-to-cart': '20', 'quantity[21]': '2', 'quantity[22]': '1' }), base: 'https://mandala.test/termek/csomag/' })).searchParams;
  ok(g.get('add-to-cart') === '20' && g.get('quantity[21]') === '2' && g.get('quantity[22]') === '1' && !g.has('quantity') && !g.has('mfs'), 'GET cím: csoportos termék termékenkénti mennyiségekkel');
  ok(net.isCartParam('quantity[21]') && net.isCartParam('mfs'), 'GET cím: a csoportos mennyiség és a süti-jel is lekerül a címből betöltés után');
  globalThis.document = { cookie: 'foo=1; woocommerce_cart_hash=h1; woocommerce_items_in_cart=1' };
}

{
  let seen = '';
  const data = await net.getAddToCart({ id: 42, qty: 3, fields: new URLSearchParams({ mandala_sub: '2', 'add-to-cart': '42' }), fetchImpl: async (u, o) => { seen = u; return { ok: true, json: async () => ({ fragments: { a: 'b' }, cart_hash: 'h2', method: o?.method || 'GET' }) }; } });
  const q = new URL(seen).searchParams;
  ok(data?.cart_hash === 'h2' && data.method === 'GET' && q.get('wc-ajax') === 'add_to_cart' && q.get('product_id') === '42' && q.get('quantity') === '3' && q.get('mfb') === '1' && q.get('mandala_sub') === '2' && !q.has('add-to-cart'), 'GET ismétlés: wc-ajax=add_to_cart&product_id&quantity&mfb + az űrlap mezői', seen);
  const bad = await net.getAddToCart({ id: 1, fetchImpl: async () => ({ ok: false, status: 403 }) });
  ok(bad === null, 'GET ismétlés: ha az is hibás, null');
}

{
  const run = async (opts) => {
    const log = { probe: 0, retry: 0, nav: null, added: null, unknown: 0 };
    // probe: egy érték (minden próbára ugyanaz) vagy lista (próbánként; a lista vége után null).
    const probes = Array.isArray(opts.probe) ? [...opts.probe] : null;
    const out = await net.recoverAddToCart({
      before: 'h1', fallbackUrl: 'FALLBACK', wait: async () => {},
      probe: async () => { log.probe++; return probes ? (probes.length ? probes.shift() : null) : (opts.probe ?? null); },
      retry: async () => { log.retry++; return opts.retry ?? null; },
      navigate: (u) => { log.nav = u; }, onAdded: (d) => { log.added = d; }, onUnknown: () => { log.unknown++; },
      ...opts.args,
    });
    return { out, ...log };
  };
  let r = await run({ args: { status: 403, blocked: true }, retry: { fragments: { x: 1 }, cart_hash: 'h2' } });
  ok(r.out === 'retried' && r.probe === 0 && r.retry === 1 && !r.nav && r.added?.cart_hash === 'h2', 'tiltás: nincs kosár-ellenőrzés (biztosan nem ment be), GET ismétlés beteszi');
  r = await run({ args: { status: 403, blocked: true }, retry: null, probe: { cart_hash: 'h1', fragments: {} } });
  ok(r.out === 'navigate' && r.nav === 'FALLBACK', 'tiltás + a GET ismétlés sem megy, a kosár nem változott: oldalbetöltés a GET címre');
  r = await run({ args: { status: 403, blocked: true }, retry: { error: true, product_url: 'https://mandala.test/termek/x/' } });
  ok(r.out === 'navigate' && r.nav === 'https://mandala.test/termek/x/', 'GET ismétlés: elfogyott / hibás → a termék oldala (ott a WooCommerce üzenete)');
  r = await run({ args: { status: 502 }, probe: { cart_hash: 'h9', fragments: {} } });
  ok(r.out === 'added' && r.retry === 0 && !r.nav, '5xx, de a kosár lenyomata változott: nem tesszük be újra (nincs dupla)');
  r = await run({ args: { status: 500 }, probe: { cart_hash: 'h1', fragments: {} }, retry: { fragments: {}, cart_hash: 'h2' } });
  ok(r.out === 'retried' && r.probe === 1 && r.retry === 1, '5xx, a kosár nem változott: GET ismétlés');
  r = await run({ args: { status: 0 }, probe: null, retry: null });
  ok(r.out === 'unknown' && r.probe === 2 && r.retry === 0 && !r.nav && r.unknown === 1, 'hálózati hiba, a kosár két próbával sem kérdezhető le: nem tesszük be újra (ismeretlen – nézze meg a kosarát)');
  r = await run({ args: { status: 502 }, probe: [null, { cart_hash: 'h7', fragments: {} }] });
  ok(r.out === 'added' && r.probe === 2 && r.retry === 0 && !r.nav, '5xx, az első kosár-lekérés hibás, a második szerint bent van: nincs dupla');
  r = await run({ args: { status: 403, blocked: true }, retry: null, probe: { cart_hash: 'h3', fragments: {} } });
  ok(r.out === 'added' && r.retry === 1 && !r.nav && r.added?.cart_hash === 'h3', 'tiltás, a GET ismétlésre nem jött válasz, de a kosár szerint bekerült: nincs oldalbetöltés (nincs dupla)');
  r = await run({ args: { status: 403, blocked: true }, retry: null, probe: null });
  ok(r.out === 'unknown' && !r.nav && r.unknown === 1, 'tiltás, a GET ismétlésre sem jött válasz, és a kosár sem kérdezhető le: nincs oldalbetöltés');
  r = await run({ args: { status: 403, blocked: true, fallbackUrl: () => 'LAZY' }, retry: null, probe: { cart_hash: 'h1', fragments: {} } });
  ok(r.out === 'navigate' && r.nav === 'LAZY', 'tiltás, a kosár nem változott: oldalbetöltés (a cím csak ekkor készül)');
}

{
  const st = await net.orderState({ fetchImpl: async (u) => ({ ok: /wc-ajax=mandala_order_state/.test(u), json: async () => ({ placed: true, url: 'https://mandala.test/penztar/order-received/5/?key=k', cart_hash: '' }) }) });
  ok(st?.placed === true && /order-received/.test(st.url), 'rendelés állapota: GET wc-ajax=mandala_order_state');
  ok(await net.orderState({ fetchImpl: async () => { throw new Error('offline'); } }) === null, 'rendelés állapota: hálózati hibánál null (ismeretlen)');
}

{
  net._resetBeacon();
  const urls = [];
  const send = (u) => urls.push(u);
  const a = net.reportBlocked('add_to_cart', { send });
  const b = net.reportBlocked('add_to_cart', { send });
  net.reportBlocked('update_order_review', { send });
  net.reportBlocked('valami_mas', { send });
  ok(a && !b && urls.length === 3, 'jelzés: oldalanként végpontonként egyszer');
  const q = urls.map((u) => new URL(u).searchParams);
  ok(urls[0].startsWith('https://mandala.test/87sdtz/mandala/v1/blocked?') && q[0].get('e') === 'add_to_cart' && q[0].get('f') === '1' && q[1].get('f') === '0' && q[2].get('e') === 'other', 'jelzés: GET a REST címre, első jel f=1, ismeretlen végpont → other');
  ['checkout', 'cart', 'rest', 'apply_coupon', 'admin_ajax'].forEach((e) => net.reportBlocked(e, { send }));
  ok(urls.length === 6, 'jelzés: oldalanként legfeljebb 6', String(urls.length));
  net._resetBeacon();
  const rest = window.MANDALA.rest; window.MANDALA.rest = '';
  ok(!net.reportBlocked('checkout', { send }), 'jelzés: REST cím nélkül nem küld');
  window.MANDALA.rest = rest;
  net._resetBeacon();
}

{
  const calls = [];
  const mk = (status, ct, body) => ({ status, ok: status < 300, headers: { get: () => ct }, clone() { return { text: async () => body }; }, text: async () => body });
  let res = await net.wcPost('mandala_set_qty', new URLSearchParams({ key: 'k1', qty: '2', security: 'n' }), { fetchImpl: async (u, o) => { calls.push([o?.method || 'GET', u]); return calls.length === 1 ? mk(403, 'text/html', NGINX_403) : mk(200, 'application/json', '{}'); } });
  const getUrl = new URL(calls[1]?.[1] || 'https://x/');
  ok(res.status === 200 && calls[0][0] === 'POST' && calls[1][0] === 'GET' && getUrl.searchParams.get('wc-ajax') === 'mandala_set_qty' && getUrl.searchParams.get('key') === 'k1' && getUrl.searchParams.get('security') === 'n' && getUrl.searchParams.get('mfb') === '1', 'saját végpont: tiltáskor ugyanaz GET-tel (nonce-szal, mfb jellel)');
  calls.length = 0;
  res = await net.wcPost('mandala_set_qty', new URLSearchParams({ key: 'k1' }), { fetchImpl: async (u, o) => { calls.push(o?.method); return mk(403, 'application/json', '{"ok":false}'); } });
  ok(res.status === 403 && calls.length === 1, 'saját végpont: a szerver saját 403-ja (nonce) után nincs GET ismétlés');
  net._resetBeacon();
  calls.length = 0;
  globalThis.document = { cookie: '' };
  await net.wcPost('apply_coupon', new URLSearchParams({ security: 'n', coupon_code: 'hot10', billing_email: 'anna@example.com' }), { omit: ['billing_email'], fetchImpl: async (u, o) => { calls.push([o?.method || 'GET', u]); return calls.length === 1 ? mk(403, 'text/html', NGINX_403) : mk(200, 'text/html', 'ok'); } });
  let q = new URL(calls[1]?.[1] || 'https://x/').searchParams;
  ok(calls[1]?.[0] === 'GET' && q.get('coupon_code') === 'hot10' && q.get('security') === 'n' && !q.has('billing_email') && !/anna@/.test(calls[1][1]), 'kupon: tiltáskor GET-tel (a híd beváltja), az e-mail-cím nélkül');
  calls.length = 0;
  await net.wcPost('mandala_capture', new URLSearchParams({ email: 'anna@example.com', name: 'Anna', security: 'n' }), { fetchImpl: async (u, o) => { calls.push([o?.method || 'GET', u]); return calls.length === 1 ? mk(403, 'text/html', NGINX_403) : mk(200, 'application/json', '{}'); } });
  q = new URL(calls[1]?.[1] || 'https://x/').searchParams;
  ok(calls[1]?.[0] === 'GET' && q.get('security') === 'n' && q.get('mfs') && !/anna|Anna/.test(calls[1][1]) && /mandala_fbf=/.test(document.cookie), 'pénztár e-mail: tiltáskor GET-tel, az e-mail és a név sütiben (nem a címben)', calls[1]?.[1]);
  globalThis.document = { cookie: 'foo=1; woocommerce_cart_hash=h1; woocommerce_items_in_cart=1' };
  net._resetBeacon();
}

{
  const review = net.checkoutTroubleHtml({ kind: 'review', blocked: true });
  const order = net.checkoutTroubleHtml({ kind: 'order', blocked: true });
  const lost = net.checkoutTroubleHtml({ kind: 'order', blocked: false });
  ok(/Most nem értük el a pénztárt/.test(review) && /tel:\+36308928385/.test(review) && /mailto:info@mandala\.hu/.test(review) && /data-net-retry="review"/.test(review), 'pénztár üzenet: frissítés – újra, telefon, e-mail, gomb');
  ok(/Még nem jött létre/.test(order) && /data-net-retry="order"/.test(order), 'pénztár üzenet: tiltott rendelés – „még nem jött létre”');
  ok(/mégis megérkezett/.test(lost) && !/Még nem jött létre/.test(lost), 'pénztár üzenet: válasz nélküli rendelés – nézd meg a postafiókod (nem ígérjük, hogy nem jött létre)');
  const expired = net.checkoutTroubleHtml({ kind: 'review', expired: true });
  ok(/régóta nyitva/.test(expired) && /data-net-retry="reload"/.test(expired) && !/másik internet/.test(expired), 'pénztár üzenet: lejárt ellenőrző kód – frissítés, nem „válts internetet”');
  ok(/data-check="1"/.test(lost) && !/data-check/.test(order), 'pénztár üzenet: a válasz nélküli rendelés újraküldés előtt ellenőriz');
  ok(/megérkezett/.test(net.orderPlacedHtml({ url: 'https://x/' })) && /ne add le újra/.test(net.orderPlacedHtml()), 'pénztár üzenet: a rendelés mégis létrejött – ne add le újra');
  ok(!/\bAI\b|mesterséges/i.test(review + order + lost + expired), 'pénztár üzenet: emberi hang');
}

// ---------------------------------------------------------------------------------------------------------------
// 2. A valódi modulok böngészőben, csonkolt jQuery-vel
// ---------------------------------------------------------------------------------------------------------------
const { chromium } = require(process.env.PWPATH || 'playwright');
const browser = await chromium.launch();
const ORIGIN = 'https://unit.mandala.test';
const JQUERY_STUB = `(() => {
  const handlers = []; const ajaxErr = [];
  const wrap = (target) => {
    const els = typeof target === 'string' ? [...document.querySelectorAll(target)] : (target && target.nodeType ? [target] : (target === document ? [document] : []));
    const api = {
      0: els[0], length: els.length,
      on(evts, fn) { evts.split(/\\s+/).forEach((e) => handlers.push({ e: e.split('.')[0], ns: e.split('.')[1] || '', el: els[0], fn })); return api; },
      one(evt, fn) { const h = { e: evt.split('.')[0], ns: evt.split('.')[1] || '', el: els[0] }; h.fn = (...a) => { handlers.splice(handlers.indexOf(h), 1); fn(...a); }; handlers.push(h); return api; },
      off(evt) { const [e, ns = ''] = evt.split('.'); for (let i = handlers.length - 1; i >= 0; i--) { const h = handlers[i]; if (h.el === els[0] && h.e === e && (!ns || h.ns === ns)) handlers.splice(i, 1); } return api; },
      trigger(evt, args = []) { (window.__triggered ||= []).push(evt); handlers.filter((h) => h.e === evt && h.el === els[0]).forEach((h) => h.fn({ type: evt }, ...args)); return api; },
      ajaxError(fn) { ajaxErr.push(fn); return api; },
      each(fn) { els.forEach((el) => fn.call(el)); return api; },
      unblock() { els.forEach((el) => { el.querySelectorAll(':scope > .blockUI').forEach((b) => b.remove()); }); return api; },
    };
    return api;
  };
  wrap.fn = { unblock() {} };
  wrap.__fail = (status, url, body = '', ct = 'text/html', statusText = 'error', data = '') => Promise.all(ajaxErr.map((fn) => fn({}, { status, statusText, responseText: body, getResponseHeader: () => ct }, { url, type: 'POST', data })));
  window.jQuery = wrap;
})();`;
const MANDALA = { rest: `${ORIGIN}/wp-json/mandala/v1/`, wcAjax: '/?wc-ajax=%%endpoint%%', cart: `${ORIGIN}/kosar/`, contact: { phone: '+36 30 892 8385', email: 'info@mandala.hu' }, icons: { check: '<path d="M5 12l5 5L20 7"/>', alert: '<path d="M12 8v5"/>', info: '<path d="M12 8v5"/>' } };

async function unitPage(body, module, { routes = {} } = {}) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const log = { requests: [], beacons: [], errors: [] };
  page.on('pageerror', (e) => log.errors.push(e.message));
  await ctx.route(`${ORIGIN}/**`, async (route) => {
    const u = new URL(route.request().url());
    log.requests.push(`${route.request().method()} ${u.pathname}${u.search}`);
    if (u.pathname.startsWith('/js/')) return route.fulfill({ status: 200, contentType: 'text/javascript', body: readFileSync(path.join(JS, u.pathname.slice(4)), 'utf8') });
    if (u.pathname.startsWith('/wp-json/mandala/v1/blocked')) { log.beacons.push(Object.fromEntries(u.searchParams)); return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    for (const [re, fn] of Object.entries(routes)) {
      if (new RegExp(re).test(u.pathname + u.search) && (await fn(route, u)) !== NEXT) return undefined;
    }
    if (route.request().method() === 'GET' && (u.pathname === '/' || u.pathname.startsWith('/kategoria/') || u.pathname.startsWith('/penztar/') || u.pathname.startsWith('/termek/'))) {
      return route.fulfill({ status: 200, contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><script>window.MANDALA=${JSON.stringify(MANDALA)};try{localStorage.setItem('mandala.cookie.v1','{"stats":false}')}catch(e){}</script><script>${JQUERY_STUB}</script>
        <script type="importmap">{"imports":{"mandala-site":"/js/site.js"}}</script></head><body>${body}<script type="module" src="/js/${module}"></script></body></html>` });
    }
    return route.fulfill({ status: 404, body: '' });
  });
  return { page, log, ctx };
}
const settle = (page, ms = 1500) => page.waitForTimeout(ms);
const NEXT = Symbol('next'); // útvonal-kezelő: „nem az enyém”, mehet az általános oldal

// 2a) Kártya „Kosárba”: a POST-ot a tűzfal tiltja → GET ismétlés AJAX-szal, oldalbetöltés nélkül.
{
  const card = '<header><span class="cart-toggle"><span data-cart-count>0</span></span></header><ul><li class="product"><h3 class="loop-product-title">Palo Santo füstölő</h3><a href="?add-to-cart=42" data-product_id="42" data-quantity="1" class="add_to_cart_button ajax_add_to_cart loading">Kosárba</a></li></ul>';
  const { page, log, ctx } = await unitPage(card, 'site.js', { routes: {
    'wc-ajax=add_to_cart&.*mfb=1': (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ fragments: { 'span[data-cart-count]': '<span data-cart-count>1</span>' }, cart_hash: 'h2' }) }),
  } });
  await page.goto(`${ORIGIN}/kategoria/fustolok/`);
  await page.waitForFunction(() => document.querySelector('#cookie-bar') || document.querySelector('.toasts') || window.jQuery);
  await settle(page, 300);
  // Két gyors kattintás (42, majd 43): a sikertelen kérés saját adata (42) dönt, nem az utolsó kattintás.
  await page.evaluate((nginx) => { const $ = window.jQuery; const b = document.querySelector('.ajax_add_to_cart'); $(document.body).trigger('adding_to_cart', [$(b), { product_id: 42, quantity: 1 }]); $(document.body).trigger('adding_to_cart', [$(b), { product_id: 43, quantity: 1 }]); return $.__fail(403, '/?wc-ajax=add_to_cart', nginx, 'text/html', 'error', 'product_sku=&product_id=42&quantity=1'); }, NGINX_403);
  await settle(page);
  ok((await page.textContent('[data-cart-count]')) === '1', 'kártya: tiltott POST után a GET ismétlés betette (a fejléc kosara frissült)');
  ok(page.url() === `${ORIGIN}/kategoria/fustolok/`, 'kártya: nem kellett oldalt tölteni');
  ok(/Kosárba tettük/.test(await page.textContent('.toasts').catch(() => '')), 'kártya: a vásárló látja, hogy bekerült');
  ok(log.beacons.length === 1 && log.beacons[0].e === 'add_to_cart' && log.beacons[0].f === '1', 'kártya: egy GET jelzés a tiltásról', JSON.stringify(log.beacons));
  ok(!log.requests.some((r) => /^POST/.test(r)), 'kártya: a tartalék csak GET-et használ');
  ok(log.requests.some((r) => /wc-ajax=add_to_cart&product_id=42&/.test(r)) && !log.requests.some((r) => /product_id=43/.test(r)), 'kártya: a sikertelen kérés terméke megy újra (két gyors kattintás sem keveredik)');
  ok(!log.errors.length, 'kártya: nincs JS hiba', log.errors.join(' | '));
  await ctx.close();
}

// 2b) Ha a GET ismétlés sem megy (és a kosár szerint nem került be): oldalbetöltés a GET címre (?add-to-cart=…&mfb=1).
{
  const card = '<span data-cart-count>0</span><ul><li class="product"><a href="?add-to-cart=42" data-product_id="42" data-quantity="2" class="ajax_add_to_cart">Kosárba</a></li></ul>';
  const { page, log, ctx } = await unitPage(card, 'site.js', { routes: {
    'wc-ajax=add_to_cart&.*mfb=1': (route) => route.fulfill({ status: 500, contentType: 'text/html', body: 'Internal Server Error' }),
    'wc-ajax=get_refreshed_fragments': (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ fragments: {}, cart_hash: '' }) }),
  } });
  await page.goto(`${ORIGIN}/kategoria/fustolok/?orderby=price`);
  await settle(page, 300);
  const nav = page.waitForURL(/add-to-cart=42/, { timeout: 8000 }).catch(() => null);
  await page.evaluate((nginx) => { const $ = window.jQuery; $(document.body).trigger('adding_to_cart', [$(document.querySelector('.ajax_add_to_cart')), { product_id: 42, quantity: 2 }]); return $.__fail(403, '/?wc-ajax=add_to_cart', nginx); }, NGINX_403);
  await nav;
  // A betöltött oldal a címet azonnal megtisztítja (history.replaceState) – a kérésnaplóból nézzük.
  const hit = log.requests.find((r) => /^GET \/kategoria\/fustolok\/\?.*add-to-cart=42/.test(r)) || '';
  const q = new URL(`${ORIGIN}${hit.slice(4)}`).searchParams;
  ok(q.get('add-to-cart') === '42' && q.get('quantity') === '2' && q.get('mfb') === '1' && q.get('orderby') === 'price', 'végső tartalék: oldalbetöltés a GET címre, a szűrők megmaradnak', hit);
  await settle(page, 600);
  ok(!/add-to-cart|mfb/.test(page.url()) && /orderby=price/.test(page.url()), 'végső tartalék: a betöltött oldal címe tiszta (frissítésre nem tesz be újra)', page.url());
  await ctx.close();
}

// 2c) 5xx: előbb a kosár GET-tel – ha már bent van, nem tesszük be újra.
{
  const card = '<span data-cart-count>1</span><ul><li class="product"><a data-product_id="42" data-quantity="1" class="ajax_add_to_cart">Kosárba</a></li></ul>';
  const { page, log, ctx } = await unitPage(card, 'site.js', { routes: {
    'wc-ajax=get_refreshed_fragments': (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ fragments: { 'span[data-cart-count]': '<span data-cart-count>2</span>' }, cart_hash: 'h-new' }) }),
  } });
  await ctx.addCookies([{ name: 'woocommerce_cart_hash', value: 'h-old', url: ORIGIN }]);
  await page.goto(`${ORIGIN}/kategoria/fustolok/`);
  await settle(page, 300);
  await page.evaluate(() => { const $ = window.jQuery; $(document.body).trigger('adding_to_cart', [$(document.querySelector('.ajax_add_to_cart')), { product_id: 42, quantity: 1 }]); return $.__fail(502, '/?wc-ajax=add_to_cart', 'Bad Gateway'); });
  await settle(page, 2000);
  ok((await page.textContent('[data-cart-count]')) === '2' && !log.requests.some((r) => /wc-ajax=add_to_cart&/.test(r)), '5xx: a kosár már változott → nincs második kosárba tétel, csak frissítés');
  ok(!log.beacons.length, '5xx: nem tűzfal-tiltás, nincs jelzés');
  await ctx.close();
}

// 2d) Pénztár: a frissítés (update_order_review) és a rendelés leadása (checkout) hibája.
{
  const form = `<form class="checkout mandala-checkout woocommerce-checkout"><section id="section-shipping"><fieldset><legend>Szállítási mód</legend></fieldset></section>
    <div class="woocommerce-checkout-review-order-table" style="position:relative"><div class="blockUI blockOverlay"></div></div>
    <div class="woocommerce-checkout-payment" style="position:relative"><div class="blockUI blockOverlay"></div></div>
    <button type="submit" id="place_order" disabled class="is-loading">Fizetési kötelezettséggel járó megrendelés</button></form>`;
  const { page, log, ctx } = await unitPage(form, 'checkout.js');
  await page.goto(`${ORIGIN}/penztar/`);
  await settle(page, 400);
  await page.evaluate((nginx) => window.jQuery.__fail(403, '/?wc-ajax=update_order_review', nginx), NGINX_403);
  await settle(page, 200);
  ok(!(await page.$('.blockUI')), 'pénztár: a beragadt szürke takarás eltűnik');
  const map = await page.evaluate(() => { let first = 'ok'; try { window.initOpenMap('x'); } catch (e) { first = e.message; } window.initOpenMap = (a) => `valódi:${a}`; return [first, window.initOpenMap('y')]; });
  ok(map[0] === 'ok' && map[1] === 'valódi:y', 'pénztár: a csomagpont-térkép hívása nem dob hibát; a bővítmény valódi függvénye utána átveszi', map.join(' '));
  const notice = await page.$('#section-shipping .mandala-net-notice[role="alert"]');
  ok(!!notice && /Most nem értük el a pénztárt/.test(await notice.textContent()) && !!(await page.$('.mandala-net-notice a[href="tel:+36308928385"]')) && !!(await page.$('.mandala-net-notice a[href="mailto:info@mandala.hu"]')), 'pénztár: üzenet a szállítási módok helyén (újra, telefon, e-mail)');
  await page.evaluate(() => { window.__triggered = []; });
  await page.click('[data-net-retry="review"]');
  ok((await page.evaluate(() => window.__triggered)).includes('update_checkout') && !(await page.$('.mandala-net-notice')), 'pénztár: „Újrapróbálom” újrakéri a frissítést');
  await page.evaluate(() => window.jQuery.__fail(0, '/?wc-ajax=update_order_review', '', '', 'abort'));
  ok(!(await page.$('.mandala-net-notice')), 'pénztár: a megszakított (felülírt) frissítés nem hiba');
  await page.evaluate((nginx) => { document.querySelector('form.checkout').classList.add('processing'); return window.jQuery.__fail(403, '/?wc-ajax=checkout', nginx); }, NGINX_403);
  await settle(page, 300);
  const top = await page.$eval('form.checkout', (f) => f.firstElementChild?.className || '');
  ok(/mandala-net-notice/.test(top) && /Még nem jött létre/.test(await page.textContent('.mandala-net-notice')), 'rendelés leadása tiltva: üzenet a pénztár tetején („még nem jött létre”)');
  ok(await page.$eval('#place_order', (b) => !b.disabled && !b.classList.contains('is-loading')) && !(await page.$eval('form.checkout', (f) => f.classList.contains('processing'))), 'rendelés leadása: a gomb újra használható');
  await page.evaluate(() => window.jQuery.__fail(504, '/?wc-ajax=checkout', 'Gateway Timeout'));
  await settle(page, 200);
  ok(/mégis megérkezett/.test(await page.textContent('.mandala-net-notice')), 'rendelés leadása, szerver nem válaszol: „nézd meg a postafiókod”');
  ok(log.beacons.length === 0, 'pénztár: a jelzést a site.js küldi (itt nincs betöltve) – a checkout.js nem küld kétszer');
  ok(!log.errors.length, 'pénztár: nincs JS hiba', log.errors.join(' | '));
  await ctx.close();
}

// 2e) Pénztár: a frissítés épp a rendelés leadása közben hibázik – a gombhoz nem nyúlunk (dupla rendelés ellen).
{
  const form = `<form class="checkout mandala-checkout woocommerce-checkout processing"><section id="section-shipping"><fieldset><legend>Szállítási mód</legend></fieldset></section>
    <div class="woocommerce-checkout-payment" style="position:relative"><div class="blockUI blockOverlay"></div></div>
    <button type="submit" id="place_order" disabled class="is-loading">Megrendelés</button></form>`;
  const { page, log, ctx } = await unitPage(form, 'checkout.js');
  await page.goto(`${ORIGIN}/penztar/`);
  await settle(page, 400);
  await page.evaluate(() => window.jQuery.__fail(0, '/?wc-ajax=update_order_review', '', '', 'error'));
  await settle(page, 200);
  ok(await page.$eval('#place_order', (b) => b.disabled) && await page.$eval('form.checkout', (f) => f.classList.contains('processing')) && !(await page.$('.mandala-net-notice')), 'pénztár: rendelés közben a hibás frissítés nem teszi újra kattinthatóvá a gombot, nem villan üzenet');
  // A rendelés hibával tér vissza (a WooCommerce submit_error-ja): utána jön a frissítés üzenete.
  await page.evaluate(() => { document.querySelector('form.checkout').classList.remove('processing'); window.jQuery(document.body).trigger('checkout_error'); });
  await settle(page, 200);
  ok(!!(await page.$('.mandala-net-notice[data-kind="review"]')) && !(await page.$('.blockUI')), 'pénztár: a rendelés hibája után megjelenik a frissítés üzenete, a takarás lekerül');
  // Lejárt ellenőrző kód (a WordPress „-1”-e, 403): frissíteni kell, nem internetet váltani.
  await page.evaluate(() => window.jQuery.__fail(403, '/?wc-ajax=update_order_review', '-1', 'text/html; charset=UTF-8'));
  await settle(page, 200);
  ok(/régóta nyitva/.test(await page.textContent('.mandala-net-notice')) && !!(await page.$('[data-net-retry="reload"]')) && !/másik internet/.test(await page.textContent('.mandala-net-notice')), 'pénztár: lejárt ellenőrző kód – „frissítsd az oldalt”');
  ok(!log.errors.length, 'pénztár (rendelés közben): nincs JS hiba', log.errors.join(' | '));
  await ctx.close();
}

// 2f) Pénztár: a rendelés leadására nem jött válasz (5xx / 0) – újraküldés előtt megnézzük, létrejött-e.
{
  const form = `<form class="checkout mandala-checkout woocommerce-checkout"><section id="section-shipping"><fieldset></fieldset></section>
    <button type="submit" id="place_order">Megrendelés</button></form>`;
  let state = { status: 200, body: { placed: false, url: '', cart_hash: 'h-same' } };
  const { page, log, ctx } = await unitPage(form, 'checkout.js', { routes: {
    'wc-ajax=mandala_order_state': (route) => route.fulfill({ status: state.status, contentType: 'application/json', body: JSON.stringify(state.body) }),
  } });
  await ctx.addCookies([{ name: 'woocommerce_cart_hash', value: 'h-same', url: ORIGIN }]);
  await page.goto(`${ORIGIN}/penztar/`);
  await settle(page, 400);
  await page.evaluate(() => { window.__placeClicks = 0; document.querySelector('#place_order').addEventListener('click', (e) => { e.preventDefault(); window.__placeClicks++; }); });
  // a) nem jött létre („Függőben” rendelés / nincs rendelés, a kosár ugyanaz): „Újrapróbálom” → újraküldés
  await page.evaluate(() => window.jQuery.__fail(502, '/?wc-ajax=checkout', 'Bad Gateway'));
  await settle(page, 300);
  await page.click('[data-net-retry="order"]');
  await settle(page, 400);
  ok((await page.evaluate(() => window.__placeClicks)) === 1 && log.requests.some((r) => /mandala_order_state/.test(r)), 'rendelés válasz nélkül, nem jött létre: ellenőrzés után újraküldés');
  // b) nem kérdezhető le: nem küldjük újra, megmondjuk, miért
  state = { status: 500, body: {} };
  await page.evaluate(() => window.jQuery.__fail(0, '/?wc-ajax=checkout', '', '', 'error'));
  await settle(page, 2000);
  await page.click('[data-net-retry="order"]');
  await settle(page, 2200);
  ok((await page.evaluate(() => window.__placeClicks)) === 1 && /Még mindig nem érjük el a boltot/.test(await page.textContent('.mandala-net-notice')), 'rendelés válasz nélkül, az állapot sem kérdezhető le: nem küldjük újra');
  // c) létrejött (utánvét, „Feldolgozás alatt”): üzenet, a gomb tiltva, és a visszaigazoló oldalra visz
  state = { status: 200, body: { placed: true, url: `${ORIGIN}/penztar/order-received/55/?key=wc_order_x`, cart_hash: 'h-same' } };
  const nav = page.waitForURL(/order-received\/55/, { timeout: 8000 }).catch(() => null);
  await page.evaluate(() => window.jQuery.__fail(500, '/?wc-ajax=checkout', 'Internal Server Error'));
  await page.waitForSelector('.mandala-net-notice[data-kind="placed"]', { timeout: 4000 }).catch(() => {});
  ok(/megérkezett/.test(await page.textContent('.mandala-net-notice').catch(() => '')) && await page.$eval('#place_order', (b) => b.disabled).catch(() => false), 'rendelés válasz nélkül, de létrejött: „A rendelésed megérkezett” – ne add le újra (a gomb tiltva)');
  await nav;
  ok(/order-received\/55/.test(page.url()) && (await page.evaluate(() => window.__placeClicks ?? 0)) === 0, 'rendelés létrejött: a visszaigazoló oldalra visz, nincs második rendelés', page.url());
  await ctx.close();
}

// 2g) Termékoldal, WooCommerce saját űrlapja: változatos termék dupla kattintásra is egyszer; csoportos termék tiltásnál.
{
  const body = `<span data-cart-count>0</span><div id="minicart" class="layer"></div><div class="mandala-variable-cart"><form class="variations_form cart" method="post" action="/termek/szantal/" data-product_id="12">
    <input type="hidden" name="variation_id" value="77"><input type="hidden" name="attribute_pa_meret" value="l"><input type="hidden" name="add-to-cart" value="12"><input name="quantity" value="1">
    <button type="submit" class="single_add_to_cart_button button alt">Kosárba</button></form></div>`;
  let posts = 0;
  const { page, log, ctx } = await unitPage(body, 'product.js', { routes: {
    '^/termek/szantal/$': async (route) => { if (route.request().method() !== 'POST') return NEXT; posts++; await new Promise((r) => setTimeout(r, 600)); return route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body><div class="woocommerce-message">Kosárba téve</div></body></html>' }); },
  } });
  await page.goto(`${ORIGIN}/termek/szantal/`);
  await settle(page, 400);
  await page.dblclick('.single_add_to_cart_button');
  await page.evaluate(() => document.querySelector('form.variations_form').requestSubmit());
  await settle(page, 1200);
  ok(posts === 1, 'változatos termék: dupla kattintásra (és második beküldésre) is egyetlen kérés', String(posts));
  ok(await page.$eval('.single_add_to_cart_button', (b) => !b.disabled && !b.classList.contains('is-loading')), 'változatos termék: utána a gomb újra használható');
  ok(!log.errors.length, 'változatos termék: nincs JS hiba', log.errors.join(' | '));
  await ctx.close();
}
{
  const body = `<span data-cart-count>0</span><div class="mandala-variable-cart"><form class="cart grouped_form" method="post" action="/termek/csomag/">
    <input name="quantity[21]" value="2"><input name="quantity[22]" value="0"><button type="submit" name="add-to-cart" value="20" class="single_add_to_cart_button">Kosárba</button></form></div>`;
  const { page, log, ctx } = await unitPage(body, 'product.js', { routes: {
    '^/termek/csomag/$': (route) => (route.request().method() === 'POST' ? route.fulfill({ status: 403, contentType: 'text/html', body: NGINX_403 }) : NEXT),
  } });
  await page.goto(`${ORIGIN}/termek/csomag/`);
  await settle(page, 400);
  const nav = page.waitForURL(/add-to-cart=20/, { timeout: 8000 }).catch(() => null);
  await page.click('.single_add_to_cart_button');
  await nav;
  const hit = log.requests.find((r) => /^GET \/termek\/csomag\/\?.*add-to-cart=20/.test(r)) || '';
  const q = new URL(`${ORIGIN}${hit.slice(4)}`).searchParams;
  ok(q.get('quantity[21]') === '2' && !q.has('quantity') && q.get('mfb') === '1', 'csoportos termék: a tiltott POST után GET-es oldalbetöltés termékenkénti mennyiséggel (nem fehér 403 oldal)', hit);
  await ctx.close();
}

// 2h) Ajándékcsomag: a POST-ot a tűzfal tiltja → ugyanaz GET-tel, a kártya üzenete nem a címben.
{
  const body = `<form data-gift-builder data-max="6"><input type="checkbox" name="items[]" value="31" data-price="1000" checked><input type="checkbox" name="items[]" value="32" data-price="2000" checked>
    <input type="radio" name="box" value="40" data-price="500" checked><textarea id="gift-message" name="message">Boldog szülinapot, Anna!</textarea><span data-gift-chars></span>
    <p data-gift-count></p><p data-gift-total></p><p data-gift-error></p><button type="submit" data-gift-submit>Kosárba</button></form>`;
  let getUrl = ''; let cookie = '';
  const { page, log, ctx } = await unitPage(body, 'gift.js', { routes: {
    'wc-ajax=mandala_gift_add': (route, u) => {
      if (route.request().method() === 'POST') return route.fulfill({ status: 403, contentType: 'text/html', body: NGINX_403 });
      getUrl = u.href; cookie = route.request().headers().cookie || '';
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, cart: `${ORIGIN}/kosar/` }) });
    },
  } });
  await page.goto(`${ORIGIN}/`);
  await settle(page, 400);
  const nav = page.waitForURL(/\/kosar\//, { timeout: 8000 }).catch(() => null);
  await page.click('[data-gift-submit]');
  await nav;
  const q = new URL(getUrl || 'https://x/').searchParams;
  ok(q.getAll('items[]').join(',') === '31,32' && q.get('box') === '40' && q.get('mfb') === '1' && q.get('mfs') && !/Boldog|Anna/.test(decodeURIComponent(getUrl)), 'ajándékcsomag: tiltáskor GET-tel, az üzenet nem a címben', getUrl);
  ok(/mandala_fbf=/.test(cookie) && decodeURIComponent(cookie).includes('Boldog szülinapot, Anna!'), 'ajándékcsomag: az üzenet a kéréssel sütiben megy');
  ok(/\/kosar\//.test(page.url()) && log.beacons.some((b) => b.e === 'mandala_gift_add'), 'ajándékcsomag: a kosárba került (a kosár oldalára visz), a tiltás jelezve', page.url());
  await ctx.close();
}

await browser.close();
console.log(fails ? `\n${fails} hiba` : '\nMinden rendben.');
process.exit(fails ? 1 : 0);
