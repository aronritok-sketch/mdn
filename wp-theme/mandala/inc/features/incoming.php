<?php
/**
 * Érkező szállítmány / előrendelés.
 *
 * Ha egy elfogyott terméknél megadod a várható érkezést (Mandala adatok → Várható érkezés),
 * a termék előrendelhető lesz: a WooCommerce utánrendelés („backorder”) funkciójával, így a
 * készlet, a kosár, a rendelés és a JUTA-szinkron is a megszokott módon működik.
 * Érkezéskor (készlet > 0) az előrendelés magától kikapcsol.
 */

defined('ABSPATH') || exit;

add_filter('mandala_product_meta_fields', function ($fields) {
    return $fields + [
        '_mandala_incoming' => ['Várható érkezés', 'date', 'Elfogyott terméknél: a következő szállítmány várható érkezése. Kitöltve a termék előrendelhető.'],
        '_mandala_incoming_from' => ['Honnan érkezik', 'text', 'Pl. „Nepálból” – a termékoldalon: „Úton Nepálból”.'],
    ];
});

/** A várható érkezés (Y-m-d), ha még jövőbeli és a termék nincs raktáron. */
function mandala_incoming_date(WC_Product $product): string
{
    $date = (string) $product->get_meta('_mandala_incoming', true, 'edit');
    if (!$date || $date < wp_date('Y-m-d')) {
        return '';
    }
    return $product->get_stock_status('edit') === 'instock' && (!$product->managing_stock() || $product->get_stock_quantity() > 0) ? '' : $date;
}

function mandala_incoming_label(string $date, bool $long = true): string
{
    return $date ? wp_date($long ? 'Y. F j.' : 'M j.', strtotime($date . ' 12:00')) : '';
}

/** Mentéskor: érkezési dátummal az utánrendelés engedélyezett (értesítéssel), nélküle tiltott. */
add_action('woocommerce_admin_process_product_object', function (WC_Product $product) {
    $date = (string) $product->get_meta('_mandala_incoming', true, 'edit');
    if ($date && $date >= wp_date('Y-m-d')) {
        $product->set_backorders('notify');
    } elseif ($product->get_backorders('edit') === 'notify' && $product->get_meta('_mandala_incoming_auto', true, 'edit')) {
        $product->set_backorders('no');
    }
    $product->update_meta_data('_mandala_incoming_auto', $date ? '1' : '');
}, 30);

/** Megérkezett (készlet > 0): az előrendelés kikapcsol. */
add_action('woocommerce_product_set_stock', function (WC_Product $product) {
    if ($product->get_stock_quantity() > 0 && $product->get_meta('_mandala_incoming', true, 'edit')) {
        $product->delete_meta_data('_mandala_incoming');
        $product->delete_meta_data('_mandala_incoming_auto');
        $product->set_backorders('no');
        $product->save();
    }
});

add_filter('mandala_product_index_row', function ($row, WC_Product $product) {
    $date = mandala_incoming_date($product);
    $row['incoming'] = $date;
    $row['incomingLabel'] = mandala_incoming_label($date, false);
    if ($date) {
        $row['stock'] = 'incoming';
    }
    return $row;
}, 10, 2);

add_filter('mandala_card_badges', function ($badges, WC_Product $product) {
    $date = mandala_incoming_date($product);
    if (!$date) {
        return $badges;
    }
    // Az „Elfogyott” helyett „Érkezik …”.
    $badges = str_replace('<span class="badge badge-dark">' . esc_html__('Elfogyott', 'mandala') . '</span>', '', $badges);
    return '<span class="badge badge-incoming">' . esc_html(sprintf(__('Érkezik %s', 'mandala'), mandala_incoming_label($date, false))) . '</span>' . $badges;
}, 5, 2);

