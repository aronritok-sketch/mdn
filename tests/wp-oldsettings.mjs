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
  "zones" => $z, "cod_for" => get_option("woocommerce_cod_settings")["enable_for_methods"] ?? [], "cod_title" => get_option("woocommerce_cod_settings")["title"] ?? "",
  "flat" => array_values(array_filter(array_map(fn($d) => implode(",", array_map(fn($x) => $x->id . ":" . $x->instance_id, (new WC_Shipping_Zone($d["id"]))->get_shipping_methods())), WC_Shipping_Zones::get_zones())))[0] ?? "",
  "cod_fee" => (int) (array_values(array_filter((array) get_option("mandala_payment", []), fn($p) => ($p["id"] ?? "") === "cod"))[0]["fee"] ?? -1),
  "terms" => get_post_field("post_content", (int) get_option("woocommerce_terms_page_id")), "card" => (int) (get_page_by_path("a-bankkartyas-fizetesrol")->ID ?? 0),
  "card_html" => get_post_field("post_content", (int) (get_page_by_path("a-bankkartyas-fizetesrol")->ID ?? 0)), "recipient" => get_option("woocommerce_new_order_settings")["recipient"] ?? "", "blog" => get_option("blogname"), "cart_page" => (int) get_option("woocommerce_cart_page_id")]);`));
const setup = (city, bacs, cod, zoneName, zoneCost, recipient, blog) => wp(`update_option("woocommerce_store_city", "${city}");
  $b = (array) get_option("woocommerce_bacs_settings", []); $b["title"] = "${bacs}"; $b["enabled"] = "yes"; update_option("woocommerce_bacs_settings", $b);
  $c = (array) get_option("woocommerce_cod_settings", []); $c["enabled"] = "${cod}"; $c["title"] = "Utánvét"; update_option("woocommerce_cod_settings", $c);
  foreach (WC_Shipping_Zones::get_zones() as $d) WC_Shipping_Zones::delete_zone($d["id"]);
  $z = new WC_Shipping_Zone(); $z->set_zone_name("${zoneName}"); $z->set_locations([["code" => "HU", "type" => "country"]]); $z->save();
  $i = $z->add_shipping_method("flat_rate"); update_option("woocommerce_flat_rate_" . $i . "_settings", ["title" => "Futár", "cost" => "${zoneCost}", "tax_status" => "taxable"]);
  $c = (array) get_option("woocommerce_cod_settings", []); $c["enable_for_methods"] = ["flat_rate:" . $i]; $c["title"] = "${cod}" === "yes" ? "Utánvét (390 Ft)" : "Utánvét"; update_option("woocommerce_cod_settings", $c);
  if ("${zoneName}" === "Magyarország") { $j = $z->add_shipping_method("free_shipping"); update_option("woocommerce_free_shipping_" . $j . "_settings", ["title" => "Ingyenes", "requires" => "min_amount", "min_amount" => "25000"]); }
  $e = (array) get_option("woocommerce_new_order_settings", []); $e["recipient"] = "${recipient}"; update_option("woocommerce_new_order_settings", $e);
  update_option("blogname", "${blog}"); WC_Cache_Helper::get_transient_version("shipping", true);`);

const b = await chromium.launch();
const page = await (await b.newContext({ acceptDownloads: true })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const cart0 = state().cart_page;

const legal = (terms, card) => wp(`$t = (int) get_option("woocommerce_terms_page_id"); wp_update_post(["ID" => $t, "post_content" => ${JSON.stringify(terms)}]);
  $c = get_page_by_path("a-bankkartyas-fizetesrol"); if ($c) wp_delete_post($c->ID, true);
  if (${JSON.stringify(card)} !== "") wp_insert_post(["post_type" => "page", "post_status" => "publish", "post_title" => "A bankkártyás fizetésről", "post_name" => "a-bankkartyas-fizetesrol", "post_content" => ${JSON.stringify(card)}]);`);
const terms1 = get0();
function get0() { return wp('echo get_post_field("post_content", (int) get_option("woocommerce_terms_page_id"));'); }

// 1. „Régi bolt” állapot + export a konzolszkripttel
setup('Szeged', 'Banki átutalás (régi)', 'yes', 'Magyarország', '1990', 'bolt@example.com', 'Mandala Régi');
legal('<div class="elementor elementor-12" data-elementor-type="wp-page"><section class="e-con" data-id="a1"><div class="e-con-inner"><h2 class="x">1. Általános rendelkezések</h2><script id="barat_script">var st = document.createElement("script");st.src = "//admin.fogyasztobarat.hu/e-api.js";st.type = "text/javascript";st.setAttribute("data-id", "TESZT123");st.setAttribute("id", "fbarat-embed");st.setAttribute("data-type", "aszf");var s = document.getElementById("barat_script");s.parentNode.insertBefore(st, s);</script><p style="color:red">Régi ÁSZF szövege.</p><script>alert(1)</script></div></section></div>', '<div class="elementor"><p>A kártyás fizetést a Teya biztosítja.</p></div>');
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

ok(exp.legal && /Régi ÁSZF/.test(exp.legal.terms?.html || '') && exp.legal.card?.slug === 'a-bankkartyas-fizetesrol' && exp.zones.every((z) => z.methods.every((m) => m.instance_id > 0)), 'export: jogi oldalak és a szállítási módok példányazonosítója', JSON.stringify(Object.keys(exp.legal || {})));

// 2. Az „új bolt” más beállításokkal
setup('Budapest', 'Átutalás', 'no', 'Egyéb', '999', 'x@example.com', 'Mandala');
legal('Új ÁSZF [kitöltendő]', '');
const before = state();

// 3. Feltöltés, előnézet, átvétel
await page.goto(`${BASE}/wp-admin/admin.php?page=mandala-oldsettings`, { waitUntil: 'networkidle' });
ok((await page.textContent('#wpbody-content')).includes('allow pasting'), 'admin: útmutató és a másolható szkript');
await page.setInputFiles('input[name="file"]', file);
await Promise.all([page.waitForNavigation(), page.click('button[value="upload"]')]);
const prev = await page.textContent('#wpbody-content');
ok(prev.includes('Szeged') && prev.includes('Magyarország') && prev.includes('Banki átutalás (régi)') && prev.includes('bolt@example.com'), 'előnézet: a régi értékek láthatók');
ok(prev.includes('Jogi oldalak') && prev.includes('A bankkártyás fizetésről'), 'előnézet: jogi oldalak átvétele');
await Promise.all([page.waitForNavigation(), page.click('button[value="apply"]')]);
const s = state();
ok(s.city === 'Szeged' && s.bacs === 'Banki átutalás (régi)' && s.cod === 'yes', 'átvétel: általános beállítás és fizetési módok', JSON.stringify(s));
ok(JSON.stringify(s.zones) === JSON.stringify({ 'Magyarország': ['flat_rate:1990', 'free_shipping:25000'] }), 'átvétel: szállítási zóna díjakkal', JSON.stringify(s.zones));
ok(s.recipient === 'bolt@example.com' && s.blog === 'Mandala Régi' && s.cart_page === cart0, 'átvétel: levélbeállítás és webhelynév; az oldalazonosítók maradnak');
ok(s.cod_for.length === 1 && s.flat.split(",").includes(s.cod_for[0]) && s.cod_for[0] !== before.cod_for[0], 'átvétel: az utánvét az ÚJ szállítási mód-példányra mutat (régi azonosító átfordítva)', JSON.stringify({ cod: s.cod_for, flat: s.flat, before: before.cod_for }));
ok(s.cod_fee === 390 && s.cod_title === 'Utánvét', 'átvétel: utánvét díja a régi címből (390 Ft), a címből kikerült', JSON.stringify({ fee: s.cod_fee, title: s.cod_title }));
ok(s.terms.includes('Régi ÁSZF szövege') && s.terms.includes('<h2>') && !/elementor|data-id|style=|<script|<div|<section/.test(s.terms), 'átvétel: ÁSZF a régi szöveggel, oldalépítő-jelölések és szkript nélkül', s.terms.slice(0, 200));
ok(s.card > 0 && s.card_html.includes('Teya'), 'átvétel: bankkártyás fizetési tájékoztató oldal létrehozva');
const termsHtml = await (await fetch(wp('echo get_permalink((int) get_option("woocommerce_terms_page_id"));'))).text();
ok(s.terms.includes('[mandala_fogyasztobarat ref="TESZT123" type="aszf"]') && termsHtml.includes('setAttribute("data-id","TESZT123")') && termsHtml.includes('Régi ÁSZF szövege'), 'átvétel: a Fogyasztóbarát ÁSZF-beágyazás megmarad és betöltődik az oldalon', s.terms.slice(0, 200));
ok(wp('echo (int) (bool) get_option("mandala_oldset_pending");') === '0', 'átvétel: a feltöltött fájl (fizetési kulcsok) nem marad az adatbázisban');

// 4. Visszavonás
page.once('dialog', (d) => d.accept());
await Promise.all([page.waitForNavigation(), page.click('button[value="undo"]')]);
const u = state();
ok(u.city === 'Budapest' && u.bacs === 'Átutalás' && u.cod === 'no' && JSON.stringify(u.zones) === JSON.stringify({ 'Egyéb': ['flat_rate:999'] }) && u.blog === 'Mandala', 'visszavonás: minden az átvétel előtti állapotban', JSON.stringify(u));
ok(u.terms === 'Új ÁSZF [kitöltendő]' && u.card === 0 && u.cod_fee === before.cod_fee && u.cod_title === 'Utánvét' && u.cod_for.length === 1 && u.flat.split(",").includes(u.cod_for[0]), 'visszavonás: jogi oldalak, utánvét díja és szállítási hivatkozás is vissza', JSON.stringify({ terms: u.terms, card: u.card, fee: u.cod_fee, cod: u.cod_for, flat: u.flat }));
wp(`wp_update_post(["ID" => (int) get_option("woocommerce_terms_page_id"), "post_content" => ${JSON.stringify(terms1)}]);`);

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
// Zónahiba: ország nélküli zóna a külföldi zóna előtt → minden címre az érvényes
const zw = JSON.parse(wp(`$a = new WC_Shipping_Zone(); $a->set_zone_name("Magyarország"); $a->set_zone_order(0); $a->save();
  $b = new WC_Shipping_Zone(); $b->set_zone_name("Szlovákia, Horvátország"); $b->set_zone_order(0); $b->set_locations([["code" => "SK", "type" => "country"], ["code" => "AT", "type" => "country"]]); $b->save();
  $w = mandala_shipping_zone_warnings(); WC_Shipping_Zones::delete_zone($a->get_id()); WC_Shipping_Zones::delete_zone($b->get_id()); echo wp_json_encode($w);`));
ok(zw.some((w) => w.includes('nincs ország rendelve') && w.includes('Szlovákia, Horvátország')) && zw.some((w) => w.includes('Horvátország, de')), 'élesítési állapot: figyelmeztet az ország nélküli (mindent elkapó) zónára és a hiányzó országra', JSON.stringify(zw));

ok(errors.length === 0, 'nincs JS hiba', errors.join(' | '));
await b.close();
fs.unlinkSync(file);
console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
