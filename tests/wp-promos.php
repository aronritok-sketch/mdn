<?php
/**
 * Kampány-ajánlatok (inc/features/promos.php) – integrációs teszt a helyi teszt-WordPressben (mintaadatokkal).
 * Futtatás: wp --path=<wp> eval-file tests/wp-promos.php
 * A saját kampányait és termék-módosításait a végén visszaállítja.
 */

if (!function_exists('mandala_promo_rules')) {
    fwrite(STDERR, "A promos.php nincs betöltve.\n");
    exit(1);
}
$fail = 0;
$ok = function (bool $cond, string $msg) use (&$fail) {
    echo ($cond ? '  ok  ' : '  HIBA ') . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
};
$saved = get_option('mandala_campaigns', []);
$restore = [];
$tz = wp_timezone_string();
$at = fn(string $rel) => wp_date('Y-m-d H:i', strtotime($rel));

// ---- tiszta függvény: 1+1 elosztás
echo "1+1 elosztás\n";
$ok(mandala_promo_bogo_split(['a' => [1, 3360], 'b' => [1, 2420]]) === ['b' => 1], 'két különböző: az olcsóbb ingyenes');
$ok(mandala_promo_bogo_split(['a' => [3, 3360]]) === ['a' => 1], 'három azonos: egy ingyenes');
$ok(mandala_promo_bogo_split(['a' => [2, 1000], 'b' => [1, 500], 'c' => [1, 800]]) == ['a' => 1, 'b' => 1], 'párok ár szerint csökkenő sorban (1000+1000, 800+500)');
$ok(mandala_promo_bogo_split(['a' => [1, 900]]) === [], 'egy darab: nincs ingyenes');

// ---- termékek a mintaadatokból
$pick = function (string $cat, int $n = 2) {
    return wc_get_products(['status' => 'publish', 'limit' => $n, 'category' => [$cat], 'type' => 'simple', 'stock_status' => 'instock', 'orderby' => 'ID', 'order' => 'ASC']);
};
$inc = $pick('fustolok', 3);
$bowl = $pick('hangtalak', 1)[0] ?? null;
$other = null;
foreach (wc_get_products(['status' => 'publish', 'limit' => 40, 'type' => 'simple', 'stock_status' => 'instock']) as $p) {
    $s = mandala_promo_cat_slugs($p->get_id());
    if (!array_intersect($s, ['fustolok', 'hangtalak']) && !mandala_is_voucher($p) && !$p->is_on_sale('edit') && (float) $p->get_regular_price('edit') > 0 && !$p->get_meta('_mandala_giftbox')) {
        $other = $p;
        break;
    }
}
$ok(count($inc) >= 2 && $bowl && $other, 'mintatermékek: ' . count($inc) . ' füstölő, hangtál, egyéb');
if (count($inc) < 2 || !$bowl || !$other) {
    exit(1);
}
foreach (array_merge($inc, [$bowl, $other]) as $p) {
    $restore[$p->get_id()] = ['sale' => $p->get_sale_price('edit'), 'log' => get_post_meta($p->get_id(), '_mandala_plog', true)];
    delete_post_meta($p->get_id(), '_mandala_plog');
    if ($p->get_sale_price('edit') !== '') {
        $p->set_sale_price('');
        $p->save();
    }
}

// ---- kampányok: Black Week (most fut) + Black Friday (most fut, ingyenes szállítás) + egy jövőbeli
$bw = ['name' => 'Teszt Black Week', 'start' => $at('-2 hours'), 'end' => $at('+2 days'), 'bar' => 'BW', 'eyebrow' => '', 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'coupon' => '', 'auto' => 'no', 'enabled' => 'yes',
    'rules' => ['bogo' => ['fustolok'], 'pct' => 20, 'pct_cats' => ['hangtalak' => 10]]];
$bf = ['name' => 'Teszt Black Friday', 'start' => $at('-1 hour'), 'end' => $at('+1 day'), 'bar' => 'BF', 'eyebrow' => '', 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'coupon' => '', 'auto' => 'no', 'enabled' => 'yes',
    'rules' => ['freeship' => 'yes', 'wrap' => 'yes', 'gwp' => ['min' => 15000, 'sku' => $inc[0]->get_sku(), 'label' => 'ajándék füstölő']]];
$later = ['name' => 'Teszt később', 'start' => $at('+10 days'), 'end' => $at('+12 days'), 'bar' => 'X', 'eyebrow' => '', 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'coupon' => '', 'auto' => 'no', 'enabled' => 'yes', 'rules' => ['pct' => 50]];
update_option('mandala_campaigns', [$bf, $bw, $later], false);