add_filter('mandala_stock_html', function ($html, WC_Product $product) {
    $date = mandala_incoming_date($product);
    if (!$date) {
        return $html;
    }
    $from = (string) $product->get_meta('_mandala_incoming_from', true, 'edit') ?: __('Nepálból és Indiából', 'mandala');
    return '<p class="stock incoming-stock">' . mandala_icon('truck', 'ico ico-s') . '<span><strong>' . esc_html(sprintf(__('Úton %s', 'mandala'), $from)) . '</strong> – '
        . esc_html(sprintf(__('várható érkezés: %s. Előrendelheted, a szállítmány megérkezése után elsőként küldjük.', 'mandala'), mandala_incoming_label($date))) . '</span></p>';
}, 10, 2);

add_filter('mandala_add_to_cart_label', fn($label, WC_Product $product) => mandala_incoming_date($product) ? __('Előrendelem', 'mandala') : $label, 10, 2);

/** Kosár és rendelés: az előrendelt tétel jelölése. */
add_filter('woocommerce_get_item_data', function ($data, $item) {
    $product = $item['data'] ?? null;
    if ($product instanceof WC_Product && ($date = mandala_incoming_date($product))) {
        $data[] = ['key' => __('Előrendelés', 'mandala'), 'value' => sprintf(__('várható érkezés: %s', 'mandala'), mandala_incoming_label($date))];
    }
    return $data;
}, 10, 2);
add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values) {
    $product = $values['data'] ?? null;
    if ($product instanceof WC_Product && ($date = mandala_incoming_date($product))) {
        $item->add_meta_data(__('Előrendelés, várható érkezés', 'mandala'), mandala_incoming_label($date));
        $item->get_order()?->update_meta_data('_mandala_preorder', '1');
    }
}, 10, 3);
add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    foreach (WC()->cart->get_cart() as $values) {
        if (($values['data'] ?? null) instanceof WC_Product && mandala_incoming_date($values['data'])) {
            $order->update_meta_data('_mandala_preorder', '1');
        }
    }
});
add_action('woocommerce_before_thankyou', function ($order_id) {
    $order = wc_get_order($order_id);
    if ($order && $order->get_meta('_mandala_preorder') === '1') {
        echo '<div class="woocommerce-info" role="status">' . mandala_icon('truck') . '<span>' . esc_html__('A rendelésed előrendelt terméket is tartalmaz: a csomagot a szállítmány megérkezése után adjuk fel, erről e-mailben értesítünk.', 'mandala') . '</span></div>';
    }
}, 20);

/** mandala/products: „incoming” mód – az érkező szállítmány termékei. */
add_filter('mandala_product_selection', function ($ids, $mode, $limit) {
    if ($mode !== 'incoming') {
        return $ids;
    }
    $found = wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'objects', 'meta_key' => '_mandala_incoming', 'meta_compare' => 'EXISTS']);
    $found = array_filter($found, fn($p) => mandala_incoming_date($p) !== '');
    usort($found, fn($a, $b) => strcmp(mandala_incoming_date($a), mandala_incoming_date($b)));
    return array_slice(array_map(fn($p) => $p->get_id(), $found), 0, $limit);
}, 10, 3);

/** Bemutató: egy termék „úton Nepálból” három hét múlva. */
add_action('mandala_demo_features', function () {
    $id = wc_get_product_id_by_sku('MND-MA-0108') ?: (wc_get_products(['limit' => 1, 'return' => 'ids', 'category' => ['mala-lancok'], 'meta_key' => '_mandala_demo', 'meta_value' => '1'])[0] ?? 0);
    $product = $id ? wc_get_product($id) : null;
    if (!$product) {
        return;
    }
    $product->set_stock_quantity(0);
    $product->set_backorders('notify');
    $product->update_meta_data('_mandala_incoming', wp_date('Y-m-d', strtotime('+21 days')));
    $product->update_meta_data('_mandala_incoming_from', 'Nepálból');
    $product->update_meta_data('_mandala_incoming_auto', '1');
    $product->save();
});
