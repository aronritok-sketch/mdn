// Marketingmodulok végponttól végpontig: csomagkedvezmény, ajándék értékhatár felett, előfizetés,
// születésnapi kupon, partnerprogram, árcsökkenés-értesítő, kilépési ablak.
// Futtatás a wp-features.mjs-sel azonos teszt WordPress mellett (friss `wp mandala setup --demo`):
//   BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-marketing.mjs
import { createRequire } from 'module';
import { execSync } from 'child_process';
import { readFileSync, existsSync } from 'fs';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const BASE = (process.env.BASE || 'http://localhost:8080').replace(/\/$/, '');
const WP = process.env.WP || 'wp';
const wp = (php) => execSync(`${WP} eval '${php.replace(/'/g, "'\\''")}' 2>/dev/null`, { encoding: 'utf8' }).trim();
const MAILLOG = process.env.MAILLOG || wp('echo WP_CONTENT_DIR;') + '/mail.log';
const mails = () => (existsSync(MAILLOG) ? readFileSync(MAILLOG, 'utf8') : '');
const num = (s) => Number(String(s).replace(/[^\d-]/g, '')) || 0;

let fails = 0;
const ok = (cond, label, extra = '') => { console.log(`${cond ? '✓' : '✗'} ${label}${extra ? ` – ${extra}` : ''}`); if (!cond) fails++; };
const errors = [];
const url = (sku) => wp(`echo get_permalink(wc_get_product_id_by_sku("${sku}"));`);

// Tiszta kiindulás: készlet, beállítások.
wp('foreach (["MND-HT-0490" => 50, "MND-FK-0012" => 50, "MND-FB-0010" => 50] as $s => $q) { $p = wc_get_product(wc_get_product_id_by_sku($s)); $p->set_manage_stock(true); $p->set_stock_quantity($q); $p->save(); }');
wp('$s = mandala_growth_settings(); $s["gwp"] = "yes"; $s["gwp_sku"] = "MND-FB-0010"; $s["gwp_threshold"] = 15000; $s["gwp_label"] = "ajándék füstölő"; $s["birthday"] = "yes"; update_option("mandala_growth", $s);');
wp('update_option("mandala_bundles", [["name" => "Kezdő szett", "skus" => ["MND-HT-0490", "MND-FK-0012"], "percent" => 10, "enabled" => "yes"]]);');

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
async function checkout(page, { email, payment = 'bacs', before } = {}) {
  await page.goto(`${BASE}/penztar/`, { waitUntil: 'networkidle' });
  for (const [sel, v] of [['#billing_email', email], ['#billing_phone', '+36 30 123 4567'], ['#billing_last_name', 'Kovács'], ['#billing_first_name', 'Anna'], ['#billing_postcode', '1052'], ['#billing_city', 'Budapest'], ['#billing_address_1', 'Váci utca 1.']]) {
    if (!(await page.inputValue(sel))) await page.fill(sel, v);
  }
  if (await page.$('#shipping_method li[data-kind="pickup"] label')) { await page.click('#shipping_method li[data-kind="pickup"] label'); await waitUpdate(page); }
  if (before) await before();
  if (await page.$(`#payment_method_${payment}`)) { await page.check(`#payment_method_${payment}`); await waitUpdate(page); }
  await page.check('#terms');
  await page.click('#place_order');
  await page.waitForURL(/order-received|rendeles-fogadva/, { timeout: 20000 }).catch(() => {});
  const m = page.url().match(/order-received\/(\d+)|rendeles-fogadva\/(\d+)/);
  if (!m) console.log('  pénztár hiba:', (await page.textContent('.woocommerce-NoticeGroup, .woocommerce-error').catch(() => '')).replace(/\s+/g, ' ').trim().slice(0, 300));
  return m ? Number(m[1] || m[2]) : 0;
}

