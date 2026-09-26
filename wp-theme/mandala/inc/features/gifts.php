<?php
/**
 * Ajándék: ajándékutalvány és ajándékcsomag-összeállító.
 *
 * Ajándékutalvány („többcélú utalvány”): a vásárlás nem ÁFA-köteles – az ÁFA a beváltáskor, a
 * megvásárolt termékek után keletkezik. Ezért az utalvány beváltása NEM kedvezmény (nem csökkenti
 * a termékek ÁFA-alapját), hanem fizetőeszköz: a fizetendő végösszeget csökkenti. Az utalványnak
 * egyenlege van, részletekben is beváltható. Adminban: WooCommerce → Ajándékutalványok.
 *
 * Ajándékcsomag: a vásárló kiválaszt néhány terméket, egy csomagolást és a kártya szövegét; a
 * tételek egy csomagként kerülnek a kosárba és a rendelésbe (a csomagolásnál a kártya szövegével).
 */

defined('ABSPATH') || exit;

function mandala_gift_settings(): array
{
    return wp_parse_args((array) get_option('mandala_gifts', []), [
        'voucher_amounts' => '5000,10000,15000,20000,30000',
        'voucher_months' => 12,
        'loyalty' => 'yes',
        'earn_per' => 100,      // ennyi Ft vásárlás = 1 pont
        'point_value' => 1,     // 1 pont = ennyi Ft kedvezmény
        'min_redeem' => 500,    // legalább ennyi pont váltható be
        'max_pct' => 50,        // a termékek árának legfeljebb ennyi %-a fizethető pontokkal
        'gift_max' => 6,        // ennyi termék fér egy ajándékcsomagba
    ]);
}

function mandala_voucher_amounts(): array
{
    $amounts = array_filter(array_map('absint', explode(',', (string) mandala_gift_settings()['voucher_amounts'])));
    sort($amounts);
    return $amounts ?: [10000];
}

add_filter('mandala_product_meta_fields', function ($fields) {
    return $fields + [
        '_mandala_voucher' => ['Ajándékutalvány', 'checkbox', 'A termék ajándékutalvány: a vásárló választja az összeget, fizetés után egyedi kódot kap e-mailben.'],
        '_mandala_giftbox' => ['Ajándékcsomagolás', 'checkbox', 'A termék csomagolásként választható az ajándékcsomag-összeállítóban (általában rejtett termék).'],
    ];
});

function mandala_is_voucher($product): bool
{
    return $product instanceof WC_Product && $product->get_meta('_mandala_voucher', true, 'edit') === 'yes';
}

/* ---------- Utalvány (CPT) ---------- */

add_action('init', function () {
    register_post_type('mandala_voucher', [
        'labels' => ['name' => 'Ajándékutalványok', 'singular_name' => 'Ajándékutalvány', 'add_new_item' => 'Új utalvány', 'edit_item' => 'Utalvány', 'search_items' => 'Kód keresése'],
        'public' => false, 'show_ui' => true, 'show_in_menu' => 'woocommerce', 'supports' => ['title'],
        'capability_type' => 'shop_coupon', 'map_meta_cap' => true,
    ]);
});

function mandala_voucher_code(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = 'MND';
        for ($g = 0; $g < 2; $g++) {
            $code .= '-';
            for ($i = 0; $i < 4; $i++) {
                $code .= $abc[random_int(0, strlen($abc) - 1)];
            }
        }
    } while (mandala_voucher_find($code));
    return $code;
}

function mandala_voucher_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $code));
}

