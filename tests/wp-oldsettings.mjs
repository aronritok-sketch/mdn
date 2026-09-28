// Régi bolt beállításainak átvétele: export a konzolszkripttel (valódi WooCommerce REST), módosítás,
// feltöltés, átvétel, visszavonás. Futtatás: BASE=http://localhost:8080 WP="wp --path=…" node tests/wp-oldsettings.mjs
import { createRequire } from 'module';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const BASE = process.env.BASE || 'http://localhost:8080';
const WP = process.env.WP || 'wp';
const wp = (php) => execSync(`${WP} eval '${php.replace(/'/g, "'\\''")}' 2>/dev/null`, { encoding: 'utf8' }).trim();
let fails = 0;
const ok = (c, name, extra = '') => { console.log(`${c ? '✓' : '✗'} ${name}${!c && extra ? ' – ' + extra : ''}`); if (!c) fails++; };
const SCRIPT = fs.readFileSync(new URL('../wp-theme/mandala/inc/features/oldsettings-export.js', import.meta.url), 'utf8');
const state = () => JSON.parse(wp(`$z = []; foreach (WC_Shipping_Zones::get_zones() as $d) { $zone = new WC_Shipping_Zone($d["id"]); $m = []; foreach ($zone->get_shipping_methods() as $x) { $m[] = $x->id . ":" . ($x->get_option("cost") ?: $x->get_option("min_amount")); } $z[$zone->get_zone_name()] = $m; }
  echo wp_json_encode(["city" => get_option("woocommerce_store_city"), "bacs" => get_option("woocommerce_bacs_settings")["title"] ?? "", "cod" => get_option("woocommerce_cod_settings")["enabled"] ?? "",
  "zones" => $z, "recipient" => get_option("woocommerce_new_order_settings")["recipient"] ?? "", "blog" => get_option("blogname"), "cart_page" => (int) get_option("woocommerce_cart_page_id")]);`));
const setup = (city, bacs, cod, zoneName, zoneCost, recipient, blog) => wp(`update_option("woocommerce_store_city", "${city}");
  $b = (array) get_option("woocommerce_bacs_settings", []); $b["title"] = "${bacs}"; $b["enabled"] = "yes"; update_option("woocommerce_bacs_settings", $b);
  $c = (array) get_option("woocommerce_cod_settings", []); $c["enabled"] = "${cod}"; $c["title"] = "Utánvét"; update_option("woocommerce_cod_settings", $c);
  foreach (WC_Shipping_Zones::get_zones() as $d) WC_Shipping_Zones::delete_zone($d["id"]);
  $z = new WC_Shipping_Zone(); $z->set_zone_name("${zoneName}"); $z->set_locations([["code" => "HU", "type" => "country"]]); $z->save();
  $i = $z->add_shipping_method("flat_rate"); update_option("woocommerce_flat_rate_" . $i . "_settings", ["title" => "Futár", "cost" => "${zoneCost}", "tax_status" => "taxable"]);
  if ("${zoneName}" === "Magyarország") { $j = $z->add_shipping_method("free_shipping"); update_option("woocommerce_free_shipping_" . $j . "_settings", ["title" => "Ingyenes", "requires" => "min_amount", "min_amount" => "25000"]); }
  $e = (array) get_option("woocommerce_new_order_settings", []); $e["recipient"] = "${recipient}"; update_option("woocommerce_new_order_settings", $e);
  update_option("blogname", "${blog}"); WC_Cache_Helper::get_transient_version("shipping", true);`);

const b = await chromium.launch();
const page = await (await b.newContext({ acceptDownloads: true })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const cart0 = state().cart_page;

// 1. „Régi bolt” állapot + export a konzolszkripttel
setup('Szeged', 'Banki átutalás (régi)', 'yes', 'Magyarország', '1990', 'bolt@example.com', 'Mandala Régi');
await page.goto(`${BASE}/wp-login.php`);
await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
await page.goto(`${BASE}/wp-admin/`, { waitUntil: 'networkidle' });
const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 90000 }), page.evaluate(SCRIPT)]);
const file = path.join(process.env.TMPDIR || '/tmp', 'mandala-regi-beallitasok.json');
await dl.saveAs(file);
const exp = JSON.parse(fs.readFileSync(file, 'utf8'));
ok(exp.v === 1 && exp.wc.general && exp.gateways.some((g) => g.id === 'bacs') && exp.zones.some((z) => z.name === 'Magyarország'), 'export: általános beállítások, fizetési módok, zónák', JSON.stringify({ groups: Object.keys(exp.wc), gw: exp.gateways.length, zones: exp.zones.map((z) => z.name), err: exp.errors }));
ok(Array.isArray(exp.plugins) && exp.plugins.length > 0 && exp.wp && exp.wp.title === 'Mandala Régi', 'export: bővítménylista és webhely-beállítások');

