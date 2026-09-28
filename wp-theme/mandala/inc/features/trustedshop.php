<?php
/**
 * Árukereső Megbízható Bolt program: rendelés után az Árukereső elégedettségi kérdőívet küld a vásárlónak,
 * a bolt pedig értékeléseket és „Megbízható Bolt” jelvényt kap az Árukeresőn (és a saját oldalán).
 *
 * Az Árukereső hivatalos protokollja szerint (TrustedShop 2.0): a köszönőoldalon a szerver tokent kér
 * (WebApiKey + e-mail + termékek), majd a vásárló böngészője az Árukereső szkriptjén keresztül elküldi.
 * A termékazonosító ugyanaz, mint a termékfeedben (cikkszám), így az értékelés a termékhez is kapcsolódik.
 *
 *  - Hozzájárulás: alapból a pénztárban egy (be nem pipált) jelölőnégyzettel kérjük; kikapcsolható, ha az
 *    ÁSZF / adatkezelési tájékoztató már rendezi (az Árukereső ezt is elfogadja).
 *  - Rendelésenként egyszer; hiba esetén a vásárló semmit nem lát, a rendelésnél megjegyzés marad.
 *  - Jelvény: az Árukereső partnerfelületéről kapott widget kód a láblécbe kerül.
 * Beállítás: WooCommerce → Mandala feedek → Árukereső Megbízható Bolt.
 */

defined('ABSPATH') || exit;

const MANDALA_TS_URL = 'https://www.arukereso.hu/';
const MANDALA_TS_AKU = 'https://assets.arukereso.com/aku.min.js';

function mandala_ts_settings(): array
{
    return wp_parse_args((array) get_option('mandala_trustedshop', []), ['key' => '', 'consent' => 'yes', 'widget' => '']);
}

/* ---------- Pénztár: hozzájárulás ---------- */

add_action('woocommerce_review_order_before_submit', function () {
    $s = mandala_ts_settings();
    if ($s['key'] === '' || $s['consent'] !== 'yes') {
        return;
    }
    echo '<p class="form-row ts-consent"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="mandala_ts_ok" value="1"> <span>'
        . esc_html__('Kérem, hogy az Árukereső a vásárlásomról elégedettségi kérdőívet küldjön (ehhez megkapja az e-mail-címemet és a vásárolt termékek nevét).', 'mandala') . '</span></label></p>';
});
add_action('woocommerce_checkout_create_order', function ($order) {
    if (!empty($_POST['mandala_ts_ok'])) { // phpcs:ignore
        $order->update_meta_data('_mandala_ts_ok', 'yes');
    }
});

/* ---------- Köszönőoldal: küldés ---------- */

/** Token kérése az Árukeresőtől (szerverről). Visszaad: a lekérdezés, vagy hibaüzenet. */
function mandala_ts_token(WC_Order $order): array
{
    $s = mandala_ts_settings();
    $products = [];
    foreach ($order->get_items() as $item) {
        $p = $item->get_product();
        $products[] = array_filter(['Name' => $item->get_name(), 'Id' => $p ? ($p->get_sku() ?: 'MND-' . $p->get_id()) : null]);
    }
    $res = wp_remote_post(MANDALA_TS_URL . 't2/TokenRequest.php', ['timeout' => 3, 'body' => [
        'Version' => '2.0/PHP', 'WebApiKey' => $s['key'], 'Email' => $order->get_billing_email(), 'Products' => wp_json_encode($products),
    ]]);
    if (is_wp_error($res)) {
        return ['error' => $res->get_error_message()];
    }
    $json = json_decode((string) wp_remote_retrieve_body($res), true);
    if (wp_remote_retrieve_response_code($res) !== 200 || empty($json['Token'])) {
        return ['error' => trim(($json['ErrorCode'] ?? wp_remote_retrieve_response_code($res)) . ' ' . ($json['ErrorMessage'] ?? ''))];
    }
    return ['query' => '?Token=' . rawurlencode($json['Token']) . '&WebApiKey=' . rawurlencode($s['key']) . '&C='];
}
add_action('woocommerce_thankyou', function ($order_id) {
    $s = mandala_ts_settings();
    $order = wc_get_order($order_id);
    if ($s['key'] === '' || !$order || $order->get_meta('_mandala_ts_sent') || ($s['consent'] === 'yes' && $order->get_meta('_mandala_ts_ok') !== 'yes')) {
        return;
    }
    $order->update_meta_data('_mandala_ts_sent', current_time('mysql'));
    $order->save_meta_data();
    $t = mandala_ts_token($order);
    if (!empty($t['error'])) {
        $order->add_order_note('Árukereső Megbízható Bolt: nem sikerült elküldeni (' . $t['error'] . ').');
        return;
    }
    $src = esc_url_raw(MANDALA_TS_URL . 't2/TrustedShop.php' . $t['query']);
    echo '<script>window.aku_request_done=function(w,c){var i=new Image();i.src=' . wp_json_encode($src) . '+c;};(function(){var a=document.createElement("script");a.async=true;a.src=' . wp_json_encode(MANDALA_TS_AKU) . ';document.head.appendChild(a);})();</script>'
        . '<noscript><img src="' . esc_url($src . md5((string) microtime())) . '" alt="" width="1" height="1"></noscript>'; // phpcs:ignore
    $order->add_order_note('Árukereső Megbízható Bolt: elégedettségi kérdőív kérve.');
}, 5);

