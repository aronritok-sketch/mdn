// WordPress + WooCommerce végponttól végpontig teszt a Mandala child témához.
// Futtatás: egy telepített teszt WordPress (mandala téma, `wp mandala setup --demo`) mellett:
//   BASE=http://localhost:8080 node tests/wp-e2e.mjs
// A kimenet: ✓ / ✗ soronként; a végén a hibák száma (kilépési kód 1, ha van hiba).
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const BASE = (process.env.BASE || 'http://localhost:8080').replace(/\/$/, '');

let fails = 0;
const ok = (cond, label, extra = '') => { console.log(`${cond ? '✓' : '✗'} ${label}${extra ? ` – ${extra}` : ''}`); if (!cond) fails++; };
const errors = [];

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
await ctx.addInitScript(() => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify({ stats: false })); } catch {} });
const page = await ctx.newPage();
page.on('pageerror', (e) => errors.push(`PAGEERROR ${page.url()} ${e.message}`));
page.on('console', (m) => { if (m.type() === 'error' && !/ERR_CERT|Failed to load resource/.test(m.text())) errors.push(`CONSOLE ${page.url()} ${m.text()}`); });
page.on('response', (r) => { if (r.status() >= 500) errors.push(`HTTP ${r.status()} ${r.url()}`); });
const cartCount = () => page.$eval('[data-cart-count]', (e) => (e.hidden ? 0 : Number(e.textContent))).catch(() => -1);

// ---------- Kínálat és szűrő ----------
await page.goto(`${BASE}/kategoria/szakralis-targyak/hangtalak/`, { waitUntil: 'networkidle' });
ok((await page.textContent('h1')).includes('Hangtálak'), 'kategória archívum: H1');
await page.waitForSelector('[data-filters] details[data-group="hang"]');
ok(await page.isVisible('[data-filters] [data-group="suly"]'), 'hangtál szűrők láthatók (súly)');
const before = await page.$$eval('[data-results] li.product', (l) => l.length);
await page.click('[data-facet="hang"][data-val="G"]');
await page.waitForTimeout(300);
const after = await page.$$eval('[data-results] li.product', (l) => l.length);
ok(after > 0 && after < before, 'hang szűrő szűkít', `${before} → ${after}`);
ok(/hang=G/.test(page.url()) && page.url().includes('/kategoria/szakralis-targyak/hangtalak/'), 'URL állapot a kategória címén', page.url());
await page.click('[data-filters] [data-cat="lakberendezes"]');
await page.waitForTimeout(300);
ok(page.url().includes('/kategoria/lakberendezes/') && !/hang=/.test(page.url()), 'kategóriaváltás: új URL, hangtál szűrő törölve', page.url());
await page.goBack();
await page.waitForTimeout(300);
ok(/hang=G/.test(page.url()), 'vissza gomb visszaállítja a szűrést');

// ---------- Kosárba (kártya, AJAX) ----------
await page.goto(`${BASE}/termekek/`, { waitUntil: 'networkidle' });
await page.waitForSelector('[data-results] li.product');
const first = page.locator('[data-results] li.product.instock .loop-product-button a.add_to_cart_button').first();
await first.click();
await page.waitForFunction(() => { const c = document.querySelector('[data-cart-count]'); return c && !c.hidden && Number(c.textContent) > 0; }, null, { timeout: 8000 }).catch(() => {});
ok((await cartCount()) === 1, 'kártya: AJAX kosárba, fejléc számláló', String(await cartCount()));
ok(await page.isVisible('.toast'), 'értesítés a kosárba tételről');

// ---------- Termékoldal ----------
await page.goto(`${BASE}/termek/mintas-hangtal-490g-405hz/`, { waitUntil: 'networkidle' });
ok((await page.$$('h1')).length === 1, 'termékoldal: egy H1');
ok(await page.isVisible('.low-stock'), 'utolsó darabok jelzés');
ok((await page.getAttribute('input[name="quantity"]', 'max')) === '2', 'mennyiség készletkorlát (max 2)');
await page.click('form[data-cart-form] [data-step="1"]');
await page.click('form[data-cart-form] [data-step="1"]');
ok((await page.inputValue('input[name="quantity"]')) === '2', 'léptető nem megy a készlet fölé');
await page.click('.single_add_to_cart_button');
await page.waitForSelector('#minicart.is-open', { timeout: 8000 }).catch(() => {});
ok(await page.isVisible('#minicart.is-open'), 'kosárba: minikosár kinyílik');
ok((await cartCount()) === 3, 'kosár: 3 db', String(await cartCount()));
ok(await page.isVisible('#minicart .ship-meter'), 'minikosár: ingyenes szállítás mérő');
ok(await page.$('script[type="application/ld+json"]') !== null, 'Product JSON-LD');