// ======================= Csomagkedvezmény + ajándék értékhatár felett + születésnap =======================
{
  const page = await newPage();
  await page.goto(url('MND-HT-0490'), { waitUntil: 'networkidle' });
  ok(await page.isVisible('.bundle-offer'), 'csomag: „Csomagban olcsóbb” ajánlat a termékoldalon');
  const offer = num(await page.textContent('.bundle-total strong'));
  await page.click('.bundle-offer .iu-button');
  await page.waitForLoadState('networkidle');
  ok(/kosar/.test(page.url()) && (await page.$$('.woocommerce-cart-form .cart_item')).length >= 2, 'csomag: „Mind a kosárba” – a kosár oldalra visz, a termékek benne', page.url());
  const cartText = await page.textContent('.woocommerce-cart-form');
  ok(/Csomagkedvezmény/.test(cartText) && (await page.$$('.woocommerce-cart-form .product-price del')).length >= 2, 'csomag: a kosárban áthúzott ár és „Csomagkedvezmény” jelölés');
  ok(await page.isVisible('.cart_item .gwp-badge') && /Ajándék/.test(cartText), 'ajándék: 15 000 Ft felett magától a kosárba kerül („Ajándék”)');
  ok(await page.isVisible('.gwp-meter.is-done'), 'ajándék: a sáv jelzi, hogy jár');
  const subtotal = num(await page.textContent('.cart-subtotal .amount'));
  ok(Math.abs(subtotal - offer) <= 2, 'csomag: a részösszeg a csomagajánlat ára (ajándék 0 Ft)', `${subtotal} / ${offer}`);
  const oid = await checkout(page, { email: 'csomag@example.com', before: async () => {
    ok(await page.isVisible('.birthday-field select[name="mandala_bday_m"]'), 'születésnap: választó a pénztárban');
    await page.selectOption('select[name="mandala_bday_m"]', '3');
    await page.selectOption('select[name="mandala_bday_d"]', '14');
  } });
  ok(oid > 0, 'csomag + ajándék: rendelés leadva');
  const lines = JSON.parse(wp(`$o = wc_get_order(${oid}); $r = []; foreach ($o->get_items() as $i) $r[] = [$i->get_name(), (float) $i->get_total(), wc_get_order_item_meta($i->get_id(), "Csomagkedvezmény", true), wc_get_order_item_meta($i->get_id(), "Ajándék", true)]; echo wp_json_encode($r);`));
  ok(lines.some((l) => l[1] === 0 && l[3]) && lines.filter((l) => l[2]).length === 2, 'rendelés: ajándék 0 Ft-tal és megjegyzéssel, a csomag tételei jelölve', JSON.stringify(lines));
  ok(wp('echo mandala_birthday_get("csomag@example.com");') === '03-14', 'születésnap: a pénztárban megadott nap elmentve (év nélkül)');
  const before = mails().length;
  ok(wp('delete_option("mandala_birthday_sent"); echo mandala_birthday_send("03-14"), mandala_birthday_send("03-14");') === '10', 'születésnap: a napján kupon levél megy, évente csak egyszer');
  ok(/Boldog születésnapot[\s\S]*SZULINAP-/.test(mails().slice(before)), 'születésnap: a levélben egyedi SZULINAP kupon');
  await page.context().close();
}