// 2. Az „új bolt” más beállításokkal
setup('Budapest', 'Átutalás', 'no', 'Egyéb', '999', 'x@example.com', 'Mandala');

// 3. Feltöltés, előnézet, átvétel
await page.goto(`${BASE}/wp-admin/admin.php?page=mandala-oldsettings`, { waitUntil: 'networkidle' });
ok((await page.textContent('#wpbody-content')).includes('allow pasting'), 'admin: útmutató és a másolható szkript');
await page.setInputFiles('input[name="file"]', file);
await Promise.all([page.waitForNavigation(), page.click('button[value="upload"]')]);
const prev = await page.textContent('#wpbody-content');
ok(prev.includes('Szeged') && prev.includes('Magyarország') && prev.includes('Banki átutalás (régi)') && prev.includes('bolt@example.com'), 'előnézet: a régi értékek láthatók');
await Promise.all([page.waitForNavigation(), page.click('button[value="apply"]')]);
const s = state();
ok(s.city === 'Szeged' && s.bacs === 'Banki átutalás (régi)' && s.cod === 'yes', 'átvétel: általános beállítás és fizetési módok', JSON.stringify(s));
ok(JSON.stringify(s.zones) === JSON.stringify({ 'Magyarország': ['flat_rate:1990', 'free_shipping:25000'] }), 'átvétel: szállítási zóna díjakkal', JSON.stringify(s.zones));
ok(s.recipient === 'bolt@example.com' && s.blog === 'Mandala Régi' && s.cart_page === cart0, 'átvétel: levélbeállítás és webhelynév; az oldalazonosítók maradnak');
ok(wp('echo (int) (bool) get_option("mandala_oldset_pending");') === '0', 'átvétel: a feltöltött fájl (fizetési kulcsok) nem marad az adatbázisban');

// 4. Visszavonás
page.once('dialog', (d) => d.accept());
await Promise.all([page.waitForNavigation(), page.click('button[value="undo"]')]);
const u = state();
ok(u.city === 'Budapest' && u.bacs === 'Átutalás' && u.cod === 'no' && JSON.stringify(u.zones) === JSON.stringify({ 'Egyéb': ['flat_rate:999'] }) && u.blog === 'Mandala', 'visszavonás: minden az átvétel előtti állapotban', JSON.stringify(u));

// 5. Élesítési állapot (REST, csak adminnak, titkok nélkül)
wp('$a = (array) get_option("mandala_analytics", []); $a["capi"] = "yes"; $a["capi_token"] = "TITKOS-CAPI-123"; update_option("mandala_analytics", $a);');
const anon = await fetch(`${BASE}/wp-json/mandala/v1/readiness`);
ok(anon.status === 401 || anon.status === 403, 'élesítési állapot: bejelentkezés nélkül nem olvasható', String(anon.status));
const rd = await page.evaluate(async (base) => {
  const nonce = (await (await fetch(base + '/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
  const r = await fetch(base + '/wp-json/mandala/v1/readiness', { headers: { 'X-WP-Nonce': nonce } });
  return { status: r.status, body: await r.text() };
}, BASE);
const rj = JSON.parse(rd.body);
ok(rd.status === 200 && rj.payments && rj.shipping && rj.products && rj.legal && rj.mail && rj.checks && rj.keys.meta_capi === true, 'élesítési állapot: fizetés, szállítás, termékek, jogi oldalak, levelek, ellenőrzések', rd.body.slice(0, 300));
ok(!rd.body.includes('TITKOS-CAPI-123'), 'élesítési állapot: titkot (kulcsot) nem ad ki');

ok(errors.length === 0, 'nincs JS hiba', errors.join(' | '));
await b.close();
fs.unlinkSync(file);
console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
