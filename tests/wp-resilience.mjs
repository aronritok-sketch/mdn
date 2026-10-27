// ELIN 403 tűrőképesség, hozzájárulás, kuponlink, köszönőoldal – végponttól végpontig a teszt WordPressen.
// A tárhely tűzfalát a böngésző oldalán utánozzuk: a kiválasztott POST kérésekre az nginx 403-as oldala jön (mint az
// ELIN-nél), a GET-ek mennek. RENDELÉST csak a teszt WordPressben ad le.
//   BASE=http://localhost:8080 WP="wp --path=/var/www/html" node tests/wp-resilience.mjs
// A hozzájárulás-teszthez a PixelYourSite kicsinyített mása kell (wp-theme/dev/test-pys-stub.php) – a teszt maga
// másolja a mu-plugins mappába, és a végén törli.
import { createRequire } from 'module';
import { execSync } from 'child_process';
import { copyFileSync, rmSync } from 'fs';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const BASE = (process.env.BASE || 'http://localhost:8080').replace(/\/$/, '');
const WP = process.env.WP || 'wp';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const wp = (php) => execSync(`${WP} eval '${php.replace(/'/g, "'\\''")}' 2>/dev/null`, { encoding: 'utf8' }).trim();
const num = (s) => Number(String(s).replace(/[^\d-]/g, '')) || 0;

let fails = 0;
const ok = (cond, label, extra = '') => { console.log(`${cond ? '✓' : '✗'} ${label}${extra ? ` – ${extra}` : ''}`); if (!cond) fails++; };
const errors = [];
const NGINX_403 = '<html>\r\n<head><title>403 Forbidden</title></head>\r\n<body>\r\n<center><h1>403 Forbidden</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'; // a szerver a „Headless” böngészőt botnak venné

const MU = wp('echo WPMU_PLUGIN_DIR;');
const STUB = path.join(MU, 'test-pys-stub.php');
copyFileSync(path.join(ROOT, 'wp-theme/dev/test-pys-stub.php'), STUB);
const stat = (metric) => Number(wp(`global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare("SELECT n FROM " . mandala_stats_table() . " WHERE day = %s AND metric = %s", wp_date("Y-m-d"), "${metric}"));`)) || 0;
const sku = (s) => Number(wp(`echo wc_get_product_id_by_sku("${s}");`));
const link = (id) => wp(`echo get_permalink(${id});`);
// A használt termékek készlete (ismételt futtatásnál ne fogyjon el); a végén visszaáll – a wp-e2e.mjs a bemutató
// készletével számol (MND-HT-0490: 2 db, „utolsó darabok”).
const origStock = wp('$o = []; foreach (["MND-HT-0490", "MND-FK-0012"] as $s) { $p = wc_get_product(wc_get_product_id_by_sku($s)); $o[$s] = [$p->get_manage_stock(), $p->get_stock_quantity(), $p->get_stock_status()]; } echo wp_json_encode($o);');
wp('foreach (["MND-HT-0490" => 50, "MND-FK-0012" => 50] as $s => $q) { $p = wc_get_product(wc_get_product_id_by_sku($s)); $p->set_manage_stock(true); $p->set_stock_quantity($q); $p->save(); } delete_option("test_hpvac_calls");');
// A mérés alapbeállítása (a többi teszt a Meta CAPI-t is bekapcsolhatta); a végén visszaáll.
const analytics0 = wp('echo wp_json_encode(get_option("mandala_analytics", null));');
wp('update_option("mandala_analytics", ["consent_default" => "yes", "pys_consent" => "yes", "track" => "yes", "capi" => "no"]);');

const browser = await chromium.launch();
/**
 * Új böngésző; block: a tiltott POST-ok mintája (pl. /wc-ajax=add_to_cart/), getBlock: a tiltott GET-eké (a végső
 * tartalék próbájához). consent: a mentett sütiválasztás (null: még nem választott).
 */
