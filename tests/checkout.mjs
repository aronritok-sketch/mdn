// Futtatás: python3 -m http.server 8000 &  →  node tests/checkout.mjs [képernyőkép-mappa]
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
// Playwright: helyi telepítés (npm i -D playwright) vagy PWPATH=<globális playwright útvonal>.
const { chromium } = require(process.env.PWPATH || 'playwright');
const out = process.argv[2] || null; // opcionális: képernyőképek mappája
const B0 = process.env.BASE || 'http://localhost:8000/';
const B = B0;
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
await ctx.addInitScript(() => { if (!sessionStorage.getItem('init')) { sessionStorage.setItem('init','1'); localStorage.clear(); localStorage.setItem('mandala.cookie.v1','{}'); } });
const p = await ctx.newPage();
const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type()==='error' && !/Failed to load/.test(m.text())) errs.push(m.text()); });
const ok = (label, cond, extra='') => console.log(cond ? '✓' : '✗', label, extra);

// Kosárba: termékoldalról 3 db (készlet: 2) → korlát
await p.goto(B + 'termek.html?p=mintas-hangtal-490g-405hz', { waitUntil: 'networkidle' });
await p.click('[data-cart-form] [data-step="1"]'); await p.click('[data-cart-form] [data-step="1"]');
ok('qty stepper capped at stock 2', await p.inputValue('[data-cart-form] input') === '2');
await p.click('.single_add_to_cart_button'); await p.waitForTimeout(500);
ok('minicart opened', await p.locator('#minicart.is-open').count() === 1);
ok('minicart shows free-shipping meter', (await p.locator('#minicart .ship-meter').innerText()).includes('ingyenes'));
await p.keyboard.press('Escape'); await p.waitForTimeout(300);
await p.click('.single_add_to_cart_button'); await p.waitForTimeout(500);
await p.keyboard.press('Escape'); await p.waitForTimeout(300);
ok('stock limit toast', (await p.locator('.toast').last().innerText()).includes('legfeljebb'));
// + mala
await p.goto(B + 'termek.html?p=chakra-mala-8-5mm', { waitUntil: 'networkidle' });
await p.click('.single_add_to_cart_button'); await p.waitForTimeout(400); await p.keyboard.press('Escape');

// Kosár oldal: kupon hibás/helyes
await p.goto(B + 'kosar.html', { waitUntil: 'networkidle' });
await p.fill('#coupon_code', 'rossz'); await p.click('[data-coupon-apply]'); await p.waitForTimeout(300);
ok('invalid coupon message', (await p.locator('#coupon-error').innerText()).includes('nem létezik'));
await p.fill('#coupon_code', 'mandala10'); await p.click('[data-coupon-apply]'); await p.waitForTimeout(400);
ok('coupon applied', await p.locator('.coupon-applied').count() === 1, await p.locator('.totals-table').innerText().then(t=>t.replace(/\s+/g,' ')));
if (out) await p.screenshot({ path: `${out}/t-cart.png`, fullPage: true });

// Pénztár
await p.goto(B + 'penztar.html', { waitUntil: 'networkidle' });
await p.click('#place_order'); await p.waitForTimeout(400);
const nErr = await p.locator('[data-notices] li').count();
ok('empty submit → error summary', nErr >= 8, `${nErr} hibalink`);
ok('focus on notice group', await p.evaluate(() => document.activeElement.matches('[data-notices]')));
if (out) await p.screenshot({ path: `${out}/t-co-errors.png` });
await p.click('[data-notices] a[href="#billing_email"]'); await p.waitForTimeout(400);
ok('error link focuses field', await p.evaluate(() => document.activeElement.id) === 'billing_email');

await p.fill('#billing_email', 'teszt@gmial.com'); await p.press('#billing_email', 'Tab'); await p.waitForTimeout(200);
ok('email typo suggestion', (await p.locator('#billing_email-suggest').innerText()).includes('gmail.com'));
await p.click('#billing_email-suggest button');
ok('suggestion applied', await p.inputValue('#billing_email') === 'teszt@gmail.com');
await p.fill('#billing_phone', '06301234567'); await p.press('#billing_phone', 'Tab'); await p.waitForTimeout(150);
ok('phone normalised', await p.inputValue('#billing_phone') === '+36 30 123 4567', await p.inputValue('#billing_phone'));
await p.fill('#billing_last_name', 'Teszt'); await p.fill('#billing_first_name', 'Anna');
await p.fill('#billing_postcode', '1052'); await p.waitForTimeout(150);
ok('zip → city autofill', await p.inputValue('#billing_city') === 'Budapest');
await p.fill('#billing_address_1', 'Váci utca 1.');
await p.check('#is_company'); await p.waitForTimeout(100);
await p.fill('#billing_company', 'Jóga Kft.'); await p.fill('#billing_tax_number', '12345678112'); await p.press('#billing_tax_number', 'Tab'); await p.waitForTimeout(150);
ok('invalid tax number rejected', (await p.locator('#billing_tax_number-error').innerText()).includes('nem érvényes'), await p.inputValue('#billing_tax_number'));
await p.fill('#billing_tax_number', '12345676241'); await p.press('#billing_tax_number', 'Tab'); await p.waitForTimeout(150);
ok('valid tax number accepted + formatted', (await p.locator('#billing_tax_number-error').innerText()) === '' , await p.inputValue('#billing_tax_number'));

