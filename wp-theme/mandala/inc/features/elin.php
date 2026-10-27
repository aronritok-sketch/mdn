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
 *  - pénztár: ha a rendelés leadására nem jött válasz, újraküldés előtt GET-tel megnézzük, létrejött-e
 *    (wc-ajax=mandala_order_state) – dupla rendelés ellen;
 *  - Teya: a vásárlót a Teya a fizetés után böngészős POST-tal küldi vissza a köszönőoldalra – ha az ő címét tiltja a
 *    tűzfal, a fizetés után fehér „403 Forbidden” oldalt lát (ezen a téma nem tud segíteni, csak az ELIN). Ha a
 *    visszatérés átment, a Teya bővítménye abból igazolja a fizetést. Ha a vásárló később GET-tel nyitja meg a
 *    köszönőoldalt, és a rendelés még „Függőben”, rendelés-jegyzet + őrszem-figyelmeztetés, és a WooCommerce nem
 *    törli „fizetetlenként”. A visszatérés nélküli „Függőben” kártyás rendeléseket a Mandala állapot oldal listázza;
 *  - a csomagpont-térkép (Pont bővítmény) window.initOpenMap() hívása a sikertelen frissítés után ne dobjon hibát
 *    (checkout.js).
 */

defined('ABSPATH') || exit;

/** A tiltott hálózatok napi listája: napi opció (mandala_blk_nets_ÉÉÉÉHHNN), hogy egy jelzés csak a napi listát írja. */
const MANDALA_BLK_NETS = 'mandala_blk_nets_';
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

/** Az utolsó $days nap (ma elöl), Y-m-d – naptári napok, az óraátállítás napján sem csúszik el (nem 86 400 mp-ekkel). */
function mandala_blk_days(int $days = MANDALA_BLK_DAYS): array
{
    $noon = new DateTimeImmutable('today 12:00', wp_timezone());
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $out[] = $noon->modify('-' . $i . ' days')->format('Y-m-d');
    }
    return $out;
}

/** Egy nap tiltott hálózatai: [hálózat => db]. */
function mandala_blk_nets(string $day): array
{
    return (array) get_option(MANDALA_BLK_NETS . str_replace('-', '', $day), []);
}

/**
 * A jelző böngésző IP-címe: a kapcsolat címe (REMOTE_ADDR). A továbbított fejléceket (X-Real-IP, X-Forwarded-For) csak
 * akkor nézzük, ha a kapcsolat helyi proxytól jön (magánhálózati / loopback cím) – különben bárki hamisíthatná, és
 * kitalált hálózatokkal elhasználhatná a napi keretet (a valódi tiltások nem számítanának), vagy a listába kerülne.
 */
function mandala_blk_ip(): string
{
    $public = fn($ip) => (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')); // phpcs:ignore
    if ($public($remote) || !filter_var($remote, FILTER_VALIDATE_IP)) {
        return $public($remote) ? $remote : '';
    }
    // X-Forwarded-For: a helyi proxy a lánc végére fűzi a hozzá kapcsolódó címet (amit a böngésző küldött, az előtte
    // áll) – a jobbról első nyilvános cím. Ha nincs, az X-Real-IP (ezt a proxy állítja be).
    foreach (array_reverse(array_map('trim', explode(',', (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))) as $ip) { // phpcs:ignore
        if ($public($ip)) {
            return $ip;
        }
    }
    $real = trim((string) wp_unslash($_SERVER['HTTP_X_REAL_IP'] ?? '')); // phpcs:ignore
    return $public($real) ? $real : $remote;
}

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
    $opt = MANDALA_BLK_NETS . str_replace('-', '', $day);
    $today = get_option($opt, null);
    if ($today === null) {
        // A nap első jelzése: a 14 napnál régebbi napi listák törlése (legfeljebb 30 napra visszamenőleg).
        foreach (array_slice(mandala_blk_days(MANDALA_BLK_DAYS + 30), MANDALA_BLK_DAYS) as $old) {
            delete_option(MANDALA_BLK_NETS . str_replace('-', '', $old));
        }
        $today = [];
    }
    $today = (array) $today;
    // Visszaélés ellen: egy hálózatról naponta legfeljebb 200, összesen legfeljebb 300 hálózat.
    if (($today[$net] ?? 0) >= 200 || (!isset($today[$net]) && count($today) >= 300)) {
        return false;
    }
    $today[$net] = (int) ($today[$net] ?? 0) + 1;
    update_option($opt, $today, false);
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
            $ok = mandala_blk_record(sanitize_key((string) $r->get_param('e')), (int) $r->get_param('f') === 1, mandala_blk_ip());
            $res = rest_ensure_response(['ok' => $ok]);
            $res->header('Cache-Control', 'no-store, max-age=0');
            return $res;
        },
    ]);
});