function mandala_voucher_find(string $code): ?WP_Post
{
    $code = mandala_voucher_normalize($code);
    if (!preg_match('/^MND-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
        return null;
    }
    $posts = get_posts(['post_type' => 'mandala_voucher', 'post_status' => 'publish', 'title' => $code, 'numberposts' => 1]);
    return $posts[0] ?? null;
}

/** Beváltható egyenleg (lejárt vagy üres utalványnál 0). */
function mandala_voucher_balance(WP_Post $voucher): int
{
    $expires = (string) get_post_meta($voucher->ID, '_expires', true);
    if ($expires && $expires < wp_date('Y-m-d')) {
        return 0;
    }
    return max(0, (int) get_post_meta($voucher->ID, '_balance', true));
}

function mandala_voucher_create(int $amount, array $meta = []): WP_Post
{
    $months = max(1, (int) mandala_gift_settings()['voucher_months']);
    $id = wp_insert_post(['post_type' => 'mandala_voucher', 'post_status' => 'publish', 'post_title' => mandala_voucher_code()]);
    update_post_meta($id, '_value', $amount);
    update_post_meta($id, '_balance', $amount);
    update_post_meta($id, '_expires', wp_date('Y-m-d', strtotime('+' . $months . ' months')));
    foreach ($meta as $key => $value) {
        update_post_meta($id, $key, $value);
    }
    return get_post($id);
}

/** Egyenleg módosítása naplózva (negatív: beváltás, pozitív: visszaírás). */
function mandala_voucher_adjust(WP_Post $voucher, int $delta, string $note): void
{
    $balance = (int) get_post_meta($voucher->ID, '_balance', true) + $delta;
    update_post_meta($voucher->ID, '_balance', max(0, $balance));
    $log = (array) get_post_meta($voucher->ID, '_log', true);
    $log[] = [current_time('mysql'), $delta, $note];
    update_post_meta($voucher->ID, '_log', $log);
}

/* Admin: oszlopok és adatdoboz. */
add_filter('manage_mandala_voucher_posts_columns', fn($c) => ['cb' => $c['cb'], 'title' => 'Kód', 'value' => 'Érték', 'balance' => 'Egyenleg', 'expires' => 'Lejárat', 'order' => 'Rendelés', 'date' => $c['date']]);
add_action('manage_mandala_voucher_posts_custom_column', function ($col, $id) {
    if ($col === 'value' || $col === 'balance') {
        echo esc_html(mandala_fmt((int) get_post_meta($id, '_' . $col, true)));
    } elseif ($col === 'expires') {
        echo esc_html((string) get_post_meta($id, '_expires', true));
    } elseif ($col === 'order' && ($order = (int) get_post_meta($id, '_order', true))) {
        echo '<a href="' . esc_url(admin_url('post.php?post=' . $order . '&action=edit')) . '">#' . (int) $order . '</a>';
    }
}, 10, 2);
add_action('add_meta_boxes_mandala_voucher', function () {
    add_meta_box('mandala_voucher', 'Utalvány adatai', function (WP_Post $post) {
        wp_nonce_field('mandala_voucher', 'mandala_voucher_nonce');
        $new = $post->post_status === 'auto-draft';
        echo '<p><label>Érték (Ft)<br><input type="number" min="0" name="mandala_voucher[value]" value="' . esc_attr((string) get_post_meta($post->ID, '_value', true)) . '"' . ($new ? '' : ' readonly') . '></label></p>';
        echo '<p><label>Egyenleg (Ft)<br><input type="number" min="0" name="mandala_voucher[balance]" value="' . esc_attr((string) get_post_meta($post->ID, '_balance', true)) . '"></label></p>';
        echo '<p><label>Lejárat<br><input type="date" name="mandala_voucher[expires]" value="' . esc_attr((string) get_post_meta($post->ID, '_expires', true)) . '"></label></p>';
        if ($new) {
            echo '<p class="description">A kód mentéskor automatikusan készül (pl. bolti eladáshoz, kárpótláshoz).</p>';
        }
        $log = (array) get_post_meta($post->ID, '_log', true);
        if (array_filter($log)) {
            echo '<h4>Napló</h4><ul>';
            foreach (array_filter($log) as [$date, $delta, $note]) {
                echo '<li>' . esc_html($date . ' · ' . ($delta > 0 ? '+' : '') . mandala_fmt($delta) . ' · ' . $note) . '</li>';
            }
            echo '</ul>';
        }
    }, null, 'normal');
});
add_action('save_post_mandala_voucher', function ($post_id, $post) {
    if (!isset($_POST['mandala_voucher_nonce']) || !wp_verify_nonce(sanitize_key($_POST['mandala_voucher_nonce']), 'mandala_voucher') || !current_user_can('edit_post', $post_id)) {
        return;
    }
    $in = array_map('sanitize_text_field', (array) wp_unslash($_POST['mandala_voucher'] ?? []));
    if (!preg_match('/^MND-/', $post->post_title)) {
        remove_all_actions('save_post_mandala_voucher');
        wp_update_post(['ID' => $post_id, 'post_title' => mandala_voucher_code()]);
    }
    if (!get_post_meta($post_id, '_value', true)) {
        update_post_meta($post_id, '_value', absint($in['value'] ?? 0));
    }
    $old = (int) get_post_meta($post_id, '_balance', true);
    $balance = absint($in['balance'] ?? $old);
    if ($balance !== $old || !metadata_exists('post', $post_id, '_balance')) {
        mandala_voucher_adjust(get_post($post_id), $balance - $old, 'Kézi módosítás: ' . wp_get_current_user()->user_login);
    }
    update_post_meta($post_id, '_expires', preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['expires'] ?? '') ? $in['expires'] : wp_date('Y-m-d', strtotime('+12 months')));
}, 10, 2);

/* ---------- Utalvány vásárlása ---------- */

/** A termékoldali kosár űrlapba: összeg, címzett, üzenet. */
add_action('mandala_cart_form_fields', function (WC_Product $product) {
    if (!mandala_is_voucher($product)) {
        return;
    }
    $amounts = mandala_voucher_amounts();
    $chosen = absint($_POST['mandala_voucher_amount'] ?? $amounts[min(1, count($amounts) - 1)]); // phpcs:ignore WordPress.Security.NonceVerification
    echo '<fieldset class="voucher-amounts"><legend>' . esc_html__('Összeg', 'mandala') . '</legend><div class="chip-row">';
    foreach ($amounts as $a) {
        echo '<label class="chip"><input type="radio" name="mandala_voucher_amount" value="' . (int) $a . '"' . checked($a, $chosen, false) . '><span>' . esc_html(mandala_fmt($a)) . '</span></label>';
    }
    echo '</div></fieldset><div class="voucher-fields">'
        . '<div class="iu-form-field"><label for="voucher-to-name">' . esc_html__('Kinek szól? (a kártyára kerül)', 'mandala') . '</label><input type="text" class="input-text" id="voucher-to-name" name="mandala_voucher_to_name" maxlength="60" autocomplete="off"></div>'
        . '<div class="iu-form-field"><label for="voucher-to-email">' . esc_html__('Címzett e-mail-címe (nem kötelező)', 'mandala') . '</label><input type="email" class="input-text" id="voucher-to-email" name="mandala_voucher_to_email" autocomplete="off" aria-describedby="voucher-to-email-help"><p class="text-small text-muted" id="voucher-to-email-help" style="margin:var(--space-2) 0 0">' . esc_html__('Ha megadod, fizetés után neki is elküldjük. Az utalványt mindenképp megkapod te is, nyomtatható formában.', 'mandala') . '</p></div>'
        . '<div class="iu-form-field"><label for="voucher-message">' . esc_html__('Üzenet (nem kötelező)', 'mandala') . '</label><textarea class="input-text" id="voucher-message" name="mandala_voucher_message" rows="3" maxlength="240"></textarea></div>'
        . '</div><p class="text-small text-muted">' . esc_html(sprintf(__('Érvényes %d hónapig, a teljes kínálatra, részletekben is beváltható. Az utalvány vásárlása nem ÁFA-köteles; az ÁFA a beváltáskor, a megvásárolt termékek után keletkezik.', 'mandala'), (int) mandala_gift_settings()['voucher_months'])) . '</p>';
});