// ======================= Ajándék: kivétel után nem kerül vissza; határ alatt nincs =======================
{
  const page = await newPage();
  await page.goto(`${BASE}/?add-to-cart=${wp('echo wc_get_product_id_by_sku("MND-FK-0012");')}`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
  ok(!(await page.$('.cart_item .gwp-badge')) && /Még .* és ajándék füstölő/.test(await page.textContent('.gwp-meter')), 'ajándék: határ alatt nincs a kosárban, a sáv mutatja, mennyi hiányzik');
  await page.goto(`${BASE}/?add-to-cart=${wp('echo wc_get_product_id_by_sku("MND-HT-0490");')}`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
  ok(await page.isVisible('.cart_item .gwp-badge'), 'ajándék: a határ átlépésekor bekerül');
  await page.click('.cart_item:has(.gwp-badge) .remove');
  await page.waitForFunction(() => !document.querySelector('.cart_item .gwp-badge'), null, { timeout: 10000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
  ok(!(await page.$('.cart_item .gwp-badge')), 'ajándék: ha a vásárló kiveszi, nem tesszük vissza');
  await page.context().close();
}

// ======================= Előfizetés =======================
{
  const page = await newPage();
  await page.goto(url('MND-FK-0012'), { waitUntil: 'networkidle' });
  ok(await page.isVisible('.sub-choice'), 'előfizetés: „Egyszeri / Előfizetés” választó a füstölő oldalán');
  await page.check('.sub-choice input[name="mandala_sub"]:not([value="0"])');
  await page.click('.single_add_to_cart_button');
  await page.waitForLoadState('networkidle');
  await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
  const text = await page.textContent('.woocommerce-cart-form');
  const base = Number(wp('echo wc_get_product(wc_get_product_id_by_sku("MND-FK-0012"))->get_price();'));
  ok(/Előfizetés/.test(text) && Math.abs(num(await page.textContent('.cart_item .product-subtotal')) - Math.round(base * 0.9)) <= 1, 'előfizetés: a kosárban jelölve, 10% kedvezménnyel', text.replace(/\s+/g, ' ').slice(0, 120));
  const oid = await checkout(page, { email: 'elofizeto@example.com', payment: 'bacs' });
  ok(oid > 0, 'előfizetés: első rendelés leadva');
  wp(`wc_get_order(${oid})->update_status("processing");`);
  const sub = Number(wp('$ids = get_posts(["post_type" => "mandala_sub", "numberposts" => 1, "fields" => "ids", "meta_key" => "_email", "meta_value" => "elofizeto@example.com"]); echo (int) ($ids[0] ?? 0);'));
  ok(sub > 0 && wp(`echo mandala_sub_meta(${sub}, "status"), "|", mandala_sub_meta(${sub}, "interval");`) === 'active|1', 'előfizetés: a feldolgozott rendelésből létrejött (aktív, havonta)');
  const next = wp(`echo mandala_sub_meta(${sub}, "next");`);
  const remind = wp(`echo (new DateTime("${next}"))->modify("-3 day")->format("Y-m-d");`);
  let m0 = mails().length;
  ok(wp(`echo implode(",", mandala_sub_daily("${remind}"));`).startsWith('1') && /Úton a következő csomag[\s\S]*Lemondom/.test(mails().slice(m0)), 'előfizetés: 3 nappal előtte emlékeztető kihagyás / lemondás linkkel');
  m0 = mails().length;
  ok(wp(`echo implode(",", mandala_sub_daily("${next}"));`).endsWith('1'), 'előfizetés: esedékességkor új rendelés készül');
  const renewed = JSON.parse(wp(`$o = mandala_sub_meta(${sub}, "orders"); $n = wc_get_order(end($o)); echo wp_json_encode([$n->get_status(), (float) $n->get_total(), count($n->get_items())]);`));
  ok(renewed[0] === 'on-hold' && renewed[2] === 1 && /elkészült a #\d+ rendelésed/.test(mails().slice(m0)), 'előfizetés: átutalásnál „Fizetésre vár” rendelés + levél', JSON.stringify(renewed));
  // Aláírt link (vendég): kihagyás
  const link = wp(`echo mandala_sub_link(${sub}, "skip");`);
  const before = wp(`echo mandala_sub_meta(${sub}, "next");`);
  await page.goto(link, { waitUntil: 'networkidle' });
  ok(wp(`echo mandala_sub_meta(${sub}, "next");`) > before, 'előfizetés: a levél aláírt linkjével kihagyható (a következő dátum tolódik)');
  const bad = await fetch(link.replace(/k=[^&]+/, 'k=hamis'), { redirect: 'manual' });
  ok(bad.status === 403, 'előfizetés: hamis aláírással nem módosítható');
  await page.context().close();
}

// ======================= Partnerprogram =======================
{
  const pid = Number(wp('echo mandala_partner_add("partner@example.com", "JOGATESZT", "Teszt Partner");'));
  ok(pid > 0 && /Üdv a Mandala partnerprogramjában/.test(mails()), 'partner: felvétel + üdvözlő levél a kóddal és a linkkel');
  const page = await newPage();
  await page.goto(`${BASE}/?partner=JOGATESZT`, { waitUntil: 'networkidle' });
  ok(!page.url().includes('partner='), 'partner: a link sütit állít és tiszta címre visz');
  await page.goto(`${BASE}/?add-to-cart=${wp('echo wc_get_product_id_by_sku("MND-FK-0012");')}`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
  ok(/jogateszt/i.test(await page.textContent('.cart_totals')), 'partner: a kód a kosárban magától érvényesül');
  const oid = await checkout(page, { email: 'partnervevo@example.com' });
  wp(`wc_get_order(${oid})->update_status("completed");`);
  const c = Number(wp(`echo wc_get_order(${oid})->get_meta("_mandala_partner_commission");`));
  ok(c > 0 && Number(wp(`echo mandala_partner_balance(${pid});`)) === c && /Jutalék jóváírva/.test(mails()), 'partner: teljesítéskor jutalék jóváírva + levél', String(c));
  ok(Number(wp(`echo (int) get_user_meta(${pid}, "_mandala_partner_clicks", true);`)) >= 1, 'partner: a kattintás számlálva');
  await page.context().close();
}

// ======================= Árcsökkenés-értesítő =======================
{
  const pid = wp('echo wc_get_product_id_by_sku("MND-FK-0012");');
  wp(`global $wpdb; $wpdb->replace(mandala_browse_table(), ["token" => str_repeat("a", 32), "email" => "arfigyelo@example.com", "views" => wp_json_encode([[${pid}, time() - 3600, 99999]]), "last_view" => gmdate("Y-m-d H:i:s", time() - 3600), "created" => gmdate("Y-m-d H:i:s")]);`);
  const m0 = mails().length;
  ok(wp('echo mandala_pricedrop_sweep();') === '1' && /Olcsóbb lett, amit néztél/.test(mails().slice(m0)), 'árcsökkenés: a megnézett, azóta olcsóbb termékről levél megy');
  wp('delete_transient("mandala_pd_" . md5("arfigyelo@example.com"));');
  ok(wp('echo mandala_pricedrop_sweep();') === '0', 'árcsökkenés: ugyanarról az árról nem megy újra');
}

// ======================= Kilépési ablak (csak kilépéskor mód) =======================
{
  wp('$s = mandala_growth_settings(); $s["popup"] = "yes"; $s["popup_delay"] = 1; $s["popup_mode"] = "exit"; update_option("mandala_growth", $s);');
  const page = await newPage();
  await page.goto(`${BASE}/rolunk/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);
  const shownEarly = await page.evaluate(() => !!document.querySelector('.welcome-layer'));
  await page.mouse.move(700, 300);
  await page.mouse.move(700, 0);
  await page.evaluate(() => document.dispatchEvent(new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
  await page.waitForTimeout(600);
  const shown = await page.evaluate(() => !!document.querySelector('.welcome-layer'));
  ok(!shownEarly && shown, '„csak kilépéskor” mód: nem ugrik fel magától, csak kilépési szándékra', `${shownEarly}/${shown}`);
  wp('$s = mandala_growth_settings(); $s["popup_delay"] = 25; $s["popup_mode"] = "both"; update_option("mandala_growth", $s);');
  await page.context().close();
}

// ======================= Levélközpont =======================
ok(wp('$t = mandala_mail_types(); echo (int) (isset($t["birthday"], $t["price_drop"], $t["sub_upcoming"], $t["sub_renewal"], $t["partner_welcome"], $t["partner_sale"]));') === '1', 'levélközpont: az új levelek szerkeszthetők');

wp('update_option("mandala_bundles", []); $s = mandala_growth_settings(); $s["gwp"] = "no"; update_option("mandala_growth", $s);');
wp('$p = wc_get_product(wc_get_product_id_by_sku("MND-HT-0490")); $p->set_stock_quantity(2); $p->save();');
ok(errors.length === 0, 'nincs JS / szerver hiba', errors.slice(0, 5).join(' | '));
await browser.close();
console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
