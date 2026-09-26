<?php
/**
 * E-mail automatizmusok (Action Scheduler – a WooCommerce része):
 *  - elhagyott kosár: egyetlen emlékeztető a kosár visszaállító linkjével;
 *  - használati útmutató a teljesített rendelés után;
 *  - újrarendelés emlékeztető fogyóeszközöknél (füstölő);
 *  - értékelés kérése személyes, ellenőrzött linkkel (lásd reviews.php).
 * Beállítás: WooCommerce → Mandala automatizmusok.
 */

defined('ABSPATH') || exit;

const MANDALA_AS_GROUP = 'mandala';

function mandala_schedule(int $delay_seconds, string $hook, array $args): void
{
    if (function_exists('as_schedule_single_action') && !as_next_scheduled_action($hook, $args, MANDALA_AS_GROUP)) {
        as_schedule_single_action(time() + $delay_seconds, $hook, $args, MANDALA_AS_GROUP);
    }
}

/* ---------- Elhagyott kosár ---------- */

/** A pénztár az e-mail megadásakor jelez (wc-ajax=mandala_capture). */
add_action('wc_ajax_mandala_capture', function () {
    check_ajax_referer('mandala-cart', 'security');
    $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
    if (!mandala_automation_on('abandoned') || !is_email($email) || !WC()->cart || WC()->cart->is_empty() || mandala_is_unsubscribed($email)) {
        wp_send_json(['ok' => false]);
    }
    $items = [];
    foreach (WC()->cart->get_cart() as $item) {
        $items[] = [(int) $item['product_id'], (int) $item['variation_id'], (int) $item['quantity']];
    }
    $carts = (array) get_option('mandala_abandoned', []);
    $carts[$email] = ['items' => $items, 'time' => time(), 'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? ''))];
    // Legfeljebb 2000 nyitott kosarat tartunk (a legrégebbiek esnek ki).
    if (count($carts) > 2000) {
        uasort($carts, fn($a, $b) => $b['time'] <=> $a['time']);
        $carts = array_slice($carts, 0, 2000, true);
    }
    update_option('mandala_abandoned', $carts, false);
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('mandala_mail_abandoned', [$email], MANDALA_AS_GROUP);
    }
    mandala_schedule((int) mandala_automation_settings()['abandoned_hours'] * HOUR_IN_SECONDS, 'mandala_mail_abandoned', [$email]);
    wp_send_json(['ok' => true]);
});

/** Rendeléskor az emlékeztető törlődik. */
add_action('woocommerce_checkout_order_created', function (WC_Order $order) {
    $email = strtolower($order->get_billing_email());
    $carts = (array) get_option('mandala_abandoned', []);
    if (isset($carts[$email])) {
        unset($carts[$email]);
        update_option('mandala_abandoned', $carts, false);
    }
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('mandala_mail_abandoned', [$email], MANDALA_AS_GROUP);
    }
});

add_action('mandala_mail_abandoned', function ($email) {
    $carts = (array) get_option('mandala_abandoned', []);
    $cart = $carts[$email] ?? null;
    if (!$cart || !mandala_automation_on('abandoned')) {
        return;
    }
    // Közben rendelt? (másik eszközről is)
    $orders = wc_get_orders(['billing_email' => $email, 'date_created' => '>' . $cart['time'], 'limit' => 1, 'return' => 'ids']);
    unset($carts[$email]);
    update_option('mandala_abandoned', $carts, false);
    if ($orders) {
        return;
    }
    $rows = '';
    foreach ($cart['items'] as [$pid, $vid, $qty]) {
        $product = wc_get_product($vid ?: $pid);
        if ($product && $product->is_purchasable()) {
            $rows .= mandala_mail_product_row($product, esc_html($qty . ' db · ') . wp_kses_post(wc_price(wc_get_price_to_display($product) * $qty)));
        }
    }
    if (!$rows) {
        return;
    }
    $restore = add_query_arg(['mandala_restore' => rawurlencode($email), 'k' => mandala_token('restore', $email, (string) $cart['time']), 't' => $cart['time']], home_url('/'));
    // A visszaállításhoz a kosár tartalma a linkhez kötve 7 napig marad meg.
    set_transient('mandala_restore_' . md5($email . $cart['time']), $cart['items'], 7 * DAY_IN_SECONDS);
    $hello = $cart['name'] ? sprintf(__('Kedves %s!', 'mandala'), esc_html($cart['name'])) : __('Kedves Vásárlónk!', 'mandala');
    $body = '<p>' . $hello . '</p><p>' . esc_html__('Úgy láttuk, a kosaradban maradt néhány darab. Félretettük neked – egy kattintással folytathatod a rendelést.', 'mandala') . '</p>'
        . $rows . mandala_mail_button($restore, __('Rendelés folytatása', 'mandala'))
        . '<p style="color:#6E6357">' . esc_html__('Kérdésed van a termékekről? Válaszolj erre a levélre, szívesen segítünk.', 'mandala') . '</p>';
    mandala_send_mail($email, __('A kosarad vár rád', 'mandala'), __('Félretettük neked', 'mandala'), $body, true);
});

