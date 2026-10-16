<?php
/**
 * Mandala Partner CRM – összekötő (a webshop oldala). A partnerkezelő CRM a HelloProVision CRM-jén fut (külön
 * WordPress, helloprovision-partner bővítmény); ez a modul küldi neki a viszonteladói adatokat, és végrehajtja a CRM
 * parancsait. Mindig a webshop kezdeményez (kifelé induló, aláírt kérés 10 percenként) – az ELIN tűzfala, amely a
 * bejövő POST-okat tiltja, nem számít.
 *
 * Mit küld (sorban, egy kérésben több esemény):
 *  - application  a „Viszonteladóknak” oldal jelentkezése (iu/form „viszontelado”), a partnerlevél-hozzájárulással;
 *  - customer     viszonteladói fiók: regisztráció, szerepváltás, adatváltozás; marketing_consent (+ _at), terms;
 *  - order        viszonteladó rendelése (létrejötte és minden állapotváltozása, tételekkel, kategóriával; ajánlatból
 *                 jött rendelésnél offer_token, halasztott fizetésnél payment_days / payment_due);
 *  - activity     viszonteladó belépése, árlista- és fotóletöltés, gyorsrendelő, új érkezések feliratkozás;
 *  - message      „Üzenet a Mandalának” a viszonteladói fiókból (crm-partner.php);
 *  - offer_open / offer_order  az ajánlat-link megnyitása, az ajánlatból lett rendelés (crm-partner.php);
 *  - web_stats    a webes forgalom havonta és csatornánként (egyéni / kereskedő) – naponta az aktuális és az előző hónap;
 *  - stock        készlet (cikkszám, név, kategória, darab, nagyker ár, 90 napos eladás, várható érkezés) – a változás
 *                 10 percenként (a JUTA közvetlen adatbázis-írása után is), a teljes lista naponta;
 *  - hello        a webshop állapota (verzió, viszonteladók száma, szinkron).
 * Mit kap: approve (viszonteladói szerep; ha nincs fiók, nyit egyet, és a WooCommerce elküldi a belépési levelet),
 * terms (kedvezményszint, fizetési határidő, rendelés-tiltás – crm-partner.php), resync (teljes újraküldés). Az
 * eredményt a következő kérésben nyugtázza. Az ajánlat-link (?mandala_offer=…) a közös titokkal aláírt: crm-partner.php.
 *
 * Beállítás: WooCommerce → Partner CRM → az összekötő kód a CRM-ből (Partnerek → Beállítások), vagy a wp-config.php-ban
 * MANDALA_CRM_URL + MANDALA_CRM_SECRET. Protokoll: hpv-partner-v1 (a CRM oldala: helloprovision-partner/includes/core.php).
 */

defined('ABSPATH') || exit;

const MANDALA_CRM_QUEUE = 'mandala_crm_queue';
const MANDALA_CRM_STATE = 'mandala_crm_state';
const MANDALA_CRM_MAX_QUEUE = 5000;

/** A CRM címe és a közös titok: [url, secret] vagy null. */
function mandala_crm_config(): ?array
{
    if (defined('MANDALA_CRM_URL') && defined('MANDALA_CRM_SECRET') && MANDALA_CRM_URL && strlen((string) MANDALA_CRM_SECRET) >= 24) {
        return [(string) MANDALA_CRM_URL, (string) MANDALA_CRM_SECRET];
    }
    $o = get_option('mandala_crm');
    return is_array($o) && !empty($o['url']) && strlen((string) ($o['secret'] ?? '')) >= 24 ? [(string) $o['url'], (string) $o['secret']] : null;
}

/** Az összekötő kód (a CRM adja): base64url JSON { v, url, secret }. */
function mandala_crm_decode_code(string $code): ?array
{
    $raw = base64_decode(strtr(trim($code), '-_', '+/'), true);
    $d = $raw ? json_decode($raw, true) : null;
    if (!is_array($d) || empty($d['url']) || strlen((string) ($d['secret'] ?? '')) < 24 || !preg_match('#^https://#i', (string) $d['url'])) {
        return null;
    }
    return ['url' => esc_url_raw((string) $d['url']), 'secret' => (string) $d['secret']];
}

