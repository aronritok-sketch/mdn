<?php
/**
 * Mérés: Google Consent Mode v2 alapállapot, GA4 események a saját felületekhez (assets/js/track.js),
 * és opcionálisan Meta Conversions API (szerveroldali Purchase esemény, csak marketing hozzájárulással).
 *
 * A GA4 / Google Ads / Meta pixel címkéi a GTM-ben (GTM4WP) futnak; a WooCommerce alap eseményeit
 * (termékoldal, pénztár lépései, vásárlás) a GTM4WP adja. A téma csak azt méri, amit a GTM4WP nem lát.
 * Beállítás: WooCommerce → Mandala mérés.
 */

defined('ABSPATH') || exit;

function mandala_analytics_settings(): array
{
    return wp_parse_args((array) get_option('mandala_analytics', []), [
        'consent_default' => 'yes',   // Consent Mode v2 alapállapot (elutasítva) a GTM betöltése előtt
        'track' => 'yes',             // saját felületek eseményei a dataLayer-be
        'capi' => 'no',
        'pixel_id' => '',
        'capi_token' => '',
        'capi_test_code' => '',
    ]);
}

/**
 * A hozzájárulás alapállapota a GTM előtt (wp_head legeleje): minden elutasítva, a korábban
 * elmentett választás (localStorage) azonnal visszaállítva – így a GTM címkék már az első
 * oldalbetöltésnél a helyes állapotot látják.
 */
add_action('wp_head', function () {
    if (mandala_analytics_settings()['consent_default'] !== 'yes' || !apply_filters('mandala_consent_mode', true)) {
        return;
    }
    echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
        . "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',functionality_storage:'granted',security_storage:'granted',wait_for_update:500});"
        . "try{var c=JSON.parse(localStorage.getItem('mandala.cookie.v1')||'null');if(c){var m=c.marketing?'granted':'denied';gtag('consent','update',{analytics_storage:c.stats?'granted':'denied',ad_storage:m,ad_user_data:m,ad_personalization:m});}}catch(e){}"
        . "</script>\n";
}, 0);

add_filter('mandala_js_data', function ($data) {
    $s = mandala_analytics_settings();
    $data['currency'] = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HUF';
    $data['track'] = ['viewItem' => !defined('GTM4WP_VERSION')];
    return $data;
});

add_action('wp_enqueue_scripts', function () {
    if (mandala_analytics_settings()['track'] === 'yes' && !is_admin()) {
        wp_enqueue_script_module('mandala-track', MANDALA_URL . '/assets/js/track.js', [], MANDALA_VERSION);
    }
}, 30);

/* ---------- Meta Conversions API ---------- */

/** A pénztárban a böngésző elküldi a marketing-hozzájárulást; a Meta sütiket (fbp, fbc) a kérésből olvassuk. */
add_action('woocommerce_after_order_notes', function () {
    echo '<input type="hidden" name="mandala_marketing_consent" id="mandala_marketing_consent" value="">';
    echo "<script>try{var c=JSON.parse(localStorage.getItem('mandala.cookie.v1')||'null');document.getElementById('mandala_marketing_consent').value=c&&c.marketing?'yes':'no';}catch(e){}</script>";
});
add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    $order->update_meta_data('_mandala_marketing_consent', ($_POST['mandala_marketing_consent'] ?? '') === 'yes' ? 'yes' : 'no'); // phpcs:ignore WordPress.Security.NonceVerification
    foreach (['_fbp', '_fbc'] as $cookie) {
        if (!empty($_COOKIE[$cookie])) {
            $order->update_meta_data('_mandala' . $cookie, sanitize_text_field(wp_unslash($_COOKIE[$cookie])));
        }
    }
});

function mandala_capi_ready(): bool
{
    $s = mandala_analytics_settings();
    return $s['capi'] === 'yes' && $s['pixel_id'] && $s['capi_token'];
}

/** Fizetés (kártya) vagy teljesítés (utánvét) után egyszer, háttérben. */
$mandala_capi_queue = function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_capi_ready() || $order->get_meta('_mandala_marketing_consent') !== 'yes' || $order->get_meta('_mandala_capi_queued')) {
        return;
    }
    $order->update_meta_data('_mandala_capi_queued', time());
    $order->save();
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('mandala_capi_purchase', [(int) $order_id], MANDALA_AS_GROUP);
    } else {
        do_action('mandala_capi_purchase', (int) $order_id);
    }
};
add_action('woocommerce_payment_complete', $mandala_capi_queue);
add_action('woocommerce_order_status_completed', $mandala_capi_queue);

