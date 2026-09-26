<?php
/**
 * REST végpontok (mandala/v1). Az iu_theme egyedi REST prefixet használ, ezért a
 * kliens az URL-t mindig a rest_url()-ből kapja (window.MANDALA.rest).
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    // Termékindex a szűrőnek és az élő keresőnek.
    register_rest_route('mandala/v1', '/products', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $response = rest_ensure_response(mandala_product_index());
            $response->header('Cache-Control', 'public, max-age=300');
            return $response;
        },
    ]);

    // Cikk kereső az élő kereséshez (az iu_theme a WP keresést szűkítheti, ezért saját lekérdezés).
    register_rest_route('mandala/v1', '/posts', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'args' => ['q' => ['type' => 'string', 'required' => true]],
        'callback' => function (WP_REST_Request $request) {
            $q = sanitize_text_field($request['q']);
            if (mb_strlen($q) < 2) {
                return [];
            }
            $query = new WP_Query(['post_type' => 'post', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 6, 'no_found_rows' => true]);
            return array_map(fn($p) => ['title' => get_the_title($p), 'url' => get_permalink($p)], $query->posts);
        },
    ]);

    // Készletértesítő feliratkozás.
    register_rest_route('mandala/v1', '/stock-notify', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'mandala_rest_stock_notify',
    ]);

    // Kedvencek szinkron belépett vásárlónál (a süti a böngészőben frissül).
    register_rest_route('mandala/v1', '/wishlist', [
        'methods' => 'POST',
        'permission_callback' => 'is_user_logged_in',
        'callback' => function (WP_REST_Request $request) {
            $ids = array_values(array_unique(array_filter(array_map('absint', (array) $request['ids']))));
            update_user_meta(get_current_user_id(), 'mandala_wishlist', array_slice($ids, 0, 100));
            return ['ids' => $ids];
        },
    ]);
});

/**
 * Készletértesítő: a feliratkozások a termék metájában, újra raktárra kerüléskor
 * (woocommerce_product_set_stock_status → instock) automatikus e-mail megy.
 */
function mandala_rest_stock_notify(WP_REST_Request $request)
{
    $email = sanitize_email((string) $request['email']);
    $product = wc_get_product(absint($request['product']));
    $errors = [];
    if (!is_email($email)) {
        $errors['email'] = __('Ez nem tűnik érvényes e-mail-címnek.', 'mandala');
    }
    if (!mandala_bool($request['accept'] ?? false)) {
        $errors['accept'] = __('Az elküldéshez fogadd el az adatkezelési tájékoztatót.', 'mandala');
    }
    if (!$product) {
        $errors['product'] = __('A termék nem található.', 'mandala');
    }
    if ($errors) {
        return new WP_REST_Response(['errors' => $errors, 'error' => __('Egy mezőt javítani kell.', 'mandala')], 400);
    }
    $list = (array) get_post_meta($product->get_id(), '_mandala_stock_notify', true);
    $list[strtolower($email)] = gmdate('c');
    update_post_meta($product->get_id(), '_mandala_stock_notify', array_filter($list));
    return ['success' => 1];
}

add_action('woocommerce_product_set_stock_status', function ($product_id, $status) {
    if ($status !== 'instock') {
        return;
    }
    $list = (array) get_post_meta($product_id, '_mandala_stock_notify', true);
    $list = array_filter($list);
    if (!$list) {
        return;
    }
    $product = wc_get_product($product_id);
    $subject = sprintf(__('Újra raktáron: %s', 'mandala'), $product->get_name());
    $body = sprintf(__("Kedves Vásárlónk!\n\nA(z) %1\$s újra elérhető webáruházunkban:\n%2\$s\n\nA készlet korlátozott, ezért érdemes hamar lecsapni rá.\n\nÜdvözlettel:\n%3\$s", 'mandala'), $product->get_name(), get_permalink($product_id), get_bloginfo('name'));
    foreach (array_keys($list) as $email) {
        wp_mail($email, $subject, $body);
    }
    delete_post_meta($product_id, '_mandala_stock_notify');
}, 10, 2);
