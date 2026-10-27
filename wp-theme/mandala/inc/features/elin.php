<?php
/**
 * ELIN 403 – a tárhely tűzfala IP-cím szerint elutasít POST kéréseket (nginx „403 Forbidden” oldal), a GET-eket
 * mindig átengedi. A vásárlónál ez így látszott: a kártya „Kosárba” gombja nem csinált semmit, a termékoldal
 * fehér „403 Forbidden” oldalt adott, a pénztárban a szürke takarás rajta maradt, és nem jöttek a szállítási módok.
 *
 * Mit csinál a téma (a böngésző oldala: assets/js/net.js, site.js, product.js, checkout.js):
 *  - kosárba tétel: tiltáskor ugyanaz GET-tel (wc-ajax=add_to_cart&…&mfb=1 – lent a „GET → POST híd”), végső
 *    esetben oldalbetöltéssel (?add-to-cart=…&mfb=1), utána tiszta címre irányítunk (a frissítés nem tesz be újra);
 *  - pénztár: a takarás le, érthető üzenet újrapróbálással, telefonnal, e-maillel; a gomb sosem marad tiltva;
 *  - jelzés: a böngésző egy kis GET kéréssel (mandala/v1/blocked) szól, ha tiltást látott – naponta, végpontonként
 *    és /24-es hálózatonként számoljuk (személyes adat nélkül), WooCommerce → Mandala állapot, 14 nap;
 *  - Teya: ha a vásárló a sikeres kártyás fizetés után visszatér, de a Teya szerverének értesítése (POST) nem jött
 *    meg, rendelés-jegyzet + őrszem-figyelmeztetés, és a rendelést a WooCommerce nem törli „fizetetlenként”;
 *  - a csomagpont-térkép (Pont bővítmény) window.initOpenMap() hívása a sikertelen frissítés után ne dobjon hibát
 *    (checkout.js).
 */

defined('ABSPATH') || exit;

const MANDALA_BLK_NETS = 'mandala_blk_nets';
const MANDALA_BLK_DAYS = 14;
/** A jelezhető végpontok (net.js ENDPOINTS) és a megjelenített nevük. */
const MANDALA_BLK_ENDPOINTS = [
    'add_to_cart' => 'Kosárba (kártya, termékoldal)',
    'product_form' => 'Termékoldali űrlap (változat)',
    'update_order_review' => 'Pénztár frissítése (szállítás, fizetés)',
    'checkout' => 'Rendelés leadása',
    'get_refreshed_fragments' => 'Fejléc kosár',
    'apply_coupon' => 'Kupon',
    'remove_coupon' => 'Kupon törlése',
    'cart' => 'Kosár oldal',
    'mandala_set_qty' => 'Minikosár mennyiség',
    'mandala_bulk_add' => 'Viszonteladói gyorsrendelő',
    'mandala_gift_add' => 'Ajándékcsomag',
    'mandala_capture' => 'Pénztár e-mail mentése',
    'rest' => 'REST (egyéb)',
    'admin_ajax' => 'admin-ajax (egyéb)',
    'other' => 'Egyéb',
];

/** /24-es (IPv6: /48-as) hálózat – ennél pontosabb cím nem kerül tárolásra. */
function mandala_blk_mask(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0/24';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = inet_pton($ip);
        return $packed === false ? '' : inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }
    return '';
}

/**
 * Egy tiltás rögzítése: napi számlálók (mandala_stats: blk, blk_e_<végpont>, blk_pv) és a hálózat (/24) napi száma.
 * Visszaad: igaz, ha számoltuk (botot, túl sokat ugyanarról a hálózatról nem).
 */
