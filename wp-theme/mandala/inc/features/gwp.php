<?php
/**
 * Ajándék értékhatár felett („gift with purchase”): ha a kosár értéke eléri a határt, egy kiválasztott
 * termék ingyen a kosárba kerül (pl. „15 000 Ft felett ajándék füstölő”). A kosár és a minikosár sávja
 * mutatja, mennyi hiányzik még – ez emeli az átlagos kosárértéket.
 *
 *  - Magától kerül be és ki: a határ alatt kikerül, fölötte visszakerül (egy darab, 0 Ft).
 *  - Ha a vásárló maga veszi ki, nem erőltetjük vissza (a munkamenet végéig).
 *  - Ha az ajándék elfogy vagy nem vásárolható, magától szünetel.
 * Beállítás: WooCommerce → Mandala kuponok.
 */

defined('ABSPATH') || exit;

/** Az ajándék termék, ha a funkció be van kapcsolva és a termék adható. */
function mandala_gwp_product(): ?WC_Product
{
    $s = mandala_growth_settings();
    if ($s['gwp'] !== 'yes' || $s['gwp_sku'] === '' || !function_exists('wc_get_product_id_by_sku')) {
        return null;
    }
    $p = wc_get_product(wc_get_product_id_by_sku($s['gwp_sku']));
    return $p && $p->get_status() === 'publish' && $p->is_type('simple') && $p->is_in_stock() ? $p : null;
}
function mandala_gwp_status(): string
{
    $s = mandala_growth_settings();
    if ($s['gwp_sku'] === '') {
        return 'Adj meg egy cikkszámot.';
    }
    $id = wc_get_product_id_by_sku($s['gwp_sku']);
    if (!$id) {
        return '<strong style="color:#a3322a">Nincs ilyen cikkszámú termék.</strong>';
    }
    $p = wc_get_product($id);
    return 'Ajándék: <a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html($p->get_name()) . '</a>' . ($p->is_in_stock() ? '' : ' – <strong style="color:#a3322a">elfogyott, szünetel</strong>') . '.';
}

/** A kosár értéke az ajándék nélkül. */
function mandala_gwp_goods(WC_Cart $cart): float
{
    $sum = 0.0;
    foreach ($cart->get_cart() as $item) {
        if (empty($item['mandala_gwp'])) {
            $sum += (float) $item['data']->get_price() * (int) $item['quantity'];
        }
    }
    return $sum;
}

/** Ajándék be/ki a kosár értéke szerint (a kosár betöltése és minden módosítása után). */
function mandala_gwp_sync(): void
{
    static $busy = false;
    $cart = function_exists('WC') ? WC()->cart : null;
    if ($busy || !$cart || is_admin() && !wp_doing_ajax()) {
        return;
    }
    $busy = true;
    $gift = mandala_gwp_product();
    $key = null;
    foreach ($cart->get_cart() as $k => $item) {
        if (!empty($item['mandala_gwp'])) {
            $key = $k;
        }
    }
    $s = mandala_growth_settings();
    $eligible = $gift && mandala_gwp_goods($cart) >= (float) $s['gwp_threshold'];
    if ($key && !$eligible) {
        $GLOBALS['mandala_gwp_auto'] = 1; // a saját eltávolításunk nem „visszautasítás”
        $cart->remove_cart_item($key);
    } elseif (!$key && $eligible && !WC()->session->get('mandala_gwp_declined')) {
        $cart->add_to_cart($gift->get_id(), 1, 0, [], ['mandala_gwp' => 1]);
    }
    $busy = false;
}
foreach (['woocommerce_cart_loaded_from_session', 'woocommerce_add_to_cart', 'woocommerce_after_cart_item_quantity_update', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored'] as $hook) {
    add_action($hook, 'mandala_gwp_sync', 20);
}

/** Kézi eltávolítás: nem tesszük vissza. */
add_action('woocommerce_remove_cart_item', function ($key, $cart) {
    if (!empty($cart->cart_contents[$key]['mandala_gwp']) && !doing_action('woocommerce_cart_loaded_from_session') && empty($GLOBALS['mandala_gwp_auto'])) {
        WC()->session->set('mandala_gwp_declined', 1);
    }
}, 10, 2);
// A saját (automatikus) eltávolításunk ne számítson visszautasításnak.
add_action('woocommerce_cart_item_removed', function () {
    unset($GLOBALS['mandala_gwp_auto']);
}, 1);

/** Ár 0, darabszám 1, a kosárban „Ajándék” felirattal. */
add_action('woocommerce_before_calculate_totals', function ($cart) {
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['mandala_gwp'])) {
            $item['data']->set_price(0);
        }
    }
}, 20);
add_filter('woocommerce_cart_item_quantity', function ($html, $key, $item) {
    return !empty($item['mandala_gwp']) ? '<span class="gwp-qty">1</span>' : $html;
}, 10, 3);
add_filter('woocommerce_cart_item_price', function ($html, $item) {
    return !empty($item['mandala_gwp']) ? '<span class="gwp-tag">' . esc_html__('Ajándék', 'mandala') . '</span>' : $html;
}, 10, 2);
add_filter('woocommerce_cart_item_subtotal', function ($html, $item) {
    return !empty($item['mandala_gwp']) ? '<span class="gwp-tag">' . esc_html__('Ajándék', 'mandala') . '</span>' : $html;
}, 10, 2);
add_filter('woocommerce_cart_item_name', function ($name, $item) {
    return !empty($item['mandala_gwp']) ? $name . ' <span class="badge gwp-badge">' . esc_html__('Ajándék', 'mandala') . '</span>' : $name;
}, 10, 2);
// A rendelésben is látszódjon, hogy ajándék volt.
add_action('woocommerce_checkout_create_order_line_item', function ($line, $key, $values) {
    if (!empty($values['mandala_gwp'])) {
        $line->add_meta_data(__('Ajándék', 'mandala'), sprintf(__('%s Ft feletti rendeléshez', 'mandala'), mandala_num(mandala_growth_settings()['gwp_threshold'])));
    }
}, 10, 3);

/** Sáv a kosárban és a minikosárban: mennyi hiányzik még az ajándékig. */
add_action('mandala_cart_meters', function ($ctx) {
    $gift = mandala_gwp_product();
    $cart = WC()->cart;
    if (!$gift || !$cart || WC()->session->get('mandala_gwp_declined')) {
        return;
    }
    $s = mandala_growth_settings();
    $threshold = (float) $s['gwp_threshold'];
    $goods = mandala_gwp_goods($cart);
    $done = $goods >= $threshold;
    $pct = min(100, $goods / max(1, $threshold) * 100);
    echo '<div class="ship-meter gwp-meter' . ($done ? ' is-done' : '') . '"><p>' . mandala_icon('gift', 'ico ico-s') . ' '
        . ($done ? sprintf(esc_html__('%s jár a rendelésedhez – már a kosárban van.', 'mandala'), '<strong>' . esc_html(mb_strtoupper(mb_substr($s['gwp_label'], 0, 1)) . mb_substr($s['gwp_label'], 1)) . '</strong>')
                 : sprintf(esc_html__('Még %1$s, és %2$s kapsz.', 'mandala'), '<strong>' . esc_html(mandala_fmt($threshold - $goods)) . '</strong>', '<strong>' . esc_html($s['gwp_label']) . '</strong>'))
        . '</p><div class="meter" role="progressbar" aria-label="' . esc_attr__('Ajándékig', 'mandala') . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span style="width:' . esc_attr((string) $pct) . '%"></span></div></div>'; // phpcs:ignore
});
