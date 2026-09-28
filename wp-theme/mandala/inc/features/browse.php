<?php
/**
 * Elhagyott böngészés – a feliratkozó megnézett egy terméket, de nem vette meg → másnap egy rövid levél
 * a megnézett darabokkal (akkor is, ha kosárba sem tette; az elhagyott kosár levél arra külön van).
 *
 * Csak hozzájárulással: azonosítót (véletlen, e-mail nélküli sütit) csak az kap, aki feliratkozott
 * (felugró ablak, pénztár, weboldal űrlap), és a böngésző csak akkor jelez, ha a marketing sütikhez is
 * hozzájárult. Nem megy, ha közben rendelt, leiratkozott, vagy 7 napon belül már kapott ilyet; a már
 * elfogyott termék kimarad. Az oldal-gyorsítótárat nem zavarja (a jelzés a böngészőből megy).
 * Be/ki, időzítés, szöveg: Mandala levelek → „Megnézett termékek” levél.
 */

defined('ABSPATH') || exit;

const MANDALA_BROWSE_DB_VERSION = '1';
const MANDALA_BROWSE_COOKIE = 'mandala_known';

function mandala_browse_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mandala_browse';
}
add_action('init', function () {
    if (get_option('mandala_browse_db') === MANDALA_BROWSE_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . mandala_browse_table() . " (
        token char(32) NOT NULL,
        email varchar(190) NOT NULL,
        views text NOT NULL,
        last_view datetime NULL,
        last_sent datetime NULL,
        created datetime NOT NULL,
        PRIMARY KEY  (token),
        KEY email (email),
        KEY last_view (last_view)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('mandala_browse_db', MANDALA_BROWSE_DB_VERSION);
});

/* ---------- Azonosítás: feliratkozáskor ---------- */

add_action('mandala_newsletter_subscribed', function ($email) {
    $email = strtolower(sanitize_email((string) $email));
    if (!is_email($email) || headers_sent() || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    global $wpdb;
    $token = (string) $wpdb->get_var($wpdb->prepare('SELECT token FROM ' . mandala_browse_table() . ' WHERE email = %s LIMIT 1', $email)); // phpcs:ignore
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $wpdb->insert(mandala_browse_table(), ['token' => $token, 'email' => $email, 'views' => '[]', 'created' => current_time('mysql', true)]);
    }
    $opts = ['expires' => time() + 180 * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'samesite' => 'Lax'];
    setcookie(MANDALA_BROWSE_COOKIE, $token, $opts + ['httponly' => true]);
    setcookie('mandala_k', '1', $opts); // csak jelző a böngészőnek (az azonosító nem olvasható JS-ből)
});

/* ---------- Jelzés a termékoldalról (REST, a böngészőből) ---------- */

add_filter('mandala_js_data', function ($data) {
    if (function_exists('is_product') && is_product()) {
        $data['productId'] = (int) get_queried_object_id();
    }
    return $data;
});

add_action('rest_api_init', function () {
    register_rest_route('mandala/v1', '/seen', [
        'methods' => 'POST',
        'permission_callback' => 'mandala_rest_verify',
        'callback' => function (WP_REST_Request $r) {
            $token = preg_replace('/[^a-f0-9]/', '', (string) ($_COOKIE[MANDALA_BROWSE_COOKIE] ?? '')); // phpcs:ignore
            $id = absint($r->get_param('id'));
            if (strlen($token) !== 32 || !$id || get_post_type($id) !== 'product') {
                return ['ok' => false];
            }
            mandala_browse_record($token, $id);
            return ['ok' => true];
        },
    ]);
});

function mandala_browse_record(string $token, int $product_id): void
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT views FROM ' . mandala_browse_table() . ' WHERE token = %s', $token)); // phpcs:ignore
    if (!$row) {
        return;
    }
    $views = array_values(array_filter((array) json_decode((string) $row->views, true), fn($v) => (int) ($v[0] ?? 0) !== $product_id));
    // [termék, időpont, ár a megnézéskor] – az ár az árcsökkenés-értesítőhöz kell.
    $p = wc_get_product($product_id);
    array_unshift($views, [$product_id, time(), $p ? (float) wc_get_price_to_display($p) : 0]);
    $wpdb->update(mandala_browse_table(), ['views' => wp_json_encode(array_slice($views, 0, 10)), 'last_view' => current_time('mysql', true)], ['token' => $token]);
}

/* ---------- Óránkénti küldés ---------- */

mandala_recurring('mandala_browse_sweep', HOUR_IN_SECONDS, fn() => time() + 15 * MINUTE_IN_SECONDS);
add_action('mandala_browse_sweep', 'mandala_browse_sweep');