function mandala_blk_record(string $endpoint, bool $first_on_page, string $ip): bool
{
    if (!function_exists('mandala_stat') || (function_exists('mandala_stat_is_bot') && mandala_stat_is_bot())) {
        return false;
    }
    $endpoint = isset(MANDALA_BLK_ENDPOINTS[$endpoint]) ? $endpoint : 'other';
    $day = wp_date('Y-m-d');
    $net = mandala_blk_mask($ip) ?: '?';
    $nets = (array) get_option(MANDALA_BLK_NETS, []);
    $today = (array) ($nets[$day] ?? []);
    // Visszaélés ellen: egy hálózatról naponta legfeljebb 200, összesen legfeljebb 300 hálózat.
    if (($today[$net] ?? 0) >= 200 || (!isset($today[$net]) && count($today) >= 300)) {
        return false;
    }
    $today[$net] = (int) ($today[$net] ?? 0) + 1;
    $nets[$day] = $today;
    ksort($nets);
    update_option(MANDALA_BLK_NETS, array_slice($nets, -MANDALA_BLK_DAYS, null, true), false);
    mandala_stat('blk');
    mandala_stat('blk_e_' . $endpoint);
    if ($first_on_page) {
        mandala_stat('blk_pv');
    }
    return true;
}

/* ---------- A böngésző jelzése (GET – a POST-ot épp a tűzfal tiltaná) ---------- */

add_action('rest_api_init', function () {
    register_rest_route('mandala/v1', '/blocked', [
        'methods' => 'GET',
        'permission_callback' => '__return_true', // névtelen számláló, személyes adat nélkül
        'args' => [
            'e' => ['type' => 'string', 'required' => true],
            'f' => ['type' => 'integer', 'required' => false],
        ],
        'callback' => function (WP_REST_Request $r) {
            $ip = function_exists('mandala_client_ip') ? mandala_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''); // phpcs:ignore
            $ok = mandala_blk_record(sanitize_key((string) $r->get_param('e')), (int) $r->get_param('f') === 1, $ip);
            $res = rest_ensure_response(['ok' => $ok]);
            $res->header('Cache-Control', 'no-store, max-age=0');
            return $res;
        },
    ]);
});

/* ---------- GET → POST híd ---------- */

/*
 * A WooCommerce (és a téma) néhány wc-ajax kezelője csak $_POST-ot olvas. A téma JS-e tiltáskor ugyanazt a kérést
 * GET-tel, mfb=1 jellel küldi: itt a kezelő előtt átmásoljuk a paramétereket. A nonce-ellenőrzés ugyanúgy fut
 * (a check_ajax_referer a $_REQUEST-et nézi); a kosárba tétel GET-tel a WooCommerce-ben eddig is lehetett (?add-to-cart=).
 */
foreach (['add_to_cart', 'apply_coupon', 'mandala_set_qty', 'mandala_bulk_add', 'mandala_gift_add'] as $mandala_ep) {
    add_action('wc_ajax_' . $mandala_ep, function () {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || empty($_GET['mfb'])) { // phpcs:ignore
            return;
        }
        mandala_nocache('elin-get');
        foreach ($_GET as $k => $v) { // phpcs:ignore WordPress.Security.NonceVerification -- a kezelő ellenőrzi
            if (!in_array($k, ['wc-ajax', 'mfb', '_'], true) && !isset($_POST[$k])) {
                $_POST[$k] = $v;
            }
        }
    }, 0);
}
unset($mandala_ep);

/* ---------- GET-es kosárba tétel oldalbetöltéssel (?add-to-cart=…&mfb=1) ---------- */

add_action('wp_loaded', function () {
    if (empty($_GET['mfb']) || empty($_REQUEST['add-to-cart'])) { // phpcs:ignore
        return;
    }
    mandala_nocache('elin-get');
    // A visszajelzést a téma adja (toast), a WooCommerce saját üzenete ne jöjjön kétszer.
    add_filter('wc_add_to_cart_message_html', '__return_empty_string', 99);
    // Sikeres betétel után tiszta címre irányítunk (frissítésre, vissza gombra se tegye be újra).
    add_filter('woocommerce_add_to_cart_redirect', function ($url, $product = null) {
        if (!$url) {
            // A jelenlegi oldal (a wp_safe_redirect csak a saját domainre enged).
            $url = (is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . wp_unslash($_SERVER['REQUEST_URI'] ?? '/'); // phpcs:ignore
        }
        $parts = wp_parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $k) {
            if (in_array($k, ['add-to-cart', 'quantity', 'variation_id', 'product_id', 'mfb', 'mandala_added', 'mandala_qty'], true) || str_starts_with($k, 'attribute_') || preg_match('/^mandala_(sub|voucher)/', $k)) {
                unset($query[$k]);
            }
        }
        $base = strtok($url, '?');
        $id = $product instanceof WC_Product ? ($product->get_parent_id() ?: $product->get_id()) : absint($_REQUEST['add-to-cart'] ?? 0); // phpcs:ignore
        $query['mandala_added'] = $id;
        $query['mandala_qty'] = max(1, absint($_REQUEST['quantity'] ?? 1)); // phpcs:ignore
        return $base . '?' . http_build_query($query);
    }, 99, 2);
}, 1);