/** Aláírás – bájtra egyezik a CRM hpv_pt_sign() függvényével (tesztvektor: tests/wp-features.mjs, hlprv tests/partner.php). */
function mandala_crm_sign(string $secret, string $ts, string $nonce, string $body): string
{
    return hash_hmac('sha256', "hpv-partner-v1\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
}

/* ---------- Sor ---------- */

function mandala_crm_enqueue(string $type, array $data): void
{
    if (!mandala_crm_config()) {
        return; // nincs összekötve: nem gyűjtünk
    }
    $q = get_option(MANDALA_CRM_QUEUE, []);
    $q = is_array($q) ? $q : [];
    $q[] = ['type' => $type, 'data' => $data];
    if (count($q) > MANDALA_CRM_MAX_QUEUE) {
        $q = array_slice($q, -MANDALA_CRM_MAX_QUEUE);
    }
    update_option(MANDALA_CRM_QUEUE, $q, false);
}

/** Fontos esemény (jelentkezés, új viszonteladó): ne várjon 10 percet – egy háttérfeladat most küldi. */
function mandala_crm_soon(): void
{
    if (function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_crm_sync', [true], MANDALA_AS_GROUP)) {
        as_enqueue_async_action('mandala_crm_sync', [true], MANDALA_AS_GROUP);
    }
}

function mandala_crm_state(array $merge = []): array
{
    $s = get_option(MANDALA_CRM_STATE, []);
    $s = is_array($s) ? $s : [];
    if ($merge) {
        $s = array_merge($s, $merge);
        update_option(MANDALA_CRM_STATE, $s, false);
    }
    return $s;
}

/**
 * Egy kérés a CRM-nek: a sor eleje (legfeljebb $max esemény) + a nyugták. Siker esetén a parancsok végrehajtása.
 *
 * @return array|WP_Error a CRM válasza
 */
function mandala_crm_post(int $max = 300)
{
    $cfg = mandala_crm_config();
    if (!$cfg) {
        return new WP_Error('mandala_crm', 'Nincs összekötve a CRM-mel.');
    }
    $queue = get_option(MANDALA_CRM_QUEUE, []);
    $queue = is_array($queue) ? $queue : [];
    $acks = get_option('mandala_crm_acks', []);
    $acks = is_array($acks) ? $acks : [];
    $batch = array_slice($queue, 0, $max);
    $body = wp_json_encode(['events' => $batch, 'acks' => $acks]);
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $res = wp_remote_post($cfg[0], [
        'timeout' => 25,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-HPV-Partner-Ts' => $ts,
            'X-HPV-Partner-Nonce' => $nonce,
            'X-HPV-Partner-Sig' => mandala_crm_sign($cfg[1], $ts, $nonce, (string) $body),
        ],
        'body' => $body,
    ]);
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $data = is_wp_error($res) ? null : json_decode((string) wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300 || !is_array($data)) {
        $msg = is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . $code . (is_array($data) && !empty($data['message']) ? ': ' . $data['message'] : '');
        mandala_crm_state(['error' => $msg, 'error_at' => time()]);
        return new WP_Error('mandala_crm', $msg);
    }
    // Elküldve: le a sorról (ami közben jött, az marad), a nyugták törölve.
    $now = get_option(MANDALA_CRM_QUEUE, []);
    update_option(MANDALA_CRM_QUEUE, array_slice(is_array($now) ? $now : [], count($batch)), false);
    update_option('mandala_crm_acks', [], false);
    $done = [];
    foreach (array_slice((array) ($data['commands'] ?? []), 0, 50) as $cmd) {
        $done[] = mandala_crm_run_command((array) $cmd);
    }
    if ($done) {
        update_option('mandala_crm_acks', $done, false);
    }
    mandala_crm_state(['ok_at' => time(), 'sent' => (int) (mandala_crm_state()['sent'] ?? 0) + count($batch), 'error' => (string) ($data['errors'][0] ?? '')]);
    return $data;
}

/** A sor kiküldése (több kérésben), a parancsok nyugtázásával. */
function mandala_crm_flush(int $rounds = 6): void
{
    for ($i = 0; $i < $rounds; $i++) {
        $r = mandala_crm_post();
        if (is_wp_error($r)) {
            return;
        }
        $left = get_option(MANDALA_CRM_QUEUE, []);
        if (!$left && !get_option('mandala_crm_acks', [])) {
            return;
        }
    }
}

/* ---------- Parancsok ---------- */

function mandala_crm_run_command(array $cmd): array
{
    $id = (int) ($cmd['id'] ?? 0);
    $d = (array) ($cmd['data'] ?? []);
    try {
        switch ((string) ($cmd['type'] ?? '')) {
            case 'approve':
                return ['id' => $id, 'ok' => true, 'result' => mandala_crm_approve($d)];
            case 'terms':
                return ['id' => $id, 'ok' => true, 'result' => mandala_crm_terms($d)];
            case 'resync':
                if ((string) ($d['what'] ?? '') === 'web_stats') {
                    mandala_crm_web_stats_add(mandala_crm_last_months(13));
                } else {
                    mandala_crm_backfill_start((string) ($d['what'] ?? 'all'));
                }
                return ['id' => $id, 'ok' => true, 'result' => ['started' => true]];
        }
        return ['id' => $id, 'ok' => false, 'result' => ['error' => 'ismeretlen parancs']];
    } catch (Throwable $e) {
        return ['id' => $id, 'ok' => false, 'result' => ['error' => $e->getMessage()]];
    }
}

/**
 * Jóváhagyás: viszonteladói szerep. Meglévő fióknál a szerep cseréje (a sima vásárlói helyett; adminnál hozzáadás);
 * ha nincs fiók, a WooCommerce nyit egyet, és elküldi a fiók-levelet (jelszó-beállító linkkel).
 */