// Foxpost automata nélkül
await p.check('#shipping_method_foxpost'); await p.waitForTimeout(200);
await p.check('#terms');
await p.click('#place_order'); await p.waitForTimeout(300);
ok('foxpost without locker blocked', (await p.locator('[data-notices]').innerText()).includes('automat'));
await p.click('[data-pick-locker]'); await p.waitForTimeout(400);
await p.fill('#locker-q', 'szeged'); await p.waitForTimeout(100);
ok('locker search filters', await p.locator('[data-locker]').count() === 1);
await p.click('[data-locker]'); await p.waitForTimeout(400);
ok('locker chosen', (await p.locator('#foxpost_point').innerText()).includes('Szeged'));

// Utánvét díj + személyes átvétel felirat
await p.check('#payment_method_cod'); await p.waitForTimeout(250);
const t1 = await p.locator('.woocommerce-checkout-review-order-table').innerText();
ok('COD fee in totals', t1.includes('Utánvét díja'), t1.replace(/\s+/g,' '));
await p.check('#shipping_method_pickup'); await p.waitForTimeout(250);
ok('pickup → "Fizetés átvételkor", no fee', (await p.locator('[data-pay-label="cod"]').innerText()) === 'Fizetés átvételkor' && !(await p.locator('.woocommerce-checkout-review-order-table').innerText()).includes('Utánvét díja'));
ok('focus kept on radio after change', await p.evaluate(() => document.activeElement.id) === 'shipping_method_pickup');

// Piszkozat megmarad újratöltés után
await p.reload({ waitUntil: 'networkidle' });
ok('draft restored', await p.inputValue('#billing_email') === 'teszt@gmail.com' && await p.isChecked('#shipping_method_pickup') && await p.inputValue('#billing_tax_number') === '12345676-2-41');
ok('terms not restored (must re-accept)', !(await p.isChecked('#terms')));

// Előre utalás + GLS, rendelés
await p.check('#shipping_method_gls'); await p.check('#payment_method_bacs'); await p.check('#terms');
await p.locator('.review-coupon[data-coupon-details] summary').click();
await p.fill('[data-review] textarea, #order_comments', '').catch(()=>{});
const total = (await p.locator('[data-summary-total]').innerText());
if (out) await p.screenshot({ path: `${out}/t-co-filled.png`, fullPage: true });
await Promise.all([p.waitForURL(/koszonjuk/), p.click('#place_order')]);
await p.waitForLoadState('networkidle');
ok('thank-you page', (await p.locator('h1').innerText()).includes('Köszönjük, Anna'));
ok('bank details shown', await p.locator('.wc-bacs-bank-details li').count() === 6);
ok('order total matches', (await p.locator('.woocommerce-order-overview__total strong').innerText()) === total, total);
ok('company + tax on invoice address', (await p.locator('.woocommerce-customer-details').innerText()).includes('12345676-2-41'));
ok('cart emptied', await p.locator('[data-cart-count]').isHidden());
if (out) await p.screenshot({ path: `${out}/t-thanks.png`, fullPage: true });

// Mobil összesítő
const m = await b.newContext({ viewport: { width: 390, height: 844 }, ignoreHTTPSErrors: true });
await m.addInitScript(() => { localStorage.setItem('mandala.cookie.v1','{}'); localStorage.setItem('mandala.cart.v2', JSON.stringify([{id:4490,qty:1}])); });
const mp = await m.newPage(); mp.on('pageerror', e => errs.push('m:'+e.message));
await mp.goto(B + 'penztar.html', { waitUntil: 'networkidle' });
ok('mobile summary collapsed', !(await mp.locator('#order_review').isVisible()));
await mp.click('[data-summary-toggle]');
ok('mobile summary opens', await mp.locator('#order_review').isVisible());
if (out) await mp.screenshot({ path: `${out}/t-co-mobile.png` });
console.log('ERRORS:', errs.length ? errs : 'none');
await b.close();