/** Visszaad: az elküldött levelek száma. */
function mandala_browse_sweep(): int
{
    if (!mandala_automation_on('browse')) {
        return 0;
    }
    global $wpdb;
    $hours = max(1, (int) mandala_automation_settings()['browse_hours']);
    $now = time();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . mandala_browse_table() . ' WHERE last_view < %s AND last_view > %s AND (last_sent IS NULL OR (last_sent < last_view AND last_sent < %s)) LIMIT 50', // phpcs:ignore
        gmdate('Y-m-d H:i:s', $now - $hours * HOUR_IN_SECONDS), gmdate('Y-m-d H:i:s', $now - ($hours + 48) * HOUR_IN_SECONDS), gmdate('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS)));
    $sent = 0;
    foreach ($rows as $row) {
        $mark = fn() => $wpdb->update(mandala_browse_table(), ['last_sent' => current_time('mysql', true)], ['token' => $row->token]);
        $since = $row->last_sent ? strtotime($row->last_sent . ' UTC') : 0;
        if (mandala_is_unsubscribed($row->email)) {
            $mark();
            continue;
        }
        // Közben rendelt → nem zavarjuk (és a megvett darabot amúgy sem ajánlanánk).
        $first_view = min(array_map(fn($v) => (int) $v[1], (array) json_decode((string) $row->views, true)) ?: [$now]);
        $ordered = wc_get_orders(['billing_email' => $row->email, 'date_created' => '>' . min($first_view, strtotime($row->last_view . ' UTC')), 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order']);
        if ($ordered) {
            $mark();
            continue;
        }
        $products = [];
        foreach ((array) json_decode((string) $row->views, true) as $v) {
            [$pid, $at] = $v;
            $p = wc_get_product((int) $pid);
            if ((int) $at > $since && $p && $p->get_status() === 'publish' && $p->is_purchasable() && $p->is_in_stock() && !(function_exists('mandala_is_voucher') && mandala_is_voucher($p))) {
                $products[] = $p;
            }
            if (count($products) === 3) {
                break;
            }
        }
        $mark();
        if (!$products) {
            continue;
        }
        $last = wc_get_orders(['billing_email' => $row->email, 'limit' => 1, 'type' => 'shop_order']);
        $name = $last ? $last[0]->get_billing_first_name() : '';
        $rows_html = implode('', array_map(fn($p) => mandala_mail_product_row($p, wp_strip_all_tags(wc_price((float) wc_get_price_to_display($p)))), $products));
        if (mandala_mail('browse', $row->email, ['keresztnev' => $name ?: __('Kedves Vásárlónk', 'mandala')], ['termekek' => $rows_html, 'gomb' => mandala_mail_button($products[0]->get_permalink(), __('Megnézem újra', 'mandala'))])) {
            $sent++;
        }
    }
    return $sent;
}

// Karbantartás: 180 napja nem látott azonosítók törlése.
add_action('mandala_404_cleanup', function () {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . mandala_browse_table() . ' WHERE COALESCE(last_view, created) < %s', gmdate('Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS))); // phpcs:ignore
});

/* ---------- Levélsablon ---------- */

add_filter('mandala_mail_types', function ($types) {
    $types['browse'] = [
        'label' => 'Megnézett termékek', 'group' => 'Értesítések', 'setting' => 'browse', 'delay' => ['browse_hours', 'óra'], 'marketing' => true,
        'when' => fn($s) => sprintf('Ha egy feliratkozó (marketing sütihez hozzájárulva) megnézett termékeket, de %d órán belül nem rendelt: a megnézett, még kapható darabok (legfeljebb 3). Hetente legfeljebb egyszer.', $s['browse_hours']),
        'vars' => ['keresztnev' => 'A keresztnév (ha rendelt már)'], 'blocks' => ['termekek' => 'A megnézett termékek', 'gomb' => '„Megnézem újra” gomb'],
        'subject' => __('Még gondolkodsz rajta?', 'mandala'), 'heading' => __('Félretettük a szemed elé', 'mandala'),
        'body' => '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p><p>' . __('Láttuk, hogy nézelődtél nálunk. Ha kérdésed van valamelyik darabról – méret, hangzás, illat –, írj nyugodtan, szívesen segítünk.', 'mandala') . '</p>{termekek}{gomb}',
        'sample' => fn() => [['keresztnev' => 'Anna'], ['termekek' => mandala_mail_sample_rows(2, true), 'gomb' => mandala_mail_button(home_url('/'), __('Megnézem újra', 'mandala'))]],
    ];
    return $types;
});

/* ---------- Adatvédelem: a WordPress export / törlés eszközeihez ---------- */

add_filter('wp_privacy_personal_data_erasers', function ($erasers) {
    $erasers['mandala-browse'] = ['eraser_friendly_name' => 'Mandala – megnézett termékek', 'callback' => function ($email) {
        global $wpdb;
        $n = (int) $wpdb->delete(mandala_browse_table(), ['email' => strtolower($email)]);
        return ['items_removed' => $n > 0, 'items_retained' => false, 'messages' => [], 'done' => true];
    }];
    return $erasers;
});
add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['mandala-browse'] = ['exporter_friendly_name' => 'Mandala – megnézett termékek', 'callback' => function ($email) {
        global $wpdb;
        $data = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT views FROM ' . mandala_browse_table() . ' WHERE email = %s', strtolower($email))) as $row) { // phpcs:ignore
            foreach ((array) json_decode((string) $row->views, true) as $v) {
                [$pid, $at] = $v;
                $data[] = ['group_id' => 'mandala-browse', 'group_label' => 'Megnézett termékek', 'item_id' => 'view-' . (int) $pid, 'data' => [['name' => 'Termék', 'value' => get_the_title((int) $pid)], ['name' => 'Időpont', 'value' => wp_date('Y-m-d H:i', (int) $at)]]];
            }
        }
        return ['data' => $data, 'done' => true];
    }];
    return $exporters;
});