function mandala_crm_approve(array $d): array
{
    $email = sanitize_email((string) ($d['email'] ?? ''));
    $user = mandala_crm_find_user($d) ?: false;
    $role = (string) (((array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']))[0] ?? 'wholesale_customer');
    $created = false;
    if (!$user) {
        if (!is_email($email) || !function_exists('wc_create_new_customer')) {
            throw new RuntimeException('nincs érvényes e-mail-cím');
        }
        $uid = wc_create_new_customer($email, '', wp_generate_password(20), [
            'first_name' => sanitize_text_field((string) ($d['first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($d['last_name'] ?? '')),
            'role' => $role,
        ]);
        if (is_wp_error($uid)) {
            throw new RuntimeException($uid->get_error_message());
        }
        $user = get_userdata($uid);
        $created = true;
    }
    if (!in_array($role, (array) $user->roles, true)) {
        // Hozzáadjuk a viszonteladói szerepet (a fiók többi szerepe – pl. bolti munkatárs – megmarad); csak a sima
        // „customer” szerep megy le, hogy ne legyen kétféle vásárló egyszerre.
        $user->add_role($role);
        if (in_array('customer', (array) $user->roles, true)) {
            $user->remove_role('customer');
        }
    }
    // Számlázási adatok, ha üresek (a CRM-ben javított adattal).
    $map = ['billing_company' => 'company', 'billing_phone' => 'phone', 'billing_postcode' => 'zip', 'billing_city' => 'city', 'billing_address_1' => 'street', 'billing_tax_number' => 'tax_number', 'billing_first_name' => 'first_name', 'billing_last_name' => 'last_name'];
    foreach ($map as $meta => $k) {
        if (!empty($d[$k]) && (string) get_user_meta($user->ID, $meta, true) === '') {
            update_user_meta($user->ID, $meta, sanitize_text_field((string) $d[$k]));
        }
    }
    if ((string) get_user_meta($user->ID, 'billing_email', true) === '') {
        update_user_meta($user->ID, 'billing_email', $user->user_email);
    }
    update_user_meta($user->ID, '_mandala_crm_approved', time());
    clean_user_cache($user->ID);
    return ['user_id' => (int) $user->ID, 'created' => $created];
}

/** A parancs fiókja: a webshop-azonosító, ha az e-mail egyezik (vagy nincs e-mail); különben az e-mail-cím szerint. */
function mandala_crm_find_user(array $d): ?WP_User
{
    $email = sanitize_email((string) ($d['email'] ?? ''));
    $user = !empty($d['user_id']) ? get_userdata((int) $d['user_id']) : false;
    if ((!$user || strtolower($user->user_email) !== strtolower($email)) && $email) {
        $user = get_user_by('email', $email) ?: false;
    }
    return $user instanceof WP_User ? $user : null;
}

/* ---------- Adatok ---------- */

/**
 * Időpont a CRM-nek: ISO 8601 zónával ('c', pl. 2026-10-16T09:40:00+02:00), hogy a CRM ne csúsztassa el (a régi, zóna
 * nélküli helyi időt a CRM UTC-nek vette). $v: unix idő, helyi 'Y-m-d H:i:s' (a webhely zónájában), vagy 'now'.
 */
function mandala_crm_time($v = 'now'): string
{
    if ($v === '' || $v === null || $v === 0 || $v === '0') {
        return '';
    }
    if (is_int($v) || (is_string($v) && ctype_digit($v))) {
        return wp_date('c', (int) $v);
    }
    try {
        return (new DateTimeImmutable((string) $v, wp_timezone()))->format('c');
    } catch (Exception $e) {
        return '';
    }
}

function mandala_crm_customer_data(int $uid): array
{
    $u = get_userdata($uid);
    if (!$u) {
        return [];
    }
    $m = fn($k) => (string) get_user_meta($uid, $k, true);
    return [
        'user_id' => $uid,
        // A párosítás a fiók e-mail-címével (user_email) – a számlázási e-mailt a vásárló maga írja át, az nem köt.
        'email' => $u->user_email,
        'billing_email' => $m('billing_email'),
        'first_name' => $m('billing_first_name') ?: $m('first_name'),
        'last_name' => $m('billing_last_name') ?: $m('last_name'),
        'company' => $m('billing_company'),
        'tax_number' => $m('billing_tax_number') ?: $m('wc_szamlazz_adoszam'),
        'phone' => $m('billing_phone'),
        'zip' => $m('billing_postcode'),
        'city' => $m('billing_city'),
        'street' => trim($m('billing_address_1') . ' ' . $m('billing_address_2')),
        'country' => $m('billing_country') ?: 'HU',
        'wholesale' => mandala_is_wholesale_user($uid),
        'registered' => $u->user_registered ? gmdate('c', (int) strtotime($u->user_registered . ' UTC')) : '',
        'last_active' => ($la = (int) $m('wc_last_active')) ? wp_date('c', $la) : '',
        // Partnerlevél-hozzájárulás (a jelentkezés pipája, a fiókban módosítható) és a CRM-től kapott feltételek.
        'marketing_consent' => $m('_mandala_partner_mail') === 'yes',
        'marketing_consent_at' => mandala_crm_time($m('_mandala_partner_mail_at')),
        'terms' => mandala_crm_terms_of($uid),
    ];
}

function mandala_crm_order_data(WC_Order $order, bool $backfill = false): array
{
    $items = [];
    $net = 0.0;
    foreach ($order->get_items() as $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }
        $product = $item->get_product();
        $cat = '';
        if ($product) {
            $terms = get_the_terms($product->get_parent_id() ?: $product->get_id(), 'product_cat');
            $cat = $terms && !is_wp_error($terms) ? $terms[0]->name : '';
        }
        $line = (float) $item->get_total();
        $net += $line;
        $items[] = ['sku' => $product ? (string) $product->get_sku() : '', 'name' => $item->get_name(), 'qty' => (float) $item->get_quantity(), 'net' => round($line, 2), 'category' => $cat];
    }
    $created = $order->get_date_created();
    return [
        'id' => $order->get_id(),
        'number' => (string) $order->get_order_number(),
        'user_id' => (int) $order->get_customer_id(),
        'email' => $order->get_billing_email(),
        'company' => $order->get_billing_company(),
        'name' => trim($order->get_billing_last_name() . ' ' . $order->get_billing_first_name()),
        'tax_number' => (string) $order->get_meta('_billing_tax_number'),
        'created' => $created ? $created->date('c') : '', // ISO zónával (a CRM a saját zónájába váltja)
        'status' => $order->get_status(),
        'net' => round($net, 2),
        'gross' => (float) $order->get_total(),
        'currency' => $order->get_currency(),
        'payment' => $order->get_payment_method_title(),
        'payment_method' => $order->get_payment_method(),
        'payment_days' => (int) $order->get_meta('_mandala_payment_days'),
        'payment_due' => (string) $order->get_meta('_mandala_payment_due'),
        'offer_token' => (string) $order->get_meta('_mandala_offer_token'),
        'items' => $items,
        'wholesale' => mandala_is_wholesale_user((int) $order->get_customer_id()),
        'backfill' => $backfill,
    ];
}

/** Viszonteladó rendelése-e (a vásárló szerepe, vagy a rendeléskor rögzített jelölő). */
function mandala_crm_is_wholesale_order(WC_Order $order): bool
{
    return mandala_is_wholesale_user((int) $order->get_customer_id()) || $order->get_meta('_mandala_wholesale') === 'yes';
}

/**
 * Készlet: a termékindex (név, kategória, cím) + friss készlet a wc_product_meta_lookup táblából (a JUTA közvetlenül
 * írja), nagyker ár, várható érkezés, 90 napos eladás – lekérdezésenként egyben, nem termékenként.
 *
 * @return array sku => sor
 */
function mandala_crm_stock_rows(): array
{
    global $wpdb;
    $lookup = [];
    foreach ($wpdb->get_results("SELECT product_id, sku, stock_quantity, stock_status FROM {$wpdb->prefix}wc_product_meta_lookup", ARRAY_A) as $r) {
        $lookup[(int) $r['product_id']] = $r;
    }
    $metakey = (string) apply_filters('mandala_wholesale_meta_key', 'wholesale_customer_wholesale_price');
    $wholesale = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", $metakey), ARRAY_A) as $r) {
        $wholesale[(int) $r['post_id']] = (float) $r['meta_value'];
    }
    $incoming = [];
    foreach ($wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_incoming' AND meta_value >= '" . esc_sql(wp_date('Y-m-d')) . "'", ARRAY_A) as $r) {
        $incoming[(int) $r['post_id']] = (string) $r['meta_value'];
    }
    $sold = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_order_product_lookup'")) {
        $since = wp_date('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT IF(variation_id > 0, variation_id, product_id) pid, SUM(product_qty) q FROM {$wpdb->prefix}wc_order_product_lookup WHERE date_created >= %s GROUP BY pid", $since), ARRAY_A) as $r) {
            $sold[(int) $r['pid']] = (int) $r['q'];
        }
    }
    $out = [];
    foreach (mandala_product_index() as $row) {
        $id = (int) $row['id'];
        $l = $lookup[$id] ?? null;
        $sku = (string) ($row['sku'] ?: ($l['sku'] ?? ''));
        if ($sku === '' || !empty($row['voucher'])) {
            continue;
        }
        $out[$sku] = [
            'sku' => $sku,
            'product_id' => $id,
            'name' => (string) $row['name'],
            'category' => (string) ($row['catLabel'] ?? ''),
            'stock' => $l && $l['stock_quantity'] !== null ? (int) $l['stock_quantity'] : null,
            'in_stock' => $l ? $l['stock_status'] !== 'outofstock' : ($row['stock'] ?? '') !== 'out',
            'wholesale' => $wholesale[$id] ?? null,
            'retail' => isset($row['price']) ? (float) $row['price'] : null,
            'sold90' => $sold[$id] ?? 0,
            'url' => (string) ($row['url'] ?? ''),
            'image' => (string) ($row['img'] ?? ''),
            'incoming' => isset($incoming[$id]) ? wp_date('Y. m. d.', strtotime($incoming[$id])) : '',
        ];
    }
    return $out;
}

/** Készlet a sorba: a változás (ujjlenyomat szerint), vagy a teljes lista. */
function mandala_crm_queue_stock(bool $full = false): int
{
    $rows = mandala_crm_stock_rows();
    $old = get_option('mandala_crm_stock_fp', []);
    $old = is_array($old) ? $old : [];
    $fp = [];
    $changed = [];
    foreach ($rows as $sku => $r) {
        $fp[$sku] = substr(md5(wp_json_encode([$r['stock'], $r['in_stock'], $r['wholesale'], $r['retail'], $r['name'], $r['incoming'], $r['sold90']])), 0, 12);
        if ($full || ($old[$sku] ?? '') !== $fp[$sku]) {
            $changed[] = $r;
        }
    }
    // Ami eltűnt a boltból (kivezetett / törölt): elfogyottként.
    foreach (array_diff_key($old, $fp) as $sku => $x) {
        if (!$full) {
            $changed[] = ['sku' => (string) $sku, 'stock' => 0, 'in_stock' => false];
        }
    }
    if ($full) {
        $chunks = array_chunk($changed, 400) ?: [[]];
        foreach ($chunks as $i => $chunk) {
            mandala_crm_enqueue('stock', ['items' => $chunk, 'full_start' => $i === 0, 'full_end' => $i === count($chunks) - 1]);
        }
        update_option('mandala_crm_stock_full_at', time(), false);
    } else {
        foreach (array_chunk($changed, 400) as $chunk) {
            mandala_crm_enqueue('stock', ['items' => $chunk]);
        }
    }
    update_option('mandala_crm_stock_fp', $fp, false);
    return count($changed);
}

/* ---------- Webes forgalom havonta (web_stats) ---------- */

/** Az utolsó $n hónap ('YYYY-MM'), a legrégebbitől az aktuálisig. */
function mandala_crm_last_months(int $n): array
{
    $first = new DateTimeImmutable(wp_date('Y-m-01'), wp_timezone());
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $out[] = $first->modify("-$i month")->format('Y-m');
    }
    return $out;
}

/**
 * Egy hónap webes forgalma csatornánként: minden rendelés (a teljesített és a feldolgozás alatti; a lemondott,
 * visszatérített, függő nem), a csatorna a vásárló viszonteladói szerepe / a rendelés jelölője szerint. A visszatérített
 * részösszegek levonva. net = a termékek és díjak nettója (szállítás és ÁFA nélkül), shipping_net = a szállítás nettója.
 * HPOS-kompatibilis (wc_get_orders), oldalanként 200 rendelés.
 *
 * @return array{egyeni: array, kereskedo: array}
 */
function mandala_crm_web_stats(string $month): array
{
    $tz = wp_timezone();
    $from = new DateTimeImmutable($month . '-01 00:00:00', $tz);
    $to = $from->modify('+1 month');
    $zero = ['orders' => 0, 'net' => 0.0, 'gross' => 0.0, 'items' => 0, 'shipping_net' => 0.0];
    $out = ['egyeni' => $zero, 'kereskedo' => $zero];
    $statuses = (array) apply_filters('mandala_crm_web_stats_statuses', ['completed', 'processing']);
    for ($page = 1; $page <= 500; $page++) {
        $orders = wc_get_orders(['type' => 'shop_order', 'status' => $statuses, 'date_created' => $from->getTimestamp() . '...' . ($to->getTimestamp() - 1), 'limit' => 200, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC']);
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order || !apply_filters('mandala_crm_web_stats_include', true, $order)) {
                continue;
            }
            $ch = mandala_crm_is_wholesale_order($order) ? 'kereskedo' : 'egyeni';
            $gross = (float) $order->get_total() - (float) $order->get_total_refunded();
            $tax = (float) $order->get_total_tax() - (float) $order->get_total_tax_refunded();
            $ship = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();
            $out[$ch]['orders']++;
            $out[$ch]['gross'] += $gross;
            $out[$ch]['shipping_net'] += $ship;
            $out[$ch]['net'] += $gross - $tax - $ship;
            $out[$ch]['items'] += (int) $order->get_item_count() - (int) $order->get_item_count_refunded();
        }
        if (count($orders) < 200) {
            break;
        }
    }
    foreach ($out as $ch => $row) {
        foreach (['net', 'gross', 'shipping_net'] as $k) {
            $out[$ch][$k] = round($row[$k], 2);
        }
    }
    return $out;
}

/** Hónapok a számolandók közé (a 10 perces szinkron adagonként számolja és küldi). */
function mandala_crm_web_stats_add(array $months): void
{
    $todo = (array) get_option('mandala_crm_web_stats_todo', []);
    $todo = array_values(array_unique(array_merge($todo, array_filter($months, fn($m) => preg_match('/^\d{4}-\d{2}$/', (string) $m)))));
    update_option('mandala_crm_web_stats_todo', array_slice($todo, -24), false);
}

/** Legfeljebb $max hónap kiszámolása és sorba tétele (csatornánként egy web_stats esemény). */
function mandala_crm_web_stats_step(int $max = 2): int
{
    $todo = (array) get_option('mandala_crm_web_stats_todo', []);
    if (!$todo || !mandala_crm_config() || !function_exists('wc_get_orders')) {
        return 0;
    }
    $done = 0;
    while ($todo && $done < $max) {
        $month = (string) array_shift($todo);
        foreach (mandala_crm_web_stats($month) as $channel => $row) {
            mandala_crm_enqueue('web_stats', ['month' => $month, 'channel' => $channel] + $row + ['computed_at' => mandala_crm_time()]);
        }
        $done++;
        update_option('mandala_crm_web_stats_todo', $todo, false);
    }
    return $done;
}

// Naponta hajnalban: az előző és az aktuális hónap (a késve teljesülő / visszatérített rendelések miatt az előző is).
mandala_recurring('mandala_crm_web_stats_daily', DAY_IN_SECONDS, fn() => (new DateTimeImmutable('tomorrow 03:20', wp_timezone()))->getTimestamp());
add_action('mandala_crm_web_stats_daily', function () {
    if (mandala_crm_config()) {
        mandala_crm_web_stats_add(mandala_crm_last_months(2));
        mandala_crm_web_stats_step();
    }
});

/* ---------- Teljes (újra)küldés ---------- */

function mandala_crm_backfill_start(string $what = 'all'): void
{
    update_option('mandala_crm_backfill', ['what' => in_array($what, ['customers', 'orders', 'stock', 'all'], true) ? $what : 'all', 'cursor' => 0, 'started' => time()], false);
}

/** Egy adag: viszonteladók (és a rendeléseik), a végén a teljes készlet. */
function mandala_crm_backfill_step(int $users_per_step = 40): void
{
    $b = get_option('mandala_crm_backfill');
    if (!is_array($b)) {
        return;
    }
    if ($b['what'] !== 'stock') {
        $ids = get_users(['role__in' => (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']), 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC', 'number' => $users_per_step, 'offset' => (int) $b['cursor']]);
        foreach ($ids as $uid) {
            if ($b['what'] !== 'orders') {
                mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
            }
            if ($b['what'] !== 'customers' && function_exists('wc_get_orders')) {
                foreach (wc_get_orders(['customer_id' => (int) $uid, 'limit' => 300, 'orderby' => 'date', 'order' => 'ASC', 'type' => 'shop_order']) as $order) {
                    mandala_crm_enqueue('order', mandala_crm_order_data($order, true));
                }
            }
        }
        if (count($ids) === $users_per_step) {
            $b['cursor'] = (int) $b['cursor'] + $users_per_step;
            update_option('mandala_crm_backfill', $b, false);
            return;
        }
    }
    if (in_array($b['what'], ['stock', 'all'], true)) {
        mandala_crm_queue_stock(true);
    }
    if ($b['what'] === 'all') {
        mandala_crm_web_stats_add(mandala_crm_last_months(13)); // a webes forgalom egy évre visszamenőleg
    }
    delete_option('mandala_crm_backfill');
    mandala_crm_state(['backfill_done' => time()]);
}

/* ---------- Ütemezés: 10 percenként (és azonnal, ha fontos esemény jött) ---------- */

mandala_recurring('mandala_crm_sync', 10 * MINUTE_IN_SECONDS, fn() => time() + 120);

add_action('mandala_crm_sync', function ($soon = false) {
    if (!mandala_crm_config()) {
        return;
    }
    if (!$soon) {
        mandala_crm_backfill_step();
        $full = (int) get_option('mandala_crm_stock_full_at', 0) < time() - DAY_IN_SECONDS;
        if (!get_option('mandala_crm_backfill')) {
            mandala_crm_queue_stock($full);
            mandala_crm_web_stats_step();
        }
        $st = mandala_crm_state();
        if ((int) ($st['hello_at'] ?? 0) < time() - HOUR_IN_SECONDS) {
            mandala_crm_enqueue('hello', mandala_crm_hello());
            mandala_crm_state(['hello_at' => time()]);
        }
    }
    mandala_crm_flush();
});

function mandala_crm_hello(): array
{
    $count = count_users();
    $roles = (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']);
    return [
        'site' => wp_parse_url(home_url(), PHP_URL_HOST),
        'version' => (string) wp_get_theme()->get('Version'),
        'wholesale_users' => array_sum(array_map(fn($r) => (int) ($count['avail_roles'][$r] ?? 0), $roles)),
        'queue' => count((array) get_option(MANDALA_CRM_QUEUE, [])),
        'backfill' => get_option('mandala_crm_backfill') ? 'fut' : 'kész',
        'features' => 'approve,resync,terms,message,web_stats,consent,offer', // amit ez a témaverzió tud (a CRM ebből láthatja)
    ];
}

/* ---------- Események a webshopból ---------- */

// „Viszonteladóknak” jelentkezés (a feldolgozó után, csak sikeres beküldésnél).
add_filter('iu_form_submit_viszontelado', function ($response) {
    $post = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification -- az iu_theme űrlapkezelője ellenőriz
    if (!is_array($response) || empty($response['success']) || !empty($response['errors']) || !empty($post['website'])) {
        return $response;
    }
    $f = fn($k) => sanitize_text_field((string) ($post[$k] ?? ''));
    $msg = array_filter([
        sanitize_textarea_field((string) ($post['message'] ?? '')),
        $f('forgalom') ? 'Várható havi rendelés: ' . $f('forgalom') : '',
        $f('erdeklodes') ? 'Érdeklődés: ' . $f('erdeklodes') : '',
    ]);
    mandala_crm_enqueue('application', [
        'email' => sanitize_email((string) ($post['email'] ?? '')),
        'name' => $f('nev'),
        'company' => $f('ceg'),
        'tax_number' => $f('adoszam'),
        'phone' => $f('telefon'),
        'city' => $f('telepules'),
        'website' => $f('weboldal'),
        'type' => $f('tevekenyseg'),
        'message' => implode("\n", $msg),
        'user_id' => get_current_user_id(),
        'marketing_consent' => !empty($post['partnerlevelek']), // „Kérek partnerleveleket” (crm-partner.php)
        'marketing_consent_at' => mandala_crm_time(),
    ]);
    mandala_crm_soon();
    return $response;
}, 20);

// Viszonteladói fiók: új, szerepváltás, adatváltozás.
add_action('user_register', function ($uid) {
    if (mandala_is_wholesale_user((int) $uid)) {
        mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
        mandala_crm_soon();
    }
}, 30);
add_action('set_user_role', function ($uid, $role, $old = []) {
    $roles = (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']);
    if (in_array($role, $roles, true) || array_intersect($roles, (array) $old)) {
        mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
    }
}, 30, 3);
add_action('add_user_role', function ($uid, $role) {
    if (in_array($role, (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']), true)) {
        mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
    }
}, 30, 2);
foreach (['profile_update', 'woocommerce_customer_save_address'] as $mandala_hook) {
    add_action($mandala_hook, function ($uid) {
        static $seen = [];
        if (!isset($seen[$uid]) && mandala_is_wholesale_user((int) $uid)) {
            $seen[$uid] = true;
            mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
        }
    }, 30);
}

// Viszonteladó rendelése: létrejötte és minden állapotváltozása.
add_action('woocommerce_checkout_order_processed', fn($order_id) => mandala_crm_order_event((int) $order_id), 30);
add_action('woocommerce_order_status_changed', fn($order_id) => mandala_crm_order_event((int) $order_id), 30);
function mandala_crm_order_event(int $order_id): void
{
    static $seen = [];
    $order = wc_get_order($order_id);
    if (!$order || isset($seen[$order_id]) || !mandala_crm_is_wholesale_order($order)) {
        return;
    }
    $seen[$order_id] = true;
    if ($order->get_meta('_mandala_wholesale') !== 'yes') {
        $order->update_meta_data('_mandala_wholesale', 'yes'); // a rendelés akkor is viszonteladói marad, ha a szerep később változik
        $order->save_meta_data();
    }
    mandala_crm_enqueue('order', mandala_crm_order_data($order));
}

// Viszonteladó a webshopban: belépés, árlista, fotók, gyorsrendelő, új érkezések levél.
add_action('wp_login', function ($login, $user) {
    if ($user instanceof WP_User && mandala_is_wholesale_user((int) $user->ID)) {
        mandala_crm_enqueue('activity', ['user_id' => (int) $user->ID, 'email' => $user->user_email, 'kind' => 'login', 'at' => mandala_crm_time()]);
    }
}, 30, 2);
add_action('mandala_b2b_activity', function ($kind, $meta = []) {
    $uid = get_current_user_id();
    if ($uid && mandala_is_wholesale_user($uid)) {
        mandala_crm_enqueue('activity', ['user_id' => $uid, 'kind' => (string) $kind, 'at' => mandala_crm_time(), 'meta' => (array) $meta]);
    }
}, 10, 2);

/* ---------- Admin: WooCommerce → Partner CRM ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Partner CRM', 'Partner CRM', 'manage_woocommerce', 'mandala-crm', 'mandala_crm_admin_page');
});

add_action('admin_post_mandala_crm_save', function () {
    if (!current_user_can('manage_woocommerce') || !check_admin_referer('mandala_crm')) {
        wp_die('Nincs jogosultság.');
    }
    $msg = '';
    if (isset($_POST['disconnect'])) {
        delete_option('mandala_crm');
        delete_option(MANDALA_CRM_QUEUE);
        $msg = 'disconnected';
    } elseif (isset($_POST['code'])) {
        $d = mandala_crm_decode_code((string) wp_unslash($_POST['code']));
        if ($d) {
            $first = !mandala_crm_config();
            update_option('mandala_crm', $d, false);
            if ($first || !get_option('mandala_crm_stock_fp')) {
                mandala_crm_backfill_start('all'); // első összekötés: minden viszonteladó, rendelés és a készlet
            }
            mandala_crm_enqueue('hello', mandala_crm_hello());
            $r = mandala_crm_post(1);
            $msg = is_wp_error($r) ? 'error' : 'saved';
        } else {
            $msg = 'badcode';
        }
    } elseif (isset($_POST['resync'])) {
        mandala_crm_backfill_start('all');
        $msg = 'resync';
    } elseif (isset($_POST['test'])) {
        mandala_crm_enqueue('hello', mandala_crm_hello());
        $r = mandala_crm_post(50);
        $msg = is_wp_error($r) ? 'error' : 'tested';
    } elseif (isset($_POST['levels'])) {
        update_option('mandala_crm_levels', mandala_crm_parse_levels((string) wp_unslash($_POST['levels'])), false);
        $msg = 'levels';
    }
    wp_safe_redirect(add_query_arg(['page' => 'mandala-crm', 'msg' => $msg], admin_url('admin.php')));
    exit;
});

function mandala_crm_admin_page(): void
{
    $cfg = mandala_crm_config();
    $st = mandala_crm_state();
    $q = (array) get_option(MANDALA_CRM_QUEUE, []);
    $b = get_option('mandala_crm_backfill');
    $msgs = [
        'saved' => ['success', 'Összekötve. A viszonteladók, a rendeléseik és a készlet a következő percekben átmennek a CRM-be (10 percenként egy adag).'],
        'tested' => ['success', 'A CRM válaszolt – a kapcsolat rendben.'],
        'resync' => ['info', 'A teljes újraküldés elindult (10 percenként egy adag).'],
        'disconnected' => ['warning', 'Szétkapcsolva. A webshop nem küld többet a CRM-nek.'],
        'levels' => ['success', 'A kedvezményszintek mentve.'],
        'badcode' => ['error', 'Ez nem érvényes összekötő kód. Másold ki újra a CRM-ből (Partnerek → Beállítások → Összekötő kód).'],
        'error' => ['error', 'A CRM most nem válaszolt: ' . ($st['error'] ?? '') . '. A webshop 10 percenként újrapróbálja.'],
    ];
    $m = $msgs[sanitize_key($_GET['msg'] ?? '')] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
    echo '<div class="wrap"><h1>Partner CRM</h1>';
    if ($m) {
        echo '<div class="notice notice-' . esc_attr($m[0]) . '"><p>' . esc_html($m[1]) . '</p></div>';
    }
    echo '<p>A viszonteladói jelentkezések, fiókok, rendelések, belépések, árlista-letöltések és a készlet a Mandala Partner CRM-be mennek; a CRM-ben jóváhagyott jelentkező itt kapja meg a viszonteladói szerepet. Mindig a webshop kapcsolódik a CRM-hez, 10 percenként.</p>';
    echo '<table class="widefat striped" style="max-width:760px"><tbody>';
    $row = fn($k, $v) => print('<tr><th style="width:220px">' . esc_html($k) . '</th><td>' . $v . '</td></tr>'); // phpcs:ignore
    $row('Állapot', $cfg ? '<strong style="color:#14692f">összekötve</strong> – ' . esc_html(wp_parse_url($cfg[0], PHP_URL_HOST)) . (defined('MANDALA_CRM_URL') ? ' (wp-config.php)' : '') : '<strong>nincs összekötve</strong>');
    $row('Utolsó sikeres kapcsolat', !empty($st['ok_at']) ? esc_html(wp_date('Y-m-d H:i', (int) $st['ok_at'])) : '–');
    $row('Elküldött esemény', esc_html((string) (int) ($st['sent'] ?? 0)));
    $row('Sorban áll', esc_html((string) count($q)));
    $row('Teljes küldés', $b ? 'folyamatban (' . (int) $b['cursor'] . ' viszonteladó kész)' : (!empty($st['backfill_done']) ? 'kész: ' . esc_html(wp_date('Y-m-d H:i', (int) $st['backfill_done'])) : '–'));
    if (!empty($st['error'])) {
        $row('Utolsó hiba', '<span style="color:#b3261e">' . esc_html((string) $st['error']) . '</span>' . (!empty($st['error_at']) ? ' (' . esc_html(wp_date('Y-m-d H:i', (int) $st['error_at'])) . ')' : ''));
    }
    echo '</tbody></table>';
    $form = fn($inner) => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px">' . wp_nonce_field('mandala_crm', '_wpnonce', true, false) . '<input type="hidden" name="action" value="mandala_crm_save">' . $inner . '</form>';
    if (!defined('MANDALA_CRM_URL')) {
        echo $form('<h2>Összekötő kód</h2><p>A CRM-ben: Partnerek → Beállítások → Összekötő kód. Titkos – csak ide másold.</p><textarea name="code" rows="3" style="width:100%;max-width:760px" placeholder="' . ($cfg ? 'Új kód (csak ha cserélni kell)' : 'eyJ2Ijox…') . '"></textarea><p><button class="button button-primary">Mentés és kapcsolódás</button></p>'); // phpcs:ignore
    }
    if ($cfg) {
        echo $form('<p><button class="button" name="test" value="1">Kapcsolat próbája most</button> <button class="button" name="resync" value="1">Teljes újraküldés</button> ' . (!defined('MANDALA_CRM_URL') ? '<button class="button button-link-delete" name="disconnect" value="1" onclick="return confirm(\'Biztosan szétkapcsolod?\')">Szétkapcsolás</button>' : '') . '</p>'); // phpcs:ignore
    }
    // Kedvezményszintek: a CRM a szint nevét küldi (pl. „Kiemelt”); itt adható meg, hány % jár a nagyker árból.
    $levels = (array) get_option('mandala_crm_levels', []);
    $text = implode("\n", array_map(fn($k, $v) => $k . ' = ' . rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'), array_keys($levels), $levels));
    echo $form('<h2>Kedvezményszintek</h2><p>A CRM a partner kartonján beállított szint nevét küldi. Ha egy szinthez százalék is jár, írd ide soronként (pl. <code>Kiemelt = 5</code>): a viszonteladó a kosárban ennyivel olcsóbban kapja a nagyker árat. Üresen a szint csak a fiókjában látszik.</p><textarea name="levels" rows="4" style="width:100%;max-width:420px" placeholder="Alap nagyker = 0' . "\n" . 'Kiemelt = 5">' . esc_textarea($text) . '</textarea><p><button class="button">Mentés</button></p>'); // phpcs:ignore
    echo '</div>';
}

/** „Kiemelt = 5” soronként → ['Kiemelt' => 5.0] (0–50 %). */
function mandala_crm_parse_levels(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^\s*(.+?)\s*[=:]\s*(\d+(?:[.,]\d+)?)\s*%?\s*$/u', $line, $m)) {
            $out[sanitize_text_field($m[1])] = max(0.0, min(50.0, (float) str_replace(',', '.', $m[2])));
        }
    }
    return $out;
}

/* ---------- Őrszem ---------- */

add_filter('mandala_health_checks', function ($out) {
    if (!mandala_crm_config()) {
        return $out;
    }
    $st = mandala_crm_state();
    $ok = !empty($st['ok_at']) && (int) $st['ok_at'] > time() - HOUR_IN_SECONDS;
    $out['crm'] = ['label' => 'Partner CRM összekötő', 'ok' => $ok, 'msg' => $ok ? 'utolsó kapcsolat: ' . wp_date('H:i', (int) $st['ok_at']) : 'egy órája nem kapcsolódott' . (!empty($st['error']) ? ': ' . $st['error'] : '')];
    return $out;
});