/* ---------- Jelvény a láblécben ---------- */

add_action('wp_footer', function () {
    $w = mandala_ts_settings()['widget'];
    if ($w !== '' && !(function_exists('is_checkout') && is_checkout())) {
        echo '<div class="ts-widget">' . $w . '</div>'; // phpcs:ignore -- az admin által beillesztett Árukereső kód
    }
}, 50);

/* ---------- Beállítás (a Mandala feedek oldal alján) ---------- */

add_action('mandala_feeds_admin_after', function () {
    $s = mandala_ts_settings();
    if (!empty($_POST['mandala_ts']) && check_admin_referer('mandala_ts')) {
        $in = (array) wp_unslash($_POST['mandala_ts']);
        $s['key'] = sanitize_text_field((string) ($in['key'] ?? ''));
        $s['consent'] = empty($in['consent']) ? 'no' : 'yes';
        $s['widget'] = current_user_can('unfiltered_html') ? trim((string) ($in['widget'] ?? '')) : wp_kses_post((string) ($in['widget'] ?? ''));
        update_option('mandala_trustedshop', $s, false);
        echo '<div class="notice notice-success"><p>Árukereső Megbízható Bolt: mentve.</p></div>';
    }
    echo '<h2 id="megbizhato-bolt">Árukereső Megbízható Bolt</h2><p>Rendelés után az Árukereső elégedettségi kérdőívet küld a vásárlónak; az értékelésekből „Megbízható Bolt” jelvény lesz. A WebAPI kulcs: Árukereső partnerfelület → Megbízható Bolt / Csatlakozás.</p><form method="post">';
    wp_nonce_field('mandala_ts');
    echo '<table class="form-table"><tr><th>WebAPI kulcs</th><td><input type="text" name="mandala_ts[key]" value="' . esc_attr($s['key']) . '" class="regular-text"><p class="description">Üresen a funkció ki van kapcsolva.</p></td></tr>'
        . '<tr><th>Hozzájárulás</th><td><label><input type="checkbox" name="mandala_ts[consent]" value="1"' . checked($s['consent'], 'yes', false) . '> a pénztárban jelölőnégyzettel kérjük (ajánlott)</label><p class="description">Ha kikapcsolod, minden rendelésnél megy – ekkor az ÁSZF-ben és az adatkezelési tájékoztatóban szerepeljen az adatátadás.</p></td></tr>'
        . '<tr><th>Jelvény (widget) kód</th><td><textarea name="mandala_ts[widget]" rows="4" class="large-text code">' . esc_textarea($s['widget']) . '</textarea><p class="description">Az Árukereső partnerfelületén kapott HTML kód; a láblécben jelenik meg (a pénztárban nem).</p></td></tr></table>';
    submit_button('Mentés');
    echo '</form>';
});
