// A funkciómodulok (wp-theme/mandala/inc/features) végponttól végpontig tesztje.
// Futtatás a wp-e2e.mjs-sel azonos teszt WordPress mellett (friss `wp mandala setup --demo`):
//   BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-features.mjs
// A szerveroldali lépéseket (rendelés teljesítése, levélnapló) WP-CLI-vel végzi; a levelek
// a teszt mu-plugin szerint a wp-content/mail.log fájlba kerülnek (MAILLOG).
import { createRequire } from 'module';
import { execSync } from 'child_process';
import { readFileSync, existsSync } from 'fs';
import { createHash } from 'crypto';
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

// A tesztben vásárolt termék készlete (ismételt futtatásnál ne fogyjon el); a végén visszaáll
// (a wp-e2e.mjs a bemutató készletével számol).
const origStock = wp('$p = wc_get_product(wc_get_product_id_by_sku("MND-HT-0490")); echo wp_json_encode([$p->get_manage_stock(), $p->get_stock_quantity()]);');
wp('$p = wc_get_product(wc_get_product_id_by_sku("MND-HT-0490")); $p->set_manage_stock(true); $p->set_stock_quantity(50); $p->save();');

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

// ======================= EU-s szállítás =======================
{
  execSync(`${WP} mandala eu-shipping`, { encoding: 'utf8' });
  const page = await newPage();
  const mala = wp('echo wc_get_product_id_by_sku("MND-HT-0490");');
  await page.goto(`${BASE}/?add-to-cart=${mala}`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/penztar/`, { waitUntil: 'networkidle' });
  ok(await page.isVisible('#billing_country'), 'EU: országválasztó a pénztárban');
  await page.selectOption('#billing_country', 'AT').catch(async () => { await page.evaluate(() => { const s = document.querySelector('#billing_country'); s.value = 'AT'; window.jQuery?.(s).trigger('change'); }); });
  await waitUpdate(page);
  await page.fill('#billing_postcode', '1010');
  await page.locator('#billing_postcode').blur();
  ok((await page.inputValue('#billing_city')) === '', 'EU: osztrák 1010 nem tölti ki „Budapest”-tel');
  ok((await page.getAttribute('#billing_postcode', 'aria-invalid')) === null, 'EU: osztrák irányítószám elfogadva');
  await page.fill('#billing_phone', '0660 1234567');
  await page.locator('#billing_phone').blur();
  ok((await page.getAttribute('#billing_phone', 'aria-invalid')) === 'true' && (await page.textContent('#billing_phone_field')).includes('Nemzetközi'), 'EU: országhívó nélküli külföldi számnál nemzetközi formátumot kér');
  await page.fill('#billing_phone', '+43 660 1234567');
  await page.locator('#billing_phone').blur();
  ok((await page.getAttribute('#billing_phone', 'aria-invalid')) === null, 'EU: +43 szám elfogadva');
  await page.check('#is_company');
  ok((await page.textContent('label[for="billing_tax_number"]')).includes('Közösségi'), 'EU: közösségi adószám címke');
  await page.fill('#billing_tax_number', '12345676-2-41');
  await page.locator('#billing_tax_number').blur();
  ok((await page.getAttribute('#billing_tax_number', 'aria-invalid')) === 'true', 'EU: magyar adószám külföldi cégnél hibás');
  await page.fill('#billing_tax_number', 'ATU12345678');
  await page.locator('#billing_tax_number').blur();
  ok((await page.getAttribute('#billing_tax_number', 'aria-invalid')) === null, 'EU: ATU közösségi adószám elfogadva');
  ok(wp('echo (int) mandala_valid_vat_id("ATU12345678", "AT") . (int) mandala_valid_vat_id("DE123456789", "AT") . (int) mandala_valid_vat_id("EL123456789", "GR");') === '101', 'EU: közösségi adószám országkód-egyezés (GR → EL)');
  await page.context().close();
  execSync(`${WP} mandala eu-shipping --off`, { encoding: 'utf8' });
}

// ======================= Viszonteladói felület =======================
{
  const page = await newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', 'viszontelado');
  await page.fill('#user_pass', 'b2b');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  await page.goto(`${BASE}/fiokom/nagyker/`, { waitUntil: 'networkidle' });
  ok((await page.textContent('.woocommerce-MyAccount-navigation')).includes('Viszonteladói felület'), 'B2B: menüpont a fiókban');
  await page.waitForSelector('[data-b2b-rows] tr');
  ok((await page.$$('[data-b2b-rows] tr')).length > 3, 'B2B: gyorsrendelő táblázat', String((await page.$$('[data-b2b-rows] tr')).length));
  await page.fill('[data-b2b-q]', 'MND-HT-0490');
  const rows = await page.$$('[data-b2b-rows] tr');
  ok(rows.length === 1 && (await rows[0].textContent()).includes('29 000'), 'B2B: keresés cikkszámra, nagyker ár a sorban');
  await page.fill('[data-b2b-rows] input[data-id]', '2');
  ok((await page.textContent('[data-b2b-sum]')).includes('2 db') && (await page.textContent('[data-b2b-sum]')).includes('58 000'), 'B2B: összesítő (2 db · 58 000 Ft)');
  await page.click('[data-b2b-add]');
  await page.waitForFunction(() => document.querySelector('[data-b2b-msg]')?.textContent.trim(), null, { timeout: 8000 });
  ok((await page.textContent('[data-b2b-msg]')).includes('2 db a kosárban'), 'B2B: tömeges kosárba');
  const csvUrl = await page.getAttribute('.b2b-tools a[href*="mandala_b2b_pricelist"]', 'href');
  const csv = await (await page.request.get(csvUrl)).text();
  ok(csv.startsWith('﻿') && csv.includes('MND-HT-0490') && /;29000;/.test(csv), 'B2B: árlista CSV (BOM, pontosvessző, nagyker ár)');
  // Termékfotó a ZIP-hez (1×1 PNG a feltöltések közé).
  wp('$pid = wc_get_product_id_by_sku("MND-HT-0490"); if (!get_post_thumbnail_id($pid)) { $u = wp_upload_dir(); $f = $u["path"] . "/teszt-foto.png"; file_put_contents($f, base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")); $a = wp_insert_attachment(["post_mime_type" => "image/png", "post_title" => "teszt"], $f, $pid); set_post_thumbnail($pid, $a); mandala_flush_index(); }');
  const zip = await page.request.get(`${BASE}/wp-admin/admin-post.php?action=mandala_b2b_images&cat=szakralis-targyak&_wpnonce=${new URL(csvUrl, BASE).searchParams.get('_wpnonce')}`);
  const zipBody = await zip.body();
  ok(zip.status() === 200 && zip.headers()['content-type'].includes('zip') && zipBody.includes(Buffer.from('MND-HT-0490.png')), 'B2B: kategória fotói ZIP-ben, cikkszám szerint elnevezve', String(zip.status()));
  await page.check('.b2b-arrivals input[type="checkbox"]');
  await page.waitForLoadState('networkidle');
  const uid = wp('echo get_user_by("login", "viszontelado")->ID;');
  ok(wp(`echo get_user_meta(${uid}, "_mandala_b2b_arrivals", true);`) === 'yes', 'B2B: feliratkozás az új érkezésekre');
  const before = mails().length;
  wp('do_action("mandala_b2b_weekly");');
  ok(/TO: b2b@example\.com[\s\S]*Új érkezések/.test(mails().slice(before)), 'B2B: heti új érkezés levél');
  await page.context().close();

  const guest = await newPage();
  const res = await guest.request.post(`${BASE}/?wc-ajax=mandala_bulk_add`, { form: { security: 'x', items: '[[1,1]]' } });
  ok(res.status() >= 400, 'B2B: tömeges kosárba viszonteladó nélkül tiltott', String(res.status()));
  await guest.context().close();
}

// ======================= Mérés: Consent Mode, GA4 események, Meta CAPI =======================
{
  const page = await newPage();
  await page.addInitScript(() => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify({ stats: true, marketing: true })); } catch {} });
  await page.context().addCookies([{ name: '_fbp', value: 'fb.1.1700000000.123456789', url: BASE }]);
  await page.goto(`${BASE}/kategoria/szakralis-targyak/hangtalak/`, { waitUntil: 'networkidle' });
  const consent = await page.evaluate(() => window.dataLayer.filter((e) => e[0] === 'consent').map((e) => `${e[1]}:${e[2].analytics_storage}`));
  ok(consent[0] === 'default:denied' && consent[1] === 'update:granted', 'Consent Mode v2: alapból elutasítva, a mentett választás azonnal visszaállítva', consent.join(' '));
  await page.waitForFunction(() => window.dataLayer.some((e) => e.event === 'view_item_list'), null, { timeout: 5000 }).catch(() => {});
  const list = await page.evaluate(() => window.dataLayer.find((e) => e.event === 'view_item_list'));
  ok(list && list.ecommerce.items.length > 0 && list.ecommerce.items[0].item_id, 'GA4: view_item_list a szűrt listára', list ? `${list.ecommerce.items.length} tétel` : '');
  const mala = wp('echo wc_get_product_id_by_sku("MND-HT-0490");');
  await page.goto(wp(`echo get_permalink(${mala});`), { waitUntil: 'networkidle' });
  await page.click('.product-summary .single_add_to_cart_button');
  await page.waitForFunction(() => window.dataLayer.some((e) => e.event === 'add_to_cart'), null, { timeout: 5000 }).catch(() => {});
  const atc = await page.evaluate(() => window.dataLayer.find((e) => e.event === 'add_to_cart'));
  ok(atc && atc.ecommerce.items[0].item_id === 'MND-HT-0490' && atc.ecommerce.currency === 'HUF', 'GA4: add_to_cart a termékoldalról (cikkszám, pénznem)');
  ok(await page.evaluate(() => window.dataLayer.some((e) => e.event === 'view_item')), 'GA4: view_item (GTM4WP nélkül a téma küldi)');

  wp('update_option("mandala_analytics", ["capi" => "yes", "pixel_id" => "123", "capi_token" => "tok"]); delete_option("mandala_capi_mock");');
  const orderId = await checkout(page, { email: 'Meres.Teszt@Example.com' });
  ok(wp(`echo wc_get_order(${orderId})->get_meta("_mandala_marketing_consent");`) === 'yes', 'CAPI: a pénztár menti a marketing-hozzájárulást');
  wp(`wc_get_order(${orderId})->update_status("completed"); do_action("mandala_capi_purchase", ${orderId});`);
  const body = JSON.parse(JSON.parse(wp('echo wp_json_encode(get_option("mandala_capi_mock")["body"] ?? "{}");')) || '{}');
  const ev = body.data?.[0] || {};
  ok(ev.event_name === 'Purchase' && ev.event_id === `order_${orderId}`, 'CAPI: Purchase esemény, deduplikációs azonosítóval');
  ok(ev.user_data?.em?.[0] === createHash('sha256').update('meres.teszt@example.com').digest('hex'), 'CAPI: e-mail kisbetűsítve, SHA-256 hash-elve');
  ok(ev.user_data?.fbp === 'fb.1.1700000000.123456789', 'CAPI: _fbp süti továbbítva');
  await page.context().close();

  const noConsent = await newPage();
  await noConsent.addInitScript(() => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify({ stats: false, marketing: false })); } catch {} });
  await noConsent.goto(`${BASE}/?add-to-cart=${mala}`, { waitUntil: 'networkidle' });
  const page2 = noConsent;
  const order2 = await checkout(page2, { email: 'nincs@example.com' });
  wp(`wc_get_order(${order2})->update_status("completed");`);
  ok(wp(`echo wc_get_order(${order2})->get_meta("_mandala_capi_queued");`) === '', 'CAPI: hozzájárulás nélkül nem küld');
  wp('delete_option("mandala_analytics");');
  await noConsent.context().close();
}

// ======================= Új termékek sora + Claude migráció =======================
{
  const W = (cmd) => execSync(`${WP} ${cmd}`, { encoding: 'utf8' });
  const ids = JSON.parse(wp(`
    update_option("mandala_ai", ["api_key" => "test-key"]);
    $GLOBALS["mandala_onboarding_skip"] = true;
    $cat = function ($slug, $name) { $t = get_term_by("slug", $slug, "product_cat"); return $t ? $t->term_id : wp_insert_term($name, "product_cat", ["slug" => $slug])["term_id"]; };
    $mk = function ($name, $sku, $cid) { if ($id = wc_get_product_id_by_sku($sku)) { wp_delete_post($id, true); } $p = new WC_Product_Simple(); $p->set_name($name); $p->set_sku($sku); $p->set_regular_price("19900"); $p->set_status("publish"); $p->set_category_ids([$cid]); $p->set_description("Régi leírás."); return $p->save(); };
    echo wp_json_encode([$mk("Tibeti hangtál kézzel kovácsolt – G#, 405 Hz, 520 g", "OLD-1", $cat("regi-tibeti-hangtalak", "Régi: Tibeti hangtálak")), $mk("Tibeti hangtál – ismeretlen", "OLD-2", $cat("regi-tibeti-hangtalak", "")), $mk("Nag Champa füstölő 15 g", "OLD-3", $cat("regi-fustolok", "Régi: Füstölők"))]);`));
  W(`mandala ai-migrate --ids=${ids.join(',')} --dry-run`);
  const sug = (id) => JSON.parse(wp(`echo wp_json_encode(wc_get_product(${id})->get_meta("_mandala_ai"));`));
  ok(sug(ids[0]).decision === 'auto' && sug(ids[1]).decision === 'review' && sug(ids[2]).decision === 'review', 'Claude próbafuttatás: biztos → automatikus, bizonytalan → ellenőrizendő');
  ok(wp(`echo implode(",", wp_get_post_terms(${ids[0]}, "product_cat", ["fields" => "slugs"]));`) === 'regi-tibeti-hangtalak', 'Claude próbafuttatás: nem ír a termékbe');
  const mock = JSON.parse(wp('echo wp_json_encode(get_option("mandala_ai_mock_last"));'));
  ok(mock.model === 'claude-opus-5-5' && mock.tool_choice.name === 'record_classifications' && mock.cached, 'Claude kérés: alapmodell, kötelező eszköz (strukturált kimenet), gyorsítótárazott rendszerprompt');
  ok(sug(ids[2]).problems.length === 0 && sug(ids[2]).missing.includes('Illat'), 'Claude: a bizonytalan kötelező szűrő (illat) miatt ellenőrizendő');

  const out = W(`mandala ai-migrate --ids=${ids.join(',')}`);
  const run = out.match(/Futtatás: (\w+)/)[1];
  const cats0 = wp(`echo implode(",", wp_get_post_terms(${ids[0]}, "product_cat", ["fields" => "slugs"]));`);
  ok(cats0.includes('hangtalak') && cats0.includes('szakralis-targyak') && cats0.includes('regi-tibeti-hangtalak'), 'migráció: új kategória, a régi megmarad', cats0);
  const vals = JSON.parse(wp(`echo wp_json_encode(mandala_product_filter_values(wc_get_product(${ids[0]})));`));
  ok(vals.hang[0] === 'g-sharp' && vals.hz === 405 && vals.suly === 520 && vals.csakra[0] === 'torok' && vals.keszites[0] === 'kovacsolt', 'migráció: szűrőadatok (hang, Hz, súly, csakra, készítés)');
  ok(wp(`echo get_post_meta(${ids[1]}, "_mandala_onboarding", true) . get_post_status(${ids[1]});`) === 'reviewpublish', 'migráció: bizonytalan élő termék az ellenőrizendő sorba, élő marad');
  ok(wp(`echo implode(",", wp_get_post_terms(${ids[1]}, "product_cat", ["fields" => "slugs"]));`) === 'regi-tibeti-hangtalak', 'migráció: bizonytalan terméknél nem ír a kategóriába');
  wp(`mandala_ai_apply(wc_get_product(${ids[2]}), wc_get_product(${ids[2]})->get_meta("_mandala_ai"), "kézi");`);
  ok(JSON.parse(wp(`echo wp_json_encode(mandala_attr(wc_get_product(${ids[2]}), "pa_forma", "slug"));`))[0] === 'palcika', 'javaslat kézi alkalmazása: új szűrőérték (nyitott lista) létrejön');
  W(`mandala ai-undo ${run}`);
  ok(wp(`echo implode(",", wp_get_post_terms(${ids[0]}, "product_cat", ["fields" => "slugs"])) . "|" . get_post_meta(${ids[0]}, "_mandala_hz", true) . "|" . get_post_meta(${ids[1]}, "_mandala_onboarding", true);`) === 'regi-tibeti-hangtalak||', 'visszavonás: kategória, szűrőadat és sorállapot visszaáll');

  // Új termék importból (JUTA): piszkozat, sor, Claude-előtöltés, ellenőrzőlista, élesítés
  const before = mails().length;
  const nid = Number(wp(`$p = new WC_Product_Simple(); $p->set_name("Tibeti hangtál öntött – A, 432 Hz, 610 g"); $p->set_sku("JUTA-NEW-" . wp_rand()); $p->set_regular_price("21900"); $p->set_status("publish"); $p->set_manage_stock(true); $p->set_stock_quantity(4); echo $p->save();`));
  ok(wp(`echo get_post_status(${nid}) . "|" . get_post_meta(${nid}, "_mandala_onboarding", true);`) === 'draft|new', 'import: új termék piszkozat, „új” állapot');
  wp(`$p = wc_get_product(${nid}); $p->set_regular_price("22900"); $p->set_stock_quantity(6); $p->set_status("publish"); $p->save();`);
  ok(wp(`echo get_post_status(${nid});`) === 'draft', 'import: a JUTA ár/készlet frissítése nem élesít');
  wp('do_action("mandala_onboarding_digest");');
  ok(/Tibeti hangtál öntött – A, 432 Hz/.test(mails().slice(before)) && /új termék vár élesítésre/.test(mails().slice(before)), 'értesítő levél az új termékekről');
  wp('do_action("mandala_ai_new_batch"); foreach (mandala_ai_runs() as $id => $r) { if (!empty($r["auto_new"]) && $r["status"] === "running") { do { $i = mandala_ai_process($id, false); } while ($i["status"] === "running"); } }');
  ok(wp(`echo implode(",", wp_get_post_terms(${nid}, "product_cat", ["fields" => "slugs"])) . "|" . get_post_status(${nid});`).match(/hangtalak.*\|draft$/) !== null, 'új termék: Claude előtölti a kategóriát, de nem élesít');
  ok(wp(`echo implode(",", mandala_onboarding_approve(${nid}));`).includes('Fő termékkép'), 'élesítés hiányos terméknél megtagadva (kép, leírás)');
  wp(`$p = wc_get_product(${nid}); $p->set_image_id(get_post_thumbnail_id(wc_get_product_id_by_sku("MND-HT-0490"))); $p->set_description(str_repeat("Mélyen zengő, öntött hangtál Nepálból, meditációhoz és hangfürdőhöz. ", 4)); $GLOBALS["mandala_onboarding_skip"] = true; $p->save();`);
  ok(wp(`echo implode(",", mandala_onboarding_approve(${nid})) . "|" . get_post_status(${nid}) . "|" . get_post_meta(${nid}, "_mandala_onboarding", true);`) === '|publish|done', 'teljes termék élesítve');

  // Admin felület
  const page = await newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  for (const tab of ['new', 'review', 'ai', 'settings']) {
    const res = await page.goto(`${BASE}/wp-admin/edit.php?post_type=product&page=mandala-onboarding&tab=${tab}`);
    const text = await page.textContent('#wpbody-content');
    ok(res.status() === 200 && !/Fatal error|Warning:|Notice:/.test(text), `admin: Új termékek → ${tab} fül`);
  }
  ok((await page.textContent('#adminmenu')).includes('Új termékek'), 'admin: „Új termékek” menüpont');
  const eid = Number(wp('$p = new WC_Product_Simple(); $p->set_name("Szerkesztő teszt hangtál"); $p->set_sku("ED-" . wp_rand()); $p->set_regular_price("9900"); $p->set_status("publish"); echo $p->save();'));
  await page.goto(`${BASE}/wp-admin/post.php?post=${eid}&action=edit`);
  ok(await page.isVisible('#mandala_onboarding'), 'szerkesztő: élesítési ellenőrzőlista doboz');
  await Promise.all([page.waitForNavigation(), page.click('#publish')]);
  ok(wp(`echo get_post_status(${eid});`) === 'draft' && (await page.locator('.notice-warning', { hasText: 'nem élesíthető' }).count()) === 1, 'szerkesztő: hiányos új termék közzététele → piszkozat marad, hiánylista');
  await page.goto(`${BASE}/wp-admin/edit.php?post_type=product&page=mandala-onboarding&tab=ai`);
  ok((await page.textContent('#wpbody-content')).includes('Próbafuttatás eredménye'), 'admin: próbafuttatás eredménye és becslés');
  await page.context().close();
  wp('delete_option("mandala_ai");');
}

// ======================= Telepítő élő boltban =======================
{
  const W = (cmd) => execSync(`${WP} ${cmd}`, { encoding: 'utf8' });
  // Kézzel módosított beállítást egy újrafuttatás nem ír felül.
  wp('update_option("woocommerce_specific_allowed_countries", ["HU", "AT"]);');
  W('mandala setup --step=woocommerce --force');
  ok(wp('echo implode(",", get_option("woocommerce_specific_allowed_countries"));') === 'HU,AT', 'telepítő: a kézzel módosított beállítást nem írja felül');
  wp('update_option("woocommerce_specific_allowed_countries", ["HU"]);');
  // Élő bolt szimuláció: termékek vannak, a telepítő még nem futott.
  const saved = wp('echo wp_json_encode([get_option("mandala_setup_steps"), get_option("mandala_setup_skipped", []), get_option("mandala_setup_confirmed", 0)]);');
  wp('delete_option("mandala_setup_steps"); delete_option("mandala_setup_confirmed"); delete_option("mandala_setup_skipped");');
  const page = await newPage();
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  await page.goto(`${BASE}/wp-admin/`);
  ok(wp('echo wp_json_encode(get_option("mandala_setup_steps", []));') === '[]' && (await page.textContent('#wpbody-content')).includes('élő boltot talált'), 'telepítő élő boltban: nem fut magától, értesít');
  await page.goto(`${BASE}/wp-admin/themes.php?page=mandala-setup`);
  ok((await page.$$('input[name="steps[]"]')).length === 9, 'telepítő élő boltban: lépésenkénti áttekintés, mit állít be');
  await page.uncheck('#st-woocommerce');
  await page.uncheck('#st-tax');
  await Promise.all([page.waitForNavigation(), page.click('button:has-text("A kijelölt lépések futtatása")')]);
  const done = JSON.parse(wp('echo wp_json_encode(get_option("mandala_setup_steps", []));'));
  ok(done.pages && done.menus && !done.woocommerce && !done.tax, 'telepítő élő boltban: csak a kijelölt lépések futnak');
  ok((await page.textContent('#wpbody-content')).includes('kihagyva') && (await page.$$('button[form="mandala-step"]')).length >= 2, 'telepítő: a kihagyott lépés később egyenként futtatható');
  ok((await page.textContent('#wpbody-content')).includes('Eredeti beállítások visszaállítása'), 'telepítő: az eredeti beállítások visszaállíthatók');
  // Bolt adatai adminból (a témafrissítés nem írja felül)
  await page.goto(`${BASE}/wp-admin/admin.php?page=mandala-store`);
  await page.fill('#ms-phone', '+36 1 999 8888');
  await page.fill('#ms-free', '30000');
  await Promise.all([page.waitForNavigation(), page.click('#submit')]);
  await page.goto(`${BASE}/kapcsolat/`, { waitUntil: 'networkidle' });
  ok((await page.textContent('body')).includes('+36 1 999 8888') && wp('echo mandala_config("freeShippingFrom");') === '30000', 'Mandala bolt adatai: telefon és ingyenes szállítás határa adminból');
  wp('delete_option("mandala_contact"); delete_option("mandala_freeShippingFrom"); delete_option("mandala_payment");');
  wp(`$s = json_decode('${saved}', true); update_option("mandala_setup_steps", $s[0]); update_option("mandala_setup_skipped", $s[1]); update_option("mandala_setup_confirmed", $s[2]);`);
  await page.context().close();
}

{
  const [manage, qty] = JSON.parse(origStock);
  wp(`$p = wc_get_product(wc_get_product_id_by_sku("MND-HT-0490")); $p->set_manage_stock(${manage ? 'true' : 'false'}); $p->set_stock_quantity(${Number(qty) || 0}); $p->set_stock_status("instock"); $p->save();`);
}
ok(errors.length === 0, 'nincs JS / szerver hiba', errors.slice(0, 5).join(' | '));
await browser.close();
console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
