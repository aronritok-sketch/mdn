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
  let seen = '';
  const data = await net.getAddToCart({ id: 42, qty: 3, fields: new URLSearchParams({ mandala_sub: '2', 'add-to-cart': '42' }), fetchImpl: async (u, o) => { seen = u; return { ok: true, json: async () => ({ fragments: { a: 'b' }, cart_hash: 'h2', method: o?.method || 'GET' }) }; } });
  const q = new URL(seen).searchParams;
  ok(data?.cart_hash === 'h2' && data.method === 'GET' && q.get('wc-ajax') === 'add_to_cart' && q.get('product_id') === '42' && q.get('quantity') === '3' && q.get('mfb') === '1' && q.get('mandala_sub') === '2' && !q.has('add-to-cart'), 'GET ismétlés: wc-ajax=add_to_cart&product_id&quantity&mfb + az űrlap mezői', seen);
  const bad = await net.getAddToCart({ id: 1, fetchImpl: async () => ({ ok: false, status: 403 }) });
  ok(bad === null, 'GET ismétlés: ha az is hibás, null');
}

{
  const run = async (opts) => {
    const log = { probe: 0, retry: 0, nav: null, added: null };
    const out = await net.recoverAddToCart({
      before: 'h1', fallbackUrl: 'FALLBACK', wait: async () => {},
      probe: async () => { log.probe++; return opts.probe ?? null; },
      retry: async () => { log.retry++; return opts.retry ?? null; },
      navigate: (u) => { log.nav = u; }, onAdded: (d) => { log.added = d; },
      ...opts.args,
    });
    return { out, ...log };
  };
  let r = await run({ args: { status: 403, blocked: true }, retry: { fragments: { x: 1 }, cart_hash: 'h2' } });
  ok(r.out === 'retried' && r.probe === 0 && r.retry === 1 && !r.nav && r.added?.cart_hash === 'h2', 'tiltás: nincs kosár-ellenőrzés (biztosan nem ment be), GET ismétlés beteszi');
  r = await run({ args: { status: 403, blocked: true }, retry: null });
  ok(r.out === 'navigate' && r.nav === 'FALLBACK', 'tiltás + a GET ismétlés sem megy: oldalbetöltés a GET címre');
  r = await run({ args: { status: 403, blocked: true }, retry: { error: true, product_url: 'https://mandala.test/termek/x/' } });
  ok(r.out === 'navigate' && r.nav === 'https://mandala.test/termek/x/', 'GET ismétlés: elfogyott / hibás → a termék oldala (ott a WooCommerce üzenete)');
  r = await run({ args: { status: 502 }, probe: { cart_hash: 'h9', fragments: {} } });
  ok(r.out === 'added' && r.retry === 0 && !r.nav, '5xx, de a kosár lenyomata változott: nem tesszük be újra (nincs dupla)');
  r = await run({ args: { status: 500 }, probe: { cart_hash: 'h1', fragments: {} }, retry: { fragments: {}, cart_hash: 'h2' } });
  ok(r.out === 'retried' && r.probe === 1 && r.retry === 1, '5xx, a kosár nem változott: GET ismétlés');
  r = await run({ args: { status: 0 }, probe: null, retry: null });
  ok(r.out === 'navigate' && r.probe === 1 && r.nav === 'FALLBACK', 'hálózati hiba, a kosár sem kérdezhető le: GET oldalbetöltés');
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
}

{
  const review = net.checkoutTroubleHtml({ kind: 'review', blocked: true });
  const order = net.checkoutTroubleHtml({ kind: 'order', blocked: true });
  const lost = net.checkoutTroubleHtml({ kind: 'order', blocked: false });
  ok(/Most nem értük el a pénztárt/.test(review) && /tel:\+36308928385/.test(review) && /mailto:info@mandala\.hu/.test(review) && /data-net-retry="review"/.test(review), 'pénztár üzenet: frissítés – újra, telefon, e-mail, gomb');
  ok(/Még nem jött létre/.test(order) && /data-net-retry="order"/.test(order), 'pénztár üzenet: tiltott rendelés – „még nem jött létre”');
  ok(/mégis megérkezett/.test(lost) && !/Még nem jött létre/.test(lost), 'pénztár üzenet: válasz nélküli rendelés – nézd meg a postafiókod (nem ígérjük, hogy nem jött létre)');
  ok(!/\bAI\b|mesterséges/i.test(review + order + lost), 'pénztár üzenet: emberi hang');
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
      on(evts, fn) { evts.split(/\\s+/).forEach((e) => handlers.push({ e, el: els[0], fn })); return api; },
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
      if (new RegExp(re).test(u.pathname + u.search)) return fn(route, u);
    }
    if (u.pathname === '/' || u.pathname.startsWith('/kategoria/') || u.pathname.startsWith('/penztar/')) {
      return route.fulfill({ status: 200, contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><script>window.MANDALA=${JSON.stringify(MANDALA)};try{localStorage.setItem('mandala.cookie.v1','{"stats":false}')}catch(e){}</script><script>${JQUERY_STUB}</script>
        <script type="importmap">{"imports":{"mandala-site":"/js/site.js"}}</script></head><body>${body}<script type="module" src="/js/${module}"></script></body></html>` });
    }
    return route.fulfill({ status: 404, body: '' });
  });
  return { page, log, ctx };
}
const settle = (page, ms = 1500) => page.waitForTimeout(ms);

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

// 2b) Ha a GET ismétlés sem megy: oldalbetöltés a GET címre (?add-to-cart=…&mfb=1).
{
  const card = '<span data-cart-count>0</span><ul><li class="product"><a href="?add-to-cart=42" data-product_id="42" data-quantity="2" class="ajax_add_to_cart">Kosárba</a></li></ul>';
  const { page, log, ctx } = await unitPage(card, 'site.js', { routes: {
    'wc-ajax=add_to_cart&.*mfb=1': (route) => route.fulfill({ status: 403, contentType: 'text/html', body: NGINX_403 }),
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

await browser.close();
console.log(fails ? `\n${fails} hiba` : '\nMinden rendben.');
process.exit(fails ? 1 : 0);