// Minikosár mennyiség csökkentése
const items = await page.$$('#minicart .woocommerce-mini-cart-item');
await page.click('#minicart .woocommerce-mini-cart-item:last-child [data-step="-1"]');
await page.waitForFunction(() => Number(document.querySelector('[data-cart-count]')?.textContent) === 2, null, { timeout: 8000 }).catch(() => {});
ok(items.length === 2 && (await cartCount()) === 2, 'minikosár: mennyiség csökkentése (fragmentek)', String(await cartCount()));
await page.keyboard.press('Escape');

// ---------- Kedvencek ----------
await page.click('.product-wish');
ok((await page.getAttribute('.product-wish', 'aria-pressed')) === 'true', 'kedvencekhez adás');
await page.goto(`${BASE}/kedvencek/`, { waitUntil: 'networkidle' });
ok((await page.$$('[data-wishlist] li.product')).length === 1, 'kedvencek oldal listázza (süti)');

// ---------- Készletértesítő (elfogyott termék) ----------
const outUrl = await page.evaluate(async (base) => { const r = await fetch(`${window.MANDALA.rest}products`); const l = await r.json(); return l.find((p) => p.stock === 'out')?.url; }, BASE);
if (outUrl) {
  await page.goto(outUrl, { waitUntil: 'networkidle' });
  await page.click('form[data-mandala-form] button[type="submit"]');
  ok(await page.isVisible('form[data-mandala-form] .form-message.is-error'), 'készletértesítő: üres beküldés hibát ad');
  await page.fill('#notify-email', 'vevo@example.com');
  await page.check('#notify-accept');
  await page.click('form[data-mandala-form] button[type="submit"]');
  await page.waitForSelector('form[data-mandala-form] .form-message.is-success', { timeout: 8000 }).catch(() => {});
  ok(await page.isVisible('form[data-mandala-form] .form-message.is-success'), 'készletértesítő: feliratkozás');
} else ok(false, 'elfogyott termék a mintában');

// ---------- Élő kereső ----------
await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
await page.keyboard.press('/');
await page.fill('#search-input', 'hangt');
await page.waitForSelector('#search-results .search-hits li', { timeout: 8000 }).catch(() => {});
ok((await page.$$('#search-results .search-hits li')).length > 0, 'élő kereső: termék találatok');
await page.keyboard.press('Escape');

// ---------- Kosár oldal ----------
await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
ok(await page.isVisible('form.woocommerce-cart-form'), 'kosár oldal: klasszikus WooCommerce kosár');
ok(await page.isVisible('.ship-meter'), 'kosár oldal: szállítás mérő');

// ---------- Pénztár ----------
await page.goto(`${BASE}/penztar/`, { waitUntil: 'networkidle' });
ok((await page.$$('h1')).length === 1, 'pénztár: egy H1');
ok((await page.textContent('#billing_last_name_field label')).includes('Vezetéknév'), 'pénztár: magyar címkék nyelvi csomag nélkül');
ok(await page.isVisible('form.mandala-checkout'), 'pénztár: saját 5 lépéses sablon');
const SHOTS = process.env.SHOTS;
if (SHOTS) await page.screenshot({ path: `${SHOTS}/wp-checkout.png`, fullPage: true });
ok((await page.$$('#shipping_method li')).length === 3, 'pénztár: 3 szállítási mód a 2. lépésben (GLS bővítmény + átvétel)');
ok((await page.$$eval('#shipping_method li', (l) => l.map((x) => x.dataset.kind).sort().join(','))) === 'courier,pickup,point', 'szállítási típusok felismerése (futár, pont, átvétel)');
ok((await page.$$('.wc_payment_methods li')).length >= 2, 'pénztár: fizetési módok a 4. lépésben');
const order = await page.$$eval('.woocommerce-billing-fields__field-wrapper .form-row input', (l) => l.filter((i) => i.type !== 'hidden').map((i) => i.id));
ok(order.indexOf('billing_last_name') < order.indexOf('billing_first_name') && order.indexOf('billing_postcode') < order.indexOf('billing_city'), 'magyar mezősorrend (vezetéknév, irsz → település)', order.slice(0, 6).join(','));

// Böngészőoldali ellenőrzés és hibaösszesítő
await page.click('#place_order');
await page.waitForSelector('.woocommerce-NoticeGroup-checkout .woocommerce-error', { timeout: 5000 }).catch(() => {});
ok((await page.$$('.woocommerce-NoticeGroup-checkout li a')).length >= 5, 'üres beküldés: hibaösszesítő linkekkel');