/* ---------- GET → POST híd ---------- */

/** A sütiben küldhető személyes mezők (net.js PRIVATE_KEY – ugyanez a lista). */
function mandala_fb_private_key(string $k): bool
{
    return (bool) preg_match('/^(mandala_voucher_(to_name|to_email|message)|message|email|name)$/', $k);
}

/**
 * A GET tartalék személyes mezői (utalvány címzettje, üzenet, e-mail, név) nem a címben jönnek – a tárhely naplójába
 * és a mérőkódok oldalmegtekintésébe ne kerüljenek –, hanem egy rövid életű sütiben (mandala_fbf), a kérés jelével
 * (mfs) párosítva. Itt a kezelő előtt a $_POST / $_REQUEST-be kerülnek, a süti törlődik.
 */
function mandala_fb_unstash(): void
{
    static $done = false;
    $token = sanitize_key(wp_unslash($_GET['mfs'] ?? '')); // phpcs:ignore
    if ($done || $token === '' || empty($_COOKIE['mandala_fbf'])) {
        return;
    }
    $done = true;
    $data = json_decode(wp_unslash((string) $_COOKIE['mandala_fbf']), true); // phpcs:ignore
    if (!headers_sent()) {
        setcookie('mandala_fbf', '', ['expires' => time() - HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'samesite' => 'Lax']);
    }
    unset($_COOKIE['mandala_fbf']);
    if (!is_array($data) || !hash_equals((string) ($data['t'] ?? ''), $token)) {
        return;
    }
    foreach ((array) ($data['f'] ?? []) as $k => $v) {
        if (is_string($k) && is_string($v) && mandala_fb_private_key($k) && !isset($_POST[$k])) { // phpcs:ignore
            $_POST[$k] = $_REQUEST[$k] = wp_slash($v); // a WordPress a kérés mezőit escape-elve tartja
        }
    }
}

/*
 * A WooCommerce (és a téma) néhány wc-ajax kezelője csak $_POST-ot olvas. A téma JS-e tiltáskor ugyanazt a kérést
 * GET-tel, mfb=1 jellel küldi: itt a kezelő előtt átmásoljuk a paramétereket. A nonce-ellenőrzés ugyanúgy fut
 * (a check_ajax_referer a $_REQUEST-et nézi); a kosárba tétel GET-tel a WooCommerce-ben eddig is lehetett (?add-to-cart=).
 */
foreach (['add_to_cart', 'apply_coupon', 'mandala_set_qty', 'mandala_bulk_add', 'mandala_gift_add', 'mandala_capture'] as $mandala_ep) {
    add_action('wc_ajax_' . $mandala_ep, function () {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || empty($_GET['mfb'])) { // phpcs:ignore
            return;
        }
        mandala_nocache('elin-get');
        foreach ($_GET as $k => $v) { // phpcs:ignore WordPress.Security.NonceVerification -- a kezelő ellenőrzi
            if (!in_array($k, ['wc-ajax', 'mfb', 'mfs', '_'], true) && !isset($_POST[$k])) {
                $_POST[$k] = $v;
            }
        }
        mandala_fb_unstash();
    }, 0);
}
unset($mandala_ep);

/* ---------- Pénztár: létrejött-e a rendelés, amire nem jött válasz ---------- */

/*
 * Ha a rendelés leadására nem jött válasz (5xx, megszakadt mobilnet), a pénztár újraküldés előtt ezt kérdezi GET-tel:
 * ha a munkamenet rendelése már nem „Függőben” / „Sikertelen” (pl. utánvét: „Feldolgozás alatt”), létrejött – az
 * újraküldés második rendelést csinálna (a WooCommerce csak a függő / sikertelen rendelést használja újra). A rendelést
 * a WooCommerce order_awaiting_payment-je (a fizetés előtt mentve – ha a kérés közben elhalt, ez marad), vagy a saját
 * jelölésünk adja (a sikeres rendelés a kosárral együtt a WooCommerce-ét is törli). Csak a saját munkamenet friss
 * rendelését adja vissza (a visszaigazoló oldal címét, kulccsal).
 */