async function newPage({ block = null, getBlock = null, consent = { stats: false } } = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, userAgent: UA });
  if (consent) await ctx.addInitScript((c) => { try { localStorage.setItem('mandala.cookie.v1', JSON.stringify(c)); } catch {} }, consent);
  const page = await ctx.newPage();
  const net = { blocked: [], beacons: [] };
  const rules = { block, getBlock };
  await ctx.route('**/*', (route) => {
    const r = route.request();
    const u = r.url();
    if (/\/mandala\/v1\/blocked/.test(u)) net.beacons.push(Object.fromEntries(new URL(u).searchParams));
    const hit = (r.method() === 'POST' && rules.block && rules.block.test(u)) || (r.method() === 'GET' && rules.getBlock && rules.getBlock.test(u));
    if (hit) { net.blocked.push(`${r.method()} ${u}`); return route.fulfill({ status: 403, contentType: 'text/html', headers: { server: 'ELIN.hu' }, body: NGINX_403 }); }
    return route.continue();
  });
  page.on('pageerror', (e) => errors.push(`PAGEERROR ${page.url()} ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(`CONSOLE ${page.url()} ${m.text()}`); });
  page.on('response', (r) => { if (r.status() >= 500) errors.push(`HTTP ${r.status()} ${r.url()}`); });
  return { page, ctx, net, rules };
}
const cartCount = (page) => page.evaluate(() => Number(document.querySelector('[data-cart-count]')?.textContent || 0));
const waitUpdate = (page) => page.waitForFunction(() => !document.querySelector('.blockUI'), null, { timeout: 10000 }).then(() => page.waitForTimeout(400));
const toastText = (page) => page.textContent('.toasts').catch(() => '');

try {
  // ======================= 1. Kártya „Kosárba” – a POST-ot a tűzfal tiltja =======================
  {
    const { page, ctx, net } = await newPage({ block: /wc-ajax=add_to_cart/ });
    const blk0 = stat('blk_e_add_to_cart');
    const res0 = stat('blk_rescued');
    await page.goto(`${BASE}/kategoria/szakralis-targyak/hangtalak/`, { waitUntil: 'networkidle' });
    const before = await cartCount(page);
    const btn = page.locator('li.product .loop-product-button a.ajax_add_to_cart').first();
    const startUrl = page.url();
    await btn.click();
    await page.waitForFunction((n) => Number(document.querySelector('[data-cart-count]')?.textContent || 0) > n, before, { timeout: 15000 }).catch(() => {});
    ok((await cartCount(page)) === before + 1, 'kártya: a tiltott POST után GET-tel bekerült', `${before} → ${await cartCount(page)}`);
    ok(net.blocked.some((x) => /^POST .*add_to_cart/.test(x)) && page.url() === startUrl, 'kártya: a POST tiltva volt, oldalbetöltés nélkül ment', net.blocked.join(' | '));
    ok(/Kosárba tettük/.test(await toastText(page)), 'kártya: a vásárló visszajelzést kap');
    await page.waitForTimeout(500);
    ok(stat('blk_e_add_to_cart') === blk0 + 1 && net.beacons.length === 1, 'jelzés: a szerver megszámolta (kosárba, ma)', `${blk0} → ${stat('blk_e_add_to_cart')}`);
    ok(stat('blk_rescued') === res0 + 1, 'Mandala állapot: „megmentve” +1');
    await ctx.close();
  }

  // ======================= 2. Termékoldal (egyszerű termék) =======================
  {
    const { page, ctx } = await newPage({ block: /wc-ajax=add_to_cart/ });
    await page.goto(link(sku('MND-HT-0490')), { waitUntil: 'networkidle' });
    const before = await cartCount(page);
    await page.click('.product-summary .single_add_to_cart_button');
    await page.waitForSelector('#minicart.is-open', { timeout: 15000 }).catch(() => {});
    ok(await page.isVisible('#minicart.is-open') && (await cartCount(page)) === before + 1, 'termékoldal: tiltott POST után is a kosárba került, a minikosár kinyílt');
    await ctx.close();
  }

  // ======================= 3. Előfizethető termék (eddig teljes oldalas POST → fehér 403 oldal) =======================
  {
    const { page, ctx } = await newPage({ block: /wc-ajax=add_to_cart|\/termek\// });
    await page.goto(link(sku('MND-FK-0012')), { waitUntil: 'networkidle' });
    ok(await page.isVisible('.sub-choice'), 'előfizetés: a választó látszik');
    await page.check('.sub-choice input[name="mandala_sub"]:not([value="0"])');
    const before = await cartCount(page);
    await page.click('.product-summary .single_add_to_cart_button');
    await page.waitForFunction((n) => Number(document.querySelector('[data-cart-count]')?.textContent || 0) > n, before, { timeout: 15000 }).catch(() => {});
    ok(!/403 Forbidden/.test(await page.title()), 'előfizetés: nincs fehér „403 Forbidden” oldal');
    await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
    const row = await page.locator('.cart_item', { hasText: 'Szantál' }).first().textContent().catch(() => '');
    ok(/Előfizetés/.test(row || ''), 'előfizetés: a GET-tel betett tétel is előfizetéses (a választás nem veszett el)', (row || '').replace(/\s+/g, ' ').slice(0, 120));
    await ctx.close();
  }

  // ======================= 4. Végső tartalék: a GET ismétlés sem megy → oldalbetöltés, utána tiszta cím =======================
  {
    const { page, ctx, rules } = await newPage({ block: /wc-ajax=add_to_cart/, getBlock: /wc-ajax=add_to_cart/ });
    await page.goto(`${BASE}/kategoria/szakralis-targyak/hangtalak/?orderby=price`, { waitUntil: 'networkidle' });
    const before = await cartCount(page);
    await page.locator('li.product .loop-product-button a.ajax_add_to_cart').first().click();
    await page.waitForURL(/mandala_added=|orderby=price$/, { timeout: 20000 }).catch(() => {});
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
    ok((await cartCount(page)) === before + 1 && /Kosárba tettük/.test(await toastText(page)), 'végső tartalék: oldalbetöltéssel bekerült, visszajelzéssel', page.url());
    ok(!/add-to-cart|mfb|mandala_added/.test(page.url()) && /orderby=price/.test(page.url()), 'végső tartalék: a cím tiszta, a rendezés megmaradt', page.url());
    rules.block = null; rules.getBlock = null;
    await page.reload({ waitUntil: 'networkidle' });
    ok((await cartCount(page)) === before + 1, 'végső tartalék: frissítésre nem kerül be újra');
    await ctx.close();
  }

  // ======================= 5. Pénztár: frissítés és rendelés leadása tiltva =======================
  {
    const { page, ctx, rules, net } = await newPage();
    await page.goto(link(sku('MND-HT-0490')), { waitUntil: 'networkidle' });
    await page.click('.product-summary .single_add_to_cart_button');
    await page.waitForSelector('#minicart.is-open', { timeout: 15000 }).catch(() => {});
    await page.goto(`${BASE}/penztar/`, { waitUntil: 'networkidle' });
    for (const [sel, v] of [['#billing_email', 'elin.teszt@example.com'], ['#billing_phone', '+36 30 123 4567'], ['#billing_last_name', 'Kovács'], ['#billing_first_name', 'Anna'], ['#billing_postcode', '1052'], ['#billing_city', 'Budapest'], ['#billing_address_1', 'Váci utca 1.']]) {
      if (!(await page.inputValue(sel))) await page.fill(sel, v);
    }
    rules.block = /wc-ajax=update_order_review/;
    await page.evaluate(() => window.jQuery(document.body).trigger('update_checkout'));
    await page.waitForSelector('.mandala-net-notice', { timeout: 10000 }).catch(() => {});
    ok(!(await page.$('.blockUI')), 'pénztár: tiltott frissítés után nincs beragadt takarás');
    ok(/Most nem értük el a pénztárt/.test(await page.textContent('#section-shipping').catch(() => '')) && await page.isVisible('[data-net-retry="review"]'), 'pénztár: érthető üzenet a szállítási módok helyén, „Újrapróbálom” gombbal');
    rules.block = null;
    await page.click('[data-net-retry="review"]');
    await waitUpdate(page);
    ok(!(await page.$('.mandala-net-notice')) && (await page.$$('#shipping_method input.shipping_method, #shipping_method li')).length > 0, 'pénztár: újrapróbálás után megjelennek a szállítási módok, az üzenet eltűnik');
    if (await page.$('#shipping_method li[data-kind="pickup"] label')) { await page.click('#shipping_method li[data-kind="pickup"] label'); await waitUpdate(page); }
    if (await page.$('#payment_method_bacs')) { await page.check('#payment_method_bacs'); await waitUpdate(page); }
    await page.check('#terms');
    rules.block = /wc-ajax=checkout/;
    const orders0 = Number(wp('echo count(wc_get_orders(["limit" => -1, "return" => "ids", "billing_email" => "elin.teszt@example.com"]));'));
    await page.click('#place_order');
    await page.waitForSelector('.mandala-net-notice[data-kind="order"]', { timeout: 15000 }).catch(() => {});
    ok(/Még nem jött létre/.test(await page.textContent('.mandala-net-notice').catch(() => '')), 'rendelés leadása tiltva: „még nem jött létre” üzenet, telefon, e-mail');
    ok(await page.$eval('#place_order', (b) => !b.disabled) && !(await page.$('form.checkout.processing')) && !(await page.$('.blockUI')), 'rendelés leadása tiltva: a gomb használható, nincs takarás');
    ok(Number(wp('echo count(wc_get_orders(["limit" => -1, "return" => "ids", "billing_email" => "elin.teszt@example.com"]));')) === orders0, 'rendelés leadása tiltva: valóban nem jött létre rendelés');
    rules.block = null;
    await page.click('[data-net-retry="order"]');
    await page.waitForURL(/order-received|rendeles-fogadva/, { timeout: 20000 }).catch(() => {});
    ok(/order-received|rendeles-fogadva/.test(page.url()), 'rendelés leadása: „Újrapróbálom” után (a tiltás megszűnt) a rendelés elmegy', page.url());
    ok(net.beacons.some((b) => b.e === 'update_order_review') && net.beacons.some((b) => b.e === 'checkout'), 'jelzés: pénztár-frissítés és rendelés leadása is', JSON.stringify(net.beacons.map((b) => b.e)));
    await ctx.close();
  }

  // ======================= 6. Kuponlink (?kupon=) visszajelzéssel =======================
  {
    wp('foreach (["hot10" => [10, null], "lejart10" => [10, time() - DAY_IN_SECONDS]] as $c => [$a, $exp]) { $id = wc_get_coupon_id_by_code($c); $k = new WC_Coupon($id ?: 0); $k->set_code($c); $k->set_discount_type("percent"); $k->set_amount($a); $k->set_date_expires($exp); $k->save(); }');
    const { page, ctx } = await newPage();
    await page.goto(`${BASE}/termekek/?kupon=hot10`, { waitUntil: 'networkidle' });
    ok(/A hot10 kód a kosaradba került: −10%/.test(await toastText(page)), 'kuponlink: „A hot10 kód a kosaradba került: −10%”', (await toastText(page)).trim());
    ok(!/kupon=/.test(page.url()), 'kuponlink: a cím tiszta (megosztásnál nem ismétlődik)', page.url());
    await page.goto(`${BASE}/termekek/?kupon=lejart10`, { waitUntil: 'networkidle' });
    ok(/lejart10 kód már lejárt/.test(await toastText(page)), 'kuponlink: lejárt kódnál semleges üzenet', (await toastText(page)).trim());
    await page.goto(`${BASE}/termekek/?kupon=nincsilyen`, { waitUntil: 'networkidle' });
    ok(/nincsilyen kódot nem találjuk/.test(await toastText(page)), 'kuponlink: ismeretlen kódnál semleges üzenet');
    // Kosárba tétel után a hot10 kedvezménye a kosárban (a meglévő kampány-logika).
    await page.goto(link(sku('MND-HT-0490')), { waitUntil: 'networkidle' });
    await page.click('.product-summary .single_add_to_cart_button');
    await page.waitForSelector('#minicart.is-open', { timeout: 15000 }).catch(() => {});
    await page.goto(`${BASE}/kosar/`, { waitUntil: 'networkidle' });
    ok(/hot10/i.test(await page.textContent('.cart_totals, .cart-collaterals').catch(() => '')), 'kuponlink: a kosárban levonva');
    // Csak más termékre érvényes kód, a kosárban lévőre nem: elmentjük, és megmondjuk.
    wp('$id = wc_get_coupon_id_by_code("csakfustolo"); $k = new WC_Coupon($id ?: 0); $k->set_code("csakfustolo"); $k->set_discount_type("percent"); $k->set_amount(5); $k->set_product_ids([wc_get_product_id_by_sku("MND-FK-0012")]); $k->save();');
    await page.goto(`${BASE}/termekek/?kupon=csakfustolo`, { waitUntil: 'networkidle' });
    ok(/csakfustolo kódot elmentettük, de a kosaradban lévő termékekre nem érvényes/.test(await toastText(page)), 'kuponlink: a kosár termékeire nem érvényes kódnál semleges üzenet', (await toastText(page)).trim().slice(0, 140));
    await ctx.close();
  }

  // ======================= 7. Hozzájárulás: Consent Mode és Meta pixel (PixelYourSite másával) =======================
  {
    const read = (page) => page.evaluate(() => ({
      pys: (window.dataLayerPYS || []).filter((e) => e && e[0] === 'consent').map((e) => `${e[1]}:${e[2].analytics_storage}/${e[2].ad_storage}`),
      fbq: typeof window.fbq === 'function',
      events: (window.__fbq || []).map((x) => x.slice(0, 2).join(':')),
      cookie: /pys_first_visit/.test(document.cookie),
    }));
    let { page, ctx } = await newPage({ consent: null });
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    let s = await read(page);
    ok(s.pys.length >= 2 && s.pys.every((x) => !/granted/.test(x)), 'választás előtt: a Google Consent Mode minden jelzése „denied” (a PixelYourSite sem állítja át)', s.pys.join(' '));
    ok(!s.fbq && !s.events.length, 'választás előtt: a Meta pixel nem töltődik be, nincs PageView');
    ok(!s.cookie, 'választás előtt: a PixelYourSite saját sütijei sincsenek');
    await page.click('[data-cookie="necessary"]');
    await page.waitForTimeout(300);
    s = await read(page);
    ok(!s.fbq && /update:denied\/denied$/.test(s.pys.at(-1)), '„Csak a szükségesek”: marad elutasítva, Meta nincs', s.pys.at(-1));
    await page.reload({ waitUntil: 'networkidle' });
    s = await read(page);
    ok(!s.fbq && s.pys.every((x) => !/granted/.test(x)), '„Csak a szükségesek” után a következő oldalon is elutasítva', s.pys.join(' '));
    await ctx.close();

    ({ page, ctx } = await newPage({ consent: null }));
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.click('[data-cookie="all"]');
    await page.waitForTimeout(300);
    s = await read(page);
    ok(s.fbq && s.events.includes('track:PageView') && /update:granted\/granted$/.test(s.pys.at(-1)), '„Mindet elfogadom”: Consent Mode engedélyezve, a Meta pixel betölt és PageView-t küld', `${s.pys.at(-1)} ${s.events.join(',')}`);
    await page.reload({ waitUntil: 'networkidle' });
    s = await read(page);
    ok(s.fbq && s.pys.slice(1).every((x) => /granted/.test(x) || /^default/.test(x)), 'elfogadás után a következő oldalon már az elején engedélyezve', s.pys.join(' '));
    await ctx.close();
  }

  // ======================= 8. Köszönőoldal: kártyás rendelés, a Teya értesítése nem jött meg =======================
  {
    const oid = Number(wp(`$o = wc_create_order(["status" => "pending", "created_via" => "checkout"]); $o->add_product(wc_get_product(wc_get_product_id_by_sku("MND-HT-0490")), 1); $o->set_billing_email("kartya.teszt@example.com"); $o->set_billing_first_name("Kata"); $o->set_payment_method("borgun"); $o->set_payment_method_title("Teya – Bankkártyás fizetés"); $o->update_meta_data("_hpvac_consent", "granted"); $o->calculate_totals(); $o->save(); echo $o->get_id();`));
    const url = wp(`echo wc_get_order(${oid})->get_checkout_order_received_url();`);
    const { page, ctx } = await newPage({ consent: { stats: true, marketing: true } });
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2200);
    const dl = await page.evaluate(() => (window.dataLayer || []).filter((e) => e && e.event === 'purchase').map((e) => e.ecommerce.transaction_id));
    ok(dl.length === 1 && dl[0] === wp(`echo wc_get_order(${oid})->get_order_number();`), 'köszönőoldal: dataLayer purchase a függő kártyás rendelésnél is (transaction_id)', dl.join(','));
    const px = await page.evaluate(() => ({ fb: (window.__fbq || []).filter((x) => x[1] === 'Purchase').map((x) => x[3]?.eventID), ga: (window.dataLayerPYS || []).filter((e) => e && e[0] === 'event' && e[1] === 'purchase').length }));
    ok(px.fb.length === 1 && px.fb[0] === `wc-${oid}` && px.ga === 1, 'köszönőoldal: a PixelYourSite helyett Meta Purchase (eventID = a hub azonosítója) és GA4 purchase', JSON.stringify(px));
    ok(JSON.parse(wp('echo wp_json_encode(get_option("test_hpvac_calls", []));')).filter((x) => x === oid).length === 1, 'köszönőoldal: a hub vásárlás-eseménye elindult');
    ok(/visszatért a Teyától/.test(wp(`echo implode(" ", array_map(fn($n) => $n->content, wc_get_order_notes(["order_id" => ${oid}])));`)), 'köszönőoldal: rendelés-jegyzet a csapatnak');
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(2200);
    const again = await page.evaluate(() => ({ dl: (window.dataLayer || []).filter((e) => e && e.event === 'purchase').length, fb: (window.__fbq || []).filter((x) => x[1] === 'Purchase').length }));
    ok(again.dl === 0 && again.fb === 0, 'köszönőoldal újratöltve: nem számol újra', JSON.stringify(again));
    wp(`$o = wc_get_order(${oid}); $o->payment_complete("TESZT"); `);
    ok(JSON.parse(wp('echo wp_json_encode(get_option("test_hpvac_calls", []));')).filter((x) => x === oid).length === 1, 'a később megérkező fizetés nem küldi újra a hubnak (rendelésenként egyszer)');
    ok(wp(`echo (int) apply_filters("woocommerce_cancel_unpaid_order", true, wc_get_order(${oid}));`) === '0', 'a visszatért kártyás rendelést a WooCommerce nem törli fizetetlenként');
    const oid2 = Number(wp('$o = wc_create_order(["status" => "pending"]); $o->set_payment_method("borgun"); $o->update_meta_data("_mandala_card_returned", time() - HOUR_IN_SECONDS); $o->save(); echo $o->get_id();'));
    const teya = JSON.parse(wp('$c = mandala_health_checks(false); echo wp_json_encode($c["teya"] ?? null);'));
    ok(teya && teya.status === 'warn' && teya.msg.includes(`#${oid2}`), 'őrszem: figyelmeztet a visszaigazolásra váró kártyás rendelésre', teya?.msg || '');
    wp(`wp_delete_post(${oid2}, true); $o = wc_get_order(${oid2}); if ($o) { $o->delete(true); }`);
    await ctx.close();
  }

  // ======================= 9. Mandala állapot (admin) és a látogatás-számláló GET-tel =======================
  {
    const views0 = stat('views');
    const { page, ctx } = await newPage({ block: /\/mandala\/v1\/hit/ });
    await page.goto(`${BASE}/rolunk/`, { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(500);
    ok(stat('views') === views0 + 1, 'látogatás-számláló: GET-tel megy (a POST-tiltás nem rontja a látogatószámot)', `${views0} → ${stat('views')}`);
    await ctx.close();
    const html = wp('ob_start(); mandala_status_page(); echo ob_get_clean();');
    ok(/Mandala állapot/.test(html) && /Tiltott kérés/.test(html) && /Leggyakrabban tiltott hálózatok/.test(html) && /127\.0\.0\.0\/24|::\/48/.test(html), 'admin: Mandala állapot – 14 napos táblázat, tiltott hálózatok (/24)');
    const h = JSON.parse(wp('$c = mandala_health_checks(false); echo wp_json_encode($c["elin"] ?? null);'));
    ok(h && /tiltott|tiltott kérés|tiltott kosárba/i.test(h.msg), 'őrszem: a tiltások száma az ellenőrzések között', h?.msg || '');
  }
} finally {
  rmSync(STUB, { force: true });
  for (const [s, [manage, qty, status]] of Object.entries(JSON.parse(origStock))) {
    wp(`$p = wc_get_product(wc_get_product_id_by_sku("${s}")); $p->set_manage_stock(${manage ? 'true' : 'false'}); $p->set_stock_quantity(${Number(qty) || 0}); $p->set_stock_status("${status || 'instock'}"); $p->save();`);
  }
  wp(analytics0 === 'null' ? 'delete_option("mandala_analytics");' : `update_option("mandala_analytics", json_decode(${JSON.stringify(analytics0)}, true));`);
  await browser.close();
}

const relevant = errors.filter((e) => !/favicon|pys-facebook|google|facebook/.test(e));
ok(relevant.length === 0, 'nincs JS hiba / 5xx', relevant.slice(0, 5).join(' | '));
console.log(fails ? `\n${fails} hiba` : '\nMinden rendben.');
process.exit(fails ? 1 : 0);
