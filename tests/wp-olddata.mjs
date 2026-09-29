// Régi bolt ADATAINAK átvétele: „régi bolt” adatok (vásárló, munkatárs, rendelések jegyzettel és visszatérítéssel,
// kupon, értékelés, blogcikk képpel, oldal, YITH ajándékkártya) → export a konzolszkripttel (valódi REST +
// WordPress-export) → a bolt kiürítése → átvétel a böngészőből → ellenőrzés → újrafuttatás → törlés.
// Futtatás: BASE=http://localhost:8080 WP="wp --path=…" node tests/wp-olddata.mjs
import { createRequire } from 'module';
import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const BASE = process.env.BASE || 'http://localhost:8080';
const WP = process.env.WP || 'wp';
const wp = (php) => execSync(`${WP} eval '${php.replace(/'/g, "'\\''")}' 2>/dev/null`, { encoding: 'utf8', maxBuffer: 64 << 20 }).trim();
let fails = 0;
const ok = (c, name, extra = '') => { console.log(`${c ? '✓' : '✗'} ${name}${!c && extra ? ' – ' + extra : ''}`); if (!c) fails++; };
const SCRIPT = fs.readFileSync(new URL('../wp-theme/mandala/inc/features/olddata-export.js', import.meta.url), 'utf8');
const MAILLOG = wp('echo WP_CONTENT_DIR;') + '/mail.log';
const mailLen = () => (fs.existsSync(MAILLOG) ? fs.statSync(MAILLOG).size : 0);
// A YITH ajándékkártya-típus helyettesítője + a helyi képletöltés engedélyezése (a tesztbolt „régi boltja” is localhost)
const MU = wp('echo WPMU_PLUGIN_DIR;') + '/zz-test-olddata.php';
fs.writeFileSync(MU, `<?php
add_action('init', fn() => register_post_type('gift_card', ['public' => false, 'can_export' => true, 'label' => 'Gift cards']));
add_filter('http_request_host_is_external', '__return_true');
add_filter('http_request_args', function ($a) { $a['reject_unsafe_urls'] = false; return $a; });
`);