add_action('woocommerce_checkout_order_created', function ($order) {
    if ($order instanceof WC_Order && function_exists('WC') && WC()->session) {
        WC()->session->set('mandala_order_sent', $order->get_id());
    }
});
add_action('wc_ajax_mandala_order_state', function () {
    mandala_nocache('elin-order');
    $out = ['placed' => false, 'url' => '', 'cart_hash' => function_exists('WC') && WC()->cart ? (string) WC()->cart->get_cart_hash() : ''];
    $session = function_exists('WC') ? WC()->session : null;
    $id = $session ? (absint($session->get('order_awaiting_payment')) ?: absint($session->get('mandala_order_sent'))) : 0;
    $order = $id ? wc_get_order($id) : null;
    // Csak a mostani kosár rendelése (egy korábbi, már lezárt vásárlás ne számítson): friss, és a kosár üres vagy
    // ugyanaz, amiből a rendelés készült.
    $fresh = $order instanceof WC_Order && $order->get_date_created() && $order->get_date_created()->getTimestamp() > time() - 2 * HOUR_IN_SECONDS
        && ($out['cart_hash'] === '' || $order->get_cart_hash() === $out['cart_hash']);
    if ($fresh && !$order->has_status(['pending', 'failed', 'cancelled', 'checkout-draft'])) {
        $out['placed'] = true;
        $out['url'] = $order->get_checkout_order_received_url();
    }
    wp_send_json($out);
});

/* ---------- GET-es kosárba tétel oldalbetöltéssel (?add-to-cart=…&mfb=1) ---------- */