add_filter('mandala_tax_note', fn($note, $product) => mandala_is_voucher($product) ? __('Többcélú utalvány – az ÁFA a beváltáskor keletkezik', 'mandala') : $note, 10, 2);
add_filter('mandala_cart_form_native', fn($native, $product) => $native || mandala_is_voucher($product), 10, 2);
add_filter('mandala_cart_show_qty', fn($show, $product) => $show && !mandala_is_voucher($product), 10, 2);
add_filter('mandala_add_to_cart_label', fn($label, $product) => mandala_is_voucher($product) ? __('Utalvány a kosárba', 'mandala') : $label, 10, 2);
add_filter('mandala_stock_html', fn($html, $product) => mandala_is_voucher($product) ? '<p class="stock in-stock">' . esc_html__('Fizetés után azonnal, e-mailben', 'mandala') . '</p>' : $html, 20, 2);
add_filter('woocommerce_get_price_html', function ($html, $product) {
    if (!mandala_is_voucher($product)) {
        return $html;
    }
    $a = mandala_voucher_amounts();
    return count($a) > 1 ? wc_price(reset($a)) . ' – ' . wc_price(end($a)) : wc_price($a[0]);
}, 20, 2);
/** Az utalvány árát nem terheli ÁFA, és a kártyán nincs „kosárba” gomb (összeget kell választani). */
add_filter('woocommerce_product_get_tax_status', fn($status, $product) => mandala_is_voucher($product) ? 'none' : $status, 10, 2);
add_filter('mandala_product_index_row', function ($row, WC_Product $product) {
    if (mandala_is_voucher($product)) {
        $row['buyable'] = false;
        $row['addUrl'] = '';
        $row['voucher'] = true;
    }
    return $row;
}, 10, 2);

