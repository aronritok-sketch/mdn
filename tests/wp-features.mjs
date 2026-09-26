// A funkciómodulok (wp-theme/mandala/inc/features) végponttól végpontig tesztje.
// Futtatás a wp-e2e.mjs-sel azonos teszt WordPress mellett (friss `wp mandala setup --demo`):
//   BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-features.mjs
// A szerveroldali lépéseket (rendelés teljesítése, levélnapló) WP-CLI-vel végzi; a levelek
// a teszt mu-plugin szerint a wp-content/mail.log fájlba kerülnek (MAILLOG).
import { createRequire } from 'module';
import { execSync } from 'child_process';
import { readFileSync, existsSync } from 'fs';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const BASE = (process.env.BASE || 'http://localhost:8080').replace(/\/$/, '');
const WP = process.env.WP || 'wp';
const wp = (php) => execSync(`${WP} eval '${php.replace(/'/g, "'\\''")}'`, { encoding: 'utf8' }).trim();
const MAILLOG = process.env.MAILLOG || wp('echo WP_CONTENT_DIR;') + '/mail.log';
const mails = () => (existsSync(MAILLOG) ? readFileSync(MAILLOG, 'utf8') : '');

let fails = 0;
const ok = (cond, label, extra = '') => { console.log(`${cond ? '✓' : '✗'} ${label}${extra ? ` – ${extra}` : ''}`); if (!cond) fails++; };
const errors = [];
const num = (s) => Number(String(s).replace(/[^\d-]/g, '')) || 0;

