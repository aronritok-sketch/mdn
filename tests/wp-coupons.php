<?php
/**
 * Saját kuponok (inc/features/growth.php): a félbemaradt, ki nem fizetett rendelés ne égesse el az egyszer használható,
 * e-mail-címhez kötött kódot (Áron, 2026-11-07: „Nem működnek a kuponkódok”).
 * Futtatás: wp --path=<wp WooCommerce-szel> eval-file tests/wp-coupons.php
 * A létrehozott terméket, kuponokat és rendeléseket a végén törli.
 */

if (!function_exists('mandala_coupon_unpaid_uses') || !function_exists('mandala_growth_coupon')) {
    fwrite(STDERR, "A growth.php nincs betöltve.\n");
    exit(1);
}
$fail = 0;
$ok = function (bool $cond, string $msg) use (&$fail) {
    echo ($cond ? '  ok  ' : '  HIBA ') . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
};
$cleanup = [];

$p = new WC_Product_Simple();
$p->set_name('Kupon-teszt füstölő');
$p->set_regular_price('2000');
$p->save();
$cleanup[] = fn() => $p->delete(true);

$email = 'kupon-teszt-' . wp_generate_password(4, false, false) . '@example.com';
$order_with = function (string $code, string $status, string $mail) use ($p, &$cleanup): WC_Order {
    $o = wc_create_order(['status' => 'pending']);
    $o->add_product($p, 1);
    $o->set_billing_email($mail);
    $o->apply_coupon($code);
    $o->calculate_totals();
    $o->set_status($status);
    $o->save();
    $cleanup[] = fn() => $o->delete(true);
    return $o;
};
$valid = function (WC_Coupon $c): string {
    unset($GLOBALS['mandala_coupon_unpaid_memo']); // minden ellenőrzés külön kérés a valóságban
    $c = new WC_Coupon($c->get_code()); // friss objektum, mint a pénztárban
    $r = (new WC_Discounts(WC()->cart))->is_coupon_valid($c);
    return is_wp_error($r) ? $r->get_error_message() : 'ok';
};
if (!WC()->cart) {
    wc_load_cart();
}
WC()->customer->set_billing_email($email); // a pénztárban megadott cím (a kupon ehhez a címhez kötött)

echo "Félbemaradt rendelés után újra beváltható\n";
$c = mandala_growth_coupon('UDV', 'percent', 10, 14, $email, ['_mandala_first_order' => 'yes']);
$cleanup[] = fn() => wp_delete_post($c->get_id(), true);
$ok($valid($c) === 'ok', 'friss kód: érvényes');
$o1 = $order_with($c->get_code(), 'pending', $email);
$c1 = new WC_Coupon($c->get_code());
$ok($c1->get_usage_count('edit') === 1, 'a „Függőben” rendelés felhasználásnak számít (WooCommerce): ' . $c1->get_usage_count('edit'));
$ok($valid($c) === 'ok', 'a gazdája ki nem fizetett rendelése mellett újra érvényes: ' . $valid($c));
$ok((int) get_post_meta($c->get_id(), 'usage_limit', true) === 1, 'a mentett korlát nem változik (1)');

echo "Rendeléskori foglalás is átmegy\n";
$c2 = new WC_Coupon($c->get_code());
$held = $c2->get_data_store()->check_and_hold_coupon($c2);
$ok(is_string($held) && $held !== '', 'check_and_hold_coupon foglal (az új rendelés leadható)');
if (is_string($held)) {
    delete_post_meta($c->get_id(), $held);
}

echo "Kifizetett rendelés után elfogy, érthető üzenettel\n";
$o1->set_date_paid(time());
$o1->set_status('processing');
$o1->save();
$c3 = new WC_Coupon($c->get_code());
$msg = $valid($c3);
$ok($msg !== 'ok', 'a kifizetett rendelés után már nem váltható be');
$ok(strpos($msg, 'már felhasználták') !== false && strpos($msg, 'próbáld újra később') === false, 'érthető üzenet (nem „próbáld újra később”): ' . $msg);

echo "Más címének rendelése nem nyitja meg\n";
$d = mandala_growth_coupon('UDV', 'percent', 10, 14, $email, []);
$cleanup[] = fn() => wp_delete_post($d->get_id(), true);
$od = $order_with($d->get_code(), 'pending', $email); // a WooCommerce csak a gazdája címével engedi felvenni
$od->set_billing_email('masvalaki@example.com');
$od->save();
$ok($valid($d) !== 'ok', 'idegen cím függő rendelése után nem váltható be újra');

echo "E-mail-cím nélküli kupon: változatlan\n";
$g = new WC_Coupon();
$g->set_code('KUPONTESZT' . wp_generate_password(4, false, false));
$g->set_discount_type('percent');
$g->set_amount(5);
$g->set_usage_limit(1);
$g->save();
$cleanup[] = fn() => wp_delete_post($g->get_id(), true);
$order_with($g->get_code(), 'pending', $email);
$ok($valid($g) !== 'ok', 'cím nélküli, egyszeri kód a függő rendelés után marad elhasznált (nincs kiskapu)');

foreach (array_reverse($cleanup) as $f) {
    $f();
}
echo $fail ? "\n$fail HIBA\n" : "\nMinden ellenőrzés sikeres\n";
exit($fail ? 1 : 0);