// 1. „Régi bolt” adatai
const old = JSON.parse(wp(`
  $p = wc_get_products(["limit" => 1, "status" => "publish", "type" => "simple", "orderby" => "ID", "order" => "ASC"])[0];
  if (!$p->get_sku()) { $p->set_sku("REGI-TESZT-1"); }
  $p->set_manage_stock(true); $p->set_stock_quantity(50); $p->save();
  $u = wp_insert_user(["user_login" => "regivevo", "user_email" => "regi.vevo@example.com", "user_pass" => "regi-jelszo", "role" => "customer", "first_name" => "Réka", "last_name" => "Régi"]);
  $u2 = wp_insert_user(["user_login" => "regimasik", "user_email" => "regi2@example.com", "user_pass" => "masik-jelszo", "role" => "customer"]);
  update_user_meta($u, "billing_city", "Pécs"); update_user_meta($u, "billing_phone", "+36301112233"); update_user_meta($u, "billing_email", "regi.vevo@example.com");
  $m = wp_insert_user(["user_login" => "regimunkatars", "user_email" => "munkatars@example.com", "user_pass" => "x-Munka-1", "role" => "shop_manager"]);
  $o = wc_create_order(["customer_id" => $u]);
  $o->add_product($p, 2);
  $o->set_address(["first_name" => "Réka", "last_name" => "Régi", "email" => "regi.vevo@example.com", "city" => "Pécs", "postcode" => "7621", "address_1" => "Király u. 1.", "country" => "HU", "phone" => "+36301112233"], "billing");
  $s = new WC_Order_Item_Shipping(); $s->set_props(["method_title" => "GLS házhozszállítás", "method_id" => "flat_rate", "total" => "1116"]); $o->add_item($s);
  $f = new WC_Order_Item_Fee(); $f->set_props(["name" => "Utánvét díja", "total" => "307", "tax_status" => "none"]); $o->add_item($f);
  $o->set_payment_method("cod"); $o->set_payment_method_title("Utánvét"); $o->set_customer_note("Kérem délután hozzák.");
  $o->calculate_totals(false); $o->set_date_created(strtotime("2025-03-14 10:20:00 UTC")); $o->set_status("completed"); $o->save();
  $o->add_order_note("Csomag feladva: GLS 123", 0); $o->add_order_note("Kedves Réka, úton a csomag.", 1);
  wc_create_refund(["order_id" => $o->get_id(), "amount" => 500, "reason" => "Sérült doboz"]);
  $g = wc_create_order(); $it = new WC_Order_Item_Product(); $it->set_props(["name" => "Régi megszűnt termék", "quantity" => 1, "subtotal" => "1000", "total" => "1000"]); $g->add_item($it);
  $g->set_address(["first_name" => "Vendég", "last_name" => "Vevő", "email" => "vendeg@example.com", "country" => "HU"], "billing"); $g->calculate_totals(false); $g->set_status("processing"); $g->save();
  $c = new WC_Coupon(); $c->set_code("REGIKUPON"); $c->set_amount(10); $c->set_discount_type("percent"); $c->set_product_ids([$p->get_id()]); $c->set_usage_count(3); $c->save();
  $rid = wp_insert_comment(["comment_post_ID" => $p->get_id(), "comment_type" => "review", "comment_approved" => 1, "comment_author" => "Teszt Elek", "comment_author_email" => "elek@example.com", "comment_content" => "Nagyon szép, gyorsan megjött.", "comment_meta" => ["rating" => 5, "verified" => 1]]);
  require_once ABSPATH . "wp-admin/includes/file.php"; require_once ABSPATH . "wp-admin/includes/media.php"; require_once ABSPATH . "wp-admin/includes/image.php";
  $att = media_sideload_image(home_url("/wp-content/themes/mandala/assets/img/hangfurdo-800.webp"), 0, null, "id");
  $url = wp_get_attachment_url($att);
  $cat = wp_insert_term("Régi hírek", "category", ["slug" => "regi-hirek"])["term_id"];
  $post = wp_insert_post(["post_title" => "Régi blogcikk a hangtálakról", "post_name" => "regi-blogcikk-hangtalak", "post_status" => "publish", "post_category" => [$cat],
    "post_content" => "<!-- wp:paragraph --><p>Bevezető a hangtálakhoz.</p><!-- /wp:paragraph --><!-- wp:image {\\"id\\":$att} --><figure class=\\"wp-block-image\\"><img src=\\"$url\\" class=\\"wp-image-$att\\"/></figure><!-- /wp:image -->"]);
  set_post_thumbnail($post, $att);
  $page = wp_insert_post(["post_type" => "page", "post_title" => "Régi rólunk oldal", "post_name" => "regi-rolunk", "post_status" => "publish", "post_content" => "<div class=\\"elementor\\"><p>Ez a régi bolt bemutatkozó oldala, hosszabb szöveggel a vásárlóknak.</p></div>"]);
  $gc1 = wp_insert_post(["post_type" => "gift_card", "post_status" => "publish", "post_title" => "ABCD-1234-EFGH-5678", "meta_input" => ["_ywgc_amount_total" => 10000, "_ywgc_balance_total" => 6500, "_ywgc_expiration" => time() + 200 * DAY_IN_SECONDS, "_ywgc_recipient" => "kapja@example.com"]]);
  $gc2 = wp_insert_post(["post_type" => "gift_card", "post_status" => "publish", "post_title" => "ELHASZ-NALT-0000", "meta_input" => ["_ywgc_amount_total" => 5000, "_ywgc_balance_total" => 0]]);
  echo wp_json_encode(["p" => $p->get_id(), "sku" => $p->get_sku(), "u" => $u, "u2" => $u2, "m" => $m, "o" => $o->get_id(), "total" => $o->get_total(), "g" => $g->get_id(), "c" => $c->get_id(), "rid" => $rid,
    "att" => $att, "url" => $url, "cat" => $cat, "post" => $post, "page" => $page, "gc" => [$gc1, $gc2]]);`));