// Megmentett kosárba tétel (GET tartalékkal) – a Mandala állapot táblázatához; és a jelzés a JS-nek, ha nem irányítottunk.
add_action('woocommerce_add_to_cart', function ($key, $product_id) {
    if (!empty($_GET['mfb']) && function_exists('mandala_stat')) { // phpcs:ignore
        mandala_stat('blk_rescued');
        $GLOBALS['mandala_fb_added'] = (int) $product_id;
    }
}, 60, 2);
add_filter('mandala_js_data', function ($data) {
    if (!empty($GLOBALS['mandala_fb_added'])) {
        $data['fbAdded'] = (int) $GLOBALS['mandala_fb_added'];
    }
    return $data;
});

/* ---------- Teya: visszatért a vásárló, de a fizetés visszaigazolása nem jött meg ---------- */

/** Kártyás fizetési módok, amelyeknél a fizetést a szolgáltató szerver-hívása (POST) igazolja vissza. */
function mandala_card_gateways(): array
{
    return (array) apply_filters('mandala_card_gateways', ['borgun']);
}

/**
 * A köszönőoldal rendelése, ha a vásárló kártyás fizetés után tért vissza, de a rendelés még „Függőben” van
 * (a Teya értesítése nem érkezett meg). A rendelés kulcsát ellenőrizzük. Egyébként null.
 */
function mandala_returned_unconfirmed_order(): ?WC_Order
{
    if (!function_exists('is_order_received_page') || !is_order_received_page()) {
        return null;
    }
    global $wp;
    $id = absint($wp->query_vars['order-received'] ?? 0);
    $order = $id ? wc_get_order($id) : null;
    $key = wc_clean(wp_unslash($_GET['key'] ?? '')); // phpcs:ignore
    if (!$order instanceof WC_Order || $key === '' || !hash_equals($order->get_order_key(), (string) $key)) {
        return null;
    }
    return $order->has_status('pending') && in_array($order->get_payment_method(), mandala_card_gateways(), true) ? $order : null;
}

// A Teya bővítmény a 10-es prioritáson próbálja visszaigazolni a fizetést (a vásárló visszatérő kéréséből); utána nézzük.
add_action('wp', function () {
    $order = mandala_returned_unconfirmed_order();
    if (!$order || $order->get_meta('_mandala_card_returned')) {
        return;
    }
    $order->update_meta_data('_mandala_card_returned', time());
    $order->add_order_note(__('A vásárló a kártyás fizetés után visszatért a Teyától a köszönőoldalra, de a fizetés visszaigazolása (a Teya szerverének értesítése) még nem érkezett meg. Ha 20 percen belül sem lesz „Feldolgozás alatt”, nézd meg a Teya felületén: ha ott sikeres, állítsd át kézzel. Az értesítést a tárhely tűzfala (ELIN) is visszatarthatja.', 'mandala'));
    $order->save();
    do_action('mandala_card_returned_unconfirmed', $order);
}, 20);

// A WooCommerce a „Függőben” rendeléseket a készletfoglalási idő után törölné – a visszatérteket nem (fizetett lehet).
add_filter('woocommerce_cancel_unpaid_order', function ($cancel, $order) {
    return $cancel && !($order instanceof WC_Order && $order->get_meta('_mandala_card_returned'));
}, 10, 2);

