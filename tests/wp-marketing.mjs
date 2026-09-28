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

// ======================= Kampány + ?kupon= link =======================
{
  wp('foreach (["KAMPANY10" => 10, "LINK5" => 5] as $code => $pct) { if (!wc_get_coupon_id_by_code($code)) { $c = new WC_Coupon(); $c->set_code($code); $c->set_discount_type("percent"); $c->set_amount($pct); $c->save(); } }');
  wp('update_option("mandala_campaigns", [["name" => "Teszt kampány", "start" => wp_date("Y-m-d H:i", time() - 3600), "end" => wp_date("Y-m-d H:i", time() + 86400 * 2), "bar" => "Teszt kampány fut", "eyebrow" => "Kampány", "title" => "Kampány cím", "text" => "Szöveg", "button" => "Megnézem", "url" => "", "coupon" => "kampany10", "auto" => "yes", "enabled" => "yes"]]);');
  const page = await newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  ok(await page.isVisible('.campaign-banner') && /Teszt kampány fut/.test(await page.textContent('.mandala-notice')), 'kampány: aktív kampány alatt banner a főoldalon és a felső sáv a kampányé');
  ok(/még \d+ nap/.test(await page.textContent('.campaign-bar')), 'kampány: visszaszámláló a felső sávban');
  await page.goto(`${BASE}/?add-to-cart=${wp('echo wc_get_product_id_by_sku("MND-FK-0012");')}`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
  ok(/kampany10/i.test(await page.textContent('.cart_totals')), 'kampány: az automatikus kupon a kosárban');
  wp('update_option("mandala_campaigns", []);');
  await page.context().close();
  const p2 = await newPage();
  await p2.goto(`${BASE}/rolunk/?kupon=LINK5`, { waitUntil: 'networkidle' });
  await p2.goto(`${BASE}/?add-to-cart=${wp('echo wc_get_product_id_by_sku("MND-FK-0012");')}`, { waitUntil: 'networkidle' });
  await p2.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
  ok(/link5/i.test(await p2.textContent('.cart_totals')), '?kupon= link: üres kosárnál is megjegyzi, a kosárban érvényesül');
  const r = await p2.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  ok(!(await p2.$('.campaign-banner')), 'kampány: lejárt / törölt kampány után nincs banner');
  await p2.context().close();
}

// ======================= Csatornák: Pinterest, Árukereső Megbízható Bolt, Google (Store), vásárlói fotók =======================
{
  wp('mandala_feeds_build();');
  const feed = await (await fetch(`${BASE}/?mandala_feed=pinterest`)).text();
  ok(/<item>[\s\S]*<g:id>/.test(feed), 'Pinterest feed: RSS termékekkel');
  wp('update_option("mandala_trustedshop", ["key" => "teszt-kulcs", "consent" => "yes", "widget" => "<span data-ts>AK</span>"]);');
  wp('update_option("mandala_localbiz", array_merge(mandala_localbiz(), ["street" => "Váci utca 1.", "zip" => "1052", "lat" => "47.49", "lng" => "19.05"]));');
  const page = await newPage();
  const home = await (await fetch(`${BASE}/`)).text();
  ok(/"@type":"Store"[\s\S]*"streetAddress":"Váci utca 1\."[\s\S]*OpeningHoursSpecification/.test(home), 'Google: bemutatóterem adatai (Store, cím, nyitvatartás) a főoldalon');
  ok(/<span data-ts>AK<\/span>/.test(home), 'Árukereső: jelvény a láblécben');
  await page.goto(`${BASE}/?add-to-cart=${wp('echo wc_get_product_id_by_sku("MND-FK-0012");')}`, { waitUntil: 'networkidle' });
  const oid = await checkout(page, { email: 'ak@example.com', before: async () => {
    ok(await page.isVisible('input[name="mandala_ts_ok"]'), 'Árukereső: hozzájárulás a pénztárban (alapból nincs bepipálva)');
    await page.check('input[name="mandala_ts_ok"]');
  } });
  ok(/Árukereső Megbízható Bolt/.test(wp(`echo implode(" | ", array_map(fn($n) => $n->content, wc_get_order_notes(["order_id" => ${oid}])));`)), 'Árukereső: a köszönőoldalon elküldve (a rendelésnél megjegyzés)');
  wp('update_option("mandala_trustedshop", ["key" => "", "consent" => "yes", "widget" => ""]);');
  // Vásárlói fotó egy jóváhagyott értékelésből
  wp('$src = get_stylesheet_directory() . "/assets/img/lotusz-800.webp"; $up = wp_upload_dir(); $dst = $up["path"] . "/ugc-teszt.webp"; copy($src, $dst); $att = wp_insert_attachment(["post_mime_type" => "image/webp", "post_title" => "ugc", "post_status" => "inherit"], $dst); require_once ABSPATH . "wp-admin/includes/image.php"; wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $dst)); $r = wp_insert_post(["post_type" => "mandala_review", "post_status" => "publish", "post_title" => "Szép"]); update_post_meta($r, "_product", wc_get_product_id_by_sku("MND-HT-0490")); update_post_meta($r, "_rating", 5); update_post_meta($r, "_photos", [$att]);');
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  ok(await page.isVisible('.ugc-grid li') && /Vásárlóink fotói/.test(await page.textContent('main')), 'vásárlói fotók: jóváhagyott értékelés fotója a főoldalon');
  await page.context().close();
}

// ======================= Tulajdonosi kivonat: mérés =======================
{
  const stats = () => JSON.parse(wp('echo wp_json_encode(mandala_stats_sum(wp_date("Y-m-d"), wp_date("Y-m-d")));') || '{}');
  const s0 = stats();
  const bot = await newPage(); // a headless böngésző robotnak számít
  await bot.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await bot.context().close();
  const s1 = stats();
  ok((s1.views || 0) === (s0.views || 0), 'kivonat: robot (headless) látogatás nem számít');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 MandalaTest/' + Date.now() });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const pid = wp('echo wc_get_product_id_by_sku("MND-FK-0012");');
  await page.goto(`${BASE}/?p=${pid}`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/?add-to-cart=${pid}`, { waitUntil: 'networkidle' });
  await page.goto(`${BASE}/penztar/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const s2 = stats(), d = (k) => (s2[k] || 0) - (s1[k] || 0);
  ok(d('visitors') === 1 && d('views') >= 2 && d('product_views') >= 1, 'kivonat: látogató, oldal- és termékmegtekintés mérve', JSON.stringify(s2));
  ok(d('carts') === 1 && d('checkouts') === 1, 'kivonat: kosárba tétel és pénztárba lépés mérve');
  await ctx.close();
  const od = JSON.parse(wp('echo wp_json_encode(mandala_owner_data(7));'));
  ok(od.cur.orders > 0 && od.cur.revenue > 0 && od.traffic.visitors > 0 && typeof od.traffic.conversion === 'number' && Object.keys(od.daily).length === 7, 'kivonat: heti adatok (bevétel, forgalom, konverzió, napi sor)', JSON.stringify(od.cur));
  ok(Object.keys(od.features).includes('bundle') && od.sources && od.subs.active >= 1, 'kivonat: források, csomag, előfizetés');
  const mail = wp('$s = mandala_report_settings(); update_option("mandala_report", array_merge($s, ["ai" => "no"])); add_filter("pre_wp_mail", function ($r, $a) { echo $a["subject"] . "|" . (int) (strpos($a["message"], "Tölcsér") !== false && strpos($a["message"], "Mi hozta a bevételt") !== false); return true; }, 10, 2); mandala_report_send("tulaj@example.com"); update_option("mandala_report", $s);');
  ok(/Heti tulajdonosi kivonat.*\|1$/.test(mail), 'kivonat: a heti levél elején a tulajdonosi kivonat', mail);
}

// ======================= Adminoldalak =======================
{
  const page = await newPage();
  await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'networkidle' });
  await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  for (const [slug, text] of [['mandala-growth', 'Ajándék értékhatár felett'], ['mandala-bundles', 'Mandala csomagok'], ['mandala-subs', 'Előfizetések'], ['mandala-partners', 'Partnerek'],
    ['mandala-campaigns', 'Kampányok'], ['mandala-feeds', 'Árukereső Megbízható Bolt'], ['mandala-store', 'Bemutatóterem (Google)'], ['mandala-owner', 'Tulajdonosi kivonat'], ['mandala-showcase', 'Bemutató mód bekapcsolása']]) {
    const res = await page.goto(`${BASE}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
    const body = await page.textContent('#wpbody-content').catch(() => '');
    ok(res.status() === 200 && body.includes(text) && !/Fatal error|Warning:/.test(body), `admin: ${slug} oldal betölt`);
  }
  await page.goto(`${BASE}/wp-admin/admin.php?page=mandala-owner`, { waitUntil: 'domcontentloaded' });
  const ownerTxt = await page.textContent('#wpbody-content');
  ok(/előző 30 nappal/.test(ownerTxt) && Number((await page.textContent('.mo-card:nth-child(2) strong')).trim()) > 0, 'admin: kivonat alapból 30 nap, a rendelések látszanak');
  await page.goto(`${BASE}/wp-admin/admin.php?page=mandala-campaigns&preset=karacsony`, { waitUntil: 'domcontentloaded' });
  ok(/december 19-ig/.test(await page.inputValue('input[name="bar"]')), 'admin: kampánysablon kitölti az űrlapot');
  await page.context().close();
}

// ======================= Levélközpont =======================
ok(wp('$t = mandala_mail_types(); echo (int) (isset($t["birthday"], $t["price_drop"], $t["sub_upcoming"], $t["sub_renewal"], $t["partner_welcome"], $t["partner_sale"]));') === '1', 'levélközpont: az új levelek szerkeszthetők');

wp('update_option("mandala_bundles", []); $s = mandala_growth_settings(); $s["gwp"] = "no"; update_option("mandala_growth", $s);');
wp('$p = wc_get_product(wc_get_product_id_by_sku("MND-HT-0490")); $p->set_stock_quantity(2); $p->save();');
// ======================= Bemutató mód =======================
{
  const snap = () => wp('echo md5(serialize([get_option("mandala_growth"), get_option("mandala_campaigns"), get_option("mandala_bundles"), get_option("mandala_subs")])), "|", count(wc_get_product_ids_on_sale()), "|", wp_count_posts("mandala_review")->publish, "|", wp_count_posts("mandala_event")->publish;');
  const before = snap();
  const log = wp('echo implode("\n", mandala_showcase_enable("bemutato@example.com"));');
  ok(/Akció: [1-9]/.test(log) && /Csomagkedvezmény: 3/.test(log) && /Értékelések: [1-9]/.test(log), 'bemutató mód: bekapcsol (akció, csomag, értékelés)', log.replace(/\n/g, ' / '));
  const page = await newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const main = await page.textContent('body');
  ok(await page.isVisible('.campaign-bar') && /Egy hét, ami csak rólad szól/.test(main) && /Akciós darabok/.test(main), 'bemutató mód: kampánysáv, banner, akciós sor a főoldalon');
  await page.context().close();
  ok(wp('add_filter("pre_wp_mail", function ($r, $a) { echo $a["to"], "|", $a["subject"]; return true; }, 10, 2); wp_mail("vevo@valos.hu", "Rendelés", "x");') === 'bemutato@example.com|[BEMUTATÓ → vevo@valos.hu] Rendelés', 'bemutató mód: minden levél az admin címre megy');
  ok(wp('$d = mandala_owner_data(30); echo (int) (!empty($d["demo"]) && $d["cur"]["orders"] > 50 && $d["traffic"]["visitors"] > 1000);') === '1', 'bemutató mód: a Kivonat demóadatokat mutat');
  wp('echo implode("\n", mandala_showcase_disable());');
  ok(snap() === before && wp('echo (int) wc_get_coupon_id_by_code("CSENDESHET"), (int) (bool) get_user_by("email", "partner.bemutato@example.com"), (int) mandala_showcase_on();') === '000', 'bemutató mód: kikapcsoláskor minden pontosan visszaáll', snap() + ' vs ' + before);
}

ok(errors.length === 0, 'nincs JS / szerver hiba', errors.slice(0, 5).join(' | '));
await browser.close();
console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