/** Kosár visszaállítása a levél linkjéből. */
add_action('wp_loaded', function () {
    if (empty($_GET['mandala_restore']) || empty($_GET['k']) || !function_exists('WC') || !WC()->cart) {
        return;
    }
    $email = strtolower(sanitize_email(wp_unslash($_GET['mandala_restore'])));
    $time = (string) absint($_GET['t'] ?? 0);
    if (!hash_equals(mandala_token('restore', $email, $time), sanitize_text_field(wp_unslash($_GET['k'])))) {
        return;
    }
    $items = get_transient('mandala_restore_' . md5($email . $time));
    if (is_array($items) && WC()->cart->is_empty()) {
        foreach ($items as [$pid, $vid, $qty]) {
            WC()->cart->add_to_cart($pid, $qty, $vid);
        }
    }
    WC()->customer?->set_billing_email($email);
    wp_safe_redirect(wc_get_checkout_url());
    exit;
}, 30);

/* ---------- Teljesített rendelés után ---------- */

add_action('woocommerce_order_status_completed', function ($order_id) {
    $s = mandala_automation_settings();
    if (mandala_automation_on('care')) {
        mandala_schedule((int) $s['care_days'] * DAY_IN_SECONDS, 'mandala_mail_care', [(int) $order_id]);
    }
    if (mandala_automation_on('review')) {
        mandala_schedule((int) $s['review_days'] * DAY_IN_SECONDS, 'mandala_mail_review', [(int) $order_id]);
    }
    if (mandala_automation_on('reorder') && mandala_order_has_category(wc_get_order($order_id), array_map('trim', explode(',', $s['reorder_cats'])))) {
        mandala_schedule((int) $s['reorder_days'] * DAY_IN_SECONDS, 'mandala_mail_reorder', [(int) $order_id]);
    }
});

function mandala_order_has_category(?WC_Order $order, array $cats): bool
{
    if (!$order) {
        return false;
    }
    foreach ($order->get_items() as $item) {
        if (has_term($cats, 'product_cat', $item->get_product_id())) {
            return true;
        }
    }
    return false;
}

add_action('mandala_mail_care', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_automation_on('care')) {
        return;
    }
    $rows = '';
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $ritual = $product ? (string) $product->get_meta('_mandala_ritual') : '';
        if ($product && $ritual && !$product->get_meta('_mandala_ticket_for')) {
            $rows .= mandala_mail_product_row($product, nl2br(esc_html($ritual)));
        }
    }
    if (!$rows) {
        return;
    }
    $body = '<p>' . sprintf(esc_html__('Kedves %s!', 'mandala'), esc_html($order->get_billing_first_name())) . '</p><p>'
        . esc_html__('Reméljük, már megérkezett és jó helyre került, amit tőlünk választottál. Összegyűjtöttük, hogyan érdemes használni és gondozni, hogy sokáig örömöd legyen benne.', 'mandala') . '</p>'
        . $rows;
    mandala_send_mail($order->get_billing_email(), __('Így használd és gondozd', 'mandala'), __('Használat és gondozás', 'mandala'), $body);
});

add_action('mandala_mail_reorder', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_automation_on('reorder')) {
        return;
    }
    $cats = array_map('trim', explode(',', mandala_automation_settings()['reorder_cats']));
    $rows = '';
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->is_in_stock() && has_term($cats, 'product_cat', $item->get_product_id())) {
            $rows .= mandala_mail_product_row($product, wp_kses_post(wc_price(wc_get_price_to_display($product))), add_query_arg('add-to-cart', $product->get_id(), wc_get_cart_url()));
        }
    }
    if (!$rows) {
        return;
    }
    $body = '<p>' . sprintf(esc_html__('Kedves %s!', 'mandala'), esc_html($order->get_billing_first_name())) . '</p><p>'
        . esc_html__('Talán már fogytán a füstölőd. Ha jólesett, egy kattintással újrarendelheted – vagy nézd meg az új illatokat.', 'mandala') . '</p>'
        . $rows . mandala_mail_button(mandala_url(['cat' => $cats[0]]), __('Új illatok', 'mandala'));
    mandala_send_mail($order->get_billing_email(), __('Fogytán a füstölő?', 'mandala'), __('Újrarendelés egy kattintással', 'mandala'), $body, true);
});

/* ---------- Pénztár: e-mail rögzítése és tájékoztatás ---------- */

add_filter('woocommerce_billing_fields', function ($fields) {
    if (mandala_automation_on('abandoned') && isset($fields['billing_email'])) {
        $fields['billing_email']['description'] = __('Ide küldjük a visszaigazolást és a csomagkövetést. Ha nem fejezed be a rendelést, egyszer emlékeztetünk – ebből egy kattintással leiratkozhatsz.', 'mandala');
    }
    return $fields;
}, 30);