const browser = await chromium.launch();
async function newPage() {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.addInitScript(() => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify({ stats: false })); } catch {} });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => errors.push(`PAGEERROR ${page.url()} ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`CONSOLE ${page.url()} ${m.text()}`); });
  page.on('response', (r) => { if (r.status() >= 500) errors.push(`HTTP ${r.status()} ${r.url()}`); });
  return page;
}
const waitUpdate = (page) => page.waitForFunction(() => !document.querySelector('.blockUI'), null, { timeout: 10000 }).then(() => page.waitForTimeout(400));

/** Pénztár kitöltése személyes átvétellel és előre utalással, majd megrendelés. */
async function checkout(page, { email = 'vevo@example.com', before } = {}) {
  await page.goto(`${BASE}/penztar/`, { waitUntil: 'networkidle' });
  if (!(await page.inputValue('#billing_email'))) await page.fill('#billing_email', email);
  if (!(await page.inputValue('#billing_phone'))) await page.fill('#billing_phone', '+36 30 123 4567');
  if (!(await page.inputValue('#billing_last_name'))) await page.fill('#billing_last_name', 'Kovács');
  if (!(await page.inputValue('#billing_first_name'))) await page.fill('#billing_first_name', 'Anna');
  if (!(await page.inputValue('#billing_postcode'))) await page.fill('#billing_postcode', '1052');
  if (!(await page.inputValue('#billing_city'))) await page.fill('#billing_city', 'Budapest');
  if (!(await page.inputValue('#billing_address_1'))) await page.fill('#billing_address_1', 'Váci utca 1.');
  if (await page.$('#shipping_method li[data-kind="pickup"] label')) {
    await page.click('#shipping_method li[data-kind="pickup"] label');
    await waitUpdate(page);
  }
  if (before) await before();
  if (await page.$('#payment_method_bacs')) { await page.check('#payment_method_bacs'); await waitUpdate(page); }
  await page.check('#terms');
  await page.click('#place_order');
  await page.waitForURL(/order-received|rendeles-fogadva/, { timeout: 20000 }).catch(() => {});
  const m = page.url().match(/order-received\/(\d+)|rendeles-fogadva\/(\d+)/);
  if (!m) {
    console.log('  pénztár hiba:', (await page.textContent('.woocommerce-NoticeGroup, .woocommerce-error').catch(() => '')).replace(/\s+/g, ' ').trim().slice(0, 300));
    await browser.close();
    console.log(`\n${fails + 1} HIBA`);
    process.exit(1);
  }
  return Number(m[1] || m[2]);
}

// ======================= Ajándékcsomag =======================
{
  const page = await newPage();
  await page.goto(`${BASE}/ajandekcsomag/`, { waitUntil: 'networkidle' });
  ok(await page.isVisible('[data-gift-builder]'), 'ajándékcsomag: összeállító az oldalon');
  ok(await page.isDisabled('[data-gift-submit]'), 'ajándékcsomag: kosárba gomb tiltva, amíg nincs 2 termék');
  const picks = page.locator('.gift-item');
  for (let i = 0; i < 3; i++) await picks.nth(i).click();
  const prices = await page.$$eval('input[name="items[]"]:checked', (l) => l.reduce((s, i) => s + Number(i.dataset.price), 0));
  await page.locator('.gift-box').nth(1).click();
  const boxPrice = Number(await page.getAttribute('input[name="box"]:checked', 'data-price'));
  ok(num(await page.textContent('[data-gift-total]')) === Math.round(prices + boxPrice), 'ajándékcsomag: élő végösszeg (termékek + csomagolás)', await page.textContent('[data-gift-total]'));
  ok((await page.textContent('[data-gift-count]')).includes('3 termék'), 'ajándékcsomag: kiválasztott darabszám felolvasva');
  await page.fill('#gift-message', 'Boldog születésnapot, Kata!');
  ok((await page.textContent('[data-gift-chars]')).startsWith('27'), 'ajándékcsomag: karakterszámláló');
  await Promise.all([page.waitForURL(/kosar/, { timeout: 10000 }), page.click('[data-gift-submit]')]);
  const cart = await page.textContent('form.woocommerce-cart-form');
  ok((cart.match(/Ajándékcsomag/g) || []).length >= 4, 'kosár: 3 termék + csomagolás egy csomagként');
  ok(cart.includes('Boldog születésnapot, Kata!'), 'kosár: a kártya szövege a csomagolásnál');
  ok((await page.$$('.gift-qty')).length === 4, 'kosár: a csomag tételeinek mennyisége rögzített');

  // ======================= Ajándékutalvány vásárlása =======================
  const voucherUrl = wp('echo get_permalink(wc_get_product_id_by_sku("MND-UTALVANY"));');
  await page.goto(voucherUrl, { waitUntil: 'networkidle' });
  ok((await page.textContent('.product-summary .price')).includes('–'), 'utalvány: ársáv a termékoldalon');
  ok(!(await page.$('.product-summary [data-qty]')), 'utalvány: nincs mennyiségválasztó');
  ok((await page.textContent('.product-summary .tax-note')).length > 0, 'utalvány: termékoldal renderel');
  await page.click('.voucher-amounts .chip:has-text("10 000")');
  await page.fill('#voucher-to-name', 'Kata');
  await page.fill('#voucher-to-email', 'kata@example.com');
  await page.fill('#voucher-message', 'Válassz magadnak valami szépet!');
  await Promise.all([page.waitForURL(/kosar/, { timeout: 10000 }), page.click('.product-summary .single_add_to_cart_button')]);
  const cart2 = await page.textContent('form.woocommerce-cart-form');
  ok(cart2.includes('Mandala ajándékutalvány') && cart2.includes('10 000'), 'utalvány a kosárban, a választott összeggel');
  ok(cart2.includes('kata@example.com'), 'utalvány: címzett a kosárban');

  const orderId = await checkout(page, { email: 'anna@example.com' });
  ok(orderId > 0, 'rendelés: ajándékcsomag + utalvány leadva', String(orderId));
  const before = mails().length;
  wp(`$o = wc_get_order(${orderId}); $o->update_status("completed");`);
  const code = wp(`foreach (wc_get_order(${orderId})->get_items() as $l) { $c = $l->get_meta("_mandala_voucher_codes"); if ($c) echo $c[0]; }`);
  ok(/^MND-[A-Z0-9]{4}-[A-Z0-9]{4}$/.test(code), 'teljesítéskor utalványkód készül', code);
  const newMail = mails().slice(before);
  ok(newMail.includes('TO: kata@example.com') && newMail.includes(code), 'utalvány levél a címzettnek, a kóddal');
  ok(newMail.includes('TO: anna@example.com') && /Nyomtatható változat/.test(newMail), 'utalvány másolat a vásárlónak, nyomtatható linkkel');
  wp(`$o = wc_get_order(${orderId}); $o->update_status("processing"); $o->update_status("completed");`);
  ok(wp(`echo count(get_posts(["post_type" => "mandala_voucher", "numberposts" => -1, "fields" => "ids", "meta_key" => "_order", "meta_value" => ${orderId}]));`) === "1", 'ismételt teljesítés: nem készül második utalvány');
  const printUrl = (newMail.match(/href="([^"]*mandala_voucher=[^"]+)"/) || [])[1]?.replace(/&amp;/g, '&');
  if (printUrl) {
    await page.goto(printUrl);
    ok((await page.textContent('.c')).trim() === code && (await page.textContent('.m')).includes('szépet'), 'nyomtatható utalvány: kód és üzenet');
  } else ok(false, 'nyomtatható utalvány link a levélben');
  await page.goto(`${BASE}/?mandala_voucher=${code}&vk=rossz`);
  ok((await page.textContent('body')).includes('Érvénytelen'), 'nyomtatható utalvány: aláírás nélkül nem nyílik meg');

  // ======================= Utalvány beváltása (fizetőeszközként) =======================
  const buyer = await newPage();
  const mala = wp('echo wc_get_product_id_by_sku("MND-HT-0490");');
  await buyer.goto(`${BASE}/?add-to-cart=${mala}`, { waitUntil: 'networkidle' });
  await buyer.goto(`${BASE}/penztar/`, { waitUntil: 'networkidle' });
  await buyer.click('#shipping_method li[data-kind="pickup"] label');
  await waitUpdate(buyer);
  const totalBefore = num(await buyer.textContent('.order-total td .amount'));
  const taxBefore = await buyer.textContent('.order-total .includes_tax');
  await buyer.click('[data-coupon-details] summary');
  await buyer.fill('#checkout_coupon', 'MND-ZZZZ-ZZZZ');
  await buyer.click('[data-coupon-apply]');
  await buyer.waitForTimeout(1200);
  ok((await buyer.textContent('#checkout_coupon-error')).includes('utalványkódot nem találjuk'), 'ismeretlen utalványkód: érthető hibaüzenet');
  await buyer.fill('#checkout_coupon', code.toLowerCase());
  await buyer.click('[data-coupon-apply]');
  await waitUpdate(buyer);
  ok(await buyer.isVisible('tr.voucher'), 'utalvány a kuponmezőben beváltva (kisbetűvel is): külön sor');
  const totalAfter = num(await buyer.textContent('.order-total td .amount'));
  ok(totalAfter === totalBefore - 10000, 'utalvány: a fizetendő 10 000 Ft-tal csökken', `${totalBefore} → ${totalAfter}`);
  ok((await buyer.textContent('.order-total .includes_tax')) === taxBefore, 'utalvány: az ÁFA-tartalom nem változik (fizetőeszköz, nem kedvezmény)', taxBefore);
  const order2 = await checkout(buyer, { email: 'bela@example.com' });
  ok(order2 > 0, 'rendelés utalvánnyal leadva');
  ok((await buyer.textContent('.woocommerce-table--order-details tfoot')).includes(code), 'köszönőoldal: utalvány sor az összesítőben');
  ok(wp(`echo (int) get_post_meta(mandala_voucher_find("${code}")->ID, "_balance", true);`) === '0', 'utalvány egyenlege levonva');
  wp(`wc_get_order(${order2})->update_status("cancelled");`);
  ok(wp(`echo (int) get_post_meta(mandala_voucher_find("${code}")->ID, "_balance", true);`) === '10000', 'lemondott rendelés: az utalvány egyenlege visszaíródik');
  await buyer.context().close();
  await page.context().close();
}

// ======================= Hűségpontok =======================
{
  wp('if (!get_user_by("login", "vevo")) { wp_insert_user(["user_login" => "vevo", "user_pass" => "vevo", "user_email" => "vevo@example.com", "role" => "customer"]); }');
  const uid = wp('echo get_user_by("login", "vevo")->ID;');
  wp(`delete_user_meta(${uid}, "_mandala_points"); delete_user_meta(${uid}, "_mandala_points_log"); mandala_points_add(${uid}, 1200, "Teszt jóváírás");`);
  const page = await newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', 'vevo');
  await page.fill('#user_pass', 'vevo');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  const mala = wp('echo wc_get_product_id_by_sku("MND-HT-0490");');
  await page.goto(wp(`echo get_permalink(${mala});`), { waitUntil: 'networkidle' });
  ok(/\+\d+ hűségpont/.test(await page.textContent('.product-summary')), 'termékoldal: ennyi hűségpont jár');
  await page.goto(`${BASE}/?add-to-cart=${mala}`, { waitUntil: 'networkidle' });
  let used = 0;
  const orderId = await checkout(page, {
    before: async () => {
      ok(await page.isVisible('.points-redeem'), 'pénztár: pontbeváltás 1200 ponttal');
      const total = num(await page.textContent('.order-total td .amount'));
      await page.check('.points-redeem input');
      await waitUpdate(page);
      used = num((await page.textContent('.points-redeem')).match(/Beváltok ([\d\s ]+) pontot/)?.[1] || '0');
      ok(await page.isVisible('tr.fee:has-text("Hűségpont")'), 'pénztár: hűségpont kedvezmény sor');
      ok(num(await page.textContent('.order-total td .amount')) === total - used, 'pénztár: a végösszeg a pontok értékével csökken', `${total} − ${used}`);
    },
  });
  ok(orderId > 0, 'rendelés pontbeváltással leadva');
  ok(wp(`echo mandala_points(${uid});`) === String(1200 - used), 'beváltott pontok levonva', wp(`echo mandala_points(${uid});`));
  wp(`wc_get_order(${orderId})->update_status("completed");`);
  const earned = Number(wp(`echo (int) wc_get_order(${orderId})->get_meta("_mandala_points_earned");`));
  ok(earned > 0 && wp(`echo mandala_points(${uid});`) === String(1200 - used + earned), 'teljesítéskor pont jóváírás (a kedvezmény utáni összegből)', `+${earned}`);
  await page.goto(`${BASE}/fiokom/husegpontok/`, { waitUntil: 'networkidle' });
  ok((await page.textContent('.points-balance')).includes(String(1200 - used + earned).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')) || (await page.textContent('.points-balance')).includes(String(1200 - used + earned)), 'Fiókom → Hűségpontok: egyenleg');
  ok((await page.$$('.points-log tbody tr')).length === 3, 'Fiókom → Hűségpontok: napló (jóváírás, beváltás, gyűjtés)');
  wp(`wc_get_order(${orderId})->update_status("refunded");`);
  ok(wp(`echo mandala_points(${uid});`) === '1200', 'visszatérítés: beváltott pont vissza, gyűjtött pont levonva');
  await page.context().close();
}

ok(errors.length === 0, 'nincs JS / szerver hiba', errors.slice(0, 5).join(' | '));
await browser.close();
console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