/** Visszaigazolásra váró kártyás rendelések (visszatért a vásárló, 20 percnél régebben, az elmúlt 7 napból). */
function mandala_card_unconfirmed_orders(): array
{
    if (!function_exists('wc_get_orders')) {
        return [];
    }
    $out = [];
    // Fizetési módonként (a meta_query-t a régi rendelés-tárolás nem ismeri); a jelölést PHP-ben szűrjük.
    foreach (mandala_card_gateways() as $gateway) {
        $orders = wc_get_orders(['status' => 'pending', 'payment_method' => (string) $gateway, 'date_created' => '>' . (time() - 7 * DAY_IN_SECONDS), 'limit' => 100, 'type' => 'shop_order']);
        foreach ($orders as $o) {
            $at = (int) $o->get_meta('_mandala_card_returned');
            if ($at > 0 && $at < time() - 20 * MINUTE_IN_SECONDS) {
                $out[] = $o;
            }
        }
    }
    return $out;
}

/* ---------- Őrszem ---------- */

/** Napi számlálók a mandala_stats táblából: [nap => [metric => n]]. */
function mandala_blk_stats(int $days = MANDALA_BLK_DAYS): array
{
    global $wpdb;
    if (!function_exists('mandala_stats_table') || get_option('mandala_stats_db') === false) {
        return [];
    }
    $from = wp_date('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT day, metric, n FROM ' . mandala_stats_table() . " WHERE day >= %s AND (metric LIKE 'blk%%' OR metric = 'visitors')", $from)); // phpcs:ignore
    $out = [];
    foreach ((array) $rows as $r) {
        $out[(string) $r->day][(string) $r->metric] = (int) $r->n;
    }
    return $out;
}

add_filter('mandala_health_checks', function ($out) {
    $today = mandala_blk_stats(1)[wp_date('Y-m-d')] ?? [];
    $shop = (int) ($today['blk_e_update_order_review'] ?? 0) + (int) ($today['blk_e_checkout'] ?? 0) + (int) ($today['blk_e_add_to_cart'] ?? 0) + (int) ($today['blk_e_product_form'] ?? 0);
    $nets = count((array) (((array) get_option(MANDALA_BLK_NETS, []))[wp_date('Y-m-d')] ?? []));
    $out['elin'] = ['label' => 'Tárhely tűzfala (ELIN 403)', 'status' => $shop >= 5 ? 'warn' : 'ok',
        'msg' => $shop >= 5
            ? sprintf('Ma %d kosárba tételt / pénztár-kérést tiltott a tárhely tűzfala, %d különböző hálózatról. A téma pótolja, ahol tudja, de kérd az ELIN-t a POST-szűrés lazítására (WooCommerce → Mandala állapot).', $shop, $nets)
            : sprintf('Ma %d tiltott kérés (%d hálózat).', (int) ($today['blk'] ?? 0), $nets)];
    $waiting = mandala_card_unconfirmed_orders();
    if ($waiting) {
        $out['teya'] = ['label' => 'Kártyás fizetések visszaigazolása', 'status' => 'warn',
            'msg' => sprintf('%d kártyás rendelésnél visszatért a vásárló, de a Teya értesítése nem jött meg (%s). Nézd meg a Teya felületén, és ha sikeres, állítsd „Feldolgozás alatt”-ra – a tűzfal (ELIN) tarthatta vissza.', count($waiting), implode(', ', array_map(fn($o) => '#' . $o->get_order_number(), array_slice($waiting, 0, 8))))];
    }
    return $out;
});