echo "Szabályok\n";
$r = mandala_promo_rules();
$ok($r['pct'] == 20 && $r['pct_cats'] == ['hangtalak' => 10] && $r['bogo'] === ['fustolok'], 'a Black Week százaléka és 1+1-e él');
$ok($r['freeship'] && $r['wrap'] && $r['gwp']['min'] === 15000, 'a Black Friday ingyenes szállítása, csomagolása, ajándéka is (összeadódnak)');
$ok(mandala_active_campaign()['name'] === 'Teszt Black Friday', 'a felső sávot az első aktív kampány adja');
$ok(count(mandala_promo_windows()) === 3 || count(mandala_promo_windows()) === 2, 'a böngésző időablakai (60 napon belül, ár-szabállyal): ' . count(mandala_promo_windows()));

echo "Százalék és ár\n";
$ok(mandala_promo_pct($bowl) == 10, 'hangtál: 10%');
$ok(mandala_promo_pct($other) == 20, 'egyéb: 20%');
$ok(mandala_promo_pct($inc[0]) == 0 && mandala_promo_in_bogo($inc[0]), 'füstölő: 1+1, százalék nélkül');
$o = wc_get_product($other->get_id());
$reg = (float) $o->get_regular_price('edit');
$ok(abs((float) $o->get_price() - round($reg * 0.8)) < 0.01 && $o->is_on_sale(), 'egyéb termék ára a megjelenítésben −20% (' . $o->get_price() . ' / ' . $reg . ') és akciósnak látszik');
$ok((float) $o->get_regular_price() == $reg, 'a rendes ár (áthúzva) változatlan');
$ok((float) $o->get_price('edit') == $reg, 'az adatbázisban (edit) nincs kampányár');

// már akciós termék: nem kap százalékot
$o->set_sale_price((string) round($reg * 0.9));
$o->save();
$o2 = wc_get_product($other->get_id());
$ok(mandala_promo_pct($o2) == 0 && (float) $o2->get_price() == round($reg * 0.9), 'a már akciós termék a saját akciós árán marad');
$o2->set_sale_price('');
$o2->save();

// 30 napos szabály: a kampány kezdete előtt 5 nappal olcsóbb volt → nem kap százalékot
$start = (int) strtotime($bw['start'] . ' ' . $tz);
update_post_meta($other->get_id(), '_mandala_plog', [wp_date('Y-m-d', $start - 5 * DAY_IN_SECONDS) => $reg - 100]);
$o3 = wc_get_product($other->get_id());
$ok(mandala_promo_pct($o3) == 0 && (float) $o3->get_price() == $reg, 'az elmúlt 30 napban olcsóbb volt: nincs százalék');
// … a kampány alatt naplózott kampányár viszont nem zárja ki (a kampány kezdete előtti 30 nap számít)
update_post_meta($other->get_id(), '_mandala_plog', [wp_date('Y-m-d', $start) => round($reg * 0.8), wp_date('Y-m-d') => round($reg * 0.8)]);
$ok(mandala_promo_pct(wc_get_product($other->get_id())) == 20, 'a kampány alatti napló (kampányár) nem zárja ki a terméket');
delete_post_meta($other->get_id(), '_mandala_plog');