add_filter('woocommerce_add_to_cart_validation', function ($ok, $product_id) {
    $product = wc_get_product($product_id);
    if (!mandala_is_voucher($product)) {
        return $ok;
    }
    $amount = absint($_POST['mandala_voucher_amount'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
    if (!in_array($amount, mandala_voucher_amounts(), true)) {
        wc_add_notice(__('Válaszd ki az utalvány összegét.', 'mandala'), 'error');
        return false;
    }
    $email = sanitize_email(wp_unslash($_POST['mandala_voucher_to_email'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    if (!empty($_POST['mandala_voucher_to_email']) && !is_email($email)) { // phpcs:ignore WordPress.Security.NonceVerification
        wc_add_notice(__('A címzett e-mail-címe nem tűnik érvényesnek.', 'mandala'), 'error');
        return false;
    }
    return $ok;
}, 10, 2);

add_filter('woocommerce_add_cart_item_data', function ($data, $product_id) {
    if (mandala_is_voucher(wc_get_product($product_id)) && isset($_POST['mandala_voucher_amount'])) { // phpcs:ignore WordPress.Security.NonceVerification
        $data['mandala_voucher'] = [
            'amount' => absint($_POST['mandala_voucher_amount']), // phpcs:ignore
            'to_name' => sanitize_text_field(wp_unslash($_POST['mandala_voucher_to_name'] ?? '')), // phpcs:ignore
            'to_email' => sanitize_email(wp_unslash($_POST['mandala_voucher_to_email'] ?? '')), // phpcs:ignore
            'message' => sanitize_textarea_field(wp_unslash($_POST['mandala_voucher_message'] ?? '')), // phpcs:ignore
        ];
        $data['unique_key'] = md5(wp_json_encode($data['mandala_voucher']) . microtime());
    }
    return $data;
}, 10, 2);
add_filter('woocommerce_add_to_cart_redirect', function ($url, $product = null) {
    return $product instanceof WC_Product && mandala_is_voucher($product) ? wc_get_cart_url() : $url;
}, 10, 2);

add_action('woocommerce_before_calculate_totals', function (WC_Cart $cart) {
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['mandala_voucher']['amount'])) {
            $item['data']->set_price((float) $item['mandala_voucher']['amount']);
        }
    }
}, 5);

add_filter('woocommerce_get_item_data', function ($rows, $item) {
    if (!empty($item['mandala_voucher'])) {
        $v = $item['mandala_voucher'];
        if ($v['to_name']) {
            $rows[] = ['key' => __('Címzett', 'mandala'), 'value' => esc_html($v['to_name'])];
        }
        if ($v['to_email']) {
            $rows[] = ['key' => __('Küldés', 'mandala'), 'value' => esc_html($v['to_email'])];
        }
    }
    if (!empty($item['mandala_gift'])) {
        $rows[] = ['key' => __('Ajándékcsomag', 'mandala'), 'value' => esc_html(sprintf(__('%d. csomag', 'mandala'), (int) $item['mandala_gift']['n']))];
        if (!empty($item['mandala_gift']['message'])) {
            $rows[] = ['key' => __('Kártya', 'mandala'), 'value' => esc_html($item['mandala_gift']['message'])];
        }
    }
    return $rows;
}, 10, 2);

add_action('woocommerce_checkout_create_order_line_item', function (WC_Order_Item_Product $line, $key, $values) {
    if (!empty($values['mandala_voucher'])) {
        $v = $values['mandala_voucher'];
        $line->add_meta_data('_mandala_voucher', $v, true);
        if ($v['to_name']) {
            $line->add_meta_data(__('Címzett', 'mandala'), $v['to_name'], true);
        }
    }
    if (!empty($values['mandala_gift'])) {
        $line->add_meta_data(__('Ajándékcsomag', 'mandala'), sprintf(__('%d. csomag', 'mandala'), (int) $values['mandala_gift']['n']), true);
        $line->add_meta_data('_mandala_gift', $values['mandala_gift']['id'], true);
        if (!empty($values['mandala_gift']['message'])) {
            $line->add_meta_data(__('Kártya szövege', 'mandala'), $values['mandala_gift']['message'], true);
        }
    }
}, 10, 3);

/** Kiállítás fizetéskor (kártya) vagy teljesítéskor (utánvét); egyszer. */
function mandala_issue_vouchers($order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    do_action('mandala_before_order_mail', $order);
    foreach ($order->get_items() as $line) {
        $v = $line->get_meta('_mandala_voucher');
        if (!is_array($v) || $line->get_meta('_mandala_voucher_codes')) {
            continue;
        }
        $codes = [];
        for ($i = 0; $i < $line->get_quantity(); $i++) {
            $voucher = mandala_voucher_create((int) $v['amount'], ['_order' => $order->get_id()]);
            mandala_voucher_adjust($voucher, 0, sprintf('Kiállítva, rendelés #%d', $order->get_id()));
            $codes[] = $voucher->post_title;
            mandala_mail_voucher($voucher, $order, $v);
        }
        $line->update_meta_data('_mandala_voucher_codes', $codes);
        $line->update_meta_data(__('Utalványkód', 'mandala'), implode(', ', $codes));
        $line->save();
        $order->add_order_note(sprintf('Ajándékutalvány kiállítva: %s (%s)', implode(', ', $codes), mandala_fmt((int) $v['amount'])));
    }
}
add_action('woocommerce_payment_complete', 'mandala_issue_vouchers');
add_action('woocommerce_order_status_completed', 'mandala_issue_vouchers');

function mandala_voucher_print_url(WP_Post $voucher): string
{
    return add_query_arg(['mandala_voucher' => $voucher->post_title, 'vk' => mandala_token('voucher', $voucher->post_title)], home_url('/'));
}

function mandala_mail_voucher(WP_Post $voucher, WC_Order $order, array $v): void
{
    $amount = (int) get_post_meta($voucher->ID, '_value', true);
    $expires = wp_date('Y. F j.', strtotime((string) get_post_meta($voucher->ID, '_expires', true)));
    $card = '<table role="presentation" style="width:100%;border-collapse:collapse;background:#F6F1E8;border-radius:12px;margin:16px 0"><tr><td style="padding:24px;text-align:center">'
        . '<p style="margin:0;color:#6E6357;font-size:13px;letter-spacing:.08em;text-transform:uppercase">' . esc_html__('Ajándékutalvány', 'mandala') . '</p>'
        . '<p style="margin:8px 0;font-size:32px;font-weight:600;color:#1C1916">' . esc_html(mandala_fmt($amount)) . '</p>'
        . '<p style="margin:0;font-family:monospace;font-size:20px;letter-spacing:.12em;color:#1C1916">' . esc_html($voucher->post_title) . '</p>'
        . '<p style="margin:8px 0 0;color:#6E6357;font-size:13px">' . esc_html(sprintf(__('Beváltható %s-ig a pénztárban, a kuponkód mezőben.', 'mandala'), $expires)) . '</p></td></tr></table>';
    $message = $v['message'] ? '<blockquote style="margin:16px 0;padding:0 16px;border-left:3px solid #B5651D;font-style:italic">' . nl2br(esc_html($v['message'])) . '</blockquote>' : '';
    $buttons = mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala')) . '<p><a href="' . esc_url(mandala_voucher_print_url($voucher)) . '">' . esc_html__('Nyomtatható változat', 'mandala') . '</a></p>';

    $buyer = $order->get_billing_first_name();
    mandala_send_mail($order->get_billing_email(), __('Az ajándékutalványod', 'mandala'), __('Itt az ajándékutalvány', 'mandala'),
        '<p>' . sprintf(esc_html__('Kedves %s!', 'mandala'), esc_html($buyer)) . '</p><p>' . esc_html($v['to_email'] ? sprintf(__('Köszönjük! Az utalványt elküldtük %s részére is. Itt a másolat, ha kinyomtatnád:', 'mandala'), $v['to_email']) : __('Köszönjük! Itt az utalvány – továbbküldheted vagy kinyomtathatod.', 'mandala')) . '</p>' . $card . $message . $buttons);
    if ($v['to_email'] && strtolower($v['to_email']) !== strtolower($order->get_billing_email())) {
        $hello = $v['to_name'] ? sprintf(__('Kedves %s!', 'mandala'), $v['to_name']) : __('Kedves Címzett!', 'mandala');
        mandala_send_mail($v['to_email'], sprintf(__('Ajándékot kaptál %s-tól', 'mandala'), trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())), __('Ajándékot kaptál', 'mandala'),
            '<p>' . esc_html($hello) . '</p><p>' . esc_html(sprintf(__('%s ajándékutalványt küldött neked a Mandala webáruházba.', 'mandala'), $buyer)) . '</p>' . $card . $message . $buttons);
    }
}

/** Nyomtatható utalvány (a levélben lévő, aláírt linkről). */
add_action('template_redirect', function () {
    if (empty($_GET['mandala_voucher']) || empty($_GET['vk'])) {
        return;
    }
    $code = mandala_voucher_normalize(wp_unslash($_GET['mandala_voucher']));
    $voucher = mandala_voucher_find($code);
    if (!$voucher || !hash_equals(mandala_token('voucher', $code), sanitize_text_field(wp_unslash($_GET['vk'])))) {
        wp_die(esc_html__('Érvénytelen utalvány link.', 'mandala'), '', ['response' => 404]);
    }
    $amount = (int) get_post_meta($voucher->ID, '_value', true);
    $expires = wp_date('Y. F j.', strtotime((string) get_post_meta($voucher->ID, '_expires', true)));
    $order = wc_get_order((int) get_post_meta($voucher->ID, '_order', true));
    $v = [];
    foreach ($order ? $order->get_items() : [] as $line) {
        if (in_array($code, (array) $line->get_meta('_mandala_voucher_codes'), true)) {
            $v = (array) $line->get_meta('_mandala_voucher');
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    nocache_headers();
    ?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?php esc_html_e('Ajándékutalvány', 'mandala'); ?></title>
<style>body{margin:0;font:16px/1.5 Georgia,serif;color:#1C1916;background:#EEE7DB;display:grid;place-items:center;min-height:100vh}.v{background:#FBF8F3;width:min(640px,92vw);padding:48px;border-radius:16px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.08)}.e{font:600 12px/1 system-ui,sans-serif;letter-spacing:.16em;text-transform:uppercase;color:#8A4B14}.a{font-size:56px;margin:16px 0 8px}.c{font:600 24px/1 ui-monospace,monospace;letter-spacing:.14em;border:1px dashed #B5651D;display:inline-block;padding:12px 20px;border-radius:8px}.m{font-style:italic;margin:24px auto;max-width:44ch}.s{font:13px/1.5 system-ui,sans-serif;color:#6E6357;margin-top:24px}button{margin-top:24px;font:600 14px system-ui,sans-serif;padding:12px 24px;border-radius:999px;border:0;background:#1C1916;color:#fff;cursor:pointer}@media print{body{background:#fff}.v{box-shadow:none}button{display:none}}</style></head>
<body><main class="v"><p class="e"><?php echo esc_html(get_bloginfo('name')); ?> · <?php esc_html_e('Ajándékutalvány', 'mandala'); ?></p>
<?php if (!empty($v['to_name'])) : ?><p><?php echo esc_html(sprintf(__('%s részére', 'mandala'), $v['to_name'])); ?></p><?php endif; ?>
<p class="a"><?php echo esc_html(mandala_fmt($amount)); ?></p><p class="c"><?php echo esc_html($code); ?></p>
<?php if (!empty($v['message'])) : ?><p class="m"><?php echo nl2br(esc_html($v['message'])); ?></p><?php endif; ?>
<p class="s"><?php echo esc_html(sprintf(__('Beváltható %1$s-ig a %2$s webáruházban: a pénztárban add meg a kódot a kuponkód mezőben. Részletekben is felhasználható.', 'mandala'), $expires, wp_parse_url(home_url(), PHP_URL_HOST))); ?></p>
<button type="button" onclick="print()"><?php esc_html_e('Nyomtatás', 'mandala'); ?></button></main></body></html><?php
    exit;
});

/* ---------- Utalvány beváltása (fizetőeszközként) ---------- */

function mandala_session_vouchers(): array
{
    return function_exists('WC') && WC()->session ? array_values(array_filter((array) WC()->session->get('mandala_vouchers', []))) : [];
}

/**
 * A kuponmező az utalványkódot is elfogadja: a WooCommerce apply_coupon kezelője előtt
 * (a pénztár AJAX-a és a kosároldal űrlapja is) ha a kód utalvány, itt kezeljük.
 */
function mandala_try_apply_voucher(string $code): ?bool
{
    $code = mandala_voucher_normalize($code);
    if (!str_starts_with($code, 'MND-')) {
        return null; // nem utalvány → marad a WooCommerce kupon
    }
    $voucher = mandala_voucher_find($code);
    if (!$voucher) {
        wc_add_notice(__('Ezt az utalványkódot nem találjuk. Ellenőrizd a betűket (a kód MND-XXXX-XXXX formájú).', 'mandala'), 'error');
        return false;
    }
    $balance = mandala_voucher_balance($voucher);
    if ($balance <= 0) {
        wc_add_notice(__('Ennek az utalványnak nincs felhasználható egyenlege, vagy lejárt.', 'mandala'), 'error');
        return false;
    }
    $codes = mandala_session_vouchers();
    if (in_array($code, $codes, true)) {
        wc_add_notice(__('Ezt az utalványt már beváltottad ennél a rendelésnél.', 'mandala'), 'error');
        return false;
    }
    $codes[] = $code;
    WC()->session->set('mandala_vouchers', $codes);
    wc_add_notice(sprintf(__('Utalvány beváltva – egyenleg: %s.', 'mandala'), mandala_fmt($balance)));
    return true;
}
add_action('wc_ajax_apply_coupon', function () {
    check_ajax_referer('apply-coupon', 'security');
    $code = wc_format_coupon_code(wp_unslash($_POST['coupon_code'] ?? '')); // phpcs:ignore
    if (mandala_try_apply_voucher((string) $code) !== null) {
        wc_print_notices();
        wp_die();
    }
}, 1);
add_action('wp_loaded', function () {
    if (!empty($_POST['apply_coupon']) && !empty($_POST['coupon_code']) && function_exists('WC') && WC()->session) { // phpcs:ignore WordPress.Security.NonceVerification
        if (mandala_try_apply_voucher(sanitize_text_field(wp_unslash($_POST['coupon_code']))) !== null) { // phpcs:ignore
            unset($_POST['apply_coupon']);
        }
    }
    if (!empty($_GET['mandala_voucher_remove']) && function_exists('WC') && WC()->session) {
        $code = mandala_voucher_normalize(wp_unslash($_GET['mandala_voucher_remove']));
        WC()->session->set('mandala_vouchers', array_values(array_diff(mandala_session_vouchers(), [$code])));
        wp_safe_redirect(remove_query_arg('mandala_voucher_remove'));
        exit;
    }
}, 19);

/** Mennyit fedez az utalvány: a végösszegből, az utalvány-termékek nélkül (utalvánnyal utalvány nem vehető). */
function mandala_voucher_allocation(WC_Cart $cart, float $total): array
{
    $payable = $total;
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['mandala_voucher'])) {
            $payable -= (float) $item['line_total'] + (float) $item['line_tax'];
        }
    }
    $use = [];
    foreach (mandala_session_vouchers() as $code) {
        $voucher = mandala_voucher_find($code);
        $amount = $voucher ? min(mandala_voucher_balance($voucher), max(0, floor($payable))) : 0;
        if ($amount > 0) {
            $use[$code] = (int) $amount;
            $payable -= $amount;
        }
    }
    return $use;
}

add_filter('woocommerce_calculated_total', function ($total, WC_Cart $cart) {
    $use = mandala_voucher_allocation($cart, (float) $total);
    if (WC()->session) {
        WC()->session->set('mandala_voucher_use', $use);
    }
    return max(0, $total - array_sum($use));
}, 50, 2);

function mandala_voucher_rows(): array
{
    return WC()->session ? (array) WC()->session->get('mandala_voucher_use', []) : [];
}

/** Összesítő sor(ok) a pénztárban és a kosárban. */
function mandala_voucher_total_rows(string $base): void
{
    $removable = true;
    foreach (mandala_voucher_rows() as $code => $amount) {
        echo '<tr class="voucher"><th>' . esc_html(sprintf(__('Ajándékutalvány (%s)', 'mandala'), $code))
            . ($removable ? ' <a class="text-small" href="' . esc_url(add_query_arg('mandala_voucher_remove', rawurlencode($code), $base)) . '">' . esc_html__('eltávolítás', 'mandala') . '</a>' : '')
            . '</th><td>−' . esc_html(mandala_fmt($amount)) . '</td></tr>';
    }
}
add_action('woocommerce_review_order_before_order_total', fn() => mandala_voucher_total_rows(wc_get_checkout_url()));
add_action('woocommerce_cart_totals_before_order_total', fn() => mandala_voucher_total_rows(wc_get_cart_url()));

/** A rendelés: felhasznált összegek levonása (a rendelés létrejöttekor, hogy ne lehessen kétszer költeni). */
add_action('woocommerce_checkout_order_created', function (WC_Order $order) {
    $use = mandala_voucher_rows();
    if (!$use) {
        return;
    }
    $order->update_meta_data('_mandala_vouchers', $use);
    foreach ($use as $code => $amount) {
        if ($voucher = mandala_voucher_find($code)) {
            mandala_voucher_adjust($voucher, -$amount, sprintf('Beváltás, rendelés #%d', $order->get_id()));
        }
    }
    $order->add_order_note('Ajándékutalvánnyal fizetve: ' . implode(', ', array_map(fn($c, $a) => $c . ' (' . mandala_fmt($a) . ')', array_keys($use), $use)) . '. A számlán fizetési módként szerepeljen, nem kedvezményként.');
    $order->save();
    WC()->session->set('mandala_vouchers', []);
    WC()->session->set('mandala_voucher_use', []);
});

/** A rendelésnél utalvánnyal fizetett összegek: kód => Ft. */
function mandala_order_vouchers($order): array
{
    $use = $order instanceof WC_Order ? $order->get_meta('_mandala_vouchers') : [];
    return is_array($use) ? array_map('intval', $use) : [];
}

/** Lemondott / sikertelen / visszatérített rendelésnél az utalvány egyenlege visszaíródik; újra fizetve újra levonódik. */
add_action('woocommerce_order_status_changed', function ($order_id, $from, $to) {
    $order = wc_get_order($order_id);
    $use = $order ? mandala_order_vouchers($order) : [];
    if (!$use) {
        return;
    }
    $returned = $order->get_meta('_mandala_vouchers_returned') === 'yes';
    if (!$returned && in_array($to, ['cancelled', 'failed', 'refunded'], true)) {
        foreach ($use as $code => $amount) {
            if ($voucher = mandala_voucher_find($code)) {
                mandala_voucher_adjust($voucher, (int) $amount, sprintf('Visszaírás, rendelés #%d (%s)', $order_id, $to));
            }
        }
        $order->update_meta_data('_mandala_vouchers_returned', 'yes');
        $order->save();
    } elseif ($returned && in_array($to, ['pending', 'on-hold', 'processing', 'completed'], true)) {
        foreach ($use as $code => $amount) {
            if ($voucher = mandala_voucher_find($code)) {
                mandala_voucher_adjust($voucher, -min((int) $amount, (int) get_post_meta($voucher->ID, '_balance', true)), sprintf('Újra levonva, rendelés #%d', $order_id));
            }
        }
        $order->update_meta_data('_mandala_vouchers_returned', '');
        $order->save();
    }
}, 10, 3);

/** Adminban az újraszámolás se tüntesse el az utalvánnyal fizetett részt. */
add_action('woocommerce_order_after_calculate_totals', function ($and_taxes, $order) {
    $use = mandala_order_vouchers($order);
    if ($use) {
        $order->set_total(max(0, (float) $order->get_total() - array_sum($use)));
    }
}, 10, 2);

/** Levelekben, köszönőoldalon, fiókban: utalvány sor a végösszeg előtt. */
add_filter('woocommerce_get_order_item_totals', function ($rows, WC_Order $order) {
    $use = mandala_order_vouchers($order);
    if (!$use) {
        return $rows;
    }
    $new = [];
    foreach ($rows as $key => $row) {
        if ($key === 'order_total') {
            foreach ($use as $code => $amount) {
                $new['voucher_' . $code] = ['label' => sprintf(__('Ajándékutalvány (%s):', 'mandala'), $code), 'value' => '−' . wc_price($amount)];
            }
        }
        $new[$key] = $row;
    }
    return $new;
}, 10, 2);
add_action('woocommerce_admin_order_totals_after_discount', function ($order_id) {
    foreach (mandala_order_vouchers(wc_get_order($order_id)) as $code => $amount) {
        echo '<tr><td class="label">' . esc_html('Ajándékutalvány (' . $code . '):') . '</td><td width="1%"></td><td class="total">−' . wp_kses_post(wc_price($amount)) . '</td></tr>';
    }
});

/* ---------- Ajándékcsomag-összeállító ---------- */

/** Csomagolásként választható termékek. */
function mandala_giftboxes(): array
{
    $boxes = function_exists('wc_get_products') ? wc_get_products(['status' => 'publish', 'limit' => 10, 'meta_key' => '_mandala_giftbox', 'meta_value' => 'yes']) : [];
    usort($boxes, fn($a, $b) => (float) $a->get_price() <=> (float) $b->get_price());
    return $boxes;
}

/** A csomagba tehető termékek: az „Ajándék” szándékhoz sorolt, raktáron lévő egyszerű termékek. */
function mandala_gift_candidates(): array
{
    $rows = array_filter(mandala_product_index(), fn($p) => !empty($p['buyable']) && empty($p['voucher']) && in_array('ajandek', (array) ($p['intents'] ?? []), true));
    usort($rows, fn($a, $b) => $a['price'] <=> $b['price']);
    return array_values($rows);
}

add_action('wc_ajax_mandala_gift_add', function () {
    check_ajax_referer('mandala-cart', 'security');
    $max = (int) mandala_gift_settings()['gift_max'];
    $items = array_slice(array_unique(array_map('absint', (array) ($_POST['items'] ?? []))), 0, $max);
    $box = absint($_POST['box'] ?? 0);
    $message = mb_substr(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')), 0, 200);
    $allowed = array_column(mandala_gift_candidates(), 'id');
    $items = array_values(array_intersect($items, $allowed));
    $box_product = $box ? wc_get_product($box) : null;
    $fail = fn($msg) => wp_send_json(['ok' => false, 'error' => $msg]);
    if (count($items) < 2) {
        $fail(__('Válassz legalább két terméket a csomagba.', 'mandala'));
    }
    if (!$box_product || $box_product->get_meta('_mandala_giftbox') !== 'yes' || !$box_product->is_purchasable()) {
        $fail(__('Válassz csomagolást.', 'mandala'));
    }
    $n = (int) WC()->session->get('mandala_gift_n', 0) + 1;
    $gift = ['id' => wp_generate_uuid4(), 'n' => $n, 'message' => ''];
    $added = [];
    foreach ($items as $pid) {
        $key = WC()->cart->add_to_cart($pid, 1, 0, [], ['mandala_gift' => $gift]);
        if (!$key) {
            foreach ($added as $k) {
                WC()->cart->remove_cart_item($k);
            }
            wc_clear_notices();
            $fail(sprintf(__('A(z) „%s” most nem tehető a kosárba – válassz helyette mást.', 'mandala'), get_the_title($pid)));
        }
        $added[] = $key;
    }
    WC()->cart->add_to_cart($box, 1, 0, [], ['mandala_gift' => ['message' => $message] + $gift]);
    WC()->session->set('mandala_gift_n', $n);
    wc_clear_notices();
    wp_send_json(['ok' => true, 'cart' => wc_get_cart_url(), 'count' => WC()->cart->get_cart_contents_count()]);
});

/** Ajándékcsomag tételei: mennyiségük a kosárban nem változtatható (egy csomag = egy darab mindenből). */
add_filter('woocommerce_cart_item_quantity', fn($html, $key, $item) => !empty($item['mandala_gift']) ? '<span class="gift-qty">1</span>' : $html, 10, 3);

add_action('init', function () {
    if (!function_exists('mandala_add_block')) {
        return;
    }
    mandala_add_block('mandala/gift-builder', [
        'title' => 'Ajándékcsomag-összeállító',
        'category' => 'iu-woocommerce',
        'template' => function () {
            $candidates = mandala_gift_candidates();
            $boxes = mandala_giftboxes();
            if (count($candidates) < 2 || !$boxes) {
                return current_user_can('manage_woocommerce') ? '<p class="notice">Az összeállítóhoz legalább két „Ajándék” szándékú termék és egy csomagolás termék kell (Mandala adatok → Ajándékcsomagolás).</p>' : '';
            }
            $max = (int) mandala_gift_settings()['gift_max'];
            ob_start(); ?>
<form class="gift-builder" data-gift-builder data-max="<?php echo (int) $max; ?>" novalidate>
  <fieldset class="gift-step"><legend><span class="step-no">1</span> <?php echo esc_html(sprintf(__('Válassz 2–%d terméket', 'mandala'), $max)); ?></legend>
    <p class="gift-count text-small" aria-live="polite" data-gift-count><?php esc_html_e('Még nincs kiválasztva termék.', 'mandala'); ?></p>
    <ul class="gift-grid">
      <?php foreach ($candidates as $p) : ?>
      <li><label class="gift-item"><input type="checkbox" name="items[]" value="<?php echo (int) $p['id']; ?>" data-price="<?php echo esc_attr((string) $p['price']); ?>" data-name="<?php echo esc_attr($p['name']); ?>">
        <span class="gift-thumb"><?php echo $p['img'] ? '<img src="' . esc_url($p['img']) . '" alt="" loading="lazy" width="160" height="160">' : '<img src="' . esc_url($p['art']) . '" alt="" loading="lazy" width="160" height="160">'; ?><span class="gift-check" aria-hidden="true"><?php echo mandala_icon('check', 'ico ico-s'); // phpcs:ignore ?></span></span>
        <span class="gift-name"><?php echo esc_html($p['name']); ?></span><span class="gift-price num"><?php echo esc_html(mandala_fmt((float) $p['price'])); ?></span></label></li>
      <?php endforeach; ?>
    </ul>
  </fieldset>
  <fieldset class="gift-step"><legend><span class="step-no">2</span> <?php esc_html_e('Csomagolás', 'mandala'); ?></legend>
    <div class="gift-boxes">
      <?php foreach ($boxes as $i => $box) : ?>
      <label class="gift-box"><input type="radio" name="box" value="<?php echo (int) $box->get_id(); ?>" data-price="<?php echo esc_attr((string) wc_get_price_to_display($box)); ?>" data-name="<?php echo esc_attr($box->get_name()); ?>"<?php checked($i, 0); ?>>
        <span class="gift-box-text"><strong><?php echo esc_html($box->get_name()); ?></strong><small><?php echo esc_html(wp_strip_all_tags($box->get_short_description())); ?></small></span><span class="gift-box-price num"><?php echo esc_html(mandala_fmt((float) wc_get_price_to_display($box))); ?></span></label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <fieldset class="gift-step"><legend><span class="step-no">3</span> <?php esc_html_e('Kézzel írt kártya', 'mandala'); ?></legend>
    <div class="iu-form-field"><label for="gift-message"><?php esc_html_e('Mit írjunk a kártyára? (nem kötelező)', 'mandala'); ?></label><textarea class="input-text" id="gift-message" name="message" rows="3" maxlength="200" aria-describedby="gift-message-count"></textarea><p class="text-small text-muted" id="gift-message-count" data-gift-chars style="margin:var(--space-2) 0 0">0 / 200</p></div>
    <p class="text-small text-muted"><?php esc_html_e('A csomagba nem teszünk árat tartalmazó papírt; a számlát e-mailben küldjük.', 'mandala'); ?></p>
  </fieldset>
  <div class="gift-summary" aria-live="polite"><div><span class="text-muted text-small"><?php esc_html_e('A csomag ára', 'mandala'); ?></span><strong class="num" data-gift-total>–</strong></div>
    <button type="submit" class="iu-button iu-button-large" data-gift-submit disabled><?php echo mandala_icon('gift', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Csomag a kosárba', 'mandala'); ?></button>
    <p class="form-message" role="status" data-gift-error></p></div>
</form>
            <?php
            wp_enqueue_script_module('mandala-gift', MANDALA_URL . '/assets/js/gift.js', [], MANDALA_VERSION);
            return (string) ob_get_clean();
        },
    ]);
});

/* ---------- Bemutató: utalvány és csomagolások ---------- */

add_action('mandala_demo_features', function () {
    $make = function (string $sku, string $name, int $price, array $meta, string $short, bool $hidden, string $cat = '') {
        if (wc_get_product_id_by_sku($sku)) {
            return;
        }
        $p = new WC_Product_Simple();
        $p->set_name($name);
        $p->set_sku($sku);
        $p->set_regular_price((string) $price);
        $p->set_short_description($short);
        $p->set_status('publish');
        $p->set_catalog_visibility($hidden ? 'hidden' : 'visible');
        foreach ($meta + ['_mandala_demo' => '1'] as $k => $v) {
            $p->update_meta_data($k, $v);
        }
        if ($cat && ($term = get_term_by('slug', $cat, 'product_cat'))) {
            $p->set_category_ids([$term->term_id]);
        }
        $p->save();
    };
    $make('MND-UTALVANY', 'Mandala ajándékutalvány', mandala_voucher_amounts()[0], ['_mandala_voucher' => 'yes', '_mandala_art' => 'scarf', '_mandala_tone' => 'saffron'],
        'Ha nem tudod, mi lenne a legjobb: választhat ő maga. E-mailben érkezik, kinyomtatható, a teljes kínálatra beváltható.', false, 'ajandektargyak');
    $make('MND-CSOM-LOKTA', 'Lokta papír csomagolás', 990, ['_mandala_giftbox' => 'yes', '_mandala_art' => 'scarf'], 'Nepáli, kézzel merített lokta papír, pamutszalaggal.', true);
    $make('MND-CSOM-DOBOZ', 'Díszdoboz selyemkendővel', 2490, ['_mandala_giftbox' => 'yes', '_mandala_art' => 'scarf', '_mandala_tone' => 'maroon'], 'Merev díszdoboz, a tárgyakat selyemkendőbe bugyolálva tesszük bele.', true);
    // Az „Ajándék” szándékhoz sorolt termékek adják az összeállító kínálatát.
    mandala_flush_index();
});
