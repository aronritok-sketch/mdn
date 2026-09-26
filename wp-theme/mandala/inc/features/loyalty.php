<?php
/**
 * Törzsvásárlói pontok (csak a webáruházban; a JUTA-val később köthető össze).
 *  - Gyűjtés: teljesített rendelés után, regisztrált vásárlónak (viszonteladó és utalványvásárlás nem).
 *  - Beváltás: a pénztárban egy jelölőnégyzettel; kedvezmény (arányosan csökkenti az ÁFA-alapot).
 *  - Egyenleg és napló: Fiókom → Hűségpontok; adminban a felhasználó profilján módosítható.
 * Beállítás (és az ajándékutalványé): WooCommerce → Ajándék és hűség.
 */

defined('ABSPATH') || exit;

function mandala_loyalty_on(): bool
{
    return mandala_gift_settings()['loyalty'] === 'yes';
}

function mandala_points(int $user_id): int
{
    return max(0, (int) get_user_meta($user_id, '_mandala_points', true));
}

function mandala_points_add(int $user_id, int $delta, string $note): void
{
    if (!$user_id || !$delta) {
        return;
    }
    update_user_meta($user_id, '_mandala_points', max(0, mandala_points($user_id) + $delta));
    $log = (array) get_user_meta($user_id, '_mandala_points_log', true);
    array_unshift($log, [current_time('mysql'), $delta, $note]);
    update_user_meta($user_id, '_mandala_points_log', array_slice(array_filter($log), 0, 100));
}

/** Ennyi pont jár ekkora (bruttó) vásárlásért. */
function mandala_points_for(float $amount): int
{
    return (int) floor($amount / max(1, (int) mandala_gift_settings()['earn_per']));
}

function mandala_can_collect(int $user_id = 0): bool
{
    return mandala_loyalty_on() && !mandala_is_wholesale_user($user_id ?: null);
}

/* ---------- Gyűjtés ---------- */

/** A pontalap: a termékek fizetett ára (utalványvásárlás és szállítás nélkül, a kedvezmények után). */
function mandala_order_points_base(WC_Order $order): float
{
    $base = 0.0;
    foreach ($order->get_items() as $line) {
        if (!is_array($line->get_meta('_mandala_voucher'))) {
            $base += (float) $line->get_total() + (float) $line->get_total_tax();
        }
    }
    foreach ($order->get_fees() as $fee) {
        if ((float) $fee->get_total() < 0) {
            $base += (float) $fee->get_total() + (float) $fee->get_total_tax();
        }
    }
    return max(0, $base);
}

add_action('woocommerce_order_status_completed', function ($order_id) {
    $order = wc_get_order($order_id);
    $user = $order ? $order->get_customer_id() : 0;
    if (!$user || $order->get_meta('_mandala_points_earned') !== '' || !mandala_can_collect($user)) {
        return;
    }
    $points = mandala_points_for(mandala_order_points_base($order));
    $order->update_meta_data('_mandala_points_earned', (string) $points);
    $order->save();
    if ($points > 0) {
        mandala_points_add($user, $points, sprintf(__('Rendelés #%d', 'mandala'), $order_id));
        $order->add_order_note(sprintf('%d hűségpont jóváírva.', $points));
    }
});

/* ---------- Beváltás a pénztárban ---------- */

/** Mennyi pont váltható be most: [pont, Ft]. */
function mandala_points_redeemable(WC_Cart $cart): array
{
    $user = get_current_user_id();
    $s = mandala_gift_settings();
    $balance = $user ? mandala_points($user) : 0;
    if (!$user || !mandala_can_collect($user) || $balance < (int) $s['min_redeem']) {
        return [0, 0];
    }
    $goods = 0.0;
    foreach ($cart->get_cart() as $item) {
        if (empty($item['mandala_voucher'])) {
            $goods += (float) $item['line_total'] + (float) $item['line_tax'];
        }
    }
    $value = max(1, (float) $s['point_value']);
    $points = (int) min($balance, floor($goods * (int) $s['max_pct'] / 100 / $value));
    return [$points, (int) floor($points * $value)];
}

function mandala_points_wanted(): bool
{
    return function_exists('WC') && WC()->session && WC()->session->get('mandala_use_points') === 'yes';
}