echo "Kosár\n";
WC()->frontend_includes();
WC()->session = new WC_Session_Handler();
WC()->session->init();
WC()->customer = new WC_Customer(0, true);
WC()->cart = new WC_Cart();
$cart = WC()->cart;
$cart->empty_cart();
$cart->add_to_cart($inc[0]->get_id(), 1);
$cart->add_to_cart($inc[1]->get_id(), 1);
$cart->calculate_totals();
$p0 = (float) $inc[0]->get_price('edit');
$p1 = (float) $inc[1]->get_price('edit');
$expect = max($p0, $p1);
$ok(abs((float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() - $expect) < 1.01, 'két füstölő: csak a drágább fizetendő (' . ($cart->get_subtotal() + $cart->get_subtotal_tax()) . ' / ' . $expect . ')');
$cart->add_to_cart($inc[0]->get_id(), 1); // 2 + 1
$cart->calculate_totals();
$units = [$p0, $p0, $p1];
rsort($units);
$ok(abs((float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() - ($units[0] + $units[2])) < 1.01, 'három füstölő: egy (a pár olcsóbbja) ingyenes');
$cart->empty_cart();
$cart->add_to_cart($bowl->get_id(), 1);
$cart->add_to_cart($other->get_id(), 1);
$cart->calculate_totals();
$want = round((float) $bowl->get_regular_price('edit') * 0.9) + round($reg * 0.8);
$ok(abs((float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() - $want) < 1.01, 'hangtál −10% + egyéb −20% a kosárban (' . ($cart->get_subtotal() + $cart->get_subtotal_tax()) . ' / ' . $want . ')');

// kupon: a kampány-kedvezményes tételre nem érvényes
$cp = new WC_Coupon();
$cp->set_code('promoteszt10');
$cp->set_discount_type('percent');
$cp->set_amount(10);
$cp->save();
$ok(!$cp->is_valid_for_product(wc_get_product($other->get_id())), 'százalékos kupon a kampány-kedvezményes tételre nem érvényes');
$cp->delete(true);

echo "Szállítás, ajándék, viszonteladó\n";
$ok((int) mandala_config('freeShippingFrom', 25000) === 1, 'ingyenes szállítás: a határ 1 Ft');
$g = mandala_growth_settings();
$ok($g['gwp'] === 'yes' && (int) $g['gwp_threshold'] === 15000 && $g['gwp_sku'] === $inc[0]->get_sku(), 'ajándék értékhatár felett a kampány idejére');
$uid = wp_insert_user(['user_login' => 'promoteszt_nagyker', 'user_pass' => wp_generate_password(), 'user_email' => 'promoteszt@example.com', 'role' => 'wholesale_customer']);
if (!is_wp_error($uid)) {
    wp_set_current_user($uid);
    $ok(mandala_is_wholesale_user() && mandala_promo_user_rules()['names'] === [], 'viszonteladónak nincs kampány');
    $ok(mandala_promo_price_for(wc_get_product($other->get_id())) === null, 'viszonteladónak nincs kampányár');
    wp_set_current_user(0);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($uid);
} else {
    $ok(false, 'viszonteladó teszt-felhasználó: ' . $uid->get_error_message());
}

echo "Index és feed\n";
$row = mandala_index_full_row(wc_get_product($other->get_id()));
$ok(in_array('ajandektargyak', $row['pc'], true) || is_array($row['pc']), 'index: kategória-lánc (pc)');
$ok($row['pok'] === 1, 'index: százalékra jogosult (pok)');
$ok($row['price'] == $reg && empty($row['compare']), 'index: a nyers ár (a böngésző számol)');
$ok(abs((float) mandala_promo_price_for(wc_get_product($other->get_id()), mandala_promo_rules()) - round($reg * 0.8)) < 0.01, 'feed: a bolti szabályokkal kampányár');

echo "Lejárt kampány\n";
update_option('mandala_campaigns', [$later], false);
$o4 = wc_get_product($other->get_id());
$ok((float) $o4->get_price() == $reg && !$o4->is_on_sale(), 'a kampány után a rendes ár');
$ok((int) mandala_config('freeShippingFrom', 25000) !== 1, 'a kampány után a szállítási határ a régi');

echo "Seed (sitefix)\n";
$seed = mandala_xmas2026_campaigns();
$ok(count($seed) === 6 && $seed[0]['name'] === 'Black Friday', '6 kampány, elöl a Black Friday');
$bwk = array_values(array_filter($seed, fn($c) => $c['name'] === 'Black Week'))[0];
$ok(mandala_promo_clean_rules($bwk['rules'])['pct'] == 20 && mandala_promo_clean_rules($bwk['rules'])['pct_cats'] == ['hangtalak' => 10], 'Black Week: 20%, hangtál 10%');
update_option('mandala_campaigns', $seed, false);
$mid = strtotime('2026-11-27 12:00 ' . $tz);
$rr = mandala_promo_rules($mid);
$ok($rr['pct'] == 20 && $rr['freeship'] && $rr['bogo'] === ['fustolok'], 'nov. 27. délben: 20% + ingyenes szállítás + 1+1');
$ok(mandala_promo_rules(strtotime('2026-11-14 12:00 ' . $tz))['bogo'] === ['fustolok'] && mandala_promo_rules(strtotime('2026-11-14 12:00 ' . $tz))['pct'] == 0, 'nov. 14.: csak 1+1');
$dec = mandala_promo_rules(strtotime('2026-12-10 12:00 ' . $tz));
$ok($dec['pct'] == 0 && $dec['wrap'] && $dec['gwp']['sku'] === '8904234400037', 'dec. 10.: nincs százalék, csomagolás + ajándék füstölő');
$ok(mandala_promo_rules(strtotime('2026-12-24 13:00 ' . $tz))['names'] === [], 'dec. 24. délután: semmi');

// ---- visszaállítás
update_option('mandala_campaigns', $saved, false);
foreach ($restore as $id => $x) {
    $p = wc_get_product($id);
    if ($p && $p->get_sale_price('edit') !== $x['sale']) {
        $p->set_sale_price($x['sale']);
        $p->save();
    }
    is_array($x['log']) ? update_post_meta($id, '_mandala_plog', $x['log']) : delete_post_meta($id, '_mandala_plog');
}
echo $fail ? "\n$fail HIBA\n" : "\nMinden rendben.\n";
exit($fail ? 1 : 0);