await page.fill('#billing_email', 'teszt@gmial.com');
await page.locator('#billing_email').blur();
ok(await page.isVisible('.field-suggest'), 'e-mail elírás javaslat (gmial → gmail)');
await page.click('.field-suggest button');
ok((await page.inputValue('#billing_email')) === 'teszt@gmail.com', 'javaslat elfogadása');
await page.fill('#billing_phone', '06301234567');
await page.locator('#billing_phone').blur();
ok((await page.inputValue('#billing_phone')).startsWith('+36 30'), 'telefonszám formázás', await page.inputValue('#billing_phone'));
await page.fill('#billing_last_name', 'Kovács');
await page.fill('#billing_first_name', 'Anna');
await page.fill('#billing_postcode', '1052');
ok((await page.inputValue('#billing_city')) === 'Budapest', 'irányítószámból település');
await page.fill('#billing_address_1', 'Váci utca 1.');

// Cég + adószám (ellenőrző számjegy)
await page.check('#is_company');
ok(await page.isVisible('#billing_tax_number'), 'cégként vásárlás: adószám mező');
await page.fill('#billing_company', 'Teszt Kft.');
await page.fill('#billing_tax_number', '12345678-2-41');
await page.locator('#billing_tax_number').blur();
ok((await page.getAttribute('#billing_tax_number', 'aria-invalid')) === 'true', 'hibás adószám jelzése (CDV)');
await page.fill('#billing_tax_number', '12345676-2-41');
await page.locator('#billing_tax_number').blur();
ok((await page.getAttribute('#billing_tax_number', 'aria-invalid')) === null, 'helyes adószám elfogadva');

// Utánvét díja: futárral van, személyes átvételnél nincs („Fizetés átvételkor”)
const waitUpdate = () => page.waitForFunction(() => !document.querySelector('.blockUI'), null, { timeout: 10000 }).then(() => page.waitForTimeout(400));
await page.check('#payment_method_cod');
await waitUpdate();
ok(await page.isVisible('.woocommerce-checkout-review-order-table tr.fee'), 'utánvét díja megjelenik (GLS)');
await page.click('#shipping_method li[data-kind="point"] label');
await waitUpdate();
ok(await page.isVisible('#shipping_method li[data-kind="point"] .method-extra'), 'GLS pont: a bővítmény pontválasztója a mód alatt');
ok((await page.textContent('[data-billing-title]')).includes('Számlázási adatok'), 'GLS pont: számlázási cím (nincs szállítási cím)');
ok((await page.textContent('.payment_method_cod .payment_box')).includes('GLS ponton'), 'GLS pont: utánvét szövege');
await page.click('#shipping_method li[data-kind="pickup"] label');
await waitUpdate();
ok(!(await page.isVisible('.woocommerce-checkout-review-order-table tr.fee')), 'személyes átvétel: nincs utánvét díj');
ok((await page.textContent('.payment_method_cod label')).includes('Fizetés átvételkor'), 'személyes átvétel: „Fizetés átvételkor”');
ok((await page.textContent('[data-billing-title]')).includes('Számlázási adatok'), 'átvételnél a cím szakasz címe változik');

// Kupon az összesítőben
await page.click('[data-coupon-details] summary');
await page.fill('#checkout_coupon', 'NINCSILYEN');
await page.click('[data-coupon-apply]');
await page.waitForTimeout(1200);
ok((await page.textContent('#checkout_coupon-error')).trim().length > 0, 'hibás kupon: hibaüzenet a mező alatt');
await page.fill('#checkout_coupon', 'MANDALA10');
await page.click('[data-coupon-apply]');
await waitUpdate();
ok(await page.isVisible('.woocommerce-checkout-review-order-table tr.cart-discount'), 'MANDALA10 kupon: kedvezmény sor');

// Előre utalás + megrendelés
await page.check('#payment_method_bacs');
await waitUpdate();
ok((await page.textContent('#place_order')).includes('Fizetési kötelezettséggel járó megrendelés'), 'megrendelés gomb szövege');
await page.check('#terms');
await page.click('#place_order');
await page.waitForURL(/rendeles-fogadva|order-received/, { timeout: 20000 }).catch(() => {});
const placed = /order-received|rendeles-fogadva/.test(page.url());
ok(placed, 'rendelés leadva → köszönő oldal', placed ? '' : (await page.textContent('.woocommerce-NoticeGroup').catch(() => '')).replace(/\s+/g, ' ').trim().slice(0, 300));
if (!placed) { await browser.close(); console.log(`\n${fails} HIBA`); process.exit(1); }
ok((await page.textContent('h1')).includes('Köszönjük, Anna'), 'köszönő oldal: megszólítás');
ok((await page.$$('h1')).length === 1, 'köszönő oldal: egy H1');
if (SHOTS) await page.screenshot({ path: `${SHOTS}/wp-thankyou.png`, fullPage: true });
ok(await page.isVisible('.woocommerce-bacs-bank-details'), 'utalási adatok másolás gombokkal');
ok((await page.$$('.copy-btn')).length >= 3, 'másolás gombok');
ok(await page.isVisible('.timeline'), '„Mi történik most?” idővonal');
const totalsText = await page.textContent('.woocommerce-table--order-details tfoot');
ok(/Kedvezmény|Discount/i.test(totalsText) && !/Utánvét/.test(totalsText), 'rendelés összesítő: kupon, díj nélkül');
ok((await page.textContent('.woocommerce-customer-details')).includes('12345676-2-41'), 'számlázási cím: adószám');
ok((await cartCount()) === 0, 'kosár kiürült');