/** A jelölőnégyzet állapota a pénztár frissítésekor és a rendelés elküldésekor. */
add_action('woocommerce_checkout_update_order_review', function ($post_data) {
    parse_str((string) $post_data, $data);
    WC()->session->set('mandala_use_points', empty($data['mandala_use_points']) ? 'no' : 'yes');
});
add_action('woocommerce_checkout_process', function () {
    WC()->session->set('mandala_use_points', empty($_POST['mandala_use_points']) ? 'no' : 'yes'); // phpcs:ignore WordPress.Security.NonceVerification
    WC()->cart->calculate_totals();
});

add_action('woocommerce_cart_calculate_fees', function (WC_Cart $cart) {
    WC()->session?->set('mandala_points_use', 0);
    if (!mandala_points_wanted() || !is_user_logged_in()) {
        return;
    }
    [$points, $amount] = mandala_points_redeemable($cart);
    if ($amount <= 0) {
        return;
    }
    $rate = (float) mandala_config('vatRate', 27);
    // Bruttó kedvezmény; a WooCommerce a negatív, adóköteles díj ÁFÁ-ját a tételek arányában számolja.
    $cart->add_fee(sprintf(__('Hűségpont beváltás (%d pont)', 'mandala'), $points), -$amount / (1 + $rate / 100), true);
    WC()->session->set('mandala_points_use', $points);
}, 40);