/* ---------- Admin: WooCommerce → Mandala állapot ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala állapot', 'Mandala állapot', 'manage_woocommerce', 'mandala-status', 'mandala_status_page');
});

function mandala_status_page(): void
{
    $stats = mandala_blk_stats();
    $nets = (array) get_option(MANDALA_BLK_NETS, []);
    $n = fn($d, $k) => (int) ($stats[$d][$k] ?? 0);
    echo '<div class="wrap"><h1>Mandala állapot</h1>';
    echo '<h2>A tárhely tűzfala (ELIN 403) – utolsó ' . (int) MANDALA_BLK_DAYS . ' nap</h2>';
    echo '<p style="max-width:860px">Az ELIN tűzfala egyes látogatók IP-címéről elutasítja a POST kéréseket (kosárba tétel, pénztár, rendelés leadása); a GET-eket átengedi. '
        . 'A vásárló böngészője minden ilyen tiltásnál szól egy kis GET kéréssel – itt látod, naponta hányszor, mely végponton és hány különböző hálózatról (/24, pontos IP-cím nélkül). '
        . 'A „Megmentve” oszlop a GET tartalékkal mégis kosárba tett termékek száma. A „Látogatók” a saját, sütimentes mérés (Kivonat).</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Nap</th><th>Látogatók</th><th>Tiltott kérés</th><th>Oldal tiltással</th><th>Kosárba</th><th>Pénztár frissítése</th><th>Rendelés leadása</th><th>Egyéb</th><th>Hálózatok (/24)</th><th>Megmentve (GET)</th></tr></thead><tbody>';
    for ($i = 0; $i < MANDALA_BLK_DAYS; $i++) {
        $d = wp_date('Y-m-d', time() - $i * DAY_IN_SECONDS);
        $cart = $n($d, 'blk_e_add_to_cart') + $n($d, 'blk_e_product_form');
        $review = $n($d, 'blk_e_update_order_review');
        $order = $n($d, 'blk_e_checkout');
        $other = max(0, $n($d, 'blk') - $cart - $review - $order);
        echo '<tr><td>' . esc_html(wp_date('m. d. (D)', strtotime($d . ' 12:00'))) . '</td><td>' . $n($d, 'visitors') . '</td><td><strong>' . $n($d, 'blk') . '</strong></td><td>' . $n($d, 'blk_pv') . '</td><td>' . $cart . '</td><td>' . $review . '</td><td>' . $order . '</td><td>' . $other . '</td><td>' . count((array) ($nets[$d] ?? [])) . '</td><td>' . $n($d, 'blk_rescued') . '</td></tr>'; // phpcs:ignore
    }
    echo '</tbody></table>';

    $by_ep = [];
    foreach ($stats as $metrics) {
        foreach ($metrics as $k => $v) {
            if (str_starts_with($k, 'blk_e_')) {
                $by_ep[substr($k, 6)] = ($by_ep[substr($k, 6)] ?? 0) + $v;
            }
        }
    }
    arsort($by_ep);
    if ($by_ep) {
        echo '<h3>Végpontonként (' . (int) MANDALA_BLK_DAYS . ' nap)</h3><ul>';
        foreach ($by_ep as $k => $v) {
            echo '<li>' . esc_html(MANDALA_BLK_ENDPOINTS[$k] ?? $k) . ': <strong>' . (int) $v . '</strong></li>';
        }
        echo '</ul>';
    }
    $top = [];
    foreach ($nets as $day_nets) {
        foreach ((array) $day_nets as $net => $v) {
            $top[$net] = ($top[$net] ?? 0) + (int) $v;
        }
    }
    arsort($top);
    if ($top) {
        echo '<h3>Leggyakrabban tiltott hálózatok</h3><p class="description">Ezt a listát elküldheted az ELIN-nek, hogy engedjék át (csak a hálózat, nem egy-egy vásárló címe).</p>';
        echo '<textarea readonly rows="8" class="large-text code" style="max-width:520px">' . esc_textarea(implode("\n", array_map(fn($k, $v) => $k . "\t" . $v, array_keys(array_slice($top, 0, 40, true)), array_slice($top, 0, 40, true)))) . '</textarea>';
    }

    $waiting = mandala_card_unconfirmed_orders();
    echo '<h2>Kártyás fizetések visszaigazolása (Teya)</h2>';
    if ($waiting) {
        echo '<p>Ezeknél a rendeléseknél a vásárló visszatért a Teyától, de a fizetés visszaigazolása nem jött meg. Nézd meg a Teya felületén; ha sikeres, állítsd „Feldolgozás alatt”-ra.</p><ul>';
        foreach ($waiting as $o) {
            echo '<li><a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> – ' . esc_html($o->get_formatted_billing_full_name()) . ', ' . wp_kses_post(wc_price($o->get_total())) . ', ' . esc_html(wp_date('m. d. H:i', (int) $o->get_meta('_mandala_card_returned'))) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>Nincs visszaigazolásra váró kártyás rendelés.</p>';
    }
    echo '</div>';
}