add_action('mandala_capi_purchase', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_capi_ready()) {
        return;
    }
    $s = mandala_analytics_settings();
    $hash = fn($v) => $v !== '' ? hash('sha256', $v) : null;
    $norm = fn($v) => strtolower(trim((string) $v));
    $user = array_filter([
        'em' => [$hash($norm($order->get_billing_email()))],
        'ph' => [$hash(preg_replace('/\D/', '', (string) $order->get_billing_phone()))],
        'fn' => [$hash($norm($order->get_billing_first_name()))],
        'ln' => [$hash($norm($order->get_billing_last_name()))],
        'ct' => [$hash(preg_replace('/\s+/', '', $norm($order->get_billing_city())))],
        'zp' => [$hash($norm($order->get_billing_postcode()))],
        'country' => [$hash($norm($order->get_billing_country()))],
        'external_id' => $order->get_customer_id() ? [$hash((string) $order->get_customer_id())] : null,
        'client_ip_address' => $order->get_customer_ip_address() ?: null,
        'client_user_agent' => $order->get_customer_user_agent() ?: null,
        'fbp' => $order->get_meta('_mandala_fbp') ?: null,
        'fbc' => $order->get_meta('_mandala_fbc') ?: null,
    ], fn($v) => $v !== null && $v !== [null]);
    $contents = [];
    foreach ($order->get_items() as $line) {
        $product = $line->get_product();
        $contents[] = ['id' => $product ? ($product->get_sku() ?: (string) $product->get_id()) : (string) $line->get_product_id(), 'quantity' => $line->get_quantity(), 'item_price' => round(((float) $line->get_total() + (float) $line->get_total_tax()) / max(1, $line->get_quantity()))];
    }
    $payload = ['data' => [[
        'event_name' => 'Purchase',
        'event_time' => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time(),
        // Deduplikáció a böngészős pixellel: a GTM Meta címkéjében eventID = "order_" + transaction_id.
        'event_id' => 'order_' . $order->get_id(),
        'action_source' => 'website',
        'event_source_url' => $order->get_checkout_order_received_url(),
        'user_data' => $user,
        'custom_data' => ['currency' => $order->get_currency(), 'value' => (float) $order->get_total(), 'order_id' => (string) $order->get_order_number(), 'content_type' => 'product', 'contents' => $contents, 'num_items' => $order->get_item_count()],
    ]]];
    if ($s['capi_test_code']) {
        $payload['test_event_code'] = $s['capi_test_code'];
    }
    $endpoint = apply_filters('mandala_capi_endpoint', 'https://graph.facebook.com/v21.0/' . rawurlencode($s['pixel_id']) . '/events');
    $response = wp_remote_post(add_query_arg('access_token', rawurlencode($s['capi_token']), $endpoint), ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload)]);
    $code = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
    $log = (array) get_option('mandala_capi_log', []);
    array_unshift($log, ['time' => current_time('mysql'), 'order' => $order->get_id(), 'status' => $code, 'body' => is_wp_error($response) ? '' : mb_substr(wp_remote_retrieve_body($response), 0, 300)]);
    update_option('mandala_capi_log', array_slice($log, 0, 30), false);
    $order->add_order_note('Meta Conversions API (Purchase): ' . (is_int($code) && $code < 300 ? 'elküldve' : 'hiba – ' . $code));
});

/* ---------- Beállítások: WooCommerce → Mandala mérés ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala mérés', 'Mandala mérés', 'manage_woocommerce', 'mandala-analytics', function () {
        if (isset($_POST['mandala_analytics']) && check_admin_referer('mandala_analytics')) {
            $in = array_map('sanitize_text_field', (array) wp_unslash($_POST['mandala_analytics']));
            $clean = [];
            foreach (mandala_analytics_settings() as $key => $value) {
                $clean[$key] = in_array($key, ['consent_default', 'track', 'capi'], true) ? (empty($in[$key]) ? 'no' : 'yes') : trim($in[$key] ?? '');
            }
            update_option('mandala_analytics', $clean, false);
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
        $s = mandala_analytics_settings();
        $box = fn($k, $label, $desc) => '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="mandala_analytics[' . $k . ']" value="1"' . checked($s[$k], 'yes', false) . '> bekapcsolva</label><p class="description">' . esc_html($desc) . '</p></td></tr>';
        $text = fn($k, $label, $desc, $type = 'text') => '<tr><th scope="row"><label for="ma-' . $k . '">' . esc_html($label) . '</label></th><td><input type="' . $type . '" class="regular-text" id="ma-' . $k . '" name="mandala_analytics[' . $k . ']" value="' . esc_attr($s[$k]) . '" autocomplete="off"><p class="description">' . esc_html($desc) . '</p></td></tr>';
        echo '<div class="wrap"><h1>Mandala mérés</h1><form method="post">';
        wp_nonce_field('mandala_analytics');
        echo '<table class="form-table">'
            . $box('consent_default', 'Consent Mode v2 alapállapot', 'A GTM előtt minden mérés „elutasítva”, a süti sáv választása frissíti. Kapcsold ki, ha külön süti-kezelő bővítmény állítja.')
            . $box('track', 'Saját felületek eseményei', 'GA4 események a dataLayer-be: szűrt lista (view_item_list, select_item), termékoldali, ajándékcsomag és viszonteladói kosárba tétel (add_to_cart), keresés, hangtál-választó. A WooCommerce alap eseményeit a GTM4WP adja.' . (defined('GTM4WP_VERSION') ? '' : ' (GTM4WP nem aktív: a termékoldal view_item eseményét is a téma küldi.)'))
            . $box('capi', 'Meta Conversions API', 'Szerveroldali Purchase esemény fizetés / teljesítés után – csak ha a vásárló a pénztárban marketing sütikhez hozzájárult. Deduplikáció: a GTM Meta címkéjében eventID = "order_" + transaction_id.')
            . $text('pixel_id', 'Pixel ID', '')
            . $text('capi_token', 'Hozzáférési token', 'Events Manager → Beállítások → Conversions API.', 'password')
            . $text('capi_test_code', 'Teszt esemény kód', 'Csak teszteléshez (Events Manager → Események tesztelése); élesben üresen.')
            . '</table>';
        submit_button('Mentés');
        $log = (array) get_option('mandala_capi_log', []);
        if (array_filter($log)) {
            echo '</form><h2>Utolsó Conversions API küldések</h2><table class="widefat striped"><thead><tr><th>Idő</th><th>Rendelés</th><th>Válasz</th></tr></thead><tbody>';
            foreach (array_filter($log) as $row) {
                echo '<tr><td>' . esc_html($row['time']) . '</td><td>#' . (int) $row['order'] . '</td><td>' . esc_html($row['status'] . ' ' . $row['body']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '</form></div>';
        }
    });
});