add_action('mandala_review_after_coupon', function () {
    if (!mandala_loyalty_on() || mandala_is_wholesale_user()) {
        return;
    }
    $cart = WC()->cart;
    $s = mandala_gift_settings();
    if (!is_user_logged_in()) {
        $earn = mandala_points_for((float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax());
        if ($earn > 0) {
            echo '<p class="points-note text-small">' . mandala_icon('sparkle', 'ico ico-s') . ' ' . esc_html(sprintf(__('Fiókkal vásárolva ezzel a rendeléssel %d hűségpontot gyűjtenél.', 'mandala'), $earn)) . '</p>'; // phpcs:ignore
        }
        return;
    }
    $balance = mandala_points(get_current_user_id());
    [$points, $amount] = mandala_points_redeemable($cart);
    if ($points > 0) {
        $checked = mandala_points_wanted();
        echo '<p class="points-redeem update_totals_on_change"><label class="check"><input type="checkbox" name="mandala_use_points" value="1"' . checked($checked, true, false) . '> <span>'
            . esc_html(sprintf(__('Beváltok %1$s pontot (−%2$s)', 'mandala'), mandala_num($points), mandala_fmt($amount)))
            . '<small class="text-muted"> · ' . esc_html(sprintf(__('egyenleg: %s pont', 'mandala'), mandala_num($balance))) . '</small></span></label></p>';
    } elseif ($balance > 0) {
        echo '<p class="points-note text-small">' . mandala_icon('sparkle', 'ico ico-s') . ' ' . esc_html(sprintf(__('%1$s pontod van – %2$s ponttól váltható be.', 'mandala'), mandala_num($balance), mandala_num((int) $s['min_redeem']))) . '</p>'; // phpcs:ignore
    }
});

add_action('woocommerce_checkout_order_created', function (WC_Order $order) {
    $points = (int) (WC()->session ? WC()->session->get('mandala_points_use', 0) : 0);
    if ($points > 0 && $order->get_customer_id()) {
        mandala_points_add($order->get_customer_id(), -$points, sprintf(__('Beváltás, rendelés #%d', 'mandala'), $order->get_id()));
        $order->update_meta_data('_mandala_points_used', (string) $points);
        $order->save();
    }
    WC()->session?->set('mandala_use_points', 'no');
    WC()->session?->set('mandala_points_use', 0);
});

/** Lemondás / visszatérítés: a beváltott pont visszajár, a kapott pont levonódik (egyszer). */
add_action('woocommerce_order_status_changed', function ($order_id, $from, $to) {
    $order = wc_get_order($order_id);
    $user = $order ? $order->get_customer_id() : 0;
    if (!$user || !in_array($to, ['cancelled', 'failed', 'refunded'], true) || $order->get_meta('_mandala_points_reversed') === 'yes') {
        return;
    }
    $used = (int) $order->get_meta('_mandala_points_used');
    $earned = (int) $order->get_meta('_mandala_points_earned');
    if (!$used && !$earned) {
        return;
    }
    if ($used) {
        mandala_points_add($user, $used, sprintf(__('Visszaírás, rendelés #%d', 'mandala'), $order_id));
    }
    if ($earned) {
        mandala_points_add($user, -$earned, sprintf(__('Visszavonás, rendelés #%d', 'mandala'), $order_id));
    }
    $order->update_meta_data('_mandala_points_reversed', 'yes');
    $order->save();
}, 10, 3);

/* ---------- Termékoldal: mennyi pont jár ---------- */

add_action('mandala_summary_after_price', function (WC_Product $product) {
    if (!mandala_can_collect() || mandala_is_voucher($product) || $product->get_meta('_mandala_ticket_for')) {
        return;
    }
    $points = mandala_points_for((float) wc_get_price_to_display($product));
    if ($points > 0) {
        echo '<p class="points-earn text-small">' . mandala_icon('sparkle', 'ico ico-s') . ' ' . esc_html(sprintf(__('+%d hűségpont', 'mandala'), $points)) . ' <span class="text-muted">' . esc_html(is_user_logged_in() ? __('a teljesítés után', 'mandala') : __('fiókkal vásárolva', 'mandala')) . '</span></p>'; // phpcs:ignore
    }
}, 20);

/* ---------- Fiókom → Hűségpontok ---------- */

add_filter('woocommerce_get_query_vars', fn($vars) => $vars + ['husegpontok' => 'husegpontok']);
add_filter('woocommerce_account_menu_items', function ($items) {
    if (!mandala_can_collect(get_current_user_id())) {
        return $items;
    }
    $new = [];
    foreach ($items as $key => $label) {
        $new[$key] = $label;
        if ($key === 'orders') {
            $new['husegpontok'] = __('Hűségpontok', 'mandala');
        }
    }
    return $new;
});
add_filter('woocommerce_endpoint_husegpontok_title', fn() => __('Hűségpontok', 'mandala'));
add_action('woocommerce_account_husegpontok_endpoint', function () {
    $user = get_current_user_id();
    $s = mandala_gift_settings();
    $balance = mandala_points($user);
    echo '<div class="points-balance"><span class="text-muted text-small">' . esc_html__('Egyenleged', 'mandala') . '</span><strong class="num">' . esc_html(mandala_num($balance)) . ' ' . esc_html__('pont', 'mandala') . '</strong>'
        . '<span class="text-small">' . esc_html(sprintf(__('= %s kedvezmény a következő rendelésedből', 'mandala'), mandala_fmt($balance * (float) $s['point_value']))) . '</span></div>';
    echo '<p>' . esc_html(sprintf(__('Minden %1$s vásárlás után 1 pontot kapsz, 1 pont = %2$s. %3$s ponttól a pénztárban beválthatod, a termékek árának legfeljebb %4$d%%-áig.', 'mandala'),
        mandala_fmt((int) $s['earn_per']), mandala_fmt((float) $s['point_value']), mandala_num((int) $s['min_redeem']), (int) $s['max_pct'])) . '</p>';
    $log = array_filter((array) get_user_meta($user, '_mandala_points_log', true));
    if ($log) {
        echo '<table class="shop_table points-log"><caption class="sr-only">' . esc_html__('Pontmozgások', 'mandala') . '</caption><thead><tr><th scope="col">' . esc_html__('Dátum', 'mandala') . '</th><th scope="col">' . esc_html__('Mozgás', 'mandala') . '</th><th scope="col" class="num">' . esc_html__('Pont', 'mandala') . '</th></tr></thead><tbody>';
        foreach ($log as [$date, $delta, $note]) {
            echo '<tr><td>' . esc_html(wp_date('Y. m. d.', strtotime($date))) . '</td><td>' . esc_html($note) . '</td><td class="num">' . esc_html(($delta > 0 ? '+' : '−') . mandala_num(abs((int) $delta))) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="text-muted">' . esc_html__('Még nincs pontmozgás. Az első teljesített rendelésed után itt látod a jóváírást.', 'mandala') . '</p>';
    }
});
/** Az új fiókvégpont miatt egyszer frissíteni kell az átírási szabályokat. */
add_action('init', function () {
    if (get_option('mandala_rewrite_version') !== 'points-1') {
        add_action('wp_loaded', fn() => flush_rewrite_rules(false));
        update_option('mandala_rewrite_version', 'points-1');
    }
}, 99);

/* ---------- Admin: pontok a felhasználó profilján ---------- */

$mandala_points_profile = function (WP_User $user) {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    echo '<h2>Hűségpontok</h2><table class="form-table"><tr><th><label for="mandala_points_adjust">Egyenleg: ' . (int) mandala_points($user->ID) . ' pont</label></th><td>'
        . '<input type="number" id="mandala_points_adjust" name="mandala_points_adjust" value="" placeholder="+100 vagy -100" style="width:140px"> '
        . '<input type="text" name="mandala_points_note" class="regular-text" placeholder="Megjegyzés (pl. kárpótlás)"><p class="description">Jóváírás vagy levonás; a vásárló a fiókjában látja a megjegyzést.</p></td></tr></table>';
};
add_action('show_user_profile', $mandala_points_profile);
add_action('edit_user_profile', $mandala_points_profile);
$mandala_points_save = function ($user_id) {
    if (!current_user_can('manage_woocommerce') || empty($_POST['mandala_points_adjust']) || !check_admin_referer('update-user_' . $user_id)) {
        return;
    }
    $note = sanitize_text_field(wp_unslash($_POST['mandala_points_note'] ?? '')) ?: __('Kézi módosítás', 'mandala');
    mandala_points_add((int) $user_id, (int) $_POST['mandala_points_adjust'], $note);
};
add_action('personal_options_update', $mandala_points_save);
add_action('edit_user_profile_update', $mandala_points_save);

/* ---------- Beállítások: WooCommerce → Ajándék és hűség ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Ajándék és hűség', 'Ajándék és hűség', 'manage_woocommerce', 'mandala-gifts', function () {
        $defaults = mandala_gift_settings();
        if (isset($_POST['mandala_gifts']) && check_admin_referer('mandala_gifts')) {
            $in = (array) wp_unslash($_POST['mandala_gifts']);
            $clean = [];
            foreach ($defaults as $key => $value) {
                $clean[$key] = $key === 'loyalty' ? (empty($in[$key]) ? 'no' : 'yes')
                    : ($key === 'voucher_amounts' ? implode(',', array_filter(array_map('absint', explode(',', (string) ($in[$key] ?? ''))))) : max(0, (float) ($in[$key] ?? $value)));
            }
            update_option('mandala_gifts', $clean, false);
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
        $s = mandala_gift_settings();
        $num = fn($key, $label, $desc) => '<tr><th scope="row"><label for="mg-' . $key . '">' . esc_html($label) . '</label></th><td><input type="number" step="any" min="0" id="mg-' . $key . '" name="mandala_gifts[' . $key . ']" value="' . esc_attr((string) $s[$key]) . '" style="width:120px"><p class="description">' . esc_html($desc) . '</p></td></tr>';
        echo '<div class="wrap"><h1>Ajándék és hűség</h1><form method="post">';
        wp_nonce_field('mandala_gifts');
        echo '<h2>Ajándékutalvány</h2><table class="form-table">'
            . '<tr><th scope="row"><label for="mg-amounts">Választható összegek</label></th><td><input type="text" id="mg-amounts" class="regular-text" name="mandala_gifts[voucher_amounts]" value="' . esc_attr($s['voucher_amounts']) . '"><p class="description">Ft, vesszővel elválasztva. Az utalvány terméknél a „Mandala adatok → Ajándékutalvány” legyen bejelölve.</p></td></tr>'
            . $num('voucher_months', 'Érvényesség (hónap)', 'A kiállítástól számítva.')
            . '</table><p class="description">Többcélú utalvány: eladásakor nincs ÁFA, a beváltáskor a vásárolt termékek után keletkezik. A beváltás a rendelésben fizetési módként jelenik meg (nem kedvezményként) – a számlázás beállítását egyeztessétek a könyvelővel.</p>'
            . '<h2>Ajándékcsomag</h2><table class="form-table">' . $num('gift_max', 'Termékek egy csomagban', 'Legfeljebb ennyi terméket lehet kiválasztani. A kínálat az „Ajándék” szándékhoz sorolt termékekből áll; a csomagolásokat a „Mandala adatok → Ajándékcsomagolás” jelöli.') . '</table>'
            . '<h2>Hűségpontok</h2><table class="form-table">'
            . '<tr><th scope="row">Program</th><td><label><input type="checkbox" name="mandala_gifts[loyalty]" value="1"' . checked($s['loyalty'], 'yes', false) . '> bekapcsolva</label><p class="description">Regisztrált vásárlóknak; viszonteladók nem gyűjtenek.</p></td></tr>'
            . $num('earn_per', 'Ennyi Ft vásárlás = 1 pont', 'A termékek fizetett ára alapján, szállítás és utalványvásárlás nélkül, a teljesítéskor.')
            . $num('point_value', '1 pont értéke (Ft)', '')
            . $num('min_redeem', 'Beváltás ennyi ponttól', '')
            . $num('max_pct', 'Legfeljebb (a termékek árának %-a)', '')
            . '</table>';
        submit_button('Mentés');
        echo '</form></div>';
    });
});
