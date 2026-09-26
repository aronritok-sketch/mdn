<?php
/**
 * Közös levélküldés az automatizmusokhoz: a WooCommerce levélsablonjába csomagolva (egységes
 * arculat), leiratkozó linkkel a marketing jellegű leveleknél, és a beállítások oldala
 * (WooCommerce → Mandala automatizmusok).
 */

defined('ABSPATH') || exit;

/** Az automatizmusok beállításai (alapértékekkel). */
function mandala_automation_settings(): array
{
    return wp_parse_args((array) get_option('mandala_automations', []), [
        'abandoned' => 'yes', 'abandoned_hours' => 3,
        'care' => 'yes', 'care_days' => 2,
        'reorder' => 'yes', 'reorder_days' => 40, 'reorder_cats' => 'fustolok',
        'review' => 'yes', 'review_days' => 10,
        'moderator' => '',
    ]);
}
function mandala_automation_on(string $key): bool
{
    return (mandala_automation_settings()[$key] ?? 'no') === 'yes';
}

/* ---------- Leiratkozás ---------- */

function mandala_token(string ...$parts): string
{
    return substr(hash_hmac('sha256', implode('|', $parts), wp_salt('auth')), 0, 32);
}
function mandala_unsubscribe_url(string $email): string
{
    return add_query_arg(['mandala_unsub' => rawurlencode(strtolower($email)), 'k' => mandala_token('unsub', strtolower($email))], home_url('/'));
}
function mandala_is_unsubscribed(string $email): bool
{
    return in_array(strtolower($email), (array) get_option('mandala_unsubscribed', []), true);
}
add_action('template_redirect', function () {
    if (empty($_GET['mandala_unsub']) || empty($_GET['k'])) {
        return;
    }
    $email = strtolower(sanitize_email(wp_unslash($_GET['mandala_unsub'])));
    if (!hash_equals(mandala_token('unsub', $email), sanitize_text_field(wp_unslash($_GET['k'])))) {
        wp_die(esc_html__('Érvénytelen leiratkozó link.', 'mandala'), '', ['response' => 400]);
    }
    $list = (array) get_option('mandala_unsubscribed', []);
    $list[] = $email;
    update_option('mandala_unsubscribed', array_values(array_unique($list)), false);
    $subs = (array) get_option('mandala_newsletter', []);
    unset($subs[$email]);
    update_option('mandala_newsletter', $subs, false);
    wp_die('<h1>' . esc_html__('Leiratkoztál', 'mandala') . '</h1><p>' . esc_html__('Több emlékeztetőt és hírlevelet nem küldünk erre a címre. A rendeléseidről szóló értesítéseket továbbra is megkapod.', 'mandala') . '</p><p><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Vissza a webáruházba', 'mandala') . '</a></p>', esc_html__('Leiratkozás', 'mandala'), ['response' => 200]);
});

/**
 * Levél küldése a WooCommerce sablonjában. $marketing = true esetén leiratkozott címre nem
 * megy, és a lábléc leiratkozó linket kap.
 */
function mandala_send_mail(string $to, string $subject, string $heading, string $body_html, bool $marketing = false): bool
{
    if (!is_email($to) || ($marketing && mandala_is_unsubscribed($to))) {
        return false;
    }
    if ($marketing) {
        $body_html .= '<p style="font-size:12px;color:#6E6357;margin-top:32px">' . sprintf(
            esc_html__('Ezt a levelet a vásárlásod miatt kaptad. %s', 'mandala'),
            '<a href="' . esc_url(mandala_unsubscribe_url($to)) . '" style="color:#6E6357">' . esc_html__('Leiratkozás az emlékeztetőkről', 'mandala') . '</a>'
        ) . '</p>';
    }
    $mailer = function_exists('WC') ? WC()->mailer() : null;
    $message = $mailer ? $mailer->wrap_message($heading, $body_html) : '<h1>' . esc_html($heading) . '</h1>' . $body_html;
    if ($mailer) {
        $email = new WC_Email();
        $message = $email->style_inline($message);
    }
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    if ($marketing) {
        $headers[] = 'List-Unsubscribe: <' . mandala_unsubscribe_url($to) . '>';
    }
    return (bool) wp_mail($to, $subject, $message, $headers);
}