add_action('wp_loaded', function () {
    if (empty($_GET['mfb']) || empty($_REQUEST['add-to-cart'])) { // phpcs:ignore
        return;
    }
    mandala_nocache('elin-get');
    mandala_fb_unstash(); // a WooCommerce kosárba tétele a 20-as prioritáson fut
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
            if (in_array($k, ['add-to-cart', 'quantity', 'variation_id', 'product_id', 'mfb', 'mfs', 'mandala_added', 'mandala_qty'], true) || str_starts_with($k, 'attribute_') || preg_match('/^mandala_(sub|voucher)/', $k)) {
                unset($query[$k]);
            }
        }
        $base = strtok($url, '?');
        $id = $product instanceof WC_Product ? ($product->get_parent_id() ?: $product->get_id()) : absint($_REQUEST['add-to-cart'] ?? 0); // phpcs:ignore
        $query['mandala_added'] = $id;
        $qty = $_REQUEST['quantity'] ?? 1; // phpcs:ignore -- csoportos terméknél tömb (quantity[ID])
        $query['mandala_qty'] = max(1, is_array($qty) ? array_sum(array_map('absint', $qty)) : absint($qty));
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

/**
 * „Függőben” kártyás rendelések az elmúlt 30 napból, 20 percnél régebbiek. $returned: igaz – a vásárló visszatért, de a
 * fizetés visszaigazolása nem jött meg (ezeket a WooCommerce nem törli, ezért 30 napig figyeljük); hamis – vissza sem
 * tért (megszakította a fizetést, vagy a visszatérését – böngészős POST – is tiltotta a tűzfal).
 */
function mandala_card_pending_orders(bool $returned): array
{
    if (!function_exists('wc_get_orders')) {
        return [];
    }
    $out = [];
    // Fizetési módonként (a meta_query-t a régi rendelés-tárolás nem ismeri); a jelölést PHP-ben szűrjük.
    foreach (mandala_card_gateways() as $gateway) {
        $orders = wc_get_orders(['status' => 'pending', 'payment_method' => (string) $gateway, 'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS), 'limit' => 100, 'type' => 'shop_order']);
        foreach ($orders as $o) {
            $at = (int) $o->get_meta('_mandala_card_returned');
            $created = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
            if ($returned ? ($at > 0 && $at < time() - 20 * MINUTE_IN_SECONDS) : (!$at && $created && $created < time() - 20 * MINUTE_IN_SECONDS)) {
                $out[] = $o;
            }
        }
    }
    return $out;
}

/** Visszaigazolásra váró kártyás rendelések (visszatért a vásárló, 20 percnél régebben, az elmúlt 30 napból). */
function mandala_card_unconfirmed_orders(): array
{
    return mandala_card_pending_orders(true);
}

/* ---------- Őrszem ---------- */

/** Napi számlálók a mandala_stats táblából: [nap => [metric => n]]. */
function mandala_blk_stats(int $days = MANDALA_BLK_DAYS): array
{
    global $wpdb;
    if (!function_exists('mandala_stats_table') || get_option('mandala_stats_db') === false) {
        return [];
    }
    $list = mandala_blk_days(max(1, $days));
    $from = (string) end($list);
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
    $nets = count(mandala_blk_nets(wp_date('Y-m-d')));
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
    $nets = [];
    foreach (mandala_blk_days() as $d) {
        $nets[$d] = mandala_blk_nets($d);
    }
    $n = fn($d, $k) => (int) ($stats[$d][$k] ?? 0);
    echo '<div class="wrap"><h1>Mandala állapot</h1>';
    echo '<h2>A tárhely tűzfala (ELIN 403) – utolsó ' . (int) MANDALA_BLK_DAYS . ' nap</h2>';
    echo '<p style="max-width:860px">Az ELIN tűzfala egyes látogatók IP-címéről elutasítja a POST kéréseket (kosárba tétel, pénztár, rendelés leadása); a GET-eket átengedi. '
        . 'A vásárló böngészője minden ilyen tiltásnál szól egy kis GET kéréssel – itt látod, naponta hányszor, mely végponton és hány különböző hálózatról (/24, pontos IP-cím nélkül). '
        . 'A „Megmentve” oszlop a GET tartalékkal mégis kosárba tett termékek száma. A „Látogatók” a saját, sütimentes mérés (Kivonat).</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Nap</th><th>Látogatók</th><th>Tiltott kérés</th><th>Oldal tiltással</th><th>Kosárba</th><th>Pénztár frissítése</th><th>Rendelés leadása</th><th>Egyéb</th><th>Hálózatok (/24)</th><th>Megmentve (GET)</th></tr></thead><tbody>';
    foreach (mandala_blk_days() as $d) {
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
    $row = fn($o, $at) => '<li><a href="' . esc_url($o->get_edit_order_url()) . '">#' . esc_html($o->get_order_number()) . '</a> – ' . esc_html($o->get_formatted_billing_full_name()) . ', ' . wp_kses_post(wc_price($o->get_total())) . ', ' . esc_html(wp_date('m. d. H:i', (int) $at)) . '</li>';
    echo '<h2>Kártyás fizetések visszaigazolása (Teya)</h2>';
    if ($waiting) {
        echo '<p>Ezeknél a rendeléseknél a vásárló visszatért a Teyától, de a fizetés visszaigazolása nem jött meg. Nézd meg a Teya felületén; ha sikeres, állítsd „Feldolgozás alatt”-ra.</p><ul>';
        foreach ($waiting as $o) {
            echo $row($o, $o->get_meta('_mandala_card_returned')); // phpcs:ignore
        }
        echo '</ul>';
    } else {
        echo '<p>Nincs visszaigazolásra váró kártyás rendelés.</p>';
    }
    $silent = mandala_card_pending_orders(false);
    if ($silent) {
        echo '<h3>„Függőben” kártyás rendelések visszatérés nélkül (30 nap)</h3><p class="description" style="max-width:860px">A vásárló nem tért vissza a Teyától: '
            . 'vagy megszakította a fizetést, vagy fizetett, de a visszatérését (a Teya böngészős POST-ját) is tiltotta a tárhely tűzfala – ilyenkor fehér „403 Forbidden” oldalt látott. '
            . 'Ha a Teya felületén sikeres a fizetés, állítsd „Feldolgozás alatt”-ra (a vásárló akkor kapja meg a visszaigazolást).</p><ul>';
        foreach ($silent as $o) {
            echo $row($o, $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0); // phpcs:ignore
        }
        echo '</ul>';
    }
    echo '</div>';
}