// ---------- Űrlap: kapcsolat (iu/form → iu_form_submit_kapcsolat) ----------
await page.goto(`${BASE}/kapcsolat/`, { waitUntil: 'networkidle' });
await page.fill('form[data-form-id="kapcsolat"] [name="nev"]', 'Teszt Elek');
await page.fill('form[data-form-id="kapcsolat"] [name="email"]', 'elek@example.com');
await page.fill('form[data-form-id="kapcsolat"] [name="message"]', 'Van 400 Hz körüli tál?');
await page.click('form[data-form-id="kapcsolat"] button[type="submit"]');
await page.waitForTimeout(1500);
ok((await page.getAttribute('form[data-form-id="kapcsolat"] .form-message', 'class')).includes('is-error'), 'kapcsolat: adatkezelés elfogadása nélkül hiba');
await page.check('form[data-form-id="kapcsolat"] [name="adatkezeles"]');
await page.click('form[data-form-id="kapcsolat"] button[type="submit"]');
await page.waitForTimeout(1500);
ok((await page.getAttribute('form[data-form-id="kapcsolat"] .form-message', 'class')).includes('is-success'), 'kapcsolat: sikeres beküldés');

// ---------- Viszonteladói ár ----------
const publicIndex = await page.evaluate(async () => (await (await fetch(window.MANDALA.rest + 'products')).json()).find((p) => p.sku === 'MND-HT-0490'));
ok(publicIndex && publicIndex.price === 37340 && !publicIndex.wholesale, 'nyilvános index: bolti ár, nagyker ár nélkül', JSON.stringify({ price: publicIndex?.price }));
const b2b = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
await b2b.addInitScript(() => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify({ stats: false })); } catch {} });
const bp = await b2b.newPage();
await bp.goto(`${BASE}/wp-login.php`);
await bp.fill('#user_login', 'viszontelado');
await bp.fill('#user_pass', 'b2b');
await bp.click('#wp-submit');
await bp.waitForLoadState('networkidle');
await bp.goto(`${BASE}/kategoria/szakralis-targyak/hangtalak/`, { waitUntil: 'networkidle' });
await bp.waitForSelector('[data-results] li.product');
const card = bp.locator('[data-results] li.product', { hasText: 'G#, torokcsakra' });
ok((await card.textContent()).includes('Nagyker ár') && (await card.textContent()).includes('29 000'), 'viszonteladó: nagyker ár a kártyán (szűrő)');
const again = await page.evaluate(async () => (await (await fetch(window.MANDALA.rest + 'products')).json()).find((p) => p.sku === 'MND-HT-0490'));
ok(again.price === 37340, 'viszonteladó látogatása után is bolti ár a nyilvános indexben');
await b2b.close();

// ---------- Mobil ----------
const m = await browser.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
await m.addInitScript(() => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify({ stats: false })); } catch {} });
const mp = await m.newPage();
await mp.goto(`${BASE}/termekek/`, { waitUntil: 'networkidle' });
await mp.click('[data-filters-open]');
await mp.waitForSelector('[data-filters].is-open', { timeout: 3000 }).catch(() => {});
ok(await mp.isVisible('[data-filters].is-open'), 'mobil: szűrőpanel');
await mp.click('.filters-apply [data-filters-close]');
await mp.click('.mobile-toggle[aria-controls]');
ok(await mp.isVisible('#main-menu.is-open'), 'mobil: menü nyílik');
ok((await mp.evaluate(() => document.documentElement.scrollWidth)) <= 390, 'mobil: nincs vízszintes görgetés');

ok(errors.length === 0, 'nincs JS / szerver hiba', errors.slice(0, 5).join(' | '));
await browser.close();
console.log(fails ? `\n${fails} HIBA` : '\nMinden rendben.');
process.exit(fails ? 1 : 0);