/** Termék sor a levelekben (kép, név, ár, link). */
function mandala_mail_product_row(WC_Product $product, string $extra = '', string $url = ''): string
{
    $img = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : mandala_art_url($product);
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 16px;border-collapse:collapse"><tr>'
        . '<td style="width:72px;padding:0 16px 0 0;vertical-align:top"><img src="' . esc_url($img) . '" width="72" height="72" alt="" style="border-radius:8px;display:block;background:#EEE7DB"></td>'
        . '<td style="vertical-align:top"><a href="' . esc_url($url ?: $product->get_permalink()) . '" style="color:#1C1916;font-weight:600;text-decoration:none">' . esc_html($product->get_name()) . '</a>'
        . ($extra ? '<div style="color:#6E6357;font-size:14px;margin-top:4px">' . $extra . '</div>' : '') . '</td></tr></table>';
}

function mandala_mail_button(string $url, string $label): string
{
    return '<p style="margin:24px 0"><a href="' . esc_url($url) . '" style="display:inline-block;background:#1C1916;color:#FFFFFF;padding:14px 28px;border-radius:999px;text-decoration:none;font-weight:600">' . esc_html($label) . '</a></p>';
}

/* ---------- Beállítások oldal ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala automatizmusok', 'Mandala automatizmusok', 'manage_woocommerce', 'mandala-automations', function () {
        if (isset($_POST['mandala_automations']) && check_admin_referer('mandala_automations')) {
            $in = (array) wp_unslash($_POST['mandala_automations']);
            $clean = [];
            foreach (mandala_automation_settings() as $key => $default) {
                $clean[$key] = in_array($key, ['abandoned', 'care', 'reorder', 'review'], true) ? (empty($in[$key]) ? 'no' : 'yes')
                    : (is_int($default) ? max(1, (int) ($in[$key] ?? $default)) : sanitize_text_field($in[$key] ?? ''));
            }
            update_option('mandala_automations', $clean, false);
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
        $s = mandala_automation_settings();
        $row = fn($key, $label, $desc, $num = '', $unit = '') => '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="mandala_automations[' . $key . ']" value="1"' . checked($s[$key], 'yes', false) . '> bekapcsolva</label>'
            . ($num ? ' &nbsp; <input type="number" min="1" style="width:70px" name="mandala_automations[' . $num . ']" value="' . esc_attr((string) $s[$num]) . '"> ' . esc_html($unit) : '') . '<p class="description">' . esc_html($desc) . '</p></td></tr>';
        echo '<div class="wrap"><h1>Mandala automatizmusok</h1><form method="post">';
        wp_nonce_field('mandala_automations');
        echo '<table class="form-table">'
            . $row('abandoned', 'Elhagyott kosár', 'Ha a vásárló megadta az e-mail-címét a pénztárban, de nem rendelt: egyetlen emlékeztető a kosár visszaállító linkjével. (A pénztárban erről tájékoztatás jelenik meg; jogi átnézés javasolt.)', 'abandoned_hours', 'óra múlva')
            . $row('care', 'Használati útmutató', 'A teljesített rendelés után a termékek „Használat és gondozás” szövege, egy levélben.', 'care_days', 'nap múlva')
            . $row('reorder', 'Újrarendelés emlékeztető', 'Fogyóeszközöknél (alapból füstölők): emlékeztető az újrarendelésre.', 'reorder_days', 'nap múlva')
            . '<tr><th scope="row">Fogyóeszköz kategóriák</th><td><input type="text" class="regular-text" name="mandala_automations[reorder_cats]" value="' . esc_attr($s['reorder_cats']) . '"><p class="description">Kategória slugok vesszővel.</p></td></tr>'
            . $row('review', 'Értékelés kérése', 'A teljesített rendelés után személyes link az értékeléshez (ellenőrzött vásárlás, fotó is feltölthető).', 'review_days', 'nap múlva')
            . '<tr><th scope="row">Értékelések moderátora</th><td><input type="email" class="regular-text" name="mandala_automations[moderator]" value="' . esc_attr($s['moderator']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '"><p class="description">Új értékelésről ide megy értesítés.</p></td></tr>'
            . '</table>';
        submit_button('Mentés');
        $pending = function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['group' => 'mandala', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500], 'ids')) : 0;
        echo '</form><p>Ütemezett levelek: <strong>' . (int) $pending . '</strong> · <a href="' . esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=mandala')) . '">Részletek</a></p></div>';
    });
});