ok(old.o > 0 && old.att > 0 && old.post > 0, '„régi bolt” adatok létrehozva', JSON.stringify(old));

// 2. Export a konzolszkripttel – a régi boltban a „Mandala költöztető segéd” is aktív (jelszó-lenyomatok)
const PLUG = wp('echo WP_PLUGIN_DIR;') + '/mandala-koltozes';
if (!fs.existsSync(PLUG)) fs.symlinkSync(new URL('../wp-plugin/mandala-koltozes', import.meta.url).pathname, PLUG);
execSync(`${WP} plugin activate mandala-koltozes 2>/dev/null`);
const anonHash = await fetch(`${BASE}/wp-json/mandala-migrate/v1/users`);
ok(anonHash.status === 401 || anonHash.status === 403, 'költöztető segéd: bejelentkezés nélkül nem adja ki a lenyomatokat', String(anonHash.status));
const b = await chromium.launch();
const page = await (await b.newContext({ acceptDownloads: true })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await page.goto(`${BASE}/wp-login.php`);
await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
await page.goto(`${BASE}/wp-admin/`, { waitUntil: 'networkidle' });
const [dl] = await Promise.all([page.waitForEvent('download', { timeout: Number(process.env.TIMEOUT || 240000) }), page.evaluate(SCRIPT)]);
const file = path.join(process.env.TMPDIR || '/tmp', 'mandala-regi-adatok.json');
await dl.saveAs(file);
const exp = JSON.parse(fs.readFileSync(file, 'utf8'));
const eo = exp.orders.find((o) => o.id === old.o);
const c1 = exp.customers.find((c) => c.id === old.u), c2 = exp.customers.find((c) => c.id === old.u2);
ok(/^\$/.test(c1?.pass_hash || '') && /^\$/.test(c2?.pass_hash || '') && !exp.customers.some((c) => c.role === 'administrator' && c.pass_hash && !c.email), 'export: a jelszavak lenyomata (segéd bővítménnyel)');
// A második vásárlónál „nincs lenyomat” (mintha a segéd nélkül exportáltak volna) – az újrafuttatás pótolja
const hash2 = c2.pass_hash;
delete c2.pass_hash;
fs.writeFileSync(file, JSON.stringify(exp));
ok(exp.kind === 'mandala-old-data' && exp.customers.some((c) => c.email === 'regi.vevo@example.com') && eo && eo.notes.length >= 2 && eo.refund_details?.length === 1, 'export: vásárlók, rendelések jegyzetekkel és visszatérítéssel', JSON.stringify({ n: exp.orders.length, notes: eo?.notes?.length, ref: eo?.refund_details, err: exp.errors }));
ok(exp.coupons.some((c) => c.code === 'regikupon') && exp.reviews.some((r) => r.reviewer === 'Teszt Elek') && exp.products[old.p]?.sku === old.sku, 'export: kupon, értékelés, termék-cikkszám');
ok(exp.posts.some((p) => p.slug === 'regi-blogcikk-hangtalak' && p.featured?.url) && exp.pages.some((p) => p.slug === 'regi-rolunk'), 'export: blog kiemelt képpel, oldalak');
ok(Array.isArray(exp.gift_cards) && exp.gift_cards.length === 2 && exp.gift_cards.some((c) => c.code === 'ABCD-1234-EFGH-5678' && c.meta._ywgc_balance_total === '6500'), 'export: YITH ajándékkártyák a WordPress-exportból', JSON.stringify(exp.gift_cards));

// 3. Az „új bolt”: nincs rendelés, a régi vásárló / kupon / értékelés / blog / oldal / kártya nincs meg
wp(`require_once ABSPATH . "wp-admin/includes/user.php";
  foreach (wc_get_orders(["limit" => -1, "return" => "ids", "type" => "shop_order", "status" => array_keys(wc_get_order_statuses())]) as $id) { $x = wc_get_order($id); foreach ($x->get_refunds() as $r) { $r->delete(true); } $x->delete(true); }
  wp_delete_user(${old.u}); wp_delete_user(${old.u2}); wp_delete_user(${old.m}); wp_delete_post(${old.c}, true); wp_delete_comment(${old.rid}, true); wp_delete_post(${old.post}, true); wp_delete_post(${old.page}, true);
  wp_delete_term(${old.cat}, "category"); wp_delete_post(${old.gc[0]}, true); wp_delete_post(${old.gc[1]}, true);`);
const stock0 = wp(`echo wc_get_product(${old.p})->get_stock_quantity();`);
const mail0 = mailLen();

// 4. Átvétel
const state = () => JSON.parse(wp(`
  $u = get_user_by("email", "regi.vevo@example.com");
  $ids = wc_get_orders(["limit" => -1, "return" => "ids", "type" => "shop_order", "status" => array_keys(wc_get_order_statuses())]);
  $find = function ($old) use ($ids) { foreach ($ids as $id) { $x = wc_get_order($id); if ((int) $x->get_meta("_mandala_old_id") === $old) return $x; } return null; };
  $o = $find(${old.o}); $g = $find(${old.g});
  $notes = $o ? array_map(fn($n) => [$n->content, $n->customer_note], wc_get_order_notes(["order_id" => $o->get_id()])) : [];
  $cid = wc_get_coupon_id_by_code("regikupon"); $cp = $cid ? new WC_Coupon($cid) : null;
  $rv = get_posts(["post_type" => "mandala_review", "post_status" => "any", "meta_key" => "_mandala_old_id", "meta_value" => ${old.rid}]);
  $post = get_page_by_path("regi-blogcikk-hangtalak", OBJECT, "post"); $pg = get_page_by_path("regi-rolunk");
  $v = function_exists("mandala_voucher_find") ? mandala_voucher_find("ABCD-1234-EFGH-5678") : null;
  echo wp_json_encode(["orders" => count($ids), "user" => $u ? ["id" => $u->ID, "city" => get_user_meta($u->ID, "billing_city", true), "pw" => (int) get_user_meta($u->ID, "_mandala_needs_pw", true), "role" => $u->roles[0] ?? ""] : null,
    "staff" => (bool) get_user_by("email", "munkatars@example.com"),
    "o" => $o ? ["id" => $o->get_id(), "number" => $o->get_order_number(), "status" => $o->get_status(), "total" => $o->get_total(), "refunded" => $o->get_total_refunded(), "customer" => $o->get_customer_id(),
      "created" => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0, "note" => $o->get_customer_note(), "items" => array_values(array_map(fn($i) => [$i->get_name(), $i->get_product_id(), $i->get_quantity()], $o->get_items())),
      "ship" => $o->get_shipping_total(), "fees" => count($o->get_fees()), "city" => $o->get_billing_city(), "notes" => $notes] : null,
    "g" => $g ? ["status" => $g->get_status(), "items" => array_values(array_map(fn($i) => [$i->get_name(), $i->get_product_id()], $g->get_items())), "customer" => $g->get_customer_id()] : null,
    "coupon" => $cp ? ["products" => $cp->get_product_ids(), "usage" => $cp->get_usage_count()] : null,
    "review" => $rv ? ["status" => $rv[0]->post_status, "rating" => (int) get_post_meta($rv[0]->ID, "_rating", true), "product" => (int) get_post_meta($rv[0]->ID, "_product", true)] : null,
    "post" => $post ? ["status" => $post->post_status, "cats" => wp_get_post_categories($post->ID, ["fields" => "slugs"]), "thumb" => (int) get_post_thumbnail_id($post->ID), "content" => $post->post_content] : null,
    "page" => $pg ? $pg->post_status : null,
    "voucher" => $v ? ["balance" => (int) get_post_meta($v->ID, "_balance", true), "value" => (int) get_post_meta($v->ID, "_value", true), "expires" => get_post_meta($v->ID, "_expires", true)] : null,
    "voucher0" => function_exists("mandala_voucher_find") && (bool) mandala_voucher_find("ELHASZ-NALT-0000"),
    "stock" => wc_get_product(${old.p})->get_stock_quantity()]);`));
const runImport = async (f) => {
  await page.goto(`${BASE}/wp-admin/admin.php?page=mandala-olddata`, { waitUntil: 'networkidle' });
  await page.setInputFiles('#mod-file', f);
  await page.waitForSelector('#mod-start:not([hidden])');
  const prev = await page.textContent('#mod-preview');
  await page.click('#mod-start');
  await page.waitForFunction(() => /Kész|Megszakadt/.test(document.getElementById('mod-status')?.textContent || ''), null, { timeout: Number(process.env.TIMEOUT || 300000) });
  return { prev, status: await page.textContent('#mod-status'), log: await page.textContent('#mod-log') };
};
const r1 = await runImport(file);
ok(r1.prev.includes('Rendelések') && r1.prev.includes('munkatársak (') && r1.prev.includes('Ajándékkártyák'), 'előnézet: darabszámok, a munkatársak kimaradnak', r1.prev.slice(0, 300));
ok(r1.status.includes('Kész'), 'átvétel lefutott', r1.status + ' | ' + r1.log);
const s = state();
ok(s.orders === exp.orders.length, 'rendelések: mind átjött', `${s.orders} / ${exp.orders.length}`);
ok(s.user && s.user.city === 'Pécs' && s.user.pw === 0 && s.user.role === 'customer' && !s.staff, 'vásárló: címadatokkal; a munkatárs nem jött át', JSON.stringify({ u: s.user, staff: s.staff }));
const signon = (login, pass) => wp(`$r = wp_signon(["user_login" => "${login}", "user_password" => "${pass}"], false); echo is_wp_error($r) ? $r->get_error_message() : "ok";`);
ok(signon('regi.vevo@example.com', 'regi-jelszo') === 'ok', 'vásárló: a RÉGI jelszavával be tud lépni az új boltba');
ok(s.o && s.o.number === String(old.o) && s.o.id !== old.o && s.o.status === 'completed' && s.o.total === old.total && s.o.customer === s.user?.id, 'rendelés: régi rendelésszám, állapot, végösszeg, vásárlóhoz kötve', JSON.stringify(s.o));
ok(s.o && s.o.items.length === 1 && s.o.items[0][1] === old.p && s.o.items[0][2] === 2 && Number(s.o.ship) === 1116 && s.o.fees === 1 && s.o.city === 'Pécs', 'rendelés: tétel a termékhez kötve (cikkszám), szállítás, díj, cím', JSON.stringify({ items: s.o?.items, ship: s.o?.ship, fees: s.o?.fees, city: s.o?.city, p: old.p }));
ok(s.o && s.o.created === Math.floor(Date.parse('2025-03-14T10:20:00Z') / 1000) && s.o.note === 'Kérem délután hozzák.' && Number(s.o.refunded) === 500, 'rendelés: eredeti dátum, vásárlói megjegyzés, visszatérítés');
ok(s.o && s.o.notes.length === eo.notes.length && s.o.notes.some((n) => n[0].includes('GLS 123') && !n[1]) && s.o.notes.some((n) => n[0].includes('úton a csomag') && n[1]), 'rendelés: a régi jegyzetek mind (vásárlói jelöléssel), az átvétel nem tett hozzá újat', JSON.stringify({ now: s.o?.notes?.length, old: eo.notes.length }));
ok(s.g && s.g.status === 'processing' && s.g.items[0][0] === 'Régi megszűnt termék' && s.g.items[0][1] === 0 && s.g.customer === 0, 'vendégrendelés: megszűnt termék névvel megmarad');
ok(mailLen() === mail0, 'átvétel közben egy levél sem ment ki', `${mailLen() - mail0} bájt új levél`);
ok(s.stock === Number(stock0), 'készlet nem változott', `${stock0} → ${s.stock}`);
ok(s.coupon && s.coupon.products.includes(old.p) && s.coupon.usage === 3, 'kupon: termékkorlát (cikkszám szerint), használatszám', JSON.stringify(s.coupon));
ok(s.review && s.review.status === 'publish' && s.review.rating === 5 && s.review.product === old.p, 'értékelés: közzétéve, 5 csillag, a termékhez kötve', JSON.stringify(s.review));
ok(s.post && s.post.status === 'publish' && s.post.cats.includes('regi-hirek') && s.post.thumb > 0 && s.post.thumb !== old.att, 'blog: közzétéve, kategóriával, saját (letöltött) kiemelt képpel', JSON.stringify({ ...s.post, content: undefined }));
ok(s.post && !s.post.content.includes(old.url) && /wp-content\/uploads\/[^"]+\.webp/.test(s.post.content) && !s.post.content.includes(`wp-image-${old.att}`) && !s.post.content.includes(`"id":${old.att}`), 'blog: a beágyazott kép letöltve, a hivatkozás átírva', s.post?.content);
ok(s.page === 'draft', 'hiányzó oldal: tervezetként', String(s.page));
ok(s.voucher && s.voucher.balance === 6500 && s.voucher.value === 10000 && /^\d{4}-\d\d-\d\d$/.test(s.voucher.expires) && !s.voucher0, 'ajándékkártya: egyenleggel, lejárattal; az elhasznált nem jön át', JSON.stringify(s.voucher));
const login = signon('regi2@example.com', 'masik-jelszo');
ok(login.includes('Új webáruházba költöztünk') && login.includes('jelszót'), 'lenyomat nélkül átvett vásárló: magyarázat + új jelszó kérése', login);

// 5. Újrafuttatás (az élesítés napján): nincs duplikáció; állapot és egyenleg frissül, levél nélkül
const exp2 = JSON.parse(fs.readFileSync(file, 'utf8'));
exp2.customers.find((c) => c.id === old.u2).pass_hash = hash2;
exp2.orders.find((o) => o.id === old.g).status = 'completed';
exp2.gift_cards.find((c) => c.code === 'ABCD-1234-EFGH-5678').meta._ywgc_balance_total = '4000';
const file2 = file.replace('.json', '-2.json');
fs.writeFileSync(file2, JSON.stringify(exp2));
const r2 = await runImport(file2);
const s2 = state();
ok(r2.status.includes('Kész') && s2.orders === exp.orders.length && /Rendelések: 0 átvéve/.test(r2.log), 'újrafuttatás: nincs duplikáció', r2.log.slice(0, 400));
ok(signon('regi2@example.com', 'masik-jelszo') === 'ok', 'újrafuttatás a segéddel: a korábban jelszó nélkül átvett vásárló is a régi jelszavával lép be');
ok(s2.g?.status === 'completed' && s2.voucher?.balance === 4000 && mailLen() === mail0, 'újrafuttatás: az állapot és a kártyaegyenleg frissül, levél nélkül', JSON.stringify({ g: s2.g?.status, v: s2.voucher, mail: mailLen() - mail0 }));

// 6. Törlés
page.once('dialog', (d) => d.accept());
await page.click('#mod-undo');
await page.waitForFunction(() => /törölve|Hiba/.test(document.getElementById('mod-status')?.textContent || ''), null, { timeout: Number(process.env.TIMEOUT || 300000) });
const s3 = state();
ok(s3.orders === 0 && !s3.user && !s3.coupon && !s3.review && !s3.post && !s3.page && !s3.voucher, 'törlés: az átvett adatok eltűntek', JSON.stringify({ o: s3.orders, u: s3.user, c: s3.coupon, p: !!s3.post, v: s3.voucher }));
ok(wp('echo (int) (bool) get_user_by("email", "b2b@example.com");') === '1' && wp('echo (int) term_exists("regi-hirek", "category");') === '0', 'törlés: a korábban is itt lévő felhasználó marad, az átvett kategória törlődik');

ok(errors.length === 0, 'nincs JS hiba', errors.join(' | '));
await b.close();
wp(`wp_delete_attachment(${old.att}, true);`);
fs.unlinkSync(MU);
execSync(`${WP} plugin deactivate mandala-koltozes 2>/dev/null`);
if (fs.lstatSync(PLUG).isSymbolicLink()) fs.unlinkSync(PLUG);
fs.unlinkSync(file);
fs.unlinkSync(file2);
console.log(`\n${fails ? fails + ' HIBA' : 'Minden rendben.'}`);
process.exit(fails ? 1 : 0);
